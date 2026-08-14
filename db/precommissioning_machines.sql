-- Equipment schedule captured at the Pre-Commissioning step ------------------
-- One row per unit (outdoor + every indoor) of every machine on a project:
-- model no., serial no. and location — the three fields the commissioning
-- technician's Android app used to ask him to re-type off the report.
--
-- Written by SubmitService when a pre-commissioning report arrives (either the
-- in-app form or an uploaded .xls/.xlsx, parsed by src/Workbook.php), read by
-- CommissionPush, which sends it to the app backend as payload.machines[].
--
-- project_key matches projects.project_key (helpers.php projectKey()), so the
-- push can look the list up by the project it is already pushing.
-- Re-reporting Pre-Commissioning REPLACES the list for that project, so a
-- corrected report corrects the machines instead of stacking a second copy.
--
-- Deploy runs no auto-migrations: apply this by hand. PreCommissioningMachines
-- ::ensureTable() also creates it at runtime, so a missed migration degrades to
-- "no machines pushed", never a failed submission.

USE `pms`;

CREATE TABLE IF NOT EXISTS `precommissioning_machines` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_key`   VARCHAR(190) NOT NULL,
  `submission_id` BIGINT UNSIGNED DEFAULT NULL,   -- visit the list came from
  `machine_no`    INT UNSIGNED NOT NULL DEFAULT 1, -- one outdoor machine = one report
  `unit_no`       INT UNSIGNED NOT NULL DEFAULT 1, -- row in that machine's schedule
  `unit_role`     ENUM('odu','idu') NOT NULL DEFAULT 'idu',
  `model`         VARCHAR(120) DEFAULT NULL,       -- e.g. RXMQ10BRY16 / FXKQ40ARV16
  `serial`        VARCHAR(120) DEFAULT NULL,       -- "Sr. No." column
  `location`      VARCHAR(190) DEFAULT NULL,       -- "System Name" column: LIVING ROOM, M BEDROOM
  `system`        VARCHAR(60)  DEFAULT NULL,       -- e.g. 10 HP
  `invoice_no`    VARCHAR(120) DEFAULT NULL,
  `invoice_date`  DATE DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_unit` (`project_key`,`machine_no`,`unit_no`),
  KEY `idx_project` (`project_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Machines are pushed with the project. A project already acked by the backend
-- would never re-send, so clear the stamp for anything that now has a machine
-- list the app never received.
UPDATE `projects` p
   SET p.`app_pushed_at` = NULL
 WHERE p.`app_pushed_at` IS NOT NULL
   AND EXISTS (SELECT 1 FROM `precommissioning_machines` m WHERE m.`project_key` = p.`project_key`);
