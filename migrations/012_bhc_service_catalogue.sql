-- =============================================================================
-- Migration 012: BHC Phase 2 Stage 3 — Service Catalogue & Provider Network
-- Creates:
--   bhc_service_categories  — configurable service categories
--   bhc_services            — individual services per category
--   bhc_service_providers   — provider profiles
--   bhc_provider_services   — many-to-many: provider ↔ service
--   bhc_service_triggers    — links BHC categories/answers → services
-- =============================================================================

-- ── Service Categories ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `bhc_service_categories` (
  `id`           SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`         VARCHAR(60)       NOT NULL,
  `name_en`      VARCHAR(120)      NOT NULL,
  `name_fr`      VARCHAR(120)      NOT NULL,
  `description_en` TEXT            NULL,
  `description_fr` TEXT            NULL,
  `icon`         VARCHAR(60)       NULL COMMENT 'icon key or class name',
  `sort_order`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `is_active`    TINYINT(1)        NOT NULL DEFAULT 1,
  `created_at`   DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `bhc_service_categories`
  (`slug`,`name_en`,`name_fr`,`sort_order`) VALUES
('social_media_marketing',  'Social Media and Digital Marketing',    'Médias sociaux et marketing numérique',    1),
('content_branding',        'Content Creation and Branding',         'Création de contenu et image de marque',  2),
('whatsapp_automation',     'WhatsApp Business and Automation',      'WhatsApp Business et Automatisation',     3),
('websites_ecommerce',      'Websites and E-commerce',               'Sites web et e-commerce',                 4),
('email_hosting',           'Business Email and Hosting',            'Email professionnel et hébergement',      5),
('crm_customer_mgmt',       'CRM and Customer Management',           'CRM et gestion des clients',              6),
('seo_visibility',          'SEO and Online Visibility',             'SEO et visibilité en ligne',              7),
('accounting_support',      'Accounting and Business Support',       'Comptabilité et soutien aux entreprises', 8),
('training_development',    'Training and Business Development',     'Formation et développement des affaires', 9),
('photography_video',       'Photography and Video',                 'Photographie et vidéo',                  10),
('logistics_services',      'Logistics and Business Services',       'Logistique et services aux entreprises', 11);

-- ── Services ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `bhc_services` (
  `id`              SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id`     SMALLINT UNSIGNED NOT NULL,
  `slug`            VARCHAR(80)       NOT NULL,
  `name_en`         VARCHAR(160)      NOT NULL,
  `name_fr`         VARCHAR(160)      NOT NULL,
  `description_en`  TEXT              NULL,
  `description_fr`  TEXT              NULL,
  `problems_en`     TEXT              NULL COMMENT 'Business problems this service addresses',
  `problems_fr`     TEXT              NULL,
  `benefits_en`     TEXT              NULL,
  `benefits_fr`     TEXT              NULL,
  `pricing_info`    VARCHAR(200)      NULL COMMENT 'e.g. "From XAF 50,000" or "Quote required"',
  `pricing_type`    ENUM('one_off','recurring','quote','free') NOT NULL DEFAULT 'quote',
  `is_featured`     TINYINT(1)        NOT NULL DEFAULT 0,
  `is_active`       TINYINT(1)        NOT NULL DEFAULT 1,
  `sort_order`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_slug` (`slug`),
  KEY `idx_category` (`category_id`),
  KEY `idx_active_featured` (`is_active`,`is_featured`),
  CONSTRAINT `fk_bsvc_cat` FOREIGN KEY (`category_id`) REFERENCES `bhc_service_categories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Initial WhatsApp services (priority category per spec §9)
INSERT IGNORE INTO `bhc_services`
  (`category_id`,`slug`,`name_en`,`name_fr`,`description_en`,`description_fr`,`pricing_type`,`is_featured`,`sort_order`) VALUES

-- WhatsApp (category 3)
(3,'whatsapp_profile_setup',
 'WhatsApp Business Profile Setup',
 'Configuration du profil WhatsApp Business',
 'Professional setup of your WhatsApp Business account including profile, catalogue and auto-replies.',
 'Configuration professionnelle de votre compte WhatsApp Business : profil, catalogue et réponses automatiques.',
 'one_off',1,1),
(3,'whatsapp_faq_automation',
 'WhatsApp FAQ and Automated Responses',
 'FAQ et réponses automatiques WhatsApp',
 'Set up automated answers to your most frequently asked questions so no enquiry goes unanswered.',
 'Configurez des réponses automatiques à vos questions les plus fréquentes pour qu\'aucune demande ne reste sans réponse.',
 'one_off',0,2),
(3,'whatsapp_catalogue',
 'WhatsApp Product Catalogue Setup',
 'Configuration du catalogue produits WhatsApp',
 'Display your products and services directly within WhatsApp so customers can browse and enquire easily.',
 'Présentez vos produits et services directement dans WhatsApp pour que les clients puissent les consulter facilement.',
 'one_off',0,3),
(3,'whatsapp_followup_automation',
 'WhatsApp Customer Follow-up Automation',
 'Automatisation du suivi client WhatsApp',
 'Automated follow-up workflows to convert enquiries into sales and keep customers engaged.',
 'Des flux de travail automatisés pour convertir les demandes en ventes et fidéliser les clients.',
 'recurring',1,4),
(3,'whatsapp_crm_integration',
 'WhatsApp and CRM Integration',
 'Intégration WhatsApp et CRM',
 'Connect WhatsApp to a CRM so customer conversations and records are managed in one place.',
 'Connectez WhatsApp à un CRM pour gérer les conversations et les dossiers clients en un seul endroit.',
 'one_off',0,5),
(3,'whatsapp_booking_workflow',
 'WhatsApp Booking Enquiry Workflow',
 'Flux de demande de réservation WhatsApp',
 'A structured WhatsApp workflow that captures booking enquiries and confirms appointments automatically.',
 'Un flux WhatsApp structuré qui capture les demandes de réservation et confirme les rendez-vous automatiquement.',
 'one_off',0,6),
(3,'whatsapp_broadcasts',
 'WhatsApp Permission-based Campaigns',
 'Campagnes WhatsApp basées sur le consentement',
 'Reach customers who have opted in with promotional updates, offers and business news via WhatsApp.',
 'Rejoignez les clients ayant donné leur consentement avec des mises à jour promotionnelles et des nouvelles commerciales via WhatsApp.',
 'recurring',0,7),
(3,'whatsapp_api_integration',
 'WhatsApp Business Platform Integration',
 'Intégration de la plateforme WhatsApp Business',
 'Advanced integration using the official WhatsApp Business API for high-volume or complex messaging needs.',
 'Intégration avancée via l\'API WhatsApp Business officielle pour les besoins de messagerie complexes ou à volume élevé.',
 'quote',0,8),

-- Websites (category 4)
(4,'website_design',
 'Professional Website Design',
 'Conception de site web professionnel',
 'A professional, mobile-friendly website that presents your business, products and services online 24/7.',
 'Un site web professionnel et adapté aux mobiles qui présente votre entreprise, vos produits et services en ligne 24h/24.',
 'quote',1,1),
(4,'ecommerce_setup',
 'E-commerce Setup',
 'Mise en place de l\'e-commerce',
 'An online shop that lets customers browse, order and pay for your products or services online.',
 'Une boutique en ligne permettant aux clients de parcourir, commander et payer vos produits ou services en ligne.',
 'quote',0,2),

-- SEO (category 7)
(7,'google_business_profile',
 'Google Business Profile Setup',
 'Configuration du profil Google Business',
 'Get your business listed, verified and optimised on Google so local customers can find you easily.',
 'Faites référencer, vérifier et optimiser votre entreprise sur Google pour que les clients locaux puissent vous trouver facilement.',
 'one_off',1,1),
(7,'seo_optimisation',
 'SEO Optimisation',
 'Optimisation SEO',
 'Improve your website visibility in search engines through keyword research, content and technical improvements.',
 'Améliorez la visibilité de votre site dans les moteurs de recherche grâce à la recherche de mots-clés, au contenu et aux améliorations techniques.',
 'recurring',0,2),

-- Social media (category 1)
(1,'social_media_management',
 'Social Media Management',
 'Gestion des médias sociaux',
 'Professional management of your Facebook, Instagram, TikTok or other social media accounts.',
 'Gestion professionnelle de vos comptes Facebook, Instagram, TikTok ou autres réseaux sociaux.',
 'recurring',1,1),
(1,'social_media_advertising',
 'Social Media Advertising',
 'Publicité sur les réseaux sociaux',
 'Paid campaigns on Facebook, Instagram or TikTok to reach new customers and grow your audience.',
 'Campagnes payantes sur Facebook, Instagram ou TikTok pour atteindre de nouveaux clients et développer votre audience.',
 'quote',0,2),

-- CRM (category 6)
(6,'crm_setup',
 'CRM Setup and Configuration',
 'Configuration du CRM',
 'Set up a customer relationship management system to track enquiries, customers and sales pipeline.',
 'Configurez un système de gestion de la relation client pour suivre les demandes, les clients et le pipeline de ventes.',
 'one_off',0,1),

-- Content/Branding (category 2)
(2,'branding_design',
 'Business Branding and Identity',
 'Image de marque et identité commerciale',
 'Logo, colours, typography and brand guidelines that give your business a professional, consistent identity.',
 'Logo, couleurs, typographie et guide de marque pour donner à votre entreprise une identité professionnelle et cohérente.',
 'quote',1,1),
(2,'content_creation',
 'Content Creation',
 'Création de contenu',
 'Professional photos, graphics, videos and written content for your social media, website and marketing.',
 'Photos professionnelles, graphiques, vidéos et contenu écrit pour vos réseaux sociaux, site web et marketing.',
 'recurring',0,2),

-- Email/Hosting (category 5)
(5,'business_email',
 'Professional Business Email',
 'Email professionnel',
 'A professional email address using your own domain (e.g. info@yourbusiness.com) with hosting and setup.',
 'Une adresse email professionnelle sur votre propre domaine (ex. : info@votreentreprise.com) avec hébergement et configuration.',
 'one_off',0,1);

-- ── Service Providers ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `bhc_service_providers` (
  `id`               SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`             VARCHAR(80)       NOT NULL,
  `business_name`    VARCHAR(160)      NOT NULL,
  `short_bio_en`     VARCHAR(300)      NULL,
  `short_bio_fr`     VARCHAR(300)      NULL,
  `full_profile_en`  TEXT              NULL,
  `full_profile_fr`  TEXT              NULL,
  `logo_path`        VARCHAR(300)      NULL COMMENT 'relative path to uploaded logo',
  `website_url`      VARCHAR(300)      NULL,
  `email`            VARCHAR(200)      NULL,
  `phone`            VARCHAR(40)       NULL,
  `whatsapp`         VARCHAR(40)       NULL,
  `facebook_url`     VARCHAR(300)      NULL,
  `instagram_url`    VARCHAR(300)      NULL,
  `linkedin_url`     VARCHAR(300)      NULL,
  `location`         VARCHAR(120)      NULL COMMENT 'city/region',
  `areas_served`     VARCHAR(300)      NULL,
  `languages`        SET('en','fr')    NOT NULL DEFAULT 'en,fr',
  `portfolio_url`    VARCHAR(300)      NULL,
  `pricing_notes`    VARCHAR(300)      NULL,
  `commercial_model` SET('referral','fee_per_lead','subscription','revenue_share','featured') NULL,
  `commission_rate`  DECIMAL(5,2)      NULL COMMENT 'percentage, if applicable',
  `is_verified`      TINYINT(1)        NOT NULL DEFAULT 0,
  `is_preferred`     TINYINT(1)        NOT NULL DEFAULT 0,
  `is_active`        TINYINT(1)        NOT NULL DEFAULT 1,
  `receives_enquiries` TINYINT(1)      NOT NULL DEFAULT 1,
  `enquiry_routing`  ENUM('auto','manual','admin_only') NOT NULL DEFAULT 'admin_only',
  `notes_internal`   TEXT              NULL COMMENT 'Internal admin notes only',
  `created_at`       DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_slug` (`slug`),
  KEY `idx_active`     (`is_active`),
  KEY `idx_verified`   (`is_verified`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Provider ↔ Service (many-to-many) ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS `bhc_provider_services` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider_id`    SMALLINT UNSIGNED NOT NULL,
  `service_id`     SMALLINT UNSIGNED NOT NULL,
  `is_primary`     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Provider specialises in this service',
  `pricing_detail` VARCHAR(200) NULL COMMENT 'Provider-specific pricing for this service',
  `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prov_svc` (`provider_id`,`service_id`),
  KEY `idx_service`  (`service_id`),
  KEY `idx_provider` (`provider_id`),
  CONSTRAINT `fk_bps_prov` FOREIGN KEY (`provider_id`) REFERENCES `bhc_service_providers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bps_svc`  FOREIGN KEY (`service_id`)  REFERENCES `bhc_services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Service ↔ BHC Trigger (what assessment findings surface a service) ──────
-- A service can be triggered by: a BHC category band, a specific answer, or a category %
CREATE TABLE IF NOT EXISTS `bhc_service_triggers` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `service_id`   SMALLINT UNSIGNED NOT NULL,
  `trigger_type` ENUM('category_band','question_answer','category_pct') NOT NULL,
  `category_slug` VARCHAR(60) NULL COMMENT 'BHC category slug',
  `score_band`   ENUM('needs_attention','developing','good_progress','strong_foundations') NULL,
  `question_id`  SMALLINT UNSIGNED NULL,
  `option_key`   VARCHAR(20) NULL COMMENT 'e.g. not_at_all, rarely',
  `pct_max`      TINYINT UNSIGNED NULL COMMENT 'fire when category_pct <= this',
  `pct_min`      TINYINT UNSIGNED NULL DEFAULT 0,
  `priority`     ENUM('high','medium','low') NOT NULL DEFAULT 'medium',
  `is_active`    TINYINT(1)  NOT NULL DEFAULT 1,
  `created_at`   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_service`  (`service_id`),
  KEY `idx_question` (`question_id`,`option_key`),
  KEY `idx_cat`      (`category_slug`,`score_band`),
  CONSTRAINT `fk_bst_svc`  FOREIGN KEY (`service_id`)  REFERENCES `bhc_services` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bst_q`    FOREIGN KEY (`question_id`) REFERENCES `bhc_questions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed WhatsApp triggers (spec §9.3)
INSERT IGNORE INTO `bhc_service_triggers`
  (`service_id`,`trigger_type`,`question_id`,`option_key`,`priority`) VALUES
-- Q12: digital communication tools → not_at_all / rarely → whatsapp_profile_setup
((SELECT id FROM bhc_services WHERE slug='whatsapp_profile_setup'),  'question_answer',12,'not_at_all','high'),
((SELECT id FROM bhc_services WHERE slug='whatsapp_profile_setup'),  'question_answer',12,'rarely','high'),
-- Q14: follow up → not_at_all / rarely → followup automation
((SELECT id FROM bhc_services WHERE slug='whatsapp_followup_automation'),'question_answer',14,'not_at_all','high'),
((SELECT id FROM bhc_services WHERE slug='whatsapp_followup_automation'),'question_answer',14,'rarely','high'),
-- Q13: recording customer details → not_at_all → CRM setup
((SELECT id FROM bhc_services WHERE slug='crm_setup'),  'question_answer',13,'not_at_all','high'),
((SELECT id FROM bhc_services WHERE slug='crm_setup'),  'question_answer',13,'rarely','medium'),
-- Low online visibility → SEO + Google Business
((SELECT id FROM bhc_services WHERE slug='google_business_profile'),'question_answer',1,'not_at_all','high'),
((SELECT id FROM bhc_services WHERE slug='google_business_profile'),'question_answer',2,'not_at_all','high'),
((SELECT id FROM bhc_services WHERE slug='seo_optimisation'),       'question_answer',2,'rarely','medium'),
-- No website → website design
((SELECT id FROM bhc_services WHERE slug='website_design'),         'question_answer',10,'not_at_all','high'),
-- No professional email → email hosting
((SELECT id FROM bhc_services WHERE slug='business_email'),         'question_answer',11,'not_at_all','medium'),
-- No branding/consistency → branding
((SELECT id FROM bhc_services WHERE slug='branding_design'),        'question_answer',7,'not_at_all','high'),
((SELECT id FROM bhc_services WHERE slug='branding_design'),        'question_answer',7,'rarely','medium'),
-- Weak customer acquisition category → social media management
((SELECT id FROM bhc_services WHERE slug='social_media_management'),'question_answer',5,'not_at_all','high'),
((SELECT id FROM bhc_services WHERE slug='social_media_management'),'question_answer',5,'rarely','medium');

-- Category-band triggers (whole category weak)
INSERT IGNORE INTO `bhc_service_triggers`
  (`service_id`,`trigger_type`,`category_slug`,`score_band`,`priority`) VALUES
((SELECT id FROM bhc_services WHERE slug='social_media_management'),  'category_band','customer_acquisition','needs_attention','high'),
((SELECT id FROM bhc_services WHERE slug='social_media_management'),  'category_band','marketing_growth',    'needs_attention','high'),
((SELECT id FROM bhc_services WHERE slug='whatsapp_profile_setup'),   'category_band','digital_presence',    'needs_attention','high'),
((SELECT id FROM bhc_services WHERE slug='google_business_profile'),  'category_band','online_visibility',   'needs_attention','high'),
((SELECT id FROM bhc_services WHERE slug='seo_optimisation'),         'category_band','online_visibility',   'developing',     'medium'),
((SELECT id FROM bhc_services WHERE slug='crm_setup'),                'category_band','customer_management', 'needs_attention','high'),
((SELECT id FROM bhc_services WHERE slug='whatsapp_followup_automation'),'category_band','customer_management','developing',   'medium');
