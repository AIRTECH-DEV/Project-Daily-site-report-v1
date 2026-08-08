-- Client share links — a signed-out, read-only window on ONE project (or one
-- building / one developer), open for 24 hours and then dead forever.
--
-- ADDITIVE ONLY. Nothing here touches the submit → sheet → PMS → PDF → email/
-- WhatsApp pipeline or its tables. Share events get their OWN table on purpose:
-- process_log.step is an ENUM, and adding a value there breaks submissions.
--
-- Run by hand on deploy (no auto-migrations):  mysql -u root -p pms < db/share_schema.sql

USE `pms`;

-- One row per issued link ----------------------------------------------------
CREATE TABLE IF NOT EXISTS `share_links` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- sha256(token). The token itself is NEVER stored: a DB dump must not yield
  -- working links. Lookup hashes the presented token and compares.
  `token_hash`    CHAR(64)        NOT NULL,
  `scope`         ENUM('project','building','developer') NOT NULL DEFAULT 'project',
  -- project  -> projects.project_key
  -- building -> B|<developer>|<building>   (lowercased, see buildingKey())
  -- developer-> V|<developer>              (lowercased)
  `scope_key`     VARCHAR(255)    NOT NULL,
  -- Snapshot of the project row id for scope='project'. project_key can change
  -- when an order id is resolved late (G|name -> O|order id), so the id is the
  -- durable handle and the key is only a fast path.
  `project_id`    INT UNSIGNED    DEFAULT NULL,
  `label`         VARCHAR(255)    NOT NULL,
  -- what the client may see: {"photos":1,"pe_names":0,"team":0,"hold_detail":"client_only"}
  `opts_json`     TEXT            DEFAULT NULL,
  `created_by`    VARCHAR(100)    NOT NULL,
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`    DATETIME        NOT NULL,
  `revoked_at`    DATETIME        DEFAULT NULL,
  `revoked_by`    VARCHAR(100)    DEFAULT NULL,
  `views`         INT UNSIGNED    NOT NULL DEFAULT 0,   -- human views (bots excluded)
  `first_view_at` DATETIME        DEFAULT NULL,
  `last_view_at`  DATETIME        DEFAULT NULL,
  `sent_summary`  VARCHAR(255)    DEFAULT NULL,         -- "2 WhatsApp · 1 email"
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token` (`token_hash`),
  KEY `idx_expires` (`expires_at`),
  KEY `idx_scope` (`scope`, `scope_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit trail for every link (created / sent / viewed / download / denied) ----
-- Recipients are stored MASKED (ya***@gmail.com, 91****3893): the trail must not
-- become a second copy of the client contact book.
CREATE TABLE IF NOT EXISTS `share_events` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `link_id`    BIGINT UNSIGNED NOT NULL,
  `event`      VARCHAR(20)     NOT NULL,   -- created|sent|view|download|denied|revoked
  `channel`    VARCHAR(20)     DEFAULT NULL,  -- email|whatsapp|copy
  `target`     VARCHAR(190)    DEFAULT NULL,  -- MASKED recipient
  `ip_hash`    CHAR(64)        DEFAULT NULL,  -- sha256(ip + token_hash), never the raw IP
  `ua`         VARCHAR(190)    DEFAULT NULL,
  `detail`     VARCHAR(255)    DEFAULT NULL,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_link` (`link_id`, `id`),
  KEY `idx_event` (`event`),
  CONSTRAINT `fk_ev_link` FOREIGN KEY (`link_id`) REFERENCES `share_links` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-account permission: who may hand a link to a client --------------------
-- Sharing is an EXTERNAL disclosure, so it is its own right, not a side effect
-- of the role. Viewer accounts can be granted it; admins have it implicitly.
-- (Re-runnable: the ADD COLUMN is skipped when the column already exists.)
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_users' AND COLUMN_NAME = 'can_share');
SET @s := IF(@c = 0,
  'ALTER TABLE `admin_users` ADD COLUMN `can_share` TINYINT(1) NOT NULL DEFAULT 0 AFTER `role`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
