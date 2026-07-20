<?php
/**
 * Google Sheets v4 helper — read/write values, resolve tabs by gid or name,
 * and the ported getProjectNames() dropdown logic. All destinations are the
 * same spreadsheets code.js used; the service account must be shared on each.
 */
class Sheets
{
    const BASE = 'https://sheets.googleapis.com/v4/spreadsheets';

    /** @var GoogleClient */
    private $client;
    /** @var array cache of spreadsheetId => [title => sheetId, ...] */
    private $tabCache = [];

    public function __construct(GoogleClient $client)
    {
        $this->client = $client;
    }

    /* ---------------- metadata ---------------- */

    /** Returns [ ['sheetId'=>int,'title'=>string], ... ] for a spreadsheet. */
    public function listTabs(string $spreadsheetId): array
    {
        if (isset($this->tabCache[$spreadsheetId])) {
            return $this->tabCache[$spreadsheetId];
        }
        $url = self::BASE . "/$spreadsheetId?fields=" . rawurlencode('sheets(properties(sheetId,title))');
        $res = $this->client->get($url);
        $out = [];
        foreach ($res['sheets'] ?? [] as $s) {
            $out[] = [
                'sheetId' => (int)($s['properties']['sheetId'] ?? 0),
                'title'   => (string)($s['properties']['title'] ?? ''),
            ];
        }
        return $this->tabCache[$spreadsheetId] = $out;
    }

    /** Tab title for a numeric gid, or null. */
    public function titleForGid(string $spreadsheetId, int $gid): ?string
    {
        foreach ($this->listTabs($spreadsheetId) as $t) {
            if ($t['sheetId'] === $gid) {
                return $t['title'];
            }
        }
        return null;
    }

    /** Tab title matched by normalized name, with token-overlap fallback (findSheetByName_). */
    public function titleForName(string $spreadsheetId, string $wanted): ?string
    {
        $tabs = $this->listTabs($spreadsheetId);
        $target = self::normalizeKey($wanted);
        foreach ($tabs as $t) {
            if (self::normalizeKey($t['title']) === $target) {
                return $t['title'];
            }
        }
        $wantedTokens = array_filter(explode(' ', preg_replace('/[^a-z0-9 ]/', ' ', $target)));
        $best = null; $bestScore = 0;
        foreach ($tabs as $t) {
            $nameTokens = explode(' ', preg_replace('/[^a-z0-9 ]/', ' ', self::normalizeKey($t['title'])));
            $score = 0;
            foreach ($wantedTokens as $tok) {
                if (in_array($tok, $nameTokens, true)) { $score++; }
            }
            if ($score > $bestScore) { $bestScore = $score; $best = $t['title']; }
        }
        return $bestScore > 0 ? $best : null;
    }

    /* ---------------- values: read ---------------- */

    /** Reads a whole tab's values (2-D array of strings). Empty tail cells omitted by API. */
    public function getTab(string $spreadsheetId, string $tabTitle): array
    {
        $range = rawurlencode("'" . str_replace("'", "''", $tabTitle) . "'");
        $url = self::BASE . "/$spreadsheetId/values/$range?majorDimension=ROWS&valueRenderOption=UNFORMATTED_VALUE";
        $res = $this->client->get($url);
        return $res['values'] ?? [];
    }

    /* ---------------- values: write ---------------- */

    /** Appends one row to a tab (INSERT_ROWS). Returns the updated range string. */
    public function appendRow(string $spreadsheetId, string $tabTitle, array $row): string
    {
        $range = rawurlencode("'" . str_replace("'", "''", $tabTitle) . "'");
        $url = self::BASE . "/$spreadsheetId/values/$range:append"
            . '?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS&includeValuesInResponse=false';
        $res = $this->client->post($url, ['values' => [array_values($row)]]);
        return (string)($res['updates']['updatedRange'] ?? '');
    }

    /** Writes a single cell (1-based row/col) with USER_ENTERED parsing. */
    public function setCell(string $spreadsheetId, string $tabTitle, int $row, int $col, $value): void
    {
        $a1 = self::colLetter($col) . $row;
        $range = "'" . str_replace("'", "''", $tabTitle) . "'!" . $a1;
        $url = self::BASE . "/$spreadsheetId/values/" . rawurlencode($range)
            . '?valueInputOption=USER_ENTERED';
        $this->client->put($url, ['range' => $range, 'majorDimension' => 'ROWS', 'values' => [[$value]]]);
    }

    /** Overwrites a contiguous row starting at column 1 (used for the response row). */
    public function setRow(string $spreadsheetId, string $tabTitle, int $row, array $values): void
    {
        $endCol = self::colLetter(max(1, count($values)));
        $range = "'" . str_replace("'", "''", $tabTitle) . "'!A$row:$endCol$row";
        $url = self::BASE . "/$spreadsheetId/values/" . rawurlencode($range)
            . '?valueInputOption=USER_ENTERED';
        $this->client->put($url, ['range' => $range, 'majorDimension' => 'ROWS', 'values' => [array_values($values)]]);
    }

    /** sheetId (gid) for a tab title, or null. */
    public function sheetIdForTitle(string $spreadsheetId, string $title): ?int
    {
        foreach ($this->listTabs($spreadsheetId) as $t) {
            if ($t['title'] === $title) {
                return $t['sheetId'];
            }
        }
        return null;
    }

    /** Deletes a 1-based row (used to clean up test submissions). */
    public function deleteRow(string $spreadsheetId, string $tabTitle, int $rowNum): void
    {
        $gid = $this->sheetIdForTitle($spreadsheetId, $tabTitle);
        if ($gid === null) {
            throw new RuntimeException("Tab \"$tabTitle\" not found for deleteRow.");
        }
        $url = self::BASE . "/$spreadsheetId:batchUpdate";
        $this->client->post($url, ['requests' => [[
            'deleteDimension' => [
                'range' => [
                    'sheetId'    => $gid,
                    'dimension'  => 'ROWS',
                    'startIndex' => $rowNum - 1,
                    'endIndex'   => $rowNum,
                ],
            ],
        ]]]);
    }

    /* ---------------- getProjectNames (ported) ---------------- */

    /**
     * Deduped, sorted project list for a site type, merging every project-name /
     * billing-name column found by header text — same rules as code.js.
     * @param array $cfg app config
     */
    public function getProjectNames(array $cfg, string $siteType): array
    {
        $isVRV = ($siteType === 'VRV');
        $sheetId = $isVRV ? $cfg['vrv_orders_sheet_id'] : $cfg['nonvrv_orders_sheet_id'];
        $gid     = $isVRV ? $cfg['vrv_orders_gid'] : $cfg['nonvrv_orders_gid'];

        $title = $this->titleForGid($sheetId, (int)$gid);
        if ($title === null) {
            return [];
        }
        $rows = $this->getTab($sheetId, $title);
        if (count($rows) < 2) {
            return [];
        }
        $headers = $rows[0];

        $projectCols = [];
        foreach ($headers as $i => $h) {
            $hl = strtolower((string)$h);
            $isProject =
                strpos($hl, 'select project name') !== false ||
                (strpos($hl, 'project name') !== false && strpos($hl, 'executive') === false) ||
                strpos($hl, 'billing customer name') !== false;
            if ($isProject) { $projectCols[] = $i; }
        }
        if (!$projectCols) {
            return [];
        }

        $cleaned = [];
        for ($r = 1; $r < count($rows); $r++) {
            foreach ($projectCols as $c) {
                $v = isset($rows[$r][$c]) ? trim(preg_replace('/\s+/', ' ', (string)$rows[$r][$c])) : '';
                if ($v !== '') { $cleaned[$v] = true; }
            }
        }
        $out = array_keys($cleaned);
        sort($out, SORT_FLAG_CASE | SORT_STRING);
        return $out;
    }

    /* ---------------- shared key helpers (ported) ---------------- */

    public static function normalizeKey($value): string
    {
        $s = ($value === null) ? '' : (string)$value;
        return trim(preg_replace('/\s+/', ' ', mb_strtolower($s)));
    }

    public static function compactKey($value): string
    {
        return preg_replace('/[^a-z0-9]/', '', self::normalizeKey($value));
    }

    /** 0-based header search by substring, case-insensitive, optional exclude (findColIndex). */
    public static function findColIndex(array $headers, string $mustInclude, ?string $mustExclude = null): int
    {
        $inc = strtolower($mustInclude);
        $exc = $mustExclude !== null ? strtolower($mustExclude) : null;
        foreach ($headers as $i => $h) {
            $hl = strtolower((string)$h);
            if (strpos($hl, $inc) !== false && ($exc === null || strpos($hl, $exc) === false)) {
                return $i;
            }
        }
        return -1;
    }

    /** 1-based column number -> A1 letter(s). */
    public static function colLetter(int $col): string
    {
        $s = '';
        while ($col > 0) {
            $m = ($col - 1) % 26;
            $s = chr(65 + $m) . $s;
            $col = intdiv($col - 1 - $m, 26);
        }
        return $s;
    }
}
