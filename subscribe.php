<?php
require_once __DIR__ . '/includes/config.php';
requireLogin();

if (file_exists(__DIR__ . '/includes/referral-helpers.php')) {
    require_once __DIR__ . '/includes/referral-helpers.php';
}

$u          = currentUser();
$listingId  = (int)($_GET['listing_id'] ?? 0);
$errors     = [];

// Verify ownership
$st = db()->prepare("SELECT l.* FROM listings l WHERE l.id=? AND l.user_id=?");
$st->execute([$listingId, $u['id']]);
$listing = $st->fetch();
if (!$listing) {
    flash('error', t('Listing not found.', 'Annonce introuvable.'));
    redirect(SITE_URL . '/dashboard');
}

// Current active subscription
$currentSub = null;
$stSub = db()->prepare("SELECT bs.*, sp.name_en, sp.name_fr, sp.slug AS plan_slug
    FROM business_subscriptions bs
    JOIN subscription_plans sp ON sp.id = bs.plan_id
    WHERE bs.listing_id=? AND bs.status='active'
    LIMIT 1");
$stSub->execute([$listingId]);
$currentSub = $stSub->fetch();

// Available plans
$plans = db()->query("SELECT * FROM subscription_plans WHERE active=1 ORDER BY sort_order, id")->fetchAll();

/* ── POST HANDLER ─────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie();

    $planId = (int)($_POST['plan_id'] ?? 0);
    $plan   = null;
    foreach ($plans as $pl) { if ($pl['id'] == $planId) { $plan = $pl; break; } }

    if (!$plan) {
        $errors[] = t('Please select a plan.', 'Veuillez choisir un forfait.');
    }

    // Free plan — no proof needed
    if ($plan && $plan['is_free']) {
        // Deactivate any previous sub for this listing
        db()->prepare("UPDATE business_subscriptions SET status='cancelled', cancelled_at=NOW() WHERE listing_id=? AND status='active'")
            ->execute([$listingId]);

        $starts = date('Y-m-d H:i:s');
        $ends   = $plan['duration_days'] > 0 ? date('Y-m-d H:i:s', strtotime('+' . $plan['duration_days'] . ' days')) : null;

        $subId = null;
        $ins = db()->prepare("INSERT INTO business_subscriptions (listing_id, user_id, plan_id, status, starts_at, ends_at)
                              VALUES (?,?,?,'active',?,?)");
        $ins->execute([$listingId, $u['id'], $planId, $starts, $ends]);
        $subId = db()->lastInsertId();

        // Unified ledger record (zero amount, instant activation)
        $ref = generateRef('SUB');
        db()->prepare("INSERT INTO monetisation_orders
            (ref, listing_id, user_id, product_type, product_id, linked_id, amount_xaf, net_xaf, payment_status, payment_confirmed_at, confirmed_by, commission_created)
            VALUES (?,?,?,'subscription',?,?, 0,0,'paid',NOW(),NULL,0)")
            ->execute([$ref, $listingId, $u['id'], $planId, $subId]);

        flash('success', t('Free plan activated!', 'Forfait gratuit activé !'));
        redirect(SITE_URL . '/dashboard');
    }

    // Paid plan — need proof
    $proofPath = null;
    if (!empty($_FILES['proof']['name'])) {
        $ext = strtolower(pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','pdf'])) {
            $proofPath = uniqid('subproof_') . '.' . $ext;
            if (!move_uploaded_file($_FILES['proof']['tmp_name'], UPLOAD_DIR . $proofPath)) {
                $errors[] = t('Failed to upload payment proof.', 'Impossible de télécharger la preuve de paiement.');
                $proofPath = null;
            }
        } else {
            $errors[] = t('Proof must be JPG, PNG or PDF.', 'La preuve doit être JPG, PNG ou PDF.');
        }
    } elseif ($plan && !$plan['is_free']) {
        $errors[] = t('Please upload your payment proof screenshot.', 'Veuillez télécharger votre capture d\'écran de paiement.');
    }

    if (!$errors && $plan) {
        // Create pending subscription
        $ins = db()->prepare("INSERT INTO business_subscriptions (listing_id, user_id, plan_id, status) VALUES (?,?,?,'pending_payment')");
        $ins->execute([$listingId, $u['id'], $planId]);
        $subId = db()->lastInsertId();

        $ref = generateRef('SUB');

        // Referral attribution
        $partnerRef  = null;
        $partnerId   = null;
        $refCode     = function_exists('getReferralCodeFromCookie') ? getReferralCodeFromCookie() : null;
        if ($refCode) {
            $linkData = function_exists('getLinkByCode') ? getLinkByCode($refCode) : null;
            if ($linkData) {
                $partnerRef = $refCode;
                $partnerId  = $linkData['user_id'] ?? null;
            }
        }

        // Unified ledger record
        db()->prepare("INSERT INTO monetisation_orders
            (ref, listing_id, user_id, product_type, product_id, linked_id, amount_xaf, net_xaf, proof, payment_status, partner_id, referral_code, commission_created)
            VALUES (?,?,?,'subscription',?,?, ?,?,?, 'pending', ?,?,0)")
            ->execute([$ref, $listingId, $u['id'], $planId, $subId,
                       $plan['price_xaf'], $plan['price_xaf'],
                       $proofPath, $partnerId, $partnerRef]);

        // Notify admin
        sendMail(SITE_EMAIL,
            'New Subscription Payment — ' . $ref,
            '<p>User <strong>' . e($u['name']) . '</strong> (' . e($u['email']) . ') submitted a subscription payment.</p>
             <p><strong>Listing:</strong> ' . e($listing['title']) . '</p>
             <p><strong>Plan:</strong> ' . e($plan['name_en']) . '</p>
             <p><strong>Amount:</strong> ' . number_format($plan['price_xaf']) . ' XAF</p>
             <p><strong>Ref:</strong> ' . $ref . '</p>
             <a href="' . SITE_URL . '/admin/subscriptions.php" style="background:#00A878;color:#fff;padding:.75rem 1.5rem;border-radius:5px;text-decoration:none;display:inline-block;margin-top:1rem;">Review in Admin Panel</a>'
        );

        // Notify user
        $planName = lang() === 'fr' ? $plan['name_fr'] : $plan['name_en'];
        sendMail($u['email'],
            t('Subscription payment received — ' . $ref, 'Paiement d\'abonnement reçu — ' . $ref),
            '<h2 style="color:#fff;font-family:Georgia,serif;">' . t('Subscription Payment Submitted', 'Paiement d\'Abonnement Soumis') . '</h2>
             <p style="color:rgba(255,255,255,0.7);">' . t('Hi', 'Bonjour') . ' ' . e($u['name']) . ',</p>
             <p style="color:rgba(255,255,255,0.7);">' . t('Your subscription payment has been received and is awaiting admin approval.', 'Votre paiement d\'abonnement a été reçu et attend l\'approbation de l\'administrateur.') . '</p>
             <table style="color:rgba(255,255,255,0.7);font-size:.875rem;border-collapse:collapse;width:100%;">
               <tr><td style="padding:.5rem 0;border-bottom:1px solid rgba(255,255,255,0.08);">' . t('Reference', 'Référence') . '</td><td style="padding:.5rem 0;border-bottom:1px solid rgba(255,255,255,0.08);color:#F5C842;"><strong>' . $ref . '</strong></td></tr>
               <tr><td style="padding:.5rem 0;border-bottom:1px solid rgba(255,255,255,0.08);">' . t('Plan', 'Forfait') . '</td><td style="padding:.5rem 0;border-bottom:1px solid rgba(255,255,255,0.08);">' . e($planName) . '</td></tr>
               <tr><td style="padding:.5rem 0;">' . t('Amount', 'Montant') . '</td><td style="padding:.5rem 0;">' . number_format($plan['price_xaf']) . ' XAF</td></tr>
             </table>'
        );

        flash('success', t(
            'Subscription payment submitted! Reference: ' . $ref . '. Your plan will be activated within 24 hours.',
            'Paiement d\'abonnement soumis ! Référence : ' . $ref . '. Votre forfait sera activé dans les 24 heures.'
        ));
        redirect(SITE_URL . '/dashboard');
    }
}

$pageTitle = t('Subscribe — 237Biz', 'S\'abonner — 237Biz');
require_once __DIR__ . '/includes/header.php';
?>
<style>
.plan-sel{background:var(--card);border:2px solid var(--border);border-radius:12px;padding:1.5rem;cursor:pointer;transition:border-color .18s,box-shadow .18s;position:relative;}
.plan-sel:hover{border-color:var(--accent);}
.plan-sel.selected{border-color:var(--accent);box-shadow:0 0 0 3px rgba(0,168,120,.15);}
.plan-sel.is-current{border-color:var(--yellow);opacity:.8;}
.plan-feature{font-size:.8rem;color:var(--muted);display:flex;align-items:center;gap:.4rem;margin:.25rem 0;}
.plan-feature::before{content:'✓';color:var(--accent);flex-shrink:0;}
.highlight-badge{position:absolute;top:-11px;left:50%;transform:translateX(-50%);background:var(--accent);color:#fff;font-size:.68rem;font-weight:700;padding:.2rem .9rem;border-radius:99px;white-space:nowrap;text-transform:uppercase;letter-spacing:.04em;}
</style>

<div class="page-header">
  <div class="container">
    <nav class="breadcrumb">
      <a href="<?= SITE_URL ?>/dashboard"><?= t('Dashboard', 'Tableau de bord') ?></a> ›
      <span><?= t('Choose a Plan', 'Choisir un forfait') ?></span>
    </nav>
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.8rem,3vw,2.5rem);">
      📦 <?= t('Subscription Plans', 'Forfaits d\'Abonnement') ?>
    </h1>
    <p style="color:var(--muted);"><?= e($listing['title']) ?></p>
    <?php if ($currentSub): ?>
    <p style="margin-top:.5rem;">
      <span style="background:rgba(0,168,120,.15);color:var(--accent);padding:.25rem .75rem;border-radius:99px;font-size:.83rem;font-weight:600;">
        ✓ <?= t('Current plan', 'Forfait actuel') ?>: <?= e(lang()==='fr' ? $currentSub['name_fr'] : $currentSub['name_en']) ?>
      </span>
    </p>
    <?php endif; ?>
  </div>
</div>

<section class="page-section">
<div class="container" style="max-width:900px;">

<?php foreach ($errors as $err): ?>
  <div class="flash flash-error"><?= e($err) ?></div>
<?php endforeach; ?>

<form method="post" enctype="multipart/form-data" id="sub-form">
  <?= csrf() ?>

  <!-- PLAN GRID -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1.25rem;margin-bottom:2rem;">
    <?php foreach ($plans as $plan):
      $isCurrent = $currentSub && $currentSub['plan_id'] == $plan['id'];
      $planName  = lang() === 'fr' ? $plan['name_fr'] : $plan['name_en'];
    ?>
    <label>
      <input type="radio" name="plan_id" value="<?= $plan['id'] ?>"
             class="plan-radio" style="display:none;"
             <?= $isCurrent ? 'checked' : ($plan['sort_order'] === 2 && !$currentSub ? 'checked' : '') ?>>
      <div class="plan-sel <?= $isCurrent ? 'is-current' : '' ?>" id="card-<?= $plan['id'] ?>">
        <?php if ($plan['badge_label_en']): ?>
          <div class="highlight-badge" style="<?= $plan['highlight_color'] ? 'background:' . e($plan['highlight_color']) : '' ?>">
            <?= e(lang()==='fr' ? ($plan['badge_label_fr'] ?: $plan['badge_label_en']) : $plan['badge_label_en']) ?>
          </div>
        <?php endif; ?>

        <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.75rem;">
          <?php if ($plan['highlight_color']): ?>
            <div style="width:8px;height:8px;border-radius:50%;background:<?= e($plan['highlight_color']) ?>;flex-shrink:0;"></div>
          <?php endif; ?>
          <h3 style="font-family:'Fraunces',serif;font-size:1rem;margin:0;"><?= e($planName) ?></h3>
          <?php if ($isCurrent): ?><span style="font-size:.72rem;background:rgba(245,200,66,.15);color:var(--yellow);padding:.15rem .5rem;border-radius:5px;"><?= t('Current', 'Actuel') ?></span><?php endif; ?>
        </div>

        <div style="font-family:'Fraunces',serif;font-weight:900;font-size:1.7rem;margin-bottom:.25rem;color:<?= $plan['highlight_color'] ?: 'var(--fg)' ?>;">
          <?= $plan['is_free'] ? t('Free', 'Gratuit') : number_format($plan['price_xaf']) . ' XAF' ?>
        </div>
        <?php if (!$plan['is_free']): ?>
        <div style="font-size:.78rem;color:var(--muted);margin-bottom:.75rem;">
          / <?= t($plan['billing_interval'] === 'monthly' ? 'month' : ($plan['billing_interval'] === 'annual' ? 'year' : 'one-off'),
                   $plan['billing_interval'] === 'monthly' ? 'mois' : ($plan['billing_interval'] === 'annual' ? 'an' : 'unique')) ?>
        </div>
        <?php else: ?>
        <div style="margin-bottom:.75rem;"></div>
        <?php endif; ?>

        <div>
          <div class="plan-feature"><?= $plan['max_offers'] ?> <?= t('active offers', 'offres actives') ?></div>
          <div class="plan-feature"><?= $plan['max_events'] ?> <?= t('active events', 'événements actifs') ?></div>
          <div class="plan-feature"><?= ucfirst($plan['analytics_level']) ?> <?= t('analytics', 'analyses') ?></div>
          <?php if ($plan['featured_eligible']): ?><div class="plan-feature"><?= t('Featured listing eligible', 'Annonce vedette éligible') ?></div><?php endif; ?>
          <?php if ($plan['verified_badge']): ?><div class="plan-feature"><?= t('Verified badge', 'Badge vérifié') ?></div><?php endif; ?>
          <?php if ($plan['priority_support']): ?><div class="plan-feature"><?= t('Priority support', 'Support prioritaire') ?></div><?php endif; ?>
        </div>
      </div>
    </label>
    <?php endforeach; ?>
  </div>

  <!-- PAYMENT SECTION (shown only for paid plans) -->
  <div id="payment-section" style="display:none;">
    <div class="listing-widget" style="background:rgba(245,200,66,.05);border-color:rgba(245,200,66,.2);margin-bottom:1.5rem;">
      <h4 style="color:var(--yellow);margin-bottom:1rem;">💳 <?= t('How to Pay (Offline)', 'Comment Payer (Hors ligne)') ?></h4>
      <ol style="list-style:decimal;padding-left:1.25rem;display:flex;flex-direction:column;gap:.75rem;">
        <li style="color:var(--muted);font-size:.875rem;"><?= t('Select your plan above.', 'Sélectionnez votre forfait ci-dessus.') ?></li>
        <li style="color:var(--muted);font-size:.875rem;">
          <?= t('Send payment via <strong style="color:var(--white)">Mobile Money (MTN/Orange)</strong> or bank transfer to:',
                 'Envoyez le paiement par <strong style="color:var(--white)">Mobile Money (MTN/Orange)</strong> ou virement bancaire à :') ?>
          <div style="background:var(--card);border:1px solid var(--border);border-radius:8px;padding:1rem;margin-top:.5rem;font-size:.83rem;">
            <div style="margin-bottom:.25rem;"><strong>MTN MoMo:</strong> +237 XXX XXX XXX</div>
            <div style="margin-bottom:.25rem;"><strong>Orange Money:</strong> +237 XXX XXX XXX</div>
            <div style="font-size:.75rem;color:var(--muted);margin-top:.25rem;"><?= t('Name: 237Biz / MS IT Solutions', 'Nom : 237Biz / MS IT Solutions') ?></div>
          </div>
        </li>
        <li style="color:var(--muted);font-size:.875rem;"><?= t('Take a screenshot of your payment confirmation.', 'Prenez une capture d\'écran de votre confirmation de paiement.') ?></li>
        <li style="color:var(--muted);font-size:.875rem;"><?= t('Upload the screenshot below and submit.', 'Téléchargez la capture d\'écran ci-dessous et soumettez.') ?></li>
      </ol>
    </div>

    <div class="form-card" style="margin-bottom:1.5rem;">
      <div class="form-group">
        <label><?= t('Payment Proof Screenshot *', 'Capture d\'écran du paiement *') ?></label>
        <input type="file" name="proof" id="proof-input" accept="image/*,.pdf"
               style="color:var(--muted);padding:.5rem 0;">
        <small style="color:var(--muted);font-size:.75rem;"><?= t('JPG, PNG or PDF · Max 5MB', 'JPG, PNG ou PDF · Max 5Mo') ?></small>
      </div>
    </div>
  </div>

  <!-- SUBMIT -->
  <button type="submit" class="btn btn-primary btn-full" style="font-size:1rem;" id="submit-btn">
    <?= t('Continue with Selected Plan', 'Continuer avec le forfait sélectionné') ?>
  </button>
</form>

</div>
</section>

<script>
(function(){
  var freePlanIds = <?= json_encode(array_values(array_map(fn($p) => (int)$p['id'], array_filter($plans, fn($p) => $p['is_free'])))) ?>;
  var radios      = document.querySelectorAll('.plan-radio');
  var paySection  = document.getElementById('payment-section');
  var proofInput  = document.getElementById('proof-input');
  var submitBtn   = document.getElementById('submit-btn');

  function update(){
    var sel = document.querySelector('.plan-radio:checked');
    document.querySelectorAll('.plan-sel').forEach(function(c){ c.classList.remove('selected'); });
    if(sel){
      document.getElementById('card-'+sel.value).classList.add('selected');
      var isFree = freePlanIds.indexOf(parseInt(sel.value,10)) > -1;
      paySection.style.display = isFree ? 'none' : '';
      if(proofInput) proofInput.required = !isFree;
      submitBtn.textContent = isFree
        ? '<?= t("Activate Free Plan", "Activer le forfait gratuit") ?>'
        : '<?= t("Submit Payment", "Soumettre le paiement") ?>';
    }
  }

  radios.forEach(function(r){ r.addEventListener('change', update); });
  update();
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
