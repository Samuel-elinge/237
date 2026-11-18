-- Migration 010: Business Health Check (Phase 1 + 2)
-- Run once via phpMyAdmin or cPanel MySQL.
-- This replaces / extends the earlier agent-only health_assessments system.
-- The original health_assessments table is retained unchanged so existing
-- agent data is preserved; new public guest assessments use bhc_assessments.

-- ─── Categories & weights (admin-configurable) ─────────────────────────────
CREATE TABLE IF NOT EXISTS `bhc_categories` (
  `id`           TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`         VARCHAR(40)      NOT NULL,
  `label_en`     VARCHAR(100)     NOT NULL,
  `label_fr`     VARCHAR(100)     NOT NULL,
  `weight`       TINYINT UNSIGNED NOT NULL DEFAULT 15,
  `sort_order`   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `is_active`    TINYINT(1)       NOT NULL DEFAULT 1,
  `created_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `bhc_categories` (`slug`,`label_en`,`label_fr`,`weight`,`sort_order`) VALUES
  ('online_visibility',    'Online Visibility',            'Visibilité en ligne',         15, 1),
  ('customer_acquisition', 'Customer Acquisition',         'Acquisition de clients',      20, 2),
  ('customer_trust',       'Customer Trust and Reputation','Confiance et réputation',     15, 3),
  ('digital_presence',     'Digital Presence and Technology','Présence numérique',        10, 4),
  ('customer_management',  'Customer Management',          'Gestion des clients',         15, 5),
  ('marketing_growth',     'Marketing and Business Growth','Marketing et croissance',     15, 6),
  ('business_operations',  'Business Operations',          'Opérations de l\'entreprise', 10, 7);

-- ─── Questions ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `bhc_questions` (
  `id`             SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id`    TINYINT UNSIGNED  NOT NULL,
  `question_en`    TEXT              NOT NULL,
  `question_fr`    TEXT              NOT NULL,
  `description_en` TEXT              NULL,
  `description_fr` TEXT              NULL,
  `sort_order`     TINYINT UNSIGNED  NOT NULL DEFAULT 0,
  `is_active`      TINYINT(1)        NOT NULL DEFAULT 1,
  `schema_version` TINYINT UNSIGNED  NOT NULL DEFAULT 1,
  `created_at`     DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_category` (`category_id`),
  CONSTRAINT `fk_bhcq_cat` FOREIGN KEY (`category_id`) REFERENCES `bhc_categories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── Answer options (5 standard options per question) ───────────────────────
CREATE TABLE IF NOT EXISTS `bhc_options` (
  `id`         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `option_key` VARCHAR(20)       NOT NULL COMMENT 'not_at_all|rarely|sometimes|mostly|consistently|na',
  `label_en`   VARCHAR(80)       NOT NULL,
  `label_fr`   VARCHAR(80)       NOT NULL,
  `score`      TINYINT SIGNED    NOT NULL COMMENT '-1 means N/A (excluded)',
  `sort_order` TINYINT UNSIGNED  NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_key` (`option_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `bhc_options` (`option_key`,`label_en`,`label_fr`,`score`,`sort_order`) VALUES
  ('not_at_all',    'Not at all',    'Pas du tout',   0, 1),
  ('rarely',        'Rarely',        'Rarement',       1, 2),
  ('sometimes',     'Sometimes',     'Parfois',        2, 3),
  ('mostly',        'Mostly',        'Souvent',        3, 4),
  ('consistently',  'Consistently',  'Toujours',       4, 5),
  ('na',            'Not applicable','Non applicable', -1,6);

-- ─── Assessments (guest + registered) ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS `bhc_assessments` (
  `id`                BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `reference`         VARCHAR(20)      NOT NULL COMMENT 'HC-YYYY-NNNNNN',
  `user_id`           INT UNSIGNED     NULL     DEFAULT NULL COMMENT 'nullable — guest assessments',
  -- business info
  `business_name`     VARCHAR(200)     NOT NULL DEFAULT '',
  `contact_name`      VARCHAR(100)     NOT NULL DEFAULT '',
  `business_category` VARCHAR(80)      NOT NULL DEFAULT '',
  `city`              VARCHAR(80)      NOT NULL DEFAULT '',
  `whatsapp`          VARCHAR(30)      NOT NULL DEFAULT '',
  `email`             VARCHAR(200)     NOT NULL DEFAULT '',
  `website`           VARCHAR(300)     NULL,
  `social_url`        VARCHAR(300)     NULL,
  `employee_band`     ENUM('solo','2_5','6_10','11_20','20plus') NULL,
  -- meta
  `lang`              CHAR(2)          NOT NULL DEFAULT 'en',
  `status`            ENUM('draft','submitted','completed') NOT NULL DEFAULT 'draft',
  `schema_version`    TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `referral_source`   VARCHAR(100)     NULL,
  `gp_id`             INT UNSIGNED     NULL     COMMENT 'Growth Partner attribution',
  `gp_ref_code`       VARCHAR(40)      NULL,
  -- consent
  `consent_privacy`   TINYINT(1)       NOT NULL DEFAULT 0,
  `consent_237biz_mkt`TINYINT(1)       NOT NULL DEFAULT 0,
  `consent_ms_mkt`    TINYINT(1)       NOT NULL DEFAULT 0,
  `consent_at`        DATETIME         NULL,
  -- scores (populated on submit)
  `score_online_visibility`    DECIMAL(6,2) NULL,
  `score_customer_acquisition` DECIMAL(6,2) NULL,
  `score_customer_trust`       DECIMAL(6,2) NULL,
  `score_digital_presence`     DECIMAL(6,2) NULL,
  `score_customer_management`  DECIMAL(6,2) NULL,
  `score_marketing_growth`     DECIMAL(6,2) NULL,
  `score_business_operations`  DECIMAL(6,2) NULL,
  `total_score`                DECIMAL(6,2) NULL,
  `score_band`                 ENUM('needs_attention','developing','good_progress','strong_foundations') NULL,
  `questions_answered`         TINYINT UNSIGNED NULL COMMENT 'count of non-NA answers',
  `questions_applicable`       TINYINT UNSIGNED NULL COMMENT 'count of applicable (non-NA) questions',
  -- timestamps
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `submitted_at`   DATETIME NULL,
  `completed_at`   DATETIME NULL,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `ip_address`     VARCHAR(45) NULL,
  `user_agent`     VARCHAR(300) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reference` (`reference`),
  KEY `idx_user_id`   (`user_id`),
  KEY `idx_status`    (`status`),
  KEY `idx_email`     (`email`),
  KEY `idx_created`   (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── Individual answers ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `bhc_answers` (
  `id`              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `assessment_id`   BIGINT UNSIGNED  NOT NULL,
  `question_id`     SMALLINT UNSIGNED NOT NULL,
  `option_key`      VARCHAR(20)      NOT NULL,
  `score`           TINYINT SIGNED   NOT NULL,
  `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_assessment_question` (`assessment_id`,`question_id`),
  KEY `idx_assessment` (`assessment_id`),
  CONSTRAINT `fk_bhca_assessment` FOREIGN KEY (`assessment_id`) REFERENCES `bhc_assessments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bhca_question`   FOREIGN KEY (`question_id`)   REFERENCES `bhc_questions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── Secure access tokens (report + resume) ─────────────────────────────────
CREATE TABLE IF NOT EXISTS `bhc_access_tokens` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `assessment_id`   BIGINT UNSIGNED NOT NULL,
  `token_type`      ENUM('report','resume') NOT NULL DEFAULT 'report',
  `token_hash`      VARCHAR(128)  NOT NULL COMMENT 'SHA-256 hex of the raw token',
  `expires_at`      DATETIME      NULL     COMMENT 'NULL = no expiry',
  `revoked`         TINYINT(1)    NOT NULL DEFAULT 0,
  `used_at`         DATETIME      NULL,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token_hash` (`token_hash`),
  KEY `idx_assessment`   (`assessment_id`),
  CONSTRAINT `fk_bhct_assessment` FOREIGN KEY (`assessment_id`) REFERENCES `bhc_assessments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── Leads generated from completed assessments ─────────────────────────────
CREATE TABLE IF NOT EXISTS `bhc_leads` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `assessment_id`   BIGINT UNSIGNED NOT NULL,
  `status`          ENUM('new','awaiting_followup','contact_requested','interested',
                         'consultation_requested','converted','not_interested','closed')
                    NOT NULL DEFAULT 'new',
  `assigned_to`     INT UNSIGNED  NULL COMMENT 'users.id of assigned staff/GP',
  `gp_id`           INT UNSIGNED  NULL,
  `notes`           TEXT          NULL,
  `last_contact_at` DATETIME      NULL,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_assessment` (`assessment_id`),
  KEY `idx_status`     (`status`),
  KEY `idx_assigned`   (`assigned_to`),
  CONSTRAINT `fk_bhcl_assessment` FOREIGN KEY (`assessment_id`) REFERENCES `bhc_assessments` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── Consent log ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `bhc_consents` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `assessment_id` BIGINT UNSIGNED NOT NULL,
  `consent_type`  ENUM('privacy_notice','237biz_marketing','ms_marketing') NOT NULL,
  `granted`       TINYINT(1)   NOT NULL,
  `ip_address`    VARCHAR(45)  NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_assessment` (`assessment_id`),
  CONSTRAINT `fk_bhcc_assessment` FOREIGN KEY (`assessment_id`) REFERENCES `bhc_assessments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── Seed the 21 questions (categories must exist first) ───────────────────

-- Category A: Online Visibility (id=1)
INSERT IGNORE INTO `bhc_questions`
  (`id`,`category_id`,`question_en`,`question_fr`,`description_en`,`description_fr`,`sort_order`) VALUES
(1, 1,
 'Does your business have a complete and up-to-date online business profile?',
 'Votre entreprise a-t-elle un profil commercial en ligne complet et à jour ?',
 'This includes listings on directories like 237biz, Google Business, or similar platforms.',
 'Cela inclut les inscriptions sur des annuaires comme 237biz, Google Business ou des plateformes similaires.',
 1),
(2, 1,
 'Can potential customers easily find your business when searching online?',
 'Les clients potentiels peuvent-ils facilement trouver votre entreprise lors de recherches en ligne ?',
 'Think about whether your business appears in relevant search results or online directories.',
 'Réfléchissez à savoir si votre entreprise apparaît dans les résultats de recherche pertinents ou les annuaires en ligne.',
 2),
(3, 1,
 'Do you regularly update your online presence with products, services, photos or business information?',
 'Mettez-vous régulièrement à jour votre présence en ligne avec des produits, services, photos ou informations commerciales ?',
 'Regular updates help keep your business visible and relevant to customers.',
 'Les mises à jour régulières aident à garder votre entreprise visible et pertinente pour les clients.',
 3);

-- Category B: Customer Acquisition (id=2)
INSERT IGNORE INTO `bhc_questions`
  (`id`,`category_id`,`question_en`,`question_fr`,`description_en`,`description_fr`,`sort_order`) VALUES
(4, 2,
 'Do you have a clear strategy for attracting new customers?',
 'Avez-vous une stratégie claire pour attirer de nouveaux clients ?',
 'A strategy means you have defined steps or methods you consistently use to bring in new business.',
 'Une stratégie signifie que vous avez des étapes ou méthodes définies que vous utilisez régulièrement pour attirer de nouvelles affaires.',
 1),
(5, 2,
 'Do you regularly use marketing channels such as WhatsApp, Facebook, TikTok or other platforms to reach potential customers?',
 'Utilisez-vous régulièrement des canaux marketing tels que WhatsApp, Facebook, TikTok ou d\'autres plateformes pour atteindre des clients potentiels ?',
 'Consider how often you actively promote your business through digital or traditional channels.',
 'Réfléchissez à la fréquence à laquelle vous faites activement la promotion de votre entreprise via des canaux numériques ou traditionnels.',
 2),
(6, 2,
 'Do you monitor where your customers come from and which activities generate enquiries or sales?',
 'Suivez-vous d\'où viennent vos clients et quelles activités génèrent des demandes ou des ventes ?',
 'Tracking your sources helps you invest in what works best.',
 'Le suivi de vos sources vous aide à investir dans ce qui fonctionne le mieux.',
 3);

-- Category C: Customer Trust and Reputation (id=3)
INSERT IGNORE INTO `bhc_questions`
  (`id`,`category_id`,`question_en`,`question_fr`,`description_en`,`description_fr`,`sort_order`) VALUES
(7, 3,
 'Does your business have a professional and consistent business identity?',
 'Votre entreprise a-t-elle une identité commerciale professionnelle et cohérente ?',
 'This includes a recognisable name, logo, colours and tone used consistently across all touchpoints.',
 'Cela inclut un nom reconnaissable, un logo, des couleurs et un ton utilisés de manière cohérente sur tous les points de contact.',
 1),
(8, 3,
 'Do you actively collect customer reviews and feedback?',
 'Collectez-vous activement les avis et commentaires des clients ?',
 'Reviews build trust and help new customers choose your business.',
 'Les avis renforcent la confiance et aident les nouveaux clients à choisir votre entreprise.',
 2),
(9, 3,
 'Do you respond to customer feedback, complaints and enquiries professionally?',
 'Répondez-vous professionnellement aux commentaires, plaintes et demandes des clients ?',
 'Timely and professional responses demonstrate that you value your customers.',
 'Des réponses rapides et professionnelles montrent que vous accordez de l\'importance à vos clients.',
 3);

-- Category D: Digital Presence and Technology (id=4)
INSERT IGNORE INTO `bhc_questions`
  (`id`,`category_id`,`question_en`,`question_fr`,`description_en`,`description_fr`,`sort_order`) VALUES
(10, 4,
 'Does your business have a professional website or suitable online platform presenting its products and services?',
 'Votre entreprise dispose-t-elle d\'un site web professionnel ou d\'une plateforme en ligne adaptée présentant ses produits et services ?',
 'A professional website or platform helps customers find out about your business at any time.',
 'Un site web professionnel ou une plateforme aide les clients à se renseigner sur votre entreprise à tout moment.',
 1),
(11, 4,
 'Do you use a professional business email address rather than relying entirely on personal email accounts?',
 'Utilisez-vous une adresse e-mail professionnelle plutôt que de vous fier entièrement à des comptes e-mail personnels ?',
 'A professional email address (e.g. info@yourbusiness.com) builds credibility with customers and partners.',
 'Une adresse e-mail professionnelle (ex. : info@votreentreprise.com) renforce votre crédibilité auprès des clients et des partenaires.',
 2),
(12, 4,
 'Do you use digital tools that help customers communicate with your business and access your services?',
 'Utilisez-vous des outils numériques qui aident les clients à communiquer avec votre entreprise et à accéder à vos services ?',
 'Examples include WhatsApp Business, online booking, payment links or a customer enquiry form.',
 'Exemples : WhatsApp Business, réservation en ligne, liens de paiement ou formulaire de demande client.',
 3);

-- Category E: Customer Management (id=5)
INSERT IGNORE INTO `bhc_questions`
  (`id`,`category_id`,`question_en`,`question_fr`,`description_en`,`description_fr`,`sort_order`) VALUES
(13, 5,
 'Do you have an organised way of recording customer details and enquiries?',
 'Avez-vous une façon organisée d\'enregistrer les coordonnées des clients et les demandes ?',
 'This could be a spreadsheet, notebook, app or CRM system — anything that helps you track customers.',
 'Il peut s\'agir d\'une feuille de calcul, d\'un carnet, d\'une application ou d\'un CRM — tout ce qui vous aide à suivre vos clients.',
 1),
(14, 5,
 'Do you follow up with potential customers who have shown interest but have not purchased?',
 'Faites-vous un suivi auprès des clients potentiels qui ont manifesté de l\'intérêt mais n\'ont pas encore acheté ?',
 'Following up with leads significantly increases your chances of converting interest into sales.',
 'Faire un suivi auprès des prospects augmente considérablement vos chances de convertir l\'intérêt en ventes.',
 2),
(15, 5,
 'Do you maintain relationships with existing customers and encourage repeat business?',
 'Entretenez-vous des relations avec vos clients existants et encouragez-vous les affaires répétées ?',
 'Retaining existing customers is typically more cost-effective than acquiring new ones.',
 'Fidéliser les clients existants est généralement plus rentable que d\'en acquérir de nouveaux.',
 3);

-- Category F: Marketing and Business Growth (id=6)
INSERT IGNORE INTO `bhc_questions`
  (`id`,`category_id`,`question_en`,`question_fr`,`description_en`,`description_fr`,`sort_order`) VALUES
(16, 6,
 'Does your business have a marketing plan or defined marketing objectives?',
 'Votre entreprise a-t-elle un plan marketing ou des objectifs marketing définis ?',
 'A marketing plan helps you focus your efforts and budget on activities that support your goals.',
 'Un plan marketing vous aide à concentrer vos efforts et votre budget sur les activités qui soutiennent vos objectifs.',
 1),
(17, 6,
 'Do you regularly run campaigns, promotions, special offers or other activities to increase sales?',
 'Organisez-vous régulièrement des campagnes, promotions, offres spéciales ou autres activités pour augmenter les ventes ?',
 'Regular promotional activity keeps your business visible and gives customers reasons to buy.',
 'Une activité promotionnelle régulière maintient votre entreprise visible et donne aux clients des raisons d\'acheter.',
 2),
(18, 6,
 'Do you measure the results of your marketing activities and make improvements based on those results?',
 'Mesurez-vous les résultats de vos activités marketing et apportez-vous des améliorations en fonction de ces résultats ?',
 'Measuring results allows you to identify what works and allocate resources more effectively.',
 'Mesurer les résultats vous permet d\'identifier ce qui fonctionne et d\'allouer les ressources plus efficacement.',
 3);

-- Category G: Business Operations (id=7)
INSERT IGNORE INTO `bhc_questions`
  (`id`,`category_id`,`question_en`,`question_fr`,`description_en`,`description_fr`,`sort_order`) VALUES
(19, 7,
 'Do you have organised processes for managing daily business activities?',
 'Avez-vous des processus organisés pour gérer les activités commerciales quotidiennes ?',
 'Organised processes reduce errors, save time and help your business run consistently.',
 'Des processus organisés réduisent les erreurs, font gagner du temps et aident votre entreprise à fonctionner de manière cohérente.',
 1),
(20, 7,
 'Do you maintain records of important business transactions, expenses and activities?',
 'Tenez-vous des registres des transactions commerciales importantes, des dépenses et des activités ?',
 'Good record-keeping supports financial management, compliance and business planning.',
 'Une bonne tenue des registres soutient la gestion financière, la conformité et la planification commerciale.',
 2),
(21, 7,
 'Do you use tools or processes to reduce manual work, errors and missed opportunities?',
 'Utilisez-vous des outils ou des processus pour réduire le travail manuel, les erreurs et les opportunités manquées ?',
 'Tools like scheduling apps, automated reminders or simple checklists can make a big difference.',
 'Des outils comme les applications de planification, les rappels automatiques ou les simples listes de contrôle peuvent faire une grande différence.',
 3);
