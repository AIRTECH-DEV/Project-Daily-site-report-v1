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

    /** Removes base64 blobs so payload_json stays small. */
    private function stripFileBytes(array $p): array
    {
        $clean = $p;
        foreach (['photos', 'drawingPhoto', 'measurementFile'] as $k) {
            if (!isset($clean[$k])) {
                continue;
            }
            if ($k === 'photos' && is_array($clean[$k])) {
                $clean[$k] = array_map(fn($f) => is_array($f)
                    ? ['name' => $f['name'] ?? '', 'mimeType' => $f['mimeType'] ?? '']
                    : $f, $clean[$k]);
            } elseif (is_array($clean[$k])) {
                $clean[$k] = ['name' => $clean[$k]['name'] ?? '', 'mimeType' => $clean[$k]['mimeType'] ?? ''];
            }
        }
        return $clean;
    }
}
