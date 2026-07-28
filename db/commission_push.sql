-- Commissioning push bookkeeping (scripts/commission_push.php) ---------------
-- app_pushed_at : stamped only when the HVAC/VAPL backend acks the project.
--                 NULL = still a push candidate, so a failed push retries every
--                 run and a successful one never re-sends.
-- Fresh installs already get this column from db/admin_ext_schema.sql, and
-- CommissionPush::ensureColumn() adds it at runtime if missing — this file is
-- for existing databases created before the push feature landed.
ALTER TABLE `projects` ADD COLUMN `app_pushed_at` DATETIME DEFAULT NULL AFTER `commissioned_at`;
