-- ============================================================
-- Migration 013 — BHC Lead Generation & Enquiry Routing
-- Stage 4 of Phase 2: service enquiry records, provider
-- assignments, internal notes, and notification log.
--
-- Reuses: bhc_assessments, bhc_service_providers,
--         bhc_services, bhc_service_categories
-- Does NOT replace bhc_leads (kept for backward compat).
-- ============================================================

-- ─── Service Enquiries ────────────────────────────────────────────────────
-- One row per service request submitted by a business owner.
-- A single assessment can generate multiple enquiries (one per service).

CREATE TABLE IF NOT EXISTS `bhc_enquiries` (
  `id`                   INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `ref`                  CHAR(12)       NOT NULL COMMENT 'human-readable reference BHC-XXXXXXXX',
  `assessment_id`        BIGINT UNSIGNED   NULL     COMMENT 'FK → bhc_assessments; null if no assessment',
  `service_id`           SMALLINT UNSIGNED NOT NULL  COMMENT 'FK → bhc_services',
  `service_category_id`  SMALLINT UNSIGNED NULL      COMMENT 'denormalised for fast filtering',

  -- Business / contact info captured at enquiry time
  `business_name`        VARCHAR(200)   NOT NULL,
  `contact_name`         VARCHAR(120)   NOT NULL,
  `email`                VARCHAR(200)   NOT NULL,
  `phone`                VARCHAR(40)    NULL,
  `location`             VARCHAR(100)   NULL,
  `preferred_contact`    ENUM('email','phone','whatsapp','any') NOT NULL DEFAULT 'email',
  `requirements`         TEXT           NULL COMMENT 'free-text description from submitter',

  -- Attribution
  `referral_source`      VARCHAR(100)   NULL COMMENT 'utm_source, referral code, etc.',
  `gp_id`                INT UNSIGNED   NULL COMMENT 'Growth Partner attribution (users.id)',
  `promo_code`           VARCHAR(60)    NULL COMMENT 'promo/referral code if supplied',

  -- Language of the enquiry form
  `lang`                 ENUM('en','fr') NOT NULL DEFAULT 'en',

  -- Routing & status
  `status`               ENUM(
                            'new',
                            'awaiting_assignment',
                            'assigned',
                            'contacted',
                            'quotation_provided',
                            'in_progress',
                            'converted',
                            'not_converted',
                            'closed'
                          ) NOT NULL DEFAULT 'new',
  `assigned_provider_id` SMALLINT UNSIGNED NULL     COMMENT 'FK → bhc_service_providers; null = unassigned',
  `assigned_by`          INT UNSIGNED      NULL     COMMENT 'staff user who made the assignment',
  `assigned_at`          DATETIME       NULL,

  -- Consent (must be TRUE before forwarding to any provider)
  `consent_sharing`      TINYINT(1)     NOT NULL DEFAULT 0
                         COMMENT 'business owner agreed to share details with provider',
  `consent_marketing`    TINYINT(1)     NOT NULL DEFAULT 0,
  `consent_ip`           VARCHAR(45)    NULL,
  `consent_at`           DATETIME       NULL,

  -- Timestamps
  `created_at`           DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ref` (`ref`),
  KEY `idx_assessment`   (`assessment_id`),
  KEY `idx_service`      (`service_id`),
  KEY `idx_category`     (`service_category_id`),
  KEY `idx_status`       (`status`),
  KEY `idx_provider`     (`assigned_provider_id`),
  KEY `idx_gp`           (`gp_id`),
  KEY `idx_created`      (`created_at`),

  CONSTRAINT `fk_bhce_service`
    FOREIGN KEY (`service_id`) REFERENCES `bhc_services` (`id`),
  CONSTRAINT `fk_bhce_category`
    FOREIGN KEY (`service_category_id`) REFERENCES `bhc_service_categories` (`id`),
  CONSTRAINT `fk_bhce_provider`
    FOREIGN KEY (`assigned_provider_id`) REFERENCES `bhc_service_providers` (`id`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── Enquiry Notes ───────────────────────────────────────────────────────
-- Internal notes by admin/staff; never shown to business owners or providers.

CREATE TABLE IF NOT EXISTS `bhc_enquiry_notes` (
  `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `enquiry_id`   INT UNSIGNED  NOT NULL,
  `staff_id`     INT UNSIGNED  NULL     COMMENT 'users.id of note author',
  `note`         TEXT          NOT NULL,
  `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_enquiry` (`enquiry_id`),
  CONSTRAINT `fk_bhcen_enquiry`
    FOREIGN KEY (`enquiry_id`) REFERENCES `bhc_enquiries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── Enquiry Notification Log ────────────────────────────────────────────
-- Records every email/notification sent in relation to an enquiry.

CREATE TABLE IF NOT EXISTS `bhc_enquiry_notifications` (
  `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `enquiry_id`   INT UNSIGNED  NOT NULL,
  `recipient`    VARCHAR(200)  NOT NULL COMMENT 'email address sent to',
  `type`         ENUM(
                   'enquiry_confirmation_business',
                   'enquiry_notification_admin',
                   'enquiry_notification_provider',
                   'status_update_business',
                   'assignment_notification_provider'
                 ) NOT NULL,
  `status`       ENUM('sent','failed','pending') NOT NULL DEFAULT 'pending',
  `sent_at`      DATETIME      NULL,
  `error_msg`    VARCHAR(500)  NULL,
  `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_enquiry` (`enquiry_id`),
  CONSTRAINT `fk_bhcenot_enquiry`
    FOREIGN KEY (`enquiry_id`) REFERENCES `bhc_enquiries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
