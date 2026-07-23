-- Performance & Incentive tab — extra master data.
--
-- ADDITIVE ONLY. Populated by admin/inc/Perf.php (and scripts/perf_sync.php),
-- which only READS the PMS Google Sheets + submissions. It never touches the
-- live submit → sheet → PMS → PDF → email/WhatsApp pipeline.
--
-- The projects.* columns below are added idempotently at runtime by
-- Perf::ensureSchema(), so importing this file is OPTIONAL — it exists so the
-- schema is documented and can be applied by hand.
--   MySQL 8 has no "ADD COLUMN IF NOT EXISTS"; re-running the ALTER will error
--   with "Duplicate column name" once the runtime check has already added it.
--   That error is safe to ignore.

USE `pms`;

-- Per-project, per-step dates lifted from the PMS progress sheets ------------
-- One row per (project, step). start/end come from the sheet's grouped
-- "Start Date" / "End Date" sub-columns; single-column date steps (e.g.
-- "LS Material Delivery") store the same date in both.
CREATE TABLE IF NOT EXISTS `project_step_dates` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_key` VARCHAR(255) NOT NULL,
  `step`        VARCHAR(190) NOT NULL,
  `step_key`    VARCHAR(190) NOT NULL,          -- compact match key
  `start_date`  DATE DEFAULT NULL,
  `end_date`    DATE DEFAULT NULL,
  `status`      VARCHAR(40)  DEFAULT NULL,      -- Done | Pending | Hold text
  `synced_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_proj_step` (`project_key`, `step_key`),
  KEY `idx_pkey` (`project_key`),
  KEY `idx_step` (`step_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Project start / actual finish, read from the PMS sheets --------------------
-- start_date        : "Marking" → Start Date (the manager's hand-over signal)
-- start_source      : marking_start | marking_end | ls_delivery | earliest_step
-- actual_end_date   : final "Commissining" → End Date (Pre- excluded)
-- sheet_target_end  : "Tentitive Project End date" straight off the sheet
-- sheet_synced_at   : when the sheet scan last refreshed the three above
ALTER TABLE `projects` ADD COLUMN `start_date`       DATE        DEFAULT NULL AFTER `first_report_at`;
ALTER TABLE `projects` ADD COLUMN `start_source`     VARCHAR(20) DEFAULT NULL AFTER `start_date`;
ALTER TABLE `projects` ADD COLUMN `actual_end_date`  DATE        DEFAULT NULL AFTER `start_source`;
ALTER TABLE `projects` ADD COLUMN `sheet_target_end` DATE        DEFAULT NULL AFTER `actual_end_date`;
ALTER TABLE `projects` ADD COLUMN `sheet_synced_at`  DATETIME    DEFAULT NULL AFTER `sheet_target_end`;
