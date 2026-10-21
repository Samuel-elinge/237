<?php
/**
 * notify-subscribe.php
 * Public double opt-in subscription form.
 * Spec: v1.0 — 21 Oct 2026
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/notify-helpers.php';

$lang    = in_array($_GET['lang'] ?? '', ['en','fr']) ? ($_GET['lang']) : 'en';
$source  = 'homepage';

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $email     = trim($_POST['email'] ?? '');
    $interests = array_intersect($_POST['interests'] ?? [], ['special_offers','events']);
    $langPost  = in_array($_POST['lang'] ?? '', ['en','fr']) ? $_POST['lang'] : 'en';
    $source    = preg_replace('/[^a-z_]/', '', strtolower($_POST['source'] ?? 'homepage'));

    // Validate
    $errors = [];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = $langPost === 'fr' ? 'Adresse e-mail invalide.' : 'Invalid email address.';
    }
    if (strlen($email) > 254) {
        $errors[] = $langPost === 'fr' ? 'Adresse e-mail trop longue.' : 'Email address too long.';
    }
    if (empty($interests)) {
        $errors[] = $langPost === 'fr'
            ? 'Veuillez sélectionner au moins un type de mise à jour.'
            : 'Please select at least one type of update.';
    }

    if (!$errors) {
        // Rate limit
        if (!notifyCheckRateLimit()) {
            $errors[] = $langPost === 'fr'
                ? 'Trop de tentatives. Veuillez réessayer dans une heure.'
                : 'Too many attempts. Please try again in an hour.';
        }
    }

    if (!$errors) {
        $result = notifySubscribe($email, $interests, $langPost, $source);

        if ($result['ok']) {
            // If suppressed_silent, we still show "check inbox" — don't reveal suppression
            if ($result['reason'] !== 'suppressed_silent' && isset($result['raw_token'])) {
                // Send verification email
                notifySendVerificationEmail($email, $langPost, $result['raw_token']);
            }
            // Always redirect to "check inbox" confirmation page
            redirect(SITE_URL . '/notify-subscribe.php?done=1&lang=' . urlencode($langPost));
        } else {
            $errors[] = $langPost === 'fr'
                ? 'Une erreur est survenue. Veuillez réessayer.'
                : 'Something went wrong. Please try again.';
        }
    }

    if ($errors) {
        // Re-display form with errors
        $formEmail     = e($email);
        $formInterests = $interests;
        $lang          = $langPost;
    }
}

// ── Done page ─────────────────────────────────────────────────────────────────
if (isset($_GET['done'])) {
    $lang = in_array($_GET['lang'] ?? '', ['en','fr']) ? $_GET['lang'] : 'en';
    $pageTitle = $lang === 'fr' ? 'Vérifiez votre boîte de réception — 237biz' : 'Check your inbox — 237biz';
    require_once __DIR__ . '/includes/header.php';
    ?>
    <section style="min-height:60vh;display:flex;align-items:center;justify-content:center;padding:3rem 1rem">
      <div style="max-width:480px;width:100%;text-align:center">
        <div style="font-size:3rem;margin-bottom:1rem">📬</div>
        <h1 style="font-family:'Fraunces',serif;font-size:1.8rem;font-weight:900;margin:0 0 1rem">
          <?= $lang === 'fr' ? 'Vérifiez votre boîte de réception !' : 'Check your inbox!' ?>
        </h1>
        <p style="color:var(--muted);line-height:1.65;margin-bottom:1.5rem">
          <?= $lang === 'fr'
            ? 'Nous vous avons envoyé un e-mail de confirmation. Cliquez sur le lien pour activer votre abonnement.'
            : 'We\'ve sent you a confirmation email. Click the link inside to activate your subscription.' ?>
        </p>
        <p style="color:var(--muted-2);font-size:0.85rem">
          <?= $lang === 'fr'
            ? 'Le lien expire dans 24 heures. Vérifiez vos spams si vous ne le voyez pas.'
            : 'The link expires in 24 hours. Check your spam folder if you don\'t see it.' ?>
        </p>
        <a href="<?= SITE_URL ?>" style="display:inline-block;margin-top:2rem;color:#00A878;text-decoration:none;font-size:0.9rem">
          ← <?= $lang === 'fr' ? 'Retour à l\'accueil' : 'Back to homepage' ?>
        </a>
      </div>
    </section>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// ── Form defaults ─────────────────────────────────────────────────────────────
$formEmail     = $formEmail ?? '';
$formInterests = $formInterests ?? ['special_offers','events'];
$errors        = $errors ?? [];
$pageTitle     = $lang === 'fr' ? 'S\'abonner aux actualités — 237biz' : 'Subscribe to updates — 237biz';

require_once __DIR__ . '/includes/header.php';
?>
<style>
.notify-card{
  max-width:520px;width:100%;margin:0 auto;
  background:var(--card);border:1px solid var(--border);border-radius:14px;
  padding:2.5rem 2rem;
}
.notify-card h1{font-family:'Fraunces',serif;font-size:1.6rem;font-weight:900;margin:0 0 0.4rem}
.notify-card .subtitle{color:var(--muted);font-size:0.9rem;margin:0 0 1.75rem;line-height:1.5}
.field-label{display:block;color:var(--muted);font-size:0.8rem;font-weight:500;margin-bottom:0.4rem;letter-spacing:0.02em;text-transform:uppercase}
.field-input{
  width:100%;background:var(--bg);border:1px solid var(--border);border-radius:8px;
  color:var(--white);padding:0.7rem 0.9rem;font-size:0.95rem;
  box-sizing:border-box;transition:border-color .15s;
}
.field-input:focus{outline:none;border-color:#00A878}
.interest-option{
  display:flex;align-items:flex-start;gap:0.8rem;
  padding:0.9rem 1rem;border:1px solid var(--border);border-radius:9px;
  cursor:pointer;transition:border-color .15s,background .15s;
  margin-bottom:0.65rem;
}
.interest-option:has(input:checked){border-color:#00A878;background:rgba(0,168,120,0.06)}
.interest-option input[type=checkbox]{
  margin-top:2px;accent-color:#00A878;width:16px;height:16px;flex-shrink:0
}
.interest-icon{font-size:1.4rem;line-height:1;flex-shrink:0}
.interest-text strong{display:block;font-size:0.9rem;margin-bottom:2px}
.interest-text small{color:var(--muted);font-size:0.78rem;line-height:1.4}
.consent-block{font-size:0.78rem;color:var(--muted-2);line-height:1.55;margin-top:1.25rem;padding-top:1rem;border-top:1px solid var(--border)}
.btn-subscribe{
  width:100%;padding:0.85rem;background:#00A878;color:#fff;border:none;
  border-radius:8px;cursor:pointer;font-size:1rem;font-weight:700;
  margin-top:1.25rem;transition:background .15s;letter-spacing:0.01em;
}
.btn-subscribe:hover{background:#009068}
.error-list{background:rgba(255,107,107,0.1);border:1px solid rgba(255,107,107,0.3);border-radius:8px;padding:0.8rem 1rem;margin-bottom:1.25rem}
.error-list li{color:#ff8a8a;font-size:0.85rem;margin:0;list-style:none}
.error-list li+li{margin-top:0.3rem}
.lang-switcher{text-align:right;margin-bottom:1rem}
.lang-switcher a{font-size:0.78rem;color:var(--muted);text-decoration:none}
.lang-switcher a:hover{color:#00A878}
</style>

<section style="min-height:70vh;display:flex;align-items:center;justify-content:center;padding:3rem 1rem">
  <div style="width:100%;max-width:560px">

    <div class="lang-switcher">
      <?php if ($lang === 'en'): ?>
        <a href="?lang=fr">🇫🇷 Français</a>
      <?php else: ?>
        <a href="?lang=en">🇬🇧 English</a>
      <?php endif; ?>
    </div>

    <div class="notify-card">
      <div style="font-size:2rem;margin-bottom:0.75rem">🔔</div>
      <h1>
        <?= $lang === 'fr' ? 'Restez informé' : 'Stay in the loop' ?>
      </h1>
      <p class="subtitle">
        <?= $lang === 'fr'
          ? 'Recevez les offres spéciales et événements de 237biz directement dans votre boîte de réception. Désabonnez-vous à tout moment.'
          : 'Get 237biz special offers and events delivered to your inbox. Unsubscribe at any time.' ?>
      </p>

      <?php if ($errors): ?>
      <ul class="error-list">
        <?php foreach ($errors as $err): ?>
        <li><?= e($err) ?></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>

      <form method="POST" action="/notify-subscribe.php" novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="lang"   value="<?= e($lang) ?>">
        <input type="hidden" name="source" value="<?= e($source) ?>">

        <div style="margin-bottom:1.25rem">
          <label class="field-label" for="email">
            <?= $lang === 'fr' ? 'Adresse e-mail' : 'Email address' ?>
          </label>
          <input
            type="email"
            id="email"
            name="email"
            class="field-input"
            value="<?= $formEmail ?>"
            placeholder="<?= $lang === 'fr' ? 'vous@exemple.fr' : 'you@example.com' ?>"
            required
            autocomplete="email"
            maxlength="254"
          >
        </div>

        <div style="margin-bottom:0.25rem">
          <span class="field-label" style="display:block;margin-bottom:0.65rem">
            <?= $lang === 'fr' ? 'Je veux recevoir :' : 'I want updates about:' ?>
          </span>

          <label class="interest-option">
            <input type="checkbox" name="interests[]" value="special_offers"
                   <?= in_array('special_offers', $formInterests) ? 'checked' : '' ?>>
            <span class="interest-icon">🏷️</span>
            <span class="interest-text">
              <strong><?= $lang === 'fr' ? 'Offres spéciales' : 'Special offers' ?></strong>
              <small><?= $lang === 'fr'
                ? 'Réductions, promotions et offres exclusives des entreprises 237biz'
                : 'Discounts, promotions and exclusive deals from 237biz businesses' ?></small>
            </span>
          </label>

          <label class="interest-option">
            <input type="checkbox" name="interests[]" value="events"
                   <?= in_array('events', $formInterests) ? 'checked' : '' ?>>
            <span class="interest-icon">📅</span>
            <span class="interest-text">
              <strong><?= $lang === 'fr' ? 'Événements' : 'Events' ?></strong>
              <small><?= $lang === 'fr'
                ? 'Événements locaux, activités et découvrir ce qui se passe'
                : 'Local events, activities and what\'s happening in your area' ?></small>
            </span>
          </label>
        </div>

        <p class="consent-block">
          <?php if ($lang === 'fr'): ?>
            En vous abonnant, vous acceptez de recevoir les mises à jour e-mail que vous avez sélectionnées de 237biz.
            Vous pouvez vous désabonner à tout moment via le lien en bas de chaque e-mail.
            Consultez notre <a href="<?= SITE_URL ?>/privacy" style="color:#00A878">politique de confidentialité</a>.
          <?php else: ?>
            By subscribing, you agree to receive the email updates you have selected from 237biz.
            You can unsubscribe at any time using the link at the bottom of any email.
            See our <a href="<?= SITE_URL ?>/privacy" style="color:#00A878">privacy policy</a>.
          <?php endif; ?>
        </p>

        <button type="submit" class="btn-subscribe">
          <?= $lang === 'fr' ? '✉️ S\'abonner — c\'est gratuit' : '✉️ Subscribe — it\'s free' ?>
        </button>
      </form>
    </div>

  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
