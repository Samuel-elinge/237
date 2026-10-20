<?php
/**
 * Favourites API
 * POST /api/favourites.php
 *
 * Actions:
 *   toggle  – save if not saved, remove if saved  (returns {ok, saved: bool, fav_id})
 *   save    – save a new item                      (returns {ok, fav_id})
 *   remove  – remove by fav_id                     (returns {ok})
 *   status  – check if item is saved               (returns {ok, saved: bool, fav_id})
 */

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

function jsonOut(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// Must be POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['ok' => false, 'error' => 'Method not allowed'], 405);
}

// Must be logged in
if (!isset($_SESSION['user_id'])) {
    jsonOut(['ok' => false, 'error' => 'login_required', 'redirect' => SITE_URL . '/login'], 401);
}

$userId = (int)$_SESSION['user_id'];

// Parse JSON body
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    jsonOut(['ok' => false, 'error' => 'Invalid JSON'], 400);
}

// CSRF
$csrf = trim($body['csrf'] ?? '');
if (!verifyCsrf($csrf)) {
    jsonOut(['ok' => false, 'error' => 'Invalid CSRF token'], 403);
}

$action   = $body['action']    ?? 'toggle';
$itemType = $body['item_type'] ?? '';
$itemId   = (int)($body['item_id'] ?? 0);
$favId    = (int)($body['fav_id']  ?? 0);

// ── Validate item_type / item_id ─────────────────────────────
$validTypes = ['listing', 'offer', 'event'];

if ($action !== 'remove' && !in_array($itemType, $validTypes)) {
    jsonOut(['ok' => false, 'error' => 'Invalid item_type'], 422);
}
if ($action !== 'remove' && $itemId < 1) {
    jsonOut(['ok' => false, 'error' => 'Invalid item_id'], 422);
}

// Verify the item actually exists (prevents phantom saves)
if (in_array($action, ['toggle', 'save', 'status'])) {
    try {
        $tbl = ['listing' => 'listings', 'offer' => 'offers', 'event' => 'events'][$itemType];
        $exists = db()->prepare("SELECT id FROM {$tbl} WHERE id = ? LIMIT 1");
        $exists->execute([$itemId]);
        if (!$exists->fetch()) {
            jsonOut(['ok' => false, 'error' => 'Item not found'], 404);
        }
    } catch (Exception $e) {
        jsonOut(['ok' => false, 'error' => 'DB error'], 500);
    }
}

// ── Actions ──────────────────────────────────────────────────

switch ($action) {

    case 'status':
        try {
            $chk = db()->prepare("
                SELECT id FROM user_favourites
                WHERE user_id=? AND item_type=? AND item_id=? LIMIT 1
            ");
            $chk->execute([$userId, $itemType, $itemId]);
            $row = $chk->fetch();
            jsonOut(['ok' => true, 'saved' => (bool)$row, 'fav_id' => $row ? (int)$row['id'] : 0]);
        } catch (Exception $e) {
            jsonOut(['ok' => false, 'error' => 'DB error'], 500);
        }
        break;

    case 'save':
        try {
            $ins = db()->prepare("
                INSERT IGNORE INTO user_favourites (user_id, item_type, item_id)
                VALUES (?, ?, ?)
            ");
            $ins->execute([$userId, $itemType, $itemId]);
            $newId = (int)db()->lastInsertId();
            if (!$newId) {
                // Already saved — look up existing
                $chk = db()->prepare("SELECT id FROM user_favourites WHERE user_id=? AND item_type=? AND item_id=? LIMIT 1");
                $chk->execute([$userId, $itemType, $itemId]);
                $newId = (int)$chk->fetchColumn();
            }
            jsonOut(['ok' => true, 'saved' => true, 'fav_id' => $newId]);
        } catch (Exception $e) {
            jsonOut(['ok' => false, 'error' => 'DB error'], 500);
        }
        break;

    case 'remove':
        if ($favId > 0) {
            // Remove by fav_id (dashboard unsave button)
            try {
                $del = db()->prepare("DELETE FROM user_favourites WHERE id=? AND user_id=?");
                $del->execute([$favId, $userId]);
                jsonOut(['ok' => true]);
            } catch (Exception $e) {
                jsonOut(['ok' => false, 'error' => 'DB error'], 500);
            }
        } elseif ($itemId > 0 && $itemType) {
            // Remove by item
            try {
                $del = db()->prepare("DELETE FROM user_favourites WHERE user_id=? AND item_type=? AND item_id=?");
                $del->execute([$userId, $itemType, $itemId]);
                jsonOut(['ok' => true]);
            } catch (Exception $e) {
                jsonOut(['ok' => false, 'error' => 'DB error'], 500);
            }
        } else {
            jsonOut(['ok' => false, 'error' => 'Missing fav_id or item'], 422);
        }
        break;

    case 'toggle':
    default:
        try {
            $chk = db()->prepare("
                SELECT id FROM user_favourites
                WHERE user_id=? AND item_type=? AND item_id=? LIMIT 1
            ");
            $chk->execute([$userId, $itemType, $itemId]);
            $existing = $chk->fetch();

            if ($existing) {
                db()->prepare("DELETE FROM user_favourites WHERE id=?")->execute([$existing['id']]);
                jsonOut(['ok' => true, 'saved' => false, 'fav_id' => 0]);
            } else {
                $ins = db()->prepare("
                    INSERT INTO user_favourites (user_id, item_type, item_id) VALUES (?, ?, ?)
                ");
                $ins->execute([$userId, $itemType, $itemId]);
                jsonOut(['ok' => true, 'saved' => true, 'fav_id' => (int)db()->lastInsertId()]);
            }
        } catch (Exception $e) {
            jsonOut(['ok' => false, 'error' => 'DB error'], 500);
        }
        break;
}
