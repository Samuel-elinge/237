-- ============================================================
-- 237Biz — Business Growth Partner: Database Migration
-- Run once via phpMyAdmin or MySQL CLI
-- ============================================================

-- 1. Partner profiles (extends users table)
CREATE TABLE IF NOT EXISTS `partner_profiles` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`         INT UNSIGNED NOT NULL,
  `status`          ENUM('applicant','pending','approved','active','suspended','inactive','terminated') NOT NULL DEFAULT 'applicant',
  `region`          VARCHAR(120)   DEFAULT NULL,
  `specialisms`     TEXT           DEFAULT NULL,
  `referral_code`   VARCHAR(40)    DEFAULT NULL UNIQUE,
  `bio`             TEXT           DEFAULT NULL,
  `phone`           VARCHAR(40)    DEFAULT NULL,
  `organisation`    VARCHAR(160)   DEFAULT NULL,
  `areas_covered`   TEXT           DEFAULT NULL,
  `experience`      TEXT           DEFAULT NULL,
  `services_offered` TEXT          DEFAULT NULL,
  `social_profiles` TEXT           DEFAULT NULL COMMENT 'JSON',
  `businesses_managed_count` SMALLINT DEFAULT 0,
  `why_join`        TEXT           DEFAULT NULL,
  `approved_by`     INT UNSIGNED   DEFAULT NULL,
  `approved_at`     DATETIME       DEFAULT NULL,
  `created_at`      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status`  (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Partner ↔ business assignments
CREATE TABLE IF NOT EXISTS `partner_business_assignments` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `partner_id`   INT UNSIGNED NOT NULL COMMENT 'partner_profiles.id',
  `listing_id`   INT UNSIGNED NOT NULL COMMENT 'listings.id',
  `role`         ENUM('primary','supporting') NOT NULL DEFAULT 'primary',
  `status`       ENUM('active','inactive','removed') NOT NULL DEFAULT 'active',
  `assigned_by`  INT UNSIGNED DEFAULT NULL COMMENT 'admin user_id',
  `assigned_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `removed_at`   DATETIME DEFAULT NULL,
  `notes`        TEXT DEFAULT NULL,
  UNIQUE KEY `uq_partner_listing` (`partner_id`,`listing_id`),
  KEY `idx_listing`  (`listing_id`),
  KEY `idx_partner`  (`partner_id`),
  KEY `idx_status`   (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Growth plans
CREATE TABLE IF NOT EXISTS `growth_plans` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `listing_id`  INT UNSIGNED NOT NULL,
  `partner_id`  INT UNSIGNED NOT NULL COMMENT 'partner_profiles.id',
  `title`       VARCHAR(200) NOT NULL,
  `start_date`  DATE DEFAULT NULL,
  `end_date`    DATE DEFAULT NULL,
  `review_date` DATE DEFAULT NULL,
  `objectives`  TEXT DEFAULT NULL,
  `notes`       TEXT DEFAULT NULL,
  `status`      ENUM('draft','active','paused','completed','expired') NOT NULL DEFAULT 'draft',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_listing` (`listing_id`),
  KEY `idx_partner` (`partner_id`),
  KEY `idx_status`  (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Growth plan objectives (measurable targets)
CREATE TABLE IF NOT EXISTS `growth_plan_objectives` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `plan_id`      INT UNSIGNED NOT NULL,
  `metric`       VARCHAR(100) NOT NULL COMMENT 'e.g. reviews, profile_views, enquiries, campaigns, social_posts',
  `target_value` INT NOT NULL DEFAULT 0,
  `current_value` INT NOT NULL DEFAULT 0,
  `sort_order`   TINYINT NOT NULL DEFAULT 0,
  KEY `idx_plan` (`plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Growth tasks
CREATE TABLE IF NOT EXISTS `growth_tasks` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `listing_id`      INT UNSIGNED NOT NULL,
  `partner_id`      INT UNSIGNED NOT NULL COMMENT 'partner_profiles.id',
  `plan_id`         INT UNSIGNED DEFAULT NULL,
  `assigned_to`     INT UNSIGNED DEFAULT NULL COMMENT 'users.id',
  `title`           VARCHAR(250) NOT NULL,
  `description`     TEXT DEFAULT NULL,
  `category`        ENUM('profile','reviews','marketing','content','social_media','leads','customer_followup','campaign','website','business_email','other') NOT NULL DEFAULT 'other',
  `priority`        ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  `status`          ENUM('todo','in_progress','completed','cancelled') NOT NULL DEFAULT 'todo',
  `due_date`        DATE DEFAULT NULL,
  `completed_at`    DATETIME DEFAULT NULL,
  `notes`           TEXT DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_listing`   (`listing_id`),
  KEY `idx_partner`   (`partner_id`),
  KEY `idx_plan`      (`plan_id`),
  KEY `idx_status`    (`status`),
  KEY `idx_due_date`  (`due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Leads (partner-facing CRM view)
CREATE TABLE IF NOT EXISTS `partner_leads` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `listing_id`    INT UNSIGNED NOT NULL,
  `partner_id`    INT UNSIGNED NOT NULL COMMENT 'partner_profiles.id',
  `customer_name` VARCHAR(160) DEFAULT NULL,
  `customer_email` VARCHAR(200) DEFAULT NULL,
  `customer_phone` VARCHAR(40)  DEFAULT NULL,
  `source`        ENUM('enquiry','booking','campaign','referral','walk_in','phone','other') NOT NULL DEFAULT 'enquiry',
  `lead_type`     VARCHAR(80)  DEFAULT NULL,
  `status`        ENUM('new','contacted','follow_up','qualified','converted','lost','closed') NOT NULL DEFAULT 'new',
  `assigned_to`   INT UNSIGNED DEFAULT NULL COMMENT 'users.id',
  `notes`         TEXT DEFAULT NULL,
  `next_action`   VARCHAR(250) DEFAULT NULL,
  `last_activity` DATETIME DEFAULT NULL,
  `enquiry_id`    INT UNSIGNED DEFAULT NULL COMMENT 'links to existing enquiries table if applicable',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_listing`  (`listing_id`),
  KEY `idx_partner`  (`partner_id`),
  KEY `idx_status`   (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Commission rules (configurable)
CREATE TABLE IF NOT EXISTS `commission_rules` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`          VARCHAR(160) NOT NULL,
  `event_type`    ENUM('business_referral','paid_listing','featured_listing','advertising','campaign','website_sale','business_email','supportdesk_sale','other') NOT NULL,
  `commission_type` ENUM('fixed','percentage','one_off','recurring') NOT NULL DEFAULT 'fixed',
  `amount`        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `currency`      VARCHAR(10) NOT NULL DEFAULT 'XAF',
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  `notes`         TEXT DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Commission records
CREATE TABLE IF NOT EXISTS `partner_commissions` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `partner_id`      INT UNSIGNED NOT NULL COMMENT 'partner_profiles.id',
  `listing_id`      INT UNSIGNED DEFAULT NULL,
  `rule_id`         INT UNSIGNED DEFAULT NULL,
  `source`          VARCHAR(160) DEFAULT NULL COMMENT 'Human-readable event description',
  `transaction_ref` VARCHAR(200) DEFAULT NULL,
  `commission_type` ENUM('fixed','percentage','one_off','recurring') NOT NULL DEFAULT 'fixed',
  `amount`          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `currency`        VARCHAR(10) NOT NULL DEFAULT 'XAF',
  `status`          ENUM('pending','approved','paid','cancelled','disputed') NOT NULL DEFAULT 'pending',
  `approved_by`     INT UNSIGNED DEFAULT NULL,
  `approved_at`     DATETIME DEFAULT NULL,
  `paid_at`         DATETIME DEFAULT NULL,
  `notes`           TEXT DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_partner`  (`partner_id`),
  KEY `idx_listing`  (`listing_id`),
  KEY `idx_status`   (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Audit log
CREATE TABLE IF NOT EXISTS `partner_audit_log` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `partner_id`  INT UNSIGNED DEFAULT NULL COMMENT 'partner_profiles.id',
  `user_id`     INT UNSIGNED DEFAULT NULL COMMENT 'who performed the action',
  `listing_id`  INT UNSIGNED DEFAULT NULL,
  `action`      VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `ip_address`  VARCHAR(45) DEFAULT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_partner`  (`partner_id`),
  KEY `idx_listing`  (`listing_id`),
  KEY `idx_created`  (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Add growth_partner role support to users table
-- (only runs if column doesn't already support the value)
-- ============================================================
-- Note: if your 'role' column is an ENUM, run:
-- ALTER TABLE users MODIFY COLUMN role ENUM('user','sales_staff','creator','growth_partner','admin') NOT NULL DEFAULT 'user';
-- If it's a VARCHAR, no change needed.
