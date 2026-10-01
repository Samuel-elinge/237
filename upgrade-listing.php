<?php
require_once __DIR__ . '/includes/config.php';
requireLogin();

$listingId = (int)($_GET['listing_id'] ?? 0);
$u = currentUser();

// Verify ownership
$st = db()->prepare("SELECT l.*, c.name_en AS cat_en FROM listings l JOIN categories c ON c.id=l.category_id WHERE l.id=? AND l.user_id=?");
$st->execute([$listingId, $u['id']]);
$listing = $st->fetch();
if (!$listing) { flash('error', t('Listing not found.','Annonce introuvable.')); redirect(SITE_URL . '/dashboard'); }

$packages = db()->query("SELECT * FROM listing_packages WHERE active=1 ORDER BY sort_order")->fetchAll();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $packageId = (int)($_POST['package_id'] ?? 0);
    $pkg = null;
    foreach ($packages as $p) { if ($p['id'] == $packageId) { $pkg = $p; break; } }

    if (!$pkg) { $errors[] = t('Please select a package.','Veuillez choisir un forfait.'); }

    // Handle payment proof upload
    $proofPath = null;
    if (!empty($_FILES['proof']['name'])) {
        $ext = strtolower(pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','pdf'])) {
            $proofPath = uniqid('proof_') . '.' . $ext;
            move_uploaded_file($_FILES['proof']['tmp_name'], UPLOAD_DIR . $proofPath);
        }
    }

    if (!$errors) {
        $ref = generateRef('FTR');
        $expires = $pkg['duration_days'] > 0
            ? date('Y-m-d H:i:s', strtotime('+' . $pkg['duration_days'] . ' days'))
            : null;

        db()->prepare("INSERT INTO listing_payments (listing_id,user_id,package_id,amount_xaf,ref,proof,status,expires_at)
                       VALUES (?,?,?,?,?,?,'pending',?)")
            ->execute([$listingId, $u['id'], $packageId, $pkg['price_xaf'], $ref, $proofPath, $expires]);

        // Notify admin
        sendMail(SITE_EMAIL,
            'New Featured Listing Payment — ' . $ref,
            '<p>User <strong>'.e($u['name']).'</strong> ('. e($u['email']) .') submitted payment for featured listing:</p>
             <p><strong>Listing:</strong> '.e($listing['title']).'</p>
             <p><strong>Package:</strong> '.e($pkg['name_en']).'</p>
             <p><strong>Amount:</strong> '.number_format($pkg['price_xaf']).' XAF</p>
             <p><strong>Ref:</strong> '.$ref.'</p>
             <a href="'.SITE_URL.'/admin/" style="background:#00A878;color:#fff;padding:0.75rem 1.5rem;border-radius:5px;text-decoration:none;display:inline-block;margin-top:1rem;">Review in Admin Panel</a>'
        );

        // Notify user
        sendMail($u['email'],
            t('Payment received — awaiting approval', 'Paiement reçu — en attente d\'approbation'),
            '<h2 style="color:#fff;font-family:Georgia,serif;">'.t('Payment Submitted','Paiement Soumis').'</h2>
             <p style="color:rgba(255,255,255,0.7);">'.t('Hi','Bonjour').' '.e($u['name']).',</p>
             <p style="color:rgba(255,255,255,0.7);">'.t('Your featured listing payment has been received and is awaiting admin approval. You will be notified by email once approved.','Votre paiement pour annonce vedette a été reçu et attend l\'approbation de l\'administrateur. Vous serez notifié par email une fois approuvé.').'</p>
             <table style="color:rgba(255,255,255,0.7);font-size:0.875rem;border-collapse:collapse;width:100%;">
               <tr><td style="padding:0.5rem 0;border-bottom:1px solid rgba(255,255,255,0.08);">'.t('Reference','Référence').'</td><td style="padding:0.5rem 0;border-bottom:1px solid rgba(255,255,255,0.08);color:#F5C842;"><strong>'.$ref.'</strong></td></tr>
               <tr><td style="padding:0.5rem 0;border-bottom:1px solid rgba(255,255,255,0.08);">'.t('Package','Forfait').'</td><td style="padding:0.5rem 0;border-bottom:1px solid rgba(255,255,255,0.08);">'.e(lang()==='fr'?$pkg['name_fr']:$pkg['name_en']).'</td></tr>
               <tr><td style="padding:0.5rem 0;">'.t('Amount','Montant').'</td><td style="padding:0.5rem 0;">'.number_format($pkg['price_xaf']).' XAF</td></tr>
             </table>'
        );

        // ── Referral attribution ──────────────────────────────────────────────────
        // If this visitor arrived via a referral link (/r/code), attribute the
        // upgrade conversion to the referring agent or creator automatically.
        if (file_exists(__DIR__ . '/includes/referral-helpers.php')) {
            require_once __DIR__ . '/includes/referral-helpers.php';
            attributeReferralConversion($listingId, $u['id'], 'upgrade');
        }
        // ─────────────────────────────────────────────────────────────────────────

        flash('success', t('Payment submitted! Reference: ' . $ref . '. We will review and activate your featured listing within 24 hours.',
                           'Paiement soumis ! Référence : ' . $ref . '. Nous examinerons et activerons votre annonce vedette dans les 24 heures.'));
        redirect(SITE_URL . '/dashboard');
    }
}

$pageTitle = t('Upgrade to Featured — 237Biz','Passer à la Vedette — 237Biz');
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div class="container">
    <nav class="breadcrumb">
      <a href="<?= SITE_URL ?>/dashboard"><?= t('Dashboard','Tableau de bord') ?></a> ›
      <span><?= t('Upgrade Listing','Améliorer l\'annonce') ?></span>
    </nav>
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.8rem,3vw,2.5rem);">
      ⭐ <?= t('Upgrade to Featured','Passer à la Vedette') ?>
    </h1>
    <p style="color:var(--muted);margin-top:0.35rem;"><?= e($listing['title']) ?>
      &nbsp;·&nbsp;<a href="<?= SITE_URL ?>/business-listing" target="_blank" style="color:var(--yellow);font-size:0.85rem;"><?= t('See full comparison →','Voir la comparaison complète →') ?></a>
    </p>
  </div>
</div>

<section class="page-section">
  <div class="container" style="max-width:800px;">
    <?php foreach ($errors as $err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endforeach; ?>

    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">

      <!-- PACKAGES -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1.25rem;margin-bottom:2rem;">
        <?php foreach ($packages as $pkg): ?>
          <label style="cursor:pointer;">
            <input type="radio" name="package_id" value="<?= $pkg['id'] ?>" style="display:none;" class="pkg-radio"
                   <?= $pkg['price_xaf'] > 0 ? '' : 'checked' ?>>
            <div class="listing-widget pkg-card" style="border:2px solid <?= $pkg['is_featured'] ? 'rgba(245,200,66,0.3)' : 'var(--border)' ?>;cursor:pointer;transition:all 0.2s;position:relative;">
              <?php if ($pkg['is_featured']): ?>
                <span style="position:absolute;top:-10px;left:50%;transform:translateX(-50%);background:var(--yellow);color:var(--dark);font-size:0.65rem;font-weight:600;padding:0.2rem 0.75rem;border-radius:20px;white-space:nowrap;text-transform:uppercase;">
                  ⭐ <?= t('Most Popular','Plus Populaire') ?>
                </span>
              <?php endif; ?>
              <h3 style="font-family:'Fraunces',serif;font-size:1.05rem;margin-bottom:0.5rem;">
                <?= e(lang()==='fr' ? $pkg['name_fr'] : $pkg['name_en']) ?>
              </h3>
              <div style="font-family:'Fraunces',serif;font-weight:900;font-size:1.8rem;color:<?= $pkg['is_featured'] ? 'var(--yellow)' : 'var(--white)' ?>;margin:0.5rem 0;">
                <?= $pkg['price_xaf'] > 0 ? number_format($pkg['price_xaf']) . ' XAF' : t('Free','Gratuit') ?>
              </div>
              <?php if ($pkg['duration_days'] > 0): ?>
                <p style="font-size:0.78rem;color:var(--muted);"><?= $pkg['duration_days'] ?> <?= t('days','jours') ?></p>
              <?php endif; ?>
              <ul style="list-style:none;margin-top:0.75rem;display:flex;flex-direction:column;gap:0.4rem;">
                <li style="font-size:0.78rem;color:var(--muted);">✓ <?= $pkg['is_featured'] ? t('Featured badge + top placement','Badge vedette + placement prioritaire') : t('Standard listing','Annonce standard') ?></li>
                <?php if ($pkg['is_verified']): ?><li style="font-size:0.78rem;color:var(--muted);">✓ <?= t('Verified tick','Coche vérifiée') ?></li><?php endif; ?>
                <li style="font-size:0.78rem;color:var(--muted);">✓ <?= t('Appear in search results','Apparaître dans les résultats') ?></li>
              </ul>
            </div>
          </label>
        <?php endforeach; ?>
      </div>

      <!-- PAYMENT INSTRUCTIONS -->
      <div class="listing-widget" style="background:rgba(245,200,66,0.05);border-color:rgba(245,200,66,0.2);margin-bottom:1.5rem;">
        <h4 style="color:var(--yellow);margin-bottom:1rem;">💳 <?= t('How to Pay (Offline)','Comment Payer (Hors ligne)') ?></h4>
        <ol style="list-style:decimal;padding-left:1.25rem;display:flex;flex-direction:column;gap:0.75rem;">
          <li style="color:var(--muted);font-size:0.875rem;"><?= t('Choose your package above.','Choisissez votre forfait ci-dessus.') ?></li>
          <li style="color:var(--muted);font-size:0.875rem;">
            <?= t('Send payment via <strong style="color:var(--white)">Mobile Money (MTN/Orange)</strong> or bank transfer to:','Envoyez le paiement par <strong style="color:var(--white)">Mobile Money (MTN/Orange)</strong> ou virement bancaire à :') ?>
            <div style="background:var(--card);border:1px solid var(--border);border-radius:8px;padding:1rem;margin-top:0.5rem;font-size:0.83rem;">
              <div style="color:var(--white);margin-bottom:0.25rem;"><strong>MTN MoMo:</strong> +237 XXX XXX XXX</div>
              <div style="color:var(--white);margin-bottom:0.25rem;"><strong>Orange Money:</strong> +237 XXX XXX XXX</div>
              <div style="color:var(--muted);font-size:0.75rem;margin-top:0.25rem;"><?= t('Name: 237Biz / MS IT Solutions','Nom : 237Biz / MS IT Solutions') ?></div>
            </div>
          </li>
          <li style="color:var(--muted);font-size:0.875rem;"><?= t('Take a screenshot of your payment confirmation.','Prenez une capture d\'écran de votre confirmation de paiement.') ?></li>
          <li style="color:var(--muted);font-size:0.875rem;"><?= t('Upload the screenshot below and submit.','Téléchargez la capture d\'écran ci-dessous et soumettez.') ?></li>
          <li style="color:var(--muted);font-size:0.875rem;"><?= t('Admin will verify and activate your featured listing within 24 hours.','L\'administrateur vérifiera et activera votre annonce vedette dans les 24 heures.') ?></li>
        </ol>
      </div>

      <div class="form-card">
        <div class="form-group">
          <label><?= t('Payment Proof Screenshot *','Capture d\'écran du paiement *') ?></label>
          <input type="file" name="proof" accept="image/*,.pdf" style="color:var(--muted);padding:0.5rem 0;" required>
          <small style="color:var(--muted-2);font-size:0.75rem;"><?= t('JPG, PNG or PDF · Max 5MB','JPG, PNG ou PDF · Max 5Mo') ?></small>
        </div>
        <button type="submit" class="btn btn-yellow btn-full" style="margin-top:0.5rem;font-size:1rem;">
          ⭐ <?= t('Submit Payment & Upgrade','Soumettre le Paiement et Améliorer') ?>
        </button>
      </div>
    </form>
  </div>
</section>

<script>
document.querySelectorAll('.pkg-radio').forEach(r => {
  r.addEventListener('change', function() {
    document.querySelectorAll('.pkg-card').forEach(c => c.style.borderColor = 'var(--border)');
    this.closest('label').querySelector('.pkg-card').style.borderColor = 'var(--green)';
  });
  if (r.checked) r.closest('label').querySelector('.pkg-card').style.borderColor = 'var(--green)';
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
