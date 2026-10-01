<?php
/**
 * admin/login-as.php — 237Biz Admin Impersonation
 *
 * Allows admin to temporarily view the site as any agent or creator.
 * Stores the original admin session so they can return with one click.
 *
 * Usage: POST to /admin/login-as.php with user_id + csrf
 * Return: GET /admin/login-as.php?return=1
 */
require_once __DIR__ . '/../includes/config.php';
requireAdmin();

// ── Return to admin ───────────────────────────────────────────────────────
if (isset($_GET['return'])) {
    if (!empty($_SESSION['admin_original_id'])) {
        // Restore original admin session
        $_SESSION['user_id']   = $_SESSION['admin_original_id'];
        $_SESSION['user_role'] = 'admin';
        unset($_SESSION['admin_original_id'], $_SESSION['impersonating']);
        flash('success', 'Returned to your admin account.');
    }
    redirect(SITE_URL . '/admin/');
}

// ── Switch to user ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $targetId = (int)($_POST['user_id'] ?? 0);
    if (!$targetId) {
        flash('error', 'Invalid user.');
        redirect(SITE_URL . '/admin/');
    }

    // Load target user — only allow agents and creators (not other admins)
    $st = db()->prepare("SELECT id, name, email, role FROM users WHERE id = ?");
    $st->execute([$targetId]);
    $target = $st->fetch();

    if (!$target || !in_array($target['role'], ['sales_staff', 'creator', 'user'])) {
        flash('error', 'You can only impersonate agents, creators and standard users.');
        redirect(SITE_URL . '/admin/');
    }

    // Store the original admin ID so we can restore it
    $_SESSION['admin_original_id'] = $_SESSION['user_id'];
    $_SESSION['impersonating']     = $target['name'];

    // Switch session to target user
    $_SESSION['user_id']   = $target['id'];
    $_SESSION['user_role'] = $target['role'];

    // Redirect to the right portal
    if ($target['role'] === 'sales_staff') {
        $destination = SITE_URL . '/agent/dashboard';
    } elseif ($target['role'] === 'creator') {
        $destination = SITE_URL . '/creator/dashboard';
    } else {
        $destination = SITE_URL . '/dashboard';
    }

    redirect($destination);
}

// Direct GET with no params — redirect to admin
redirect(SITE_URL . '/admin/');
