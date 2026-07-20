-- PMS process tracker + audit database.
-- Google Sheets stay the data store; this DB records every submission and each
-- downstream step so any failure (sheet/pms/drive/pdf/email/whatsapp) is visible.

CREATE DATABASE IF NOT EXISTS `pms`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `pms`;

-- One row per form submission ------------------------------------------------
CREATE TABLE IF NOT EXISTS `submissions` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id`       CHAR(32)        NOT NULL,               -- opaque id used in URLs/logs
  `site_type`       VARCHAR(20)     NOT NULL,               -- VRV | Non-VRV
  `client_type`     VARCHAR(20)     DEFAULT NULL,           -- General | Developer
  `developer`       VARCHAR(190)    DEFAULT NULL,
  `building`        VARCHAR(190)    DEFAULT NULL,
  `floor`           VARCHAR(60)     DEFAULT NULL,
  `flat_no`         VARCHAR(60)     DEFAULT NULL,
  `project`         VARCHAR(255)    DEFAULT NULL,
  `order_id`        VARCHAR(120)    DEFAULT NULL,           -- resolved from Orders sheet
  `people`          VARCHAR(60)     DEFAULT NULL,
  `engineer`        VARCHAR(190)    DEFAULT NULL,
  `current_status`  VARCHAR(190)    DEFAULT NULL,           -- the project step (e.g. Copper Piping)
  `status`          VARCHAR(30)     DEFAULT NULL,           -- Done | Pending | Hold
  `hold_reason`     VARCHAR(255)    DEFAULT NULL,
  `hold_reason_detail` TEXT         DEFAULT NULL,
  `work_done_by`    VARCHAR(190)    DEFAULT NULL,
  `contractor_name` VARCHAR(190)    DEFAULT NULL,
  `tentative_end`   VARCHAR(60)     DEFAULT NULL,
  `activity`        TEXT            DEFAULT NULL,
  `next_plan`       TEXT            DEFAULT NULL,
  `amendment`       VARCHAR(10)     DEFAULT NULL,
  `amendment_why`   TEXT            DEFAULT NULL,
  `drawing_change`  VARCHAR(10)     DEFAULT NULL,
  `measurement`     VARCHAR(10)     DEFAULT NULL,
  `payload_json`    LONGTEXT        DEFAULT NULL,           -- full raw payload (minus file bytes)
  `submitter_email` VARCHAR(190)    DEFAULT NULL,
  `submitter_ip`    VARCHAR(45)     DEFAULT NULL,
  -- outcome mirrors of the sheet write, for quick querying
  `response_tab`    VARCHAR(40)     DEFAULT NULL,
  `response_row`    INT UNSIGNED    DEFAULT NULL,
  `pdf_url`         VARCHAR(512)    DEFAULT NULL,
  `overall_status`  ENUM('received','processing','done','failed','partial')
                    NOT NULL DEFAULT 'received',
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_public_id` (`public_id`),
  KEY `idx_project` (`project`),
  KEY `idx_site_type` (`site_type`),
  KEY `idx_created` (`created_at`),
  KEY `idx_overall` (`overall_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per processing step, per submission --------------------------------
CREATE TABLE IF NOT EXISTS `process_log` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `submission_id` BIGINT UNSIGNED NOT NULL,
  `step`          ENUM('sheet_write','photo_save','pms_update','pdf','email','whatsapp')
                  NOT NULL,
  `status`        ENUM('pending','running','done','failed','skipped')
                  NOT NULL DEFAULT 'pending',
  `message`       TEXT            DEFAULT NULL,             -- error text or note
  `target`        VARCHAR(512)    DEFAULT NULL,             -- e.g. tab!row, pdf id, sheet id
  `attempts`      INT UNSIGNED    NOT NULL DEFAULT 0,
  `started_at`    DATETIME        DEFAULT NULL,
  `finished_at`   DATETIME        DEFAULT NULL,
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_submission_step` (`submission_id`, `step`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_log_submission` FOREIGN KEY (`submission_id`)
    REFERENCES `submissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Files attached to a submission (photos, drawing, measurement, generated PDF) -
CREATE TABLE IF NOT EXISTS `attachments` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `submission_id` BIGINT UNSIGNED NOT NULL,
  `kind`          ENUM('site_photo','drawing','measurement','pdf') NOT NULL,
  `file_name`     VARCHAR(255)    DEFAULT NULL,
  `mime_type`     VARCHAR(120)    DEFAULT NULL,
  `drive_file_id` VARCHAR(120)    DEFAULT NULL,
  `url`           VARCHAR(512)    DEFAULT NULL,
  `bytes`         BIGINT UNSIGNED DEFAULT NULL,
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_submission_kind` (`submission_id`, `kind`),
  CONSTRAINT `fk_att_submission` FOREIGN KEY (`submission_id`)
    REFERENCES `submissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
