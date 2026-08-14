<?php
/**
 * Minimal read-only spreadsheet reader for uploaded pre-commissioning workbooks.
 * Handles .xls (BIFF8 inside an OLE2 compound file) and .xlsx (a zip of XML).
 *
 * Hand-rolled on purpose: PMS has no composer (vendor/ is fpdf only) and the
 * `zip` extension is not guaranteed on every box, so PhpSpreadsheet is off the
 * table. Scope is deliberately tiny — cell TEXT only. No styles, no number
 * formats, no formula evaluation (a formula cell yields its cached result).
 * If this ever needs more than "pull a table out of a sheet", swap in a library.
 *
 * read() returns ['Sheet name' => [rowIdx => [colIdx => 'text']]] — sparse,
 * 0-based, only cells that carry a value. Throws on anything it cannot parse.
 */
class Workbook
{
    /** @return array<string, array<int, array<int, string>>> */
    public static function read(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('Workbook not found: ' . basename($path));
        }
        $data = (string)file_get_contents($path);
        if (strlen($data) < 8) {
            throw new RuntimeException('Workbook is empty.');
        }
        if (strncmp($data, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8) === 0) {
            return self::readXls($data);
        }
        if (strncmp($data, "PK\x03\x04", 4) === 0) {
            return self::readXlsx($path, $data);
        }
        throw new RuntimeException('Unrecognised workbook format (not .xls or .xlsx).');
    }

    /* ===================== .xls : OLE2 compound file ====================== */

    private static function readXls(string $d): array
    {
        $sectorSize = 1 << self::u16($d, 0x1E);
        $miniSize   = 1 << self::u16($d, 0x20);
        $miniCutoff = self::u32($d, 0x38);
        if ($sectorSize < 128 || $miniSize < 16) {
            throw new RuntimeException('Corrupt OLE2 header.');
        }

        // FAT: 109 sector ids inline, the rest chained through DIFAT sectors.
        $fatIds = [];
        for ($i = 0; $i < 109; $i++) {
            $sid = self::u32($d, 0x4C + $i * 4);
            if ($sid > 0xFFFFFFFA) { break; }
            $fatIds[] = $sid;
        }
        $next  = self::u32($d, 0x44);
        $guard = 0;
        while ($next <= 0xFFFFFFFA && $guard++ < 100000) {
            $off = self::sectorOffset($next, $sectorSize);
            for ($i = 0; $i < ($sectorSize / 4) - 1; $i++) {
                $sid = self::u32($d, $off + $i * 4);
                if ($sid > 0xFFFFFFFA) { continue; }
                $fatIds[] = $sid;
            }
            $next = self::u32($d, $off + $sectorSize - 4);
        }
        $fat = [];
        foreach ($fatIds as $sid) {
            $off = self::sectorOffset($sid, $sectorSize);
            for ($i = 0; $i < $sectorSize / 4; $i++) {
                $fat[] = self::u32($d, $off + $i * 4);
            }
        }

        // Directory: 128-byte entries. Root (type 5) also holds the mini stream.
        $dir = self::readChain($d, $fat, self::u32($d, 0x30), $sectorSize, 0);
        $entries = [];
        for ($p = 0; $p + 128 <= strlen($dir); $p += 128) {
            $nameLen = self::u16($dir, $p + 0x40);
            $type    = ord($dir[$p + 0x42]);
            if ($type !== 2 && $type !== 5) { continue; }
            $name = $nameLen > 2
                ? (string)@iconv('UTF-16LE', 'UTF-8//IGNORE', substr($dir, $p, $nameLen - 2))
                : '';
            $entries[] = [
                'name'  => $name,
                'type'  => $type,
                'start' => self::u32($dir, $p + 0x74),
                'size'  => self::u32($dir, $p + 0x78),   // low dword is plenty here
            ];
        }

        $root = null; $book = null;
        foreach ($entries as $e) {
            if ($e['type'] === 5) { $root = $e; continue; }
            if ($book === null && ($e['name'] === 'Workbook' || $e['name'] === 'Book')) { $book = $e; }
        }
        if ($book === null) {
            throw new RuntimeException('No Workbook stream in the .xls file.');
        }

        if ($book['size'] >= $miniCutoff || $root === null) {
            $stream = self::readChain($d, $fat, $book['start'], $sectorSize, $book['size']);
        } else {
            // Small streams live inside the root stream, indexed by the mini FAT.
            $miniFatRaw = self::readChain($d, $fat, self::u32($d, 0x3C), $sectorSize, 0);
            $miniFat = [];
            for ($i = 0; $i + 4 <= strlen($miniFatRaw); $i += 4) { $miniFat[] = self::u32($miniFatRaw, $i); }
            $container = self::readChain($d, $fat, $root['start'], $sectorSize, 0);
            $stream = '';
            $sid = $book['start']; $guard = 0;
            while ($sid <= 0xFFFFFFFA && $guard++ < 1000000) {
                $stream .= substr($container, $sid * $miniSize, $miniSize);
                $sid = $miniFat[$sid] ?? 0xFFFFFFFE;
            }
            $stream = substr($stream, 0, $book['size']);
        }

        return self::parseBiff($stream);
    }

    private static function sectorOffset(int $sid, int $sectorSize): int
    {
        return 512 + $sid * $sectorSize;
    }

    /** Walk a FAT chain into one string; $size 0 = take the whole chain. */
    private static function readChain(string $d, array $fat, int $start, int $sectorSize, int $size): string
    {
        $out = ''; $sid = $start; $guard = 0;
        while ($sid <= 0xFFFFFFFA && $guard++ < 1000000) {
            $out .= substr($d, self::sectorOffset($sid, $sectorSize), $sectorSize);
            $sid = $fat[$sid] ?? 0xFFFFFFFE;
        }
        return $size > 0 ? substr($out, 0, $size) : $out;
    }

    /* ========================= .xls : BIFF8 records ======================== */

    private static function parseBiff(string $s): array
    {
        $n = strlen($s);

        // Pass 1: sheet names (BOUNDSHEET carries the substream's byte offset)
        // and the shared string table. Both live in the globals substream.
        $sheetAt = []; $sst = [];
        $p = 0;
        while ($p + 4 <= $n) {
            $type = self::u16($s, $p); $len = self::u16($s, $p + 2);
            if ($type === 0x0085) {                                   // BOUNDSHEET
                $pos  = self::u32($s, $p + 4);
                $name = self::shortString($s, $p + 4 + 6);
                $sheetAt[$pos] = $name;
            } elseif ($type === 0x00FC) {                              // SST (+CONTINUEs)
                $segments = [substr($s, $p + 4 + 8, $len - 8)];
                $q = $p + 4 + $len;
                while ($q + 4 <= $n && self::u16($s, $q) === 0x003C) {
                    $cl = self::u16($s, $q + 2);
                    $segments[] = substr($s, $q + 4, $cl);
                    $q += 4 + $cl;
                }
                $sst = self::parseSst($segments, self::u32($s, $p + 4 + 4));
                $p = $q; continue;
            } elseif ($type === 0x000A && $p > 0) {                    // EOF of globals
                break;
            }
            $p += 4 + $len;
        }

        // Pass 2: cells, one substream per sheet.
        $out = []; $cur = null; $pendingFormula = null;
        $p = 0;
        while ($p + 4 <= $n) {
            $type = self::u16($s, $p); $len = self::u16($s, $p + 2); $at = $p + 4;
            switch ($type) {
                case 0x0809:                                           // BOF
                    $cur = $sheetAt[$p] ?? null;
                    if ($cur !== null && !isset($out[$cur])) { $out[$cur] = []; }
                    break;
                case 0x00FD:                                           // LABELSST
                    self::put($out, $cur, $s, $at, $sst[self::u32($s, $at + 6)] ?? '');
                    break;
                case 0x0204:                                           // LABEL
                    self::put($out, $cur, $s, $at, self::longString($s, $at + 6));
                    break;
                case 0x0203:                                           // NUMBER
                    self::put($out, $cur, $s, $at, self::num(self::dbl($s, $at + 6)));
                    break;
                case 0x027E:                                           // RK
                    self::put($out, $cur, $s, $at, self::num(self::rk(self::u32($s, $at + 6))));
                    break;
                case 0x00BD:                                           // MULRK
                    $row = self::u16($s, $at); $col = self::u16($s, $at + 2);
                    for ($i = 0; $at + 4 + $i * 6 + 6 <= $at + $len; $i++) {
                        $v = self::num(self::rk(self::u32($s, $at + 4 + $i * 6 + 2)));
                        if ($cur !== null && $v !== '') { $out[$cur][$row][$col + $i] = $v; }
                    }
                    break;
                case 0x0006:                                           // FORMULA
                    // A cached string/bool/error result is flagged by 0xFFFF in the
                    // top word; the string itself arrives in the next STRING record.
                    if (self::u16($s, $at + 12) === 0xFFFF) {
                        if (ord($s[$at + 6]) === 0) { $pendingFormula = [self::u16($s, $at), self::u16($s, $at + 2)]; }
                        elseif (ord($s[$at + 6]) === 1) { self::put($out, $cur, $s, $at, ord($s[$at + 8]) ? 'TRUE' : 'FALSE'); }
                    } else {
                        self::put($out, $cur, $s, $at, self::num(self::dbl($s, $at + 6)));
                    }
                    break;
                case 0x0207:                                           // STRING (formula result)
                    if ($pendingFormula !== null && $cur !== null) {
                        $v = self::longString($s, $at);
                        if ($v !== '') { $out[$cur][$pendingFormula[0]][$pendingFormula[1]] = $v; }
                        $pendingFormula = null;
                    }
                    break;
            }
            $p += 4 + $len;
        }

        foreach ($out as $name => $rows) { ksort($rows); foreach ($rows as $r => $cells) { ksort($cells); $rows[$r] = $cells; } $out[$name] = $rows; }
        return $out;
    }

    /** Cell records all start row(2) col(2) xf(2). */
    private static function put(array &$out, ?string $sheet, string $s, int $at, string $value): void
    {
        if ($sheet === null || $value === '') { return; }
        $out[$sheet][self::u16($s, $at)][self::u16($s, $at + 2)] = $value;
    }

    /**
     * Shared string table. Strings run across CONTINUE boundaries, and each new
     * segment restarts with its own "is this half UTF-16?" flag byte — that quirk
     * is the whole reason this needs a cursor instead of one concatenated buffer.
     */
    private static function parseSst(array $segments, int $unique): array
    {
        $seg = 0; $pos = 0;
        $left = static function () use (&$segments, &$seg, &$pos): int {
            return isset($segments[$seg]) ? strlen($segments[$seg]) - $pos : 0;
        };
        $advance = static function () use (&$seg, &$pos, $left): bool {
            if ($left() > 0) { return true; }
            $seg++; $pos = 0;
            return $left() > 0;
        };
        $take = static function (int $bytes) use (&$segments, &$seg, &$pos, $advance): string {
            if (!$advance()) { return ''; }
            $chunk = substr($segments[$seg], $pos, $bytes);
            $pos += strlen($chunk);
            return $chunk;
        };

        $out = [];
        for ($i = 0; $i < $unique; $i++) {
            if (!$advance()) { break; }
            $head = $take(3);
            if (strlen($head) < 3) { break; }
            $cch   = self::u16($head, 0);
            $grbit = ord($head[2]);
            $runs  = ($grbit & 0x08) ? self::u16($take(2), 0) : 0;
            $ext   = ($grbit & 0x04) ? self::u32($take(4), 0) : 0;

            $text = ''; $wide = ($grbit & 0x01) !== 0; $need = $cch;
            while ($need > 0) {
                if (!$advance()) { break; }
                $avail = $left();
                $chars = $wide ? min($need, intdiv($avail, 2)) : min($need, $avail);
                if ($chars <= 0) {                       // segment boundary mid-string
                    $seg++; $pos = 0;
                    if ($left() <= 0) { break; }
                    $wide = (ord($take(1)) & 0x01) !== 0;
                    continue;
                }
                $raw = $take($chars * ($wide ? 2 : 1));
                $text .= $wide
                    ? (string)@iconv('UTF-16LE', 'UTF-8//IGNORE', $raw)
                    : self::latin1($raw);
                $need -= $chars;
                if ($need > 0 && $left() <= 0) {          // continues in the next segment
                    $seg++; $pos = 0;
                    if ($left() <= 0) { break; }
                    $wide = (ord($take(1)) & 0x01) !== 0;
                }
            }
            if ($runs) { $take($runs * 4); }
            if ($ext)  { $take($ext); }
            $out[] = $text;
        }
        return $out;
    }

    /** XLUnicodeString with a 1-byte (short) / 2-byte (long) character count. */
    private static function shortString(string $s, int $at): string { return self::uniString($s, $at, ord($s[$at]), $at + 1); }
    private static function longString(string $s, int $at): string  { return self::uniString($s, $at, self::u16($s, $at), $at + 2); }

    private static function uniString(string $s, int $at, int $cch, int $flagAt): string
    {
        $wide = (ord($s[$flagAt]) & 0x01) !== 0;
        $raw  = substr($s, $flagAt + 1, $cch * ($wide ? 2 : 1));
        return $wide ? (string)@iconv('UTF-16LE', 'UTF-8//IGNORE', $raw) : self::latin1($raw);
    }

    private static function latin1(string $raw): string
    {
        return (string)@iconv('CP1252', 'UTF-8//IGNORE', $raw);
    }

    /** RK: a float squeezed into 4 bytes — 30-bit int or a truncated double, /100 optional. */
    private static function rk(int $rk): float
    {
        if ($rk & 0x02) {
            $v = (float)($rk >> 2);
            if ($rk & 0x80000000) { $v -= 0x40000000; }
        } else {
            $v = self::dbl(pack('V2', 0, $rk & 0xFFFFFFFC), 0);
        }
        return ($rk & 0x01) ? $v / 100 : $v;
    }

    /* ============================ .xlsx : zip+xml ========================== */

    private static function readXlsx(string $path, string $data): array
    {
        $files = self::zipEntries($path, $data);
        $get = static fn(string $n): string => $files[$n] ?? '';

        $shared = [];
        if (($xml = $get('xl/sharedStrings.xml')) !== '') {
            $doc = self::xml($xml);
            foreach ($doc->si as $si) {
                $t = '';
                foreach ($si->xpath('.//*[local-name()="t"]') ?: [] as $node) { $t .= (string)$node; }
                $shared[] = $t;
            }
        }

        // rId -> part path, so a sheet's name can be tied to its XML.
        $rels = [];
        if (($xml = $get('xl/_rels/workbook.xml.rels')) !== '') {
            foreach (self::xml($xml)->Relationship as $rel) {
                $target = ltrim((string)$rel['Target'], '/');
                $rels[(string)$rel['Id']] = strpos($target, 'xl/') === 0 ? $target : 'xl/' . $target;
            }
        }

        $out = [];
        $book = $get('xl/workbook.xml');
        if ($book === '') { throw new RuntimeException('No xl/workbook.xml — not an .xlsx file.'); }
        $i = 0;
        foreach (self::xml($book)->sheets->sheet as $sheet) {
            $i++;
            $rid  = (string)$sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $part = $rels[$rid] ?? ('xl/worksheets/sheet' . $i . '.xml');
            $xml  = $get($part);
            if ($xml === '') { continue; }
            $out[(string)$sheet['name']] = self::xlsxRows(self::xml($xml), $shared);
        }
        return $out;
    }

    private static function xlsxRows(SimpleXMLElement $doc, array $shared): array
    {
        $rows = [];
        foreach ($doc->sheetData->row as $row) {
            foreach ($row->c as $c) {
                [$r, $col] = self::cellRef((string)$c['r']);
                $type = (string)$c['t'];
                if ($type === 'inlineStr') {
                    $v = '';
                    foreach ($c->xpath('.//*[local-name()="t"]') ?: [] as $node) { $v .= (string)$node; }
                } elseif ($type === 's') {
                    $v = $shared[(int)$c->v] ?? '';
                } else {
                    $v = (string)$c->v;
                    if ($v !== '' && is_numeric($v)) { $v = self::num((float)$v); }
                    if ($type === 'b') { $v = $v === '1' ? 'TRUE' : 'FALSE'; }
                }
                if ($v !== '') { $rows[$r][$col] = $v; }
            }
        }
        ksort($rows);
        foreach ($rows as $r => $cells) { ksort($cells); $rows[$r] = $cells; }
        return $rows;
    }

    /** "B13" -> [12, 1] (0-based row, col). */
    private static function cellRef(string $ref): array
    {
        if (!preg_match('/^([A-Z]+)(\d+)$/i', $ref, $m)) { return [0, 0]; }
        $col = 0;
        foreach (str_split(strtoupper($m[1])) as $ch) { $col = $col * 26 + (ord($ch) - 64); }
        return [(int)$m[2] - 1, $col - 1];
    }

    /**
     * name => bytes for every file in the zip. Uses ZipArchive when the extension
     * is there, otherwise reads the central directory by hand — XAMPP here ships
     * without ext-zip and an uploaded .xlsx still has to open.
     */
    private static function zipEntries(string $path, string $data): array
    {
        $out = [];
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($path) === true) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = (string)$zip->getNameIndex($i);
                    $out[$name] = (string)$zip->getFromIndex($i);
                }
                $zip->close();
                return $out;
            }
        }
        $eocd = strrpos($data, "PK\x05\x06");
        if ($eocd === false) { throw new RuntimeException('Damaged .xlsx (no zip directory).'); }
        $count = self::u16($data, $eocd + 10);
        $p     = self::u32($data, $eocd + 16);
        for ($i = 0; $i < $count && $p + 46 <= strlen($data); $i++) {
            $method  = self::u16($data, $p + 10);
            $csize   = self::u32($data, $p + 20);
            $nameLen = self::u16($data, $p + 28);
            $extLen  = self::u16($data, $p + 30);
            $cmtLen  = self::u16($data, $p + 32);
            $local   = self::u32($data, $p + 42);
            $name    = substr($data, $p + 46, $nameLen);
            // The local header repeats name/extra with its OWN extra length.
            $start   = $local + 30 + self::u16($data, $local + 26) + self::u16($data, $local + 28);
            $bytes   = substr($data, $start, $csize);
            if ($method === 8) { $bytes = (string)@gzinflate($bytes); }
            elseif ($method !== 0) { $bytes = ''; }
            $out[$name] = $bytes;
            $p += 46 + $nameLen + $extLen + $cmtLen;
        }
        return $out;
    }

    private static function xml(string $s): SimpleXMLElement
    {
        $prev = libxml_use_internal_errors(true);
        $doc  = simplexml_load_string($s);
        libxml_use_internal_errors($prev);
        if ($doc === false) { throw new RuntimeException('Damaged sheet XML.'); }
        return $doc;
    }

    /* ================================ shared ============================== */

    private static function u16(string $s, int $at): int { return strlen($s) >= $at + 2 ? unpack('v', substr($s, $at, 2))[1] : 0; }
    private static function u32(string $s, int $at): int { return strlen($s) >= $at + 4 ? unpack('V', substr($s, $at, 4))[1] : 0; }
    private static function dbl(string $s, int $at): float { return strlen($s) >= $at + 8 ? (float)unpack('e', substr($s, $at, 8))[1] : 0.0; }

    /** Whole numbers must not gain a ".0" — a serial no. is read as text downstream. */
    private static function num(float $v): string
    {
        if (!is_finite($v)) { return ''; }
        return (abs($v - round($v)) < 1e-9 && abs($v) < 1e15)
            ? (string)(int)round($v)
            : rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
    }
}
