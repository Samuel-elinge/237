-- Run this if tables 1-6 already exist and only table 7 failed.
-- Fixed: removed DATE() function from KEY definition (not supported in MariaDB this way).

CREATE TABLE IF NOT EXISTS acquisition_commission_events (
    id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    business_id         INT UNSIGNED    NOT NULL,
    partner_id          INT UNSIGNED    NOT NULL,
    assignment_id       INT UNSIGNED    NULL,
    prospect_id         INT UNSIGNED    NULL,
    event_type          ENUM(
        'business_acquired',
        'plan_subscribed',
        'service_purchased',
        'subscription_renewed'
    )                                   NOT NULL,
    commission_rule_id  INT UNSIGNED    NULL,
    commission_id       INT UNSIGNED    NULL,
    gross_amount        DECIMAL(10,2)   NULL,
    commission_amount   DECIMAL(10,2)   NULL,
    currency            CHAR(3)         NOT NULL DEFAULT 'XAF',
    status              ENUM(
        'pending_approval',
        'approved',
        'paid',
        'rejected',
        'duplicate_check'
    )                                   NOT NULL DEFAULT 'pending_approval',
    recorded_by         INT UNSIGNED    NOT NULL,
    approved_by         INT UNSIGNED    NULL,
    approved_at         DATETIME        NULL,
    notes               TEXT            NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ace_business    (business_id),
    KEY idx_ace_partner     (partner_id),
    KEY idx_ace_event       (event_type),
    KEY idx_ace_status      (status),
    KEY idx_ace_dedup       (business_id, partner_id, event_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
