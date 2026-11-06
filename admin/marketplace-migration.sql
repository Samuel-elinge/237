-- ============================================================
-- 237biz Growth Partner Services Marketplace — DB Migration
-- Run AFTER all growth-partner-p*-migration.sql files
-- Safe to re-run (uses IF NOT EXISTS / ADD COLUMN IF NOT EXISTS)
-- ============================================================
-- Reuses: users, listings (businesses), partner_profiles,
--         partner_business_assignments, business_subscriptions,
--         subscription_plans, monetisation_orders, partner_notifications
-- Adds:   marketplace-specific tables below
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────
-- 1. SERVICE CATEGORIES
--    Top-level groupings (website, email, listing, future)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `mp_service_categories` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `key`            VARCHAR(60)  NOT NULL UNIQUE   COMMENT 'e.g. website_design, business_email, listing_plan',
  `name_en`        VARCHAR(160) NOT NULL,
  `name_fr`        VARCHAR(160) NOT NULL,
  `description_en` TEXT         DEFAULT NULL,
  `description_fr` TEXT         DEFAULT NULL,
  `icon`           VARCHAR(10)  DEFAULT '🏷️',
  `active`         TINYINT(1)   NOT NULL DEFAULT 1,
  `orderable`      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'false until all required config is set',
  `sort_order`     TINYINT      NOT NULL DEFAULT 0,
  `created_by`     INT UNSIGNED DEFAULT NULL COMMENT 'users.id',
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_active_ord` (`active`, `orderable`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 2. SERVICE PRODUCTS
--    Individual packages within a category
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `mp_service_products` (
  `id`                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `category_id`          INT UNSIGNED NOT NULL COMMENT 'mp_service_categories.id',
  `key`                  VARCHAR(80)  NOT NULL UNIQUE COMMENT 'e.g. website_starter, email_1mb, listing_growth',
  `name_en`              VARCHAR(200) NOT NULL,
  `name_fr`              VARCHAR(200) NOT NULL,
  `description_en`       TEXT         DEFAULT NULL,
  `description_fr`       TEXT         DEFAULT NULL,
  `service_type`         ENUM('website_setup','website_renewal','business_email','listing_subscription') NOT NULL,
  `active`               TINYINT(1)   NOT NULL DEFAULT 1,
  `orderable`            TINYINT(1)   NOT NULL DEFAULT 0,
  `form_schema`          JSON         DEFAULT NULL COMMENT 'required fields JSON schema for order form',
  `eligibility_rules`    JSON         DEFAULT NULL COMMENT 'optional rules (e.g. no existing website)',
  `fulfilment_template`  TEXT         DEFAULT NULL COMMENT 'checklist JSON for fulfilment tasks',
  `renewal_policy`       JSON         DEFAULT NULL COMMENT 'billing interval, grace days, reminder_days[]',
  `terms_en`             TEXT         DEFAULT NULL,
  `terms_fr`             TEXT         DEFAULT NULL,
  `listing_plan_slug`    VARCHAR(60)  DEFAULT NULL COMMENT 'subscription_plans.slug — only for listing_subscription type',
  `sort_order`           TINYINT      NOT NULL DEFAULT 0,
  `version`              SMALLINT     NOT NULL DEFAULT 1,
  `created_by`           INT UNSIGNED DEFAULT NULL,
  `created_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_category`     (`category_id`, `active`, `orderable`),
  KEY `idx_service_type` (`service_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 3. SERVICE PRICES
--    Versioned; setup + recurring stored separately
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `mp_service_prices` (
  `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `product_id`       INT UNSIGNED NOT NULL COMMENT 'mp_service_products.id',
  `variant_key`      VARCHAR(80)  NOT NULL COMMENT 'e.g. starter_setup, starter_renewal, email_1mb_monthly',
  `price_type`       ENUM('setup','recurring','one_off') NOT NULL DEFAULT 'one_off',
  `setup_amount`     INT UNSIGNED NOT NULL DEFAULT 0  COMMENT 'XAF minor units (1 XAF = 1 unit)',
  `recurring_amount` INT UNSIGNED NOT NULL DEFAULT 0  COMMENT 'XAF minor units, 0 if no recurrence',
  `currency`         CHAR(3)      NOT NULL DEFAULT 'XAF',
  `billing_interval` ENUM('NONE','MONTH','YEAR') NOT NULL DEFAULT 'NONE',
  `mailbox_limit`    TINYINT UNSIGNED DEFAULT NULL   COMMENT 'max mailboxes for email tiers',
  `effective_from`   DATE         NOT NULL,
  `effective_to`     DATE         DEFAULT NULL COMMENT 'NULL = current/no end',
  `active`           TINYINT(1)   NOT NULL DEFAULT 1,
  `version`          SMALLINT     NOT NULL DEFAULT 1,
  `created_by`       INT UNSIGNED DEFAULT NULL,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_product_variant_from` (`product_id`, `variant_key`, `effective_from`),
  KEY `idx_product_active` (`product_id`, `active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 4. MARKETPLACE ORDERS
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `marketplace_orders` (
  `id`                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `ref`                    VARCHAR(40)  NOT NULL UNIQUE,
  `listing_id`             INT UNSIGNED NOT NULL  COMMENT 'listings.id (the business)',
  `partner_profile_id`     INT UNSIGNED NOT NULL  COMMENT 'partner_profiles.id',
  `created_by_user_id`     INT UNSIGNED NOT NULL  COMMENT 'users.id — the partner user',
  `order_state`            ENUM(
                              'PENDING_OWNER_APPROVAL',
                              'REJECTED',
                              'AWAITING_PAYMENT',
                              'PAYMENT_REVIEW',
                              'PAID',
                              'READY_FOR_FULFILMENT',
                              'IN_FULFILMENT',
                              'BLOCKED',
                              'COMPLETED',
                              'CANCELLED'
                           ) NOT NULL DEFAULT 'PENDING_OWNER_APPROVAL',
  `payment_state`          ENUM(
                              'NOT_STARTED',
                              'INITIATED',
                              'PENDING_PROVIDER',
                              'PAYMENT_REVIEW',
                              'SUCCEEDED',
                              'FAILED',
                              'CANCELLED',
                              'REFUND_PENDING',
                              'REFUNDED',
                              'PARTIALLY_REFUNDED'
                           ) NOT NULL DEFAULT 'NOT_STARTED',
  `currency`               CHAR(3)       NOT NULL DEFAULT 'XAF',
  `due_now_amount`         INT UNSIGNED  NOT NULL DEFAULT 0  COMMENT 'XAF minor units',
  `service_requirements`   JSON          DEFAULT NULL COMMENT 'partner-entered form data (non-secret)',
  `service_start_at`       DATETIME      DEFAULT NULL,
  `service_end_at`         DATETIME      DEFAULT NULL,
  `existing_subscription_id` INT UNSIGNED DEFAULT NULL COMMENT 'business_subscriptions.id if linked',
  `idempotency_key`        VARCHAR(80)   DEFAULT NULL UNIQUE,
  `partner_note`           TEXT          DEFAULT NULL,
  `admin_note`             TEXT          DEFAULT NULL COMMENT 'internal only — never expose to partner/owner',
  `rejection_reason`       TEXT          DEFAULT NULL,
  `cancelled_reason`       TEXT          DEFAULT NULL,
  `cancelled_by`           INT UNSIGNED  DEFAULT NULL COMMENT 'users.id',
  `completed_at`           DATETIME      DEFAULT NULL,
  `created_at`             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_listing`        (`listing_id`),
  KEY `idx_partner`        (`partner_profile_id`),
  KEY `idx_order_state`    (`order_state`),
  KEY `idx_payment_state`  (`payment_state`),
  KEY `idx_created`        (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 5. MARKETPLACE ORDER ITEMS
--    Price snapshot is immutable after submission
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `marketplace_order_items` (
  `id`                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `order_id`                 INT UNSIGNED NOT NULL COMMENT 'marketplace_orders.id',
  `product_id`               INT UNSIGNED NOT NULL COMMENT 'mp_service_products.id',
  `price_id`                 INT UNSIGNED NOT NULL COMMENT 'mp_service_prices.id',
  `product_snapshot`         JSON         NOT NULL COMMENT 'name, description, service_type at order time',
  `price_snapshot`           JSON         NOT NULL COMMENT 'amounts, currency, interval, variant_key at order time',
  `quantity`                 TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `configuration`            JSON         DEFAULT NULL COMMENT 'e.g. {mailbox_count: 3}',
  `setup_amount`             INT UNSIGNED NOT NULL DEFAULT 0  COMMENT 'XAF',
  `recurring_amount`         INT UNSIGNED NOT NULL DEFAULT 0  COMMENT 'XAF',
  `billing_interval`         ENUM('NONE','MONTH','YEAR') NOT NULL DEFAULT 'NONE',
  `amount_due_now`           INT UNSIGNED NOT NULL DEFAULT 0  COMMENT 'XAF — this item\'s share of due_now',
  `existing_subscription_id` INT UNSIGNED DEFAULT NULL COMMENT 'business_subscriptions.id',
  `created_at`               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_order` (`order_id`),
  KEY `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 6. ORDER APPROVALS
--    Owner approve/reject decisions
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `mp_order_approvals` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `order_id`       INT UNSIGNED NOT NULL COMMENT 'marketplace_orders.id',
  `decision`       ENUM('APPROVED','REJECTED','PENDING') NOT NULL DEFAULT 'PENDING',
  `actor_user_id`  INT UNSIGNED DEFAULT NULL COMMENT 'users.id — owner who decided',
  `actor_role`     VARCHAR(40)  DEFAULT NULL,
  `comment`        TEXT         DEFAULT NULL,
  `requested_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `decided_at`     DATETIME     DEFAULT NULL,
  `expires_at`     DATETIME     DEFAULT NULL COMMENT 'admin-configurable approval deadline',
  KEY `idx_order` (`order_id`),
  KEY `idx_decision` (`order_id`, `decision`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 7. MARKETPLACE PAYMENT ATTEMPTS
--    New attempt per retry; never overwrite
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `mp_payment_attempts` (
  `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `order_id`          INT UNSIGNED NOT NULL COMMENT 'marketplace_orders.id',
  `attempt_number`    TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `channel`           ENUM('mtn_momo','orange_money','offline') NOT NULL,
  `amount`            INT UNSIGNED  NOT NULL COMMENT 'XAF',
  `currency`          CHAR(3)       NOT NULL DEFAULT 'XAF',
  `idempotency_key`   VARCHAR(80)   NOT NULL UNIQUE,
  `provider_ref`      VARCHAR(200)  DEFAULT NULL COMMENT 'provider transaction ID',
  `payer_user_id`     INT UNSIGNED  DEFAULT NULL COMMENT 'users.id',
  `payer_ref`         VARCHAR(200)  DEFAULT NULL COMMENT 'user-submitted reference for offline',
  `proof_file`        VARCHAR(255)  DEFAULT NULL COMMENT 'uploaded screenshot path',
  `status`            ENUM(
                         'NOT_STARTED','INITIATED','PENDING_PROVIDER',
                         'PAYMENT_REVIEW','SUCCEEDED','FAILED',
                         'CANCELLED','REFUND_PENDING','REFUNDED','PARTIALLY_REFUNDED'
                      ) NOT NULL DEFAULT 'NOT_STARTED',
  `verified_by`       INT UNSIGNED  DEFAULT NULL COMMENT 'admin users.id',
  `verified_at`       DATETIME      DEFAULT NULL,
  `verifier_note`     TEXT          DEFAULT NULL,
  `provider_metadata` JSON          DEFAULT NULL COMMENT 'safe provider response — no credentials',
  `created_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_order`     (`order_id`),
  KEY `idx_status`    (`status`),
  KEY `idx_provider`  (`provider_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 8. FULFILMENT TASKS
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `mp_fulfilment_tasks` (
  `id`                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `order_id`               INT UNSIGNED NOT NULL COMMENT 'marketplace_orders.id',
  `order_item_id`          INT UNSIGNED DEFAULT NULL COMMENT 'marketplace_order_items.id',
  `assignee_user_id`       INT UNSIGNED DEFAULT NULL COMMENT 'users.id — fulfilment operator',
  `status`                 ENUM('UNASSIGNED','ASSIGNED','IN_PROGRESS','WAITING_ON_CUSTOMER','COMPLETED','CANCELLED','BLOCKED') NOT NULL DEFAULT 'UNASSIGNED',
  `due_at`                 DATETIME      DEFAULT NULL,
  `customer_status_update` TEXT          DEFAULT NULL COMMENT 'visible to owner + partner',
  `internal_notes`         TEXT          DEFAULT NULL COMMENT 'admin/operator only — never expose',
  `checklist`              JSON          DEFAULT NULL COMMENT 'steps from fulfilment_template',
  `blocked_reason`         TEXT          DEFAULT NULL,
  `cancelled_reason`       TEXT          DEFAULT NULL,
  `completed_at`           DATETIME      DEFAULT NULL,
  `created_at`             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_order`         (`order_id`),
  KEY `idx_assignee`      (`assignee_user_id`),
  KEY `idx_status`        (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 9. SERVICE INSTANCES
--    Activated services linked to orders and existing subscriptions
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `mp_service_instances` (
  `id`                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `listing_id`               INT UNSIGNED NOT NULL COMMENT 'listings.id',
  `order_id`                 INT UNSIGNED NOT NULL COMMENT 'marketplace_orders.id',
  `order_item_id`            INT UNSIGNED DEFAULT NULL,
  `product_id`               INT UNSIGNED NOT NULL COMMENT 'mp_service_products.id',
  `service_type`             ENUM('website_setup','website_renewal','business_email','listing_subscription') NOT NULL,
  `status`                   ENUM('ACTIVE','EXPIRED','SUSPENDED','CANCELLED') NOT NULL DEFAULT 'ACTIVE',
  `existing_subscription_id` INT UNSIGNED DEFAULT NULL COMMENT 'business_subscriptions.id',
  `activated_at`             DATETIME      DEFAULT NULL,
  `expires_at`               DATETIME      DEFAULT NULL,
  `next_renewal_at`          DATETIME      DEFAULT NULL,
  `service_metadata`         JSON          DEFAULT NULL COMMENT 'non-secret: domain, mailbox_count, plan_slug, provider_account_ref',
  `created_at`               DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`               DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_listing`           (`listing_id`),
  KEY `idx_order`             (`order_id`),
  KEY `idx_status`            (`status`),
  KEY `idx_renewal`           (`next_renewal_at`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 10. COMMISSION POLICIES
--     Must be explicitly activated before any commission accrues
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `mp_commission_policies` (
  `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`              VARCHAR(160) NOT NULL,
  `description`       TEXT         DEFAULT NULL,
  `active`            TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'explicit activation required',
  `commission_type`   ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage',
  `rate`              DECIMAL(6,3) NOT NULL DEFAULT 0.000 COMMENT 'percentage (e.g. 10.000) or XAF fixed',
  `currency`          CHAR(3)      NOT NULL DEFAULT 'XAF',
  `basis`             ENUM('setup_amount','recurring_amount','total_due_now','net_amount') NOT NULL DEFAULT 'total_due_now',
  `eligible_service_types` JSON  DEFAULT NULL COMMENT 'null = all; or [\"website_setup\"]',
  `eligible_product_ids`   JSON  DEFAULT NULL COMMENT 'null = all in eligible_service_types',
  `hold_days`         TINYINT UNSIGNED NOT NULL DEFAULT 30 COMMENT 'days after payment before PAYABLE',
  `effective_from`    DATE         NOT NULL,
  `effective_to`      DATE         DEFAULT NULL,
  `created_by`        INT UNSIGNED NOT NULL,
  `activated_by`      INT UNSIGNED DEFAULT NULL,
  `activated_at`      DATETIME     DEFAULT NULL,
  `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_active`    (`active`, `effective_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 11. COMMISSION LEDGER ENTRIES
--     Zero entries until a policy is activated
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `mp_commission_ledger` (
  `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `partner_profile_id` INT UNSIGNED NOT NULL COMMENT 'partner_profiles.id',
  `order_id`         INT UNSIGNED NOT NULL COMMENT 'marketplace_orders.id',
  `payment_attempt_id` INT UNSIGNED DEFAULT NULL COMMENT 'mp_payment_attempts.id',
  `policy_id`        INT UNSIGNED NOT NULL COMMENT 'mp_commission_policies.id',
  `policy_snapshot`  JSON         NOT NULL COMMENT 'policy fields at calculation time',
  `basis_amount`     INT UNSIGNED NOT NULL COMMENT 'XAF — the qualifying amount used',
  `commission_amount` INT UNSIGNED NOT NULL COMMENT 'XAF',
  `currency`         CHAR(3)       NOT NULL DEFAULT 'XAF',
  `status`           ENUM('PENDING','APPROVED','PAYABLE','PAID','REVERSED','ON_HOLD') NOT NULL DEFAULT 'PENDING',
  `reversal_id`      INT UNSIGNED  DEFAULT NULL COMMENT 'self-ref to original entry',
  `reversal_reason`  TEXT          DEFAULT NULL,
  `payout_ref`       VARCHAR(200)  DEFAULT NULL,
  `approved_by`      INT UNSIGNED  DEFAULT NULL,
  `approved_at`      DATETIME      DEFAULT NULL,
  `paid_at`          DATETIME      DEFAULT NULL,
  `hold_until`       DATETIME      DEFAULT NULL,
  `admin_note`       TEXT          DEFAULT NULL,
  `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_partner_order_policy` (`partner_profile_id`, `order_id`, `policy_id`) COMMENT 'prevent duplicate accrual',
  KEY `idx_partner`  (`partner_profile_id`, `status`),
  KEY `idx_order`    (`order_id`),
  KEY `idx_status`   (`status`, `hold_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 12. MARKETPLACE AUDIT EVENTS (append-only)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `mp_audit_events` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `entity_type`    VARCHAR(60)  NOT NULL COMMENT 'marketplace_orders, mp_service_products, etc.',
  `entity_id`      INT UNSIGNED NOT NULL,
  `actor_user_id`  INT UNSIGNED DEFAULT NULL,
  `actor_role`     VARCHAR(40)  DEFAULT NULL,
  `action`         VARCHAR(100) NOT NULL COMMENT 'e.g. order.state_changed, payment.verified',
  `before_state`   JSON         DEFAULT NULL,
  `after_state`    JSON         DEFAULT NULL,
  `ip_address`     VARCHAR(45)  DEFAULT NULL,
  `request_id`     VARCHAR(80)  DEFAULT NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_entity`  (`entity_type`, `entity_id`),
  KEY `idx_actor`   (`actor_user_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 13. MARKETPLACE NOTIFICATION LOG
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `mp_notification_log` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `order_id`        INT UNSIGNED  DEFAULT NULL COMMENT 'marketplace_orders.id',
  `recipient_user_id` INT UNSIGNED DEFAULT NULL COMMENT 'users.id',
  `channel`         ENUM('email','in_app') NOT NULL DEFAULT 'email',
  `template_key`    VARCHAR(80)  NOT NULL COMMENT 'e.g. order.owner_approval_requested',
  `locale`          CHAR(2)      NOT NULL DEFAULT 'en',
  `delivery_status` ENUM('queued','sent','delivered','failed','suppressed') NOT NULL DEFAULT 'queued',
  `provider_ref`    VARCHAR(200) DEFAULT NULL,
  `error`           TEXT         DEFAULT NULL,
  `retry_count`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_order`    (`order_id`),
  KEY `idx_recipient`(`recipient_user_id`),
  KEY `idx_status`   (`delivery_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- SEED: Launch catalogue (spec §3)
-- All products set orderable=0 until admin completes §14 config
-- ─────────────────────────────────────────────────────────────

-- Categories
INSERT IGNORE INTO `mp_service_categories`
  (key, name_en, name_fr, description_en, description_fr, icon, active, orderable, sort_order)
VALUES
  ('website_design', 'Website Design',  'Création de Site Web',
   'Professional website packages for your business.',
   'Packages de site web professionnels pour votre entreprise.',
   '🌐', 1, 0, 1),
  ('business_email', 'Business Email',  'Email Professionnel',
   'Professional email addresses for your team.',
   'Adresses email professionnelles pour votre équipe.',
   '📧', 1, 0, 2),
  ('listing_plan',   'Listing Plan',    'Plan d\'Annonce',
   'Upgrade your 237biz listing visibility.',
   'Améliorez la visibilité de votre annonce 237biz.',
   '📋', 1, 0, 3);

-- Website products
INSERT IGNORE INTO `mp_service_products`
  (category_id, key, name_en, name_fr, service_type, active, orderable, sort_order)
SELECT c.id,
       v.key, v.name_en, v.name_fr, 'website_setup', 1, 0, v.sort_order
FROM `mp_service_categories` c
JOIN (SELECT 'website_starter'      AS `key`, 'Website Starter'      AS name_en, 'Site Web Starter'       AS name_fr, 1 AS sort_order UNION ALL
      SELECT 'website_business',          'Website Business',          'Site Web Business',                              2 UNION ALL
      SELECT 'website_professional',      'Website Professional',      'Site Web Professionnel',                         3) v ON c.key = 'website_design';

-- Website prices (setup + annual renewal per package — spec §3)
-- setup_amount in XAF, recurring_amount = annual hosting renewal
INSERT IGNORE INTO `mp_service_prices`
  (product_id, variant_key, price_type, setup_amount, recurring_amount, currency, billing_interval, effective_from, active)
SELECT p.id, CONCAT(p.key, '_setup'), 'setup', v.setup_xaf, 0, 'XAF', 'NONE', CURDATE(), 1
FROM `mp_service_products` p
JOIN (SELECT 'website_starter'       AS k, 100000 AS setup_xaf UNION ALL
      SELECT 'website_business',          200000             UNION ALL
      SELECT 'website_professional',      350000) v ON p.key = v.k
UNION ALL
SELECT p.id, CONCAT(p.key, '_renewal'), 'recurring', 0, v.renewal_xaf, 'XAF', 'YEAR', CURDATE(), 1
FROM `mp_service_products` p
JOIN (SELECT 'website_starter'       AS k, 100000 AS renewal_xaf UNION ALL
      SELECT 'website_business',          100000              UNION ALL
      SELECT 'website_professional',      135000) v ON p.key = v.k;

-- Email products
INSERT IGNORE INTO `mp_service_products`
  (category_id, key, name_en, name_fr, service_type, active, orderable, sort_order)
SELECT c.id,
       v.key, v.name_en, v.name_fr, 'business_email', 1, 0, v.sort_order
FROM `mp_service_categories` c
JOIN (SELECT 'email_1mb'   AS `key`, 'Business Email — 1 Mailbox'          AS name_en, 'Email Pro — 1 boîte'          AS name_fr, 1 AS sort_order UNION ALL
      SELECT 'email_5mb',            'Business Email — Up to 5 Mailboxes',            'Email Pro — jusqu\'à 5 boîtes', 2            UNION ALL
      SELECT 'email_10mb',           'Business Email — Up to 10 Mailboxes',           'Email Pro — jusqu\'à 10 boîtes', 3) v ON c.key = 'business_email';

-- Email prices (monthly recurring, no setup per spec)
INSERT IGNORE INTO `mp_service_prices`
  (product_id, variant_key, price_type, setup_amount, recurring_amount, currency, billing_interval, mailbox_limit, effective_from, active)
SELECT p.id, CONCAT(p.key, '_monthly'), 'recurring', 0, v.price_xaf, 'XAF', 'MONTH', v.mb_limit, CURDATE(), 1
FROM `mp_service_products` p
JOIN (SELECT 'email_1mb'  AS k,  2500 AS price_xaf,  1 AS mb_limit UNION ALL
      SELECT 'email_5mb',         5000,                5             UNION ALL
      SELECT 'email_10mb',        9000,               10) v ON p.key = v.k;

-- Listing plan products (linked to existing subscription_plans)
INSERT IGNORE INTO `mp_service_products`
  (category_id, key, name_en, name_fr, service_type, active, orderable, listing_plan_slug, sort_order)
SELECT c.id,
       v.key, v.name_en, v.name_fr, 'listing_subscription', 1, 0, v.plan_slug, v.sort_order
FROM `mp_service_categories` c
JOIN (SELECT 'listing_growth'   AS `key`,
             'Business Growth Plan'   AS name_en,
             'Plan Croissance Business' AS name_fr,
             'growth'           AS plan_slug,
             1                  AS sort_order
      UNION ALL
      SELECT 'listing_premium',
             'Premium Plan',
             'Plan Premium',
             'premium',
             2) v ON c.key = 'listing_plan';

-- Listing prices (annual, per spec §3)
INSERT IGNORE INTO `mp_service_prices`
  (product_id, variant_key, price_type, setup_amount, recurring_amount, currency, billing_interval, effective_from, active)
SELECT p.id, CONCAT(p.key, '_annual'), 'recurring', 0, v.price_xaf, 'XAF', 'YEAR', CURDATE(), 1
FROM `mp_service_products` p
JOIN (SELECT 'listing_growth'  AS k, 40000 AS price_xaf UNION ALL
      SELECT 'listing_premium',       80000) v ON p.key = v.k;

-- ─────────────────────────────────────────────────────────────
-- site_settings keys used by the marketplace
-- (insert with no value — admin must configure before enabling)
-- ─────────────────────────────────────────────────────────────
INSERT IGNORE INTO `site_settings` (`key`, `value`) VALUES
  ('mp_website_domain_charge',           ''),
  ('mp_website_hosting_included_days',   ''),
  ('mp_website_grace_days',              ''),
  ('mp_email_grace_days',                ''),
  ('mp_listing_plan_id_growth',          ''),
  ('mp_listing_plan_id_premium',         ''),
  ('mp_commission_policy_active',        '0'),
  ('mp_payment_instruction_en',          'Please send payment to our MTN MoMo number and upload your screenshot below.'),
  ('mp_payment_instruction_fr',          'Veuillez envoyer le paiement à notre numéro MTN MoMo et téléverser votre capture d\'écran ci-dessous.'),
  ('mp_approval_expiry_days',            '7'),
  ('mp_renewal_reminder_days',           '14');

SET FOREIGN_KEY_CHECKS = 1;
