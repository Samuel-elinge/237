<?php
/**
 * automation/track-open.php
 * Serves a 1x1 transparent pixel and records the open event.
 * Linked from every automation email as:
 *   <img src="{{site_url}}/automation/track-open.php?lid={{log_id}}&uid={{user_id}}" width="1" height="1" style="display:none">
 */
require_once __DIR__ . '/../includes/config.php';

// Always serve the pixel — never fail visibly
header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// 1x1 transparent GIF
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

// Record the open asynchronously — don't let DB errors break pixel delivery
$logId = (int)($_GET['lid'] ?? 0);
$uid   = (int)($_GET['uid'] ?? 0);

if (!$logId) exit;

try {
    // Mark as opened — only first open counts
    db()->prepare("
        UPDATE automation_log
        SET opened_at = COALESCE(opened_at, NOW()),
            open_count = open_count + 1
        WHERE id = ?
    ")->execute([$logId]);

    // Also log individual open event for analytics
    db()->prepare("
        INSERT INTO automation_opens (log_id, user_id, ip_hash, user_agent, opened_at)
        VALUES (?, ?, ?, ?, NOW())
    ")->execute([
        $logId,
        $uid ?: null,
        hash('sha256', ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '') . date('Y-m-d')),
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)
    ]);
} catch (Throwable $e) {
    error_log('track-open error: ' . $e->getMessage());
}
