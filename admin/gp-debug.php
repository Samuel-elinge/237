<?php
require_once __DIR__ . '/../includes/config.php';
requireAnyAdmin();
$pdo = db();

// What users have growth_partner role?
$users = $pdo->query("SELECT id, name, email, role FROM users WHERE role='growth_partner'")->fetchAll();

// What's in partner_profiles?
$profiles = $pdo->query("SELECT pp.id, pp.user_id, pp.status, pp.businesses_managed_count, pp.marketplace_commission_rate, u.name FROM partner_profiles pp JOIN users u ON u.id=pp.user_id")->fetchAll();

// What's in partner_business_assignments?
$assignments = $pdo->query("SELECT partner_id, COUNT(*) as cnt FROM partner_business_assignments WHERE status='active' GROUP BY partner_id")->fetchAll();

header('Content-Type: text/plain');
echo "=== USERS WITH growth_partner ROLE ===\n";
foreach ($users as $u) echo "id={$u['id']} name={$u['name']} email={$u['email']}\n";

echo "\n=== PARTNER_PROFILES ===\n";
foreach ($profiles as $p) echo "pp.id={$p['id']} user_id={$p['user_id']} name={$p['name']} status={$p['status']} biz_count={$p['businesses_managed_count']} comm={$p['marketplace_commission_rate']}\n";

echo "\n=== ACTIVE ASSIGNMENTS (by partner_id) ===\n";
foreach ($assignments as $a) echo "partner_id={$a['partner_id']} count={$a['cnt']}\n";

echo "\n=== FILE VERSION CHECK ===\n";
echo filemtime(__DIR__ . '/manage-growth-partners.php') . ' = ' . date('Y-m-d H:i:s', filemtime(__DIR__ . '/manage-growth-partners.php')) . "\n";
