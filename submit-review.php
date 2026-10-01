<?php
require_once __DIR__ . '/includes/config.php';
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
}

// Redirect back to listing
$st = db()->prepare("SELECT slug FROM listings WHERE id = ?");
$st->execute([$listingId]);
$row = $st->fetch();
redirect($row ? SITE_URL . '/listing.php?slug=' . $row['slug'] : SITE_URL . '/listings.php');
