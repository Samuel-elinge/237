-- ============================================================
-- phase4-migration.sql — 237Biz Phase 4
-- Business Growth, Lead Management & Partner Enablement
-- Run once via phpMyAdmin on biz97h_app
-- Safe to re-run (IF NOT EXISTS / IF NOT EXISTS column checks)
-- ============================================================

-- ── 1. Extend listing_enquiries ───────────────────────────────────────────
-- Add Phase 4 CRM columns to existing table (safe if already added)
ALTER TABLE listing_enquiries
    ADD COLUMN IF NOT EXISTS `ref`            VARCHAR(20)  DEFAULT NULL            COMMENT 'ENQ-YYYYMM-XXXX',
    ADD COLUMN IF NOT EXISTS `subject`        VARCHAR(200) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `contact_method` ENUM('email','phone','whatsapp') DEFAULT 'email',
    ADD COLUMN IF NOT EXISTS `source_type`    ENUM('listing','offer','event','campaign') DEFAULT 'listing',
    ADD COLUMN IF NOT EXISTS `source_id`      INT UNSIGNED DEFAULT NULL            COMMENT 'offer_id / event_id / campaign_id',
    ADD COLUMN IF NOT EXISTS `crm_status`     ENUM('new','contacted','quoted','won','lost') NOT NULL DEFAULT 'new',
    ADD COLUMN IF NOT EXISTS `lost_reason`    VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `assigned_to`    INT UNSIGNED DEFAULT NULL            COMMENT 'user_id of staff member',
    ADD COLUMN IF NOT EXISTS `notes`          TEXT         DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `follow_up_at`   DATETIME     DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `first_response_at` DATETIME  DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `won_at`         DATETIME     DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `lost_at`        DATETIME     DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `spam_score`     TINYINT UNSIGNED DEFAULT 0;

-- Back-fill ref for existing unref'd enquiries
UPDATE listing_enquiries
SET ref = CONCAT('ENQ-', DATE_FORMAT(created_at,'%Y%m'), '-', LPAD(id, 4, '0'))
WHERE ref IS NULL;

-- Back-fill crm_status from old status column
UPDATE listing_enquiries SET crm_status = 'new'       WHERE status = 'unread';
UPDATE listing_enquiries SET crm_status = 'contacted' WHERE status = 'read';

-- Indexes
ALTER TABLE listing_enquiries
    ADD INDEX IF NOT EXISTS idx_crm_status  (crm_status),
    ADD INDEX IF NOT EXISTS idx_listing_crm (listing_id, crm_status),
    ADD INDEX IF NOT EXISTS idx_assigned    (assigned_to);

-- ── 2. Enquiry status history log ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS enquiry_status_log (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    enquiry_id   INT UNSIGNED NOT NULL,
    old_status   VARCHAR(30)  DEFAULT NULL,
    new_status   VARCHAR(30)  NOT NULL,
    changed_by   INT UNSIGNED NOT NULL COMMENT 'user_id',
    note         TEXT         DEFAULT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_enquiry (enquiry_id),
    KEY idx_changed_by (changed_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. Extend marketplace_impressions for click events ────────────────────
-- Add event_subtype so phone_click / whatsapp_click / website_click are tracked
ALTER TABLE marketplace_impressions
    ADD COLUMN IF NOT EXISTS `event_subtype` VARCHAR(40) DEFAULT NULL
        COMMENT 'null=view, phone_click, whatsapp_click, website_click, offer_click, booking_click';

-- Extend valid entity types (if column is ENUM, widen; if VARCHAR, no-op)
-- We track subtypes via event_subtype — entity_type stays listing/offer/event

-- ── 4. Profile completeness cache ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS listing_profile_scores (
    listing_id       INT UNSIGNED PRIMARY KEY,
    score_pct        TINYINT UNSIGNED NOT NULL DEFAULT 0  COMMENT '0-100',
    missing_fields   TEXT             DEFAULT NULL        COMMENT 'JSON array of missing field keys',
    last_calculated  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_score (score_pct)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 5. Growth recommendations ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS growth_recommendations (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    listing_id   INT UNSIGNED NOT NULL,
    rule_key     VARCHAR(60)  NOT NULL  COMMENT 'e.g. no_photos, high_views_low_contact',
    priority     TINYINT UNSIGNED DEFAULT 50 COMMENT 'lower = higher priority',
    title_en     VARCHAR(200) NOT NULL,
    title_fr     VARCHAR(200) NOT NULL,
    body_en      TEXT         DEFAULT NULL,
    body_fr      TEXT         DEFAULT NULL,
    action_url   VARCHAR(500) DEFAULT NULL,
    rec_status   ENUM('open','completed','dismissed') NOT NULL DEFAULT 'open',
    dismissed_by INT UNSIGNED DEFAULT NULL,
    dismissed_at DATETIME     DEFAULT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_listing_status (listing_id, rec_status),
    KEY idx_rule (rule_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 6. Partner → business authorised access ────────────────────────────────
-- partner_business_assignments already exists (growth-partner-migration.sql)
-- Add columns if missing:
ALTER TABLE partner_business_assignments
    ADD COLUMN IF NOT EXISTS `can_view_enquiries`  TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `can_view_analytics`  TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS `notes`               TEXT DEFAULT NULL;

-- ── 7. Business growth metrics daily rollup ────────────────────────────────
-- Lightweight daily cache so dashboards don't hit impressions table raw
CREATE TABLE IF NOT EXISTS listing_metrics_daily (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    listing_id    INT UNSIGNED NOT NULL,
    metric_date   DATE         NOT NULL,
    views         INT UNSIGNED NOT NULL DEFAULT 0,
    phone_clicks  INT UNSIGNED NOT NULL DEFAULT 0,
    wa_clicks     INT UNSIGNED NOT NULL DEFAULT 0,
    web_clicks    INT UNSIGNED NOT NULL DEFAULT 0,
    enquiries     INT UNSIGNED NOT NULL DEFAULT 0,
    bookings_req  INT UNSIGNED NOT NULL DEFAULT 0,
    bookings_conf INT UNSIGNED NOT NULL DEFAULT 0,
    offer_views   INT UNSIGNED NOT NULL DEFAULT 0,
    event_regs    INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_listing_date (listing_id, metric_date),
    KEY idx_date (metric_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 8. Rate-limit table for enquiry spam protection ───────────────────────
CREATE TABLE IF NOT EXISTS enquiry_rate_limit (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_hash      VARCHAR(64)  NOT NULL,
    listing_id   INT UNSIGNED NOT NULL,
    submitted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ip_listing (ip_hash, listing_id),
    KEY idx_submitted (submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Purge old rate-limit rows automatically (keep 24h)
-- (run from cron or after each insert)

-- ── 9. Notification preferences extension ────────────────────────────────
-- Stores per-user Phase 4 notification opt-ins
CREATE TABLE IF NOT EXISTS notification_prefs (
    user_id          INT UNSIGNED PRIMARY KEY,
    notify_enquiry   TINYINT(1) NOT NULL DEFAULT 1,
    notify_status    TINYINT(1) NOT NULL DEFAULT 1,
    notify_onboarding TINYINT(1) NOT NULL DEFAULT 1,
    notify_profile   TINYINT(1) NOT NULL DEFAULT 0,
    notify_promo_exp TINYINT(1) NOT NULL DEFAULT 1,
    notify_report    TINYINT(1) NOT NULL DEFAULT 0,
    updated_at       DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 10. Phase 4 audit log (security-sensitive admin actions) ──────────────
CREATE TABLE IF NOT EXISTS phase4_audit_log (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_id     INT UNSIGNED NOT NULL COMMENT 'user_id who performed the action',
    target_type  VARCHAR(40)  NOT NULL COMMENT 'enquiry, listing, recommendation, partner_access',
    target_id    INT UNSIGNED NOT NULL,
    action       VARCHAR(80)  NOT NULL,
    detail       JSON         DEFAULT NULL,
    ip           VARCHAR(45)  DEFAULT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_actor (actor_id),
    KEY idx_target (target_type, target_id),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Done ──────────────────────────────────────────────────────────────────
-- Tables created / extended:
--   listing_enquiries (ALTER — Phase 4 CRM columns)
--   enquiry_status_log (NEW)
--   marketplace_impressions (ALTER — event_subtype)
--   listing_profile_scores (NEW)
--   growth_recommendations (NEW)
--   partner_business_assignments (ALTER — permission flags)
--   listing_metrics_daily (NEW)
--   enquiry_rate_limit (NEW)
--   notification_prefs (NEW)
--   phase4_audit_log (NEW)
