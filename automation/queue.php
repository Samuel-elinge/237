<?php
/**
 * automation/queue.php
 * n8n polls GET /automation/queue.php?token=SECRET to pick up queued emails.
 * n8n calls back POST /automation/queue.php?token=SECRET to mark them sent/failed.
 *
 * Set AUTOMATION_WEBHOOK_TOKEN in config.php (or hardcode below).
 */
require_once __DIR__ . '/../includes/config.php';

define('WEBHOOK_TOKEN', defined('AUTOMATION_WEBHOOK_TOKEN') ? AUTOMATION_WEBHOOK_TOKEN : '237biz-automation-2026');

header('Content-Type: application/json');

// Authenticate
$token = $_GET['token'] ?? '';
if (!$token || !hash_equals(WEBHOOK_TOKEN, $token)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ── POST: n8n marks items as sent/failed ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) { http_response_code(400); echo json_encode(['error'=>'Invalid JSON']); exit; }

    $updated = 0;
    foreach ($body as $item) {
        $logId  = (int)($item['log_id'] ?? 0);
        $status = in_array($item['status']??'', ['sent','failed','skipped']) ? $item['status'] : null;
        $error  = $item['error'] ?? null;
        if ($logId && $status) {
            db()->prepare("UPDATE automation_log SET status=?, sent_at=NOW(), error_msg=? WHERE id=? AND status='queued'")
                 ->execute([$status, $error, $logId]);
            $updated++;
        }
    }
    echo json_encode(['updated' => $updated]);
    exit;
}

// ── GET: return up to 50 queued emails ───────────────────
$queued = db()->query("
    SELECT al.id AS log_id, al.to_email, al.subject, al.lang,
           al.user_id, u.name AS user_name,
           t.body_en, t.body_fr, t.subject_en, t.subject_fr,
           l.title AS business_name, l.slug AS listing_slug
    FROM automation_log al
    JOIN users u        ON u.id = al.user_id
    JOIN automation_templates t ON t.id = al.template_id
    LEFT JOIN listings l ON l.user_id = u.id AND l.status='approved'
    WHERE al.status = 'queued' AND al.queued_at <= NOW()
    GROUP BY al.id
    ORDER BY al.queued_at ASC
    LIMIT 50
")->fetchAll();

// Resolve merge tags and build final email payload
$payload = [];
foreach ($queued as $q) {
    $lang      = $q['lang'];
    $subject   = $q['subject']; // already resolved at queue time
    $bodyRaw   = $lang === 'fr' ? $q['body_fr'] : $q['body_en'];

    // Merge tags
    $listingUrl = $q['listing_slug']
        ? SITE_URL . '/listing/' . $q['listing_slug']
        : SITE_URL . '/dashboard';

    $merged = str_replace(
        ['{{name}}', '{{business_name}}', '{{listing_url}}', '{{site_url}}',
         '{{unsubscribe_url}}'],
        [$q['user_name'], $q['business_name'] ?: $q['user_name'], $listingUrl, SITE_URL,
         SITE_URL . '/unsubscribe?uid=' . $q['user_id']],
        $bodyRaw
    );

    // Wrap in branded template with open tracking pixel
    $html = function_exists('emailWrap')
        ? emailWrap($subject, $merged, (int)$q['log_id'], (int)$q['user_id'])
        : $merged;

    $payload[] = [
        'log_id'    => $q['log_id'],
        'to'        => $q['to_email'],
        'subject'   => $subject,
        'html'      => $html,
        'from_name' => SMTP_FROM_NAME,
        'from_email'=> SMTP_USER,
    ];
}

echo json_encode(['count' => count($payload), 'emails' => $payload]);
