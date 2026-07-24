-- One-shot backfill for rows written before src/Db.php pinned the session tz.
--
-- On the prod box MySQL's global time_zone is SYSTEM (= UTC), so every column
-- with DEFAULT CURRENT_TIMESTAMP written through src/Db.php landed 5:30 behind
-- the PHP-generated timestamps in the same row. process_log.started_at IS
-- PHP-generated (Asia/Kolkata), so it is the trusted clock we correct against.
--
-- Run once, AFTER deploying the src/Db.php fix. Safe to re-run: the WHERE guard
-- only touches rows still more than an hour out of step.

-- 1) Preview what will change.
SELECT s.id, s.created_at AS stored_utc, p.t AS real_ist,
       TIMESTAMPDIFF(MINUTE, s.created_at, p.t) AS drift_min
FROM submissions s
JOIN (SELECT submission_id, MIN(started_at) t FROM process_log GROUP BY submission_id) p
  ON p.submission_id = s.id
WHERE TIMESTAMPDIFF(MINUTE, s.created_at, p.t) > 60
ORDER BY s.id;

-- 2) Apply.
UPDATE submissions s
JOIN (SELECT submission_id, MIN(started_at) t FROM process_log GROUP BY submission_id) p
  ON p.submission_id = s.id
SET s.created_at = p.t
WHERE TIMESTAMPDIFF(MINUTE, s.created_at, p.t) > 60;

-- 3) Same drift on the child tables (no PHP clock to compare against there,
--    so shift by the fixed IST offset). Only rows older than the deploy.
--    >>> replace the cutoff with the actual deploy timestamp before running <<<
UPDATE process_log SET created_at = created_at + INTERVAL 330 MINUTE
WHERE created_at < '2026-07-23 00:00:00';

UPDATE attachments SET created_at = created_at + INTERVAL 330 MINUTE
WHERE created_at < '2026-07-23 00:00:00';
