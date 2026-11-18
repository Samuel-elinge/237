-- ============================================================
-- Migration 005: BHC Questions & Categories
-- Creates bhc_categories and bhc_questions tables.
-- Also creates bhc_settings for the BHC Settings admin page.
-- Safe to run multiple times (IF NOT EXISTS).
-- ============================================================

-- ── Categories ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS bhc_categories (
    id         INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    slug       VARCHAR(60)      NOT NULL,
    label_en   VARCHAR(120)     NOT NULL,
    label_fr   VARCHAR(120)     NOT NULL DEFAULT '',
    weight     TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Percentage weight (all rows must sum to 100)',
    sort_order SMALLINT         NOT NULL DEFAULT 0,
    is_active  TINYINT(1)       NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bhc_categories_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default categories (skip if already populated)
INSERT IGNORE INTO bhc_categories (slug, label_en, label_fr, weight, sort_order) VALUES
  ('online_visibility',    'Online Visibility',    'Visibilité en ligne',         15,  1),
  ('customer_acquisition', 'Customer Acquisition', 'Acquisition de clients',      20,  2),
  ('customer_trust',       'Customer Trust',       'Confiance des clients',       15,  3),
  ('digital_presence',     'Digital Presence',     'Présence numérique',          10,  4),
  ('customer_management',  'Customer Management',  'Gestion des clients',         15,  5),
  ('marketing_growth',     'Marketing & Growth',   'Marketing et croissance',     15,  6),
  ('business_operations',  'Business Operations',  'Opérations commerciales',     10,  7);

-- ── Questions ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS bhc_questions (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_id      INT UNSIGNED NOT NULL,
    question_en      TEXT         NOT NULL,
    question_fr      TEXT         NOT NULL DEFAULT '',
    description_en   TEXT         DEFAULT NULL,
    description_fr   TEXT         DEFAULT NULL,
    sort_order       SMALLINT     NOT NULL DEFAULT 0,
    is_active        TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_bhc_questions_category (category_id),
    CONSTRAINT fk_bhc_questions_category
        FOREIGN KEY (category_id) REFERENCES bhc_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default questions per category (skip if already populated)
-- Uses a subselect to look up category IDs by slug for portability

INSERT IGNORE INTO bhc_questions (category_id, question_en, question_fr, sort_order)
SELECT id, 'Does your business appear in Google Search results for relevant local keywords?',
           'Votre entreprise apparaît-elle dans les résultats Google pour des mots-clés locaux pertinents ?', 1
FROM bhc_categories WHERE slug='online_visibility';

INSERT IGNORE INTO bhc_questions (category_id, question_en, question_fr, sort_order)
SELECT id, 'Do you have a verified Google Business Profile (formerly Google My Business)?',
           'Avez-vous un profil Google Business vérifié (anciennement Google My Business) ?', 2
FROM bhc_categories WHERE slug='online_visibility';

INSERT IGNORE INTO bhc_questions (category_id, question_en, question_fr, sort_order)
SELECT id, 'Are you actively listed on at least three online directories relevant to your industry?',
           'Êtes-vous activement inscrit sur au moins trois annuaires en ligne pertinents pour votre secteur ?', 3
FROM bhc_categories WHERE slug='online_visibility';

INSERT IGNORE INTO bhc_questions (category_id, question_en, question_fr, sort_order)
SELECT id, 'Do you have a consistent strategy for attracting new customers each month?',
           'Avez-vous une stratégie cohérente pour attirer de nouveaux clients chaque mois ?', 1
FROM bhc_categories WHERE slug='customer_acquisition';

INSERT IGNORE INTO bhc_questions (category_id, question_en, question_fr, sort_order)
SELECT id, 'Do you track where your new customers come from (referrals, social, search, etc.)?',
           'Suivez-vous la provenance de vos nouveaux clients (recommandations, réseaux sociaux, recherche, etc.) ?', 2
FROM bhc_categories WHERE slug='customer_acquisition';

INSERT IGNORE INTO bhc_questions (category_id, question_en, question_fr, sort_order)
SELECT id, 'Have you run any paid advertising in the last 6 months to generate leads?',
           'Avez-vous diffusé de la publicité payante au cours des 6 derniers mois pour générer des prospects ?', 3
FROM bhc_categories WHERE slug='customer_acquisition';

INSERT IGNORE INTO bhc_questions (category_id, question_en, question_fr, sort_order)
SELECT id, 'Do you actively collect and display customer reviews online?',
           'Collectez-vous et affichez-vous activement les avis clients en ligne ?', 1
FROM bhc_categories WHERE slug='customer_trust';

INSERT IGNORE INTO bhc_questions (category_id, question_en, question_fr, sort_order)
SELECT id, 'Does your business have a professional website with clear contact information?',
           'Votre entreprise dispose-t-elle d''un site web professionnel avec des coordonnées claires ?', 2
FROM bhc_categories WHERE slug='customer_trust';

INSERT IGNORE INTO bhc_questions (category_id, question_en, question_fr, sort_order)
SELECT id, 'Do you respond promptly to customer enquiries (within 24 hours)?',
           'Répondez-vous rapidement aux demandes des clients (dans les 24 heures) ?', 3
FROM bhc_categories WHERE slug='customer_trust';

INSERT IGNORE INTO bhc_questions (category_id, question_en, question_fr, sort_order)
SELECT id, 'Is your business active on at least one social media platform?',
           'Votre entreprise est-elle active sur au moins une plateforme de médias sociaux ?', 1
FROM bhc_categories WHERE slug='digital_presence';

INSERT IGNORE INTO bhc_questions (category_id, question_en, question_fr, sort_order)
SELECT id, 'Is your website mobile-friendly and loads quickly on smartphones?',
           'Votre site web est-il adapté aux mobiles et se charge-t-il rapidement sur les smartphones ?', 2
FROM bhc_categories WHERE slug='digital_presence';

INSERT IGNORE INTO bhc_questions (category_id, question_en, question_fr, sort_order)
SELECT id, 'Do you post fresh content (blog, news, updates) at least once a month?',
           'Publiez-vous du contenu frais (blog, actualités, mises à jour) au moins une fois par mois ?', 3
FROM bhc_categories WHERE slug='digital_presence';

INSERT IGNORE INTO bhc_questions (category_id, question_en, question_fr, sort_order)
SELECT id, 'Do you use a CRM or system to track customer interactions and follow-ups?',
           'Utilisez-vous un CRM ou un système pour suivre les interactions avec les clients et les relances ?', 1
FROM bhc_categories WHERE slug='customer_management';

INSERT IGNORE INTO bhc_questions (category_id, question_en, question_fr, sort_order)
SELECT id, 'Do you have a process to re-engage past customers (e.g. email, WhatsApp, offers)?',
           'Avez-vous un processus pour réengager les anciens clients (e-mail, WhatsApp, offres) ?', 2
FROM bhc_categories WHERE slug='customer_management';

INSERT IGNORE INTO bhc_questions (category_id, question_en, question_fr, sort_order)
SELECT id, 'Do you collect customer contact details at the point of sale or enquiry?',
           'Collectez-vous les coordonnées des clients au point de vente ou lors d''une demande ?', 3
FROM bhc_categories WHERE slug='customer_management';

INSERT IGNORE INTO bhc_questions (category_id, question_en, question_fr, sort_order)
SELECT id, 'Do you have a documented marketing plan or calendar for the year?',
           'Avez-vous un plan ou un calendrier marketing documenté pour l''année ?', 1
FROM bhc_categories WHERE slug='marketing_growth';

INSERT IGNORE INTO bhc_questions (category_id, question_en, question_fr, sort_order)
SELECT id, 'Have you partnered with other local businesses or organisations to grow your reach?',
           'Avez-vous noué des partenariats avec d''autres entreprises ou organisations locales pour étendre votre portée ?', 2
FROM bhc_categories WHERE slug='marketing_growth';

INSERT IGNORE INTO bhc_questions (category_id, question_en, question_fr, sort_order)
SELECT id, 'Do you measure the return on your marketing spend (cost per lead or acquisition)?',
           'Mesurez-vous le retour sur vos dépenses marketing (coût par prospect ou acquisition) ?', 3
FROM bhc_categories WHERE slug='marketing_growth';

INSERT IGNORE INTO bhc_questions (category_id, question_en, question_fr, sort_order)
SELECT id, 'Do you use digital tools (accounting, booking, invoicing) to run your business efficiently?',
           'Utilisez-vous des outils numériques (comptabilité, réservation, facturation) pour gérer votre entreprise efficacement ?', 1
FROM bhc_categories WHERE slug='business_operations';

INSERT IGNORE INTO bhc_questions (category_id, question_en, question_fr, sort_order)
SELECT id, 'Do you have clear processes documented for key business tasks?',
           'Disposez-vous de processus clairement documentés pour les tâches commerciales clés ?', 2
FROM bhc_categories WHERE slug='business_operations';

INSERT IGNORE INTO bhc_questions (category_id, question_en, question_fr, sort_order)
SELECT id, 'Do you review your business performance (revenue, costs, growth) at least quarterly?',
           'Examinez-vous les performances de votre entreprise (revenus, coûts, croissance) au moins tous les trimestres ?', 3
FROM bhc_categories WHERE slug='business_operations';

-- ── Settings ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS bhc_settings (
    `key`        VARCHAR(80)  NOT NULL,
    `value`      TEXT         DEFAULT NULL,
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by` INT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
