<?php
require_once __DIR__ . '/includes/config.php';
$token = trim($_GET['token'] ?? '');
if (!$token) redirect(SITE_URL . '/login.php');
$st = db()->prepare("SELECT * FROM users WHERE verify_token = ? AND verified = 0");
$st->execute([$token]);
$user = $st->fetch();
if ($user) {
    db()->prepare("UPDATE users SET verified=1, verify_token=NULL WHERE id=?")
        ->execute([$user['id']]);

    // ── Automation trigger ────────────────────────────────
    if (file_exists(__DIR__ . '/automation/helper.php')) {
        require_once __DIR__ . '/automation/helper.php';
        addUserTag($user['id'], 'verified');
        enrollUser($user['id'], 'Welcome & Onboarding');
    }
    // ─────────────────────────────────────────────────────

    flash('success', t('Email verified! You can now sign in.','Email vérifié ! Vous pouvez maintenant vous connecter.'));
    redirect(SITE_URL . '/login.php?mode=login&verified=ok');
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
