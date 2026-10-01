<?php
/**
 * automation/queue.php — n8n polling endpoint
 * GET  ?token=SECRET → returns queued emails as JSON
 * POST ?token=SECRET → marks emails sent/failed
 */

// Always output JSON — never let PHP errors break the response
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Catch fatal errors too
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code(500);
        }
        echo json_encode(['error' => 'PHP fatal: ' . $err['message'], 'file' => $err['file'], 'line' => $err['line']]);
    }
});

header('Content-Type: application/json');

// Load config safely
$configPath = __DIR__ . '/../includes/config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'config.php not found at ' . $configPath]);
    exit;
}

try {
    require_once $configPath;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'config.php error: ' . $e->getMessage()]);
    exit;
}

// Token — hardcoded fallback so it works even if constant missing
$webhookToken = defined('AUTOMATION_WEBHOOK_TOKEN')
    ? AUTOMATION_WEBHOOK_TOKEN
    : 'tuJtTu01lnwgZax56miSfKOTjzqkWAdMI2cXmzhQeL8';

// Authenticate
$token = $_GET['token'] ?? '';
if (!$token || !hash_equals($webhookToken, $token)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ── POST: mark items sent/failed ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON', 'received' => substr($raw, 0, 200)]);
        exit;
    }
    $updated = 0;
    foreach ($body as $item) {
        $logId  = (int)($item['log_id'] ?? 0);
        $status = in_array($item['status'] ?? '', ['sent','failed','skipped']) ? $item['status'] : null;
        $error  = $item['error'] ?? null;
        if ($logId && $status) {
            try {
                db()->prepare("
                    UPDATE automation_log
                    SET status=?, sent_at=NOW(), error_msg=?
                    WHERE id=? AND status='queued'
                ")->execute([$status, $error, $logId]);
                $updated++;
            } catch (Throwable $e) {
                error_log('queue.php callback error: ' . $e->getMessage());
            }
        }
    }
    echo json_encode(['updated' => $updated]);
    exit;
}

// ── GET: return queued emails ─────────────────────────────
try {
    // Test DB connection first
    db()->query("SELECT 1");
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed: ' . $e->getMessage()]);
    exit;
}

// Check automation_log table exists
try {
    db()->query("SELECT 1 FROM automation_log LIMIT 1");
} catch (Throwable $e) {
    echo json_encode(['count' => 0, 'emails' => [], 'notice' => 'automation_log table not found']);
    exit;
}

try {
    $queued = db()->query("
        SELECT al.id AS log_id, al.to_email, al.subject, al.lang,
               al.user_id, u.name AS user_name,
               t.body_en, t.body_fr,
               l.title AS business_name, l.slug AS listing_slug
        FROM automation_log al
        JOIN users u ON u.id = al.user_id
        JOIN automation_templates t ON t.id = al.template_id
        LEFT JOIN listings l ON l.user_id = u.id AND l.status = 'approved'
        WHERE al.status = 'queued' AND al.queued_at <= NOW()
        GROUP BY al.id
        ORDER BY al.queued_at ASC
        LIMIT 50
    ")->fetchAll();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Query failed: ' . $e->getMessage()]);
    exit;
}

$payload = [];
foreach ($queued as $q) {
    $lang    = $q['lang'] ?? 'en';
    $bodyRaw = $lang === 'fr' ? ($q['body_fr'] ?: $q['body_en']) : $q['body_en'];

    $listingUrl = $q['listing_slug']
        ? SITE_URL . '/listing/' . $q['listing_slug']
        : SITE_URL . '/dashboard';

    $merged = str_replace(
        ['{{name}}','{{business_name}}','{{listing_url}}','{{site_url}}','{{unsubscribe_url}}'],
        [
            htmlspecialchars($q['user_name'] ?? ''),
            htmlspecialchars($q['business_name'] ?? $q['user_name'] ?? ''),
            $listingUrl,
            SITE_URL,
            SITE_URL . '/unsubscribe?uid=' . $q['user_id']
        ],
        $bodyRaw
    );

    // Wrap in branded email template (emailWrap defined in config.php)
    $html = function_exists('emailWrap') ? emailWrap($q['subject'], $merged) : $merged;

    $payload[] = [
        'log_id'  => (int)$q['log_id'],
        'to'      => $q['to_email'],
        'subject' => $q['subject'],
        'html'    => $html,
    ];
}

echo json_encode(['count' => count($payload), 'emails' => $payload]);
