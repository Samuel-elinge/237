-- Migration 009: Suggested / Requested Listings
-- Run once on the live database via phpMyAdmin or cPanel MySQL.
-- This creates the table that stores public suggestions/requests
-- for businesses that are not yet listed on 237Biz.

CREATE TABLE IF NOT EXISTS `suggested_listings` (
  `id`             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `business_name`  VARCHAR(200)    NOT NULL,
  `category_id`    INT UNSIGNED        NULL DEFAULT NULL,
  `location_id`    INT UNSIGNED        NULL DEFAULT NULL,
  `description`    TEXT                NULL DEFAULT NULL,
  `contact_info`   VARCHAR(200)        NULL DEFAULT NULL COMMENT 'Phone/email/website of the suggested biz, if known',
  `submitter_name` VARCHAR(100)        NULL DEFAULT NULL,
  `submitter_email`VARCHAR(200)        NULL DEFAULT NULL,
  `submitter_ip`   VARCHAR(45)         NULL DEFAULT NULL,
  `status`         ENUM('pending','approved','rejected','converted') NOT NULL DEFAULT 'pending',
  `admin_notes`    TEXT                NULL DEFAULT NULL,
  `converted_listing_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'listings.id once converted',
  `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status`     (`status`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
