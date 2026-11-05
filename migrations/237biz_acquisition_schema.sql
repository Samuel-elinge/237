-- =============================================================
-- 237biz – Business Acquisition Module – Database Schema
-- Stage A foundation + Stage B tables (all 7 new entities)
-- Run once on biz97h_app. Safe to re-run: CREATE TABLE IF NOT EXISTS.
-- DO NOT touch: businesses, users, partners, partner_business_assignments,
--               tasks, notifications, audit_logs, commissions, commission_rules,
--               business_growth_plans, promo_codes, promotion_orders,
--               promotion_products
-- =============================================================

-- ---------------------------------------------------------------
-- 1. BUSINESS PROSPECTS
--    One row per (partner × business) prospecting relationship.
--    References the EXISTING business record by business_id.
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS business_prospects (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    business_id     INT UNSIGNED    NOT NULL,                      -- FK → businesses.id
    partner_id      INT UNSIGNED    NOT NULL,                      -- FK → partners.id (the Growth Partner)
    added_by        INT UNSIGNED    NOT NULL,                      -- FK → users.id (always the partner's user account)
    status          ENUM(
        'new',
        'contacted',
        'follow_up_required',
        'interested',
        'invitation_sent',
        'awaiting_owner_response',
        'awaiting_authorisation',
        'awaiting_assignment',
        'converted',
        'not_interested',
        'unreachable',
        'rejected',
        'closed'
    )                               NOT NULL DEFAULT 'new',
    acquisition_source  VARCHAR(100)    NULL,                      -- e.g. 'partner_search', 'admin_allocation'
    contact_person  VARCHAR(150)    NULL,                          -- lawfully provided contact name
    contact_details VARCHAR(255)    NULL,                          -- authorised contact info
    next_followup   DATE            NULL,
    last_contact    DATETIME        NULL,
    notes           TEXT            NULL,
    conversion_outcome VARCHAR(100) NULL,                          -- populated on close/convert
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- A partner can only have ONE active prospect record per business
    UNIQUE KEY uq_prospect_partner_business (partner_id, business_id),
    KEY idx_bp_business     (business_id),
    KEY idx_bp_partner      (partner_id),
    KEY idx_bp_status       (status),
    KEY idx_bp_followup     (next_followup)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------
-- 2. BUSINESS PROSPECT CONTACTS
--    Log of every contact attempt against a prospect.
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS business_prospect_contacts (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    prospect_id     INT UNSIGNED    NOT NULL,                      -- FK → business_prospects.id
    business_id     INT UNSIGNED    NOT NULL,                      -- denormalised for indexed queries
    partner_id      INT UNSIGNED    NOT NULL,                      -- denormalised for indexed queries
    contacted_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    contact_method  ENUM(
        'telephone',
        'whatsapp',
        'email',
        'in_person',
        'social_media',
        'other'
    )                               NOT NULL,
    outcome         ENUM(
        'no_answer',
        'spoke_to_owner',
        'interested',
        'not_interested',
        'requested_more_info',
        'appointment_arranged',
        'follow_up_required'
    )                               NOT NULL,
    notes           TEXT            NULL,
    next_followup   DATE            NULL,
    created_by      INT UNSIGNED    NOT NULL,                      -- FK → users.id
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_bpc_prospect    (prospect_id),
    KEY idx_bpc_business    (business_id),
    KEY idx_bpc_partner     (partner_id),
    KEY idx_bpc_contacted   (contacted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------
-- 3. BUSINESS INVITATIONS
--    Secure token sent to an unclaimed business owner to claim
--    their existing 237biz listing. Does NOT create a new listing.
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS business_invitations (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    business_id     INT UNSIGNED    NOT NULL,                      -- FK → businesses.id (existing listing)
    prospect_id     INT UNSIGNED    NULL,                          -- FK → business_prospects.id (nullable if admin-initiated)
    partner_id      INT UNSIGNED    NULL,                          -- FK → partners.id (nullable if admin-initiated)
    initiated_by    INT UNSIGNED    NOT NULL,                      -- FK → users.id
    token           CHAR(64)        NOT NULL,                      -- cryptographically random, URL-safe
    token_expires   DATETIME        NOT NULL,                      -- typically now + 7 days
    delivery_method ENUM(
        'email',
        'whatsapp',
        'in_platform',
        'other'
    )                               NOT NULL DEFAULT 'email',
    recipient_email VARCHAR(255)    NULL,
    status          ENUM(
        'pending',
        'sent',
        'delivered',
        'accepted',
        'expired',
        'cancelled',
        'disputed'
    )                               NOT NULL DEFAULT 'pending',
    sent_at         DATETIME        NULL,
    accepted_at     DATETIME        NULL,
    accepted_by     INT UNSIGNED    NULL,                          -- FK → users.id of the person who accepted
    notes           TEXT            NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_invitation_token (token),
    KEY idx_bi_business     (business_id),
    KEY idx_bi_partner      (partner_id),
    KEY idx_bi_status       (status),
    KEY idx_bi_expires      (token_expires)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------
-- 4. BUSINESS OWNER AUTHORISATIONS
--    Explicit owner approval for a Growth Partner to manage their business.
--    Required BEFORE any assignment is activated.
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS business_owner_authorisations (
    id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    business_id         INT UNSIGNED    NOT NULL,                  -- FK → businesses.id
    owner_user_id       INT UNSIGNED    NOT NULL,                  -- FK → users.id (business owner)
    partner_id          INT UNSIGNED    NOT NULL,                  -- FK → partners.id
    requested_by        INT UNSIGNED    NOT NULL,                  -- FK → users.id (partner's user, or admin)
    status              ENUM(
        'pending',
        'approved',
        'declined',
        'revoked',
        'expired'
    )                                   NOT NULL DEFAULT 'pending',
    requested_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    responded_at        DATETIME        NULL,
    revoked_at          DATETIME        NULL,
    revoked_by          INT UNSIGNED    NULL,                      -- FK → users.id
    -- What the partner requested
    requested_services  TEXT            NULL,                      -- JSON array of service types
    requested_permissions TEXT          NULL,                      -- JSON array of permission keys
    proposed_start      DATE            NULL,
    terms_agreed        TINYINT(1)      NOT NULL DEFAULT 0,
    -- Consent & audit trail (§18 mandatory)
    consent_timestamp   DATETIME        NULL,                      -- when owner approved
    consent_ip          VARCHAR(45)     NULL,                      -- IPv4/IPv6
    notes               TEXT            NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_boa_business    (business_id),
    KEY idx_boa_owner       (owner_user_id),
    KEY idx_boa_partner     (partner_id),
    KEY idx_boa_status      (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------
-- 5. BUSINESS ASSIGNMENT REQUESTS
--    Formal request to activate a partner_business_assignments record
--    after owner authorisation. May require admin approval.
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS business_assignment_requests (
    id                      INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    business_id             INT UNSIGNED    NOT NULL,              -- FK → businesses.id
    partner_id              INT UNSIGNED    NOT NULL,              -- FK → partners.id
    authorisation_id        INT UNSIGNED    NOT NULL,              -- FK → business_owner_authorisations.id
    prospect_id             INT UNSIGNED    NULL,                  -- FK → business_prospects.id
    assignment_source       ENUM(
        'partner_acquisition',
        'admin_allocation',
        'business_request',
        'partner_marketplace',
        'other'
    )                                       NOT NULL DEFAULT 'partner_acquisition',
    status                  ENUM(
        'pending_owner_approval',
        'pending_admin_approval',
        'active',
        'suspended',
        'revoked',
        'completed'
    )                                       NOT NULL DEFAULT 'pending_admin_approval',
    requested_by            INT UNSIGNED    NOT NULL,              -- FK → users.id
    approved_by             INT UNSIGNED    NULL,                  -- FK → users.id (admin who approved)
    approved_at             DATETIME        NULL,
    rejected_by             INT UNSIGNED    NULL,
    rejected_at             DATETIME        NULL,
    rejection_reason        TEXT            NULL,
    start_date              DATE            NULL,
    end_date                DATE            NULL,
    -- Activated assignment (populated on approval)
    assignment_id           INT UNSIGNED    NULL,                  -- FK → partner_business_assignments.id
    notes                   TEXT            NULL,
    created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_bar_business    (business_id),
    KEY idx_bar_partner     (partner_id),
    KEY idx_bar_status      (status),
    KEY idx_bar_auth        (authorisation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------
-- 6. ACQUISITION ACTIVITY LOGS
--    Immutable audit log for all acquisition-related events (§18 mandatory).
--    Separate from ops_admin_audit_log — covers partner-side events too.
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS acquisition_activity_logs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_type      VARCHAR(80)     NOT NULL,
    -- e.g. 'prospect_added', 'contact_recorded', 'invitation_sent',
    --      'owner_authorised', 'assignment_activated', 'access_revoked'
    business_id     INT UNSIGNED    NOT NULL,
    partner_id      INT UNSIGNED    NULL,
    actor_user_id   INT UNSIGNED    NOT NULL,                      -- FK → users.id (who did it)
    subject_type    VARCHAR(50)     NULL,                          -- e.g. 'business_prospect', 'invitation'
    subject_id      INT UNSIGNED    NULL,                          -- PK of the subject row
    old_value       JSON            NULL,                          -- before state (redacted where sensitive)
    new_value       JSON            NULL,                          -- after state
    ip_address      VARCHAR(45)     NULL,
    user_agent      VARCHAR(500)    NULL,
    notes           TEXT            NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_aal_business    (business_id),
    KEY idx_aal_partner     (partner_id),
    KEY idx_aal_actor       (actor_user_id),
    KEY idx_aal_event       (event_type),
    KEY idx_aal_created     (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------
-- 7. ACQUISITION COMMISSION EVENTS
--    Records qualifying commission events (§16).
--    DO NOT auto-create on prospect_added — only on qualifying events.
--    Reuses existing commissions / commission_rules tables for payment.
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS acquisition_commission_events (
    id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    business_id         INT UNSIGNED    NOT NULL,                  -- FK → businesses.id
    partner_id          INT UNSIGNED    NOT NULL,                  -- FK → partners.id
    assignment_id       INT UNSIGNED    NULL,                      -- FK → partner_business_assignments.id
    prospect_id         INT UNSIGNED    NULL,                      -- FK → business_prospects.id
    event_type          ENUM(
        'business_acquired',
        'plan_subscribed',
        'service_purchased',
        'subscription_renewed'
    )                                   NOT NULL,
    commission_rule_id  INT UNSIGNED    NULL,                      -- FK → commission_rules.id
    commission_id       INT UNSIGNED    NULL,                      -- FK → commissions.id (once created)
    gross_amount        DECIMAL(10,2)   NULL,                      -- qualifying revenue amount
    commission_amount   DECIMAL(10,2)   NULL,                      -- calculated commission
    currency            CHAR(3)         NOT NULL DEFAULT 'XAF',
    status              ENUM(
        'pending_approval',
        'approved',
        'paid',
        'rejected',
        'duplicate_check'
    )                                   NOT NULL DEFAULT 'pending_approval',
    recorded_by         INT UNSIGNED    NOT NULL,                  -- FK → users.id
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
    -- Prevent exact duplicate commission events on same day for same trigger
    KEY idx_ace_dedup       (business_id, partner_id, event_type, DATE(created_at))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------
-- NOTES FOR DBA / DEVELOPER
-- ---------------------------------------------------------------
-- Foreign key constraints are intentionally omitted here because
-- the existing 237biz tables may use MyISAM or have no FK support
-- configured. Add FOREIGN KEY constraints manually if the schema
-- supports them. Referential integrity is enforced at the
-- application layer (PHP).
--
-- Column businesses.owner_user_id (or equivalent) must exist and
-- be populated for the claiming/authorisation workflow. If the
-- column is named differently, update the PHP accordingly.
--
-- The existing partner_business_assignments table is NOT altered.
-- business_assignment_requests.assignment_id links to it once the
-- request is approved and the assignment row is created/activated.
-- =============================================================
