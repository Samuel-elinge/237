<?php
/**
 * Temporary diagnostic — DELETE after use
 * Captures the actual error from partner/dashboard.php
 */
if (!isset($_GET['key']) || $_GET['key'] !== 'debug237') {
    http_response_code(403); exit('Forbidden');
}

// Capture any fatal/error output
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Log errors to a string
$errorLog = [];
set_error_handler(function($errno, $errstr, $errfile, $errline) use (&$errorLog) {
    $errorLog[] = "[$errno] $errstr in $errfile:$errline";
    return true;
});

ob_start();
$crashed = false;
try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/helpers.php';
    require_once __DIR__ . '/../includes/partner-helpers.php';

    $pdo = db();
    $uid = $_SESSION['user_id'] ?? null;

    echo "<pre>Session user_id: $uid\n";

    // Step through dashboard queries one by one
    $st = $pdo->prepare("SELECT * FROM partner_profiles WHERE user_id=?");
    $st->execute([$uid]);
    $partner = $st->fetch();
    echo "Partner: " . ($partner ? "id={$partner['id']} status={$partner['status']}" : "NOT FOUND") . "\n";

    if (!$partner) exit("No partner profile — would redirect to /partner/pending\n</pre>");
    $pid = (int)$partner['id'];

    echo "Testing portfolio query...\n";
    $st = $pdo->prepare("SELECT COUNT(*),SUM(l.status='approved'),SUM(pba.assigned_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)) FROM partner_business_assignments pba JOIN listings l ON l.id=pba.listing_id WHERE pba.partner_id=? AND pba.status='active'");
    $st->execute([$pid]); echo "✅ portfolio\n";

    echo "Testing growth_tasks...\n";
    $st = $pdo->prepare("SELECT COUNT(*) FROM growth_tasks WHERE partner_id=? AND status IN ('todo','in_progress')");
    $st->execute([$pid]); echo "✅ growth_tasks\n";

    echo "Testing partner_leads...\n";
    $st = $pdo->prepare("SELECT COUNT(*) FROM partner_leads WHERE partner_id=? AND follow_up_date < CURDATE() AND status NOT IN ('converted','lost','closed')");
    $st->execute([$pid]); echo "✅ partner_leads follow_up_date\n";

    echo "Testing campaigns...\n";
    $st = $pdo->prepare("SELECT status,COUNT(*) cnt FROM campaigns WHERE partner_id=? GROUP BY status");
    $st->execute([$pid]); echo "✅ campaigns\n";

    echo "Testing getAttentionQueue...\n";
    $attn = getAttentionQueue($pid); echo "✅ getAttentionQueue (" . count($attn) . " items)\n";

    echo "Testing ai_recommendations...\n";
    $st = $pdo->prepare("SELECT COUNT(*) FROM ai_recommendations WHERE partner_id=? AND status IN ('new','viewed')");
    $st->execute([$pid]); echo "✅ ai_recommendations\n";

    echo "Testing getUnreadNotifications...\n";
    $notifs = getUnreadNotifications($uid); echo "✅ getUnreadNotifications: $notifs\n";

    echo "Testing partner_audit_log...\n";
    $st = $pdo->prepare("SELECT pal.*,l.name AS biz_name FROM partner_audit_log pal LEFT JOIN listings l ON l.id=pal.listing_id WHERE pal.partner_id=? ORDER BY pal.created_at DESC LIMIT 12");
    $st->execute([$pid]); echo "✅ partner_audit_log\n";

    echo "Testing content_items...\n";
    $st = $pdo->prepare("SELECT ci.*,l.name AS biz_name FROM content_items ci JOIN listings l ON l.id=ci.listing_id WHERE ci.partner_id=? AND ci.status='scheduled' AND DATE(ci.scheduled_date)=CURDATE() ORDER BY ci.scheduled_date ASC LIMIT 6");
    $st->execute([$pid]); echo "✅ content_items\n";

    echo "\n✅ ALL QUERIES PASSED — dashboard should load\n";

} catch (Throwable $e) {
    $crashed = true;
    echo "\n❌ CRASH: " . get_class($e) . ": " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " line " . $e->getLine() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
}

$out = ob_get_clean();
if ($errorLog) $out .= "\n\nPHP Warnings/Notices:\n" . implode("\n", $errorLog);
header('Content-Type: text/html');
echo "<pre>" . htmlspecialchars($out) . "</pre>";
