<?php
/**
 * Disk-backed job queue. A submit writes one JSON job file (the full payload +
 * meta + state) and returns immediately; the background Worker reads, advances
 * and finally deletes it. State lives in the file so the worker is the single
 * source of truth for a job's progress.
 *
 * Job shape:
 *   { public_id, submission_id, meta, payload,
 *     state: 'queued'|'core_done',
 *     created_at: epoch, notify_after: epoch|null,
 *     core: { tab, row, project, client_type, developer, pdf_path, pdf_drive_id } }
 */
class JobQueue
{
    /** @var string */ private $dir;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/\\');
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
    }

    public function path(string $publicId): string
    {
        return $this->dir . '/' . $publicId . '.json';
    }

    public function save(array $job): void
    {
        $tmp = $this->path($job['public_id']) . '.tmp';
        file_put_contents($tmp, json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        rename($tmp, $this->path($job['public_id']));   // atomic swap
    }

    public function load(string $publicId): ?array
    {
        $p = $this->path($publicId);
        if (!is_file($p)) {
            return null;
        }
        $j = json_decode((string)file_get_contents($p), true);
        return is_array($j) ? $j : null;
    }

    public function delete(string $publicId): void
    {
        @unlink($this->path($publicId));
    }

    /** All job files, oldest first (by created_at). */
    public function all(): array
    {
        $jobs = [];
        foreach (glob($this->dir . '/*.json') ?: [] as $f) {
            $j = json_decode((string)file_get_contents($f), true);
            if (is_array($j) && !empty($j['public_id'])) {
                $jobs[] = $j;
            }
        }
        usort($jobs, fn($a, $b) => ($a['created_at'] ?? 0) <=> ($b['created_at'] ?? 0));
        return $jobs;
    }
}
