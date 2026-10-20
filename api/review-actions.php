<?php
/**
 * Review Actions API — 237Biz
 *
 * POST /api/review-actions.php
 *   action=reply   — listing owner posts/edits their reply
 *   action=vote    — logged-in user votes helpful / not-helpful
 *
 * Returns JSON { ok: bool, ... }
 */

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function jsonOut(array $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Must be logged in for every action
if (!isLoggedIn()) {
    jsonOut(['ok' => false, 'error' => 'auth'], 401);
}

verifyCsrfOrDie();

$action    = trim($_POST['action'] ?? '');
$review_id = (int)($_POST['review_id'] ?? 0);

if (!$review_id) {
    jsonOut(['ok' => false, 'error' => 'missing review_id'], 400);
}

// ── Fetch the review ──────────────────────────────────────────────────────────
try {
    $rq = db()->prepare("
        SELECT r.*, l.user_id AS listing_owner_id
        FROM reviews r
        JOIN listings l ON l.id = r.listing_id
        WHERE r.id = ? AND r.status = 'approved'
    ");
    $rq->execute([$review_id]);
    $review = $rq->fetch();
} catch (Exception $e) {
    jsonOut(['ok' => false, 'error' => 'db'], 500);
}

if (!$review) {
    jsonOut(['ok' => false, 'error' => 'not_found'], 404);
}

$u = currentUser();

// ── action=reply ─────────────────────────────────────────────────────────────
if ($action === 'reply') {
    // Only the listing owner may reply
    if ((int)$review['listing_owner_id'] !== (int)$u['id']) {
        jsonOut(['ok' => false, 'error' => 'forbidden'], 403);
    }

    $reply = trim($_POST['reply'] ?? '');

    if (mb_strlen($reply) > 2000) {
        jsonOut(['ok' => false, 'error' => 'too_long'], 422);
    }

    try {
        if ($reply === '') {
            // Clear reply
            $sq = db()->prepare("UPDATE reviews SET owner_reply=NULL, owner_reply_at=NULL WHERE id=?");
            $sq->execute([$review_id]);
        } else {
            $sq = db()->prepare("UPDATE reviews SET owner_reply=?, owner_reply_at=NOW() WHERE id=?");
            $sq->execute([$reply, $review_id]);
        }
    } catch (Exception $e) {
        jsonOut(['ok' => false, 'error' => 'db'], 500);
    }

    jsonOut(['ok' => true, 'reply' => $reply, 'cleared' => ($reply === '')]);
}

// ── action=vote ───────────────────────────────────────────────────────────────
if ($action === 'vote') {
    $vote = (int)($_POST['vote'] ?? 0);
    if (!in_array($vote, [1, -1], true)) {
        jsonOut(['ok' => false, 'error' => 'invalid_vote'], 422);
    }

    // Users cannot vote on their own review
    if ((int)$review['user_id'] === (int)$u['id']) {
        jsonOut(['ok' => false, 'error' => 'own_review'], 422);
    }

    try {
        // Upsert vote
        $vq = db()->prepare("
            INSERT INTO review_votes (review_id, user_id, vote, created_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE vote = VALUES(vote)
        ");
        $vq->execute([$review_id, $u['id'], $vote]);

        // Return updated tallies
        $tq = db()->prepare("
            SELECT
                SUM(vote = 1)  AS helpful,
                SUM(vote = -1) AS not_helpful
            FROM review_votes
            WHERE review_id = ?
        ");
        $tq->execute([$review_id]);
        $tally = $tq->fetch();
    } catch (Exception $e) {
        jsonOut(['ok' => false, 'error' => 'db'], 500);
    }

    jsonOut([
        'ok'          => true,
        'helpful'     => (int)($tally['helpful'] ?? 0),
        'not_helpful' => (int)($tally['not_helpful'] ?? 0),
        'user_vote'   => $vote,
    ]);
}

jsonOut(['ok' => false, 'error' => 'unknown_action'], 400);
