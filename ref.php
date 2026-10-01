<?php
/**
 * ref.php — 237Biz Referral Link Handler
 * URL: /r/{code}  (e.g. /r/sam237)
 *
 * Tracks the click, sets a cookie, then redirects to homepage or intended page.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/referral-helpers.php';

$code = trim($_GET['code'] ?? '');
$redirect = trim($_GET['to'] ?? '/');

// Sanitise redirect
if (strpos($redirect, '/') !== 0 || strpos($redirect, '//') !== false) {
    $redirect = '/';
}

if (!$code) {
    redirect(SITE_URL . $redirect);
}

try {
    $link = getLinkByCode($code);

    if ($link) {
        // Track click
        $ipHash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        recordReferralClick((int)$link['id'], $ipHash);

        // Get cookie duration from programme
        $days = 30;
        try {
            $prog = db()->prepare("SELECT cookie_days FROM referral_programmes WHERE id=?");
            $prog->execute([$link['programme_id']]);
            $days = (int)($prog->fetchColumn() ?: 30);
        } catch (Exception $e) {}

        setReferralCookie($code, $days);
    }
} catch (Exception $e) {
    // Silently fail — still redirect
}

redirect(SITE_URL . $redirect);
