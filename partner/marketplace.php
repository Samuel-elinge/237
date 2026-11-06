<?php
/**
 * partner/marketplace.php — Growth Partner Services Marketplace
 *
 * Step 1: partner selects a business from their portfolio
 * Step 2: partner browses the service catalogue
 * Step 3: partner selects a product → review quote → submit order
 *
 * Uses: acquisition nav (partner-header.php) — not the main partner portal nav
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';

$partnerProfile = requireGrowthPartner();
$partnerId      = (int)$partnerProfile['id'];   // partner_profiles.id
$user           = currentUser();
$pdo            = db();

$lang = $user['lang'] ?? 'en';

/* ── helpers ─────────────────────────────────────────────────── */
function mpT(string $en, string $fr): string {
    global $lang;
    return $lang === 'fr' ? $fr : $en;
}
function mpFmt(int $xaf): string {
    return number_format($xaf) . ' FCFA';
}
function mpInterval(string $interval): string {
    return match($interval) {
        'MONTH' => mpT('/ month', '/ mois'),
        'YEAR'  => mpT('/ year', '/ an'),
        default => '',
    };
}

/* ── URL params ──────────────────────────────────────────────── */
$step           = $_GET['step']       ?? 'select_business';
$listingId      = (int)($_GET['listing_id'] ?? 0);
$productId      = (int)($_GET['product_id'] ?? 0);
$priceId        = (int)($_GET['price_id']   ?? 0);
$errors         = [];
$orderSubmitted = false;

/* ── POST: submit order ──────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_order'])) {
    verifyCsrfOrDie();

    $listingId      = (int)($_POST['listing_id']  ?? 0);
    $productId      = (int)($_POST['product_id']  ?? 0);
    $priceId        = (int)($_POST['price_id']    ?? 0);
    $partnerNote    = trim($_POST['partner_note'] ?? '');
    $serviceReqJson = $_POST['service_requirements'] ?? '{}';
    $mailboxCount   = (int)($_POST['mailbox_count'] ?? 0);

    /* 1. Verify portfolio assignment */
    $stA = $pdo->prepare("
        SELECT pba.id FROM partner_business_assignments pba
        WHERE pba.partner_id = ? AND pba.listing_id = ? AND pba.status = 'active'
        LIMIT 1
    ");
    $stA->execute([$partnerId, $listingId]);
    if (!$stA->fetch()) {
        $errors[] = mpT('You are not authorised to order for this business.', 'Vous n\'êtes pas autorisé à commander pour cette entreprise.');
    }

    /* 2. Load product + price */
    $stP = $pdo->prepare("
        SELECT p.*, c.key AS cat_key,
               pr.id AS price_id, pr.price_type, pr.setup_amount, pr.recurring_amount,
               pr.currency, pr.billing_interval, pr.mailbox_limit, pr.variant_key
        FROM mp_service_products p
        JOIN mp_service_categories c ON c.id = p.category_id
        JOIN mp_service_prices pr ON pr.product_id = p.id AND pr.id = ?
        WHERE p.id = ? AND p.active = 1 AND p.orderable = 1
          AND pr.active = 1 AND (pr.effective_to IS NULL OR pr.effective_to >= CURDATE())
        LIMIT 1
    ");
    $stP->execute([$priceId, $productId]);
    $product = $stP->fetch();

    if (!$product) {
        $errors[] = mpT('Selected product or price is not available.', 'Le produit ou le tarif sélectionné n\'est pas disponible.');
    }

    /* 3. Validate listing */
    $stL = $pdo->prepare("SELECT id, title, user_id FROM listings WHERE id = ? AND status = 'active' LIMIT 1");
    $stL->execute([$listingId]);
    $listing = $stL->fetch();
    if (!$listing) {
        $errors[] = mpT('Business not found or not active.', 'Entreprise introuvable ou inactive.');
    }

    /* 4. Build service requirements */
    $serviceReq = [];
    if ($product) {
        $schema = json_decode($product['form_schema'] ?? '{}', true) ?: [];
        // Allow partner note
        if ($partnerNote) $serviceReq['partner_note'] = $partnerNote;
        if ($mailboxCount > 0) $serviceReq['mailbox_count'] = $mailboxCount;
        // Validate required fields from schema
        foreach ($schema['required'] ?? [] as $field) {
            $val = trim($_POST['req_' . $field] ?? '');
            if ($val === '') {
                $errors[] = mpT("Field '$field' is required.", "Le champ '$field' est obligatoire.");
            } else {
                $serviceReq[$field] = $val;
            }
        }
    }

    if (!$errors && $product && $listing) {
        /* 5. Determine due_now amount */
        $dueNow = $product['price_type'] === 'setup'
            ? $product['setup_amount']
            : $product['recurring_amount'];

        /* 6. Generate ref + idempotency key */
        $ref      = generateRef('MPO');
        $idemKey  = bin2hex(random_bytes(16));

        /* 7. Insert order */
        $pdo->prepare("
            INSERT INTO marketplace_orders
              (ref, listing_id, partner_profile_id, created_by_user_id,
               order_state, payment_state, currency, due_now_amount,
               service_requirements, partner_note, idempotency_key)
            VALUES (?,?,?,?, 'PENDING_OWNER_APPROVAL','NOT_STARTED','XAF',?,?,?,?)
        ")->execute([
            $ref, $listingId, $partnerId, $user['id'],
            $dueNow,
            json_encode($serviceReq),
            $partnerNote,
            $idemKey,
        ]);
        $orderId = (int)$pdo->lastInsertId();

        /* 8. Build product + price snapshots */
        $productSnapshot = [
            'id'           => $product['id'],
            'key'          => $product['key'],
            'name_en'      => $product['name_en'],
            'name_fr'      => $product['name_fr'],
            'service_type' => $product['service_type'],
            'cat_key'      => $product['cat_key'],
        ];
        $priceSnapshot = [
            'id'               => $product['price_id'],
            'variant_key'      => $product['variant_key'],
            'price_type'       => $product['price_type'],
            'setup_amount'     => $product['setup_amount'],
            'recurring_amount' => $product['recurring_amount'],
            'currency'         => $product['currency'],
            'billing_interval' => $product['billing_interval'],
            'mailbox_limit'    => $product['mailbox_limit'],
        ];

        /* 9. Insert order item */
        $pdo->prepare("
            INSERT INTO marketplace_order_items
              (order_id, product_id, price_id, product_snapshot, price_snapshot,
               quantity, configuration, setup_amount, recurring_amount,
               billing_interval, amount_due_now)
            VALUES (?,?,?, ?,?, 1,?,?,?,?,?)
        ")->execute([
            $orderId, $productId, $priceId,
            json_encode($productSnapshot), json_encode($priceSnapshot),
            json_encode($serviceReq),
            $product['setup_amount'], $product['recurring_amount'],
            $product['billing_interval'],
            $dueNow,
        ]);

        /* 10. Create approval record */
        $approvalExpiry = null;
        $expiryDays = (int)(getSettingCached('mp_approval_expiry_days') ?: 7);
        if ($expiryDays > 0) {
            $approvalExpiry = date('Y-m-d H:i:s', strtotime("+{$expiryDays} days"));
        }
        $pdo->prepare("
            INSERT INTO mp_order_approvals (order_id, decision, requested_at, expires_at)
            VALUES (?, 'PENDING', NOW(), ?)
        ")->execute([$orderId, $approvalExpiry]);

        /* 11. Audit event */
        $pdo->prepare("
            INSERT INTO mp_audit_events (entity_type, entity_id, actor_user_id, actor_role, action, after_state, ip_address)
            VALUES ('marketplace_orders', ?, ?, 'growth_partner', 'order.created',
                    JSON_OBJECT('order_state','PENDING_OWNER_APPROVAL','ref',?), ?)
        ")->execute([$orderId, $user['id'], $ref, $_SERVER['REMOTE_ADDR'] ?? null]);

        /* 12. Notify business owner */
        $ownerRow = $pdo->prepare("SELECT email, name FROM users WHERE id = ?");
        $ownerRow->execute([$listing['user_id']]);
        $owner = $ownerRow->fetch();
        if ($owner) {
            $productName = $lang === 'fr' ? $product['name_fr'] : $product['name_en'];
            $approveUrl  = SITE_URL . '/marketplace-approve?ref=' . $ref;
            sendMail(
                $owner['email'],
                mpT(
                    "Service Request — {$ref}: {$productName}",
                    "Demande de service — {$ref} : {$productName}"
                ),
                '<p>' . mpT('Hello', 'Bonjour') . ' ' . e($owner['name']) . ',</p>
                 <p>' . mpT(
                     "Your Growth Partner has requested a service for <strong>" . e($listing['title']) . "</strong>:",
                     "Votre Partenaire de Croissance a demandé un service pour <strong>" . e($listing['title']) . "</strong> :"
                 ) . '</p>
                 <p><strong>' . e($productName) . '</strong></p>
                 <p>' . mpT('Amount due:', 'Montant dû :') . ' <strong>' . mpFmt($dueNow) . '</strong></p>
                 <p>' . mpT('Please review and approve or reject this request.', 'Veuillez examiner et approuver ou rejeter cette demande.') . '</p>
                 <p><a href="' . $approveUrl . '" style="background:#00A878;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;">'
                    . mpT('Review Request →', 'Examiner la demande →') . '</a></p>'
            );
        }

        /* 13. In-app notification for partner */
        $pdo->prepare("
            INSERT INTO partner_notifications
              (user_id, partner_id, listing_id, type, title, body, action_url)
            VALUES (?,?,?,?,?,?,?)
        ")->execute([
            $user['id'], $partnerId, $listingId,
            'marketplace_order_created',
            mpT("Order {$ref} submitted — awaiting owner approval", "Commande {$ref} soumise — en attente d'approbation"),
            mpT("Your service request is pending approval from the business owner.", "Votre demande de service est en attente d'approbation."),
            SITE_URL . '/partner/marketplace-order?ref=' . $ref,
        ]);

        $orderSubmitted = true;
        $submittedRef   = $ref;
    }
}

/* ── READ: partner's portfolio businesses ────────────────────── */
$businesses = $pdo->prepare("
    SELECT l.id, l.title, l.verified,
           c.name_en AS cat_en, loc.name_en AS city
    FROM listings l
    JOIN partner_business_assignments pba ON pba.listing_id = l.id
    JOIN categories c   ON c.id  = l.category_id
    JOIN locations  loc ON loc.id = l.location_id
    WHERE pba.partner_id = ? AND pba.status = 'active'
      AND l.status = 'active'
    ORDER BY l.title ASC
");
$businesses->execute([$partnerId]);
$businessList = $businesses->fetchAll();

/* ── READ: chosen business detail ────────────────────────────── */
$selectedBusiness = null;
if ($listingId) {
    $stSB = $pdo->prepare("
        SELECT l.id, l.title, l.verified, c.name_en AS cat_en, loc.name_en AS city,
               u.name AS owner_name, u.email AS owner_email
        FROM listings l
        JOIN categories c   ON c.id  = l.category_id
        JOIN locations  loc ON loc.id = l.location_id
        JOIN users u        ON u.id   = l.user_id
        JOIN partner_business_assignments pba ON pba.listing_id = l.id AND pba.partner_id = ? AND pba.status = 'active'
        WHERE l.id = ? AND l.status = 'active'
        LIMIT 1
    ");
    $stSB->execute([$partnerId, $listingId]);
    $selectedBusiness = $stSB->fetch();
}

/* ── READ: service catalogue ─────────────────────────────────── */
$catalogue = [];
if ($selectedBusiness) {
    $stCat = $pdo->prepare("
        SELECT cat.id AS cat_id, cat.key AS cat_key,
               cat.name_en AS cat_name_en, cat.name_fr AS cat_name_fr,
               cat.icon,
               p.id AS product_id, p.key AS product_key,
               p.name_en, p.name_fr, p.description_en, p.description_fr,
               p.service_type, p.form_schema, p.terms_en, p.terms_fr,
               pr.id AS price_id, pr.variant_key, pr.price_type,
               pr.setup_amount, pr.recurring_amount, pr.currency,
               pr.billing_interval, pr.mailbox_limit
        FROM mp_service_categories cat
        JOIN mp_service_products p   ON p.category_id = cat.id AND p.active = 1 AND p.orderable = 1
        JOIN mp_service_prices   pr  ON pr.product_id  = p.id  AND pr.active = 1
            AND (pr.effective_to IS NULL OR pr.effective_to >= CURDATE())
        WHERE cat.active = 1 AND cat.orderable = 1
        ORDER BY cat.sort_order, p.sort_order, pr.price_type DESC
    ");
    $stCat->execute();
    foreach ($stCat->fetchAll() as $row) {
        $catKey = $row['cat_key'];
        if (!isset($catalogue[$catKey])) {
            $catalogue[$catKey] = [
                'id'      => $row['cat_id'],
                'key'     => $catKey,
                'name'    => $lang === 'fr' ? $row['cat_name_fr'] : $row['cat_name_en'],
                'icon'    => $row['icon'],
                'products'=> [],
            ];
        }
        $pid = $row['product_id'];
        if (!isset($catalogue[$catKey]['products'][$pid])) {
            $catalogue[$catKey]['products'][$pid] = [
                'id'          => $pid,
                'key'         => $row['product_key'],
                'name'        => $lang === 'fr' ? $row['name_fr'] : $row['name_en'],
                'description' => $lang === 'fr' ? $row['description_fr'] : $row['description_en'],
                'service_type'=> $row['service_type'],
                'form_schema' => json_decode($row['form_schema'] ?? '{}', true) ?: [],
                'terms'       => $lang === 'fr' ? $row['terms_fr'] : $row['terms_en'],
                'prices'      => [],
            ];
        }
        $catalogue[$catKey]['products'][$pid]['prices'][] = [
            'id'               => $row['price_id'],
            'variant_key'      => $row['variant_key'],
            'price_type'       => $row['price_type'],
            'setup_amount'     => (int)$row['setup_amount'],
            'recurring_amount' => (int)$row['recurring_amount'],
            'currency'         => $row['currency'],
            'billing_interval' => $row['billing_interval'],
            'mailbox_limit'    => $row['mailbox_limit'],
        ];
    }
}

/* ── READ: selected product detail ───────────────────────────── */
$selectedProduct = null;
$selectedPrice   = null;
if ($productId && $priceId && $selectedBusiness) {
    foreach ($catalogue as $cat) {
        foreach ($cat['products'] as $prod) {
            if ($prod['id'] === $productId) {
                $selectedProduct = $prod;
                foreach ($prod['prices'] as $pr) {
                    if ($pr['id'] === $priceId) {
                        $selectedPrice = $pr;
                        break;
                    }
                }
                break 2;
            }
        }
    }
}

/* ── payment instructions from settings ──────────────────────── */
$payInstructKey = $lang === 'fr' ? 'mp_payment_instruction_fr' : 'mp_payment_instruction_en';
$payInstruction = getSettingCached($payInstructKey) ?: '';
$mtnNum   = getSettingCached('mtn_momo_number')    ?: '';
$orangeNum = getSettingCached('orange_money_number') ?: '';

/* ── Page ─────────────────────────────────────────────────────── */
$pageTitle = mpT('Services Marketplace', 'Marketplace de Services');
require_once __DIR__ . '/../includes/partner-header.php';
?>

<div class="container py-4" style="max-width:1100px;">

<?php if ($orderSubmitted): ?>
  <div class="text-center py-5">
    <div style="font-size:4rem;">✅</div>
    <h2 class="mt-3"><?= mpT('Order Submitted!', 'Commande soumise !') ?></h2>
    <p class="text-muted"><?= mpT('Reference:', 'Référence :') ?> <strong><?= e($submittedRef) ?></strong></p>
    <p class="text-muted"><?= mpT(
        'The business owner has been notified and must approve before payment is collected.',
        'Le propriétaire de l\'entreprise a été notifié et doit approuver avant que le paiement soit collecté.'
    ) ?></p>
    <a href="<?= SITE_URL ?>/partner/marketplace-order?ref=<?= e($submittedRef) ?>" class="btn btn-success me-2">
      <?= mpT('Track Order', 'Suivre la commande') ?>
    </a>
    <a href="<?= SITE_URL ?>/partner/marketplace?listing_id=<?= $listingId ?>" class="btn btn-outline-secondary">
      <?= mpT('Place Another Order', 'Passer une autre commande') ?>
    </a>
  </div>

<?php elseif ($selectedProduct && $selectedPrice): ?>
  <!-- ── STEP 3: Quote & Submit ──────────────────────────────── -->
  <?php
    $dueNow = $selectedPrice['price_type'] === 'setup'
        ? $selectedPrice['setup_amount']
        : $selectedPrice['recurring_amount'];
  ?>
  <nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb small">
      <li class="breadcrumb-item"><a href="<?= SITE_URL ?>/partner/marketplace"><?= mpT('Marketplace', 'Marketplace') ?></a></li>
      <li class="breadcrumb-item"><a href="<?= SITE_URL ?>/partner/marketplace?listing_id=<?= $listingId ?>"><?= e($selectedBusiness['title']) ?></a></li>
      <li class="breadcrumb-item active"><?= e($selectedProduct['name']) ?></li>
    </ol>
  </nav>

  <div class="row g-4">
    <div class="col-lg-7">
      <div class="card shadow-sm mb-4">
        <div class="card-header fw-bold"><?= mpT('Order Details', 'Détails de la commande') ?></div>
        <div class="card-body">
          <dl class="row mb-0">
            <dt class="col-sm-4"><?= mpT('Business', 'Entreprise') ?></dt>
            <dd class="col-sm-8"><?= e($selectedBusiness['title']) ?></dd>
            <dt class="col-sm-4"><?= mpT('Service', 'Service') ?></dt>
            <dd class="col-sm-8"><?= e($selectedProduct['name']) ?></dd>
            <?php if ($selectedPrice['price_type'] === 'setup'): ?>
            <dt class="col-sm-4"><?= mpT('Setup fee', 'Frais de mise en place') ?></dt>
            <dd class="col-sm-8"><?= mpFmt($selectedPrice['setup_amount']) ?></dd>
            <?php if ($selectedPrice['recurring_amount'] > 0): ?>
            <dt class="col-sm-4"><?= mpT('Renewal', 'Renouvellement') ?></dt>
            <dd class="col-sm-8"><?= mpFmt($selectedPrice['recurring_amount']) ?> <?= mpInterval($selectedPrice['billing_interval']) ?></dd>
            <?php endif; ?>
            <?php else: ?>
            <dt class="col-sm-4"><?= mpT('Recurring', 'Récurrent') ?></dt>
            <dd class="col-sm-8"><?= mpFmt($selectedPrice['recurring_amount']) ?> <?= mpInterval($selectedPrice['billing_interval']) ?></dd>
            <?php endif; ?>
            <dt class="col-sm-4 fw-bold"><?= mpT('Due now', 'À payer maintenant') ?></dt>
            <dd class="col-sm-8 fw-bold text-success" style="font-size:1.2rem;"><?= mpFmt($dueNow) ?></dd>
          </dl>
        </div>
      </div>

      <?php if ($selectedProduct['terms']): ?>
      <div class="card shadow-sm mb-4">
        <div class="card-header fw-bold"><?= mpT('Terms', 'Conditions') ?></div>
        <div class="card-body small text-muted"><?= nl2br(e($selectedProduct['terms'])) ?></div>
      </div>
      <?php endif; ?>

      <?php if ($errors): ?>
      <div class="alert alert-danger">
        <?php foreach ($errors as $err): ?><div>⚠ <?= e($err) ?></div><?php endforeach; ?>
      </div>
      <?php endif; ?>

      <form method="POST" action="<?= SITE_URL ?>/partner/marketplace?step=submit">
        <?= csrfField() ?>
        <input type="hidden" name="listing_id"  value="<?= $listingId ?>">
        <input type="hidden" name="product_id"  value="<?= $productId ?>">
        <input type="hidden" name="price_id"    value="<?= $priceId ?>">
        <input type="hidden" name="submit_order" value="1">

        <?php
        // Render schema fields
        $schema = $selectedProduct['form_schema'];
        $fields = $schema['fields'] ?? [];
        foreach ($fields as $field):
          $fname = $field['name'] ?? '';
          $flabel = $lang === 'fr' ? ($field['label_fr'] ?? $field['label_en'] ?? $fname) : ($field['label_en'] ?? $fname);
          $ftype  = $field['type'] ?? 'text';
          $freq   = in_array($fname, $schema['required'] ?? []);
        ?>
        <div class="mb-3">
          <label class="form-label"><?= e($flabel) ?><?= $freq ? ' <span class="text-danger">*</span>' : '' ?></label>
          <?php if ($ftype === 'textarea'): ?>
            <textarea name="req_<?= e($fname) ?>" class="form-control" rows="3"
              <?= $freq ? 'required' : '' ?>><?= e($_POST['req_' . $fname] ?? '') ?></textarea>
          <?php else: ?>
            <input type="<?= e($ftype) ?>" name="req_<?= e($fname) ?>" class="form-control"
              value="<?= e($_POST['req_' . $fname] ?? '') ?>"
              <?= $freq ? 'required' : '' ?>>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <?php if ($selectedPrice['mailbox_limit']): ?>
        <div class="mb-3">
          <label class="form-label"><?= mpT('Number of mailboxes needed', 'Nombre de boîtes mail nécessaires') ?>
            <span class="text-muted small">(max <?= (int)$selectedPrice['mailbox_limit'] ?>)</span>
          </label>
          <input type="number" name="mailbox_count" class="form-control"
            min="1" max="<?= (int)$selectedPrice['mailbox_limit'] ?>"
            value="<?= (int)($_POST['mailbox_count'] ?? 1) ?>" required>
        </div>
        <?php endif; ?>

        <div class="mb-3">
          <label class="form-label"><?= mpT('Note to admin (optional)', 'Note à l\'admin (facultatif)') ?></label>
          <textarea name="partner_note" class="form-control" rows="2"><?= e($_POST['partner_note'] ?? '') ?></textarea>
        </div>

        <div class="alert alert-info small">
          <?= mpT(
            '⚠ The business owner must approve this order before any payment is collected.',
            '⚠ Le propriétaire de l\'entreprise doit approuver cette commande avant tout paiement.'
          ) ?>
        </div>

        <button type="submit" class="btn btn-success btn-lg w-100">
          <?= mpT('Submit Order Request', 'Soumettre la demande de commande') ?>
        </button>
      </form>
    </div>

    <div class="col-lg-5">
      <div class="card shadow-sm border-success">
        <div class="card-header bg-success text-white"><?= mpT('Order Summary', 'Récapitulatif de commande') ?></div>
        <div class="card-body">
          <div class="fw-bold mb-1"><?= e($selectedProduct['name']) ?></div>
          <div class="text-muted small mb-3"><?= e($selectedBusiness['title']) ?></div>
          <div class="d-flex justify-content-between border-top pt-3 mt-3">
            <span><?= mpT('Total due now', 'Total à payer') ?></span>
            <strong class="text-success" style="font-size:1.3rem;"><?= mpFmt($dueNow) ?></strong>
          </div>
          <?php if ($selectedPrice['billing_interval'] !== 'NONE' && $selectedPrice['recurring_amount'] > 0): ?>
          <div class="text-muted small mt-2">
            + <?= mpFmt($selectedPrice['recurring_amount']) ?> <?= mpInterval($selectedPrice['billing_interval']) ?>
            <?= mpT('after activation', 'après activation') ?>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($mtnNum || $orangeNum): ?>
      <div class="card shadow-sm mt-3">
        <div class="card-header"><?= mpT('Payment Info', 'Infos de paiement') ?></div>
        <div class="card-body small text-muted">
          <?= nl2br(e($payInstruction)) ?>
          <?php if ($mtnNum): ?><div class="mt-2">📱 MTN MoMo: <strong><?= e($mtnNum) ?></strong></div><?php endif; ?>
          <?php if ($orangeNum): ?><div>🍊 Orange Money: <strong><?= e($orangeNum) ?></strong></div><?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>

<?php elseif ($selectedBusiness): ?>
  <!-- ── STEP 2: Product Catalogue ──────────────────────────── -->
  <div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
    <div>
      <h1 style="font-family:'Fraunces',serif;font-size:1.8rem;font-weight:900;margin:0;">
        🛒 <?= mpT('Services Marketplace', 'Marketplace de Services') ?>
      </h1>
      <div class="text-muted mt-1">
        <?= mpT('Ordering for:', 'Commander pour :') ?>
        <strong><?= e($selectedBusiness['title']) ?></strong>
        <a href="<?= SITE_URL ?>/partner/marketplace" class="ms-2 small text-secondary">(<?= mpT('change', 'changer') ?>)</a>
      </div>
    </div>
  </div>

  <?php if (empty($catalogue)): ?>
  <div class="text-center py-5 card">
    <div class="card-body">
      <div style="font-size:3rem;">🏗️</div>
      <h4 class="mt-3"><?= mpT('Marketplace coming soon', 'Marketplace bientôt disponible') ?></h4>
      <p class="text-muted"><?= mpT(
          'The admin is currently configuring the service catalogue. Please check back shortly.',
          'L\'administrateur configure actuellement le catalogue de services. Veuillez revenir bientôt.'
      ) ?></p>
    </div>
  </div>
  <?php else: ?>

  <?php foreach ($catalogue as $cat): ?>
  <div class="mb-5">
    <h3 class="mb-3"><?= e($cat['icon']) ?> <?= e($cat['name']) ?></h3>
    <div class="row g-3">
      <?php foreach ($cat['products'] as $prod):
        // Show primary price (setup or first recurring)
        $mainPrice = null;
        $renewalPrice = null;
        foreach ($prod['prices'] as $pr) {
            if ($pr['price_type'] === 'setup' && $mainPrice === null) $mainPrice = $pr;
            if ($pr['price_type'] === 'recurring' && $renewalPrice === null) $renewalPrice = $pr;
        }
        if (!$mainPrice) $mainPrice = $renewalPrice;
        if (!$mainPrice) continue;
        $orderUrl = SITE_URL . '/partner/marketplace?listing_id=' . $listingId
                  . '&product_id=' . $prod['id']
                  . '&price_id=' . $mainPrice['id'];
      ?>
      <div class="col-md-6 col-xl-4">
        <div class="card h-100 shadow-sm" style="border-top:3px solid #00A878;">
          <div class="card-body d-flex flex-column">
            <h5 class="card-title"><?= e($prod['name']) ?></h5>
            <?php if ($prod['description']): ?>
            <p class="card-text text-muted small"><?= e($prod['description']) ?></p>
            <?php endif; ?>

            <div class="mt-auto">
              <?php if ($mainPrice['price_type'] === 'setup'): ?>
              <div class="fw-bold text-success" style="font-size:1.2rem;"><?= mpFmt($mainPrice['setup_amount']) ?></div>
              <div class="text-muted small"><?= mpT('one-time setup', 'mise en place unique') ?></div>
              <?php if ($renewalPrice && $renewalPrice['recurring_amount'] > 0): ?>
              <div class="text-muted small">+ <?= mpFmt($renewalPrice['recurring_amount']) ?> <?= mpInterval($renewalPrice['billing_interval']) ?></div>
              <?php endif; ?>
              <?php else: ?>
              <div class="fw-bold text-success" style="font-size:1.2rem;">
                <?= mpFmt($mainPrice['recurring_amount']) ?> <?= mpInterval($mainPrice['billing_interval']) ?>
              </div>
              <?php if ($mainPrice['mailbox_limit']): ?>
              <div class="text-muted small"><?= mpT('up to', 'jusqu\'à') ?> <?= (int)$mainPrice['mailbox_limit'] ?> <?= mpT('mailboxes', 'boîtes mail') ?></div>
              <?php endif; ?>
              <?php endif; ?>

              <a href="<?= $orderUrl ?>" class="btn btn-success btn-sm mt-3 w-100">
                <?= mpT('Select →', 'Choisir →') ?>
              </a>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

<?php else: ?>
  <!-- ── STEP 1: Select Business ────────────────────────────── -->
  <div class="mb-4">
    <h1 style="font-family:'Fraunces',serif;font-size:2rem;font-weight:900;margin:0;">
      🛒 <?= mpT('Services Marketplace', 'Marketplace de Services') ?>
    </h1>
    <p class="text-muted mt-1"><?= mpT(
        'Request professional services for businesses in your portfolio.',
        'Commandez des services professionnels pour les entreprises de votre portefeuille.'
    ) ?></p>
  </div>

  <?php if (!$businessList): ?>
  <div class="text-center py-5 card">
    <div class="card-body">
      <div style="font-size:3rem;">📭</div>
      <h4 class="mt-3"><?= mpT('No businesses in your portfolio', 'Aucune entreprise dans votre portefeuille') ?></h4>
      <p class="text-muted"><?= mpT(
          'You need an actively assigned business to place a service order.',
          'Vous avez besoin d\'une entreprise assignée active pour passer une commande.'
      ) ?></p>
    </div>
  </div>
  <?php else: ?>
  <div class="card shadow-sm mb-4">
    <div class="card-header fw-bold"><?= mpT('Select a Business', 'Sélectionner une entreprise') ?></div>
    <div class="list-group list-group-flush">
      <?php foreach ($businessList as $biz): ?>
      <a href="<?= SITE_URL ?>/partner/marketplace?listing_id=<?= $biz['id'] ?>"
         class="list-group-item list-group-item-action d-flex align-items-center justify-content-between py-3">
        <div>
          <strong><?= e($biz['title']) ?></strong>
          <div class="text-muted small"><?= e($biz['cat_en']) ?> · <?= e($biz['city']) ?></div>
        </div>
        <span class="text-success">→</span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

<?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
