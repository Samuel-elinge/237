-- Run this in phpMyAdmin if catalogue page is blank (empty tables)
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
