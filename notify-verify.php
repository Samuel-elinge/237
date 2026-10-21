<?php
/**
 * notify-verify.php
 * Double opt-in email verification endpoint.
 * Validates token, activates subscriber, sends confirmation email.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/notify-helpers.php';

$rawToken = trim($_GET['token'] ?? '');
$result   = null;

if ($rawToken) {
    $result = notifyVerifyToken($rawToken);

    if ($result['ok']) {
        $subId = $result['subscriber_id'];
        $prefs = notifyGetPreferences($subId);
        if ($prefs) {
            $lang     = $prefs['language'] ?? 'en';
            $offersOn = (bool)($prefs['special_offers_enabled'] ?? 1);
            $eventsOn = (bool)($prefs['events_enabled'] ?? 1);
            notifySendConfirmationEmail($prefs['email_display'], $lang, $offersOn, $eventsOn, $subId);
        }
    }
} else {
    $result = ['ok' => false, 'reason' => 'missing_token'];
}

$lang = 'en';
if ($result['ok'] && isset($prefs)) {
    $lang = $prefs['language'] ?? 'en';
}

$pageTitle = $lang === 'fr' ? 'Abonnement confirmé — 237biz' : 'Subscription confirmed — 237biz';
require_once __DIR__ . '/includes/header.php';
?>
<section style="min-height:65vh;display:flex;align-items:center;justify-content:center;padding:3rem 1rem">
  <div style="max-width:480px;width:100%;text-align:center">

    <?php if ($result['ok']): ?>

      <div style="font-size:3.5rem;margin-bottom:1rem">🎉</div>
      <h1 style="font-family:'Fraunces',serif;font-size:1.8rem;font-weight:900;margin:0 0 1rem">
        <?= $lang === 'fr' ? 'Vous êtes abonné !' : 'You\'re subscribed!' ?>
      </h1>
      <p style="color:var(--muted);line-height:1.65;margin-bottom:2rem">
        <?= $lang === 'fr'
          ? 'Votre adresse e-mail a été confirmée. Vous recevrez prochainement vos premières mises à jour 237biz.'
          : 'Your email address has been confirmed. You\'ll receive your first 237biz updates soon.' ?>
      </p>

      <?php
      // Link to manage preferences using a fresh manage token
      $subId      = $result['subscriber_id'];
      $manageToken = notifyGetManageToken($subId);
      $manageUrl   = SITE_URL . '/notify-preferences.php?token=' . urlencode($manageToken);
      ?>
      <div style="display:flex;flex-direction:column;align-items:center;gap:0.75rem">
        <a href="<?= SITE_URL ?>"
           style="display:inline-block;background:#00A878;color:#fff;padding:0.8rem 2rem;border-radius:8px;text-decoration:none;font-weight:700;font-size:0.95rem">
          <?= $lang === 'fr' ? '→ Explorer 237biz' : '→ Explore 237biz' ?>
        </a>
        <a href="<?= e($manageUrl) ?>"
           style="font-size:0.82rem;color:var(--muted);text-decoration:none">
          <?= $lang === 'fr' ? 'Gérer mes préférences' : 'Manage my preferences' ?>
        </a>
      </div>

    <?php elseif ($result['reason'] === 'expired'): ?>

      <div style="font-size:3rem;margin-bottom:1rem">⏰</div>
      <h1 style="font-family:'Fraunces',serif;font-size:1.6rem;font-weight:900;margin:0 0 1rem">
        <?= $lang === 'fr' ? 'Lien expiré' : 'Link expired' ?>
      </h1>
      <p style="color:var(--muted);line-height:1.65;margin-bottom:2rem">
        <?= $lang === 'fr'
          ? 'Ce lien de confirmation a expiré (durée de validité : 24 heures). Abonnez-vous à nouveau pour recevoir un nouveau lien.'
          : 'This confirmation link has expired (valid for 24 hours). Subscribe again to receive a new link.' ?>
      </p>
      <a href="<?= SITE_URL ?>/notify-subscribe.php"
         style="display:inline-block;background:#00A878;color:#fff;padding:0.8rem 2rem;border-radius:8px;text-decoration:none;font-weight:700">
        <?= $lang === 'fr' ? 'S\'abonner à nouveau' : 'Subscribe again' ?>
      </a>

    <?php else: ?>

      <div style="font-size:3rem;margin-bottom:1rem">❌</div>
      <h1 style="font-family:'Fraunces',serif;font-size:1.6rem;font-weight:900;margin:0 0 1rem">
        <?= $lang === 'fr' ? 'Lien invalide' : 'Invalid link' ?>
      </h1>
      <p style="color:var(--muted);line-height:1.65;margin-bottom:2rem">
        <?= $lang === 'fr'
          ? 'Ce lien de confirmation est invalide ou a déjà été utilisé.'
          : 'This confirmation link is invalid or has already been used.' ?>
      </p>
      <a href="<?= SITE_URL ?>/notify-subscribe.php"
         style="display:inline-block;background:#00A878;color:#fff;padding:0.8rem 2rem;border-radius:8px;text-decoration:none;font-weight:700">
        <?= $lang === 'fr' ? 'S\'abonner' : 'Subscribe' ?>
      </a>

    <?php endif; ?>

  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
