<?php
/**
 * Temporary diagnostic — DELETE after use
 */
if (!isset($_GET['key']) || $_GET['key'] !== 'debug237') {
    http_response_code(403); exit('Forbidden');
}
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Use config.php only (no auth needed for DB queries)
require_once __DIR__ . '/../includes/config.php';

$pdo = db();

echo "<pre>Testing dashboard queries for partner_id=1 (user_id=49)\n\n";
$pid = 1;
$uid = 49;

$tests = [
    'portfolio' => "SELECT COUNT(*),SUM(l.status='approved'),SUM(pba.assigned_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)) FROM partner_business_assignments pba JOIN listings l ON l.id=pba.listing_id WHERE pba.partner_id=$pid AND pba.status='active'",
    'open_tasks' => "SELECT COUNT(*) FROM growth_tasks WHERE partner_id=$pid AND status IN ('todo','in_progress')",
    'overdue_tasks' => "SELECT COUNT(*) FROM growth_tasks WHERE partner_id=$pid AND status IN ('todo','in_progress') AND due_date < CURDATE()",
    'upcoming_tasks' => "SELECT gt.*, l.name AS biz_name FROM growth_tasks gt JOIN listings l ON l.id = gt.listing_id WHERE gt.partner_id=$pid AND gt.status IN ('todo','in_progress') AND gt.due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) ORDER BY gt.due_date ASC, gt.priority DESC LIMIT 8",
    'active_leads' => "SELECT COUNT(*) FROM partner_leads WHERE partner_id=$pid AND status IN ('new','contacted','follow_up')",
    'overdue_leads' => "SELECT COUNT(*) FROM partner_leads WHERE partner_id=$pid AND follow_up_date < CURDATE() AND status NOT IN ('converted','lost','closed')",
    'month_comm' => "SELECT COALESCE(SUM(amount),0) FROM partner_commissions WHERE partner_id=$pid AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())",
    'active_plans' => "SELECT COUNT(*) FROM growth_plans WHERE partner_id=$pid AND status='active'",
    'campaigns' => "SELECT status, COUNT(*) cnt FROM campaigns WHERE partner_id=$pid GROUP BY status",
    'campaigns_ending' => "SELECT c.*, l.name AS biz_name FROM campaigns c JOIN listings l ON l.id=c.listing_id WHERE c.partner_id=$pid AND c.status='active' AND c.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY) ORDER BY c.end_date ASC LIMIT 5",
    'ai_recs' => "SELECT COUNT(*) FROM ai_recommendations WHERE partner_id=$pid AND status IN ('new','viewed')",
    'growth_alerts' => "SELECT COUNT(*) FROM growth_alerts WHERE partner_id=$pid AND status IN ('new','viewed')",
    'urgent_alerts' => "SELECT COUNT(*) FROM growth_alerts WHERE partner_id=$pid AND status IN ('new','viewed') AND priority='urgent'",
    'partner_notifications' => "SELECT COUNT(*) FROM partner_notifications WHERE user_id=$uid AND is_read=0",
    'audit_log' => "SELECT pal.*, l.name AS biz_name FROM partner_audit_log pal LEFT JOIN listings l ON l.id = pal.listing_id WHERE pal.partner_id=$pid ORDER BY pal.created_at DESC LIMIT 12",
    'content_items' => "SELECT ci.*, l.name AS biz_name FROM content_items ci JOIN listings l ON l.id=ci.listing_id WHERE ci.partner_id=$pid AND ci.status='scheduled' AND DATE(ci.scheduled_date)=CURDATE() ORDER BY ci.scheduled_date ASC LIMIT 6",
    'attention_queue' => "SELECT l.id, l.title FROM listings l JOIN partner_business_assignments pba ON pba.listing_id = l.id AND pba.partner_id=$pid AND pba.status = 'active'",
    'growth_score' => "SELECT gsh.score, gsh.recorded_at FROM growth_score_history gsh WHERE gsh.partner_id=$pid ORDER BY gsh.recorded_at ASC LIMIT 30",
    'total_camps' => "SELECT COUNT(*) FROM campaigns WHERE partner_id=$pid",
];

foreach ($tests as $name => $sql) {
    try {
        $pdo->query($sql);
        echo "✅ $name\n";
    } catch (Throwable $e) {
        echo "❌ $name — " . $e->getMessage() . "\n";
    }
}
echo "\n✅ Done\n</pre>";
