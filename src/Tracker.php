<?php
/**
 * Records a submission and its per-step progress in the tracker DB. Each step
 * (sheet_write, photo_save, pms_update, pdf, email, whatsapp) gets one row in
 * process_log flipped running -> done/failed/skipped, so the whole pipeline is
 * auditable and a failure never disappears silently.
 */
class Tracker
{
    /** @var Db */
    private $db;
    /** @var int current submission id */
    private $submissionId = 0;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    public function submissionId(): int
    {
        return $this->submissionId;
    }

    /** Attaches this tracker to an already-created submission (worker phases). */
    public function bind(int $submissionId): void
    {
        $this->submissionId = $submissionId;
    }

    /** Creates the submission row from the incoming payload; returns [id, public_id]. */
    public function startSubmission(array $p, ?string $email, ?string $ip): array
    {
        $publicId = bin2hex(random_bytes(16));
        $this->submissionId = $this->db->insert('submissions', [
            'public_id'          => $publicId,
            'site_type'          => (string)($p['siteType'] ?? ''),
            'client_type'        => $p['clientType'] ?? null,
            'developer'          => $p['developer'] ?? null,
            'building'           => $p['building'] ?? null,
            'floor'              => $p['floor'] ?? null,
            'flat_no'            => $p['flatNo'] ?? null,
            'project'            => $p['project'] ?? null,
            'people'             => $p['people'] ?? null,
            'engineer'           => $p['engineer'] ?? null,
            'current_status'     => $p['currentStatus'] ?? null,
            'status'             => $p['status'] ?? null,
            'hold_reason'        => $p['holdReason'] ?? null,
            'hold_reason_detail' => $p['holdReasonDetail'] ?? null,
            'work_done_by'       => $p['workDoneBy'] ?? null,
            'contractor_name'    => $p['contractorName'] ?? null,
            'tentative_end'      => $p['tentativeEndDate'] ?? null,
            'activity'           => $p['activity'] ?? null,
            'next_plan'          => $p['nextPlan'] ?? null,
            'amendment'          => $p['amendment'] ?? null,
            'amendment_why'      => $p['amendmentWhy'] ?? null,
            'drawing_change'     => $p['drawingChange'] ?? null,
            'measurement'        => $p['measurement'] ?? null,
            'payload_json'       => json_encode($this->stripFileBytes($p), JSON_UNESCAPED_UNICODE),
            'submitter_email'    => $email,
            'submitter_ip'       => $ip,
            'overall_status'     => 'processing',
        ]);
        return [$this->submissionId, $publicId];
    }

    /** Marks a step running; returns the log row id to finish later. */
    public function stepStart(string $step, ?string $target = null): int
    {
        return $this->db->insert('process_log', [
            'submission_id' => $this->submissionId,
            'step'          => $step,
            'status'        => 'running',
            'target'        => $target,
            'attempts'      => 1,
            'started_at'    => date('Y-m-d H:i:s'),
        ]);
    }

    public function stepDone(int $logId, ?string $message = null, ?string $target = null): void
    {
        $this->finish($logId, 'done', $message, $target);
    }

    public function stepFailed(int $logId, string $message, ?string $target = null): void
    {
        $this->finish($logId, 'failed', $message, $target);
    }

    public function stepSkipped(int $logId, string $message): void
    {
        $this->finish($logId, 'skipped', $message);
    }

    private function finish(int $logId, string $status, ?string $message, ?string $target = null): void
    {
        $data = ['status' => $status, 'message' => $message, 'finished_at' => date('Y-m-d H:i:s')];
        if ($target !== null) {
            $data['target'] = $target;
        }
        $this->db->update('process_log', $logId, $data);
    }

    /** Patch fields on the submission row (response_row, pdf_url, overall_status...). */
    public function updateSubmission(array $data): void
    {
        if ($this->submissionId) {
            $this->db->update('submissions', $this->submissionId, $data);
        }
    }

    /**
     * Writes each flat's own Order ID back into payload flats[] (multi-flat visits).
     *
     * The submissions row has ONE order_id column but a multi-flat visit resolves an
     * Order ID per flat in the developer sheet — see Pms::updateDeveloperFlats. The
     * flat entry is where the admin panel reads a flat's identity from, so it is
     * where the per-flat id has to land; the column keeps the first, as before.
     *
     * @param array<string,string> $byFlat flat no => order id
     */
    public function stampFlatOrderIds(array $byFlat): void
    {
        if (!$this->submissionId || !$byFlat) {
            return;
        }
        $rows = $this->db->query("SELECT payload_json FROM submissions WHERE id = ?", [$this->submissionId]);
        $p = json_decode((string)($rows[0]['payload_json'] ?? ''), true);
        if (!is_array($p) || !is_array($p['flats'] ?? null)) {
            return;
        }
        // Match on the same normalisation the sheet lookup used, so "A-101"/"a 101" pair up.
        $norm = static fn($v) => strtolower(preg_replace('/[^a-z0-9]+/i', '', (string)$v));
        $keyed = [];
        foreach ($byFlat as $flatNo => $orderId) {
            $keyed[$norm($flatNo)] = (string)$orderId;
        }
        $changed = false;
        foreach ($p['flats'] as $i => $f) {
            if (!is_array($f)) {
                continue;
            }
            $oid = $keyed[$norm($f['flatNo'] ?? '')] ?? '';
            if ($oid !== '' && ($f['orderId'] ?? '') !== $oid) {
                $p['flats'][$i]['orderId'] = $oid;
                $changed = true;
            }
        }
        if ($changed) {
            $this->updateSubmission(['payload_json' => json_encode($p, JSON_UNESCAPED_UNICODE)]);
        }
    }

    public function addAttachment(string $kind, array $meta): void
    {
        $this->db->insert('attachments', [
            'submission_id' => $this->submissionId,
            'kind'          => $kind,
            'file_name'     => $meta['file_name'] ?? null,
            'mime_type'     => $meta['mime_type'] ?? null,
            'drive_file_id' => $meta['drive_file_id'] ?? null,
            'url'           => $meta['url'] ?? null,
            'bytes'         => $meta['bytes'] ?? null,
        ]);
    }

    /**
     * Removes base64 blobs so payload_json stays small. Strips the top-level report AND
     * every per-flat report in flats[] — a multi-flat visit carries photos for each flat,
     * and leaving them in blew past max_allowed_packet on INSERT.
     */
    private function stripFileBytes(array $p): array
    {
        $clean = $this->stripReportFiles($p);
        if (isset($clean['flats']) && is_array($clean['flats'])) {
            $clean['flats'] = array_map(
                fn($f) => is_array($f) ? $this->stripReportFiles($f) : $f,
                $clean['flats']
            );
        }
        return $clean;
    }

    /** Replaces base64 file blobs with just {name,mimeType} in one report-shaped array. */
    private function stripReportFiles(array $r): array
    {
        foreach (['photos', 'drawingPhoto', 'measurementFile'] as $k) {
            if (!isset($r[$k]) || !is_array($r[$k])) {
                continue;
            }
            // Each slot is a list of blobs; older payloads sent a single blob object.
            $r[$k] = (isset($r[$k]['base64']) || isset($r[$k]['name']))
                ? ['name' => $r[$k]['name'] ?? '', 'mimeType' => $r[$k]['mimeType'] ?? '']
                : array_map(fn($f) => is_array($f)
                    ? ['name' => $f['name'] ?? '', 'mimeType' => $f['mimeType'] ?? '']
                    : $f, $r[$k]);
        }
        return $r;
    }
}
