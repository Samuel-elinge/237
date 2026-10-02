<?php
/**
 * Temporary diagnostic — DELETE after use
 * Shows the exact error from partner/dashboard.php
 */
if (!isset($_GET['key']) || $_GET['key'] !== 'debug237') {
    http_response_code(403); exit('Forbidden');
}
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/partner-helpers.php';

$pdo = db();
echo "<h2>DB Table Check</h2><pre>";

$tables = [
    'partner_profiles','partner_business_assignments','growth_plans','growth_plan_objectives',
    'growth_tasks','partner_leads','partner_commissions','campaigns','campaign_content',
    'content_items','ai_recommendations','growth_alerts','growth_score_history',
    'partner_audit_log','partner_qbrs','partner_revenue_targets','partner_performance_notes',
    'partner_complaints','partner_message_threads','partner_messages'
];

foreach ($tables as $t) {
    try {
        $r = $pdo->query("SELECT COUNT(*) FROM `$t`");
        echo "✅ $t (" . $r->fetchColumn() . " rows)\n";
    } catch (Exception $e) {
        echo "❌ $t — " . $e->getMessage() . "\n";
    }
}

echo "\n<h2>partner_leads columns</h2>";
$cols = $pdo->query("SHOW COLUMNS FROM partner_leads");
foreach ($cols->fetchAll() as $c) echo $c['Field'] . " (" . $c['Type'] . ")\n";

echo "\n<h2>Session</h2>";
echo "user_id: " . ($_SESSION['user_id'] ?? 'none') . "\n";
echo "role: " . ($_SESSION['role'] ?? 'none') . "\n";
echo "</pre>";
