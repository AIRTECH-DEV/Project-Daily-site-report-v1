-- Admin panel — extended master tables (Project 360, workforce/contractor
-- tracking, project lifecycle, notification center).
--
-- ADDITIVE ONLY. These are populated by admin/sync (which READS submissions/
-- payload_json) and never touch the live submit → sheet → PMS → PDF → email/
-- WhatsApp pipeline or its tables (submissions/process_log/attachments).

USE `pms`;

-- Contractor companies (normalized from peopleRows[].contractorName) ---------
CREATE TABLE IF NOT EXISTS `contractors` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(190) NOT NULL,
  `name_key`   VARCHAR(190) NOT NULL,            -- normalized for matching
  `trade`      VARCHAR(120) DEFAULT NULL,        -- optional skill/trade note
  `phone`      VARCHAR(120) DEFAULT NULL,
  `visits`     INT UNSIGNED NOT NULL DEFAULT 0,  -- refreshed by sync
  `first_seen` DATE DEFAULT NULL,
  `last_seen`  DATE DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ckey` (`name_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Individual workers (VAPL staff or contractor labour) -----------------------
CREATE TABLE IF NOT EXISTS `workers` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(190) NOT NULL,
  `name_key`      VARCHAR(190) NOT NULL,
  `type`          VARCHAR(20)  NOT NULL DEFAULT 'VAPL',   -- VAPL | Contractor
  `contractor_id` INT UNSIGNED DEFAULT NULL,
  `visits`        INT UNSIGNED NOT NULL DEFAULT 0,
  `first_seen`    DATE DEFAULT NULL,
  `last_seen`     DATE DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wkey` (`name_key`, `type`),
  KEY `idx_contractor` (`contractor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per (visit, worker) — normalized peopleRows ------------------------
CREATE TABLE IF NOT EXISTS `visit_workers` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `submission_id`   BIGINT UNSIGNED NOT NULL,
  `project_key`     VARCHAR(255) NOT NULL,
  `worker_id`       INT UNSIGNED DEFAULT NULL,
  `worker_name`     VARCHAR(190) NOT NULL,
  `type`            VARCHAR(20)  NOT NULL DEFAULT 'VAPL',
  `contractor_id`   INT UNSIGNED DEFAULT NULL,
  `contractor_name` VARCHAR(190) DEFAULT NULL,
  `steps`           TEXT DEFAULT NULL,           -- comma list of steps this visit
  `engineer`        VARCHAR(190) DEFAULT NULL,   -- PE on this visit
  `visit_date`      DATE DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sub` (`submission_id`),
  KEY `idx_pkey` (`project_key`),
  KEY `idx_worker` (`worker_id`),
  KEY `idx_contractor` (`contractor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Project master — one row per project/unit, rollup + lifecycle -------------
CREATE TABLE IF NOT EXISTS `projects` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_key`      VARCHAR(255) NOT NULL,
  `label`            VARCHAR(255) NOT NULL,
  `site_type`        VARCHAR(20)  DEFAULT NULL,
  `client_type`      VARCHAR(20)  DEFAULT NULL,
  `developer`        VARCHAR(190) DEFAULT NULL,
  `building`         VARCHAR(190) DEFAULT NULL,
  `flat_no`          VARCHAR(60)  DEFAULT NULL,
  `project_name`     VARCHAR(255) DEFAULT NULL,
  `order_id`         VARCHAR(120) DEFAULT NULL,
  `primary_pe`       VARCHAR(190) DEFAULT NULL,
  `report_count`     INT UNSIGNED NOT NULL DEFAULT 0,
  `steps_total`      INT UNSIGNED NOT NULL DEFAULT 0,
  `steps_done`       INT UNSIGNED NOT NULL DEFAULT 0,
  `current_step`     VARCHAR(190) DEFAULT NULL,
  `hold_owner`       VARCHAR(60)  DEFAULT NULL,   -- Client | VAPL | ''
  `hold_since`       DATE DEFAULT NULL,
  `first_report_at`  DATETIME DEFAULT NULL,
  `last_report_at`   DATETIME DEFAULT NULL,
  `next_plan_date`   DATE DEFAULT NULL,
  `next_plan_steps`  TEXT DEFAULT NULL,
  `target_end`       DATE DEFAULT NULL,
  `lifecycle`        VARCHAR(30)  NOT NULL DEFAULT 'Not Started',
  `lifecycle_locked` TINYINT(1)   NOT NULL DEFAULT 0,   -- 1 = manual (Commissioned/Closed), sync won't override
  `commissioned_at`  DATETIME DEFAULT NULL,
  `closed_at`        DATETIME DEFAULT NULL,
  `closed_by`        VARCHAR(100) DEFAULT NULL,
  `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pkey` (`project_key`),
  KEY `idx_lifecycle` (`lifecycle`),
  KEY `idx_pe` (`primary_pe`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Alerts / notification inbox -----------------------------------------------
CREATE TABLE IF NOT EXISTS `alerts` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rule`          VARCHAR(50)  NOT NULL,
  `dedupe_key`    VARCHAR(190) NOT NULL,
  `severity`      VARCHAR(20)  NOT NULL DEFAULT 'info',   -- critical | warning | info
  `project_key`   VARCHAR(255) DEFAULT NULL,
  `project_label` VARCHAR(255) DEFAULT NULL,
  `submission_id` BIGINT UNSIGNED DEFAULT NULL,
  `owner`         VARCHAR(190) DEFAULT NULL,              -- PE / manager name
  `title`         VARCHAR(255) NOT NULL,
  `detail`        TEXT DEFAULT NULL,
  `status`        VARCHAR(20)  NOT NULL DEFAULT 'open',   -- open | ack | snoozed | resolved
  `due_at`        DATETIME DEFAULT NULL,
  `snooze_until`  DATETIME DEFAULT NULL,
  `notified_at`   DATETIME DEFAULT NULL,                  -- internal email/WA sent
  `acked_at`      DATETIME DEFAULT NULL,
  `acked_by`      VARCHAR(100) DEFAULT NULL,
  `resolved_at`   DATETIME DEFAULT NULL,
  `resolved_by`   VARCHAR(100) DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dedupe` (`dedupe_key`),
  KEY `idx_status` (`status`),
  KEY `idx_sev` (`severity`),
  KEY `idx_pkey` (`project_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Alert lifecycle history (created/notified/ack/snooze/resolve/escalate) -----
CREATE TABLE IF NOT EXISTS `alert_events` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `alert_id`   BIGINT UNSIGNED NOT NULL,
  `event`      VARCHAR(40) NOT NULL,
  `actor`      VARCHAR(100) DEFAULT NULL,
  `note`       VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_alert` (`alert_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
