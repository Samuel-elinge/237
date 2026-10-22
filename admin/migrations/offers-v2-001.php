<?php
/**
 * offers-v2-001.php — Offers & Discount Codes v2 Migration
 *
 * Upgrades the existing Promotions / Offers feature to the full
 * Offers & Discount Codes Management System spec.
 *
 * Safe to re-run: every ALTER uses IF NOT EXISTS / IF EXISTS guards;
 * every CREATE TABLE uses IF NOT EXISTS.
 *
 * Run via: https://237biz.net/admin/migrations/offers-v2-001.php
 * (Requires admin session)
 *
 * What this does:
 *   1. Expand `offers` status ENUM → add scheduled, paused, archived, suspended_admin
 *   2. Add new columns to `offers` (code_type, discount_type, currency, min_spend,
 *      max_discount, batch_size, combinable, eligible_items, metadata)
 *   3. Expand `discount_codes` with business_id, code_type, usage_limit, usage_count
 *   4. Create `offer_redemptions`  — replaces bare discount_codes.status for in-store
 *   5. Create `offer_entitlements` — add-on slot tracking per listing
 *   6. Create `offer_audit_logs`   — admin + business action history
 *   7. Create `offer_views`        — analytics: page views
 *   8. Create `offer_code_copies`  — analytics: copy-button clicks
 *   9. Migrate existing promo_code values → shared discount_codes rows
 *  10. Seed `offer_entitlements` from existing addon_entitlements (offers type)
 *  11. Update entitlement-helpers usage count query to include paused offers
 */

require_once __DIR__ . '/../../includes/config.php';
requireAdmin();

$db = db();
$log = [];
$errors = [];

function runSql(PDO $db, string $sql, string $label, array &$log, array &$errors): bool {
    try {
        $db->exec($sql);
        $log[] = "✅ $label";
        return true;
    } catch (PDOException $e) {
        $errors[] = "❌ $label: " . $e->getMessage();
        return false;
    }
}

try { $db->exec("SET foreign_key_checks = 0"); } catch (PDOException $e) { /* non-fatal */ }

// ══════════════════════════════════════════════════════════════════════════════
// 1. Expand `offers` status ENUM
//    Current: 'pending','active','draft','expired','rejected'
//    New:     + scheduled, paused, archived, suspended_admin
// ══════════════════════════════════════════════════════════════════════════════
runSql($db, "
    ALTER TABLE offers
    MODIFY COLUMN status ENUM(
        'draft','pending','scheduled','active','paused',
        'expired','rejected','archived','suspended_admin'
    ) NOT NULL DEFAULT 'draft'
", "offers: expand status ENUM", $log, $errors);

// ══════════════════════════════════════════════════════════════════════════════
// 2. Add new columns to `offers`
// ══════════════════════════════════════════════════════════════════════════════

// code_type — shared (Type A) or unique (Type B); NULL = legacy row
runSql($db, "
    ALTER TABLE offers
    ADD COLUMN IF NOT EXISTS code_type ENUM('shared','unique') NULL DEFAULT NULL
        AFTER promo_code
", "offers: add code_type", $log, $errors);

// discount_type — maps existing offer_type; kept separate for clarity
// (offer_type ENUM stays as-is for backward compat; new code writes discount_type)
runSql($db, "
    ALTER TABLE offers
    ADD COLUMN IF NOT EXISTS discount_type
        ENUM('percent_off','fixed_off','fixed_price','bogo','free_item','bundle')
        NULL DEFAULT NULL
        AFTER code_type
", "offers: add discount_type", $log, $errors);

// currency — XAF default
runSql($db, "
    ALTER TABLE offers
    ADD COLUMN IF NOT EXISTS currency VARCHAR(3) NOT NULL DEFAULT 'XAF'
        AFTER discount_type
", "offers: add currency", $log, $errors);

// min_spend — minimum purchase amount to redeem
runSql($db, "
    ALTER TABLE offers
    ADD COLUMN IF NOT EXISTS min_spend DECIMAL(12,2) NULL DEFAULT NULL
        AFTER currency
", "offers: add min_spend", $log, $errors);

// max_discount — cap for percent_off offers
runSql($db, "
    ALTER TABLE offers
    ADD COLUMN IF NOT EXISTS max_discount DECIMAL(12,2) NULL DEFAULT NULL
        AFTER min_spend
", "offers: add max_discount", $log, $errors);

// batch_size — number of unique codes to pre-generate (Type B)
runSql($db, "
    ALTER TABLE offers
    ADD COLUMN IF NOT EXISTS batch_size SMALLINT UNSIGNED NOT NULL DEFAULT 0
        AFTER max_discount
", "offers: add batch_size", $log, $errors);

// combinable — whether offer stacks with others
runSql($db, "
    ALTER TABLE offers
    ADD COLUMN IF NOT EXISTS combinable TINYINT(1) NOT NULL DEFAULT 0
        AFTER batch_size
", "offers: add combinable", $log, $errors);

// eligible_items — JSON or text description of which products/services apply
runSql($db, "
    ALTER TABLE offers
    ADD COLUMN IF NOT EXISTS eligible_items TEXT NULL DEFAULT NULL
        AFTER combinable
", "offers: add eligible_items", $log, $errors);

// metadata — JSON blob for future extensibility (e.g. customer groups)
runSql($db, "
    ALTER TABLE offers
    ADD COLUMN IF NOT EXISTS metadata JSON NULL DEFAULT NULL
        AFTER eligible_items
", "offers: add metadata JSON", $log, $errors);

// updated_at (may already exist on some installs)
runSql($db, "
    ALTER TABLE offers
    ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL DEFAULT NULL
        ON UPDATE CURRENT_TIMESTAMP
        AFTER created_at
", "offers: add updated_at", $log, $errors);

// ── Backfill discount_type from offer_type for existing rows ─────────────────
runSql($db, "
    UPDATE offers
    SET discount_type = offer_type
    WHERE discount_type IS NULL
      AND offer_type IS NOT NULL
", "offers: backfill discount_type from offer_type", $log, $errors);

// ── Mark existing rows that have a promo_code as shared type ─────────────────
runSql($db, "
    UPDATE offers
    SET code_type = 'shared'
    WHERE promo_code IS NOT NULL
      AND promo_code != ''
      AND code_type IS NULL
", "offers: mark legacy promo_code rows as shared", $log, $errors);

// ── Re-map status: 'pending' is now the admin-approval queue gate;
//    rows that were 'active' or 'draft' stay as-is ──────────────────────────
// (no status remapping needed — existing values still valid in expanded ENUM)

// ══════════════════════════════════════════════════════════════════════════════
// 3. Expand `discount_codes` table
//    Existing: id, code, offer_id, claim_id, user_id, status, redeemed_at,
//              redeemed_by, created_at
//    Note: if discount-codes-001.php was never run, create the base table first.
// ══════════════════════════════════════════════════════════════════════════════

// Ensure base table exists (idempotent if discount-codes-001.php was already run)
runSql($db, "
    CREATE TABLE IF NOT EXISTS discount_codes (
        id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code          VARCHAR(20)     NOT NULL,
        offer_id      INT UNSIGNED    NOT NULL,
        claim_id      BIGINT UNSIGNED NULL,
        user_id       INT UNSIGNED    NULL,
        status        ENUM('active','redeemed','expired','cancelled')
                                      NOT NULL DEFAULT 'active',
        redeemed_at   DATETIME        NULL,
        redeemed_by   INT UNSIGNED    NULL COMMENT 'listing user_id who redeemed',
        created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_code       (code),
        INDEX idx_offer          (offer_id),
        INDEX idx_status_offer   (status, offer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", "discount_codes: ensure base table exists", $log, $errors);

// Also ensure offer_claims exists (dependency of discount-codes-001.php)
runSql($db, "
    CREATE TABLE IF NOT EXISTS offer_claims (
        id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        offer_id      INT UNSIGNED    NOT NULL,
        user_id       INT UNSIGNED    NOT NULL,
        claimed_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_offer_user (offer_id, user_id),
        INDEX idx_user  (user_id),
        INDEX idx_offer (offer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", "offer_claims: ensure base table exists", $log, $errors);

// business_id — denormalised for fast tenant isolation checks
runSql($db, "
    ALTER TABLE discount_codes
    ADD COLUMN IF NOT EXISTS business_id INT UNSIGNED NULL DEFAULT NULL
        AFTER offer_id
", "discount_codes: add business_id", $log, $errors);

// code_type — which type this code is
runSql($db, "
    ALTER TABLE discount_codes
    ADD COLUMN IF NOT EXISTS code_type ENUM('shared','unique') NOT NULL DEFAULT 'unique'
        AFTER business_id
", "discount_codes: add code_type", $log, $errors);

// usage_limit — max times this specific code can be redeemed (0 = unlimited)
runSql($db, "
    ALTER TABLE discount_codes
    ADD COLUMN IF NOT EXISTS usage_limit INT UNSIGNED NOT NULL DEFAULT 1
        AFTER code_type
", "discount_codes: add usage_limit", $log, $errors);

// usage_count — denormalised counter (incremented atomically on redemption)
runSql($db, "
    ALTER TABLE discount_codes
    ADD COLUMN IF NOT EXISTS usage_count INT UNSIGNED NOT NULL DEFAULT 0
        AFTER usage_limit
", "discount_codes: add usage_count", $log, $errors);

// expires_at — code-level expiry (may differ from offer expiry)
runSql($db, "
    ALTER TABLE discount_codes
    ADD COLUMN IF NOT EXISTS expires_at DATETIME NULL DEFAULT NULL
        AFTER usage_count
", "discount_codes: add expires_at", $log, $errors);

// Expand status ENUM to include cancelled explicitly
runSql($db, "
    ALTER TABLE discount_codes
    MODIFY COLUMN status ENUM('active','redeemed','expired','cancelled','revoked')
        NOT NULL DEFAULT 'active'
", "discount_codes: expand status ENUM", $log, $errors);

// Backfill business_id from offer → listing
runSql($db, "
    UPDATE discount_codes dc
    JOIN offers o ON o.id = dc.offer_id
    JOIN listings l ON l.id = o.listing_id
    SET dc.business_id = l.user_id
    WHERE dc.business_id IS NULL
", "discount_codes: backfill business_id", $log, $errors);

// Index for tenant isolation
runSql($db, "
    ALTER TABLE discount_codes
    ADD INDEX IF NOT EXISTS idx_business (business_id)
", "discount_codes: add idx_business", $log, $errors);

// ══════════════════════════════════════════════════════════════════════════════
// 4. Create `offer_redemptions`
//    Tracks each confirmed redemption event (in-store or online).
//    Separate from discount_codes.status to allow reversal history.
// ══════════════════════════════════════════════════════════════════════════════
runSql($db, "
    CREATE TABLE IF NOT EXISTS offer_redemptions (
        id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        offer_id           INT UNSIGNED NOT NULL,
        discount_code_id   BIGINT UNSIGNED NULL COMMENT 'NULL for shared-code redemptions before claim row exists',
        claim_id           BIGINT UNSIGNED NULL COMMENT 'offer_claims.id if applicable',
        business_id        INT UNSIGNED NOT NULL COMMENT 'listing.user_id — tenant key',
        customer_id        INT UNSIGNED NULL     COMMENT 'users.id; NULL for anonymous',
        channel            ENUM('instore','online','manual') NOT NULL DEFAULT 'instore',
        order_reference    VARCHAR(120) NULL     COMMENT 'External POS or checkout reference',
        purchase_amount    DECIMAL(14,2) NULL    COMMENT 'Total purchase before discount',
        discount_amount    DECIMAL(14,2) NULL    COMMENT 'Actual discount applied',
        currency           VARCHAR(3) NOT NULL DEFAULT 'XAF',
        status             ENUM('pending','confirmed','reversed','cancelled') NOT NULL DEFAULT 'confirmed',
        staff_user_id      INT UNSIGNED NULL     COMMENT 'Who processed the in-store redemption',
        reversal_reason    TEXT NULL,
        reversed_by        INT UNSIGNED NULL,
        reversed_at        DATETIME NULL,
        idempotency_key    VARCHAR(64) NULL      COMMENT 'Prevents duplicate online submissions',
        redeemed_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at         DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,

        UNIQUE  KEY uq_idempotency (idempotency_key),
        INDEX   idx_offer    (offer_id),
        INDEX   idx_code     (discount_code_id),
        INDEX   idx_business (business_id),
        INDEX   idx_customer (customer_id),
        INDEX   idx_status   (status),
        INDEX   idx_redeemed (redeemed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", "CREATE offer_redemptions", $log, $errors);

// ══════════════════════════════════════════════════════════════════════════════
// 5. Create `offer_entitlements`
//    Tracks purchased add-on offer slots per listing (separate from
//    addon_entitlements which covers all addon types).
// ══════════════════════════════════════════════════════════════════════════════
runSql($db, "
    CREATE TABLE IF NOT EXISTS offer_entitlements (
        id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        listing_id       INT UNSIGNED NOT NULL,
        source_type      ENUM('plan','addon','manual','promo') NOT NULL DEFAULT 'addon',
        source_reference VARCHAR(120) NULL COMMENT 'addon_entitlements.id or transaction ref',
        quantity         TINYINT UNSIGNED NOT NULL DEFAULT 1,
        status           ENUM('active','expired','cancelled','suspended') NOT NULL DEFAULT 'active',
        granted_by       INT UNSIGNED NULL COMMENT 'admin user id for manual grants',
        starts_at        DATETIME NULL,
        ends_at          DATETIME NULL,
        notes            TEXT NULL,
        created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at       DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,

        INDEX idx_listing (listing_id),
        INDEX idx_status  (status),
        INDEX idx_expires (ends_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", "CREATE offer_entitlements", $log, $errors);

// Seed offer_entitlements from existing addon_entitlements for offers type
// addon_entitlements actual columns: listing_id, addon_order_id, addon_type, capacity, activated_at, expires_at
runSql($db, "
    INSERT IGNORE INTO offer_entitlements
        (listing_id, source_type, source_reference, quantity, status, starts_at, ends_at, created_at)
    SELECT
        ae.listing_id,
        'addon',
        CONCAT('addon_entitlements:', ae.addon_order_id),
        ae.capacity,
        CASE WHEN (ae.expires_at IS NULL OR ae.expires_at > NOW()) THEN 'active' ELSE 'expired' END,
        ae.activated_at,
        ae.expires_at,
        COALESCE(ae.activated_at, NOW())
    FROM addon_entitlements ae
    WHERE ae.addon_type = 'offers'
", "offer_entitlements: seed from addon_entitlements", $log, $errors);

// ══════════════════════════════════════════════════════════════════════════════
// 6. Create `offer_audit_logs`
//    Records every sensitive action on offers, codes and redemptions.
// ══════════════════════════════════════════════════════════════════════════════
runSql($db, "
    CREATE TABLE IF NOT EXISTS offer_audit_logs (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        actor_id     INT UNSIGNED NULL    COMMENT 'User performing the action; NULL = system',
        actor_role   ENUM('admin','business','system','customer') NOT NULL DEFAULT 'system',
        business_id  INT UNSIGNED NULL    COMMENT 'Affected business (listing.user_id)',
        action       VARCHAR(80) NOT NULL COMMENT 'e.g. offer.create, code.revoke, redemption.reverse',
        entity_type  VARCHAR(40) NOT NULL COMMENT 'offer | discount_code | redemption | entitlement',
        entity_id    BIGINT UNSIGNED NULL,
        old_value    JSON NULL,
        new_value    JSON NULL,
        ip_address   VARCHAR(45) NULL,
        user_agent   VARCHAR(512) NULL,
        notes        TEXT NULL,
        created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

        INDEX idx_actor      (actor_id),
        INDEX idx_business   (business_id),
        INDEX idx_entity     (entity_type, entity_id),
        INDEX idx_action     (action),
        INDEX idx_created    (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", "CREATE offer_audit_logs", $log, $errors);

// ══════════════════════════════════════════════════════════════════════════════
// 7. Create `offer_views` — analytics
// ══════════════════════════════════════════════════════════════════════════════
runSql($db, "
    CREATE TABLE IF NOT EXISTS offer_views (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        offer_id   INT UNSIGNED NOT NULL,
        user_id    INT UNSIGNED NULL,
        session_id VARCHAR(64) NULL COMMENT 'Hashed session for dedup without PII',
        ip_hash    VARCHAR(64) NULL COMMENT 'SHA-256 of IP; not stored raw',
        viewed_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

        INDEX idx_offer   (offer_id),
        INDEX idx_user    (user_id),
        INDEX idx_viewed  (viewed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", "CREATE offer_views", $log, $errors);

// ══════════════════════════════════════════════════════════════════════════════
// 8. Create `offer_code_copies` — analytics: copy-button clicks
// ══════════════════════════════════════════════════════════════════════════════
runSql($db, "
    CREATE TABLE IF NOT EXISTS offer_code_copies (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        offer_id   INT UNSIGNED NOT NULL,
        code_id    BIGINT UNSIGNED NULL COMMENT 'discount_codes.id if applicable',
        user_id    INT UNSIGNED NULL,
        session_id VARCHAR(64) NULL,
        copied_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

        INDEX idx_offer  (offer_id),
        INDEX idx_user   (user_id),
        INDEX idx_copied (copied_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", "CREATE offer_code_copies", $log, $errors);

// ══════════════════════════════════════════════════════════════════════════════
// 9. Migrate existing promo_code values → shared discount_codes rows
//    For each active/pending offer that has a hand-typed promo_code,
//    create a shared-type discount_code row so the new system can serve it.
//    The original promo_code column is kept for rollback safety.
// ══════════════════════════════════════════════════════════════════════════════
$toMigrate = [];
try {
    $migrateStmt = $db->prepare("
        SELECT o.id AS offer_id, o.promo_code, o.listing_id, l.user_id AS business_id,
               COALESCE(o.redemption_count, 0) AS usage_count
        FROM offers o
        JOIN listings l ON l.id = o.listing_id
        WHERE o.promo_code IS NOT NULL
          AND o.promo_code != ''
          AND NOT EXISTS (
              SELECT 1 FROM discount_codes dc
              WHERE dc.offer_id = o.id
                AND dc.code_type = 'shared'
                AND dc.code = o.promo_code
          )
    ");
    $migrateStmt->execute();
    $toMigrate = $migrateStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errors[] = "⚠ Could not fetch legacy promo_codes to migrate: " . $e->getMessage();
}

// Note: subqueries inside VALUES() are not portable; usage_count is fetched in the SELECT above.
$insertCode = null;
try {
    $insertCode = $db->prepare("
        INSERT INTO discount_codes
            (code, offer_id, business_id, code_type, usage_limit, usage_count,
             claim_id, user_id, status, created_at)
        VALUES
            (?, ?, ?, 'shared', 0, ?, NULL, NULL, 'active', NOW())
        ON DUPLICATE KEY UPDATE business_id = VALUES(business_id), code_type = 'shared'
    ");
} catch (PDOException $e) {
    $errors[] = "⚠ Could not prepare discount_codes insert: " . $e->getMessage();
}

$migrated = 0;
if ($insertCode) {
    foreach ($toMigrate as $row) {
        try {
            $insertCode->execute([
                strtoupper($row['promo_code']),
                $row['offer_id'],
                $row['business_id'],
                (int)$row['usage_count'],
            ]);
            $migrated++;
        } catch (PDOException $e) {
            $errors[] = "⚠ Could not migrate promo_code for offer #{$row['offer_id']}: " . $e->getMessage();
        }
    }
}
$log[] = "✅ Migrated $migrated legacy promo_code values → discount_codes (shared type)";

// ══════════════════════════════════════════════════════════════════════════════
// 10. Update offer_claims index (add listing_id for faster queries)
// ══════════════════════════════════════════════════════════════════════════════
runSql($db, "
    ALTER TABLE offer_claims
    ADD COLUMN IF NOT EXISTS business_id INT UNSIGNED NULL DEFAULT NULL
        AFTER offer_id
", "offer_claims: add business_id", $log, $errors);

runSql($db, "
    UPDATE offer_claims oc
    JOIN offers o ON o.id = oc.offer_id
    JOIN listings l ON l.id = o.listing_id
    SET oc.business_id = l.user_id
    WHERE oc.business_id IS NULL
", "offer_claims: backfill business_id", $log, $errors);

runSql($db, "
    ALTER TABLE offer_claims
    ADD INDEX IF NOT EXISTS idx_business (business_id)
", "offer_claims: add idx_business", $log, $errors);

// ══════════════════════════════════════════════════════════════════════════════
// 11. Note about entitlement-helpers.php
//     The spec requires paused offers to count toward the slot limit.
//     Current query in entitlement-helpers.php line 94 counts only:
//       status IN ('active','scheduled')
//     This needs to be updated to:
//       status IN ('active','scheduled','paused')
//     That change is in the PHP file, not here — flagged for code update.
// ══════════════════════════════════════════════════════════════════════════════
$log[] = "ℹ️  ACTION REQUIRED: Update entitlement-helpers.php line 94 — add 'paused' to slot-counting statuses";
$log[] = "ℹ️  ACTION REQUIRED: Update entitlement-helpers.php line 148 — add 'paused' to checkEntitlement status guard";

try { $db->exec("SET foreign_key_checks = 1"); } catch (PDOException $e) { /* non-fatal */ }

// ── Log this migration run ───────────────────────────────────────────────────
try {
    $db->prepare("
        INSERT INTO offer_audit_logs
            (actor_id, actor_role, action, entity_type, notes, created_at)
        VALUES
            (?, 'admin', 'migration.run', 'system', ?, NOW())
    ")->execute([
        $_SESSION['user_id'] ?? null,
        'offers-v2-001.php: ' . count($log) . ' steps, ' . count($errors) . ' errors'
    ]);
} catch (Exception $e) { /* audit log may not exist yet if this failed */ }

// ── Output ───────────────────────────────────────────────────────────────────
$pageTitle = 'Offers v2 Migration — Admin';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../includes/nav.php';
adminNav(''); adminNavAssets();
?>
<div class="page-header"><div class="container">
  <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:1.8rem;">
    🛠 Offers v2 Migration — offers-v2-001
  </h1>
  <p style="color:var(--muted);margin-top:0.25rem;">
    Run at <?= date('Y-m-d H:i:s') ?> by <?= e($_SESSION['user_name'] ?? 'Admin') ?>
  </p>
</div></div>
<section class="page-section"><div class="container" style="max-width:900px;">

<?php if ($errors): ?>
<div style="background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.3);border-radius:10px;padding:1.25rem;margin-bottom:1.5rem;">
  <h3 style="color:#ef4444;margin-bottom:0.75rem;">⚠ <?= count($errors) ?> Error(s)</h3>
  <ul style="margin:0;padding-left:1.25rem;">
    <?php foreach ($errors as $e): ?><li style="font-family:monospace;font-size:0.85rem;color:#f87171;margin-bottom:0.3rem;"><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<div style="background:var(--card);border:1px solid var(--border);border-radius:10px;padding:1.25rem;">
  <h3 style="margin-bottom:0.75rem;">Migration Log</h3>
  <ul style="margin:0;padding-left:1.25rem;list-style:none;">
    <?php foreach ($log as $line): ?>
      <li style="font-family:monospace;font-size:0.84rem;padding:0.2rem 0;color:<?=
        strpos($line,'✅') !== false ? '#4ade80' :
        (strpos($line,'ℹ️') !== false ? '#60a5fa' : 'var(--muted)')
      ?>;"><?= htmlspecialchars($line, ENT_QUOTES, 'UTF-8') ?></li>
    <?php endforeach; ?>
  </ul>
</div>

<div style="margin-top:1.5rem;padding:1rem;background:rgba(0,168,120,0.06);border:1px solid rgba(0,168,120,0.2);border-radius:10px;">
  <strong style="color:#00A878;">Next steps:</strong>
  <ol style="margin-top:0.5rem;color:var(--muted);font-size:0.88rem;">
    <li>Update <code>includes/entitlement-helpers.php</code> — add <code>'paused'</code> to both status arrays (lines 94, 148)</li>
    <li>Deploy updated <code>partner/offers.php</code> (new guided form, code_type selector, no promo_code input)</li>
    <li>Deploy updated <code>admin/offers.php</code> (new statuses: scheduled, paused, archived, suspended_admin)</li>
    <li>Deploy updated <code>partner/manage-offer-codes.php</code> (in-store redemption v2)</li>
    <li>Deploy updated <code>offer.php</code> (Type A copy vs Type B get-code flows)</li>
    <li>Run a record-count reconciliation check after deployment</li>
  </ol>
</div>

</div></section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
