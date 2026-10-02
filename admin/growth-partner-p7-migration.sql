-- ============================================================
-- 237biz Growth Partner Phase 7 Migration
-- Create partner_campaigns table (separate from admin campaigns)
-- Safe to re-run (uses IF NOT EXISTS)
-- ============================================================

CREATE TABLE IF NOT EXISTS partner_campaigns (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    partner_id    INT UNSIGNED NOT NULL,
    listing_id    INT UNSIGNED NOT NULL,
    title         VARCHAR(200) NOT NULL,
    description   TEXT,
    status        ENUM('draft','scheduled','active','paused','completed','cancelled') NOT NULL DEFAULT 'draft',
    start_date    DATE,
    end_date      DATE,
    budget        DECIMAL(10,2) DEFAULT 0,
    notes         TEXT,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_partner (partner_id),
    INDEX idx_listing (listing_id),
    INDEX idx_status  (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
