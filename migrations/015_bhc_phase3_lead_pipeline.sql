-- ============================================================
-- Migration 015 — BHC Phase 3: Lead Pipeline & GP Attribution
-- Follow-up activities log, GP assessment links, lead statuses
-- and conversion tracking.
-- ============================================================

-- ─── Follow-up Activities ────────────────────────────────────────────────
-- Tracks every follow-up action taken on an enquiry: calls, emails,
-- meetings, etc.  Growth Partners can log their own activities on
-- leads assigned to them.

CREATE TABLE IF NOT EXISTS `bhc_enquiry_followups` (
  `id`           INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `enquiry_id`   INT UNSIGNED   NOT NULL COMMENT 'FK → bhc_enquiries',
  `actor_id`     INT UNSIGNED   NULL     COMMENT 'users.id — staff or GP who logged this',
  `actor_type`   ENUM('staff','growth_partner') NOT NULL DEFAULT 'staff',
  `activity`     ENUM(
                   'call_outbound',
                   'call_inbound',
                   'email_sent',
                   'email_received',
                   'meeting_scheduled',
                   'meeting_completed',
                   'quotation_sent',
                   'proposal_sent',
                   'whatsapp_message',
                   'note',
                   'status_change'
                 ) NOT NULL DEFAULT 'note',
  `summary`      VARCHAR(500)   NULL     COMMENT 'short description of the activity',
  `detail`       TEXT           NULL     COMMENT 'full notes / email body / call notes',
  `outcome`      VARCHAR(200)   NULL     COMMENT 'e.g. "interested", "call back next week"',
  `follow_up_at` DATETIME       NULL     COMMENT 'scheduled next follow-up date',
  `created_at`   DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_enquiry`    (`enquiry_id`),
  KEY `idx_actor`      (`actor_id`),
  KEY `idx_followup`   (`follow_up_at`),
  KEY `idx_created`    (`created_at`),

  CONSTRAINT `fk_bhcf_enquiry`
    FOREIGN KEY (`enquiry_id`) REFERENCES `bhc_enquiries` (`id`) ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── GP Assessment Links ─────────────────────────────────────────────────
-- Stores partner-specific assessment URL tokens so a Growth Partner's
-- referrals are automatically attributed when the assessment is started.

CREATE TABLE IF NOT EXISTS `bhc_gp_links` (
  `id`           INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `gp_id`        INT UNSIGNED   NOT NULL COMMENT 'FK → users.id of the Growth Partner',
  `token`        CHAR(32)       NOT NULL COMMENT 'URL token; maps to ?gp=TOKEN',
  `label`        VARCHAR(120)   NULL     COMMENT 'friendly name e.g. "LinkedIn campaign"',
  `is_active`    TINYINT(1)     NOT NULL DEFAULT 1,
  `click_count`  INT UNSIGNED   NOT NULL DEFAULT 0,
  `created_at`   DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`   DATETIME       NULL     COMMENT 'NULL = never expires',

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token` (`token`),
  KEY `idx_gp`     (`gp_id`),
  KEY `idx_active` (`is_active`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── Conversion Events ───────────────────────────────────────────────────
-- Records when an enquiry converts to a paid service/contract.
-- Linked to commercial terms for commission calculation (via
-- bhc_service_commercial_terms).

CREATE TABLE IF NOT EXISTS `bhc_conversion_events` (
  `id`              INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `enquiry_id`      INT UNSIGNED   NOT NULL COMMENT 'FK → bhc_enquiries',
  `service_id`      SMALLINT UNSIGNED NOT NULL COMMENT 'FK → bhc_services',
  `provider_id`     SMALLINT UNSIGNED NULL     COMMENT 'FK → bhc_service_providers',
  `converted_by`    INT UNSIGNED   NULL     COMMENT 'staff user who marked conversion',
  `conversion_value` DECIMAL(10,2) NULL     COMMENT 'contract/invoice value in currency_code',
  `currency_code`   CHAR(3)        NOT NULL DEFAULT 'GBP',
  `commission_term_id` INT UNSIGNED NULL    COMMENT 'FK → bhc_service_commercial_terms.id',
  `commission_amount`  DECIMAL(10,2) NULL,
  `notes`           TEXT           NULL,
  `converted_at`    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at`      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_enquiry`   (`enquiry_id`),
  KEY `idx_service`   (`service_id`),
  KEY `idx_provider`  (`provider_id`),
  KEY `idx_converted` (`converted_at`),

  CONSTRAINT `fk_bhcce_enquiry`
    FOREIGN KEY (`enquiry_id`) REFERENCES `bhc_enquiries` (`id`),
  CONSTRAINT `fk_bhcce_service`
    FOREIGN KEY (`service_id`) REFERENCES `bhc_services` (`id`),
  CONSTRAINT `fk_bhcce_provider`
    FOREIGN KEY (`provider_id`) REFERENCES `bhc_service_providers` (`id`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── GP Lead Assignments ─────────────────────────────────────────────────
-- Which enquiries are explicitly assigned to which Growth Partners
-- (in addition to auto-attribution via gp_id on the enquiry).

CREATE TABLE IF NOT EXISTS `bhc_gp_lead_assignments` (
  `id`           INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `enquiry_id`   INT UNSIGNED   NOT NULL,
  `gp_id`        INT UNSIGNED   NOT NULL COMMENT 'users.id of the Growth Partner',
  `assigned_by`  INT UNSIGNED   NULL     COMMENT 'staff user who made the assignment',
  `assigned_at`  DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_primary`   TINYINT(1)     NOT NULL DEFAULT 1 COMMENT '1 = primary GP for this lead',
  `notes`        VARCHAR(300)   NULL,

  PRIMARY KEY (`id`),
  KEY `idx_enquiry` (`enquiry_id`),
  KEY `idx_gp`      (`gp_id`),

  CONSTRAINT `fk_bhcgla_enquiry`
    FOREIGN KEY (`enquiry_id`) REFERENCES `bhc_enquiries` (`id`) ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
