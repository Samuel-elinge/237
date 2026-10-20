<?php
require_once __DIR__ . '/includes/config.php';
requireLogin();

if (file_exists(__DIR__ . '/includes/referral-helpers.php')) {
    require_once __DIR__ . '/includes/referral-helpers.php';
}

$u         = currentUser();
$listingId = (int)($_GET['listing_id'] ?? 0);
$errors    = [];

// Verify ownership
$st = db()->prepare("SELECT l.* FROM listings l WHERE l.id=? AND l.user_id=?");
$st->execute([$listingId, $u['id']]);
$listing = $st->fetch();
if (!$listing) {
    flash('error', t('Listing not found.', 'Annonce introuvable.'));
    redirect(SITE_URL . '/dashboard');
}

// Current subscription plan (for eligibility check)
$subPlan = null;
$stSub = db()->prepare("SELECT sp.* FROM business_subscriptions bs
    JOIN subscription_plans sp ON sp.id = bs.plan_id
    WHERE bs.listing_id=? AND bs.status='active' LIMIT 1");
$stSub->execute([$listingId]);
$subPlan = $stSub->fetch();
$currentPlanSlug = $subPlan['slug'] ?? 'free';

// Available promotions — filter by eligible_content includes 'listing'
$products = db()->query("SELECT * FROM promotion_products WHERE active=1 AND FIND_IN_SET('listing', eligible_content) ORDER BY sort_order, id")->fetchAll();

// Filter out products that require a higher plan than current
$planOrder = ['free' => 0, 'growth' => 1, 'premium' => 2];
$currentPlanOrder = $planOrder[$currentPlanSlug] ?? 0;
$eligibleProducts = [];
foreach ($products as $prod) {
    if ($prod['min_plan_slug']) {
        $minOrder = $planOrder[$prod['min_plan_slug']] ?? 0;
        if ($currentPlanOrder < $minOrder) continue;
    }
    $eligibleProducts[] = $prod;
}

// Active promotions for this listing
$activePromos = db()->prepare("SELECT po.*, pp.name_en, pp.name_fr
    FROM promotion_orders po
    JOIN promotion_products pp ON pp.id = po.product_id
    WHERE po.listing_id=? AND po.promo_status IN ('active','pending_approval','scheduled','pending_payment')
    ORDER BY po.created_at DESC");
$activePromos->execute([$listingId]);
$activePromos = $activePromos->fetchAll();

/* ── POST HANDLER ─────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie();

    $productId = (int)($_POST['product_id'] ?? 0);
    $product   = null;
    foreach ($eligibleProducts as $p) { if ($p['id'] == $productId) { $product = $p; break; } }

    if (!$product) {
        $errors[] = t('Please select a promotion.', 'Veuillez choisir une promotion.');
    }

    // Upload proof
    $proofPath = null;
    if (!empty($_FILES['proof']['name'])) {
        $ext = strtolower(pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','pdf'])) {
            $proofPath = uniqid('promoproof_') . '.' . $ext;
            if (!move_uploaded_file($_FILES['proof']['tmp_name'], UPLOAD_DIR . $proofPath)) {
                $errors[] = t('Failed to upload payment proof.', 'Impossible de télécharger la preuve.');
                $proofPath = null;
            }
        } else {
            $errors[] = t('Proof must be JPG, PNG or PDF.', 'La preuve doit être JPG, PNG ou PDF.');
        }
    } else {
        $errors[] = t('Please upload your payment proof screenshot.', 'Veuillez télécharger votre capture d\'écran de paiement.');
    }

    if (!$errors && $product) {
        $ref = generateRef('PRO');

        // Referral attribution
        $partnerRef = null;
        $partnerId  = null;
        $refCode    = function_exists('getReferralCodeFromCookie') ? getReferralCodeFromCookie() : null;
        if ($refCode) {
            $linkData = function_exists('getLinkByCode') ? getLinkByCode($refCode) : null;
            if ($linkData) {
                $partnerRef = $refCode;
                $partnerId  = $linkData['user_id'] ?? null;
            }
        }

        // Create promotion order
        $ins = db()->prepare("INSERT INTO promotion_orders
            (ref, listing_id, user_id, product_id, content_type, content_id, amount_xaf, proof, payment_status, promo_status, partner_ref)
            VALUES (?,?,?,?,'listing',?,?, ?,'pending','pending_payment',?)");
        $ins->execute([$ref, $listingId, $u['id'], $productId, $listingId, $product['price_xaf'], $proofPath, $partnerRef]);
        $promoOrderId = db()->lastInsertId();

        // Unified ledger
        db()->prepare("INSERT INTO monetisation_orders
            (ref, listing_id, user_id, product_type, product_id, linked_id, amount_xaf, net_xaf, proof, payment_status, partner_id, referral_code, commission_created)
            VALUES (?,?,?,'promotion',?,?, ?,?,?, 'pending', ?,?,0)")
            ->execute([$ref, $listingId, $u['id'], $productId, $promoOrderId,
                       $product['price_xaf'], $product['price_xaf'],
                       $proofPath, $partnerId, $partnerRef]);

        $productName = lang() === 'fr' ? $product['name_fr'] : $product['name_en'];

        // Notify admin
        sendMail(SITE_EMAIL,
            'New Promotion Order — ' . $ref,
            '<p>User <strong>' . e($u['name']) . '</strong> (' . e($u['email']) . ') submitted a promotion payment.</p>
             <p><strong>Listing:</strong> ' . e($listing['title']) . '</p>
             <p><strong>Promotion:</strong> ' . e($product['name_en']) . '</p>
             <p><strong>Amount:</strong> ' . number_format($product['price_xaf']) . ' XAF</p>
             <p><strong>Ref:</strong> ' . $ref . '</p>
             <a href="' . SITE_URL . '/admin/promotion-orders.php" style="background:#00A878;color:#fff;padding:.75rem 1.5rem;border-radius:5px;text-decoration:none;display:inline-block;margin-top:1rem;">Review in Admin Panel</a>'
        );

        // Notify user
        sendMail($u['email'],
            t('Promotion payment received — ' . $ref, 'Paiement de promotion reçu — ' . $ref),
            '<h2 style="color:#fff;font-family:Georgia,serif;">' . t('Promotion Payment Submitted', 'Paiement de Promotion Soumis') . '</h2>
             <p style="color:rgba(255,255,255,0.7);">' . t('Hi', 'Bonjour') . ' ' . e($u['name']) . ',</p>
             <p style="color:rgba(255,255,255,0.7);">' . t('Your promotion payment has been received and is awaiting admin approval. Your promotion will go live once approved.', 'Votre paiement de promotion a été reçu et attend l\'approbation de l\'administrateur. Votre promotion sera active une fois approuvée.') . '</p>
             <table style="color:rgba(255,255,255,0.7);font-size:.875rem;border-collapse:collapse;width:100%;">
               <tr><td style="padding:.5rem 0;border-bottom:1px solid rgba(255,255,255,0.08);">' . t('Reference', 'Référence') . '</td><td style="padding:.5rem 0;border-bottom:1px solid rgba(255,255,255,0.08);color:#F5C842;"><strong>' . $ref . '</strong></td></tr>
               <tr><td style="padding:.5rem 0;border-bottom:1px solid rgba(255,255,255,0.08);">' . t('Promotion', 'Promotion') . '</td><td style="padding:.5rem 0;border-bottom:1px solid rgba(255,255,255,0.08);">' . e($productName) . '</td></tr>
               <tr><td style="padding:.5rem 0;border-bottom:1px solid rgba(255,255,255,0.08);">' . t('Duration', 'Durée') . '</td><td style="padding:.5rem 0;border-bottom:1px solid rgba(255,255,255,0.08);">' . $product['duration_days'] . ' ' . t('days', 'jours') . '</td></tr>
               <tr><td style="padding:.5rem 0;">' . t('Amount', 'Montant') . '</td><td style="padding:.5rem 0;">' . number_format($product['price_xaf']) . ' XAF</td></tr>
             </table>'
        );

        flash('success', t(
            'Promotion payment submitted! Reference: ' . $ref . '. Your promotion will be activated within 24 hours.',
            'Paiement de promotion soumis ! Référence : ' . $ref . '. Votre promotion sera activée dans les 24 heures.'
        ));
        redirect(SITE_URL . '/dashboard');
    }
}

$pageTitle = t('Promote Your Listing — 237Biz', 'Promouvoir votre annonce — 237Biz');
require_once __DIR__ . '/includes/header.php';
?>
<style>
.promo-card{background:var(--card);border:2px solid var(--border);border-radius:12px;padding:1.4rem;cursor:pointer;transition:border-color .18s,box-shadow .18s;}
.promo-card:hover{border-color:var(--accent);}
.promo-card.selected{border-color:var(--accent);box-shadow:0 0 0 3px rgba(0,168,120,.15);}
.promo-type-badge{display:inline-block;padding:.2rem .7rem;border-radius:6px;font-size:.72rem;font-weight:600;background:rgba(109,40,217,.12);color:#7c3aed;margin-bottom:.6rem;}
.active-promo-row{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:.9rem 1.1rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap;}
</style>

<div class="page-header">
  <div class="container">
    <nav class="breadcrumb">
      <a href="<?= SITE_URL ?>/dashboard"><?= t('Dashboard', 'Tableau de bord') ?></a> ›
      <span><?= t('Promote Listing', 'Promouvoir l\'annonce') ?></span>
    </nav>
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.8rem,3vw,2.5rem);">
      📣 <?= t('Promote Your Listing', 'Promouvoir votre annonce') ?>
    </h1>
    <p style="color:var(--muted);"><?= e($listing['title']) ?>
      <?php if ($subPlan): ?>
        &nbsp;·&nbsp;<span style="font-size:.83rem;color:var(--accent);"><?= e(lang()==='fr' ? $subPlan['name_fr'] : $subPlan['name_en']) ?> plan</span>
      <?php endif; ?>
    </p>
  </div>
</div>

<section class="page-section">
<div class="container" style="max-width:900px;">

<?php foreach ($errors as $err): ?>
  <div class="flash flash-error"><?= e($err) ?></div>
<?php endforeach; ?>

<!-- ACTIVE PROMOTIONS -->
<?php if ($activePromos): ?>
<div style="margin-bottom:2rem;">
  <h3 style="font-family:'Fraunces',serif;margin-bottom:.75rem;"><?= t('Active & Pending Promotions', 'Promotions actives et en attente') ?></h3>
  <div style="display:flex;flex-direction:column;gap:.5rem;">
    <?php foreach ($activePromos as $ap):
      $promoName = lang() === 'fr' ? $ap['name_fr'] : $ap['name_en'];
      $statusColors = ['active'=>'#15803d','pending_approval'=>'#b45309','scheduled'=>'#1d4ed8','pending_payment'=>'#6b7280'];
      $statusColor = $statusColors[$ap['promo_status']] ?? '#6b7280';
    ?>
    <div class="active-promo-row">
      <div style="flex:1;min-width:0;">
        <div style="font-weight:600;"><?= e($promoName) ?></div>
        <div style="font-size:.8rem;color:var(--muted);">Ref: <?= e($ap['ref']) ?></div>
      </div>
      <?php if ($ap['starts_at'] && $ap['ends_at']): ?>
      <div style="font-size:.8rem;color:var(--muted);">
        <?= date('d M', strtotime($ap['starts_at'])) ?> → <?= date('d M Y', strtotime($ap['ends_at'])) ?>
      </div>
      <?php endif; ?>
      <span style="font-size:.75rem;font-weight:600;padding:.2rem .7rem;border-radius:99px;background:<?= $statusColor ?>22;color:<?= $statusColor ?>;">
        <?= ucfirst(str_replace('_', ' ', $ap['promo_status'])) ?>
      </span>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($eligibleProducts): ?>
<form method="post" enctype="multipart/form-data" id="promo-form">
  <?= csrf() ?>

  <h3 style="font-family:'Fraunces',serif;margin-bottom:1rem;"><?= t('Choose a Promotion', 'Choisir une promotion') ?></h3>

  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:1rem;margin-bottom:2rem;">
    <?php foreach ($eligibleProducts as $prod):
      $prodName = lang() === 'fr' ? $prod['name_fr'] : $prod['name_en'];
      $promoTypeLabels = [
        'featured_listing'   => t('Featured Listing', 'Annonce vedette'),
        'featured_offer'     => t('Featured Offer', 'Offre vedette'),
        'promoted_event'     => t('Promoted Event', 'Événement promu'),
        'category_placement' => t('Category Placement', 'Placement catégorie'),
        'location_placement' => t('Location Placement', 'Placement localisation'),
        'homepage'           => t('Homepage', 'Page d\'accueil'),
      ];
    ?>
    <label>
      <input type="radio" name="product_id" value="<?= $prod['id'] ?>"
             class="prod-radio" style="display:none;">
      <div class="promo-card" id="pcard-<?= $prod['id'] ?>">
        <div class="promo-type-badge"><?= $promoTypeLabels[$prod['promo_type']] ?? e($prod['promo_type']) ?></div>
        <div style="font-family:'Fraunces',serif;font-weight:700;font-size:1rem;margin-bottom:.4rem;"><?= e($prodName) ?></div>
        <div style="font-size:1.4rem;font-weight:900;color:var(--accent);margin-bottom:.2rem;"><?= number_format($prod['price_xaf']) ?> XAF</div>
        <div style="font-size:.8rem;color:var(--muted);"><?= $prod['duration_days'] ?> <?= t('days', 'jours') ?></div>
        <?php if ($prod['description_en'] || $prod['description_fr']): ?>
        <p style="font-size:.8rem;color:var(--muted);margin-top:.6rem;line-height:1.5;">
          <?= e(lang()==='fr' ? ($prod['description_fr'] ?: $prod['description_en']) : $prod['description_en']) ?>
        </p>
        <?php endif; ?>
        <?php if ($prod['max_concurrent']): ?>
        <div style="font-size:.74rem;color:var(--muted);margin-top:.4rem;">⚡ <?= t('Limited slots', 'Places limitées') ?></div>
        <?php endif; ?>
      </div>
    </label>
    <?php endforeach; ?>
  </div>

  <!-- PAYMENT INSTRUCTIONS -->
  <div class="listing-widget" style="background:rgba(245,200,66,.05);border-color:rgba(245,200,66,.2);margin-bottom:1.5rem;">
    <h4 style="color:var(--yellow);margin-bottom:1rem;">💳 <?= t('How to Pay', 'Comment payer') ?></h4>
    <ol style="list-style:decimal;padding-left:1.25rem;display:flex;flex-direction:column;gap:.75rem;">
      <li style="color:var(--muted);font-size:.875rem;"><?= t('Choose your promotion slot above.', 'Choisissez votre slot de promotion ci-dessus.') ?></li>
      <li style="color:var(--muted);font-size:.875rem;">
        <?= t('Send payment via <strong>Mobile Money (MTN/Orange)</strong> or bank transfer to:',
               'Envoyez le paiement par <strong>Mobile Money (MTN/Orange)</strong> ou virement bancaire à :') ?>
        <div style="background:var(--card);border:1px solid var(--border);border-radius:8px;padding:1rem;margin-top:.5rem;font-size:.83rem;">
          <div style="margin-bottom:.25rem;"><strong>MTN MoMo:</strong> +237 XXX XXX XXX</div>
          <div><strong>Orange Money:</strong> +237 XXX XXX XXX</div>
        </div>
      </li>
      <li style="color:var(--muted);font-size:.875rem;"><?= t('Take a screenshot of your payment confirmation.', 'Prenez une capture d\'écran de votre confirmation.') ?></li>
      <li style="color:var(--muted);font-size:.875rem;"><?= t('Upload the screenshot below and submit.', 'Téléchargez la capture d\'écran et soumettez.') ?></li>
    </ol>
  </div>

  <div class="form-card" style="margin-bottom:1.5rem;">
    <div class="form-group">
      <label><?= t('Payment Proof Screenshot *', 'Capture d\'écran du paiement *') ?></label>
      <input type="file" name="proof" accept="image/*,.pdf" required
             style="color:var(--muted);padding:.5rem 0;">
      <small style="color:var(--muted);font-size:.75rem;"><?= t('JPG, PNG or PDF · Max 5MB', 'JPG, PNG ou PDF · Max 5Mo') ?></small>
    </div>
    <button type="submit" class="btn btn-primary btn-full" style="margin-top:.5rem;font-size:1rem;">
      📣 <?= t('Submit Promotion Payment', 'Soumettre le paiement de promotion') ?>
    </button>
  </div>
</form>

<?php else: ?>
<div style="text-align:center;padding:3rem;background:var(--card);border:1px solid var(--border);border-radius:12px;">
  <div style="font-size:2.5rem;margin-bottom:.75rem;">📣</div>
  <h3 style="font-family:'Fraunces',serif;"><?= t('No promotions available', 'Aucune promotion disponible') ?></h3>
  <?php if (!$subPlan || $currentPlanSlug === 'free'): ?>
  <p style="color:var(--muted);"><?= t('Upgrade your subscription to unlock promotion slots.', 'Améliorez votre abonnement pour débloquer des slots de promotion.') ?></p>
  <a href="<?= SITE_URL ?>/subscribe?listing_id=<?= $listingId ?>" class="btn btn-primary" style="margin-top:1rem;">
    <?= t('View Plans', 'Voir les forfaits') ?>
  </a>
  <?php endif; ?>
</div>
<?php endif; ?>

</div><!-- /container -->
</section>

<script>
document.querySelectorAll('.prod-radio').forEach(function(r){
  r.addEventListener('change', function(){
    document.querySelectorAll('.promo-card').forEach(function(c){ c.classList.remove('selected'); });
    document.getElementById('pcard-'+this.value).classList.add('selected');
  });
});
// Auto-select first
var first = document.querySelector('.prod-radio');
if(first){ first.checked=true; document.getElementById('pcard-'+first.value).classList.add('selected'); }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
