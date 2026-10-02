-- =============================================================
-- 237Biz Growth Partner — Phase 2 Migration
-- Run AFTER growth-partner-migration.sql (Phase 1)
-- Import via phpMyAdmin: click your database first, then Import.
-- =============================================================
-- NOTE: Foreign key constraints to listings/partner_profiles removed
-- to avoid type-mismatch errors (errno 150) on shared hosting.
-- Referential integrity is enforced at the application layer.
-- =============================================================

SET FOREIGN_KEY_CHECKS=0;

-- ─────────────────────────────────────────────
-- 1. CAMPAIGNS
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS campaigns (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    partner_id      INT UNSIGNED NOT NULL,
    listing_id      INT UNSIGNED NOT NULL,
    name            VARCHAR(200) NOT NULL,
    campaign_type   ENUM(
                        'business_promotion','product_promotion','service_promotion',
                        'special_offer','event','review_campaign',
                        'social_media_campaign','seasonal_campaign'
                    ) NOT NULL DEFAULT 'business_promotion',
    description     TEXT,
    objective       TEXT,
    target_audience VARCHAR(300),
    offer           VARCHAR(500),
    budget          DECIMAL(12,2) UNSIGNED,
    currency        CHAR(3) NOT NULL DEFAULT 'XAF',
    call_to_action  VARCHAR(200),
    status          ENUM('draft','scheduled','active','paused','completed','cancelled')
                        NOT NULL DEFAULT 'draft',
    start_date      DATE,
    end_date        DATE,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_camp_partner (partner_id),
    KEY idx_camp_listing (listing_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- 2. CAMPAIGN CONTENT
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS campaign_content (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id  INT UNSIGNED NOT NULL,
    content_type ENUM('text','image','video','link') NOT NULL DEFAULT 'text',
    body         TEXT,
    media_url    VARCHAR(500),
    sort_order   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cc_campaign (campaign_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- 3. CAMPAIGN METRICS
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS campaign_metrics (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id     INT UNSIGNED NOT NULL,
    metric_date     DATE NOT NULL,
    source          ENUM('platform','partner_reported') NOT NULL DEFAULT 'platform',
    impressions     INT UNSIGNED NOT NULL DEFAULT 0,
    profile_visits  INT UNSIGNED NOT NULL DEFAULT 0,
    clicks          INT UNSIGNED NOT NULL DEFAULT 0,
    website_clicks  INT UNSIGNED NOT NULL DEFAULT 0,
    whatsapp_clicks INT UNSIGNED NOT NULL DEFAULT 0,
    calls           INT UNSIGNED NOT NULL DEFAULT 0,
    leads           INT UNSIGNED NOT NULL DEFAULT 0,
    bookings        INT UNSIGNED NOT NULL DEFAULT 0,
    conversions     INT UNSIGNED NOT NULL DEFAULT 0,
    notes           TEXT,
    recorded_by     INT UNSIGNED,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_camp_date_source (campaign_id, metric_date, source),
    KEY idx_cm_campaign (campaign_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- 4. CONTENT ITEMS (social / marketing content)
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS content_items (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    partner_id       INT UNSIGNED NOT NULL,
    listing_id       INT UNSIGNED NOT NULL,
    campaign_id      INT UNSIGNED,
    content_type     ENUM(
                         'social_post','promotional_post','product_post','service_post',
                         'event_post','review_post','video','image','announcement'
                     ) NOT NULL DEFAULT 'social_post',
    title            VARCHAR(200),
    body             TEXT,
    media_url        VARCHAR(500),
    platform         VARCHAR(50),
    external_post_id VARCHAR(200),
    scheduled_date   DATETIME,
    published_date   DATETIME,
    status           ENUM('draft','scheduled','published','archived') NOT NULL DEFAULT 'draft',
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_ci_partner  (partner_id),
    KEY idx_ci_listing  (listing_id),
    KEY idx_ci_campaign (campaign_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- 5. REVIEW CAMPAIGNS
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS review_campaigns (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    partner_id         INT UNSIGNED NOT NULL,
    listing_id         INT UNSIGNED NOT NULL,
    name               VARCHAR(200) NOT NULL,
    objective          TEXT,
    target_reviews     INT UNSIGNED NOT NULL DEFAULT 10,
    message_template   TEXT,
    start_date         DATE,
    end_date           DATE,
    status             ENUM('draft','active','completed','cancelled') NOT NULL DEFAULT 'draft',
    requests_sent      INT UNSIGNED NOT NULL DEFAULT 0,
    requests_delivered INT UNSIGNED NOT NULL DEFAULT 0,
    requests_clicked   INT UNSIGNED NOT NULL DEFAULT 0,
    reviews_generated  INT UNSIGNED NOT NULL DEFAULT 0,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_rc_partner (partner_id),
    KEY idx_rc_listing (listing_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- 6. LEAD ACTIVITIES
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS lead_activities (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_id       INT UNSIGNED NOT NULL,
    partner_id    INT UNSIGNED NOT NULL,
    activity_type ENUM(
                      'created','contacted','email_sent','message_sent',
                      'phone_call','follow_up','appointment','booking',
                      'converted','lost','note'
                  ) NOT NULL DEFAULT 'note',
    notes         TEXT,
    follow_up_date DATE,
    created_by    INT UNSIGNED,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_la_lead    (lead_id),
    KEY idx_la_partner (partner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- 7. GROWTH OPPORTUNITIES
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS growth_opportunities (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    partner_id      INT UNSIGNED NOT NULL,
    listing_id      INT UNSIGNED NOT NULL,
    service_type    VARCHAR(100) NOT NULL,
    title           VARCHAR(200) NOT NULL,
    description     TEXT,
    status          ENUM('identified','discussing','proposal','won','lost')
                        NOT NULL DEFAULT 'identified',
    estimated_value DECIMAL(12,2) UNSIGNED,
    currency        CHAR(3) NOT NULL DEFAULT 'XAF',
    notes           TEXT,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_go_partner (partner_id),
    KEY idx_go_listing (listing_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- 8. GROWTH REPORTS
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS growth_reports (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    partner_id   INT UNSIGNED NOT NULL,
    listing_id   INT UNSIGNED NOT NULL,
    period_start DATE NOT NULL,
    period_end   DATE NOT NULL,
    title        VARCHAR(200) NOT NULL,
    summary_json JSON,
    status       ENUM('draft','generated','shared') NOT NULL DEFAULT 'draft',
    version      TINYINT UNSIGNED NOT NULL DEFAULT 1,
    generated_at TIMESTAMP NULL,
    shared_at    TIMESTAMP NULL,
    created_by   INT UNSIGNED,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_gr_partner (partner_id),
    KEY idx_gr_listing (listing_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- 9. BUSINESS ACTIVITY FEED
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS business_activity (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    listing_id    INT UNSIGNED NOT NULL,
    partner_id    INT UNSIGNED,
    actor_id      INT UNSIGNED,
    activity_type VARCHAR(80) NOT NULL,
    description   TEXT NOT NULL,
    ref_type      VARCHAR(40),
    ref_id        INT UNSIGNED,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ba_listing (listing_id),
    KEY idx_ba_partner (partner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- 10. PARTNER NOTIFICATIONS
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS partner_notifications (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    partner_id INT UNSIGNED,
    listing_id INT UNSIGNED,
    type       VARCHAR(80) NOT NULL,
    title      VARCHAR(200) NOT NULL,
    body       TEXT,
    action_url VARCHAR(500),
    is_read    TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_pn_user (user_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- 11. Extend partner_leads (Phase 1 table)
-- ─────────────────────────────────────────────
ALTER TABLE partner_leads
    ADD COLUMN IF NOT EXISTS follow_up_date DATE AFTER next_action,
    ADD COLUMN IF NOT EXISTS last_contacted  DATETIME AFTER follow_up_date;

SET FOREIGN_KEY_CHECKS=1;
