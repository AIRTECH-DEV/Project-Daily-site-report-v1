<?php
/**
 * Submission pipeline, split so the HTTP submit returns instantly:
 *
 *   enqueue()          — fast: create the DB submission + write the job file. (submit)
 *   runCore()          — photos -> Drive, response row, PMS stamp, PDF, PDF -> Drive. (worker)
 *   runNotifications() — email + WhatsApp, ~notify_delay_seconds after submit.  (worker)
 *
 * handle() runs all three inline (used by CLI tests); the web submit only calls
 * enqueue() and lets the background Worker do runCore()/runNotifications().
 * Mirrors code.js submitSiteReport() but every step is recorded in the tracker DB
 * and Drive/notification latency no longer blocks the submitter.
 */
class SubmitService
{
    /** @var Bootstrap */ private $app;
    /** @var Sheets */    private $sheets;
    /** @var Drive */     private $drive;
    /** @var array */     private $cfg;
    /** @var JobQueue */  private $queue;
    /** @var ?bool */     private $driveReady = null;

    public function __construct(Bootstrap $app)
    {
        $this->app = $app;
        $this->sheets = $app->sheets;
        $this->drive = $app->drive;
        $this->cfg = $app->cfg;
        $this->queue = new JobQueue($this->cfg['queue_dir']);
    }

    /* ================= FAST PATH (submit) ================= */

    /** Captures the submission and returns immediately. */
    public function enqueue(array $p, array $meta): array
    {
        $tracker = new Tracker($this->app->db());
        [$subId, $publicId] = $tracker->startSubmission($p, $meta['email'] ?? null, $meta['ip'] ?? null);
        $tracker->updateSubmission(['overall_status' => 'queued']);

        $this->queue->save([
            'public_id'     => $publicId,
            'submission_id' => $subId,
            'meta'          => $meta,
            'payload'       => $p,
            'state'         => 'queued',
            'created_at'    => time(),
            'notify_after'  => null,
            'core'          => null,
        ]);

        return ['submission_id' => $subId, 'public_id' => $publicId];
    }

    /* ================= CORE (worker phase 1) ================= */

    /**
     * Photos, response row, PMS, PDF. Mutates $job (fills core, advances state).
     * @return array ['warnings'=>[], 'fatal'=>?string]
     */
    public function runCore(array &$job): array
    {
        $p = $job['payload'];
        $meta = $job['meta'] ?? [];
        $tracker = new Tracker($this->app->db());
        $tracker->bind((int)$job['submission_id']);
        $tracker->updateSubmission(['overall_status' => 'processing']);

        $isDeveloper = ($p['clientType'] ?? '') === 'Developer';
        $projectName = trim((string)($isDeveloper
            ? ($p['developer'] ?: 'General_Reports')
            : ($p['project'] ?: 'General_Reports')));
        $warnings = [];

        // 1) Photos -> Drive
        $urls = ['site' => [], 'drawing' => null, 'measurement' => null];
        $folderId = null;
        $log = $tracker->stepStart('photo_save', $projectName);
        try {
            if ($this->isDriveReady()) {
                $folderId = $this->drive->getOrCreateProjectFolder($projectName);
                foreach (($p['photos'] ?? []) as $i => $f) {
                    $saved = $this->drive->saveBase64File($f, $folderId, 'SitePhoto_' . ($i + 1));
                    if ($saved) { $urls['site'][] = $saved['url']; $tracker->addAttachment('site_photo', $saved); }
                }
                if (($p['drawingChange'] ?? '') === 'Yes' && !empty($p['drawingPhoto'])) {
                    $saved = $this->drive->saveBase64File($p['drawingPhoto'], $folderId, 'DrawingChange');
                    if ($saved) { $urls['drawing'] = $saved['url']; $tracker->addAttachment('drawing', $saved); }
                }
                if (($p['measurement'] ?? '') === 'Yes' && !empty($p['measurementFile'])) {
                    $saved = $this->drive->saveBase64File($p['measurementFile'], $folderId, 'MeasurementReport');
                    if ($saved) { $urls['measurement'] = $saved['url']; $tracker->addAttachment('measurement', $saved); }
                }
                $tracker->stepDone($log, count($urls['site']) . ' photo(s) uploaded', 'folder ' . $folderId);
            } else {
                $tracker->stepSkipped($log, 'Shared Drive not configured — photos not uploaded.');
                $warnings[] = 'Photos not uploaded (Shared Drive not configured).';
            }
        } catch (Throwable $e) {
            $tracker->stepFailed($log, $e->getMessage());
            $warnings[] = 'Photo upload failed: ' . $e->getMessage();
        }

        // 2) Response row
        $log = $tracker->stepStart('sheet_write');
        try {
            $writer = new ResponseSheet($this->sheets, $this->cfg);
            [$tab, $rowNum, $headers, $rowValues] =
                $writer->writeRow($p, $projectName, $urls, $meta['email'] ?? 'unknown');
            $tracker->stepDone($log, 'row written', "$tab!$rowNum");
            $tracker->updateSubmission(['response_tab' => $tab, 'response_row' => $rowNum]);
        } catch (Throwable $e) {
            $tracker->stepFailed($log, $e->getMessage());
            $tracker->updateSubmission(['overall_status' => 'failed']);
            return ['warnings' => $warnings, 'fatal' => 'Failed writing response row: ' . $e->getMessage()];
        }

        // 3) PMS
        $log = $tracker->stepStart('pms_update');
        try {
            $res = (new Pms($this->sheets, $this->cfg))->updateProgressSheets($p);
            if (!empty($res['order_id'])) { $tracker->updateSubmission(['order_id' => $res['order_id']]); }
            if ($res['updated']) { $tracker->stepDone($log, 'PMS row stamped'); }
            else { $tracker->stepSkipped($log, $res['warning'] ?: 'not updated'); $warnings[] = $res['warning']; }
        } catch (Throwable $e) {
            $tracker->stepFailed($log, $e->getMessage());
            $warnings[] = 'PMS update failed: ' . $e->getMessage();
        }

        // 4) PDF (+ Drive upload)
        $pdfUrl = ''; $pdfPath = ''; $pdfDriveId = '';
        $log = $tracker->stepStart('pdf');
        try {
            $pdfPath = $this->buildPdf($projectName, $job['public_id'], $headers, $rowValues, $p);
            $pdfMeta = ['file_name' => basename($pdfPath), 'mime_type' => 'application/pdf', 'bytes' => filesize($pdfPath)];
            if ($this->isDriveReady() && $folderId) {
                $up = $this->drive->uploadBytes($folderId, basename($pdfPath), 'application/pdf', file_get_contents($pdfPath));
                $pdfUrl = $up['url']; $pdfDriveId = $up['id'];
                $pdfMeta['drive_file_id'] = $up['id']; $pdfMeta['url'] = $up['url'];
                $writer->stampCell($tab, $rowNum, $headers, 'pdf id', $up['id']);
                $writer->stampCell($tab, $rowNum, $headers, 'mail status', 'PDF GENERATED');
            } else {
                $pdfUrl = rtrim((string)($meta['base_url'] ?? ''), '/') . '/storage/reports/' . basename($pdfPath);
                $pdfMeta['url'] = $pdfUrl;
                $writer->stampCell($tab, $rowNum, $headers, 'mail status', 'PDF GENERATED (local)');
            }
            $tracker->addAttachment('pdf', $pdfMeta);
            $tracker->stepDone($log, 'PDF built', $pdfUrl);
        } catch (Throwable $e) {
            $tracker->stepFailed($log, $e->getMessage());
            try { $writer->stampCell($tab, $rowNum, $headers, 'mail status', 'PDF FAILED: ' . $e->getMessage()); } catch (Throwable $x) {}
            $warnings[] = 'PDF failed: ' . $e->getMessage();
        }

        // advance job -> awaiting notifications
        $job['core'] = [
            'tab' => $tab, 'row' => $rowNum, 'project' => $projectName,
            'client_type' => $p['clientType'] ?? '', 'developer' => $p['developer'] ?? '',
            'pdf_path' => $pdfPath, 'pdf_drive_id' => $pdfDriveId, 'pdf_url' => $pdfUrl,
        ];
        $job['state'] = 'core_done';
        $job['notify_after'] = time() + (int)($this->cfg['notify_delay_seconds'] ?? 180);
        $tracker->updateSubmission([
            'pdf_url'        => $pdfUrl,
            'overall_status' => 'awaiting_notify',
        ]);
        return ['warnings' => array_values(array_filter($warnings)), 'fatal' => null];
    }

    /* ================= NOTIFICATIONS (worker phase 2) ================= */

    /** Email + WhatsApp for a core-done job. */
    public function runNotifications(array $job): array
    {
        $core = $job['core'] ?? [];
        $tracker = new Tracker($this->app->db());
        $tracker->bind((int)$job['submission_id']);

        $tab = $core['tab'] ?? '';
        $row = (int)($core['row'] ?? 0);
        $headers = [];
        if ($tab !== '') {
            try { $headers = ($this->sheets->getTab($this->cfg['response_sheet_id'], $tab)[0]) ?? []; }
            catch (Throwable $e) {}
        }

        $writer = new ResponseSheet($this->sheets, $this->cfg);
        $notifier = new NotificationService($this->sheets, $this->drive, $this->cfg, $writer);
        $warnings = $notifier->process($tracker, [
            'clientType'  => $core['client_type'] ?? '',
            'developer'   => $core['developer'] ?? '',
            'projectName' => $core['project'] ?? '',
            'tab'         => $tab,
            'row'         => $row,
            'headers'     => $headers,
            'pdfPath'     => $core['pdf_path'] ?? '',
            'pdfName'     => !empty($core['pdf_path']) ? basename($core['pdf_path']) : 'Site Report.pdf',
            'pdfDriveId'  => $core['pdf_drive_id'] ?? '',
        ]);

        $tracker->updateSubmission(['overall_status' => $warnings ? 'partial' : 'done']);
        return array_values(array_filter($warnings));
    }

    /* ================= SYNC ALL-IN-ONE (CLI/tests) ================= */

    public function handle(array $p, array $meta): array
    {
        $r = $this->enqueue($p, $meta);
        $job = $this->queue->load($r['public_id']);
        if (!$job) {
            return ['success' => false, 'error' => 'enqueue failed', 'publicId' => $r['public_id']];
        }
        $core = $this->runCore($job);
        $this->queue->save($job);
        if ($core['fatal']) {
            $this->queue->delete($r['public_id']);
            return ['success' => false, 'error' => $core['fatal'], 'publicId' => $r['public_id']];
        }
        $notifyWarn = $this->runNotifications($job);   // sync: no delay
        $this->queue->delete($r['public_id']);

        $warnings = array_merge($core['warnings'], $notifyWarn);
        return [
            'success'    => true,
            'row'        => $job['core']['row'] ?? 0,
            'pdfUrl'     => $job['core']['pdf_url'] ?? '',
            'pmsWarning' => implode(' ', $warnings),
            'publicId'   => $r['public_id'],
        ];
    }

    /* ================= helpers ================= */

    private function buildPdf(string $projectName, string $publicId, array $headers, array $rowValues, array $p): string
    {
        $photos = [];
        foreach (($p['photos'] ?? []) as $f) {
            $bytes = $this->decode($f);
            if ($bytes !== null) { $photos[] = ['bytes' => $bytes, 'mime' => $f['mimeType'] ?? 'image/jpeg']; }
        }
        $drawing = null;
        if (($p['drawingChange'] ?? '') === 'Yes' && !empty($p['drawingPhoto'])) {
            $b = $this->decode($p['drawingPhoto']);
            if ($b !== null) { $drawing = ['bytes' => $b, 'mime' => $p['drawingPhoto']['mimeType'] ?? 'image/jpeg']; }
        }

        $dir = __DIR__ . '/../storage/reports';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $projectName) ?: 'report';
        $out = $dir . '/' . date('d_M_Y') . '_' . $safe . '_' . substr($publicId, 0, 8) . '.pdf';

        $tsIdx = Sheets::findColIndex($headers, 'timestamp');
        $ts = $tsIdx > -1 ? (string)($rowValues[$tsIdx] ?? '') : date('d-M-Y H:i:s');

        (new Pdf(__DIR__ . '/../assets'))->build([
            'project_name' => $projectName,
            'timestamp'    => $ts,
            'headers'      => $headers,
            'rowValues'    => $rowValues,
            'photos'       => $photos,
            'drawing'      => $drawing,
            'client_hold'  => $this->clientHoldText($p),
            'out_path'     => $out,
        ]);
        return $out;
    }

    /**
     * Reason text for steps HELD UP BY THE CLIENT (payload, not the response
     * sheet, which has no Hold Reason columns). VAPL/other holds return ''.
     */
    private function clientHoldText(array $p): string
    {
        $parts = [];
        foreach (($p['stepStatuses'] ?? []) as $e) {
            if (!is_array($e) || trim((string)($e['status'] ?? '')) !== 'Hold') { continue; }
            if (stripos((string)($e['holdReason'] ?? ''), 'client') === false) { continue; }
            $detail = trim((string)($e['holdReasonDetail'] ?? ''));
            $parts[] = $detail !== '' ? $detail : trim((string)$e['holdReason']);
        }
        if (!$parts && stripos((string)($p['holdReason'] ?? ''), 'client') !== false) {
            $d = trim((string)($p['holdReasonDetail'] ?? ''));
            $parts[] = $d !== '' ? $d : trim((string)$p['holdReason']);
        }
        return implode("\n", array_values(array_unique(array_filter($parts))));
    }

    private function decode(?array $f): ?string
    {
        if (!$f || empty($f['base64'])) { return null; }
        $b = base64_decode($f['base64'], true);
        return $b === false ? null : $b;
    }

    /** Checks (once) that the parent folder is reachable by the SA on a Shared Drive. */
    private function isDriveReady(): bool
    {
        if ($this->driveReady !== null) {
            return $this->driveReady;
        }
        $parent = $this->cfg['parent_folder_id'] ?? '';
        if ($parent === '') {
            return $this->driveReady = false;
        }
        try {
            $url = Drive::FILES . '/' . rawurlencode($parent) . '?'
                . http_build_query(['fields' => 'id,driveId', 'supportsAllDrives' => 'true']);
            $meta = $this->app->client->get($url);
            return $this->driveReady = !empty($meta['driveId']);
        } catch (Throwable $e) {
            return $this->driveReady = false;
        }
    }
}
