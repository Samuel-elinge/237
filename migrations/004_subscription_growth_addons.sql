-- =====================================================================
-- Migration 004: Subscription Growth plan, add-ons & entitlements
-- Run once on the production database via phpMyAdmin or CLI.
-- Safe to re-run: uses IF NOT EXISTS / column existence checks.
-- =====================================================================

-- ── 1. Extend subscription_plans ────────────────────────────────────

ALTER TABLE subscription_plans
    ADD COLUMN IF NOT EXISTS max_products      INT          NOT NULL DEFAULT 0     COMMENT 'Max published products; 0 = unlimited',
    ADD COLUMN IF NOT EXISTS premium_star      TINYINT(1)   NOT NULL DEFAULT 0     COMMENT '1 = show golden Premium star badge',
    ADD COLUMN IF NOT EXISTS search_priority   INT          NOT NULL DEFAULT 0     COMMENT 'Higher = ranked first in search; 0 = no boost',
    ADD COLUMN IF NOT EXISTS addon_eligible    TINYINT(1)   NOT NULL DEFAULT 0     COMMENT '1 = plan may purchase add-ons';

-- ── 2. Admin audit log for plan price / limit changes ────────────────

CREATE TABLE IF NOT EXISTS plan_audit_log (
    id          INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
    plan_id     INT UNSIGNED    NOT NULL,
    admin_id    INT UNSIGNED    NOT NULL,
    field_name  VARCHAR(64)     NOT NULL,
    old_value   TEXT,
    new_value   TEXT,
    changed_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_plan  (plan_id),
    INDEX idx_admin (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 3. Add-on product catalogue (admin-managed) ──────────────────────

CREATE TABLE IF NOT EXISTS plan_addons (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name_en         VARCHAR(120)    NOT NULL,
    name_fr         VARCHAR(120)    NOT NULL,
    description_en  TEXT,
    description_fr  TEXT,
    addon_type      ENUM('offers','events','products') NOT NULL,
    quantity        INT UNSIGNED    NOT NULL DEFAULT 1  COMMENT 'Capacity granted per purchase',
    price_xaf       INT UNSIGNED    NOT NULL DEFAULT 0,
    -- Eligibility: NULL = available on all plans; JSON array of plan slugs otherwise
    eligible_plan_slugs TEXT         DEFAULT NULL,
    -- Expiry policy
    expiry_policy   ENUM('subscription_end','fixed_days','never') NOT NULL DEFAULT 'subscription_end',
    expiry_days     INT UNSIGNED    DEFAULT NULL          COMMENT 'Used when expiry_policy=fixed_days',
    max_per_listing INT UNSIGNED    DEFAULT NULL          COMMENT 'Purchase cap per listing; NULL = unlimited',
    active          TINYINT(1)      NOT NULL DEFAULT 1,
    sort_order      INT             NOT NULL DEFAULT 0,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 4. Add-on orders (one row per purchase, reuses payment ledger) ───

CREATE TABLE IF NOT EXISTS addon_orders (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ref             VARCHAR(32)     NOT NULL UNIQUE,
    listing_id      INT UNSIGNED    NOT NULL,
    user_id         INT UNSIGNED    NOT NULL,
    addon_id        INT UNSIGNED    NOT NULL,
    quantity        INT UNSIGNED    NOT NULL DEFAULT 1   COMMENT 'Number of add-on units bought',
    total_xaf       INT UNSIGNED    NOT NULL,
    payment_method  VARCHAR(50)     DEFAULT NULL,
    payment_status  ENUM('pending_payment','paid','refunded','cancelled') NOT NULL DEFAULT 'pending_payment',
    proof           VARCHAR(255)    DEFAULT NULL         COMMENT 'Payment proof screenshot path',
    -- Entitlement dates (filled on payment confirmation)
    activated_at    DATETIME        DEFAULT NULL,
    expires_at      DATETIME        DEFAULT NULL,
    notes           TEXT,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_listing (listing_id),
    INDEX idx_user    (user_id),
    INDEX idx_addon   (addon_id),
    INDEX idx_status  (payment_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 5. Active entitlement cache (fast lookup for enforcement) ────────

CREATE TABLE IF NOT EXISTS addon_entitlements (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
    listing_id      INT UNSIGNED    NOT NULL,
    addon_order_id  INT UNSIGNED    NOT NULL UNIQUE      COMMENT 'One entitlement row per paid order',
    addon_type      ENUM('offers','events','products') NOT NULL,
    capacity        INT UNSIGNED    NOT NULL,
    activated_at    DATETIME        NOT NULL,
    expires_at      DATETIME        DEFAULT NULL,
    INDEX idx_listing_type (listing_id, addon_type),
    INDEX idx_expires      (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 6. Seed the Business Growth plan (idempotent) ───────────────────

INSERT INTO subscription_plans
    (slug, name_en, name_fr, description_en, description_fr,
     price_xaf, billing_interval, duration_days,
     max_offers, max_events, max_products, analytics_level,
     featured_eligible, promotions_eligible, verified_badge, priority_support,
     premium_star, search_priority, addon_eligible,
     is_free, active, sort_order, highlight_color, badge_label_en, badge_label_fr)
VALUES
    ('growth',
     'Business Growth', 'Croissance Business',
     'All standard paid features. Grow your presence with 1 active offer, 1 active event and 5 published products.',
     'Toutes les fonctionnalités payantes standard. Développez votre présence avec 1 offre active, 1 événement actif et 5 produits publiés.',
     40000, 'annual', 365,
     1, 1, 5, 'enhanced',
     1, 1, 0, 0,
     0, 1, 1,
     0, 1, 10, '#3b82f6', 'Growth', 'Croissance')
ON DUPLICATE KEY UPDATE
    price_xaf        = VALUES(price_xaf),
    max_offers       = VALUES(max_offers),
    max_events       = VALUES(max_events),
    max_products     = VALUES(max_products),
    search_priority  = VALUES(search_priority),
    addon_eligible   = VALUES(addon_eligible),
    active           = VALUES(active);

-- ── 7. Update existing Premium plan to add new columns ───────────────
-- (Only applies if a plan with slug 'premium' already exists.)

UPDATE subscription_plans SET
    max_products     = 10,
    premium_star     = 1,
    search_priority  = 2,
    addon_eligible   = 1
WHERE slug = 'premium';

-- Also ensure Free plan has search_priority = 0 and no star
UPDATE subscription_plans SET
    premium_star     = 0,
    search_priority  = 0,
    addon_eligible   = 0,
    max_products     = CASE WHEN max_products = 0 THEN 0 ELSE max_products END
WHERE is_free = 1;
