<?php
/**
 * suggest-business.php — Public "Suggest / Request a Business" page.
 *
 * Renders a form and handles its submission.  Saves to suggested_listings.
 * No login required; rate-limited by IP (max 3 per 24 h).
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/partner-helpers.php';

$pdo    = db();
$isFr   = lang() === 'fr';
$errors = [];
$done   = false;

// ── Load dropdowns ────────────────────────────────────────────────────────────
$cats = $pdo->query("SELECT id, name_en, name_fr, icon FROM categories ORDER BY sort_order")->fetchAll();
$locs = $pdo->query("SELECT id, slug, name_en, name_fr FROM locations ORDER BY sort_order")->fetchAll();

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    gpVerifyCsrf();

    $businessName   = trim($_POST['business_name']   ?? '');
    $categoryId     = (int)($_POST['category_id']    ?? 0) ?: null;
    $locationId     = (int)($_POST['location_id']    ?? 0) ?: null;
    $description    = trim($_POST['description']     ?? '');
    $contactInfo    = trim($_POST['contact_info']    ?? '');
    $submitterName  = trim($_POST['submitter_name']  ?? '');
    $submitterEmail = trim($_POST['submitter_email'] ?? '');
    $ip             = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $ip             = trim(explode(',', $ip)[0]);

    // Validate
    if (mb_strlen($businessName) < 2 || mb_strlen($businessName) > 200) {
        $errors[] = t('Business name is required (2–200 characters).', 'Le nom de l\'entreprise est requis (2 à 200 caractères).');
    }
    if ($submitterEmail && !filter_var($submitterEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = t('Please enter a valid email address.', 'Veuillez entrer une adresse e-mail valide.');
    }

    // Rate limit: max 3 submissions per IP per 24 h
    if (!$errors) {
        $count = (int)$pdo->prepare(
            "SELECT COUNT(*) FROM suggested_listings WHERE submitter_ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        )->execute([$ip]) ? $pdo->query(
            "SELECT COUNT(*) FROM suggested_listings WHERE submitter_ip = " . $pdo->quote($ip) . " AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        )->fetchColumn() : 0;

        // Use prepared statement properly
        $rateStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM suggested_listings WHERE submitter_ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        $rateStmt->execute([$ip]);
        $count = (int)$rateStmt->fetchColumn();

        if ($count >= 3) {
            $errors[] = t('You\'ve submitted too many suggestions today. Please try again tomorrow.', 'Vous avez soumis trop de suggestions aujourd\'hui. Réessayez demain.');
        }
    }

    // Insert
    if (!$errors) {
        $stmt = $pdo->prepare("
            INSERT INTO suggested_listings
                (business_name, category_id, location_id, description, contact_info,
                 submitter_name, submitter_email, submitter_ip)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $businessName,
            $categoryId,
            $locationId,
            $description ?: null,
            $contactInfo ?: null,
            $submitterName ?: null,
            $submitterEmail ?: null,
            $ip,
        ]);
        $done = true;
    }
}

// ── Page ──────────────────────────────────────────────────────────────────────
$pageTitle = t('Suggest a Business — 237Biz', 'Suggérer une Entreprise — 237Biz');
require_once __DIR__ . '/includes/header.php';
?>

<style>
.suggest-wrap { max-width: 640px; margin: 0 auto; padding: 2rem 0 4rem; }
.suggest-wrap h1 { font-family: 'Fraunces', serif; font-weight: 900; font-size: 2rem; margin-bottom: 0.4rem; }
.suggest-wrap .lead { color: var(--muted); margin-bottom: 2rem; line-height: 1.65; }
.sug-form { background: var(--card); border: 1px solid var(--border); border-radius: 18px; padding: 2rem 2rem; display: flex; flex-direction: column; gap: 1.1rem; }
.sug-form .row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
@media (max-width: 540px) { .sug-form .row-2 { grid-template-columns: 1fr; } }
.sug-form label { display: block; font-size: 0.8rem; font-weight: 700; color: rgba(255,255,255,0.55); text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 0.4rem; }
.sug-form label span { font-weight: 400; color: var(--muted); text-transform: none; letter-spacing: 0; }
.sug-form input, .sug-form textarea, .sug-form select {
    width: 100%; padding: 0.7rem 0.9rem; border-radius: 10px;
    border: 1px solid var(--border); background: rgba(255,255,255,0.04);
    color: var(--white); font-size: 0.9rem; font-family: inherit;
    box-sizing: border-box; transition: border-color .2s;
}
.sug-form select { background-color: #0e1f17; }
.sug-form input:focus, .sug-form textarea:focus, .sug-form select:focus {
    outline: none; border-color: var(--green);
}
.sug-form textarea { resize: vertical; min-height: 90px; }
.sug-form .submit-row { margin-top: 0.5rem; }
.sug-done { background: rgba(0,168,120,0.07); border: 1px solid rgba(0,168,120,0.25); border-radius: 18px; padding: 3rem 2rem; text-align: center; }
.sug-done h2 { font-family: 'Fraunces', serif; font-weight: 900; font-size: 1.6rem; margin: 0.75rem 0 0.5rem; }
.sug-done p { color: var(--muted); line-height: 1.65; }
.error-box { background: rgba(220,50,50,0.08); border: 1px solid rgba(220,50,50,0.3); border-radius: 10px; padding: 0.9rem 1.1rem; color: #f87171; font-size: 0.875rem; }
.error-box ul { margin: 0; padding-left: 1.2rem; }
</style>

<div class="page-header">
  <div class="container">
    <div class="breadcrumb"><a href="<?= SITE_URL ?>"><?= t('Home', 'Accueil') ?></a> › <?= t('Suggest a Business', 'Suggérer une Entreprise') ?></div>
  </div>
</div>

<section class="page-section">
  <div class="container">
    <div class="suggest-wrap">

      <?php if ($done): ?>
        <div class="sug-done">
          <div style="font-size:3rem;">🎉</div>
          <h2><?= t('Thank you!', 'Merci !') ?></h2>
          <p><?= t('Your suggestion has been received. Our team will review it and reach out to the business.', 'Votre suggestion a bien été reçue. Notre équipe va l\'examiner et contacter l\'entreprise.') ?></p>
          <div style="margin-top:1.75rem;display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
            <a href="<?= SITE_URL ?>/suggest-business" class="btn btn-outline"><?= t('Suggest Another', 'Suggérer une autre') ?></a>
            <a href="<?= SITE_URL ?>/listings" class="btn btn-primary"><?= t('Browse Businesses', 'Parcourir les entreprises') ?></a>
          </div>
        </div>

      <?php else: ?>
        <h1>💡 <?= t('Suggest a Business', 'Suggérer une Entreprise') ?></h1>
        <p class="lead">
          <?= t(
            'Know a great local business that\'s not on 237Biz yet? Tell us about it — we\'ll track it down and invite them to claim their free listing.',
            'Vous connaissez une bonne entreprise locale qui n\'est pas encore sur 237Biz ? Dites-nous-en plus — nous la contacterons pour l\'inviter à revendiquer sa fiche gratuite.'
          ) ?>
        </p>

        <?php if ($errors): ?>
          <div class="error-box" style="margin-bottom:1.5rem;">
            <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
          </div>
        <?php endif; ?>

        <form method="POST" class="sug-form">
          <?= gpCsrfField() ?>

          <div>
            <label><?= t('Business Name', 'Nom de l\'entreprise') ?> <span>*</span></label>
            <input type="text" name="business_name" required maxlength="200"
                   value="<?= e($_POST['business_name'] ?? $_GET['business_name'] ?? '') ?>"
                   placeholder="<?= e(t('e.g. Fouda Plomberie, Restaurant La Terrasse…', 'ex. Fouda Plomberie, Restaurant La Terrasse…')) ?>">
          </div>

          <div class="row-2">
            <div>
              <label><?= t('Category', 'Catégorie') ?> <span><?= t('(optional)', '(facultatif)') ?></span></label>
              <select name="category_id">
                <option value=""><?= t('Select a category…', 'Choisir une catégorie…') ?></option>
                <?php foreach ($cats as $cat):
                  $catName = $isFr ? ($cat['name_fr'] ?? $cat['name_en']) : $cat['name_en'];
                ?>
                <option value="<?= (int)$cat['id'] ?>" <?= ((int)($_POST['category_id'] ?? 0) === (int)$cat['id']) ? 'selected' : '' ?>>
                  <?= e($cat['icon'] . ' ' . $catName) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label><?= t('City / Location', 'Ville / Localisation') ?> <span><?= t('(optional)', '(facultatif)') ?></span></label>
              <select name="location_id">
                <option value=""><?= t('Select a city…', 'Choisir une ville…') ?></option>
                <?php foreach ($locs as $loc):
                  $locName = $isFr ? ($loc['name_fr'] ?? $loc['name_en']) : $loc['name_en'];
                ?>
                <option value="<?= (int)$loc['id'] ?>" <?= ((int)($_POST['location_id'] ?? 0) === (int)$loc['id']) ? 'selected' : '' ?>>
                  📍 <?= e($locName) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div>
            <label><?= t('What do they do?', 'Que font-ils ?') ?> <span><?= t('(optional)', '(facultatif)') ?></span></label>
            <textarea name="description" maxlength="800"
                      placeholder="<?= e(t('Brief description of the business and why you recommend them.', 'Brève description de l\'entreprise et pourquoi vous les recommandez.')) ?>"><?= e($_POST['description'] ?? '') ?></textarea>
          </div>

          <div>
            <label><?= t('Their contact / website', 'Leur contact / site web') ?> <span><?= t('(if you know it)', '(si vous le savez)') ?></span></label>
            <input type="text" name="contact_info" maxlength="200"
                   value="<?= e($_POST['contact_info'] ?? '') ?>"
                   placeholder="<?= e(t('Phone, email or website URL', 'Téléphone, e-mail ou URL du site')) ?>">
          </div>

          <hr style="border:none;border-top:1px solid var(--border);margin:0.25rem 0;">

          <div class="row-2">
            <div>
              <label><?= t('Your name', 'Votre nom') ?> <span><?= t('(optional)', '(facultatif)') ?></span></label>
              <input type="text" name="submitter_name" maxlength="100"
                     value="<?= e($_POST['submitter_name'] ?? '') ?>"
                     placeholder="<?= e(t('How to call you', 'Comment vous appeler')) ?>">
            </div>
            <div>
              <label><?= t('Your email', 'Votre e-mail') ?> <span><?= t('(optional)', '(facultatif)') ?></span></label>
              <input type="email" name="submitter_email" maxlength="200"
                     value="<?= e($_POST['submitter_email'] ?? '') ?>"
                     placeholder="you@example.com">
            </div>
          </div>

          <div class="submit-row">
            <button type="submit" class="btn btn-primary" style="width:100%;padding:0.85rem;font-size:1rem;">
              💡 <?= t('Submit Suggestion', 'Soumettre la suggestion') ?>
            </button>
          </div>
        </form>

      <?php endif; ?>
    </div>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
