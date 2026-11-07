-- marketplace-alter.sql
-- Run this ONCE in phpMyAdmin to fix column mismatches left by the old migration.
-- Safe to inspect before running — no data is deleted.

-- ── 1. Fix mp_payment_attempts ──────────────────────────────────────────────
-- Old columns:  channel, proof_file, status, verified_by, verified_at, verifier_note
-- PHP expects:  provider, proof_path, payment_state, reviewed_by_user_id, reviewed_at, reviewer_note

ALTER TABLE `mp_payment_attempts`
    CHANGE COLUMN `channel`       `provider`            VARCHAR(32)   NOT NULL DEFAULT '',
    CHANGE COLUMN `proof_file`    `proof_path`          VARCHAR(512)  NULL DEFAULT NULL,
    CHANGE COLUMN `status`        `payment_state`       VARCHAR(32)   NOT NULL DEFAULT 'PENDING',
    CHANGE COLUMN `verified_by`   `reviewed_by_user_id` INT           NULL DEFAULT NULL,
    CHANGE COLUMN `verified_at`   `reviewed_at`         DATETIME      NULL DEFAULT NULL,
    CHANGE COLUMN `verifier_note` `reviewer_note`       TEXT          NULL DEFAULT NULL;

-- ── 2. Fix marketplace_orders — add missing columns ──────────────────────────
-- Missing: payment_confirmed_at, confirmed_by_user_id

ALTER TABLE `marketplace_orders`
    ADD COLUMN `payment_confirmed_at`  DATETIME  NULL DEFAULT NULL AFTER `completed_at`,
    ADD COLUMN `confirmed_by_user_id`  INT       NULL DEFAULT NULL AFTER `payment_confirmed_at`;
