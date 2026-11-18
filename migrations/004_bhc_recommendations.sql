-- Migration 004: Create bhc_recommendations table
-- Run once on the live server via phpMyAdmin or MySQL CLI.
-- The admin/bhc-recommendations.php page also runs CREATE TABLE IF NOT EXISTS
-- automatically, so this file is for documentation / auditing purposes.

CREATE TABLE IF NOT EXISTS bhc_recommendations (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL COMMENT 'FK → bhc_categories.id',
    score_band  ENUM('needs_attention','developing','good_progress','strong_foundations') NOT NULL,
    priority    TINYINT UNSIGNED NOT NULL DEFAULT 5
                COMMENT '1 = show first; top 4 by priority per band are used in reports',
    title_en    VARCHAR(200) NOT NULL,
    body_en     TEXT         NOT NULL,
    title_fr    VARCHAR(200) DEFAULT NULL,
    body_fr     TEXT         DEFAULT NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_band_cat  (score_band, category_id),
    INDEX idx_priority  (priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: example recommendations (optional — remove if seeding manually via UI)
-- INSERT INTO bhc_recommendations (category_id, score_band, priority, title_en, body_en) VALUES
-- (1, 'needs_attention', 1, 'Claim your Google Business Profile', 'Set up and verify your Google Business Profile so customers can find your hours, location, and reviews on Google Search and Maps. This is free and typically takes under 30 minutes.'),
-- (1, 'needs_attention', 2, 'Ensure your NAP is consistent online', 'Your Name, Address, and Phone number (NAP) should be identical across every directory listing and your website. Inconsistencies confuse search engines and reduce local ranking.');
