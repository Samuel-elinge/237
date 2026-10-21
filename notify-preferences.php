<?php
/**
 * notify-preferences.php
 * Subscriber preference management — accessed via secure manage token.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/notify-helpers.php';

$rawToken = trim($_GET['token'] ?? '');
$sub      = $rawToken ? notifyGetSubscriberByToken($rawToken) : null;

if (!$sub) {
    // Invalid or expired token — redirect to subscribe
    $pageTitle = 'Preferences — 237biz';
    require_once __DIR__ . '/includes/header.php';
    ?>
    <section style="min-height:65vh;display:flex;align-items:center;justify-content:center;padding:3rem 1rem">
      <div style="max-width:440px;width:100%;text-align:center">
        <div style="font-size:2.5rem;margin-bottom:1rem">🔒</div>
        <h1 style="font-family:'Fraunces',serif;font-size:1.5rem;font-weight:900;margin:0 0 1rem">Link expired</h1>
        <p style="color:var(--muted);margin-bottom:2rem">This link is no longer valid. Subscribe again to manage your preferences.</p>
        <a href="<?= SITE_URL ?>/notify-subscribe.php"
           style="display:inline-block;background:#00A878;color:#fff;padding:0.8rem 2rem;border-radius:8px;text-decoration:none;font-weight:700">
          Subscribe
        </a>
      </div>
    </section>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$subId    = (int)$sub['id'];
$lang     = $sub['language'] ?? 'en';
$prefs    = notifyGetPreferences($subId);
$offersOn = (bool)($prefs['special_offers_enabled'] ?? 1);
$eventsOn = (bool)($prefs['events_enabled'] ?? 1);

$saved  = false;
$errors = [];

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $action = $_POST['action'] ?? 'update';

    if ($action === 'unsubscribe') {
        notifyUnsubscribe($subId, 'preferences_page');
        redirect(SITE_URL . '/notify-unsubscribe.php?done=1&lang=' . urlencode($lang));
    }

    // Update preferences
    $newLang     = in_array($_POST['lang'] ?? '', ['en','fr']) ? $_POST['lang'] : $lang;
    $newOffersOn = isset($_POST['interests']) && in_array('special_offers', $_POST['interests']);
    $newEventsOn = isset($_POST['interests']) && in_array('events', $_POST['interests']);

    if (!$newOffersOn && !$newEventsOn) {
        // Both unchecked = unsubscribe
        notifyUnsubscribe($subId, 'preferences_page');
        redirect(SITE_URL . '/notify-unsubscribe.php?done=1&lang=' . urlencode($newLang));
    }

    if (notifyUpdatePreferences($subId, $newOffersOn, $newEventsOn, $newLang)) {
        $lang     = $newLang;
        $offersOn = $newOffersOn;
        $eventsOn = $newEventsOn;
        $saved    = true;
    } else {
        $errors[] = $lang === 'fr' ? 'Erreur lors de la sauvegarde.' : 'Failed to save preferences.';
    }
}

$pageTitle = $lang === 'fr' ? 'Mes préférences e-mail — 237biz' : 'My email preferences — 237biz';
require_once __DIR__ . '/includes/header.php';
?>
<style>
.pref-card{
  max-width:520px;width:100%;margin:0 auto;
  background:var(--card);border:1px solid var(--border);border-radius:14px;
  padding:2.5rem 2rem;
}
.interest-option{
  display:flex;align-items:flex-start;gap:0.8rem;
  padding:0.9rem 1rem;border:1px solid var(--border);border-radius:9px;
  cursor:pointer;transition:border-color .15s,background .15s;
  margin-bottom:0.65rem;
}
.interest-option:has(input:checked){border-color:#00A878;background:rgba(0,168,120,0.06)}
.interest-option input[type=checkbox]{margin-top:2px;accent-color:#00A878;width:16px;height:16px;flex-shrink:0}
.interest-icon{font-size:1.4rem;line-height:1;flex-shrink:0}
.interest-text strong{display:block;font-size:0.9rem;margin-bottom:2px}
.interest-text small{color:var(--muted);font-size:0.78rem;line-height:1.4}
.btn-save{
  width:100%;padding:0.8rem;background:#00A878;color:#fff;border:none;
  border-radius:8px;cursor:pointer;font-size:0.95rem;font-weight:700;
  margin-top:1rem;transition:background .15s;
}
.btn-save:hover{background:#009068}
.btn-unsub{
  background:none;border:none;color:var(--muted-2);font-size:0.78rem;
  cursor:pointer;padding:0.5rem;margin-top:0.75rem;text-decoration:underline;
  width:100%;
}
.btn-unsub:hover{color:#ff6b6b}
.success-banner{
  background:rgba(0,168,120,0.1);border:1px solid rgba(0,168,120,0.3);
  border-radius:8px;padding:0.75rem 1rem;margin-bottom:1.25rem;
  color:#00A878;font-size:0.85rem;
}
</style>

<section style="min-height:70vh;display:flex;align-items:center;justify-content:center;padding:3rem 1rem">
  <div style="width:100%;max-width:560px">

    <div class="pref-card">
      <div style="font-size:1.8rem;margin-bottom:0.75rem">⚙️</div>
      <h1 style="font-family:'Fraunces',serif;font-size:1.5rem;font-weight:900;margin:0 0 0.3rem">
        <?= $lang === 'fr' ? 'Mes préférences e-mail' : 'My email preferences' ?>
      </h1>
      <p style="color:var(--muted);font-size:0.85rem;margin:0 0 1.5rem">
        <?= e($sub['email_display']) ?>
      </p>

      <?php if ($saved): ?>
      <div class="success-banner">
        ✓ <?= $lang === 'fr' ? 'Préférences sauvegardées !' : 'Preferences saved!' ?>
      </div>
      <?php endif; ?>

      <?php if ($errors): ?>
      <div style="background:rgba(255,107,107,0.1);border:1px solid rgba(255,107,107,0.3);border-radius:8px;padding:0.75rem 1rem;margin-bottom:1.25rem">
        <?php foreach ($errors as $err): ?>
        <p style="color:#ff8a8a;font-size:0.85rem;margin:0"><?= e($err) ?></p>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <form method="POST" action="/notify-preferences.php?token=<?= urlencode($rawToken) ?>">
        <?= csrf() ?>
        <input type="hidden" name="action" value="update">

        <!-- Language preference -->
        <div style="margin-bottom:1.25rem">
          <label style="display:block;color:var(--muted);font-size:0.78rem;font-weight:500;margin-bottom:0.4rem;letter-spacing:0.02em;text-transform:uppercase">
            <?= $lang === 'fr' ? 'Langue des e-mails' : 'Email language' ?>
          </label>
          <select name="lang"
                  style="background:var(--bg);border:1px solid var(--border);border-radius:7px;color:var(--white);padding:0.65rem 0.9rem;font-size:0.9rem;width:100%;cursor:pointer">
            <option value="en" <?= $lang === 'en' ? 'selected' : '' ?>>🇬🇧 English</option>
            <option value="fr" <?= $lang === 'fr' ? 'selected' : '' ?>>🇫🇷 Français</option>
          </select>
        </div>

        <!-- Interests -->
        <div style="margin-bottom:0.25rem">
          <span style="display:block;color:var(--muted);font-size:0.78rem;font-weight:500;margin-bottom:0.65rem;letter-spacing:0.02em;text-transform:uppercase">
            <?= $lang === 'fr' ? 'Je veux recevoir :' : 'I want updates about:' ?>
          </span>

          <label class="interest-option">
            <input type="checkbox" name="interests[]" value="special_offers"
                   <?= $offersOn ? 'checked' : '' ?>>
            <span class="interest-icon">🏷️</span>
            <span class="interest-text">
              <strong><?= $lang === 'fr' ? 'Offres spéciales' : 'Special offers' ?></strong>
              <small><?= $lang === 'fr'
                ? 'Réductions, promotions et offres exclusives'
                : 'Discounts, promotions and exclusive deals' ?></small>
            </span>
          </label>

          <label class="interest-option">
            <input type="checkbox" name="interests[]" value="events"
                   <?= $eventsOn ? 'checked' : '' ?>>
            <span class="interest-icon">📅</span>
            <span class="interest-text">
              <strong><?= $lang === 'fr' ? 'Événements' : 'Events' ?></strong>
              <small><?= $lang === 'fr'
                ? 'Événements locaux et activités'
                : 'Local events and activities' ?></small>
            </span>
          </label>
        </div>

        <p style="font-size:0.75rem;color:var(--muted-2);margin-top:0.75rem">
          <?= $lang === 'fr'
            ? 'Décochez toutes les cases pour vous désabonner entièrement.'
            : 'Uncheck all boxes to unsubscribe completely.' ?>
        </p>

        <button type="submit" class="btn-save">
          <?= $lang === 'fr' ? '💾 Sauvegarder mes préférences' : '💾 Save my preferences' ?>
        </button>
      </form>

      <form method="POST" action="/notify-preferences.php?token=<?= urlencode($rawToken) ?>"
            style="text-align:center"
            onsubmit="return confirm('<?= $lang === 'fr' ? 'Se désabonner de tous les e-mails 237biz ?' : 'Unsubscribe from all 237biz emails?' ?>')">
        <?= csrf() ?>
        <input type="hidden" name="action" value="unsubscribe">
        <button type="submit" class="btn-unsub">
          <?= $lang === 'fr' ? 'Se désabonner de tous les e-mails' : 'Unsubscribe from all emails' ?>
        </button>
      </form>

    </div>

    <p style="text-align:center;margin-top:1.5rem">
      <a href="<?= SITE_URL ?>" style="color:var(--muted);font-size:0.82rem;text-decoration:none">
        ← <?= $lang === 'fr' ? 'Retour à 237biz' : 'Back to 237biz' ?>
      </a>
    </p>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
