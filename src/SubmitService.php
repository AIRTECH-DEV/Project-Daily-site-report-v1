<?php
/**
 * Orchestrates one submission end-to-end, mirroring code.js submitSiteReport()
 * but recording every step in the tracker DB and degrading gracefully when the
 * Shared Drive isn't configured yet (photo/PDF upload logged as skipped, the
 * response row + PMS + PDF + DB tracking still complete).
 *
 * Order: start DB row -> upload photos (Drive) -> write response row ->
 *        stamp PMS -> build PDF (from payload bytes) -> upload PDF (Drive) ->
 *        stamp PDF ID/Mail Status -> finalise.
 */
class SubmitService
{
    /** @var Bootstrap */ private $app;
    /** @var Sheets */    private $sheets;
    /** @var Drive */     private $drive;
    /** @var array */     private $cfg;
    /** @var ?bool */     private $driveReady = null;

    public function __construct(Bootstrap $app)
    {
        $this->app = $app;
        $this->sheets = $app->sheets;
        $this->drive = $app->drive;
        $this->cfg = $app->cfg;
    }

    public function handle(array $p, array $meta): array
    {
        $tracker = new Tracker($this->app->db());
        [$subId, $publicId] = $tracker->startSubmission($p, $meta['email'] ?? null, $meta['ip'] ?? null);

        $isDeveloper = ($p['clientType'] ?? '') === 'Developer';
        $projectName = trim((string)($isDeveloper
            ? ($p['developer'] ?: 'General_Reports')
            : ($p['project'] ?: 'General_Reports')));

        $result = ['success' => false, 'publicId' => $publicId];
        $warnings = [];

        // ---- 1. Photos -> Drive (skipped if Shared Drive not ready) --------
        $urls = ['site' => [], 'drawing' => null, 'measurement' => null];
        $folderId = null;
        $log = $tracker->stepStart('photo_save', $projectName);
        try {
            if ($this->isDriveReady()) {
                $folderId = $this->drive->getOrCreateProjectFolder($projectName);
                foreach (($p['photos'] ?? []) as $i => $f) {
                    $saved = $this->drive->saveBase64File($f, $folderId, 'SitePhoto_' . ($i + 1));
                    if ($saved) {
                        $urls['site'][] = $saved['url'];
                        $tracker->addAttachment('site_photo', $saved);
                    }
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
                $tracker->stepSkipped($log, 'Shared Drive not configured — photos not uploaded. Set config parent_folder_id to a Shared Drive folder shared with the SA.');
                $warnings[] = 'Photos not uploaded (Shared Drive not configured).';
            }
        } catch (Throwable $e) {
            $tracker->stepFailed($log, $e->getMessage());
            $warnings[] = 'Photo upload failed: ' . $e->getMessage();
        }

        // ---- 2. Response row ----------------------------------------------
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
            return ['success' => false, 'error' => 'Failed writing response row: ' . $e->getMessage(), 'publicId' => $publicId];
        }

        // ---- 3. PMS progress sheet ----------------------------------------
        $log = $tracker->stepStart('pms_update');
        try {
            $pms = new Pms($this->sheets, $this->cfg);
            $res = $pms->updateProgressSheets($p);
            if (!empty($res['order_id'])) {
                $tracker->updateSubmission(['order_id' => $res['order_id']]);
            }
            if ($res['updated']) {
                $tracker->stepDone($log, 'PMS row stamped');
            } else {
                $tracker->stepSkipped($log, $res['warning'] ?: 'not updated');
                $warnings[] = $res['warning'];
            }
        } catch (Throwable $e) {
            $tracker->stepFailed($log, $e->getMessage());
            $warnings[] = 'PMS update failed: ' . $e->getMessage();
        }

        // ---- 4. PDF (from payload bytes) ----------------------------------
        $pdfUrl = '';
        $pdfPath = '';
        $pdfDriveId = '';
        $log = $tracker->stepStart('pdf');
        try {
            $pdfPath = $this->buildPdf($projectName, $publicId, $headers, $rowValues, $p);
            $pdfMeta = ['file_name' => basename($pdfPath), 'mime_type' => 'application/pdf', 'bytes' => filesize($pdfPath)];

            // Upload to Drive if available, else serve locally.
            if ($this->isDriveReady() && $folderId) {
                $up = $this->drive->uploadBytes($folderId, basename($pdfPath), 'application/pdf', file_get_contents($pdfPath));
                $pdfUrl = $up['url'];
                $pdfDriveId = $up['id'];
                $pdfMeta['drive_file_id'] = $up['id'];
                $pdfMeta['url'] = $up['url'];
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
            $writer->stampCell($tab, $rowNum, $headers, 'mail status', 'PDF FAILED: ' . $e->getMessage());
            $tracker->updateSubmission(['overall_status' => 'partial']);
            return ['success' => false, 'error' => 'Data saved, but PDF failed: ' . $e->getMessage(),
                    'row' => $rowNum, 'publicId' => $publicId, 'pmsWarning' => implode(' ', array_filter($warnings))];
        }

        // ---- 4b. Notifications (email + WhatsApp) -------------------------
        // Sent right after the PDF is ready. MODE-aware (OFF/TEST/LIVE) and never
        // fails the submission — each result is recorded in process_log.
        try {
            $notifier = new NotificationService($this->sheets, $this->drive, $this->cfg, $writer);
            $warnings = array_merge($warnings, $notifier->process($tracker, [
                'clientType'  => $p['clientType'] ?? '',
                'developer'   => $p['developer'] ?? '',
                'projectName' => $projectName,
                'tab'         => $tab,
                'row'         => $rowNum,
                'headers'     => $headers,
                'pdfPath'     => $pdfPath,
                'pdfName'     => $pdfPath !== '' ? basename($pdfPath) : 'SiteReport.pdf',
                'pdfDriveId'  => $pdfDriveId,
            ]));
        } catch (Throwable $e) {
            $warnings[] = 'Notifications error: ' . $e->getMessage();
        }

        // ---- 5. Finalise ---------------------------------------------------
        $overall = array_filter($warnings) ? 'partial' : 'done';
        $tracker->updateSubmission(['overall_status' => $overall, 'pdf_url' => $pdfUrl]);

        return [
            'success'    => true,
            'row'        => $rowNum,
            'pdfUrl'     => $pdfUrl,
            'pmsWarning' => implode(' ', array_filter($warnings)),
            'publicId'   => $publicId,
        ];
    }

    /* ---------------- helpers ---------------- */

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
        // Fallback to the representative hold fields if stepStatuses was absent.
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
            // Require it to be on a Shared Drive (driveId present) to avoid the 0-quota trap.
            return $this->driveReady = !empty($meta['driveId']);
        } catch (Throwable $e) {
            return $this->driveReady = false;
        }
    }
}
