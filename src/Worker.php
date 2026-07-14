<?php
/**
 * Background worker that drains the JobQueue:
 *   queued      -> runCore()          (photos/sheet/PMS/PDF) — done ASAP
 *   core_done   -> runNotifications() (email + WhatsApp) — only once notify_after passes
 *
 * A single flock guarantees one worker at a time. In loop mode (spawned by a
 * submit) it keeps passing until nothing is pending, so the delayed notifications
 * still go out without any scheduler. In --once mode (Task Scheduler safety net)
 * it makes a single pass and exits.
 */
class Worker
{
    /** @var Bootstrap */ private $app;
    /** @var array */     private $cfg;
    /** @var JobQueue */  private $queue;
    /** @var SubmitService */ private $svc;
    /** @var resource|null */ private $lockHandle = null;

    public function __construct(Bootstrap $app)
    {
        $this->app = $app;
        $this->cfg = $app->cfg;
        $this->queue = new JobQueue($this->cfg['queue_dir']);
        $this->svc = new SubmitService($app);
    }

    /** @param bool $once single pass (scheduler) vs loop until idle (spawned). */
    public function run(bool $once = false): void
    {
        if (!$this->lock()) {
            $this->log('another worker holds the lock — exiting');
            return;
        }
        $start = time();
        $maxRuntime = (int)($this->cfg['worker_max_runtime'] ?? 900);
        $poll = max(3, (int)($this->cfg['worker_poll_seconds'] ?? 15));

        try {
            do {
                $pendingFuture = $this->pass();               // returns true if work remains but not yet due
                $hasQueued = $this->hasState('queued');
                if ($once) {
                    break;
                }
                if (!$hasQueued && !$pendingFuture) {
                    break;                                    // fully drained
                }
                if (time() - $start > $maxRuntime) {
                    $this->log('max runtime reached — exiting (jobs remain for next run)');
                    break;
                }
                sleep($poll);
            } while (true);
        } finally {
            $this->unlock();
        }
    }

    /**
     * One pass over all jobs. Returns true if at least one job is core_done but
     * still waiting for its notify_after (i.e. work remains for a later pass).
     */
    private function pass(): bool
    {
        $future = false;
        foreach ($this->queue->all() as $job) {
            $pid = $job['public_id'];
            try {
                if (($job['state'] ?? '') === 'queued') {
                    $this->log("core: $pid ({$this->projectOf($job)})");
                    $res = $this->svc->runCore($job);
                    $this->queue->save($job);                 // persist core[] + state
                    if (!empty($res['fatal'])) {
                        $this->log("  fatal: {$res['fatal']} — dropping job");
                        $this->queue->delete($pid);
                        continue;
                    }
                    $this->log('  core done; notify after ' . date('H:i:s', $job['notify_after']));
                    // fall through: maybe it's already due (delay 0)
                    $job = $this->queue->load($pid) ?: $job;
                }

                if (($job['state'] ?? '') === 'core_done') {
                    if (time() >= (int)($job['notify_after'] ?? 0)) {
                        $this->log("notify: $pid");
                        $warn = $this->svc->runNotifications($job);
                        if ($warn) { $this->log('  notify warnings: ' . implode(' | ', $warn)); }
                        $this->queue->delete($pid);
                        $this->log("  done: $pid");
                    } else {
                        $future = true;                        // due later
                    }
                }
            } catch (Throwable $e) {
                $this->log("ERROR on $pid: " . $e->getMessage());
            }
        }
        return $future;
    }

    private function hasState(string $state): bool
    {
        foreach ($this->queue->all() as $job) {
            if (($job['state'] ?? '') === $state) { return true; }
        }
        return false;
    }

    private function projectOf(array $job): string
    {
        $p = $job['payload'] ?? [];
        return (string)(($p['clientType'] ?? '') === 'Developer' ? ($p['developer'] ?? '') : ($p['project'] ?? ''));
    }

    /* ---- single-instance lock ---- */

    private function lock(): bool
    {
        $file = rtrim($this->cfg['queue_dir'], '/\\') . '/.worker.lock';
        $this->lockHandle = fopen($file, 'c');
        if (!$this->lockHandle) { return false; }
        return flock($this->lockHandle, LOCK_EX | LOCK_NB);
    }

    private function unlock(): void
    {
        if ($this->lockHandle) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }

    private function log(string $msg): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
        @file_put_contents(rtrim($this->cfg['queue_dir'], '/\\') . '/worker.log', $line, FILE_APPEND);
        if (PHP_SAPI === 'cli') { echo $line; }
    }
}
