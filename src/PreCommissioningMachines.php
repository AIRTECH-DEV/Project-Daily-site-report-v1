<?php
require_once __DIR__ . '/Workbook.php';

/**
 * The equipment schedule out of a pre-commissioning report — model no., serial
 * no. and location (the "System Name" column) for every indoor/outdoor unit.
 *
 * Why it exists: the commissioning technician's Android app asks for exactly
 * those three fields per machine, and they were already typed once at the
 * Pre-Commissioning step. Capturing them here lets CommissionPush hand them to
 * the app backend, so the technician opens a job with the machine list already
 * filled in instead of copying serials off a sheet.
 *
 * Both report routes are covered:
 *   - the in-app form  -> preCommissioningReport JSON (machineReports[].units[])
 *   - an upload        -> the .xls/.xlsx workbook itself, parsed by Workbook
 *
 * Purely additive: extraction failure is swallowed by the caller and costs the
 * submission nothing — the technician just types the machines as before.
 */
class PreCommissioningMachines
{
    /**
     * Column header -> field. Matched against the header with punctuation and
     * spacing stripped (see headerKey), because these workbooks are hand-made and
     * every engineer spells the column differently: "Sr. No." / "SER. NO " /
     * "ODU SR.NO" all have to land on the serial column.
     */
    private const HEADERS = [
        'model'       => ['model'],
        'serial'      => ['srno', 'serno', 'serial', 'slno'],
        'location'    => ['systemname', 'location', 'room'],
        'invoiceNo'   => ['invoice'],
        'invoiceDate' => ['date'],
    ];

    /* ============================== extraction ============================= */

    /**
     * From the browser form's saved report. Every machine report contributes its
     * ODU plus the units in its schedule.
     * @return array<int, array<string, string|int>>
     */
    public static function fromForm(array $report): array
    {
        $machineReports = (!empty($report['machineReports']) && is_array($report['machineReports']))
            ? array_values(array_filter($report['machineReports'], 'is_array'))
            : [$report];

        $out = [];
        foreach ($machineReports as $mi => $m) {
            $system    = self::clean($m['system'] ?? ($m['gasSystem'] ?? ($report['system'] ?? '')));
            $oduModel  = self::clean($m['oduModel']  ?? '');
            $oduSerial = self::clean($m['oduSerial'] ?? '');
            $rows = [];

            foreach ((array)($m['units'] ?? []) as $u) {
                if (!is_array($u)) { continue; }
                $model  = self::clean($u['model']  ?? '');
                $serial = self::clean($u['serial'] ?? '');
                if ($model === '' && $serial === '') { continue; }
                $location = self::clean($u['systemName'] ?? ($u['location'] ?? ''));
                $rows[] = [
                    'role'        => self::roleOf($model, $serial, $location, $oduModel, $oduSerial),
                    'model'       => $model,
                    'serial'      => $serial,
                    'location'    => $location,
                    'system'      => $system,
                    'invoiceNo'   => self::clean($u['invoice'] ?? ''),
                    'invoiceDate' => self::dateOf($u['invoiceDate'] ?? ''),
                ];
            }

            // The form keeps the ODU in its own pair of fields. Only add it when
            // the schedule did not already list it (the sample workbooks do).
            $hasOdu = false;
            foreach ($rows as $r) { if ($r['role'] === 'odu') { $hasOdu = true; break; } }
            if (!$hasOdu && ($oduModel !== '' || $oduSerial !== '')) {
                array_unshift($rows, [
                    'role'        => 'odu',
                    'model'       => $oduModel,
                    'serial'      => $oduSerial,
                    'location'    => trim($system . ' ODU'),
                    'system'      => $system,
                    'invoiceNo'   => '',
                    'invoiceDate' => null,
                ]);
            }
            self::appendMachine($out, $rows, $mi + 1);
        }
        return $out;
    }

    /**
     * From an uploaded workbook. Every sheet carrying an equipment schedule is
     * one outdoor machine, so a two-system site uploaded as one file still comes
     * out as two machines.
     */
    public static function fromWorkbook(string $path): array
    {
        $out = [];
        $machineNo = 0;
        $seen = [];
        foreach (Workbook::read($path) as $rows) {
            $head = self::findHeader($rows);
            if ($head === null) { continue; }
            [$headRow, $cols] = $head;
            $system = self::systemOf($rows, $headRow);

            $units = [];
            $blanks = 0;
            foreach ($rows as $r => $cells) {
                if ($r <= $headRow) { continue; }
                $first = self::clean($cells[array_key_first($cells)] ?? '');
                // The schedule ends where the summary rows start.
                if (preg_match('/^(refrigerant|findings|total|remarks?)\b/i', $first)) { break; }
                $model    = self::col($cells, $cols, 'model');
                $serial   = self::col($cells, $cols, 'serial');
                $location = self::col($cells, $cols, 'location');
                if ($model === '' && $serial === '') {
                    if (++$blanks >= 3) { break; }   // tolerate a gap, not a gulf
                    continue;
                }
                $blanks = 0;
                $units[] = [
                    'role'        => self::roleOf($model, $serial, $location, '', ''),
                    'model'       => $model,
                    'serial'      => $serial,
                    'location'    => $location,
                    'system'      => $system,
                    'invoiceNo'   => self::col($cells, $cols, 'invoiceNo'),
                    'invoiceDate' => self::dateOf(self::col($cells, $cols, 'invoiceDate')),
                ];
            }
            // The gas-quantity sheet repeats the outdoor unit under its own
            // "ODU MODEL / ODU SR.NO" heading — that is the same machine again,
            // not a second one.
            if (!$units || self::allSeen($units, $seen)) { continue; }
            foreach ($units as $u) { $seen[self::sig($u)] = true; }
            self::appendMachine($out, $units, ++$machineNo);
        }
        return $out;
    }

    private static function sig(array $unit): string
    {
        return strtolower(($unit['model'] ?? '') . '|' . ($unit['serial'] ?? ''));
    }

    private static function allSeen(array $units, array $seen): bool
    {
        foreach ($units as $u) {
            if (!isset($seen[self::sig($u)])) { return false; }
        }
        return true;
    }

    /** Stamp machine/unit numbers and push a machine's units onto the result. */
    private static function appendMachine(array &$out, array $units, int $machineNo): void
    {
        $unitNo = 0;
        foreach ($units as $u) {
            $u['machineNo'] = $machineNo;
            $u['unitNo']    = ++$unitNo;
            $out[] = $u;
        }
    }

    /** The row holding "Model No." and a serial column, plus its column map. */
    private static function findHeader(array $rows): ?array
    {
        foreach ($rows as $r => $cells) {
            $cols = [];
            foreach ($cells as $c => $value) {
                $text = self::headerKey($value);
                if ($text === '') { continue; }
                foreach (self::HEADERS as $field => $needles) {
                    if (isset($cols[$field])) { continue; }
                    foreach ($needles as $needle) {
                        if (strpos($text, $needle) !== false) { $cols[$field] = $c; break; }
                    }
                }
            }
            if (isset($cols['model'], $cols['serial'])) { return [$r, self::spans($cols)]; }
        }
        return null;
    }

    /**
     * field => [firstCol, lastCol].
     *
     * A merged header cell anchors on its LEFT column, so "Model No." merged over
     * B:C reads as column B while the models sit in C — and column B is the row
     * counter. Giving each field every column up to the next header's, then taking
     * the rightmost filled cell of that span, lands on the model and skips the
     * counter. The trailing field gets two spare columns for the same reason.
     */
    private static function spans(array $cols): array
    {
        asort($cols);
        $fields = array_keys($cols);
        $out = [];
        foreach ($fields as $i => $field) {
            $start = $cols[$field];
            $end   = isset($fields[$i + 1]) ? $cols[$fields[$i + 1]] - 1 : $start + 2;
            $out[$field] = [$start, max($start, $end)];
        }
        return $out;
    }

    /**
     * The capacity above the schedule. Written every which way across these
     * workbooks — "System | 10 HP", "SYSTEM :- 10 HP", and a label cell whose
     * value cell repeats the word ("SYSTEM | SYSTEM - 10 HP") — so the prefix is
     * stripped from the value too, not just the label.
     */
    private static function systemOf(array $rows, int $headRow): string
    {
        foreach ($rows as $r => $cells) {
            if ($r >= $headRow) { break; }
            $keys = array_keys($cells);
            foreach ($keys as $i => $c) {
                $text = self::clean($cells[$c]);
                if (!preg_match('/^system\b\s*[:\-\s]*(.*)$/i', $text, $m)) { continue; }
                $inline = self::stripSystemPrefix($m[1]);
                if ($inline !== '') { return $inline; }
                $next = $keys[$i + 1] ?? null;
                if ($next !== null) { return self::stripSystemPrefix(self::clean($cells[$next])); }
            }
        }
        return '';
    }

    private static function stripSystemPrefix(string $v): string
    {
        $v = trim($v, " :-\t");
        if (preg_match('/^system\b\s*[:\-\s]*(.+)$/i', $v, $m)) { $v = trim($m[1], " :-\t"); }
        return $v;
    }

    /** Rightmost filled cell inside the field's column span. */
    private static function col(array $cells, array $cols, string $field): string
    {
        if (!isset($cols[$field])) { return ''; }
        [$first, $last] = $cols[$field];
        for ($c = $last; $c >= $first; $c--) {
            $v = self::clean($cells[$c] ?? '');
            if ($v !== '') { return $v; }
        }
        return '';
    }

    /** Outdoor unit if the location says ODU, or it matches the form's ODU pair. */
    private static function roleOf(string $model, string $serial, string $location, string $oduModel, string $oduSerial): string
    {
        if (preg_match('/\bodu\b|outdoor/i', $location)) { return 'odu'; }
        if ($oduModel !== '' && strcasecmp($model, $oduModel) === 0
            && ($oduSerial === '' || strcasecmp($serial, $oduSerial) === 0)) { return 'odu'; }
        return 'idu';
    }

    /** 'YYYY-MM-DD', a d-m-Y string, or an Excel day serial -> 'YYYY-MM-DD'. */
    private static function dateOf($value): ?string
    {
        $v = self::clean($value);
        if ($v === '') { return null; }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { return $v; }
        if (ctype_digit($v) && (int)$v >= 20000 && (int)$v <= 80000) {
            return gmdate('Y-m-d', ((int)$v - 25569) * 86400);   // Excel epoch 1899-12-30
        }
        foreach (['d-m-Y', 'd/m/Y', 'j-n-Y', 'j/n/Y'] as $fmt) {
            $d = DateTime::createFromFormat($fmt, $v);
            if ($d instanceof DateTime) { return $d->format('Y-m-d'); }
        }
        return null;
    }

    private static function clean($v): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string)$v));
    }

    /** Header text down to bare letters+digits: "SER. NO " -> "serno". */
    private static function headerKey($v): string
    {
        return strtolower(preg_replace('/[^A-Za-z0-9]+/', '', (string)$v));
    }

    /* ============================= persistence ============================= */

    /**
     * Replace the machine list for a project. Re-reporting Pre-Commissioning
     * corrects the list rather than stacking a second copy onto it.
     *
     * An EMPTY list is a no-op, not a wipe: an unreadable workbook or a report
     * filed without a schedule must not throw away a good list captured earlier.
     *
     * @return int rows written
     */
    public static function store(PDO $db, string $projectKey, ?int $submissionId, array $machines): int
    {
        $projectKey = trim($projectKey);
        if ($projectKey === '' || !$machines) { return 0; }
        self::ensureTable($db);

        $db->prepare("DELETE FROM `precommissioning_machines` WHERE `project_key` = ?")->execute([$projectKey]);
        // Every identifier quoted: `system` is a RESERVED WORD in MySQL 8 (prod)
        // and an unquoted one is a 1064 syntax error. MariaDB — XAMPP, where this
        // is developed — accepts it bare, so this passes locally and fails only
        // in production.
        $ins = $db->prepare(
            "INSERT INTO `precommissioning_machines`
               (`project_key`, `submission_id`, `machine_no`, `unit_no`, `unit_role`,
                `model`, `serial`, `location`, `system`, `invoice_no`, `invoice_date`)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        );
        $n = 0;
        foreach ($machines as $m) {
            $ins->execute([
                $projectKey,
                $submissionId,
                (int)($m['machineNo'] ?? 1),
                (int)($m['unitNo'] ?? ++$n),
                ($m['role'] ?? 'idu') === 'odu' ? 'odu' : 'idu',
                self::cut($m['model'] ?? '', 120),
                self::cut($m['serial'] ?? '', 120),
                self::cut($m['location'] ?? '', 190),
                self::cut($m['system'] ?? '', 60),
                self::cut($m['invoiceNo'] ?? '', 120),
                $m['invoiceDate'] ?? null,
            ]);
            $n++;
        }
        return $n;
    }

    /** Machine list for one project, in the shape the app backend ingests. */
    public static function forProject(PDO $db, string $projectKey): array
    {
        try {
            // `system` quoted — reserved in MySQL 8. See store().
            $st = $db->prepare(
                "SELECT `machine_no`, `unit_no`, `unit_role`, `model`, `serial`, `location`,
                        `system`, `invoice_no`, `invoice_date`
                   FROM `precommissioning_machines`
                  WHERE `project_key` = ?
                  ORDER BY `machine_no`, `unit_no`"
            );
            $st->execute([$projectKey]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];   // table not migrated yet — push the project without machines
        }
        return array_map(static fn(array $r): array => [
            'machineNo'   => (int)$r['machine_no'],
            'unitNo'      => (int)$r['unit_no'],
            'role'        => $r['unit_role'],
            'model'       => $r['model']      ?: null,
            'serial'      => $r['serial']     ?: null,
            'location'    => $r['location']   ?: null,
            'system'      => $r['system']     ?: null,
            'invoiceNo'   => $r['invoice_no'] ?: null,
            'invoiceDate' => $r['invoice_date'] ?: null,
        ], $rows);
    }

    /** Self-migrating, like CommissionPush::ensureColumns — deploy has no auto-migrations. */
    public static function ensureTable(PDO $db): void
    {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS `precommissioning_machines` (
               `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
               `project_key`   VARCHAR(190) NOT NULL,
               `submission_id` BIGINT UNSIGNED DEFAULT NULL,
               `machine_no`    INT UNSIGNED NOT NULL DEFAULT 1,
               `unit_no`       INT UNSIGNED NOT NULL DEFAULT 1,
               `unit_role`     ENUM('odu','idu') NOT NULL DEFAULT 'idu',
               `model`         VARCHAR(120) DEFAULT NULL,
               `serial`        VARCHAR(120) DEFAULT NULL,
               `location`      VARCHAR(190) DEFAULT NULL,
               `system`        VARCHAR(60)  DEFAULT NULL,
               `invoice_no`    VARCHAR(120) DEFAULT NULL,
               `invoice_date`  DATE DEFAULT NULL,
               `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
               PRIMARY KEY (`id`),
               UNIQUE KEY `uq_unit` (`project_key`,`machine_no`,`unit_no`),
               KEY `idx_project` (`project_key`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private static function cut(string $v, int $max): ?string
    {
        $v = self::clean($v);
        if ($v === '') { return null; }
        return function_exists('mb_substr') ? mb_substr($v, 0, $max) : substr($v, 0, $max);
    }
}
