<?php
/**
 * PMS progress-sheet updater — port of the progress logic in code.js.
 *
 * On submit, stamps the matching row in a PMS progress sheet:
 *   - General   -> general_pms_sheet_id, matched by Order ID then Project Name
 *   - Developer -> the developer's building tab, matched by Flat No
 * Every column is resolved by HEADER TEXT (two-row grouped headers supported and
 * forward-filled), so adding/reordering sheet columns never breaks this.
 *
 * Note vs Apps Script: writeAllowingCustomList_ existed only to defeat strict
 * dropdown validation on UI entry. The Sheets REST API writes values regardless
 * of a cell's data-validation rule, so a plain setCell() is behaviour-equivalent.
 */
class Pms
{
    /** @var Sheets */
    private $sheets;
    /** @var array */
    private $cfg;

    public function __construct(Sheets $sheets, array $cfg)
    {
        $this->sheets = $sheets;
        $this->cfg = $cfg;
    }

    /**
     * Routes a submission to the right progress sheet/row and stamps it.
     * Never throws — returns ['updated'=>bool, 'warning'=>string] so submit
     * completes even when the flat/project isn't found.
     */
    public function updateProgressSheets(array $p): array
    {
        try {
            if (($p['clientType'] ?? '') === 'Developer') {
                return $this->updateDeveloper($p);
            }
            return $this->updateGeneral($p);
        } catch (Throwable $e) {
            return ['updated' => false, 'warning' => 'Progress sheet update error: ' . $e->getMessage()];
        }
    }

    /**
     * Read-only lookup of steps already marked "Done" for a project/flat, plus the
     * Order ID (found or generated). Drives the front-end's pre-ticked/locked steps.
     * Never throws — returns ['found'=>bool,'doneSteps'=>[],'orderId'=>string].
     */
    public function getProgressState(array $p): array
    {
        try {
            $base = (($p['clientType'] ?? '') === 'Developer')
                ? $this->progressDeveloper($p)
                : $this->progressGeneral($p);
        } catch (Throwable $e) {
            $base = ['found' => false, 'doneSteps' => [], 'orderId' => '', 'tentativeEndDate' => ''];
        }
        // Amendment / Drawing / Measurement come from the RESPONSE sheet (per-submission
        // log), not the PMS sheet — read the latest matching row so the form can skip them.
        $base['prefill'] = $this->getResponsePrefill($p, (string)($base['orderId'] ?? ''));
        return $base;
    }

    /**
     * Latest response-sheet answers for Amendment / Drawing change / Measurement (+ amendment why)
     * for this project (General) or building+flat (Developer). Empty strings when not found.
     */
    private function getResponsePrefill(array $p, string $orderId = ''): array
    {
        $out = ['amendment' => '', 'amendmentWhy' => '', 'drawingChange' => '', 'measurement' => ''];
        try {
            $ssId = $this->cfg['response_sheet_id'];
            // Prefer a tab named after the site type (future Cold Room/Storage/PAC tabs),
            // else the current VRV / Non-VRV split (matches ResponseSheet routing).
            $title = $this->sheets->titleForName($ssId, (string)($p['siteType'] ?? ''));
            if ($title === null) {
                $tab = ($p['siteType'] ?? '') === 'VRV'
                    ? $this->cfg['tab_names']['VRV'] : $this->cfg['tab_names']['NONVRV'];
                $title = $this->sheets->titleForName($ssId, $tab);
            }
            if ($title === null) {
                return $out;
            }
            $rows = $this->sheets->getTab($ssId, $title);
            if (count($rows) < 2) {
                return $out;
            }
            $headers = $rows[0];
            $amoCol = Sheets::findColIndex($headers, 'approval required?', 'why');
            $whyCol = Sheets::findColIndex($headers, 'why');
            $drwCol = Sheets::findColIndex($headers, 'changes in drawing', 'upload photo here');
            $meaCol = Sheets::findColIndex($headers, 'measurement report created today', 'upload the measurement');

            $isDev   = ($p['clientType'] ?? '') === 'Developer';
            $projCol = Sheets::findColIndex($headers, 'select project name');
            $ordCol  = Sheets::findColIndex($headers, 'order id');
            $bldCol  = Sheets::findColIndex($headers, 'building');
            $flatCol = Sheets::findColIndex($headers, 'flat no');
            $wantProj = Sheets::normalizeKey($p['project'] ?? '');
            $wantBld  = Sheets::normalizeKey($p['building'] ?? '');
            $wantFlat = Sheets::normalizeKey($p['flatNo'] ?? '');
            // Order ID (col B) is the exact key for BOTH general (Orders-sheet ID)
            // and developer (building+flat ID) — the same value we stamp on the row.
            $wantOrder = Sheets::normalizeKey($orderId);

            $match = -1;
            for ($r = count($rows) - 1; $r >= 1; $r--) {   // latest first
                $row = $rows[$r];
                // Primary: Order ID match (works for both client types).
                if ($ordCol > -1 && $wantOrder !== ''
                    && Sheets::normalizeKey($row[$ordCol] ?? '') === $wantOrder) {
                    $match = $r; break;
                }
                // Fallback for rows written before Order ID was stamped.
                if ($isDev) {
                    if ($bldCol > -1 && $flatCol > -1 && $wantFlat !== ''
                        && Sheets::normalizeKey($row[$bldCol] ?? '') === $wantBld
                        && Sheets::normalizeKey($row[$flatCol] ?? '') === $wantFlat) {
                        $match = $r; break;
                    }
                } elseif ($projCol > -1 && $wantProj !== ''
                    && Sheets::normalizeKey($row[$projCol] ?? '') === $wantProj) {
                    $match = $r; break;
                }
            }
            if ($match < 0) {
                return $out;
            }
            $row = $rows[$match];
            $val = function (int $c) use ($row) {
                $v = $c > -1 ? trim((string)($row[$c] ?? '')) : '';
                return strcasecmp($v, 'N/A') === 0 ? '' : $v;
            };
            $out['amendment']    = $val($amoCol);
            $out['amendmentWhy'] = $val($whyCol);
            $out['drawingChange']= $val($drwCol);
            $out['measurement']  = $val($meaCol);
        } catch (Throwable $e) {
            // best-effort: no prefill on error
        }
        return $out;
    }

    /** Flats for a developer building: [['flat'=>'D-102','floor'=>'1st floor'], ...] (floor forward-filled over merged cells). */
    public function getFlats(string $developer, string $building): array
    {
        try {
            $dev = $this->cfg['developer_building_sheets'][$developer] ?? null;
            if (!$dev || empty($dev['spreadsheetId'])) {
                return [];
            }
            $ssId = $dev['spreadsheetId'];
            $title = $this->sheets->titleForName($ssId, $building);
            if ($title === null) {
                return [];
            }
            $rows = $this->sheets->getTab($ssId, $title);
            $info = $this->headerInfo($rows);
            $flatCol = $this->findNamedCol($info, 'Flat No');
            if ($flatCol < 1) {
                return [];
            }
            $floorCol = $this->findNamedCol($info, 'Floor');
            $out = [];
            $seen = [];
            $lastFloor = '';
            for ($r = $info['dataStartRow']; $r <= count($rows); $r++) {
                if ($floorCol > 0) {
                    $fv = trim((string)$this->cell($rows, $r, $floorCol));
                    if ($fv !== '') {
                        $lastFloor = $fv;
                    }
                }
                $flat = trim((string)$this->cell($rows, $r, $flatCol));
                if ($flat === '') {
                    continue;
                }
                $key = Sheets::compactKey($flat);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = ['flat' => $flat, 'floor' => $lastFloor];
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    private function progressDeveloper(array $p): array
    {
        $orderId = $this->makeDeveloperOrderId((string)($p['building'] ?? ''), (string)($p['flatNo'] ?? ''));
        $empty = ['found' => false, 'doneSteps' => [], 'orderId' => $orderId];

        $dev = $this->cfg['developer_building_sheets'][$p['developer'] ?? ''] ?? null;
        if (!$dev || empty($dev['spreadsheetId'])) {
            return $empty;
        }
        $ssId = $dev['spreadsheetId'];
        $title = $this->sheets->titleForName($ssId, (string)($p['building'] ?? ''));
        if ($title === null) {
            return $empty;
        }
        $rows = $this->sheets->getTab($ssId, $title);
        $info = $this->headerInfo($rows);
        $flatCol = $this->findNamedCol($info, 'Flat No');
        if ($flatCol < 1) {
            return $empty;
        }
        $devRow = $this->findFlatRow($rows, $info, $flatCol, (string)($p['flatNo'] ?? ''));
        if ($devRow < 0) {
            return $empty;
        }

        // Prefer an Order ID already written in the sheet, else show the generated one.
        $orderCol = $this->findOrderIdCol($info);
        if ($orderCol < 1) {
            $orderCol = $this->findNamedCol($info, 'OrderID');
        }
        if ($orderCol > 0) {
            $existing = trim((string)$this->cell($rows, $devRow, $orderCol));
            if ($existing !== '') {
                $orderId = $existing;
            }
        }

        return [
            'found'            => true,
            'doneSteps'        => $this->readDoneSteps($rows, $devRow, $info),
            'orderId'          => $orderId,
            'tentativeEndDate' => $this->readTentative($rows, $devRow, $info),
        ];
    }

    private function progressGeneral(array $p): array
    {
        $empty = ['found' => false, 'doneSteps' => [], 'orderId' => ''];

        $ssId = $this->cfg['general_pms_sheet_id'];
        $tabName = ($p['siteType'] ?? '') === 'VRV'
            ? $this->cfg['general_pms_tabs']['VRV']
            : $this->cfg['general_pms_tabs']['NONVRV'];
        $title = $this->sheets->titleForName($ssId, $tabName);
        if ($title === null) {
            return $empty;
        }
        $rows = $this->sheets->getTab($ssId, $title);
        $info = $this->headerInfo($rows);

        $pmsRow = -1;
        $orderId = $this->getOrderIdForProject((string)($p['siteType'] ?? ''), (string)($p['project'] ?? ''));
        if ($orderId !== '') {
            $orderCol = $this->findOrderIdCol($info);
            if ($orderCol > 0) {
                $pmsRow = $this->findRowByColValue($rows, $info, $orderCol, $orderId);
            }
        }
        if ($pmsRow < 0) {
            $projCol = $this->findNamedCol($info, 'Project Name');
            $pmsRow = $this->findRowByColValue($rows, $info, $projCol, (string)($p['project'] ?? ''));
        }
        if ($pmsRow < 0) {
            return ['found' => false, 'doneSteps' => [], 'orderId' => $orderId];
        }
        return [
            'found'            => true,
            'doneSteps'        => $this->readDoneSteps($rows, $pmsRow, $info),
            'orderId'          => $orderId,
            'tentativeEndDate' => $this->readTentative($rows, $pmsRow, $info),
        ];
    }

    /** Reads the "Tentitive Project End date" cell, converting a Sheets serial to Y-m-d. */
    private function readTentative(array $rows, int $row, array $info): string
    {
        $col = $this->findColContains($info, 'tentative');
        if ($col < 1) {
            $col = $this->findColContains($info, 'tentitive'); // the sheet's actual spelling
        }
        if ($col < 1) {
            return '';
        }
        $v = $this->cell($rows, $row, $col);
        if (is_numeric($v)) {
            // Google Sheets serial date -> Y-m-d (25569 = serial of 1970-01-01).
            $ts = ((float)$v - 25569) * 86400;
            if ($ts > 0) {
                return gmdate('Y-m-d', (int)round($ts));
            }
        }
        return trim((string)$v);
    }

    /** First column whose group/sub header contains a substring (normalized). 1-based, or -1. */
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

    /** Non-step single-column headers to ignore when detecting date-type "done" steps. */
    private const NON_STEP_COLS = [
        'timestamp', 'orderid', 'order id', 'project exective by', 'project executive by',
        'project name', 'tentitive project end date', 'tentative project end date',
        'remarks', 'work done by', 'email address', 'email', 'shipping address',
        'total order value', 'sales person', 'order type', 'floor', 'flat no',
    ];

    /**
     * Step names counted as done on a row:
     *   - grouped steps whose "Status" sub-cell reads "Done", plus
     *   - single-column DATE steps (e.g. "LS Material Delivery") that hold any value.
     * Over-returning is harmless: the front-end only locks names in its STATUS_STEPS.
     */
    private function readDoneSteps(array $rows, int $row, array $info): array
    {
        $out = [];
        $seen = [];
        $add = function (string $name) use (&$out, &$seen) {
            $key = Sheets::compactKey($name);
            if ($name === '' || isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $out[] = $name;
        };

        for ($i = 0; $i < $info['lastCol']; $i++) {
            $sub = Sheets::normalizeKey($info['subVals'][$i] ?? '');
            $name = trim((string)($info['groupVals'][$i] ?? ''));
            if ($sub === 'status') {
                if (Sheets::normalizeKey($this->cell($rows, $row, $i + 1)) === 'done') {
                    $add($name);
                }
                continue;
            }
            // Single-column date step: no sub-label, a real (non-base) header, value present.
            if ($sub !== '' || $name === '' || in_array(Sheets::normalizeKey($name), self::NON_STEP_COLS, true)) {
                continue;
            }
            if (trim((string)$this->cell($rows, $row, $i + 1)) !== '') {
                $add($name);
            }
        }
        return $out;
    }

    /** building word-initials + flat, e.g. "Balmoral River side D-wing" + "D-102" -> "BRSDW-D-102". */
    private function makeDeveloperOrderId(string $building, string $flat): string
    {
        $flat = strtoupper(trim($flat));
        if ($flat === '') {
            return '';
        }
        $tokens = preg_split('/[\s\-]+/', trim($building), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $initials = '';
        foreach ($tokens as $t) {
            if (preg_match('/[A-Za-z0-9]/', $t, $m)) {
                $initials .= strtoupper($m[0]);
            }
        }
        return $initials !== '' ? $initials . '-' . $flat : $flat;
    }

    /**
     * Per-step {step,status,holdReason,holdReasonDetail} list to stamp this visit.
     * Prefers payload stepStatuses[]; falls back to legacy doneSteps[]/currentStatus + single status.
     */
    private function normalizeStepStatuses(array $p): array
    {
        $out = [];
        $raw = $p['stepStatuses'] ?? [];
        if (is_array($raw) && $raw) {
            foreach ($raw as $e) {
                if (!is_array($e)) {
                    continue;
                }
                $step = trim((string)($e['step'] ?? ''));
                if ($step === '') {
                    continue;
                }
                $out[] = [
                    'step'             => $step,
                    'status'           => trim((string)($e['status'] ?? ($p['status'] ?? ''))),
                    'holdReason'       => (string)($e['holdReason'] ?? ''),
                    'holdReasonDetail' => (string)($e['holdReasonDetail'] ?? ''),
                ];
            }
            if ($out) {
                return $out;
            }
        }

        // Legacy fallback: doneSteps[] (or comma list) with one shared status.
        $steps = $p['doneSteps'] ?? [];
        if (!is_array($steps) || !$steps) {
            $steps = !empty($p['currentStatus']) ? array_map('trim', explode(',', (string)$p['currentStatus'])) : [];
        }
        $status = (string)($p['status'] ?? '');
        foreach ($steps as $s) {
            $s = trim((string)$s);
            if ($s === '') {
                continue;
            }
            $out[] = [
                'step'             => $s,
                'status'           => $status,
                'holdReason'       => (string)($p['holdReason'] ?? ''),
                'holdReasonDetail' => (string)($p['holdReasonDetail'] ?? ''),
            ];
        }
        return $out;
    }

    /* ---------------- Developer ---------------- */

    private function updateDeveloper(array $p): array
    {
        $dev = $this->cfg['developer_building_sheets'][$p['developer'] ?? ''] ?? null;
        if (!$dev || empty($dev['spreadsheetId'])) {
            return $this->skip('No progress sheet configured for developer "' . ($p['developer'] ?? '') . '".');
        }
        $ssId = $dev['spreadsheetId'];
        $title = $this->sheets->titleForName($ssId, (string)($p['building'] ?? ''));
        if ($title === null) {
            return $this->skip('Building tab "' . ($p['building'] ?? '') . '" not found in ' . $p['developer'] . "'s progress sheet.");
        }
        $rows = $this->sheets->getTab($ssId, $title);
        $info = $this->headerInfo($rows);

        $flatCol = $this->findNamedCol($info, 'Flat No');
        if ($flatCol < 1) {
            return $this->skip('No "Flat No" column found in building tab "' . $p['building'] . '".');
        }
        $devRow = $this->findFlatRow($rows, $info, $flatCol, (string)($p['flatNo'] ?? ''));
        if ($devRow < 0) {
            return $this->skip('Flat "' . ($p['flatNo'] ?? '') . '" not found in building "' . $p['building'] . '". Progress sheet not updated — check the flat number.');
        }

        // Developer sheets carry no Order ID — stamp a deterministic building+flat
        // ID into the OrderID column if it's still empty (for cross-report tracking).
        $orderId = $this->makeDeveloperOrderId((string)($p['building'] ?? ''), (string)($p['flatNo'] ?? ''));
        $orderCol = $this->findOrderIdCol($info);
        if ($orderCol < 1) {
            $orderCol = $this->findNamedCol($info, 'OrderID');
        }
        if ($orderCol > 0 && $orderId !== '') {
            $cur = $this->cell($rows, $devRow, $orderCol);
            if ($cur === '' || $cur === null) {
                $this->sheets->setCell($ssId, $title, $devRow, $orderCol, $orderId);
            } else {
                $orderId = trim((string)$cur); // keep whatever is already there
            }
        }

        $this->updateRow($ssId, $title, $rows, $devRow, $info, $p, true);
        return ['updated' => true, 'warning' => '', 'order_id' => $orderId];
    }

    /* ---------------- General ---------------- */

    private function updateGeneral(array $p): array
    {
        $ssId = $this->cfg['general_pms_sheet_id'];
        $tabName = ($p['siteType'] ?? '') === 'VRV'
            ? $this->cfg['general_pms_tabs']['VRV']
            : $this->cfg['general_pms_tabs']['NONVRV'];
        $title = $this->sheets->titleForName($ssId, $tabName);
        if ($title === null) {
            return $this->skip('PMS tab "' . $tabName . '" not found.');
        }
        $rows = $this->sheets->getTab($ssId, $title);
        $info = $this->headerInfo($rows);

        // Match by Order ID first (exact shared key), fall back to project name.
        $pmsRow = -1;
        $orderId = $this->getOrderIdForProject((string)($p['siteType'] ?? ''), (string)($p['project'] ?? ''));
        if ($orderId !== '') {
            $orderCol = $this->findOrderIdCol($info);
            if ($orderCol > 0) {
                $pmsRow = $this->findRowByColValue($rows, $info, $orderCol, $orderId);
            }
        }
        if ($pmsRow < 0) {
            $projCol = $this->findNamedCol($info, 'Project Name');
            $pmsRow = $this->findRowByColValue($rows, $info, $projCol, (string)($p['project'] ?? ''));
        }
        if ($pmsRow < 0) {
            return $this->skip('Project "' . ($p['project'] ?? '') . '"'
                . ($orderId !== '' ? ' (Order ID ' . $orderId . ')' : '')
                . ' not found in ' . $tabName . '. Progress sheet not updated.');
        }
        $this->updateRow($ssId, $title, $rows, $pmsRow, $info, $p, false);
        return ['updated' => true, 'warning' => '', 'order_id' => $orderId];
    }

    /* ---------------- write one PMS row (updatePmsRow_) ---------------- */

    private function updateRow(string $ssId, string $title, array $rows, int $row, array $info, array $p, bool $isDeveloper): void
    {
        $setByName = function (string $name, $val) use ($ssId, $title, $info, $row) {
            if ($val === '' || $val === null) {
                return;
            }
            $col = $this->findNamedCol($info, $name);
            if ($col > 0) {
                $this->sheets->setCell($ssId, $title, $row, $col, $val);
            }
        };

        if ($isDeveloper) {
            $setByName('Timestamp', $this->now());
            $setByName('Project Exective By', $p['engineer'] ?? '');
        }

        // Stamp EACH ticked step with ITS OWN status (Done / Pending / Hold).
        $entries = $this->normalizeStepStatuses($p);
        $holdEntries = [];
        foreach ($entries as $e) {
            $step = $e['step'];
            $stat = $e['status'];
            if ($step === '' || $stat === '') {
                continue;
            }
            $statusCol = $this->findStepStatusCol($info, $step);
            if ($statusCol < 1) {
                continue;
            }
            // Single-column DATE steps (e.g. "LS Material Delivery") have no Status
            // sub-header — they store a DATE, so writing "Done" is invalid. Stamp the
            // date when marked Done (only if still empty); ignore Pending/Hold there.
            if (Sheets::normalizeKey($info['subVals'][$statusCol - 1] ?? '') !== 'status') {
                if ($stat === 'Done') {
                    $cur = $this->cell($rows, $row, $statusCol);
                    if ($cur === '' || $cur === null) {
                        $this->sheets->setCell($ssId, $title, $row, $statusCol, $this->today());
                    }
                }
                continue;
            }

            $cellVal = ($stat === 'Hold') ? ($e['holdReason'] ?: 'Hold') : $stat;
            $this->sheets->setCell($ssId, $title, $row, $statusCol, $cellVal);

            if ($stat === 'Done') {
                $endCol = $this->findStepSubCol($info, $step, 'End Date');
                if ($endCol > 0) {
                    $cur = $this->cell($rows, $row, $endCol);
                    if ($cur === '' || $cur === null) {
                        $this->sheets->setCell($ssId, $title, $row, $endCol, $this->now());
                    }
                }
            }
            if ($stat === 'Hold') {
                $holdEntries[] = $e;
            }
        }

        // Plan-for-tomorrow: stamp Start Date on each planned next step (only if the
        // cell is empty), mirroring the End-Date-on-Done logic above. One common date
        // for all picked steps. First-process step (LS Material Delivery) is excluded
        // client-side and, as a single-column date step, has no Start Date sub-col.
        $startDate = $this->toSheetDate((string)($p['nextStepStartDate'] ?? ''));
        if ($startDate !== '') {
            foreach ((array)($p['tomorrowSteps'] ?? []) as $tStep) {
                $tStep = (string)$tStep;
                if ($tStep === '') {
                    continue;
                }
                $startCol = $this->findStepSubCol($info, $tStep, 'Start Date');
                if ($startCol < 1) {
                    continue;
                }
                $cur = $this->cell($rows, $row, $startCol);
                if ($cur === '' || $cur === null) {
                    $this->sheets->setCell($ssId, $title, $row, $startCol, $startDate);
                }
            }
        }

        // Hold -> one Remarks line per held step; otherwise clear a stale hold remark.
        if ($holdEntries) {
            $parts = [];
            foreach ($holdEntries as $e) {
                $line = $e['step'];
                if (!empty($e['holdReasonDetail'])) {
                    $line .= ': ' . $e['holdReasonDetail'];
                }
                if (preg_match('/by\s+(.+)$/i', (string)($e['holdReason'] ?? ''), $m)) {
                    $line .= ' (stuck by ' . trim($m[1]) . ')';
                }
                $parts[] = $line;
            }
            $setByName('Remarks', implode(' | ', $parts));
        } elseif ($entries) {
            $remCol = $this->findNamedCol($info, 'Remarks');
            if ($remCol > 0) {
                $this->sheets->setCell($ssId, $title, $row, $remCol, '');
            }
        }

        // Work Done BY is now a per-person summary string (see SubmitService payload).
        $wdb = (string)($p['workDoneBy'] ?? '');
        if ($wdb !== '') {
            $wCol = $this->findNamedCol($info, 'Work Done BY');
            if ($wCol > 0) {
                $this->sheets->setCell($ssId, $title, $row, $wCol, $wdb);
            }
        }

        if (!empty($p['tentativeEndDate'])) {
            $setByName('Tentitive Project End date', $p['tentativeEndDate']);
        }
    }

    /* ---------------- header parsing (getPmsHeaderInfo_) ---------------- */

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
        $groupRow = $subRow > 0 ? $subRow - 1 : $subRow;
        $subVals   = $this->pad($rows[$subRow] ?? [], $lastCol);
        $groupVals = $this->pad($rows[$groupRow] ?? [], $lastCol);

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

    /* ---------------- column finders ---------------- */

    private function findStepStatusCol(array $info, string $stepName): int
    {
        $step = Sheets::compactKey($stepName);
        if ($step === '') {
            return -1;
        }
        for ($i = 0; $i < $info['lastCol']; $i++) {
            if (Sheets::compactKey($info['groupVals'][$i]) === $step
                && Sheets::normalizeKey($info['subVals'][$i]) === 'status') {
                return $i + 1;
            }
        }
        for ($i = 0; $i < $info['lastCol']; $i++) {
            $g = Sheets::compactKey($info['groupVals'][$i]);
            $s = Sheets::compactKey($info['subVals'][$i]);
            if (($g === $step && $s === '') || $s === $step) {
                return $i + 1;
            }
        }
        return -1;
    }

    private function findStepSubCol(array $info, string $stepName, string $subLabel): int
    {
        $step = Sheets::compactKey($stepName);
        $sub  = Sheets::normalizeKey($subLabel);
        if ($step === '' || $sub === '') {
            return -1;
        }
        for ($i = 0; $i < $info['lastCol']; $i++) {
            if (Sheets::compactKey($info['groupVals'][$i]) === $step
                && Sheets::normalizeKey($info['subVals'][$i]) === $sub) {
                return $i + 1;
            }
        }
        return -1;
    }

    private function findNamedCol(array $info, string $name): int
    {
        $n = Sheets::compactKey($name);
        if ($n === '') {
            return -1;
        }
        for ($i = 0; $i < $info['lastCol']; $i++) {
            $g = Sheets::compactKey($info['groupVals'][$i]);
            $s = Sheets::compactKey($info['subVals'][$i]);
            if ($s === $n || ($g === $n && $s === '') || ($g . $s) === $n) {
                return $i + 1;
            }
        }
        return -1;
    }

    private function isOrderIdHeader(string $text): bool
    {
        $t = Sheets::normalizeKey($text);
        if ($t === '' || strpos($t, 'order') === false) {
            return false;
        }
        if (strpos($t, 'date') !== false) {
            return false;
        }
        return $t === 'order'
            || strpos($t, 'orderid') !== false
            || (bool)preg_match('/(^|[^a-z])(id|no|no\.|number|code|ref)([^a-z]|$)/', $t);
    }

    private function findOrderIdCol(array $info): int
    {
        for ($i = 0; $i < $info['lastCol']; $i++) {
            if ($this->isOrderIdHeader((string)$info['subVals'][$i]) || $this->isOrderIdHeader((string)$info['groupVals'][$i])) {
                return $i + 1;
            }
        }
        return -1;
    }

    /* ---------------- row finders ---------------- */

    private function findRowByColValue(array $rows, array $info, int $colIndex, string $wanted): int
    {
        if ($colIndex < 1) {
            return -1;
        }
        $w = Sheets::normalizeKey($wanted);
        if ($w === '') {
            return -1;
        }
        for ($r = $info['dataStartRow']; $r <= count($rows); $r++) {
            if (Sheets::normalizeKey($this->cell($rows, $r, $colIndex)) === $w) {
                return $r;
            }
        }
        return -1;
    }

    /** Flat-tolerant finder: exact/compact match, else same-digits when wings don't conflict, unique-hit only. */
    private function findFlatRow(array $rows, array $info, int $colIndex, string $wanted): int
    {
        if ($colIndex < 1) {
            return -1;
        }
        $wNorm = Sheets::normalizeKey($wanted);
        if ($wNorm === '') {
            return -1;
        }
        $wComp    = Sheets::compactKey($wanted);
        $wLetters = preg_replace('/[^a-z]/', '', $wNorm);
        $wDigits  = preg_replace('/\D/', '', $wNorm);

        $digitRow = -1; $digitHits = 0;
        for ($r = $info['dataStartRow']; $r <= count($rows); $r++) {
            $cell = $this->cell($rows, $r, $colIndex);
            if ($cell === '' || $cell === null) {
                continue;
            }
            $cNorm = Sheets::normalizeKey($cell);
            if ($cNorm === $wNorm || Sheets::compactKey($cell) === $wComp) {
                return $r;
            }
            $cLetters = preg_replace('/[^a-z]/', '', $cNorm);
            $cDigits  = preg_replace('/\D/', '', $cNorm);
            $lettersOk = ($wLetters === '' || $cLetters === '' || $wLetters === $cLetters);
            if ($lettersOk && $wDigits !== '' && $cDigits === $wDigits) {
                $digitHits++;
                if ($digitRow < 0) {
                    $digitRow = $r;
                }
            }
        }
        return $digitHits === 1 ? $digitRow : -1;
    }

    /* ---------------- Order ID from Orders sheet (getOrderIdForProject_) ---------------- */

    private function getOrderIdForProject(string $siteType, string $projectName): string
    {
        $want = Sheets::normalizeKey($projectName);
        if ($want === '') {
            return '';
        }
        try {
            $isVRV = ($siteType === 'VRV');
            $ssId = $isVRV ? $this->cfg['vrv_orders_sheet_id'] : $this->cfg['nonvrv_orders_sheet_id'];
            $gid  = $isVRV ? $this->cfg['vrv_orders_gid'] : $this->cfg['nonvrv_orders_gid'];
            $title = $this->sheets->titleForGid($ssId, (int)$gid);
            if ($title === null) {
                return '';
            }
            $rows = $this->sheets->getTab($ssId, $title);
            if (count($rows) < 2) {
                return '';
            }
            $headers = $rows[0];
            $orderCol = -1;
            $nameCols = [];
            foreach ($headers as $i => $h) {
                if ($orderCol < 0 && $this->isOrderIdHeader((string)$h)) {
                    $orderCol = $i;
                }
                $hl = strtolower((string)$h);
                if (strpos($hl, 'select project name') !== false
                    || (strpos($hl, 'project name') !== false && strpos($hl, 'executive') === false)
                    || strpos($hl, 'billing customer name') !== false) {
                    $nameCols[] = $i;
                }
            }
            if ($orderCol < 0 || !$nameCols) {
                return '';
            }
            for ($r = 1; $r < count($rows); $r++) {
                foreach ($nameCols as $c) {
                    if (Sheets::normalizeKey($rows[$r][$c] ?? '') === $want) {
                        $oid = $rows[$r][$orderCol] ?? '';
                        return trim((string)$oid);
                    }
                }
            }
            return '';
        } catch (Throwable $e) {
            return '';
        }
    }

    /* ---------------- misc ---------------- */

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

    private function now(): string
    {
        return (new DateTime('now', new DateTimeZone($this->cfg['timezone'])))->format('d-M-Y H:i:s');
    }

    /** Date only (no time) — for single-column date cells with strict date validation. */
    private function today(): string
    {
        return (new DateTime('now', new DateTimeZone($this->cfg['timezone'])))->format('d-M-Y');
    }

    /** Convert an input[type=date] value (YYYY-MM-DD) to sheet date format d-M-Y. */
    private function toSheetDate(string $ymd): string
    {
        $ymd = trim($ymd);
        if ($ymd === '') {
            return '';
        }
        $d = DateTime::createFromFormat('Y-m-d', $ymd, new DateTimeZone($this->cfg['timezone']));
        return $d ? $d->format('d-M-Y') : $ymd;
    }

    private function skip(string $msg): array
    {
        return ['updated' => false, 'warning' => $msg];
    }
}
