<?php
/**
 * Temporary diagnostic — DELETE after use
 */
if (!isset($_GET['key']) || $_GET['key'] !== 'debug237') {
    http_response_code(403); exit('Forbidden');
}
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';

$pdo = db();
echo "<h2>DB Table Check</h2><pre>";

$tables = [
    'partner_profiles','partner_business_assignments','growth_plans','growth_plan_objectives',
    'growth_tasks','partner_leads','partner_commissions','campaigns','campaign_content',
    'content_items','ai_recommendations','growth_alerts','growth_score_history',
    'partner_audit_log','partner_qbrs','partner_revenue_targets','partner_performance_notes',
    'partner_complaints','partner_message_threads','partner_messages',
    'business_activity','partner_notifications','notifications'
];

foreach ($tables as $t) {
    try {
        $r = $pdo->query("SELECT COUNT(*) FROM `$t`");
        echo "✅ $t (" . $r->fetchColumn() . " rows)\n";
    } catch (Exception $e) {
        echo "❌ $t — " . $e->getMessage() . "\n";
    }
}

echo "\n<h2>partner_profiles status for user session</h2><pre>";
session_start();
$uid = $_SESSION['user_id'] ?? null;
echo "Session user_id: " . ($uid ?? 'not logged in') . "\n";
if ($uid) {
    $st = $pdo->prepare("SELECT id, status FROM partner_profiles WHERE user_id=?");
    $st->execute([$uid]);
    $p = $st->fetch();
    if ($p) echo "Partner profile: id={$p['id']}, status={$p['status']}\n";
    else echo "No partner_profile for this user\n";
}
echo "</pre>";
