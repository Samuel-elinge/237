<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';

$pdo = db();
$partners = $pdo->query("
    SELECT pp.id AS pp_id, pp.user_id, pp.status, pp.organisation, pp.region, pp.phone,
           pp.display_name, pp.tagline, pp.created_at AS pp_created_at,
           COALESCE(pp.marketplace_commission_rate, 10) AS marketplace_commission_rate,
           COALESCE(pp.businesses_managed_count, 0) AS assigned_count,
           u.id AS uid, u.created_at AS user_created_at,
           u.name AS user_name, u.email AS user_email
    FROM users u
    LEFT JOIN partner_profiles pp ON pp.user_id = u.id
    WHERE u.role = 'growth_partner'
    ORDER BY COALESCE(pp.created_at, u.created_at) DESC
")->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/plain');
echo "Count: " . count($partners) . "\n";

// Simulate exactly what the template does for each row
foreach ($partners as $p):
    echo "\n--- uid={$p['uid']} ---\n";
    // Test each operation the template does
    echo "1. e(user_name): [" . e($p['user_name']) . "]\n";
    echo "2. e(user_email): [" . e($p['user_email']) . "]\n";
    echo "3. e(org ?: dash): [" . e($p['organisation'] ?: '—') . "]\n";
    echo "4. e(region ?: dash): [" . e($p['region'] ?: '—') . "]\n";
    echo "5. if status: " . ($p['status'] ? 'TRUE' : 'FALSE') . "\n";
    echo "6. int assigned_count: " . (int)$p['assigned_count'] . "\n";
    echo "7. if pp_id: " . ($p['pp_id'] ? 'TRUE' : 'FALSE') . "\n";
    echo "8. csrfField(): [" . csrfField() . "]\n";
    echo "9. number_format comm: [" . number_format((float)($p['marketplace_commission_rate'] ?? 10), 1) . "]\n";
    echo "10. pp_created_at date: [" . ($p['pp_created_at'] ? date('j M Y', strtotime($p['pp_created_at'])) : '—') . "]\n";
    echo "11. int uid: " . (int)$p['uid'] . "\n";
    echo "12. addslashes name: [" . addslashes($p['user_name']) . "]\n";
    echo "13. in_array status pending/applicant: " . (in_array($p['status'], ['pending','applicant']) ? 'TRUE' : 'FALSE') . "\n";
    echo "--- end uid={$p['uid']} ---\n";
endforeach;
echo "\nDone.\n";
