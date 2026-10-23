-- Migration: ensure promo_uses table exists with required columns
-- and add used_count to promo_codes if missing.
-- Run once via cPanel phpMyAdmin.

CREATE TABLE IF NOT EXISTS `promo_uses` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `code_id`      INT UNSIGNED NOT NULL,
  `user_id`      INT UNSIGNED NOT NULL,
  `listing_id`   INT UNSIGNED DEFAULT NULL,
  `ref`          VARCHAR(64)  DEFAULT NULL COMMENT 'listing_payments.ref',
  `discount_xaf` INT UNSIGNED NOT NULL DEFAULT 0,
  `used_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_code`    (`code_id`),
  KEY `idx_user`    (`user_id`),
  KEY `idx_listing` (`listing_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add used_count to promo_codes if it doesn't already exist
ALTER TABLE `promo_codes`
  ADD COLUMN IF NOT EXISTS `used_count` INT UNSIGNED NOT NULL DEFAULT 0;
