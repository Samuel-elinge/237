<?php
/**
 * Follows API
 * POST /api/follows
 *
 * Actions:
 *   toggle  – follow if not following, unfollow if following  (returns {ok, following: bool, row_id})
 *   follow  – follow a business                               (returns {ok, row_id})
 *   unfollow – unfollow (soft-delete via unfollowed_at)        (returns {ok})
 *   status  – check if following                              (returns {ok, following: bool, row_id})
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/notify.php';

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

$action     = $body['action']      ?? 'toggle';
$followType = $body['follow_type'] ?? 'listing';
$followId   = (int)($body['follow_id'] ?? 0);
$rowId      = (int)($body['row_id']    ?? 0);

// Validate
$validTypes = ['listing'];
if (!in_array($followType, $validTypes)) {
    jsonOut(['ok' => false, 'error' => 'Invalid follow_type'], 422);
}
if ($action !== 'status' && $followId < 1) {
    jsonOut(['ok' => false, 'error' => 'Invalid follow_id'], 422);
}

// Verify the listing exists
if (in_array($action, ['toggle', 'follow', 'status'])) {
    try {
        $ex = db()->prepare("SELECT id FROM listings WHERE id = ? LIMIT 1");
        $ex->execute([$followId]);
        if (!$ex->fetch()) {
            jsonOut(['ok' => false, 'error' => 'Listing not found'], 404);
        }
    } catch (Exception $e) {
        jsonOut(['ok' => false, 'error' => 'DB error'], 500);
    }
}

// Helper: notify listing owner of a new follower (fire-and-forget; never fatal)
function notifyListingOwner(int $followerUserId, int $listingId): void {
    try {
        $ls = db()->prepare("SELECT user_id, title, slug FROM listings WHERE id = ? LIMIT 1");
        $ls->execute([$listingId]);
        $listing = $ls->fetch();
        if (!$listing || !$listing['user_id']) return;
        $ownerId = (int)$listing['user_id'];
        if ($ownerId === $followerUserId) return; // don't notify self-follows

        // Fetch follower display name
        $us = db()->prepare("SELECT name FROM users WHERE id = ? LIMIT 1");
        $us->execute([$followerUserId]);
        $follower = $us->fetch();
        $followerName = $follower ? $follower['name'] : 'Someone';

        $title = e($listing['title']);
        dispatch_notification(
            $ownerId,
            'bell',
            "{$followerName} started following {$title}",
            "{$followerName} suit maintenant {$title}",
            "You have a new follower on your listing.",
            "Vous avez un nouvel abonné à votre annonce.",
            '/listing/' . $listing['slug'],
            'listing',
            $listingId,
            'follow'
        );
    } catch (Exception $e) { /* non-critical */ }
}

// Helper: fetch active follow row
function getActiveFollow(int $userId, string $type, int $id): ?array {
    try {
        $s = db()->prepare("
            SELECT id FROM user_follows
            WHERE user_id = ? AND follow_type = ? AND follow_id = ?
              AND unfollowed_at IS NULL
            LIMIT 1
        ");
        $s->execute([$userId, $type, $id]);
        return $s->fetch() ?: null;
    } catch (Exception $e) {
        return null;
    }
}

switch ($action) {

    case 'status':
        $row = getActiveFollow($userId, $followType, $followId);
        jsonOut(['ok' => true, 'following' => (bool)$row, 'row_id' => $row ? (int)$row['id'] : 0]);
        break;

    case 'follow':
        try {
            // Check not already following
            $existing = getActiveFollow($userId, $followType, $followId);
            if ($existing) {
                jsonOut(['ok' => true, 'following' => true, 'row_id' => (int)$existing['id']]);
            }
            $ins = db()->prepare("
                INSERT INTO user_follows (user_id, follow_type, follow_id, notify)
                VALUES (?, ?, ?, 1)
            ");
            $ins->execute([$userId, $followType, $followId]);
            $newRowId = (int)db()->lastInsertId();
            if ($followType === 'listing') notifyListingOwner($userId, $followId);
            jsonOut(['ok' => true, 'following' => true, 'row_id' => $newRowId]);
        } catch (Exception $e) {
            jsonOut(['ok' => false, 'error' => 'DB error'], 500);
        }
        break;

    case 'unfollow':
        try {
            // Soft-delete: set unfollowed_at
            if ($rowId > 0) {
                $upd = db()->prepare("
                    UPDATE user_follows SET unfollowed_at = NOW()
                    WHERE id = ? AND user_id = ? AND unfollowed_at IS NULL
                ");
                $upd->execute([$rowId, $userId]);
            } else {
                $upd = db()->prepare("
                    UPDATE user_follows SET unfollowed_at = NOW()
                    WHERE user_id = ? AND follow_type = ? AND follow_id = ?
                      AND unfollowed_at IS NULL
                ");
                $upd->execute([$userId, $followType, $followId]);
            }
            jsonOut(['ok' => true, 'following' => false]);
        } catch (Exception $e) {
            jsonOut(['ok' => false, 'error' => 'DB error'], 500);
        }
        break;

    case 'toggle':
    default:
        try {
            $existing = getActiveFollow($userId, $followType, $followId);
            if ($existing) {
                // Unfollow (soft-delete)
                $upd = db()->prepare("
                    UPDATE user_follows SET unfollowed_at = NOW()
                    WHERE id = ? AND user_id = ?
                ");
                $upd->execute([(int)$existing['id'], $userId]);
                jsonOut(['ok' => true, 'following' => false, 'row_id' => 0]);
            } else {
                // Follow — re-use a soft-deleted row if one exists, else insert
                $reuse = db()->prepare("
                    SELECT id FROM user_follows
                    WHERE user_id = ? AND follow_type = ? AND follow_id = ?
                      AND unfollowed_at IS NOT NULL
                    ORDER BY id DESC LIMIT 1
                ");
                $reuse->execute([$userId, $followType, $followId]);
                $reuseRow = $reuse->fetch();
                if ($reuseRow) {
                    $upd = db()->prepare("
                        UPDATE user_follows SET unfollowed_at = NULL, notify = 1, created_at = NOW()
                        WHERE id = ?
                    ");
                    $upd->execute([(int)$reuseRow['id']]);
                    if ($followType === 'listing') notifyListingOwner($userId, $followId);
                    jsonOut(['ok' => true, 'following' => true, 'row_id' => (int)$reuseRow['id']]);
                } else {
                    $ins = db()->prepare("
                        INSERT INTO user_follows (user_id, follow_type, follow_id, notify)
                        VALUES (?, ?, ?, 1)
                    ");
                    $ins->execute([$userId, $followType, $followId]);
                    $newRowId = (int)db()->lastInsertId();
                    if ($followType === 'listing') notifyListingOwner($userId, $followId);
                    jsonOut(['ok' => true, 'following' => true, 'row_id' => $newRowId]);
                }
            }
        } catch (Exception $e) {
            jsonOut(['ok' => false, 'error' => 'DB error'], 500);
        }
        break;
}
