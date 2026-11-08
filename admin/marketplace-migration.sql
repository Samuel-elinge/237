-- ============================================================
-- 237biz Growth Partner Services Marketplace — DB Migration
-- Run AFTER all growth-partner-p*-migration.sql files
-- Safe to re-run (uses IF NOT EXISTS / ADD COLUMN IF NOT EXISTS)
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────
-- 1. SERVICE CATEGORIES
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `mp_service_categories` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `key`            VARCHAR(60)  NOT NULL UNIQUE,
  `name_en`        VARCHAR(160) NOT NULL,
  `name_fr`        VARCHAR(160) NOT NULL,
  `description_en` TEXT         DEFAULT NULL,
  `description_fr` TEXT         DEFAULT NULL,
  `icon`           VARCHAR(10)  DEFAULT '🏷️',
  `active`         TINYINT(1)   NOT NULL DEFAULT 1,
  `orderable`      TINYINT(1)   NOT NULL DEFAULT 0,
  `sort_order`     TINYINT      NOT NULL DEFAULT 0,
  `created_by`     INT UNSIGNED DEFAULT NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_active_ord` (`active`, `orderable`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 2. SERVICE PRODUCTS
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `mp_service_products` (
  `id`                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `category_id`          INT UNSIGNED NOT NULL,
  `key`                  VARCHAR(80)  NOT NULL UNIQUE,
  `name_en`              VARCHAR(200) NOT NULL,
  `name_fr`              VARCHAR(200) NOT NULL,
  `description_en`       TEXT         DEFAULT NULL,
  `description_fr`       TEXT         DEFAULT NULL,
  `service_type`         ENUM('website_setup','website_renewal','business_email','listing_subscription') NOT NULL,
  `active`               TINYINT(1)   NOT NULL DEFAULT 1,
  `orderable`            TINYINT(1)   NOT NULL DEFAULT 0,
  `form_schema`          JSON         DEFAULT NULL,
  `eligibility_rules`    JSON         DEFAULT NULL,
  `fulfilment_template`  TEXT         DEFAULT NULL,
  `renewal_policy`       JSON         DEFAULT NULL,
  `terms_en`             TEXT         DEFAULT NULL,
  `terms_fr`             TEXT         DEFAULT NULL,
  `listing_plan_slug`    VARCHAR(60)  DEFAULT NULL,
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
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `mp_service_prices` (
  `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `product_id`       INT UNSIGNED NOT NULL,
  `variant_key`      VARCHAR(80)  NOT NULL,
  `price_type`       ENUM('setup','recurring','one_off') NOT NULL DEFAULT 'one_off',
  `setup_amount`     INT UNSIGNED NOT NULL DEFAULT 0,
  `recurring_amount` INT UNSIGNED NOT NULL DEFAULT 0,
  `currency`         CHAR(3)      NOT NULL DEFAULT 'XAF',
  `billing_interval` ENUM('NONE','MONTH','YEAR') NOT NULL DEFAULT 'NONE',
  `mailbox_limit`    TINYINT UNSIGNED DEFAULT NULL,
  `effective_from`   DATE         NOT NULL,
  `effective_to`     DATE         DEFAULT NULL,
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
  `listing_id`             INT UNSIGNED NOT NULL,
  `partner_profile_id`     INT UNSIGNED NOT NULL,
  `created_by_user_id`     INT UNSIGNED NOT NULL,
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
  `due_now_amount`         INT UNSIGNED  NOT NULL DEFAULT 0,
  `service_requirements`   JSON          DEFAULT NULL,
  `service_start_at`       DATETIME      DEFAULT NULL,
  `service_end_at`         DATETIME      DEFAULT NULL,
  `existing_subscription_id` INT UNSIGNED DEFAULT NULL,
  `idempotency_key`        VARCHAR(80)   DEFAULT NULL UNIQUE,
  `partner_note`           TEXT          DEFAULT NULL,
  `admin_note`             TEXT          DEFAULT NULL,
  `rejection_reason`       TEXT          DEFAULT NULL,
  `cancelled_reason`       TEXT          DEFAULT NULL,
  `cancelled_by`           INT UNSIGNED  DEFAULT NULL,
  `payment_confirmed_at`   DATETIME      DEFAULT NULL,
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
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `marketplace_order_items` (
  `id`                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `order_id`                 INT UNSIGNED NOT NULL,
  `product_id`               INT UNSIGNED NOT NULL,
  `price_id`                 INT UNSIGNED NOT NULL,
  `product_snapshot`         JSON         NOT NULL,
  `price_snapshot`           JSON         NOT NULL,
  `quantity`                 TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `configuration`            JSON         DEFAULT NULL,
  `setup_amount`             INT UNSIGNED NOT NULL DEFAULT 0,
  `recurring_amount`         INT UNSIGNED NOT NULL DEFAULT 0,
  `billing_interval`         ENUM('NONE','MONTH','YEAR') NOT NULL DEFAULT 'NONE',
  `amount_due_now`           INT UNSIGNED NOT NULL DEFAULT 0,
  `existing_subscription_id` INT UNSIGNED DEFAULT NULL,
  `created_at`               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_order` (`order_id`),
  KEY `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 6. ORDER APPROVALS
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `mp_order_approvals` (
  `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `order_id`          INT UNSIGNED NOT NULL,
  `decision`          ENUM('APPROVED','REJECTED','PENDING') NOT NULL DEFAULT 'PENDING',
  `decided_by_user_id` INT UNSIGNED DEFAULT NULL,
  `reviewer_note`     TEXT         DEFAULT NULL,
  `requested_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `decided_at`        DATETIME     DEFAULT NULL,
  `expires_at`        DATETIME     DEFAULT NULL,
  KEY `idx_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 7. MARKETPLACE PAYMENT ATTEMPTS
--    Columns match marketplace-pay.php and marketplace-orders.php
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `mp_payment_attempts` (
  `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `order_id`            INT UNSIGNED NOT NULL,
  `payment_state`       ENUM('NOT_STARTED','INITIATED','PENDING_PROVIDER',
                             'PAYMENT_REVIEW','SUCCEEDED','FAILED',
                             'CANCELLED','REFUND_PENDING','REFUNDED','PARTIALLY_REFUNDED')
                        NOT NULL DEFAULT 'NOT_STARTED',
  `provider`            ENUM('MTN_MOMO','ORANGE_MONEY','OTHER') NOT NULL DEFAULT 'OTHER',
  `amount`              INT UNSIGNED NOT NULL,
  `currency`            CHAR(3)      NOT NULL DEFAULT 'XAF',
  `provider_ref`        VARCHAR(200) DEFAULT NULL,
  `proof_path`          VARCHAR(255) DEFAULT NULL,
  `submitted_by_user_id` INT UNSIGNED DEFAULT NULL,
  `partner_note`        TEXT         DEFAULT NULL,
  `reviewer_note`       TEXT         DEFAULT NULL,
  `reviewed_by_user_id` INT UNSIGNED DEFAULT NULL,
  `reviewed_at`         DATETIME     DEFAULT NULL,
  `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_order`       (`order_id`),
  KEY `idx_state`       (`payment_state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 8. FULFILMENT TASKS
--    Columns match marketplace-fulfilment.php
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `mp_fulfilment_tasks` (
  `id`                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `order_id`               INT UNSIGNED NOT NULL,
  `title`                  VARCHAR(200) NOT NULL,
  `description`            TEXT         DEFAULT NULL,
  `fulfilment_state`       ENUM('UNASSIGNED','ASSIGNED','IN_PROGRESS','WAITING_ON_CUSTOMER',
                                'COMPLETED','BLOCKED','CANCELLED')
                           NOT NULL DEFAULT 'UNASSIGNED',
  `assignee_id`            INT UNSIGNED DEFAULT NULL,
  `due_date`               DATE         DEFAULT NULL,
  `notes`                  TEXT         DEFAULT NULL,
  `created_by_user_id`     INT UNSIGNED DEFAULT NULL,
  `created_at`             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_order`          (`order_id`),
  KEY `idx_assignee`       (`assignee_id`),
  KEY `idx_state`          (`fulfilment_state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 9. SERVICE INSTANCES
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `mp_service_instances` (
  `id`                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `listing_id`               INT UNSIGNED NOT NULL,
  `order_id`                 INT UNSIGNED NOT NULL,
  `order_item_id`            INT UNSIGNED DEFAULT NULL,
  `product_id`               INT UNSIGNED NOT NULL,
  `service_type`             ENUM('website_setup','website_renewal','business_email','listing_subscription') NOT NULL,
  `status`                   ENUM('ACTIVE','EXPIRED','SUSPENDED','CANCELLED') NOT NULL DEFAULT 'ACTIVE',
  `existing_subscription_id` INT UNSIGNED DEFAULT NULL,
  `activated_at`             DATETIME     DEFAULT NULL,
  `expires_at`               DATETIME     DEFAULT NULL,
  `next_renewal_at`          DATETIME     DEFAULT NULL,
  `service_metadata`         JSON         DEFAULT NULL,
  `created_at`               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_listing`           (`listing_id`),
  KEY `idx_order`             (`order_id`),
  KEY `idx_status`            (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 10. COMMISSION POLICIES
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `mp_commission_policies` (
  `id`                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`                   VARCHAR(160) NOT NULL,
  `description`            TEXT         DEFAULT NULL,
  `active`                 TINYINT(1)   NOT NULL DEFAULT 0,
  `commission_type`        ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage',
  `rate`                   DECIMAL(6,3) NOT NULL DEFAULT 0.000,
  `currency`               CHAR(3)      NOT NULL DEFAULT 'XAF',
  `basis`                  ENUM('setup_amount','recurring_amount','total_due_now','net_amount') NOT NULL DEFAULT 'total_due_now',
  `eligible_service_types` JSON         DEFAULT NULL,
  `eligible_product_ids`   JSON         DEFAULT NULL,
  `hold_days`              TINYINT UNSIGNED NOT NULL DEFAULT 30,
  `effective_from`         DATE         NOT NULL,
  `effective_to`           DATE         DEFAULT NULL,
  `created_by`             INT UNSIGNED NOT NULL DEFAULT 1,
  `activated_by`           INT UNSIGNED DEFAULT NULL,
  `activated_at`           DATETIME     DEFAULT NULL,
  `created_at`             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_active`         (`active`, `effective_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 11. COMMISSION LEDGER
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `mp_commission_ledger` (
  `id`                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `partner_profile_id`   INT UNSIGNED NOT NULL,
  `order_id`             INT UNSIGNED NOT NULL,
  `payment_attempt_id`   INT UNSIGNED DEFAULT NULL,
  `policy_id`            INT UNSIGNED NOT NULL,
  `policy_snapshot`      JSON         NOT NULL,
  `basis_amount`         INT UNSIGNED NOT NULL,
  `commission_amount`    INT UNSIGNED NOT NULL,
  `currency`             CHAR(3)      NOT NULL DEFAULT 'XAF',
  `status`               ENUM('PENDING','APPROVED','PAYABLE','PAID','REVERSED','ON_HOLD') NOT NULL DEFAULT 'PENDING',
  `reversal_id`          INT UNSIGNED DEFAULT NULL,
  `reversal_reason`      TEXT         DEFAULT NULL,
  `payout_ref`           VARCHAR(200) DEFAULT NULL,
  `approved_by`          INT UNSIGNED DEFAULT NULL,
  `approved_at`          DATETIME     DEFAULT NULL,
  `paid_at`              DATETIME     DEFAULT NULL,
  `hold_until`           DATETIME     DEFAULT NULL,
  `admin_note`           TEXT         DEFAULT NULL,
  `created_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_partner_order_policy` (`partner_profile_id`, `order_id`, `policy_id`),
  KEY `idx_partner`      (`partner_profile_id`, `status`),
  KEY `idx_order`        (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 12. AUDIT EVENTS (append-only)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `mp_audit_events` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `entity_type`    VARCHAR(60)  NOT NULL,
  `entity_id`      INT UNSIGNED NOT NULL,
  `actor_user_id`  INT UNSIGNED DEFAULT NULL,
  `actor_role`     VARCHAR(40)  DEFAULT NULL,
  `action`         VARCHAR(100) NOT NULL,
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
-- 13. NOTIFICATION LOG
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `mp_notification_log` (
  `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `order_id`         INT UNSIGNED DEFAULT NULL,
  `recipient_user_id` INT UNSIGNED DEFAULT NULL,
  `channel`          ENUM('email','in_app') NOT NULL DEFAULT 'email',
  `template_key`     VARCHAR(80)  NOT NULL,
  `locale`           CHAR(2)      NOT NULL DEFAULT 'en',
  `delivery_status`  ENUM('queued','sent','delivered','failed','suppressed') NOT NULL DEFAULT 'queued',
  `provider_ref`     VARCHAR(200) DEFAULT NULL,
  `error`            TEXT         DEFAULT NULL,
  `retry_count`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_order`    (`order_id`),
  KEY `idx_recipient`(`recipient_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- SEED: Launch catalogue (spec §3)
-- All products set orderable=0 until admin completes §14 config
-- ─────────────────────────────────────────────────────────────

-- Categories (backtick `key` to avoid reserved-word error in MariaDB)
INSERT IGNORE INTO `mp_service_categories`
  (`key`, `name_en`, `name_fr`, `description_en`, `description_fr`, `icon`, `active`, `orderable`, `sort_order`)
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
  (`category_id`, `key`, `name_en`, `name_fr`, `service_type`, `active`, `orderable`, `sort_order`)
SELECT c.id,
       v.pkey, v.name_en, v.name_fr, 'website_setup', 1, 0, v.sort_order
FROM `mp_service_categories` c
JOIN (SELECT 'website_starter'      AS pkey, 'Website Starter'      AS name_en, 'Site Web Starter'      AS name_fr, 1 AS sort_order UNION ALL
      SELECT 'website_business',           'Website Business',           'Site Web Business',                            2            UNION ALL
      SELECT 'website_professional',       'Website Professional',       'Site Web Professionnel',                       3) v ON c.`key` = 'website_design';

-- Website prices
INSERT IGNORE INTO `mp_service_prices`
  (`product_id`, `variant_key`, `price_type`, `setup_amount`, `recurring_amount`, `currency`, `billing_interval`, `effective_from`, `active`)
SELECT p.id, CONCAT(p.`key`, '_setup'), 'setup', v.setup_xaf, 0, 'XAF', 'NONE', CURDATE(), 1
FROM `mp_service_products` p
JOIN (SELECT 'website_starter'      AS k, 100000 AS setup_xaf UNION ALL
      SELECT 'website_business',          200000              UNION ALL
      SELECT 'website_professional',      350000) v ON p.`key` = v.k
UNION ALL
SELECT p.id, CONCAT(p.`key`, '_renewal'), 'recurring', 0, v.renewal_xaf, 'XAF', 'YEAR', CURDATE(), 1
FROM `mp_service_products` p
JOIN (SELECT 'website_starter'      AS k, 100000 AS renewal_xaf UNION ALL
      SELECT 'website_business',          100000               UNION ALL
      SELECT 'website_professional',      135000) v ON p.`key` = v.k;

-- Email products
INSERT IGNORE INTO `mp_service_products`
  (`category_id`, `key`, `name_en`, `name_fr`, `service_type`, `active`, `orderable`, `sort_order`)
SELECT c.id,
       v.pkey, v.name_en, v.name_fr, 'business_email', 1, 0, v.sort_order
FROM `mp_service_categories` c
JOIN (SELECT 'email_1mb'  AS pkey, 'Business Email — 1 Mailbox'          AS name_en, 'Email Pro — 1 boîte'           AS name_fr, 1 AS sort_order UNION ALL
      SELECT 'email_5mb',          'Business Email — Up to 5 Mailboxes',            'Email Pro — jusqu\'à 5 boîtes', 2            UNION ALL
      SELECT 'email_10mb',         'Business Email — Up to 10 Mailboxes',           'Email Pro — jusqu\'à 10 boîtes', 3) v ON c.`key` = 'business_email';

-- Email prices
INSERT IGNORE INTO `mp_service_prices`
  (`product_id`, `variant_key`, `price_type`, `setup_amount`, `recurring_amount`, `currency`, `billing_interval`, `mailbox_limit`, `effective_from`, `active`)
SELECT p.id, CONCAT(p.`key`, '_monthly'), 'recurring', 0, v.price_xaf, 'XAF', 'MONTH', v.mb_limit, CURDATE(), 1
FROM `mp_service_products` p
JOIN (SELECT 'email_1mb'  AS k,  2500 AS price_xaf,  1 AS mb_limit UNION ALL
      SELECT 'email_5mb',         5000,                5             UNION ALL
      SELECT 'email_10mb',        9000,               10) v ON p.`key` = v.k;

-- Listing plan products
INSERT IGNORE INTO `mp_service_products`
  (`category_id`, `key`, `name_en`, `name_fr`, `service_type`, `active`, `orderable`, `listing_plan_slug`, `sort_order`)
SELECT c.id,
       v.pkey, v.name_en, v.name_fr, 'listing_subscription', 1, 0, v.plan_slug, v.sort_order
FROM `mp_service_categories` c
JOIN (SELECT 'listing_growth'  AS pkey, 'Business Growth Plan'    AS name_en, 'Plan Croissance Business' AS name_fr, 'growth'  AS plan_slug, 1 AS sort_order UNION ALL
      SELECT 'listing_premium',         'Premium Plan',                        'Plan Premium',                        'premium', 2) v ON c.`key` = 'listing_plan';

-- Listing prices
INSERT IGNORE INTO `mp_service_prices`
  (`product_id`, `variant_key`, `price_type`, `setup_amount`, `recurring_amount`, `currency`, `billing_interval`, `effective_from`, `active`)
SELECT p.id, CONCAT(p.`key`, '_annual'), 'recurring', 0, v.price_xaf, 'XAF', 'YEAR', CURDATE(), 1
FROM `mp_service_products` p
JOIN (SELECT 'listing_growth'  AS k, 40000 AS price_xaf UNION ALL
      SELECT 'listing_premium',       80000) v ON p.`key` = v.k;

-- ─────────────────────────────────────────────────────────────
-- site_settings keys used by the marketplace
-- ─────────────────────────────────────────────────────────────
INSERT IGNORE INTO `site_settings` (`key`, `value`) VALUES
  ('mp_payment_instruction_en',  'Please send payment to our MTN MoMo number and upload your screenshot below.'),
  ('mp_payment_instruction_fr',  'Veuillez envoyer le paiement à notre numéro MTN MoMo et téléverser votre capture d\'écran ci-dessous.'),
  ('mp_approval_expiry_days',    '7'),
  ('mp_fulfilment_sla_days',     '5'),
  ('mp_commission_enabled',      '0'),
  ('mp_auto_renewal_enabled',    '0');

SET FOREIGN_KEY_CHECKS = 1;

-- ─────────────────────────────────────────────────────────────
-- PATCH: Add partner_commission_rate to mp_service_products
-- Run once; safe to re-run (IF NOT EXISTS via column check)
-- ─────────────────────────────────────────────────────────────
ALTER TABLE `mp_service_products`
  ADD COLUMN IF NOT EXISTS `partner_commission_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00
    COMMENT 'Commission % paid to the Growth Partner on this product (e.g. 15.00 = 15%)';
