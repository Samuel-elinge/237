<?php
if (!isset($_GET['key']) || $_GET['key'] !== 'debug237') {
    http_response_code(403); exit('Forbidden');
}
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/../includes/config.php';
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
echo "<pre>";

// Find first approved partner
$partnerRow = $pdo->query("SELECT gp.*, gp.user_id FROM growth_partners gp WHERE gp.status='approved' LIMIT 1")->fetch();
if (!$partnerRow) { echo "NO APPROVED PARTNER FOUND\n"; exit; }
$pid = (int)$partnerRow['id'];
echo "Testing with partner_id=$pid\n\n";

function tryq($pdo, $label, $sql, $params=[]) {
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $st->fetchAll();
        echo "✅ $label\n";
    } catch (Exception $e) {
        echo "❌ $label — " . $e->getMessage() . "\n";
    }
}

// Check partner_campaigns table exists
try {
    $pdo->query("SELECT 1 FROM partner_campaigns LIMIT 1");
    echo "✅ partner_campaigns table exists\n";
} catch (Exception $e) {
    echo "❌ partner_campaigns table MISSING — run p7 migration!\n";
}

echo "\n--- Dashboard queries ---\n";

tryq($pdo, "portfolio stats", "
    SELECT COUNT(*), SUM(l.status='approved'), SUM(pba.assigned_at >= DATE_SUB(NOW(), INTERVAL 30 DAY))
    FROM partner_business_assignments pba
    JOIN listings l ON l.id = pba.listing_id
    WHERE pba.partner_id=? AND pba.status='active'", [$pid]);

tryq($pdo, "open tasks count", "SELECT COUNT(*) FROM growth_tasks WHERE partner_id=? AND status IN ('todo','in_progress')", [$pid]);

tryq($pdo, "upcoming_tasks", "
    SELECT gt.*, l.title AS biz_name
    FROM growth_tasks gt
    JOIN listings l ON l.id = gt.listing_id
    WHERE gt.partner_id=? AND gt.status IN ('todo','in_progress') AND gt.due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY gt.due_date ASC, gt.priority DESC LIMIT 8", [$pid]);

tryq($pdo, "active leads count", "SELECT COUNT(*) FROM partner_leads WHERE partner_id=? AND status IN ('new','contacted','follow_up')", [$pid]);

tryq($pdo, "overdue leads", "SELECT COUNT(*) FROM partner_leads WHERE partner_id=? AND follow_up_date < CURDATE() AND status NOT IN ('converted','lost','closed')", [$pid]);

tryq($pdo, "month commissions", "SELECT COALESCE(SUM(amount),0) FROM partner_commissions WHERE partner_id=? AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())", [$pid]);

tryq($pdo, "active plans", "SELECT COUNT(*) FROM growth_plans WHERE partner_id=? AND status='active'", [$pid]);

tryq($pdo, "campaigns group by status", "SELECT status, COUNT(*) cnt FROM partner_campaigns WHERE partner_id=? GROUP BY status", [$pid]);

tryq($pdo, "ending soon campaigns", "
    SELECT c.*, l.title AS biz_name FROM partner_campaigns c JOIN listings l ON l.id=c.listing_id
    WHERE c.partner_id=? AND c.status='active' AND c.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)
    ORDER BY c.end_date ASC LIMIT 5", [$pid]);

tryq($pdo, "ai_recommendations count", "SELECT COUNT(*) FROM ai_recommendations WHERE partner_id=? AND status IN ('new','viewed')", [$pid]);

tryq($pdo, "growth_alerts count", "SELECT COUNT(*) FROM growth_alerts WHERE partner_id=? AND status IN ('new','viewed')", [$pid]);

tryq($pdo, "audit log", "
    SELECT pal.*, l.title AS biz_name
    FROM partner_audit_log pal
    LEFT JOIN listings l ON l.id = pal.listing_id
    WHERE pal.partner_id=? ORDER BY pal.created_at DESC LIMIT 12", [$pid]);

tryq($pdo, "content today", "
    SELECT ci.*, l.title AS biz_name FROM content_items ci JOIN listings l ON l.id=ci.listing_id
    WHERE ci.partner_id=? AND ci.status='scheduled' AND DATE(ci.scheduled_date)=CURDATE()
    ORDER BY ci.scheduled_date ASC LIMIT 6", [$pid]);

tryq($pdo, "growth score", "
    SELECT AVG(gsh.score) AS avg_score, COUNT(DISTINCT gsh.listing_id) AS scored_count
    FROM growth_score_history gsh
    JOIN partner_business_assignments pba ON pba.listing_id=gsh.listing_id
    WHERE pba.partner_id=? AND pba.status='active'
      AND gsh.computed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)", [$pid]);

tryq($pdo, "active leads list", "
    SELECT pl.*, l.title AS biz_name FROM partner_leads pl JOIN listings l ON l.id=pl.listing_id
    WHERE pl.partner_id=? AND pl.status IN ('new','contacted','follow_up')
    ORDER BY pl.follow_up_date ASC, pl.created_at DESC LIMIT 6", [$pid]);

tryq($pdo, "total campaigns count", "SELECT COUNT(*) FROM partner_campaigns WHERE partner_id=?", [$pid]);

// Test partner-helpers include
echo "\n--- partner-helpers.php ---\n";
try {
    require_once __DIR__ . '/../includes/helpers.php';
    require_once __DIR__ . '/../includes/partner-helpers.php';
    echo "✅ partner-helpers.php loaded\n";
    // Test getAttentionQueue
    $q = getAttentionQueue($pid);
    echo "✅ getAttentionQueue() returned " . count($q) . " items\n";
} catch (Exception $e) {
    echo "❌ partner-helpers: " . $e->getMessage() . "\n";
} catch (Error $e) {
    echo "❌ partner-helpers ERROR: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "</pre>";
