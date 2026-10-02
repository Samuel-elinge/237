-- ================================================================
-- 237Biz Growth Partner — Phase 4 Migration
-- Partner Network Foundation, Marketplace, Ecosystem & Scale
-- Run after growth-partner-p3b-migration.sql
-- ================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ── Phase 4A: Partner Network Foundation ─────────────────────────

-- Partner tiers (Solo → Agency → Strategic)
CREATE TABLE IF NOT EXISTS `partner_tiers` (
  `id`                  TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`                VARCHAR(60) NOT NULL,        -- Solo, Agency, Strategic
  `slug`                VARCHAR(40) NOT NULL UNIQUE,
  `description`         TEXT DEFAULT NULL,
  `max_businesses`      SMALLINT DEFAULT NULL,       -- NULL = unlimited
  `commission_rate`     DECIMAL(5,2) DEFAULT 25.00,  -- base %
  `recurring_rate`      DECIMAL(5,2) DEFAULT 7.00,
  `benefits`            TEXT DEFAULT NULL,           -- JSON array of benefit strings
  `sort_order`          TINYINT DEFAULT 0,
  `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `partner_tiers` (id, name, slug, description, max_businesses, commission_rate, recurring_rate, sort_order) VALUES
  (1, 'Solo Partner',      'solo',      'Individual growth partners managing up to 10 businesses.', 10,   25.00, 7.00, 1),
  (2, 'Agency Partner',    'agency',    'Teams or small agencies managing up to 50 businesses.',    50,   27.00, 8.00, 2),
  (3, 'Strategic Partner', 'strategic', 'Larger organisations with sub-partners across a region.', NULL, 30.00, 10.00, 3);

-- Add tier_id to partner_profiles if not present
ALTER TABLE `partner_profiles`
  ADD COLUMN IF NOT EXISTS `tier_id`          TINYINT UNSIGNED DEFAULT 1 AFTER `status`,
  ADD COLUMN IF NOT EXISTS `parent_partner_id` INT UNSIGNED DEFAULT NULL AFTER `tier_id`,
  ADD COLUMN IF NOT EXISTS `display_name`     VARCHAR(160) DEFAULT NULL AFTER `organisation`,
  ADD COLUMN IF NOT EXISTS `avatar_url`       VARCHAR(255) DEFAULT NULL AFTER `display_name`,
  ADD COLUMN IF NOT EXISTS `tagline`          VARCHAR(255) DEFAULT NULL AFTER `avatar_url`,
  ADD COLUMN IF NOT EXISTS `verified`         TINYINT(1) DEFAULT 0 AFTER `tagline`,
  ADD COLUMN IF NOT EXISTS `verified_at`      DATETIME DEFAULT NULL AFTER `verified`,
  ADD COLUMN IF NOT EXISTS `public_profile`   TINYINT(1) DEFAULT 1 AFTER `verified_at`,
  ADD COLUMN IF NOT EXISTS `capacity_status`  ENUM('accepting','limited','full','paused') DEFAULT 'accepting' AFTER `public_profile`,
  ADD COLUMN IF NOT EXISTS `max_businesses`   SMALLINT DEFAULT 10 AFTER `capacity_status`,
  ADD COLUMN IF NOT EXISTS `profile_views`    INT UNSIGNED DEFAULT 0 AFTER `max_businesses`,
  ADD COLUMN IF NOT EXISTS `rating`           DECIMAL(3,2) DEFAULT NULL AFTER `profile_views`,
  ADD COLUMN IF NOT EXISTS `rating_count`     SMALLINT UNSIGNED DEFAULT 0 AFTER `rating`,
  ADD INDEX IF NOT EXISTS `idx_tier`          (`tier_id`),
  ADD INDEX IF NOT EXISTS `idx_parent`        (`parent_partner_id`),
  ADD INDEX IF NOT EXISTS `idx_capacity`      (`capacity_status`),
  ADD INDEX IF NOT EXISTS `idx_verified`      (`verified`);

-- Certification definitions (admin-managed)
CREATE TABLE IF NOT EXISTS `partner_certifications` (
  `id`          SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(120) NOT NULL,
  `slug`        VARCHAR(80) NOT NULL UNIQUE,
  `description` TEXT DEFAULT NULL,
  `icon`        VARCHAR(10) DEFAULT '🏅',
  `badge_color` VARCHAR(20) DEFAULT '#7c3aed',
  `level`       ENUM('foundation','intermediate','advanced','elite') NOT NULL DEFAULT 'foundation',
  `criteria`    TEXT DEFAULT NULL,            -- JSON: {modules_required:[...], min_businesses:N, ...}
  `active`      TINYINT(1) DEFAULT 1,
  `sort_order`  TINYINT DEFAULT 0,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `partner_certifications` (name, slug, description, icon, level, sort_order) VALUES
  ('Certified Growth Partner',      'cgp',      'Core certification for all active partners.',                    '🏅', 'foundation',   1),
  ('Digital Marketing Specialist',  'dms',      'Expertise in digital marketing for local businesses.',           '📱', 'intermediate', 2),
  ('Business Development Expert',   'bde',      'Advanced skills in lead generation and business development.',   '💼', 'advanced',     3),
  ('AI Growth Strategist',          'ags',      'Proficiency in AI-powered growth tools and automation.',         '🧠', 'advanced',     4),
  ('Elite Partner',                 'elite',    'Top-tier recognition for consistent outstanding performance.',   '⭐', 'elite',        5);

-- Awarded certifications (partner ↔ cert)
CREATE TABLE IF NOT EXISTS `partner_cert_awards` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `partner_id`     INT UNSIGNED NOT NULL COMMENT 'partner_profiles.id',
  `cert_id`        SMALLINT UNSIGNED NOT NULL,
  `awarded_by`     INT UNSIGNED DEFAULT NULL COMMENT 'admin user_id or NULL=auto',
  `awarded_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`     DATETIME DEFAULT NULL,
  `revoked`        TINYINT(1) DEFAULT 0,
  `revoked_at`     DATETIME DEFAULT NULL,
  `revoked_reason` TEXT DEFAULT NULL,
  UNIQUE KEY `uq_partner_cert` (`partner_id`, `cert_id`),
  KEY `idx_partner`  (`partner_id`),
  KEY `idx_cert`     (`cert_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Partner locations / coverage areas
CREATE TABLE IF NOT EXISTS `partner_locations` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `partner_id`   INT UNSIGNED NOT NULL COMMENT 'partner_profiles.id',
  `region`       VARCHAR(120) NOT NULL,              -- e.g. South West, Limbe, Buea
  `city`         VARCHAR(120) DEFAULT NULL,
  `is_primary`   TINYINT(1) DEFAULT 0,
  `radius_km`    SMALLINT DEFAULT NULL,
  `notes`        TEXT DEFAULT NULL,
  KEY `idx_partner` (`partner_id`),
  KEY `idx_region`  (`region`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Capacity log (history of availability changes)
CREATE TABLE IF NOT EXISTS `partner_capacity_log` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `partner_id`     INT UNSIGNED NOT NULL,
  `from_status`    VARCHAR(40) DEFAULT NULL,
  `to_status`      VARCHAR(40) NOT NULL,
  `max_businesses` SMALLINT DEFAULT NULL,
  `changed_by`     INT UNSIGNED DEFAULT NULL,
  `reason`         VARCHAR(255) DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_partner` (`partner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Partner verifications (admin checklist)
CREATE TABLE IF NOT EXISTS `partner_verifications` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `partner_id`   INT UNSIGNED NOT NULL UNIQUE,
  `phone_verified`   TINYINT(1) DEFAULT 0,
  `phone_verified_at` DATETIME DEFAULT NULL,
  `id_sighted`       TINYINT(1) DEFAULT 0,
  `id_sighted_at`    DATETIME DEFAULT NULL,
  `area_confirmed`   TINYINT(1) DEFAULT 0,
  `area_confirmed_at` DATETIME DEFAULT NULL,
  `background_check` TINYINT(1) DEFAULT 0,
  `background_check_at` DATETIME DEFAULT NULL,
  `notes`            TEXT DEFAULT NULL,
  `verified_by`      INT UNSIGNED DEFAULT NULL,
  `verified_at`      DATETIME DEFAULT NULL,
  KEY `idx_partner` (`partner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Partner agreements (versioned, digital acceptance)
CREATE TABLE IF NOT EXISTS `partner_agreements` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `partner_id`     INT UNSIGNED NOT NULL,
  `version`        VARCHAR(20) NOT NULL DEFAULT '1.0',
  `agreement_text` LONGTEXT DEFAULT NULL,
  `accepted`       TINYINT(1) DEFAULT 0,
  `accepted_at`    DATETIME DEFAULT NULL,
  `accepted_ip`    VARCHAR(45) DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_partner` (`partner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Phase 4B: Partner Marketplace ────────────────────────────────

-- Business → partner requests (from listing owners)
CREATE TABLE IF NOT EXISTS `partner_requests` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `listing_id`   INT UNSIGNED DEFAULT NULL,
  `requester_id` INT UNSIGNED NOT NULL COMMENT 'users.id',
  `business_name` VARCHAR(200) DEFAULT NULL,
  `region`       VARCHAR(120) DEFAULT NULL,
  `specialisms_needed` TEXT DEFAULT NULL,    -- JSON array
  `budget_range` VARCHAR(80) DEFAULT NULL,
  `description`  TEXT DEFAULT NULL,
  `status`       ENUM('pending','matched','assigned','closed','declined') NOT NULL DEFAULT 'pending',
  `assigned_partner_id` INT UNSIGNED DEFAULT NULL,
  `assigned_by`  INT UNSIGNED DEFAULT NULL,
  `assigned_at`  DATETIME DEFAULT NULL,
  `notes`        TEXT DEFAULT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_listing`  (`listing_id`),
  KEY `idx_status`   (`status`),
  KEY `idx_requester` (`requester_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Partner feedback (businesses rate their partner)
CREATE TABLE IF NOT EXISTS `partner_feedback` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `partner_id`   INT UNSIGNED NOT NULL,
  `listing_id`   INT UNSIGNED NOT NULL,
  `reviewer_id`  INT UNSIGNED NOT NULL COMMENT 'users.id',
  `rating`       TINYINT UNSIGNED NOT NULL DEFAULT 5,   -- 1-5
  `comment`      TEXT DEFAULT NULL,
  `categories`   TEXT DEFAULT NULL,                    -- JSON: {communication:4, results:5, ...}
  `admin_flagged` TINYINT(1) DEFAULT 0,
  `published`    TINYINT(1) DEFAULT 1,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_partner_listing_review` (`partner_id`, `listing_id`, `reviewer_id`),
  KEY `idx_partner` (`partner_id`),
  KEY `idx_listing` (`listing_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Phase 4C: Partner Ecosystem ───────────────────────────────────

-- Academy training modules
CREATE TABLE IF NOT EXISTS `academy_modules` (
  `id`           SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title`        VARCHAR(200) NOT NULL,
  `slug`         VARCHAR(120) NOT NULL UNIQUE,
  `description`  TEXT DEFAULT NULL,
  `content`      LONGTEXT DEFAULT NULL,               -- HTML content
  `video_url`    VARCHAR(500) DEFAULT NULL,
  `duration_mins` SMALLINT DEFAULT NULL,
  `cert_id`      SMALLINT UNSIGNED DEFAULT NULL,      -- unlocks this cert on completion
  `order_in_cert` TINYINT DEFAULT 0,
  `level`        ENUM('foundation','intermediate','advanced','elite') DEFAULT 'foundation',
  `active`       TINYINT(1) DEFAULT 1,
  `sort_order`   SMALLINT DEFAULT 0,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_cert` (`cert_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `academy_modules` (title, slug, description, level, sort_order) VALUES
  ('Welcome to 237Biz Partner Network',  'welcome',             'Introduction to the platform, your dashboard, and getting started.', 'foundation',   1),
  ('Understanding Cameroonian Businesses','cameroon-businesses', 'Market context, business types, and how to build trust locally.',    'foundation',   2),
  ('Your First Business Audit',          'first-audit',         'Step-by-step guide to auditing a new business client.',              'foundation',   3),
  ('Digital Marketing Fundamentals',     'digital-marketing',   'Social media, content, and campaign basics for local businesses.',   'intermediate', 4),
  ('Using AI Growth Tools',              'ai-tools',            'How to use the AI content, recommendations, and automation tools.',  'intermediate', 5),
  ('Lead Generation & Conversion',       'lead-gen',            'Strategies for generating and converting leads for clients.',        'advanced',     6);

-- Academy progress (partner module completions)
CREATE TABLE IF NOT EXISTS `academy_progress` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `partner_id`   INT UNSIGNED NOT NULL,
  `module_id`    SMALLINT UNSIGNED NOT NULL,
  `started_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME DEFAULT NULL,
  `score`        TINYINT UNSIGNED DEFAULT NULL,       -- quiz score %
  UNIQUE KEY `uq_partner_module` (`partner_id`, `module_id`),
  KEY `idx_partner`   (`partner_id`),
  KEY `idx_module`    (`module_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Resource library
CREATE TABLE IF NOT EXISTS `resource_library` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title`        VARCHAR(200) NOT NULL,
  `description`  TEXT DEFAULT NULL,
  `category`     ENUM('pitch_deck','template','guide','case_study','script','checklist','other') NOT NULL DEFAULT 'guide',
  `file_url`     VARCHAR(500) DEFAULT NULL,
  `file_type`    VARCHAR(20) DEFAULT NULL,            -- pdf, docx, pptx, etc.
  `file_size_kb` INT UNSIGNED DEFAULT NULL,
  `tier_required` TINYINT UNSIGNED DEFAULT 1,         -- min tier_id to access
  `cert_required` SMALLINT UNSIGNED DEFAULT NULL,     -- cert_id required, or NULL
  `download_count` INT UNSIGNED DEFAULT 0,
  `active`       TINYINT(1) DEFAULT 1,
  `sort_order`   SMALLINT DEFAULT 0,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Partner-visible opportunities (admin-posted)
CREATE TABLE IF NOT EXISTS `partner_opportunities` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title`        VARCHAR(200) NOT NULL,
  `description`  TEXT DEFAULT NULL,
  `type`         ENUM('new_territory','campaign_brief','urgent_request','special_project','other') NOT NULL DEFAULT 'other',
  `region`       VARCHAR(120) DEFAULT NULL,
  `reward`       VARCHAR(120) DEFAULT NULL,           -- e.g. "Bonus 5% commission"
  `deadline`     DATE DEFAULT NULL,
  `tier_required` TINYINT UNSIGNED DEFAULT 1,
  `status`       ENUM('open','closed','filled') NOT NULL DEFAULT 'open',
  `created_by`   INT UNSIGNED DEFAULT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_status` (`status`),
  KEY `idx_region` (`region`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Partner opportunity expressions of interest
CREATE TABLE IF NOT EXISTS `opportunity_interests` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `opportunity_id` INT UNSIGNED NOT NULL,
  `partner_id`     INT UNSIGNED NOT NULL,
  `message`        TEXT DEFAULT NULL,
  `status`         ENUM('pending','selected','declined') NOT NULL DEFAULT 'pending',
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_opp_partner` (`opportunity_id`, `partner_id`),
  KEY `idx_opportunity` (`opportunity_id`),
  KEY `idx_partner`     (`partner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Phase 4C: Communication ───────────────────────────────────────

-- Internal messaging (admin ↔ partner or admin → all/region)
CREATE TABLE IF NOT EXISTS `partner_messages` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `from_user_id` INT UNSIGNED NOT NULL,
  `to_partner_id` INT UNSIGNED DEFAULT NULL,    -- NULL = broadcast
  `broadcast_to` ENUM('all','region','tier') DEFAULT NULL,
  `broadcast_val` VARCHAR(120) DEFAULT NULL,    -- region name or tier_id
  `subject`      VARCHAR(255) DEFAULT NULL,
  `body`         TEXT NOT NULL,
  `read_at`      DATETIME DEFAULT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_to_partner` (`to_partner_id`),
  KEY `idx_from`       (`from_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Message read receipts (for broadcasts)
CREATE TABLE IF NOT EXISTS `message_reads` (
  `message_id` INT UNSIGNED NOT NULL,
  `partner_id` INT UNSIGNED NOT NULL,
  `read_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (`message_id`, `partner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Phase 4D: Analytics support ───────────────────────────────────

-- Regional market coverage snapshots (cron-updated)
CREATE TABLE IF NOT EXISTS `market_coverage` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `region`          VARCHAR(120) NOT NULL,
  `total_listings`  INT UNSIGNED DEFAULT 0,
  `listings_with_partner` INT UNSIGNED DEFAULT 0,
  `active_partners` SMALLINT UNSIGNED DEFAULT 0,
  `penetration_pct` DECIMAL(5,2) DEFAULT 0.00,
  `snapshot_date`   DATE NOT NULL,
  KEY `idx_region` (`region`),
  KEY `idx_date`   (`snapshot_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Partner performance snapshots (weekly)
CREATE TABLE IF NOT EXISTS `partner_performance_log` (
  `id`                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `partner_id`         INT UNSIGNED NOT NULL,
  `period_start`       DATE NOT NULL,
  `period_end`         DATE NOT NULL,
  `businesses_managed` SMALLINT DEFAULT 0,
  `tasks_completed`    SMALLINT DEFAULT 0,
  `leads_converted`    SMALLINT DEFAULT 0,
  `campaigns_run`      SMALLINT DEFAULT 0,
  `content_published`  SMALLINT DEFAULT 0,
  `avg_growth_score`   DECIMAL(5,2) DEFAULT 0.00,
  `feedback_avg`       DECIMAL(3,2) DEFAULT NULL,
  `commission_earned`  DECIMAL(10,2) DEFAULT 0.00,
  `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_partner` (`partner_id`),
  KEY `idx_period`  (`period_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
