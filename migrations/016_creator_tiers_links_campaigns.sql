-- =============================================================
-- 016: Creator Program — Base Rate, Tiers, Multi-Link Manager,
--      Campaign Targeting, Renewal Commissions
-- =============================================================
-- Additive only. Safe to re-run — every statement is guarded with
-- IF NOT EXISTS / ADD COLUMN IF NOT EXISTS where the MySQL version
-- supports it (MariaDB 10.x / MySQL 8+ on cPanel shared hosting).
-- Run on biz97h_app via phpMyAdmin → Import.
-- =============================================================

SET FOREIGN_KEY_CHECKS=0;

-- ─────────────────────────────────────────────
-- 1. creator_profiles — base rate + tier override
-- ─────────────────────────────────────────────
ALTER TABLE `creator_profiles`
  ADD COLUMN IF NOT EXISTS `base_rate_xaf`    INT UNSIGNED NOT NULL DEFAULT 50000  AFTER `status`,
  ADD COLUMN IF NOT EXISTS `base_rate_active` TINYINT(1)   NOT NULL DEFAULT 0      AFTER `base_rate_xaf`,
  ADD COLUMN IF NOT EXISTS `tier_override`    ENUM('auto','15','20','25') NOT NULL DEFAULT 'auto' AFTER `base_rate_active`;

-- Auto-activate the base rate for creators already approved (one-time backfill;
-- harmless to re-run — only touches rows still at the default 0/approved combo).
UPDATE `creator_profiles` SET `base_rate_active` = 1 WHERE `status` = 'approved' AND `base_rate_active` = 0;

-- ─────────────────────────────────────────────
-- 2. referral_links — named / multi-link support
-- ─────────────────────────────────────────────
ALTER TABLE `referral_links`
  ADD COLUMN IF NOT EXISTS `label`       VARCHAR(100) DEFAULT NULL  AFTER `code`,
  ADD COLUMN IF NOT EXISTS `campaign_id` INT UNSIGNED DEFAULT NULL  AFTER `label`,
  ADD COLUMN IF NOT EXISTS `link_type`   ENUM('general','campaign','custom') NOT NULL DEFAULT 'custom' AFTER `campaign_id`,
  ADD INDEX IF NOT EXISTS `idx_campaign` (`campaign_id`);

-- ─────────────────────────────────────────────
-- 3. referral_conversions — order value (tier basis) + renewal type
-- ─────────────────────────────────────────────
ALTER TABLE `referral_conversions`
  ADD COLUMN IF NOT EXISTS `order_value_xaf` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `conversion_type`;

-- 'conversion_type' is a free-text VARCHAR(80) in the live schema (not an ENUM),
-- so the new value 'renewal' needs no column change — just start writing it.

-- ─────────────────────────────────────────────
-- 4. campaigns — general flag + target audience
-- ─────────────────────────────────────────────
ALTER TABLE `campaigns`
  ADD COLUMN IF NOT EXISTS `is_general` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`,
  ADD COLUMN IF NOT EXISTS `audience`   ENUM('all','selected') NOT NULL DEFAULT 'all' AFTER `is_general`;

-- Only one general ("General 237Biz Promotion") campaign should ever exist;
-- enforced at the application layer (ensureGeneralCampaign()), not by a
-- unique index, since older MySQL/MariaDB on shared hosting can't partial-index.

CREATE TABLE IF NOT EXISTS `campaign_targets` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `campaign_id` INT UNSIGNED NOT NULL,
  `user_id`     INT UNSIGNED NOT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_campaign_user` (`campaign_id`,`user_id`),
  KEY `idx_ct_campaign` (`campaign_id`),
  KEY `idx_ct_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
