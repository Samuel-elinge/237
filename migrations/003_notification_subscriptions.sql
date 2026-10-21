-- ============================================================
-- Migration 003: Optional Offers & Events Notification Subscription
-- Spec: v1.0 — 21 Oct 2026
-- ============================================================

-- ── 1. Subscribers ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `notification_subscribers` (
    `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `email_normalised`  VARCHAR(255)    NOT NULL COMMENT 'lowercase trimmed canonical email',
    `email_display`     VARCHAR(255)    NOT NULL COMMENT 'original casing supplied by user',
    `account_user_id`   INT UNSIGNED    NULL     DEFAULT NULL COMMENT 'FK to users.id when subscriber has an account',
    `language`          ENUM('en','fr') NOT NULL DEFAULT 'en',
    `status`            ENUM('pending','active','unsubscribed','suppressed','bounced')
                                        NOT NULL DEFAULT 'pending',
    `email_verified_at` DATETIME        NULL     DEFAULT NULL,
    `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `last_activity_at`  DATETIME        NULL     DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_email_normalised` (`email_normalised`),
    KEY `idx_status` (`status`),
    KEY `idx_account_user` (`account_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Preferences (one row per subscriber) ──────────────────
CREATE TABLE IF NOT EXISTS `notification_preferences` (
    `subscriber_id`        INT UNSIGNED NOT NULL,
    `special_offers_enabled` TINYINT(1)  NOT NULL DEFAULT 1,
    `events_enabled`         TINYINT(1)  NOT NULL DEFAULT 1,
    `updated_at`             DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`subscriber_id`),
    CONSTRAINT `fk_np_subscriber`
        FOREIGN KEY (`subscriber_id`)
        REFERENCES `notification_subscribers` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. Consent events (append-only audit) ────────────────────
CREATE TABLE IF NOT EXISTS `notification_consent_events` (
    `id`                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `subscriber_id`       INT UNSIGNED    NOT NULL,
    `event_type`          ENUM(
                            'consent_given',
                            'verified',
                            'preference_changed',
                            'unsubscribed',
                            'resubscribed',
                            'suppression_applied',
                            'verification_resent'
                          )               NOT NULL,
    `consent_version`     VARCHAR(16)     NOT NULL DEFAULT '1.0',
    `consent_text_snapshot` TEXT          NULL COMMENT 'exact wording shown at consent time',
    `selected_interests`  VARCHAR(64)     NULL COMMENT 'e.g. offers,events',
    `language`            ENUM('en','fr') NOT NULL DEFAULT 'en',
    `source`              VARCHAR(64)     NOT NULL DEFAULT 'homepage'
                                          COMMENT 'homepage|offers|events|admin|api',
    `verification_method` VARCHAR(32)     NULL COMMENT 'email_token|account_verified',
    `request_ip_hash`     VARCHAR(64)     NULL COMMENT 'SHA-256 of IP for evidence; never raw IP',
    `occurred_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_nce_subscriber` (`subscriber_id`),
    KEY `idx_nce_type` (`event_type`),
    CONSTRAINT `fk_nce_subscriber`
        FOREIGN KEY (`subscriber_id`)
        REFERENCES `notification_subscribers` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. Verification tokens ───────────────────────────────────
CREATE TABLE IF NOT EXISTS `notification_verification_tokens` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `subscriber_id` INT UNSIGNED NOT NULL,
    `token_hash`    VARCHAR(64)  NOT NULL COMMENT 'SHA-256 hex of the raw random token',
    `expires_at`    DATETIME     NOT NULL,
    `used_at`       DATETIME     NULL DEFAULT NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_nvt_subscriber` (`subscriber_id`),
    KEY `idx_nvt_token`      (`token_hash`),
    CONSTRAINT `fk_nvt_subscriber`
        FOREIGN KEY (`subscriber_id`)
        REFERENCES `notification_subscribers` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 5. Suppressions ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `notification_suppressions` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email_normalised` VARCHAR(255) NOT NULL,
    `reason`           ENUM('unsubscribe','spam_complaint','hard_bounce','admin_compliance')
                                    NOT NULL,
    `source`           VARCHAR(64)  NOT NULL DEFAULT 'system',
    `active`           TINYINT(1)  NOT NULL DEFAULT 1,
    `created_at`       DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ns_email`  (`email_normalised`),
    KEY `idx_ns_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 6. Campaigns ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `notification_campaigns` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`             VARCHAR(255) NOT NULL,
    `type`             ENUM('special_offers','events','general') NOT NULL DEFAULT 'general',
    `language`         ENUM('en','fr') NOT NULL DEFAULT 'en',
    `subject`          VARCHAR(255) NOT NULL,
    `preview_text`     VARCHAR(255) NULL,
    `body_html`        MEDIUMTEXT   NOT NULL,
    `status`           ENUM('draft','ready','scheduled','sending','paused','completed','cancelled','failed')
                                    NOT NULL DEFAULT 'draft',
    `scheduled_at`     DATETIME     NULL DEFAULT NULL,
    `sent_at`          DATETIME     NULL DEFAULT NULL,
    `created_by`       INT UNSIGNED NOT NULL COMMENT 'admin user id',
    `test_sent`        TINYINT(1)  NOT NULL DEFAULT 0,
    `approved_by`      INT UNSIGNED NULL DEFAULT NULL,
    `recipient_count`  INT UNSIGNED NULL DEFAULT NULL,
    `created_at`       DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_nc_status` (`status`),
    KEY `idx_nc_scheduled` (`scheduled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 7. Campaign segments (audience filters) ──────────────────
CREATE TABLE IF NOT EXISTS `notification_campaign_segments` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campaign_id`      INT UNSIGNED NOT NULL,
    `filter_interests` SET('special_offers','events') NULL COMMENT 'NULL = all interests',
    `filter_language`  ENUM('en','fr') NULL COMMENT 'NULL = all languages',
    `filter_location`  VARCHAR(64)  NULL COMMENT 'location slug or NULL for all',
    PRIMARY KEY (`id`),
    KEY `idx_ncs_campaign` (`campaign_id`),
    CONSTRAINT `fk_ncs_campaign`
        FOREIGN KEY (`campaign_id`)
        REFERENCES `notification_campaigns` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 8. Campaign recipients (materialised at send time) ───────
CREATE TABLE IF NOT EXISTS `notification_campaign_recipients` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campaign_id`   INT UNSIGNED    NOT NULL,
    `subscriber_id` INT UNSIGNED    NOT NULL,
    `email`         VARCHAR(255)    NOT NULL COMMENT 'copy at send time',
    `status`        ENUM('queued','sent','failed','skipped','unsubscribed')
                                    NOT NULL DEFAULT 'queued',
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ncr_campaign_sub` (`campaign_id`, `subscriber_id`),
    KEY `idx_ncr_status` (`status`),
    CONSTRAINT `fk_ncr_campaign`
        FOREIGN KEY (`campaign_id`)
        REFERENCES `notification_campaigns` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 9. Delivery events ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS `notification_delivery_events` (
    `id`           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `campaign_id`  INT UNSIGNED     NOT NULL,
    `recipient_id` BIGINT UNSIGNED  NOT NULL,
    `event_type`   ENUM('sent','delivered','opened','clicked','bounced','complained','unsubscribed')
                                    NOT NULL,
    `provider_msg_id` VARCHAR(255)  NULL COMMENT 'SMTP/provider message ID',
    `detail`       TEXT             NULL,
    `occurred_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_nde_campaign`   (`campaign_id`),
    KEY `idx_nde_recipient`  (`recipient_id`),
    KEY `idx_nde_type`       (`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 10. Audit log ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `notification_audit_logs` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `actor_id`    INT UNSIGNED  NULL DEFAULT NULL COMMENT 'admin user id or NULL for system',
    `actor_type`  ENUM('admin','system','subscriber') NOT NULL DEFAULT 'system',
    `action`      VARCHAR(64)   NOT NULL,
    `object_type` VARCHAR(64)   NULL,
    `object_id`   INT UNSIGNED  NULL,
    `detail`      TEXT          NULL,
    `occurred_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_nal_actor`  (`actor_id`),
    KEY `idx_nal_action` (`action`),
    KEY `idx_nal_object` (`object_type`, `object_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
