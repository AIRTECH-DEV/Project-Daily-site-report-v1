<?php
/**
 * PmsDates — read-only BULK reader for the PMS progress sheets.
 *
 * The submit pipeline reads ONE project row at a time (Pms::getProgressState).
 * The performance / incentive analytics need the opposite: the step dates of
 * EVERY project at once. This class reads each PMS tab exactly once and returns,
 * per project row, the whole step Start/End/Status grid plus:
 *
 *   start_date  — the project's real start = "Marking" → Start Date
 *                 (the cell the manager fills when a site is handed over).
 *                 Falls back to Marking End Date → the earliest step Start Date
 *                 → the LS Material Delivery date, and records which was used.
 *   end_date    — the project's real finish = final "Commissining" → End Date
 *                 ("Pre-Commissining" is deliberately excluded).
 *   target_end  — "Tentitive Project End date" (the sheet's own spelling).
 *
 * It ONLY reads. Nothing here writes to a sheet, a queue, or the tracker — it
 * cannot affect the submit → sheet → PMS → PDF → notify pipeline.
 */
class PmsDates
{
    /** Non-step single-column headers to ignore (mirrors Pms::NON_STEP_COLS). */
    private const NON_STEP_COLS = [
        'timestamp', 'orderid', 'order id', 'project exective by', 'project executive by',
        'project name', 'tentitive project end date', 'tentative project end date',
        'remarks', 'work done by', 'email address', 'email', 'shipping address',
        'total order value', 'sales person', 'order type', 'floor', 'flat no',
    ];

    /** @var Sheets */ private $sheets;
    /** @var array */  private $cfg;
    /** @var string[] Non-fatal problems hit while scanning (shown in the UI). */
    public $warnings = [];

    public function __construct(Sheets $sheets, array $cfg)
    {
        $this->sheets = $sheets;
        $this->cfg    = $cfg;
    }

    /**
     * Every project row across the General VRV / Non-VRV tabs and each
     * configured developer building tab, keyed by the SAME project_key the
     * admin tracker uses ("G|name" / "D|dev|building|flat").
     */
    public function scanAll(): array
    {
        $out = [];
        foreach (['VRV', 'NONVRV'] as $siteType) {
            foreach ($this->scanGeneral($siteType) as $k => $row) {
                $out[$k] = $row;
            }
        }
        foreach (($this->cfg['developer_building_sheets'] ?? []) as $dev => $conf) {
            foreach (($conf['buildings'] ?? []) as $building) {
                foreach ($this->scanDeveloperBuilding((string)$dev, (array)$conf, (string)$building) as $k => $row) {
                    $out[$k] = $row;
                }
            }
        }
        return $out;
    }

    /* ---------------- per-sheet scanners ---------------- */

    /** General PMS tab (VRV / Non-VRV) — one project per row, keyed by Project Name. */
    private function scanGeneral(string $siteType): array
    {
        $out = [];
        try {
            $ssId    = (string)$this->cfg['general_pms_sheet_id'];
            $tabName = $siteType === 'VRV'
                ? $this->cfg['general_pms_tabs']['VRV']
                : $this->cfg['general_pms_tabs']['NONVRV'];
            $title = $this->sheets->titleForName($ssId, (string)$tabName);
            if ($title === null) {
                $this->warnings[] = "General PMS tab not found: $tabName";
                return $out;
            }
            $rows = $this->sheets->getTab($ssId, $title);
            $info = $this->headerInfo($rows);
            $nameCol  = $this->findNamedCol($info, 'Project Name');
            $orderCol = $this->findNamedCol($info, 'OrderID');
            if ($nameCol < 1) {
                $this->warnings[] = "No 'Project Name' column in $title";
                return $out;
            }

            for ($r = $info['dataStartRow']; $r <= count($rows); $r++) {
                $name = trim((string)$this->cell($rows, $r, $nameCol));
                if ($name === '') {
                    continue;
                }
                $row = $this->extractRow($rows, $r, $info);
                $row['project_key'] = 'G|' . strtolower(trim($name));
                $row['label']       = $name;
                $row['site_type']   = $siteType === 'VRV' ? 'VRV' : 'Non-VRV';
                $row['order_id']    = $orderCol > 0 ? trim((string)$this->cell($rows, $r, $orderCol)) : '';
                $row['source']      = $title;
                $out[$row['project_key']] = $row;
            }
        } catch (Throwable $e) {
            $this->warnings[] = "General $siteType scan failed: " . $e->getMessage();
        }
        return $out;
    }

    /** One developer building tab — one flat per row, keyed by developer|building|flat. */
    private function scanDeveloperBuilding(string $developer, array $conf, string $building): array
    {
        $out = [];
        try {
            $ssId = (string)($conf['spreadsheetId'] ?? '');
            if ($ssId === '') {
                return $out;
            }
            $title = $this->sheets->titleForName($ssId, $building);
            if ($title === null) {
                $this->warnings[] = "Building tab not found: $developer › $building";
                return $out;
            }
            $rows = $this->sheets->getTab($ssId, $title);
            $info = $this->headerInfo($rows);
            $flatCol  = $this->findNamedCol($info, 'Flat No');
            $orderCol = $this->findNamedCol($info, 'OrderID');
            if ($flatCol < 1) {
                $this->warnings[] = "No 'Flat No' column in $developer › $building";
                return $out;
            }

            for ($r = $info['dataStartRow']; $r <= count($rows); $r++) {
                $flat = trim((string)$this->cell($rows, $r, $flatCol));
                if ($flat === '') {
                    continue;
                }
                $row = $this->extractRow($rows, $r, $info);
                // Same shape as helpers.php projectKey(): outer trim only.
                $row['project_key'] = 'D|' . strtolower(trim($developer . '|' . $building . '|' . $flat));
                $row['label']       = $developer . ' › ' . $building . ' › ' . $flat;
                $row['site_type']   = '';
                $row['order_id']    = $orderCol > 0 ? trim((string)$this->cell($rows, $r, $orderCol)) : '';
                $row['source']      = $developer . ' / ' . $title;
                $out[$row['project_key']] = $row;
            }
        } catch (Throwable $e) {
            $this->warnings[] = "$developer › $building scan failed: " . $e->getMessage();
        }
        return $out;
    }

    /* ---------------- one row -> step grid + derived dates ---------------- */

    /**
     * Pulls every step's Start/End/Status off one row, then derives the project
     * start (Marking), finish (final Commissioning) and target end.
     */
    private function extractRow(array $rows, int $r, array $info): array
    {
        $steps = [];   // compact step key => ['step','start','end','status']

        for ($i = 0; $i < $info['lastCol']; $i++) {
            $group = trim((string)($info['groupVals'][$i] ?? ''));
            $sub   = Sheets::normalizeKey($info['subVals'][$i] ?? '');
            $val   = $this->cell($rows, $r, $i + 1);

            if ($group === '') {
                continue;
            }
            $key = Sheets::compactKey($group);

            if ($sub === 'start date' || $sub === 'end date' || $sub === 'status') {
                if (!isset($steps[$key])) {
                    $steps[$key] = ['step' => $group, 'start' => null, 'end' => null, 'status' => ''];
                }
                if ($sub === 'status') {
                    $steps[$key]['status'] = trim((string)$val);
                } else {
                    $steps[$key][$sub === 'start date' ? 'start' : 'end'] = self::toYmd($val);
                }
                continue;
            }

            // Single-column DATE step (e.g. "LS Material Delivery"): no sub-label,
            // a real header, a value present — the date IS the completion.
            if ($sub !== '' || in_array(Sheets::normalizeKey($group), self::NON_STEP_COLS, true)) {
                continue;
            }
            $d = self::toYmd($val);
            if ($d !== null && !isset($steps[$key])) {
                $steps[$key] = ['step' => $group, 'start' => $d, 'end' => $d, 'status' => 'Done'];
            }
        }

        // --- derive project start: Marking Start Date is the manager's own signal.
        $start = null; $startSrc = '';
        $marking = $steps[Sheets::compactKey('Marking')] ?? null;
        if ($marking && $marking['start']) {
            $start = $marking['start']; $startSrc = 'marking_start';
        } elseif ($marking && $marking['end']) {
            $start = $marking['end']; $startSrc = 'marking_end';
        } else {
            $ls = $steps[Sheets::compactKey('LS Material Delivery')] ?? null;
            if ($ls && $ls['start']) {
                $start = $ls['start']; $startSrc = 'ls_delivery';
            } else {
                foreach ($steps as $s) {          // earliest start date on the row
                    if ($s['start'] && ($start === null || $s['start'] < $start)) {
                        $start = $s['start']; $startSrc = 'earliest_step';
                    }
                }
            }
        }

        // --- derive project finish: the FINAL commissioning step's End Date.
        $end = null;
        foreach ($steps as $k => $s) {
            if (self::isFinalCommissioning($k) && $s['end']) {
                $end = $s['end'];
                break;
            }
        }

        return [
            'start_date'   => $start,
            'start_source' => $startSrc,
            'end_date'     => $end,
            'target_end'   => $this->readTargetEnd($rows, $r, $info),
            'steps'        => array_values($steps),
        ];
    }

    /** "Tentitive Project End date" (sheet spelling) or the correct spelling. */
    private function readTargetEnd(array $rows, int $r, array $info): ?string
    {
        foreach (['tentative', 'tentitive'] as $needle) {
            $col = $this->findColContains($info, $needle);
            if ($col > 0) {
                $d = self::toYmd($this->cell($rows, $r, $col));
                if ($d !== null) {
                    return $d;
                }
            }
        }
        return null;
    }

    /** True for the FINAL commissioning step — "Pre-Commissining" must not count. */
    public static function isFinalCommissioning(string $compactKey): bool
    {
        if (strncmp($compactKey, 'pre', 3) === 0) {
            return false;
        }
        return strpos($compactKey, 'commiss') !== false;
    }

    /* ---------------- date coercion ---------------- */

    /**
     * Sheet cell -> 'Y-m-d', or null when it holds no usable date.
     * getTab() uses UNFORMATTED_VALUE, so real dates arrive as Sheets serials;
     * text cells can still hold "12-Jul-2026", "2026-07-12" or "12/07/2026".
     */
    public static function toYmd($v): ?string
    {
        if ($v === null || $v === '' || is_bool($v)) {
            return null;
        }
        if (is_numeric($v)) {
            $serial = (float)$v;
            if ($serial < 1 || $serial > 80000) {      // sane range: 1900 .. ~2119
                return null;
            }
            $ts = ($serial - 25569) * 86400;           // 25569 = serial of 1970-01-01
            return $ts > 0 ? gmdate('Y-m-d', (int)round($ts)) : null;
        }
        $s = trim((string)$v);
        if ($s === '') {
            return null;
        }
        foreach (['d-M-Y H:i:s', 'd-M-Y', 'Y-m-d H:i:s', 'Y-m-d', 'd/m/Y', 'd-m-Y'] as $fmt) {
            $d = DateTime::createFromFormat($fmt, $s);
            if ($d instanceof DateTime) {
                return $d->format('Y-m-d');
            }
        }
        $ts = strtotime($s);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    /* ---------------- header parsing (self-contained, read-only) ---------------- */

    /**
     * Same two-row grouped-header model Pms uses: a "sub" row of
     * Start Date / End Date / Status under a "group" row of step names.
     * Kept local so the analytics can never disturb the pipeline's parser.
     */
    private function headerInfo(array $rows): array
    {
        $lastRow = count($rows);
        $lastCol = 0;
        foreach ($rows as $r) {
            $lastCol = max($lastCol, count($r));
        }
        $scan = min(6, $lastRow);
        if ($scan < 1 || $lastCol < 1) {
            return ['subRowIndex' => 1, 'dataStartRow' => 2, 'lastCol' => $lastCol, 'groupVals' => [], 'subVals' => []];
        }

        $subRow = 0; $bestCount = -1;
        for ($r = 0; $r < $scan; $r++) {
            $count = 0;
            for ($c = 0; $c < $lastCol; $c++) {
                $t = Sheets::normalizeKey($rows[$r][$c] ?? '');
                if ($t === 'status' || $t === 'start date' || $t === 'end date') {
                    $count++;
                }
            }
            if ($count > $bestCount) {
                $bestCount = $count;
                $subRow = $r;
            }
        }
        $groupRow  = $subRow > 0 ? $subRow - 1 : $subRow;
        $subVals   = $this->pad($rows[$subRow] ?? [], $lastCol);
        $groupVals = $this->pad($rows[$groupRow] ?? [], $lastCol);

        // merged group cells: carry the step name across its Start/End/Status trio
        $lastGroup = '';
        for ($c = 0; $c < $lastCol; $c++) {
            if ($groupVals[$c] !== '' && $groupVals[$c] !== null) {
                $lastGroup = $groupVals[$c];
            } else {
                $s = Sheets::normalizeKey($subVals[$c]);
                if (($s === 'status' || $s === 'start date' || $s === 'end date') && $lastGroup !== '') {
                    $groupVals[$c] = $lastGroup;
                }
            }
        }

        return [
            'subRowIndex'  => $subRow + 1,
            'dataStartRow' => $subRow + 2,
            'lastCol'      => $lastCol,
            'groupVals'    => $groupVals,
            'subVals'      => $subVals,
        ];
    }

    private function findNamedCol(array $info, string $name): int
    {
        $n = Sheets::compactKey($name);
        if ($n === '') {
            return -1;
        }
        for ($i = 0; $i < $info['lastCol']; $i++) {
            $g = Sheets::compactKey($info['groupVals'][$i] ?? '');
            $s = Sheets::compactKey($info['subVals'][$i] ?? '');
            if ($s === $n || ($g === $n && $s === '') || ($g . $s) === $n) {
                return $i + 1;
            }
        }
        return -1;
    }

    private function findColContains(array $info, string $needle): int
    {
        $needle = Sheets::normalizeKey($needle);
        if ($needle === '') {
            return -1;
        }
        for ($i = 0; $i < $info['lastCol']; $i++) {
            $g = Sheets::normalizeKey($info['groupVals'][$i] ?? '');
            $s = Sheets::normalizeKey($info['subVals'][$i] ?? '');
            if (($g !== '' && strpos($g, $needle) !== false) || ($s !== '' && strpos($s, $needle) !== false)) {
                return $i + 1;
            }
        }
        return -1;
    }

    private function cell(array $rows, int $row1, int $col1)
    {
        return $rows[$row1 - 1][$col1 - 1] ?? '';
    }

    private function pad(array $arr, int $len): array
    {
        for ($i = 0; $i < $len; $i++) {
            if (!array_key_exists($i, $arr)) {
                $arr[$i] = '';
            }
        }
        ksort($arr);
        return $arr;
    }
}
