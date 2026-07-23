<?php
/** Fire-and-forget launch of the background worker (non-blocking). */
class Spawn
{
    /**
     * Starts scripts/worker.php detached so the HTTP request returns immediately.
     * Best-effort: if process spawning is disabled, the Task Scheduler safety net
     * (or the next submit's spawn) still drains the queue.
     */
    public static function worker(array $cfg): void
    {
        $php    = $cfg['php_binary'] ?? 'php';
        $script = realpath(__DIR__ . '/../scripts/worker.php');
        if (!$script) {
            return;
        }
        $log = rtrim($cfg['queue_dir'], '/\\') . '/spawn.log';

        if (stripos(PHP_OS, 'WIN') === 0) {
            // start /B detaches; wrap in cmd so it returns control at once.
            $cmd = 'start /B "" ' . escapeshellarg($php) . ' ' . escapeshellarg($script)
                 . ' >> ' . escapeshellarg($log) . ' 2>&1';
            $full = 'cmd /c ' . $cmd;
            if (function_exists('popen')) {
                $h = @popen($full, 'r');
                if ($h !== false) { pclose($h); return; }
            }
        } else {
            $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script)
                 . ' >> ' . escapeshellarg($log) . ' 2>&1 &';
            if (function_exists('exec')) { @exec($cmd); return; }
        }
        // Last resort: nothing spawned; queue will be drained by scheduler/next submit.
    }
}
