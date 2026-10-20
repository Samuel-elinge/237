<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/notify.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(SITE_URL . '/index.php');
verifyCsrf();

$listingId = (int)($_POST['listing_id'] ?? 0);
$rating    = (int)($_POST['rating'] ?? 0);
$comment   = trim($_POST['comment'] ?? '');
$u         = currentUser();

if ($listingId && $rating >= 1 && $rating <= 5) {
    $st = db()->prepare("INSERT INTO reviews (listing_id, user_id, name, rating, comment, status) VALUES (?,?,?,?,?,'pending')");
    $st->execute([$listingId, $u['id'], $u['name'], $rating, $comment]);
    flash('success', t('Review submitted! It will appear after moderation.','Avis soumis ! Il apparaîtra après modération.'));

    // Notify listing owner of the new review (non-critical)
    try {
        $ls2 = db()->prepare("SELECT user_id, title, slug FROM listings WHERE id = ? LIMIT 1");
        $ls2->execute([$listingId]);
        $listing2 = $ls2->fetch();
        if ($listing2 && $listing2['user_id'] && (int)$listing2['user_id'] !== (int)$u['id']) {
            $stars = str_repeat('⭐', $rating);
            dispatch_notification(
                (int)$listing2['user_id'],
                'bell',
                "New {$rating}-star review on " . $listing2['title'],
                "Nouvel avis {$stars} pour " . $listing2['title'],
                "{$u['name']} left a review: \"" . mb_substr($comment, 0, 100) . (mb_strlen($comment) > 100 ? '…' : '') . "\"",
                "{$u['name']} a laissé un avis.",
                '/listing/' . $listing2['slug'],
                'listing',
                $listingId,
                null  // always notify owner of reviews (no pref gating for this)
            );
        }
    } catch (Exception $e) { /* non-critical */ }
}

// Redirect back to listing
$st = db()->prepare("SELECT slug FROM listings WHERE id = ?");
$st->execute([$listingId]);
$row = $st->fetch();
redirect($row ? SITE_URL . '/listing.php?slug=' . $row['slug'] : SITE_URL . '/listings.php');
