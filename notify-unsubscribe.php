<?php
/**
 * notify-unsubscribe.php
 * One-click unsubscribe — handles List-Unsubscribe-Post (RFC 8058) and GET.
 *
 * GET  /notify-unsubscribe.php?token=<raw>        — show confirm page
 * POST /notify-unsubscribe.php?token=<raw>         — one-click (List-Unsubscribe-Post)
 * GET  /notify-unsubscribe.php?done=1&lang=<lang> — confirmation page
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/notify-helpers.php';

// ── List-Unsubscribe-Post (RFC 8058) one-click ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify it's the RFC 8058 List-Unsubscribe-Post format
    $listUnsub = $_POST['List-Unsubscribe'] ?? $_POST['list-unsubscribe'] ?? null;
    $rawToken  = trim($_GET['token'] ?? '');

    if ($rawToken) {
        $sub = notifyGetSubscriberByToken($rawToken);
        if ($sub) {
            notifyUnsubscribe((int)$sub['id'], 'list_unsubscribe_post');
        }
    }
    // RFC 8058 response — simple 200 OK
    http_response_code(200);
    exit('OK');
}

// ── Done page ─────────────────────────────────────────────────────────────────
if (isset($_GET['done'])) {
    $lang      = in_array($_GET['lang'] ?? '', ['en','fr']) ? $_GET['lang'] : 'en';
    $pageTitle = $lang === 'fr' ? 'Désabonnement confirmé — 237biz' : 'Unsubscribed — 237biz';
    require_once __DIR__ . '/includes/header.php';
    ?>
    <section style="min-height:65vh;display:flex;align-items:center;justify-content:center;padding:3rem 1rem">
      <div style="max-width:440px;width:100%;text-align:center">
        <div style="font-size:3rem;margin-bottom:1rem">👋</div>
        <h1 style="font-family:'Fraunces',serif;font-size:1.7rem;font-weight:900;margin:0 0 1rem">
          <?= $lang === 'fr' ? 'Vous êtes désabonné' : 'You\'ve unsubscribed' ?>
        </h1>
        <p style="color:var(--muted);line-height:1.65;margin-bottom:2rem">
          <?= $lang === 'fr'
            ? 'Vous ne recevrez plus d\'e-mails de 237biz. Vous pouvez vous réabonner à tout moment.'
            : 'You\'ll no longer receive emails from 237biz. You can re-subscribe at any time.' ?>
        </p>
        <div style="display:flex;flex-direction:column;align-items:center;gap:0.75rem">
          <a href="<?= SITE_URL ?>"
             style="display:inline-block;background:#00A878;color:#fff;padding:0.8rem 2rem;border-radius:8px;text-decoration:none;font-weight:700">
            <?= $lang === 'fr' ? '→ Retour à 237biz' : '→ Back to 237biz' ?>
          </a>
          <a href="<?= SITE_URL ?>/notify-subscribe.php?lang=<?= urlencode($lang) ?>"
             style="font-size:0.82rem;color:var(--muted);text-decoration:none">
            <?= $lang === 'fr' ? 'Se réabonner' : 'Re-subscribe' ?>
          </a>
        </div>
      </div>
    </section>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// ── Confirm page (GET with token) ─────────────────────────────────────────────
$rawToken = trim($_GET['token'] ?? '');
$sub      = $rawToken ? notifyGetSubscriberByToken($rawToken) : null;
$lang     = $sub ? ($sub['language'] ?? 'en') : 'en';

if (!$sub) {
    $pageTitle = 'Unsubscribe — 237biz';
    require_once __DIR__ . '/includes/header.php';
    ?>
    <section style="min-height:65vh;display:flex;align-items:center;justify-content:center;padding:3rem 1rem">
      <div style="max-width:440px;width:100%;text-align:center">
        <div style="font-size:2.5rem;margin-bottom:1rem">🔒</div>
        <h1 style="font-family:'Fraunces',serif;font-size:1.5rem;font-weight:900;margin:0 0 1rem">Link expired</h1>
        <p style="color:var(--muted);margin-bottom:1.5rem">This unsubscribe link is no longer valid.</p>
        <a href="<?= SITE_URL ?>" style="color:#00A878;text-decoration:none">← Back to 237biz</a>
      </div>
    </section>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// ── Handle confirm POST from the confirm page ─────────────────────────────────
// (Note: the confirm page posts back here via the form below)

$pageTitle = $lang === 'fr' ? 'Se désabonner — 237biz' : 'Unsubscribe — 237biz';
require_once __DIR__ . '/includes/header.php';
?>
<section style="min-height:65vh;display:flex;align-items:center;justify-content:center;padding:3rem 1rem">
  <div style="max-width:440px;width:100%;text-align:center">

    <div style="font-size:2.5rem;margin-bottom:1rem">🔕</div>
    <h1 style="font-family:'Fraunces',serif;font-size:1.6rem;font-weight:900;margin:0 0 0.75rem">
      <?= $lang === 'fr' ? 'Se désabonner ?' : 'Unsubscribe?' ?>
    </h1>
    <p style="color:var(--muted);line-height:1.65;margin-bottom:0.5rem">
      <?= $lang === 'fr'
        ? 'Confirmer le désabonnement pour :'
        : 'Confirm unsubscribe for:' ?>
    </p>
    <p style="font-weight:600;margin:0 0 1.5rem;color:var(--white)">
      <?= e($sub['email_display']) ?>
    </p>

    <div style="display:flex;flex-direction:column;gap:0.75rem;align-items:center">

      <form method="POST" action="/notify-preferences.php?token=<?= urlencode($rawToken) ?>" style="width:100%;max-width:280px">
        <?= csrf() ?>
        <input type="hidden" name="action" value="unsubscribe">
        <button type="submit"
                style="width:100%;padding:0.8rem;background:#ff6b6b;color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:700;font-size:0.95rem">
          <?= $lang === 'fr' ? '✓ Oui, me désabonner' : '✓ Yes, unsubscribe me' ?>
        </button>
      </form>

      <a href="<?= SITE_URL ?>/notify-preferences.php?token=<?= urlencode($rawToken) ?>"
         style="display:block;width:100%;max-width:280px;padding:0.8rem;background:var(--card);border:1px solid var(--border);border-radius:8px;color:var(--white);text-decoration:none;font-size:0.9rem;box-sizing:border-box">
        <?= $lang === 'fr' ? '← Non, garder mon abonnement' : '← No, keep my subscription' ?>
      </a>

      <a href="<?= SITE_URL ?>/notify-preferences.php?token=<?= urlencode($rawToken) ?>"
         style="font-size:0.8rem;color:var(--muted);text-decoration:none;margin-top:0.5rem">
        <?= $lang === 'fr' ? 'Modifier mes préférences à la place' : 'Update my preferences instead' ?>
      </a>
    </div>

  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
