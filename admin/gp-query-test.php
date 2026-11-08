<?php
require_once __DIR__ . '/../includes/config.php';
$pdo = db();

$partners = $pdo->query("
    SELECT pp.id, pp.user_id, pp.status, pp.organisation, pp.region, pp.phone,
           pp.display_name, pp.tagline, pp.created_at,
           COALESCE(pp.marketplace_commission_rate, 10) AS marketplace_commission_rate,
           COALESCE(pp.businesses_managed_count, 0) AS assigned_count,
           u.id AS uid, u.name AS user_name, u.email AS user_email
    FROM users u
    LEFT JOIN partner_profiles pp ON pp.user_id = u.id
    WHERE u.role = 'growth_partner'
    ORDER BY COALESCE(pp.created_at, u.created_at) DESC
")->fetchAll();

echo "Query returned: " . count($partners) . " rows\n\n";
foreach ($partners as $p) {
    echo "uid={$p['uid']} pp_id={$p['id']} name={$p['user_name']} status={$p['status']}\n";
}
