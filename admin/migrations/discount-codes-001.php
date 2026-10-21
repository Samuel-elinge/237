<?php
/**
 * Migration: Universal Discount Code System
 * Creates offer_claims and discount_codes tables.
 * Also adds verify_token_created_at to users, and user_id to listing_bookings.
 *
 * Run once via browser as Super Admin:
 *   https://237biz.net/admin/migrations/discount-codes-001.php?t=dc237
 * Safe to re-run (IF NOT EXISTS / ADD COLUMN IF NOT EXISTS).
 */
require_once __DIR__ . '/../../includes/config.php';
requireAdmin();
if (($_GET['t'] ?? '') !== 'dc237') { http_response_code(403); die('Forbidden'); }
header('Content-Type: text/plain');

$steps  = [];
$errors = [];

function mrun(string $label, string $sql): void {
    global $steps, $errors;
    try {
        db()->exec($sql);
        $steps[] = "✓ $label";
    } catch (Exception $e) {
        $errors[] = "✗ $label: " . $e->getMessage();
    }
}

// ── 1. users: add verify_token_created_at ────────────────────────────
mrun('users: add verify_token_created_at',
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS verify_token_created_at DATETIME NULL DEFAULT NULL AFTER verify_token"
);

// ── 2. listing_bookings: add user_id ─────────────────────────────────
mrun('listing_bookings: add user_id',
    "ALTER TABLE listing_bookings ADD COLUMN IF NOT EXISTS user_id INT UNSIGNED NULL DEFAULT NULL AFTER listing_id"
);
mrun('listing_bookings: index on user_id',
    "ALTER TABLE listing_bookings ADD INDEX IF NOT EXISTS idx_user_id (user_id)"
);

// ── 3. offer_claims table ────────────────────────────────────────────
// One row per customer-offer claim. Enforces per-user claim limit.
mrun('Create offer_claims table',
    "CREATE TABLE IF NOT EXISTS offer_claims (
        id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        offer_id      INT UNSIGNED    NOT NULL,
        user_id       INT UNSIGNED    NOT NULL,
        claimed_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_offer_user (offer_id, user_id),
        INDEX idx_user  (user_id),
        INDEX idx_offer (offer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// ── 4. discount_codes table ──────────────────────────────────────────
// One unique code generated per claim. Format: 237-XXXXXX (hex).
mrun('Create discount_codes table',
    "CREATE TABLE IF NOT EXISTS discount_codes (
        id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code          VARCHAR(20)     NOT NULL,
        offer_id      INT UNSIGNED    NOT NULL,
        claim_id      BIGINT UNSIGNED NOT NULL,
        user_id       INT UNSIGNED    NOT NULL,
        status        ENUM('active','redeemed','expired','cancelled')
                                      NOT NULL DEFAULT 'active',
        redeemed_at   DATETIME        NULL,
        redeemed_by   INT UNSIGNED    NULL COMMENT 'listing user_id who redeemed',
        created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_code       (code),
        UNIQUE KEY uq_claim      (claim_id),
        INDEX idx_offer          (offer_id),
        INDEX idx_user           (user_id),
        INDEX idx_status_offer   (status, offer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// ── 5. offers: add max_claims_per_user ──────────────────────────────
mrun('offers: add max_claims_per_user (default 1)',
    "ALTER TABLE offers ADD COLUMN IF NOT EXISTS max_claims_per_user TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER max_redemptions"
);

// ── Output ───────────────────────────────────────────────────────────
echo implode("\n", $steps) . "\n";
if ($errors) {
    echo "\nWARNINGS / ERRORS:\n" . implode("\n", $errors) . "\n";
}
echo "\nDone. You may delete this file after verifying above.\n";
