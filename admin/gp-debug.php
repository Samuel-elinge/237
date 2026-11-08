<?php
require_once __DIR__ . '/../includes/config.php';
$pdo = db();

header('Content-Type: text/plain');

echo "=== FILE MOD TIME ===\n";
echo date('Y-m-d H:i:s', filemtime(__DIR__ . '/manage-growth-partners.php')) . "\n\n";

echo "=== USERS WITH growth_partner ROLE ===\n";
$users = $pdo->query("SELECT id, name, email FROM users WHERE role='growth_partner'")->fetchAll();
foreach ($users as $u) echo "id={$u['id']} | {$u['name']} | {$u['email']}\n";
echo "Total: " . count($users) . "\n\n";

echo "=== PARTNER_PROFILES ===\n";
try {
    $profiles = $pdo->query("SELECT pp.id, pp.user_id, pp.status, pp.businesses_managed_count, pp.marketplace_commission_rate, u.name FROM partner_profiles pp JOIN users u ON u.id=pp.user_id")->fetchAll();
    foreach ($profiles as $p) echo "pp.id={$p['id']} user_id={$p['user_id']} | {$p['name']} | status={$p['status']} | biz={$p['businesses_managed_count']} | comm={$p['marketplace_commission_rate']}\n";
    echo "Total: " . count($profiles) . "\n\n";
} catch (Exception $e) { echo "ERROR: " . $e->getMessage() . "\n\n"; }

echo "=== ACTIVE ASSIGNMENTS ===\n";
try {
    $asgn = $pdo->query("SELECT partner_id, COUNT(*) as cnt FROM partner_business_assignments WHERE status='active' GROUP BY partner_id")->fetchAll();
    foreach ($asgn as $a) echo "partner_id={$a['partner_id']} count={$a['cnt']}\n";
    if (!$asgn) echo "none\n";
} catch (Exception $e) { echo "ERROR: " . $e->getMessage() . "\n"; }
