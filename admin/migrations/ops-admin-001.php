<?php
/**
 * Migration: Operations Admin role
 * Run once via browser as Super Admin.
 * Safe to re-run (uses IF NOT EXISTS / MODIFY COLUMN).
 */
require_once __DIR__ . '/../../includes/config.php';
requireAdmin();
header('Content-Type: text/plain');

$steps = [];
$errors = [];

function run(string $label, string $sql): void {
    global $steps, $errors;
    try {
        db()->exec($sql);
        $steps[] = "✓ $label";
    } catch (Exception $e) {
        $errors[] = "✗ $label: " . $e->getMessage();
    }
}

// 1. Extend role ENUM
run('Extend users.role ENUM to include operations_admin',
    "ALTER TABLE users MODIFY COLUMN role
     ENUM('user','sales_staff','creator','growth_partner','admin','operations_admin')
     NOT NULL DEFAULT 'user'"
);

// 2. Ops-admin restricted-action request table
run('Create ops_admin_requests table',
    "CREATE TABLE IF NOT EXISTS ops_admin_requests (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        requester_id  INT UNSIGNED NOT NULL COMMENT 'operations_admin user id',
        record_type   VARCHAR(50)  NOT NULL COMMENT 'e.g. user, listing, invoice',
        record_id     INT UNSIGNED NOT NULL,
        action        VARCHAR(100) NOT NULL COMMENT 'e.g. delete_listing, transfer_ownership',
        reason        TEXT         NOT NULL,
        status        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        reviewed_by   INT UNSIGNED NULL COMMENT 'admin user id',
        reviewed_at   DATETIME     NULL,
        review_note   TEXT         NULL,
        created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_status (status),
        INDEX idx_requester (requester_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

// 3. Unified ops-admin audit log
run('Create ops_admin_audit_log table',
    "CREATE TABLE IF NOT EXISTS ops_admin_audit_log (
        id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        actor_id    INT UNSIGNED NOT NULL COMMENT 'user who performed the action',
        actor_role  VARCHAR(30)  NOT NULL,
        action      VARCHAR(100) NOT NULL,
        record_type VARCHAR(50)  NULL,
        record_id   INT UNSIGNED NULL,
        detail      TEXT         NULL COMMENT 'JSON or human-readable summary',
        ip          VARCHAR(45)  NULL,
        created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_actor (actor_id),
        INDEX idx_action (action),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

echo "=== Migration: ops-admin-001 ===\n\n";
foreach ($steps  as $s) echo $s . "\n";
foreach ($errors as $e) echo $e . "\n";
echo "\n" . (empty($errors) ? "All done ✓" : count($errors) . " error(s) — check above") . "\n";
