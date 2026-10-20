<?php
/**
 * Notifications API
 * POST /api/notifications
 *
 * Actions:
 *   mark_read     – mark a single notification as read  {notif_id}
 *   mark_all_read – mark all bell notifications as read
 *   unread_count  – return {ok, count} of unread bell notifications
 */

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

function jsonOut(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['ok' => false, 'error' => 'Method not allowed'], 405);
}

if (!isset($_SESSION['user_id'])) {
    jsonOut(['ok' => false, 'error' => 'login_required', 'redirect' => SITE_URL . '/login'], 401);
}

$userId = (int)$_SESSION['user_id'];

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    jsonOut(['ok' => false, 'error' => 'Invalid JSON'], 400);
}

$csrf = trim($body['csrf'] ?? '');
if (!verifyCsrf($csrf)) {
    jsonOut(['ok' => false, 'error' => 'Invalid CSRF token'], 403);
}

$action = $body['action'] ?? 'mark_read';

switch ($action) {

    case 'unread_count':
        try {
            $s = db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND channel='bell' AND is_read=0");
            $s->execute([$userId]);
            jsonOut(['ok' => true, 'count' => (int)$s->fetchColumn()]);
        } catch (Exception $e) {
            jsonOut(['ok' => false, 'error' => 'DB error'], 500);
        }
        break;

    case 'mark_all_read':
        try {
            db()->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? AND channel='bell' AND is_read=0")
                ->execute([$userId]);
            jsonOut(['ok' => true]);
        } catch (Exception $e) {
            jsonOut(['ok' => false, 'error' => 'DB error'], 500);
        }
        break;

    case 'mark_read':
    default:
        $notifId = (int)($body['notif_id'] ?? 0);
        if ($notifId < 1) {
            jsonOut(['ok' => false, 'error' => 'Invalid notif_id'], 422);
        }
        try {
            // Only mark notifications belonging to this user
            $s = db()->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=? AND channel='bell'");
            $s->execute([$notifId, $userId]);
            if ($s->rowCount() === 0) {
                jsonOut(['ok' => false, 'error' => 'Not found or already read'], 404);
            }
            jsonOut(['ok' => true]);
        } catch (Exception $e) {
            jsonOut(['ok' => false, 'error' => 'DB error'], 500);
        }
        break;
}
