-- ============================================================
-- Migration 014 — BHC Commercial Terms
-- Stage 5 of Phase 2: configurable commercial arrangements
-- between 237biz and service providers.
--
-- Reuses: bhc_service_providers, bhc_provider_services,
--         bhc_services
-- Does NOT duplicate the existing commission engine.
-- ============================================================

-- ─── Service Commercial Terms ─────────────────────────────────────────────
-- One row per commercial arrangement between 237biz and a provider
-- for a specific service (or NULL service = applies to all their services).
-- Multiple active rows allowed when several models apply simultaneously
-- (e.g. subscription + revenue share).

CREATE TABLE IF NOT EXISTS `bhc_service_commercial_terms` (
  `id`              INT UNSIGNED      NOT NULL AUTO_INCREMENT,

  -- Scope: provider is required; service is optional (NULL = applies to all)
  `provider_id`     SMALLINT UNSIGNED NOT NULL COMMENT 'FK → bhc_service_providers',
  `service_id`      SMALLINT UNSIGNED NULL     COMMENT 'FK → bhc_services; NULL = all services for this provider',

  -- Commercial model
  `model`           ENUM(
                      'referral',          -- % commission on converted enquiry
                      'fee_per_lead',      -- flat fee per qualified lead sent
                      'subscription',      -- recurring monthly/annual fee
                      'revenue_share',     -- % of ongoing contract value
                      'featured'           -- paid featured placement in catalogue
                    ) NOT NULL,

  -- Rates and amounts (use whichever columns apply to the model)
  `rate_pct`        DECIMAL(5,2)      NULL COMMENT 'Percentage rate (referral / revenue_share)',
  `flat_amount`     DECIMAL(10,2)     NULL COMMENT 'Flat fee in XAF (fee_per_lead / featured)',
  `currency`        CHAR(3)           NOT NULL DEFAULT 'XAF',
  `billing_cycle`   ENUM('one_off','monthly','annual','per_lead') NULL
                    COMMENT 'How often the arrangement is charged / paid',

  -- Validity window
  `valid_from`      DATE              NULL,
  `valid_until`     DATE              NULL,

  -- Administrative
  `notes`           TEXT              NULL COMMENT 'Internal notes — not shown to providers',
  `is_active`       TINYINT(1)        NOT NULL DEFAULT 1,
  `created_by`      INT UNSIGNED      NULL COMMENT 'staff user who created this term',
  `created_at`      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_provider`  (`provider_id`),
  KEY `idx_service`   (`service_id`),
  KEY `idx_model`     (`model`),
  KEY `idx_active`    (`is_active`),

  CONSTRAINT `fk_bct_provider`
    FOREIGN KEY (`provider_id`) REFERENCES `bhc_service_providers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bct_service`
    FOREIGN KEY (`service_id`)  REFERENCES `bhc_services` (`id`) ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
