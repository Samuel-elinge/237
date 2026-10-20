-- ============================================================
-- 237Biz Phase 3 — Monetisation Migration
-- Run once via phpMyAdmin or MySQL CLI
-- Safe to re-run (uses IF NOT EXISTS / ADD COLUMN IF NOT EXISTS)
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────
-- MODULE A: Subscription Plans
-- ─────────────────────────────────────────────────────────────

-- Configurable subscription plans (admin-managed, no code changes needed)
CREATE TABLE IF NOT EXISTS `subscription_plans` (
  `id`                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `slug`                    VARCHAR(60) NOT NULL UNIQUE,
  `name_en`                 VARCHAR(120) NOT NULL,
  `name_fr`                 VARCHAR(120) NOT NULL,
  `description_en`          TEXT DEFAULT NULL,
  `description_fr`          TEXT DEFAULT NULL,
  `price_xaf`               INT UNSIGNED NOT NULL DEFAULT 0,
  `billing_interval`        ENUM('monthly','annual','one_off') NOT NULL DEFAULT 'monthly',
  `duration_days`           SMALLINT UNSIGNED NOT NULL DEFAULT 30  COMMENT '30=monthly, 365=annual, 0=unlimited',
  `max_offers`              TINYINT UNSIGNED NOT NULL DEFAULT 3     COMMENT 'max active offers',
  `max_events`              TINYINT UNSIGNED NOT NULL DEFAULT 2,
  `analytics_level`         ENUM('basic','enhanced','advanced') NOT NULL DEFAULT 'basic',
  `featured_eligible`       TINYINT(1) NOT NULL DEFAULT 0,
  `promotions_eligible`     TINYINT(1) NOT NULL DEFAULT 0,
  `verified_badge`          TINYINT(1) NOT NULL DEFAULT 0,
  `priority_support`        TINYINT(1) NOT NULL DEFAULT 0,
  `is_free`                 TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order`              TINYINT NOT NULL DEFAULT 0,
  `active`                  TINYINT(1) NOT NULL DEFAULT 1,
  `highlight_color`         VARCHAR(20) DEFAULT NULL  COMMENT 'e.g. #00A878 for featured plan',
  `badge_label_en`          VARCHAR(60) DEFAULT NULL  COMMENT 'e.g. Most Popular',
  `badge_label_fr`          VARCHAR(60) DEFAULT NULL,
  `created_at`              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_active`  (`active`),
  KEY `idx_sort`    (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the three initial plans
INSERT IGNORE INTO `subscription_plans`
  (slug, name_en, name_fr, description_en, description_fr,
   price_xaf, billing_interval, duration_days,
   max_offers, max_events, analytics_level,
   featured_eligible, promotions_eligible, verified_badge, priority_support,
   is_free, sort_order, highlight_color, badge_label_en, badge_label_fr)
VALUES
  ('free', 'Free Listing', 'Annonce Gratuite',
   'Get your business listed in the directory at no cost.',
   'Inscrivez votre entreprise dans l\'annuaire gratuitement.',
   0, 'monthly', 0, 2, 1, 'basic', 0, 0, 0, 0, 1, 1, NULL, NULL, NULL),

  ('growth', 'Business Growth', 'Croissance Business',
   'Grow your visibility with more offers, events and analytics.',
   'Augmentez votre visibilité avec plus d\'offres, d\'événements et d\'analyses.',
   15000, 'monthly', 30, 5, 3, 'enhanced', 1, 1, 1, 0, 0, 2, '#00A878', 'Most Popular', 'Le Plus Populaire'),

  ('premium', 'Premium', 'Premium',
   'Maximum visibility, advanced analytics and priority placement.',
   'Visibilité maximale, analyses avancées et placement prioritaire.',
   35000, 'monthly', 30, 15, 10, 'advanced', 1, 1, 1, 1, 0, 3, '#F5C842', NULL, NULL);

-- Business subscriptions (one active record per listing)
CREATE TABLE IF NOT EXISTS `business_subscriptions` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `listing_id`     INT UNSIGNED NOT NULL,
  `user_id`        INT UNSIGNED NOT NULL  COMMENT 'listing owner',
  `plan_id`        INT UNSIGNED NOT NULL  COMMENT 'subscription_plans.id',
  `status`         ENUM('pending_payment','active','payment_failed','past_due','cancellation_scheduled','cancelled','expired','suspended') NOT NULL DEFAULT 'pending_payment',
  `starts_at`      DATETIME DEFAULT NULL,
  `ends_at`        DATETIME DEFAULT NULL  COMMENT 'NULL = unlimited (free)',
  `cancelled_at`   DATETIME DEFAULT NULL,
  `cancellation_reason` TEXT DEFAULT NULL,
  `renewed_from`   INT UNSIGNED DEFAULT NULL  COMMENT 'previous subscription id',
  `admin_note`     TEXT DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_listing` (`listing_id`),
  KEY `idx_user`    (`user_id`),
  KEY `idx_status`  (`status`),
  KEY `idx_ends`    (`ends_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- MODULE B: Promotion Products & Orders
-- ─────────────────────────────────────────────────────────────

-- Configurable promotion products
CREATE TABLE IF NOT EXISTS `promotion_products` (
  `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `slug`                VARCHAR(80) NOT NULL UNIQUE,
  `name_en`             VARCHAR(160) NOT NULL,
  `name_fr`             VARCHAR(160) NOT NULL,
  `description_en`      TEXT DEFAULT NULL,
  `description_fr`      TEXT DEFAULT NULL,
  `promo_type`          ENUM('featured_listing','featured_offer','promoted_event','category_placement','location_placement','homepage') NOT NULL,
  `price_xaf`           INT UNSIGNED NOT NULL DEFAULT 0,
  `duration_days`       TINYINT UNSIGNED NOT NULL DEFAULT 7,
  `max_concurrent`      TINYINT UNSIGNED DEFAULT NULL  COMMENT 'NULL = unlimited',
  `eligible_content`    SET('listing','offer','event') NOT NULL DEFAULT 'listing',
  `min_plan_slug`       VARCHAR(60) DEFAULT NULL  COMMENT 'min subscription plan required, NULL = any',
  `sort_order`          TINYINT NOT NULL DEFAULT 0,
  `active`              TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_type`   (`promo_type`),
  KEY `idx_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `promotion_products`
  (slug, name_en, name_fr, promo_type, price_xaf, duration_days, eligible_content, sort_order)
VALUES
  ('featured-offer-7d',   'Featured Offer — 7 days',  'Offre Vedette — 7 jours',  'featured_offer',    5000,  7, 'offer',    1),
  ('featured-offer-14d',  'Featured Offer — 14 days', 'Offre Vedette — 14 jours', 'featured_offer',    8000, 14, 'offer',    2),
  ('promoted-event-7d',   'Promoted Event — 7 days',  'Événement Promu — 7 jours','promoted_event',    5000,  7, 'event',    3),
  ('featured-listing-7d', 'Featured Listing — 7 days','Annonce Vedette — 7 jours','featured_listing', 10000,  7, 'listing',  4),
  ('featured-listing-30d','Featured Listing — 30 days','Annonce Vedette — 30 jours','featured_listing',25000, 30, 'listing', 5);

-- Promotion orders
CREATE TABLE IF NOT EXISTS `promotion_orders` (
  `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `ref`                 VARCHAR(40) NOT NULL UNIQUE,
  `listing_id`          INT UNSIGNED NOT NULL,
  `user_id`             INT UNSIGNED NOT NULL,
  `product_id`          INT UNSIGNED NOT NULL   COMMENT 'promotion_products.id',
  `content_type`        ENUM('listing','offer','event') NOT NULL DEFAULT 'listing',
  `content_id`          INT UNSIGNED NOT NULL  COMMENT 'id in the relevant table',
  `amount_xaf`          INT UNSIGNED NOT NULL,
  `proof`               VARCHAR(255) DEFAULT NULL  COMMENT 'uploaded payment proof filename',
  `payment_status`      ENUM('pending','paid','failed','cancelled','refunded','partially_refunded') NOT NULL DEFAULT 'pending',
  `promo_status`        ENUM('draft','pending_payment','pending_approval','scheduled','active','paused','completed','rejected','cancelled','refunded') NOT NULL DEFAULT 'pending_payment',
  `starts_at`           DATETIME DEFAULT NULL,
  `ends_at`             DATETIME DEFAULT NULL,
  `approved_by`         INT UNSIGNED DEFAULT NULL,
  `approved_at`         DATETIME DEFAULT NULL,
  `rejection_reason`    TEXT DEFAULT NULL,
  `admin_note`          TEXT DEFAULT NULL,
  `partner_ref`         VARCHAR(40) DEFAULT NULL  COMMENT 'referral attribution ref',
  `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_listing`     (`listing_id`),
  KEY `idx_user`        (`user_id`),
  KEY `idx_promo_status`(`promo_status`),
  KEY `idx_ends`        (`ends_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- MODULE C: Orders — unified monetisation ledger
-- ─────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `monetisation_orders` (
  `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `ref`                 VARCHAR(40) NOT NULL UNIQUE,
  `listing_id`          INT UNSIGNED NOT NULL,
  `user_id`             INT UNSIGNED NOT NULL,
  `product_type`        ENUM('subscription','promotion') NOT NULL,
  `product_id`          INT UNSIGNED NOT NULL  COMMENT 'plan_id or product_id',
  `linked_id`           INT UNSIGNED DEFAULT NULL COMMENT 'business_subscriptions.id or promotion_orders.id',
  `amount_xaf`          INT UNSIGNED NOT NULL,
  `discount_xaf`        INT UNSIGNED NOT NULL DEFAULT 0,
  `net_xaf`             INT UNSIGNED NOT NULL,
  `proof`               VARCHAR(255) DEFAULT NULL,
  `payment_status`      ENUM('pending','paid','failed','cancelled','refunded','partially_refunded','disputed') NOT NULL DEFAULT 'pending',
  `payment_confirmed_at` DATETIME DEFAULT NULL,
  `confirmed_by`        INT UNSIGNED DEFAULT NULL  COMMENT 'admin user_id',
  `partner_id`          INT UNSIGNED DEFAULT NULL  COMMENT 'partner_profiles.id for attribution',
  `referral_code`       VARCHAR(60) DEFAULT NULL,
  `commission_created`  TINYINT(1) NOT NULL DEFAULT 0,
  `refund_amount_xaf`   INT UNSIGNED DEFAULT NULL,
  `refund_reason`       TEXT DEFAULT NULL,
  `refunded_at`         DATETIME DEFAULT NULL,
  `notes`               TEXT DEFAULT NULL,
  `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_listing`     (`listing_id`),
  KEY `idx_user`        (`user_id`),
  KEY `idx_product`     (`product_type`, `product_id`),
  KEY `idx_status`      (`payment_status`),
  KEY `idx_partner`     (`partner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- MODULE D & E: Partner referral attribution extensions
-- ─────────────────────────────────────────────────────────────

-- Extend commission_rules to support subscription and promotion event types
ALTER TABLE `commission_rules`
  MODIFY COLUMN IF EXISTS `event_type`
    ENUM('business_referral','paid_listing','featured_listing','advertising','campaign',
         'website_sale','business_email','supportdesk_sale',
         'subscription_new','subscription_renewal','promotion_purchase','other')
    NOT NULL DEFAULT 'other';

-- Add new columns to partner_commissions for Phase 3 orders
ALTER TABLE `partner_commissions`
  ADD COLUMN IF NOT EXISTS `order_id`       INT UNSIGNED DEFAULT NULL  COMMENT 'monetisation_orders.id' AFTER `transaction_ref`,
  ADD COLUMN IF NOT EXISTS `order_type`     ENUM('subscription','promotion','listing') DEFAULT NULL AFTER `order_id`,
  ADD COLUMN IF NOT EXISTS `reversed_at`    DATETIME DEFAULT NULL  AFTER `paid_at`,
  ADD COLUMN IF NOT EXISTS `reversal_reason` TEXT DEFAULT NULL  AFTER `reversed_at`;

-- Payout batches (admin groups payable commissions into a payout)
CREATE TABLE IF NOT EXISTS `partner_payout_batches` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `ref`           VARCHAR(40) NOT NULL UNIQUE,
  `partner_id`    INT UNSIGNED NOT NULL  COMMENT 'partner_profiles.id',
  `total_xaf`     INT UNSIGNED NOT NULL DEFAULT 0,
  `currency`      VARCHAR(10) NOT NULL DEFAULT 'XAF',
  `status`        ENUM('draft','approved','processing','completed','failed','cancelled') NOT NULL DEFAULT 'draft',
  `payout_method` VARCHAR(80) DEFAULT NULL  COMMENT 'e.g. MTN MoMo, Bank Transfer',
  `payout_ref`    VARCHAR(200) DEFAULT NULL  COMMENT 'external transfer reference',
  `approved_by`   INT UNSIGNED DEFAULT NULL,
  `approved_at`   DATETIME DEFAULT NULL,
  `paid_at`       DATETIME DEFAULT NULL,
  `notes`         TEXT DEFAULT NULL,
  `created_by`    INT UNSIGNED DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_partner` (`partner_id`),
  KEY `idx_status`  (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Link commissions to a payout batch
ALTER TABLE `partner_commissions`
  ADD COLUMN IF NOT EXISTS `payout_batch_id` INT UNSIGNED DEFAULT NULL  AFTER `paid_at`,
  ADD INDEX IF NOT EXISTS `idx_payout_batch` (`payout_batch_id`);

-- ─────────────────────────────────────────────────────────────
-- Add referral_links table if it doesn't exist
-- (used by ref.php — may or may not already be in DB)
-- ─────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `referral_programmes` (
  `id`           TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`         VARCHAR(120) NOT NULL,
  `cookie_days`  TINYINT UNSIGNED NOT NULL DEFAULT 30,
  `active`       TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `referral_programmes` (id, name, cookie_days) VALUES (1, 'Default', 30);

CREATE TABLE IF NOT EXISTS `referral_links` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`        INT UNSIGNED NOT NULL,
  `programme_id`   TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `code`           VARCHAR(60) NOT NULL UNIQUE,
  `clicks`         INT UNSIGNED NOT NULL DEFAULT 0,
  `unique_clicks`  INT UNSIGNED NOT NULL DEFAULT 0,
  `active`         TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_user`   (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `referral_clicks` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `link_id`      INT UNSIGNED NOT NULL,
  `ip_hash`      VARCHAR(64) DEFAULT NULL,
  `user_agent`   VARCHAR(500) DEFAULT NULL,
  `referrer`     VARCHAR(500) DEFAULT NULL,
  `is_unique`    TINYINT(1) NOT NULL DEFAULT 1,
  `clicked_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_link`     (`link_id`),
  KEY `idx_clicked`  (`clicked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `referral_conversions` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `link_id`        INT UNSIGNED NOT NULL,
  `listing_id`     INT UNSIGNED DEFAULT NULL,
  `user_id`        INT UNSIGNED DEFAULT NULL,
  `conversion_type` VARCHAR(80) DEFAULT 'registration',
  `order_id`       INT UNSIGNED DEFAULT NULL  COMMENT 'monetisation_orders.id',
  `converted_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_link`    (`link_id`),
  KEY `idx_listing` (`listing_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- Notification log (idempotent delivery tracking)
-- ─────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `monetisation_notification_log` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `event_key`    VARCHAR(160) NOT NULL UNIQUE  COMMENT 'e.g. sub_activated_123, promo_expired_456',
  `recipient`    VARCHAR(200) NOT NULL,
  `sent_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_event` (`event_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- Phase 3 migration complete.
-- Next: run admin/phase3-seed-plans.php to verify plan data.
-- ============================================================
