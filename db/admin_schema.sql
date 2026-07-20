-- Admin panel tables for the PMS tracker.
-- Auth (admin_users), brute-force throttle (rate_limits), and an audit trail
-- (audit_logs). The submissions/process_log/attachments tables already hold the
-- daily-update data the dashboard reports on — these tables are auth/ops only.

USE `pms`;

-- Admin login accounts ------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_users` (
  `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(50)     NOT NULL,
  `password_hash` VARCHAR(255)    NOT NULL,
  `display_name`  VARCHAR(120)    DEFAULT NULL,
  `role`          VARCHAR(30)     NOT NULL DEFAULT 'admin',   -- admin | viewer
  `is_active`     TINYINT(1)      NOT NULL DEFAULT 1,
  `last_login_at` DATETIME        DEFAULT NULL,
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Login rate limiting -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rate_limits` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key_hash`      CHAR(64)        NOT NULL,
  `action`        VARCHAR(50)     NOT NULL,
  `requests`      INT UNSIGNED    NOT NULL DEFAULT 0,
  `window_start`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `blocked_until` DATETIME        DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_key_action` (`key_hash`, `action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit trail ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_user`  VARCHAR(100)    DEFAULT NULL,
  `action`      VARCHAR(100)    NOT NULL,
  `entity_type` VARCHAR(60)     DEFAULT NULL,
  `entity_id`   BIGINT UNSIGNED DEFAULT NULL,
  `old_value`   TEXT            DEFAULT NULL,
  `new_value`   TEXT            DEFAULT NULL,
  `ip_address`  VARCHAR(45)     DEFAULT NULL,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
