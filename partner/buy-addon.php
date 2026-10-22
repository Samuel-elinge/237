<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/entitlement-helpers.php';
requireLogin();

$u   = currentUser();
$lid = (int)($_GET['listing_id'] ?? $_POST['listing_id'] ?? 0);

// Verify listing ownership
$stL = db()->prepare("SELECT * FROM listings WHERE id=? AND (user_id=? OR ?='admin') AND featured=1");
$stL->execute([$lid, $u['id'], $u['role']]);
$listing = $stL->fetch();
if (!$listing) {
    flash('error', t('Listing not found or not accessible.', 'Annonce introuvable.'));
    redirect(SITE_URL . '/dashboard');
}

// Entitlements for this listing
$ent = getListingEntitlements($lid);

// Only addon-eligible plans can buy add-ons
if (!$ent['addon_eligible']) {
    flash('error', t(
        'Add-ons are not available on your current plan. Upgrade to Growth or Premium to unlock them.',
        'Les extensions ne sont pas disponibles sur votre plan actuel. Passez à Growth ou Premium pour les débloquer.'
    ));
    redirect(SITE_URL . '/partner/dashboard?listing_id=' . $lid);
}

/* ── POST: submit purchase ───────────────────────────────── */
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie();

    $addonId       = (int)($_POST['addon_id'] ?? 0);
    $quantity      = max(1, (int)($_POST['quantity'] ?? 1));
    $paymentMethod = trim($_POST['payment_method'] ?? '');

    // Fetch the chosen add-on
    $stA = db()->prepare("SELECT * FROM plan_addons WHERE id=? AND active=1");
    $stA->execute([$addonId]);
    $addon = $stA->fetch();

    if (!$addon) {
        $errors[] = t('Invalid add-on selected.', 'Extension invalide.');
    }

    // Verify the addon is eligible for this plan
    if ($addon && $addon['eligible_plan_slugs']) {
        $allowed = json_decode($addon['eligible_plan_slugs'], true) ?: [];
        if ($allowed && !in_array($ent['plan_slug'], $allowed)) {
            $errors[] = t('This add-on is not available for your current plan.', 'Cette extension n\'est pas disponible pour votre plan actuel.');
        }
    }

    // Check max_per_listing cap
    if ($addon && $addon['max_per_listing']) {
        $existing = db()->prepare("SELECT COALESCE(SUM(ao.quantity),0) FROM addon_orders ao
            WHERE ao.listing_id=? AND ao.addon_id=? AND ao.payment_status IN ('pending_payment','paid')");
        $existing->execute([$lid, $addonId]);
        $alreadyBought = (int)$existing->fetchColumn();
        if ($alreadyBought + $quantity > $addon['max_per_listing']) {
            $errors[] = t(
                "You can purchase at most {$addon['max_per_listing']} unit(s) of this add-on per listing (you already have {$alreadyBought}).",
                "Vous pouvez acheter au maximum {$addon['max_per_listing']} unité(s) de cette extension par annonce (vous en avez déjà {$alreadyBought})."
            );
        }
    }

    if (!$paymentMethod) {
        $errors[] = t('Please enter your payment method / reference.', 'Veuillez indiquer votre méthode de paiement / référence.');
    }

    // Upload proof
    $proofPath = null;
    if (!empty($_FILES['proof']['name'])) {
        $ext = strtolower(pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','pdf'])) {
            @mkdir(UPLOAD_DIR . 'addons', 0755, true);
            $proofPath = 'addons/' . uniqid('addonproof_') . '.' . $ext;
            if (!move_uploaded_file($_FILES['proof']['tmp_name'], UPLOAD_DIR . $proofPath)) {
                $errors[] = t('Failed to upload payment proof.', 'Impossible de télécharger la preuve de paiement.');
                $proofPath = null;
            }
        } else {
            $errors[] = t('Proof must be JPG, PNG or PDF.', 'La preuve doit être JPG, PNG ou PDF.');
        }
    } else {
        $errors[] = t('Please upload your payment proof screenshot.', 'Veuillez télécharger votre capture d\'écran de paiement.');
    }

    if (!$errors && $addon) {
        $totalXaf = $addon['price_xaf'] * $quantity;
        $ref      = generateRef('AOD'); // Add-On order

        db()->prepare("INSERT INTO addon_orders
            (ref, listing_id, user_id, addon_id, quantity, total_xaf, payment_method, payment_status, proof)
            VALUES (?,?,?,?,?,?,?,'pending_payment',?)")
           ->execute([$ref, $lid, $u['id'], $addonId, $quantity, $totalXaf, $paymentMethod, $proofPath]);

        // Notify admin
        sendMail(SITE_EMAIL,
            'New Add-on Order — ' . $ref,
            '<p><strong>' . e($u['name']) . '</strong> (' . e($u['email']) . ') purchased an add-on.</p>
             <p><strong>Listing:</strong> ' . e($listing['business_name']) . '</p>
             <p><strong>Add-on:</strong> ' . e($addon['name_en']) . ' × ' . $quantity . '</p>
             <p><strong>Total:</strong> ' . number_format($totalXaf) . ' FCFA</p>
             <p><strong>Method:</strong> ' . e($paymentMethod) . '</p>
             <p><a href="' . SITE_URL . '/admin/addon-orders.php">Review in admin →</a></p>'
        );

        flash('success', t(
            "Your add-on order ({$ref}) has been submitted. We'll activate it once payment is confirmed — usually within 24 hours.",
            "Votre commande d'extension ({$ref}) a été soumise. Nous l'activerons dès confirmation du paiement — généralement sous 24h."
        ));
        redirect(SITE_URL . '/partner/dashboard?listing_id=' . $lid);
    }
}

/* ── READ: available add-ons for this plan ───────────────── */
$addons = db()->query("SELECT * FROM plan_addons WHERE active=1 ORDER BY addon_type, sort_order, id")->fetchAll();

// Filter to plan-eligible only
$eligibleAddons = array_filter($addons, function($a) use ($ent) {
    if (!$a['eligible_plan_slugs']) return true; // available on all plans
    $allowed = json_decode($a['eligible_plan_slugs'], true) ?: [];
    return in_array($ent['plan_slug'], $allowed);
});

// Group by type
$addonsByType = ['offers' => [], 'events' => [], 'products' => []];
foreach ($eligibleAddons as $a) $addonsByType[$a['addon_type']][] = $a;

global $isFr;
$pageTitle = t('Buy Add-on', 'Acheter une extension');
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.addon-option{border:2px solid var(--border);border-radius:12px;padding:1.25rem;cursor:pointer;transition:border-color .15s;}
.addon-option:has(input:checked){border-color:var(--accent);}
.addon-option label{cursor:pointer;display:block;}
.addon-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:.75rem;margin-bottom:1.5rem;}
.usage-bar{height:6px;border-radius:99px;background:var(--border);overflow:hidden;margin-top:.25rem;}
.usage-fill{height:100%;border-radius:99px;background:var(--accent);}
.section-label{font-weight:700;font-family:'Fraunces',serif;font-size:.95rem;margin:1.25rem 0 .5rem;color:var(--fg);}
</style>

<div class="page-header">
  <div class="container">
    <nav class="breadcrumb">
      <a href="<?= SITE_URL ?>/partner/dashboard?listing_id=<?= $lid ?>"><?= e($listing['business_name']) ?></a>
      › <?= t('Buy Add-on','Acheter une extension') ?>
    </nav>
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.5rem,3vw,2.2rem);">
      🛒 <?= t('Add-on Capacity','Capacité supplémentaire') ?>
    </h1>
    <p style="color:var(--muted);"><?= t(
      'Purchase extra offers, event or product slots on top of your plan.',
      'Achetez des slots supplémentaires d\'offres, d\'événements ou de produits en plus de votre plan.'
    ) ?></p>
  </div>
</div>

<section class="page-section">
<div class="container" style="max-width:760px;">

<!-- Current usage summary -->
<div style="background:var(--card);border:1px solid var(--border);border-radius:12px;padding:1.25rem;margin-bottom:1.75rem;">
  <p style="font-weight:700;margin:0 0 .75rem;font-family:'Fraunces',serif;">
    <?= t('Current limits','Limites actuelles') ?> — <?= e($isFr ? $ent['plan_name_fr'] : $ent['plan_name_en']) ?>
  </p>
  <?php
  $usageItems = [
    ['label' => t('Offers','Offres'),   'used' => $ent['used_offers'],   'total' => $ent['total_offers']],
    ['label' => t('Events','Événements'),'used' => $ent['used_events'],   'total' => $ent['total_events']],
    ['label' => t('Products','Produits'),'used' => $ent['used_products'], 'total' => $ent['total_products']],
  ];
  foreach ($usageItems as $ui):
    $pct = $ui['total'] > 0 ? min(100, round(($ui['used']/$ui['total'])*100)) : 0;
  ?>
  <div style="margin-bottom:.65rem;">
    <div style="display:flex;justify-content:space-between;font-size:.85rem;">
      <span><?= $ui['label'] ?></span>
      <span style="color:var(--muted);"><?= $ui['used'] ?> / <?= $ui['total'] ?: '∞' ?></span>
    </div>
    <?php if ($ui['total'] > 0): ?>
    <div class="usage-bar"><div class="usage-fill" style="width:<?= $pct ?>%;<?= $pct >= 100 ? 'background:#ef4444;' : '' ?>"></div></div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($errors): ?>
  <div class="alert alert-error" style="margin-bottom:1.25rem;">
    <?php foreach ($errors as $e_): ?><div><?= e($e_) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- Purchase form -->
<form method="post" enctype="multipart/form-data">
  <?= csrfField() ?>
  <input type="hidden" name="listing_id" value="<?= $lid ?>">

  <?php
  $typeLabels = ['offers' => t('Offer Add-ons','Extensions offres'), 'events' => t('Event Add-ons','Extensions événements'), 'products' => t('Product Add-ons','Extensions produits')];
  foreach ($typeLabels as $type => $label):
    $items = $addonsByType[$type];
    if (!$items) continue;
  ?>
  <p class="section-label"><?= $label ?></p>
  <div class="addon-grid">
    <?php foreach ($items as $a): ?>
    <div class="addon-option">
      <label>
        <input type="radio" name="addon_id" value="<?= $a['id'] ?>"
               style="accent-color:var(--accent);"
               <?= isset($_POST['addon_id']) && (int)$_POST['addon_id'] === $a['id'] ? 'checked' : '' ?>>
        <strong style="display:block;margin:.3rem 0 .15rem;"><?= e($isFr ? $a['name_fr'] : $a['name_en']) ?></strong>
        <span style="font-size:.82rem;color:var(--muted);"><?= e($isFr ? ($a['description_fr'] ?? '') : ($a['description_en'] ?? '')) ?></span>
        <div style="margin-top:.6rem;display:flex;justify-content:space-between;align-items:center;">
          <span style="font-weight:700;color:var(--accent);"><?= number_format($a['price_xaf']) ?> FCFA</span>
          <span style="font-size:.8rem;color:var(--muted);">+<?= $a['quantity'] ?> <?= t($type, $type) ?></span>
        </div>
        <div style="font-size:.75rem;color:var(--muted);margin-top:.25rem;">
          <?= match($a['expiry_policy']) {
            'subscription_end' => t('Valid until subscription ends','Valide jusqu\'à la fin de l\'abonnement'),
            'fixed_days'       => t("Valid for {$a['expiry_days']} days","Valide {$a['expiry_days']} jours"),
            'never'            => t('Never expires','N\'expire jamais'),
          } ?>
        </div>
      </label>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>

  <?php if (!array_filter($addonsByType)): ?>
    <p style="color:var(--muted);"><?= t('No add-ons are currently available for your plan.','Aucune extension disponible pour votre plan pour l\'instant.') ?></p>
  <?php else: ?>

  <!-- Quantity -->
  <div style="margin-bottom:1rem;">
    <label class="form-label"><?= t('Quantity','Quantité') ?></label>
    <input type="number" name="quantity" class="form-input" min="1" max="10" style="max-width:100px;"
           value="<?= max(1,(int)($_POST['quantity'] ?? 1)) ?>">
  </div>

  <!-- Payment method -->
  <div style="margin-bottom:1rem;">
    <label class="form-label"><?= t('Payment method / reference','Méthode de paiement / référence') ?> *</label>
    <input type="text" name="payment_method" class="form-input" required
           placeholder="<?= t('e.g. MTN MoMo #12345678','ex. MTN MoMo #12345678') ?>"
           value="<?= e($_POST['payment_method'] ?? '') ?>">
  </div>

  <!-- Proof upload -->
  <div style="margin-bottom:1.5rem;">
    <label class="form-label"><?= t('Payment proof (screenshot)','Preuve de paiement (capture d\'écran)') ?> *</label>
    <input type="file" name="proof" accept="image/*,.pdf" class="form-input" required>
    <p style="font-size:.8rem;color:var(--muted);margin:.25rem 0 0;">
      <?= t('Upload a screenshot of your MoMo/OM transfer confirmation. JPG, PNG or PDF.','Téléchargez une capture de votre confirmation de transfert MoMo/OM. JPG, PNG ou PDF.') ?>
    </p>
  </div>

  <button type="submit" class="btn btn-primary">
    🛒 <?= t('Submit Order','Soumettre la commande') ?>
  </button>
  <a href="<?= SITE_URL ?>/partner/dashboard?listing_id=<?= $lid ?>" class="btn btn-secondary" style="margin-left:.5rem;">
    <?= t('Cancel','Annuler') ?>
  </a>

  <?php endif; ?>
</form>

</div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
