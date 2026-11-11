<?php
/**
 * partner/migrate-phase5.php — One-time DB migration for Phase 5A–5C
 * Run once: https://237biz.net/partner/migrate-phase5?key=run237phase5
 * DELETE this file from repo after running.
 */
if (($_GET['key'] ?? '') !== 'run237phase5') {
    http_response_code(403); exit('Forbidden');
}
require_once __DIR__ . '/../includes/config.php';
$pdo = db();
$results = [];

// 1. Add WA columns to communication_templates
try {
    $pdo->exec("ALTER TABLE communication_templates
        ADD COLUMN IF NOT EXISTS wa_category ENUM('utility','marketing','authentication') NULL AFTER channel,
        ADD COLUMN IF NOT EXISTS wa_header TEXT NULL AFTER wa_category,
        ADD COLUMN IF NOT EXISTS wa_footer TEXT NULL AFTER wa_header");
    $results[] = "✅ communication_templates: wa_category, wa_header, wa_footer added";
} catch (Exception $e) {
    $results[] = "⚠️ communication_templates alter: " . $e->getMessage();
}

// 2. Add source_ref + partner_id to marketplace_impressions
try {
    $pdo->exec("ALTER TABLE marketplace_impressions
        ADD COLUMN IF NOT EXISTS source_ref VARCHAR(100) NULL AFTER event_subtype,
        ADD COLUMN IF NOT EXISTS partner_id INT NULL AFTER source_ref");
    $results[] = "✅ marketplace_impressions: source_ref, partner_id added";
} catch (Exception $e) {
    $results[] = "⚠️ marketplace_impressions alter: " . $e->getMessage();
}

// 3. Create partner_tracking_links table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS partner_tracking_links (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        partner_id  INT NOT NULL,
        listing_id  INT NULL,
        ref_code    VARCHAR(20) NOT NULL UNIQUE,
        label       VARCHAR(100) NOT NULL,
        target_url  TEXT NOT NULL,
        campaign_id INT NULL,
        clicks      INT NOT NULL DEFAULT 0,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_partner (partner_id),
        INDEX idx_ref (ref_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = "✅ partner_tracking_links table created";
} catch (Exception $e) {
    $results[] = "⚠️ partner_tracking_links: " . $e->getMessage();
}

// 4. Seed a few WA message templates for the toolkit
try {
    // Check if any WA templates already exist for any partner
    $existing = $pdo->query("SELECT COUNT(*) FROM communication_templates WHERE channel='whatsapp'")->fetchColumn();
    if ($existing == 0) {
        // Insert system/example templates — partner_id=0 means system template (we'll handle display in UI)
        // Actually better: insert with the first active partner as seed — skip, let partners create their own
        $results[] = "ℹ️ No WA seed templates inserted (partners will create via UI)";
    } else {
        $results[] = "ℹ️ WA templates already exist ({$existing}) — skipping seed";
    }
} catch (Exception $e) {
    $results[] = "⚠️ seed check: " . $e->getMessage();
}

echo "<pre>Phase 5 Migration Results:\n";
foreach ($results as $r) echo $r . "\n";
echo "\n✅ Done — delete this file from the repo.\n</pre>";
