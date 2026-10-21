<?php
require_once __DIR__ . '/includes/config.php';
$token = trim($_GET['token'] ?? '');
if (!$token) redirect(SITE_URL . '/login.php');

// Token valid for 48 hours (users.verify_token_created_at column added by migration below;
// fall back gracefully if column does not exist yet)
$st = db()->prepare("SELECT * FROM users WHERE verify_token = ? AND verified = 0");
$st->execute([$token]);
$user = $st->fetch();

// Check 48-hour expiry if column exists
if ($user) {
    try {
        $expCheck = db()->prepare("SELECT verify_token_created_at FROM users WHERE id=?");
        $expCheck->execute([$user['id']]);
        $row = $expCheck->fetch();
        if ($row && !empty($row['verify_token_created_at'])) {
            if (strtotime($row['verify_token_created_at']) < (time() - 172800)) {
                // Expired — clear token so resend-verify generates a new one
                db()->prepare("UPDATE users SET verify_token=NULL WHERE id=?")->execute([$user['id']]);
                flash('error', t('Verification link has expired. Please request a new one.','Le lien de vérification a expiré. Veuillez en demander un nouveau.'));
                redirect(SITE_URL . '/resend-verify.php');
                exit;
            }
        }
    } catch (Exception $e) { /* column may not exist yet — skip expiry check */ }
}

if ($user) {
    db()->prepare("UPDATE users SET verified=1, verify_token=NULL WHERE id=?")
        ->execute([$user['id']]);
    // Log user in immediately after verification
    $_SESSION['user_id'] = $user['id'];

    // ── Automation trigger ────────────────────────────────
    if (file_exists(__DIR__ . '/automation/helper.php')) {
        require_once __DIR__ . '/automation/helper.php';
        addUserTag($user['id'], 'verified');
        enrollUser($user['id'], 'Welcome & Onboarding');
    }
    // ─────────────────────────────────────────────────────

    flash('success', t('Email verified! Welcome to 237Biz.','Email vérifié ! Bienvenue sur 237Biz.'));
    redirect(SITE_URL . '/dashboard');
} else {
    // Check if already verified
    $st2 = db()->prepare("SELECT id FROM users WHERE verify_token = ?");
    $st2->execute([$token]);
    if (!$st2->fetch()) {
        flash('error', t('Invalid or expired verification link. Please register again.',
                         'Lien de vérification invalide ou expiré. Veuillez vous réinscrire.'));
    } else {
        flash('success', t('Account already verified. Please sign in.','Compte déjà vérifié. Veuillez vous connecter.'));
    }
    redirect(SITE_URL . '/login.php');
}
