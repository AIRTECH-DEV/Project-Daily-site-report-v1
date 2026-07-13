<?php
/**
 * Writes one submission row into the RESPONSE spreadsheet (VRV / Non-VRV tab).
 * Column placement is by HEADER TEXT exactly like code.js submitSiteReport(), so
 * reordering sheet columns never breaks it. Returns the 1-based row written.
 */
class ResponseSheet
{
    /** @var Sheets */
    private $sheets;
    /** @var array app config */
    private $cfg;

    public function __construct(Sheets $sheets, array $cfg)
    {
        $this->sheets = $sheets;
        $this->cfg = $cfg;
    }

    /**
     * @param array  $p         submission payload
     * @param string $projectName resolved project/developer name for the row
     * @param array  $urls      ['site'=>[url,...], 'drawing'=>?url, 'measurement'=>?url]
     * @param string $email     submitter email (or 'unknown')
     * @return array [tabName, rowNumber, headers, rowValues]
     */
    public function writeRow(array $p, string $projectName, array $urls, string $email): array
    {
        $ssId = $this->cfg['response_sheet_id'];
        $tab  = ($p['siteType'] ?? '') === 'VRV'
            ? $this->cfg['tab_names']['VRV']
            : $this->cfg['tab_names']['NONVRV'];

        $rows = $this->sheets->getTab($ssId, $tab);
        if (!$rows) {
            throw new RuntimeException("Response tab \"$tab\" has no header row.");
        }
        $headers = $rows[0];
        $row = array_fill(0, count($headers), '');

        $set = function (int $idx, $value) use (&$row) {
            if ($idx > -1) {
                $row[$idx] = $value;
            }
        };
        $H = fn(string $inc, ?string $exc = null) => Sheets::findColIndex($headers, $inc, $exc);

        $status  = $p['status'] ?? '';
        $isHold  = ($status === 'Hold');
        $workBy  = ($p['workDoneBy'] ?? '') === 'Contractor'
            ? ($p['contractorName'] ?: 'Contractor')
            : ($p['workDoneBy'] ?? '');

        $set($H('timestamp'), $this->now());
        $set($H('email address'), $email);
        $set($H('site type'), $p['siteType'] ?? 'Non-VRV');
        $set($H('client type'), $p['clientType'] ?? 'General');
        $set($H('developer'), $p['developer'] ?? '');
        $set($H('building'), $p['building'] ?? '');
        $set($H('floor'), $p['floor'] ?? '');
        $set($H('flat no'), $p['flatNo'] ?? '');
        $set($H('current status'), $p['currentStatus'] ?? '');
        $set($H('work done by'), $workBy);
        $set($H('tentative project end date'), !empty($p['tentativeEndDate']) ? $p['tentativeEndDate'] : '');
        $set($H('hold reason', 'detail'), $isHold ? ($p['holdReason'] ?? '') : '');
        $set($H('hold reason detail'), $isHold ? ($p['holdReasonDetail'] ?? '') : '');
        $set($H('select project name'), $projectName);
        $set($H('number of people'), $p['people'] ?? '');
        $set($H('project engineer name'), $p['engineer'] ?? '');
        $set($H("today's activity"), $p['activity'] ?? '');
        $set($H('upload site photo'), implode(', ', $urls['site'] ?? []));
        $set($H('what is the next plan'), $p['nextPlan'] ?? '');
        $set($H('approval required?', 'why'), $p['amendment'] ?? 'No');
        $set($H('why'), ($p['amendment'] ?? '') === 'Yes' ? ($p['amendmentWhy'] ?? '') : 'N/A');
        $set($H('changes in drawing', 'upload photo here'), $p['drawingChange'] ?? 'No');
        $set($H('upload photo here'), $urls['drawing'] ?? 'N/A');
        $set($H('measurement report created today', 'upload the measurement'), $p['measurement'] ?? 'No');
        $set($H('upload the measurement report here'), $urls['measurement'] ?? 'N/A');
        $set($H('mail status'), 'PENDING');

        $updatedRange = $this->sheets->appendRow($ssId, $tab, $row);
        $rowNum = $this->rowFromRange($updatedRange);

        return [$tab, $rowNum, $headers, $row];
    }

    /** Stamp a header-named cell on an existing response row (PDF ID, Mail Status...). */
    public function stampCell(string $tab, int $rowNum, array $headers, string $headerInc, $value, ?string $headerExc = null): void
    {
        $idx = Sheets::findColIndex($headers, $headerInc, $headerExc);
        if ($idx > -1) {
            $this->sheets->setCell($this->cfg['response_sheet_id'], $tab, $rowNum, $idx + 1, $value);
        }
    }

    private function now(): string
    {
        // USER_ENTERED + this format lets Sheets store a real datetime, IST.
        return (new DateTime('now', new DateTimeZone($this->cfg['timezone'])))->format('d-M-Y H:i:s');
    }

    /** 'VRV!A5:AC5' -> 5 */
    private function rowFromRange(string $range): int
    {
        if (preg_match('/![A-Z]+(\d+)/', $range, $m)) {
            return (int)$m[1];
        }
        return 0;
    }
}
