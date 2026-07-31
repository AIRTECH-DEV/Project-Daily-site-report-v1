-- Commissioning push bookkeeping (scripts/commission_push.php) ---------------
-- app_pushed_at       : stamped only when the HVAC/VAPL backend acks the project.
--                       NULL = still a push candidate, so a failed push retries
--                       every run and a successful one never re-sends.
-- pre_commissioned_at : stamped by the admin sync when the "Pre-Commissining"
--                       step is done. THIS is the hand-off trigger now — a project
--                       goes to the app after PRE-commissioning, not after final
--                       commissioning (lifecycle Commissioned/Closed stays a
--                       fallback candidate so nothing is missed).
-- Fresh installs get both columns from db/admin_ext_schema.sql, and the code
-- self-adds them at runtime (Sync::ensureSchema / CommissionPush::ensureColumns)
-- — this file is for existing databases created before those features landed.
-- Each ALTER errors harmlessly ("Duplicate column name") if already present.
ALTER TABLE `projects` ADD COLUMN `app_pushed_at` DATETIME DEFAULT NULL AFTER `commissioned_at`;
ALTER TABLE `projects` ADD COLUMN `pre_commissioned_at` DATETIME DEFAULT NULL AFTER `commissioned_at`;

-- Backfill: anything already commissioned/closed is past pre-commissioning, so it
-- keeps the same push status it had before the trigger moved.
UPDATE `projects`
   SET `pre_commissioned_at` = COALESCE(`commissioned_at`, `last_report_at`)
 WHERE `pre_commissioned_at` IS NULL
   AND `lifecycle` IN ('Commissioned', 'Closed');
