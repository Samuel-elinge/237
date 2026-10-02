-- ============================================================
-- 237biz Growth Partner Phase 5 Migration
-- Task 61: Strategic Partner Architecture
-- ============================================================

-- Add strategic columns to partner_profiles
ALTER TABLE partner_profiles
    ADD COLUMN IF NOT EXISTS is_strategic        TINYINT(1)     NOT NULL DEFAULT 0 AFTER tier,
    ADD COLUMN IF NOT EXISTS exclusive_territory  VARCHAR(255)   NULL     AFTER is_strategic,
    ADD COLUMN IF NOT EXISTS revenue_commitment   DECIMAL(10,2)  NULL     AFTER exclusive_territory,
    ADD COLUMN IF NOT EXISTS account_manager_id   INT            NULL     REFERENCES users(id) AFTER revenue_commitment,
    ADD COLUMN IF NOT EXISTS strategic_note       TEXT           NULL     AFTER account_manager_id,
    ADD COLUMN IF NOT EXISTS strategic_since      DATETIME       NULL     AFTER strategic_note;

-- Quarterly Business Reviews
CREATE TABLE IF NOT EXISTS partner_qbrs (
    id           INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    partner_id   INT          NOT NULL REFERENCES partner_profiles(id),
    scheduled_at DATETIME     NOT NULL,
    notes        TEXT         NULL,
    status       ENUM('scheduled','completed','cancelled') NOT NULL DEFAULT 'scheduled',
    outcome      TEXT         NULL,
    created_by   INT          NULL REFERENCES users(id),
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_partner_qbrs_partner (partner_id),
    INDEX idx_partner_qbrs_status  (status)
);

-- Revenue targets (per partner per year)
CREATE TABLE IF NOT EXISTS partner_revenue_targets (
    id            INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
    partner_id    INT           NOT NULL REFERENCES partner_profiles(id),
    period        VARCHAR(10)   NOT NULL COMMENT 'Year e.g. 2026',
    target_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    set_by        INT           NULL REFERENCES users(id),
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_partner_period (partner_id, period),
    INDEX idx_revenue_targets_partner (partner_id)
);

-- Partner performance notes (used by quality-monitoring / partner-performance)
CREATE TABLE IF NOT EXISTS partner_performance_notes (
    id            INT      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    partner_id    INT      NOT NULL REFERENCES partner_profiles(id),
    admin_user_id INT      NOT NULL REFERENCES users(id),
    note          TEXT     NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_perf_notes_partner (partner_id)
);

-- Partner complaints (used by quality-monitoring)
CREATE TABLE IF NOT EXISTS partner_complaints (
    id          INT      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    partner_id  INT      NOT NULL REFERENCES partner_profiles(id),
    reporter_id INT      NULL REFERENCES users(id),
    subject     VARCHAR(255) NOT NULL,
    description TEXT     NULL,
    status      ENUM('open','resolved','dismissed') NOT NULL DEFAULT 'open',
    resolution  TEXT     NULL,
    resolved_at DATETIME NULL,
    resolved_by INT      NULL REFERENCES users(id),
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_complaints_partner (partner_id),
    INDEX idx_complaints_status  (status)
);

-- Flagging columns on partner_profiles
ALTER TABLE partner_profiles
    ADD COLUMN IF NOT EXISTS flagged     TINYINT(1)   NOT NULL DEFAULT 0 AFTER strategic_since,
    ADD COLUMN IF NOT EXISTS flag_reason VARCHAR(500) NULL             AFTER flagged,
    ADD COLUMN IF NOT EXISTS flagged_at  DATETIME     NULL             AFTER flag_reason;

-- Performance score / tier columns
ALTER TABLE partner_profiles
    ADD COLUMN IF NOT EXISTS performance_score   TINYINT UNSIGNED NULL AFTER flagged_at,
    ADD COLUMN IF NOT EXISTS score_updated_at    DATETIME         NULL AFTER performance_score,
    ADD COLUMN IF NOT EXISTS score_note          VARCHAR(500)     NULL AFTER score_updated_at;

-- Partner message threads (used by communication hub)
CREATE TABLE IF NOT EXISTS partner_message_threads (
    id             INT      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    partner_id     INT      NOT NULL REFERENCES partner_profiles(id),
    to_partner_id  INT      NULL REFERENCES partner_profiles(id),
    subject        VARCHAR(255) NOT NULL,
    last_message_at DATETIME NULL,
    archived_by    INT      NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_threads_partner    (partner_id),
    INDEX idx_threads_to_partner (to_partner_id)
);

-- Partner messages
CREATE TABLE IF NOT EXISTS partner_messages (
    id             INT      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    thread_id      INT      NOT NULL REFERENCES partner_message_threads(id),
    sender_user_id INT      NOT NULL REFERENCES users(id),
    body           TEXT     NOT NULL,
    read_at        DATETIME NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_messages_thread    (thread_id),
    INDEX idx_messages_sender    (sender_user_id),
    INDEX idx_messages_read      (read_at)
);

-- Broadcast messages (admin announcements to all partners)
CREATE TABLE IF NOT EXISTS broadcast_messages (
    id         INT      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    subject    VARCHAR(255) NOT NULL,
    body       TEXT     NOT NULL,
    sent_by    INT      NULL REFERENCES users(id),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Partner QBR index on strategic_since
ALTER TABLE partner_profiles
    ADD INDEX IF NOT EXISTS idx_partner_profiles_strategic (is_strategic);
