<?php
/**
 * partner/marketplace.php — Growth Partner Services Marketplace
 *
 * Step 1: partner selects a business from their portfolio
 * Step 2: partner browses the service catalogue (shopping cart UI)
 * Step 3: order confirmation / success
 *
 * Cart is JS-driven (sessionStorage), supports multi-item checkout.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';

$partnerProfile = requireGrowthPartner();
$partnerId      = (int)$partnerProfile['id'];
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
$listingId      = (int)($_GET['listing_id'] ?? 0);
$errors         = [];
$orderSubmitted = false;
$submittedRefs  = [];

/* ── POST: submit multi-item order ──────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_order'])) {
    verifyCsrfOrDie();

    $listingId   = (int)($_POST['listing_id'] ?? 0);
    $partnerNote = trim($_POST['partner_note'] ?? '');
    $cartJson    = $_POST['cart_json'] ?? '[]';
    $cartItems   = json_decode($cartJson, true) ?: [];

    /* 1. Verify portfolio assignment */
    $stA = $pdo->prepare("
        SELECT pba.id FROM partner_business_assignments pba
        WHERE pba.partner_id = ? AND pba.listing_id = ? AND pba.status = 'active'
        LIMIT 1
    ");
    $stA->execute([$partnerId, $listingId]);
    if (!$stA->fetch()) {
        $stOwn = $pdo->prepare("SELECT id FROM listings WHERE id=? AND user_id=(SELECT user_id FROM partner_profiles WHERE id=?) LIMIT 1");
        $stOwn->execute([$listingId, $partnerId]);
        if (!$stOwn->fetch()) {
            $errors[] = mpT('You are not authorised to order for this business.', 'Vous n\'êtes pas autorisé à commander pour cette entreprise.');
        }
    }

    /* 2. Validate listing */
    $stL = $pdo->prepare("SELECT id, title, user_id FROM listings WHERE id = ? AND status IN ('active','approved') LIMIT 1");
    $stL->execute([$listingId]);
    $listing = $stL->fetch();
    if (!$listing) {
        $errors[] = mpT('Business not found or not active.', 'Entreprise introuvable ou inactive.');
    }

    if (!$cartItems) {
        $errors[] = mpT('Your cart is empty.', 'Votre panier est vide.');
    }

    if (!$errors && $listing && $cartItems) {
        /* 3. Create one order per cart item (keeps existing approval flow intact) */
        foreach ($cartItems as $ci) {
            $productId   = (int)($ci['product_id'] ?? 0);
            $priceId     = (int)($ci['price_id']   ?? 0);
            $mailboxCount = (int)($ci['mailbox_count'] ?? 0);
            $serviceReq  = [];

            /* Validate product + price */
            $stP = $pdo->prepare("
                SELECT p.*, c.`key` AS cat_key,
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
            if (!$product) continue;

            /* Build service requirements */
            if ($partnerNote) $serviceReq['partner_note'] = $partnerNote;
            if ($mailboxCount > 0) $serviceReq['mailbox_count'] = $mailboxCount;

            /* Additional fields from cart item */
            if (!empty($ci['fields']) && is_array($ci['fields'])) {
                foreach ($ci['fields'] as $k => $v) {
                    $serviceReq[$k] = $v;
                }
            }

            $dueNow  = $product['price_type'] === 'setup'
                ? $product['setup_amount']
                : $product['recurring_amount'];

            $ref     = generateRef('MPO');
            $idemKey = bin2hex(random_bytes(16));

            /* Insert order */
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

            /* Snapshots */
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

            /* Insert order item */
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

            /* Approval record */
            $expiryDays = (int)(getSettingCached('mp_approval_expiry_days') ?: 7);
            $approvalExpiry = $expiryDays > 0
                ? date('Y-m-d H:i:s', strtotime("+{$expiryDays} days"))
                : null;
            $pdo->prepare("
                INSERT INTO mp_order_approvals (order_id, decision, requested_at, expires_at)
                VALUES (?, 'PENDING', NOW(), ?)
            ")->execute([$orderId, $approvalExpiry]);

            /* Audit */
            $pdo->prepare("
                INSERT INTO mp_audit_events (entity_type, entity_id, actor_user_id, actor_role, action, after_state, ip_address)
                VALUES ('marketplace_orders', ?, ?, 'growth_partner', 'order.created',
                        JSON_OBJECT('order_state','PENDING_OWNER_APPROVAL','ref',?), ?)
            ")->execute([$orderId, $user['id'], $ref, $_SERVER['REMOTE_ADDR'] ?? null]);

            /* Notify owner (once per order) */
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
                     <p><a href="' . $approveUrl . '" style="background:#00A878;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;">'
                        . mpT('Review Request →', 'Examiner la demande →') . '</a></p>'
                );
            }

            /* In-app notification */
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

            $submittedRefs[] = $ref;
        }

        if ($submittedRefs) {
            $orderSubmitted = true;
        }
    }
}

/* ── READ: partner's portfolio businesses ────────────────────── */
$businesses = $pdo->prepare("
    SELECT l.id, l.title, l.verified,
           c.name_en AS cat_en, loc.name_en AS city
    FROM listings l
    JOIN categories c   ON c.id  = l.category_id
    JOIN locations  loc ON loc.id = l.location_id
    WHERE l.status IN ('active','approved')
      AND (
        EXISTS (
            SELECT 1 FROM partner_business_assignments pba
            WHERE pba.listing_id = l.id AND pba.partner_id = ? AND pba.status = 'active'
        )
        OR l.user_id = (SELECT user_id FROM partner_profiles WHERE id = ?)
      )
    ORDER BY l.title ASC
");
$businesses->execute([$partnerId, $partnerId]);
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
        WHERE l.id = ? AND l.status IN ('active','approved')
          AND (
            EXISTS (
                SELECT 1 FROM partner_business_assignments pba
                WHERE pba.listing_id = l.id AND pba.partner_id = ? AND pba.status = 'active'
            )
            OR l.user_id = (SELECT user_id FROM partner_profiles WHERE id = ?)
          )
        LIMIT 1
    ");
    $stSB->execute([$listingId, $partnerId, $partnerId]);
    $selectedBusiness = $stSB->fetch();
}

/* ── READ: service catalogue ─────────────────────────────────── */
$catalogue = [];
if ($selectedBusiness) {
    $stCat = $pdo->prepare("
        SELECT cat.id AS cat_id, cat.`key` AS cat_key,
               cat.name_en AS cat_name_en, cat.name_fr AS cat_name_fr,
               cat.icon,
               p.id AS product_id, p.`key` AS product_key,
               p.name_en, p.name_fr, p.description_en, p.description_fr,
               p.service_type, p.form_schema, p.terms_en, p.terms_fr,
               p.partner_commission_rate, p.sort_order AS prod_sort,
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
                'id'              => $pid,
                'key'             => $row['product_key'],
                'name'            => $lang === 'fr' ? $row['name_fr'] : $row['name_en'],
                'description'     => $lang === 'fr' ? $row['description_fr'] : $row['description_en'],
                'service_type'    => $row['service_type'],
                'form_schema'     => json_decode($row['form_schema'] ?? '{}', true) ?: [],
                'terms'           => $lang === 'fr' ? $row['terms_fr'] : $row['terms_en'],
                'commission_rate' => (float)($row['partner_commission_rate'] ?? 0),
                'sort_order'      => (int)$row['prod_sort'],
                'prices'          => [],
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

/* ── payment instructions ─────────────────────────────────────── */
$payInstructKey = $lang === 'fr' ? 'mp_payment_instruction_fr' : 'mp_payment_instruction_en';
$payInstruction = getSettingCached($payInstructKey) ?: '';
$mtnNum    = getSettingCached('mtn_momo_number')    ?: '';
$orangeNum = getSettingCached('orange_money_number') ?: '';

/* ── Build catalogue JSON for JS ─────────────────────────────── */
$catalogueJson = json_encode($catalogue, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

/* ── Page ─────────────────────────────────────────────────────── */
$pageTitle = mpT('Services Marketplace', 'Marketplace de Services') . ' — Growth Workspace';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/includes/nav.php';
partnerNavBadges($partnerId, $pdo);
?>
<?php partnerNavAssets(); ?>
<?php partnerNav('marketplace'); ?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">

<style>
/* ── Marketplace Styles ─────────────────────────── */
:root {
  --mp-green: #00A878;
  --mp-green-dim: rgba(0,168,120,0.12);
  --mp-green-border: rgba(0,168,120,0.3);
  --mp-bg-dark: #081410;
  --mp-radius: 12px;
  --mp-panel-bg: #ffffff;
  --mp-panel-fg: #212529;
  --mp-card-bg: #ffffff;
  --mp-card-border: #dee2e6;
}
/* Dark mode — the site uses a dark background so detect it */
@media (prefers-color-scheme: dark) {
  :root {
    --mp-panel-bg: #0d1f1a;
    --mp-panel-fg: #e8f5f1;
    --mp-card-bg: #0d1f1a;
    --mp-card-border: rgba(0,168,120,0.25);
  }
}
/* Also support explicit data-theme on <html> if the site sets it */
[data-theme="dark"] {
  --mp-panel-bg: #0d1f1a;
  --mp-panel-fg: #e8f5f1;
  --mp-card-bg: #0d1f1a;
  --mp-card-border: rgba(0,168,120,0.25);
}

/* Cart panel */
#mp-cart-panel {
  position: sticky;
  top: 80px;
  max-height: calc(100vh - 100px);
  overflow-y: auto;
  border-radius: var(--mp-radius);
  border: 1.5px solid var(--mp-green-border);
  background: var(--mp-panel-bg);
  color: var(--mp-panel-fg);
}

/* Category accordion */
.mp-cat-header {
  background: linear-gradient(135deg, var(--mp-green-dim), transparent);
  border-left: 4px solid var(--mp-green);
  border-radius: 8px;
  padding: 1rem 1.25rem;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: space-between;
  transition: background 0.15s;
}
.mp-cat-header:hover { background: rgba(0,168,120,0.18); }

/* Product cards */
.mp-product-card {
  border: 1.5px solid var(--mp-card-border);
  border-radius: var(--mp-radius);
  background: var(--mp-card-bg);
  color: var(--mp-panel-fg);
  transition: border-color 0.2s, transform 0.15s, box-shadow 0.15s;
  cursor: default;
}
.mp-product-card:hover {
  border-color: var(--mp-green);
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(0,168,120,0.15);
}
.mp-product-card.in-cart {
  border-color: var(--mp-green);
  background: var(--mp-green-dim);
}

/* Badge for in-cart */
.mp-in-cart-badge {
  display: none;
  font-size: 0.7rem;
  background: var(--mp-green);
  color: #fff;
  padding: 2px 8px;
  border-radius: 20px;
  vertical-align: middle;
}
.mp-product-card.in-cart .mp-in-cart-badge { display: inline; }

/* Price display */
.mp-price-big { font-size: 1.3rem; font-weight: 700; color: var(--mp-green); }
.mp-price-sub { font-size: 0.78rem; color: #6c757d; }

/* Upsell banner */
.mp-upsell-banner {
  border: 1.5px dashed var(--mp-green);
  background: var(--mp-green-dim);
  border-radius: 10px;
  padding: 0.75rem 1rem;
  margin-top: 0.5rem;
  font-size: 0.84rem;
  display: none;
}

/* Cart item row */
.mp-cart-item {
  border-bottom: 1px solid rgba(0,168,120,0.15);
  padding: 0.6rem 0;
  font-size: 0.85rem;
}
.mp-cart-item:last-child { border-bottom: none; }

/* Sticky total bar (mobile) */
#mp-sticky-bar {
  display: none;
  position: fixed;
  bottom: 0; left: 0; right: 0;
  background: var(--mp-panel-bg);
  color: var(--mp-panel-fg);
  border-top: 2px solid var(--mp-green);
  padding: 0.75rem 1.25rem;
  z-index: 1030;
  box-shadow: 0 -4px 16px rgba(0,0,0,0.12);
}
@media (max-width: 991px) { #mp-sticky-bar { display: block; } }

/* Commission tag */
.mp-commission-tag {
  font-size: 0.75rem;
  background: var(--mp-green-dim);
  border: 1px solid var(--mp-green-border);
  border-radius: 20px;
  padding: 2px 10px;
  color: var(--mp-green);
  font-weight: 600;
}

/* Tier badge colors */
.mp-tier-starter  { background: #e8f8f4; color: #00A878; border-color: #aeddd1; }
.mp-tier-pro      { background: #fff3cd; color: #856404; border-color: #ffe187; }
.mp-tier-premium  { background: #cff4fc; color: #055160; border-color: #9eeaf9; }
.mp-tier-enterprise { background: #f8d7da; color: #842029; border-color: #f5c2c7; }

.btn-mp {
  background: var(--mp-green);
  color: #fff;
  border: none;
  border-radius: 8px;
  padding: 0.45rem 1rem;
  font-weight: 600;
  font-size: 0.85rem;
  transition: opacity 0.15s;
}
.btn-mp:hover { opacity: 0.88; color: #fff; }
.btn-mp-outline {
  background: transparent;
  color: var(--mp-green);
  border: 1.5px solid var(--mp-green);
  border-radius: 8px;
  padding: 0.4rem 1rem;
  font-weight: 600;
  font-size: 0.85rem;
  transition: background 0.15s;
}
.btn-mp-outline:hover { background: var(--mp-green-dim); }

.mp-empty-cart {
  text-align: center;
  padding: 1.5rem 1rem;
  color: #adb5bd;
  font-size: 0.85rem;
}
</style>

<div style="max-width:1200px; margin:0 auto; padding:2rem 1.5rem 5rem;">

<?php if ($orderSubmitted): ?>
<!-- ── ORDER SUCCESS ───────────────────────────────────────────── -->
<div class="text-center py-5">
  <div style="font-size:4rem;">✅</div>
  <h2 class="mt-3"><?= count($submittedRefs) > 1
      ? mpT('Orders Submitted!', 'Commandes soumises !')
      : mpT('Order Submitted!', 'Commande soumise !') ?></h2>
  <p class="text-muted"><?= mpT(
      count($submittedRefs) > 1 ? 'References:' : 'Reference:',
      count($submittedRefs) > 1 ? 'Références :' : 'Référence :'
  ) ?>
  <?php foreach ($submittedRefs as $r): ?>
    <strong class="ms-1"><?= e($r) ?></strong>
  <?php endforeach; ?>
  </p>
  <p class="text-muted"><?= mpT(
      'The business owner has been notified and must approve before payment is collected.',
      'Le propriétaire de l\'entreprise a été notifié et doit approuver avant que le paiement soit collecté.'
  ) ?></p>
  <div class="d-flex gap-2 justify-content-center flex-wrap mt-3">
    <?php foreach ($submittedRefs as $r): ?>
    <a href="<?= SITE_URL ?>/partner/marketplace-order?ref=<?= e($r) ?>" class="btn btn-success">
      <?= mpT('Track', 'Suivre') ?> <?= e($r) ?> →
    </a>
    <?php endforeach; ?>
    <a href="<?= SITE_URL ?>/partner/marketplace?listing_id=<?= $listingId ?>" class="btn btn-outline-secondary">
      <?= mpT('Place Another Order', 'Passer une autre commande') ?>
    </a>
  </div>
</div>

<?php elseif ($selectedBusiness): ?>
<!-- ── STEP 2: Catalogue + Cart ───────────────────────────────── -->

<!-- Page header -->
<div class="d-flex align-items-start gap-3 mb-4 flex-wrap">
  <div class="flex-grow-1">
    <h1 style="font-family:'Fraunces',serif;font-size:1.8rem;font-weight:900;margin:0;">
      🛒 <?= mpT('Services Marketplace', 'Marketplace de Services') ?>
    </h1>
    <div class="text-muted mt-1 small">
      <?= mpT('Ordering for:', 'Commander pour :') ?>
      <strong><?= e($selectedBusiness['title']) ?></strong>
      <span class="text-muted">&bull; <?= e($selectedBusiness['cat_en']) ?> · <?= e($selectedBusiness['city']) ?></span>
      <a href="<?= SITE_URL ?>/partner/marketplace" class="ms-2 text-secondary">(<?= mpT('change business', 'changer d\'entreprise') ?>)</a>
    </div>
  </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger mb-3">
  <?php foreach ($errors as $err): ?><div>⚠ <?= e($err) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (empty($catalogue)): ?>
<div class="text-center py-5 card"><div class="card-body">
  <div style="font-size:3rem;">🏗️</div>
  <h4 class="mt-3"><?= mpT('Marketplace coming soon', 'Marketplace bientôt disponible') ?></h4>
  <p class="text-muted"><?= mpT(
      'The admin is currently configuring the service catalogue. Please check back shortly.',
      'L\'administrateur configure actuellement le catalogue de services. Veuillez revenir bientôt.'
  ) ?></p>
</div></div>
<?php else: ?>

<div class="row g-4">
  <!-- LEFT: Catalogue -->
  <div class="col-lg-8">

    <?php $catIndex = 0; foreach ($catalogue as $cat): $catIndex++; ?>
    <div class="mb-4">
      <!-- Category header -->
      <div class="mp-cat-header mb-3" data-bs-toggle="collapse" data-bs-target="#cat-<?= e($cat['key']) ?>" aria-expanded="true">
        <div class="d-flex align-items-center gap-2">
          <span style="font-size:1.5rem;"><?= e($cat['icon']) ?></span>
          <div>
            <div class="fw-bold" style="font-size:1.1rem;"><?= e($cat['name']) ?></div>
            <div class="text-muted small"><?= count($cat['products']) ?> <?= mpT('service', 'service') ?><?= count($cat['products']) > 1 ? 's' : '' ?></div>
          </div>
        </div>
        <i class="bi bi-chevron-down text-muted" style="transition:transform 0.2s;" id="chevron-<?= e($cat['key']) ?>"></i>
      </div>

      <!-- Products grid -->
      <div class="collapse show" id="cat-<?= e($cat['key']) ?>">
        <div class="row g-3">
          <?php
          $products = array_values($cat['products']);
          // Sort by sort_order
          usort($products, fn($a, $b) => $a['sort_order'] <=> $b['sort_order']);
          $prodCount = count($products);
          foreach ($products as $prodIdx => $prod):
            // Pick primary price
            $mainPrice = null;
            $renewalPrice = null;
            foreach ($prod['prices'] as $pr) {
                if ($pr['price_type'] === 'setup' && !$mainPrice) $mainPrice = $pr;
                if ($pr['price_type'] === 'recurring' && !$renewalPrice) $renewalPrice = $pr;
            }
            if (!$mainPrice) $mainPrice = $renewalPrice;
            if (!$mainPrice) continue;

            $dueNow    = $mainPrice['price_type'] === 'setup' ? $mainPrice['setup_amount'] : $mainPrice['recurring_amount'];
            $commRate  = $prod['commission_rate'];
            $commAmt   = $commRate > 0 ? (int)round($dueNow * $commRate / 100) : 0;

            // Tier detection for styling (by sort order position)
            $tierClass = match($prodIdx) {
                0 => 'mp-tier-starter',
                1 => 'mp-tier-pro',
                2 => 'mp-tier-premium',
                default => 'mp-tier-enterprise',
            };
          ?>
          <div class="col-md-6 col-xl-4">
            <div class="card mp-product-card h-100"
                 id="card-<?= $prod['id'] ?>"
                 data-product-id="<?= $prod['id'] ?>"
                 data-price-id="<?= $mainPrice['id'] ?>"
                 data-product-name="<?= e($prod['name']) ?>"
                 data-cat-key="<?= e($cat['key']) ?>"
                 data-cat-name="<?= e($cat['name']) ?>"
                 data-due-now="<?= $dueNow ?>"
                 data-price-type="<?= e($mainPrice['price_type']) ?>"
                 data-setup-amount="<?= $mainPrice['setup_amount'] ?>"
                 data-recurring-amount="<?= $mainPrice['recurring_amount'] ?>"
                 data-billing-interval="<?= e($mainPrice['billing_interval'] ?? '') ?>"
                 data-mailbox-limit="<?= (int)($mainPrice['mailbox_limit'] ?? 0) ?>"
                 data-comm-rate="<?= $commRate ?>"
                 data-comm-amt="<?= $commAmt ?>"
                 data-prod-sort="<?= $prodIdx ?>"
                 data-prod-count="<?= $prodCount ?>">
              <div class="card-body d-flex flex-column p-3">
                <!-- Name + badge -->
                <div class="d-flex align-items-start justify-content-between mb-1 gap-2">
                  <div>
                    <span class="badge <?= $tierClass ?> mb-1" style="font-size:0.68rem;">
                      <?= mpT(
                          match($prodIdx){ 0=>'Starter', 1=>'Professional', 2=>'Premium', default=>'Enterprise' },
                          match($prodIdx){ 0=>'Démarrage', 1=>'Professionnel', 2=>'Premium', default=>'Entreprise' }
                      ) ?>
                    </span>
                    <span class="mp-in-cart-badge">✓ <?= mpT('In cart', 'Dans le panier') ?></span>
                  </div>
                </div>

                <h6 class="card-title mb-1" style="font-size:0.95rem;"><?= e($prod['name']) ?></h6>
                <?php if ($prod['description']): ?>
                <p class="card-text text-muted" style="font-size:0.78rem; flex-grow:1;"><?= e($prod['description']) ?></p>
                <?php endif; ?>

                <!-- Price -->
                <div class="mt-auto">
                  <div class="mp-price-big">
                    <?= mpFmt($dueNow) ?>
                  </div>
                  <?php if ($mainPrice['price_type'] === 'setup'): ?>
                  <div class="mp-price-sub"><?= mpT('one-time setup', 'mise en place unique') ?></div>
                  <?php if ($renewalPrice && $renewalPrice['recurring_amount'] > 0): ?>
                  <div class="mp-price-sub">+ <?= mpFmt($renewalPrice['recurring_amount']) ?> <?= mpInterval($renewalPrice['billing_interval']) ?></div>
                  <?php endif; ?>
                  <?php else: ?>
                  <div class="mp-price-sub"><?= mpInterval($mainPrice['billing_interval']) ?></div>
                  <?php if ($mainPrice['mailbox_limit']): ?>
                  <div class="mp-price-sub"><?= mpT('up to', 'jusqu\'à') ?> <?= (int)$mainPrice['mailbox_limit'] ?> <?= mpT('mailboxes', 'boîtes mail') ?></div>
                  <?php endif; ?>
                  <?php endif; ?>

                  <?php if ($commRate > 0): ?>
                  <div class="mt-1">
                    <span class="mp-commission-tag">💰 +<?= mpFmt($commAmt) ?> <?= mpT('commission', 'commission') ?></span>
                  </div>
                  <?php endif; ?>

                  <!-- Add/Remove cart button -->
                  <button type="button"
                          class="btn btn-mp w-100 mt-3 mp-cart-btn"
                          data-product-id="<?= $prod['id'] ?>"
                          onclick="mpToggleCart(<?= $prod['id'] ?>)">
                    <i class="bi bi-cart-plus me-1"></i> <?= mpT('Add to Order', 'Ajouter à la commande') ?>
                  </button>
                </div>
              </div>
            </div>

            <!-- Upsell banner (shown below the card if a lower tier is in cart) -->
            <div class="mp-upsell-banner" id="upsell-<?= $prod['id'] ?>">
              <span class="fw-semibold">⚡ <?= mpT('Upgrade suggestion', 'Suggestion de mise à niveau') ?></span><br>
              <span id="upsell-text-<?= $prod['id'] ?>"></span>
              <button class="btn btn-mp-outline btn-sm mt-1" onclick="mpUpsellSwitch(<?= $prod['id'] ?>)">
                <?= mpT('Upgrade →', 'Mettre à niveau →') ?>
              </button>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>

  </div><!-- /col-lg-8 -->

  <!-- RIGHT: Cart panel -->
  <div class="col-lg-4 d-none d-lg-block">
    <div id="mp-cart-panel" class="p-3">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <h6 class="fw-bold mb-0">🛒 <?= mpT('Your Order', 'Votre commande') ?></h6>
        <span class="badge bg-success" id="cart-count-badge">0</span>
      </div>

      <!-- Cart items list -->
      <div id="mp-cart-items">
        <div class="mp-empty-cart" id="mp-cart-empty">
          <i class="bi bi-cart3 d-block mb-2" style="font-size:2rem;"></i>
          <?= mpT('No services selected yet.<br>Browse the catalogue →', 'Aucun service sélectionné.<br>Parcourez le catalogue →') ?>
        </div>
      </div>

      <!-- Totals -->
      <div id="mp-cart-totals" class="d-none border-top pt-3 mt-2">
        <div class="d-flex justify-content-between small mb-1">
          <span><?= mpT('Subtotal due now', 'Sous-total à payer') ?></span>
          <strong id="cart-total-amount" class="text-success"></strong>
        </div>
        <div class="d-flex justify-content-between small text-muted mb-2" id="cart-commission-row" style="display:none!important;">
          <span>💰 <?= mpT('Your total commission', 'Votre commission totale') ?></span>
          <strong id="cart-commission-amount" style="color:var(--mp-green);"></strong>
        </div>
        <div class="alert alert-warning p-2 mb-2" style="font-size:0.76rem;">
          ⚠ <?= mpT('Owner approval required before payment.', 'Approbation du propriétaire requise avant paiement.') ?>
        </div>
      </div>

      <!-- Note field -->
      <div class="mt-2" id="mp-note-wrap" style="display:none;">
        <label class="form-label small fw-semibold"><?= mpT('Note to admin (optional)', 'Note à l\'admin (facultatif)') ?></label>
        <textarea id="mp-partner-note" class="form-control form-control-sm" rows="2" placeholder="<?= mpT('Any specific requirements...', 'Exigences spécifiques...') ?>"></textarea>
      </div>

      <!-- Checkout button -->
      <div class="mt-3" id="mp-checkout-wrap" style="display:none;">
        <button class="btn btn-mp w-100" id="mp-checkout-btn" onclick="mpCheckout()">
          <i class="bi bi-check2-circle me-1"></i> <?= mpT('Submit Order Request', 'Soumettre la commande') ?>
        </button>
      </div>

      <!-- Payment info -->
      <?php if ($mtnNum || $orangeNum): ?>
      <div class="mt-3 border-top pt-3 small text-muted" id="mp-payment-info" style="display:none;">
        <?php if ($payInstruction): ?><div class="mb-1"><?= nl2br(e($payInstruction)) ?></div><?php endif; ?>
        <?php if ($mtnNum): ?><div>📱 MTN MoMo: <strong><?= e($mtnNum) ?></strong></div><?php endif; ?>
        <?php if ($orangeNum): ?><div>🍊 Orange Money: <strong><?= e($orangeNum) ?></strong></div><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div><!-- /col-lg-4 -->
</div><!-- /row -->

<!-- Hidden checkout form -->
<form id="mp-checkout-form" method="POST" action="<?= SITE_URL ?>/partner/marketplace?listing_id=<?= $listingId ?>" style="display:none;">
  <?= csrfField() ?>
  <input type="hidden" name="listing_id"   value="<?= $listingId ?>">
  <input type="hidden" name="submit_order"  value="1">
  <input type="hidden" name="partner_note"  id="form-partner-note">
  <input type="hidden" name="cart_json"     id="form-cart-json">
</form>

<!-- Mobile sticky bar -->
<div id="mp-sticky-bar">
  <div class="d-flex align-items-center justify-content-between">
    <div>
      <span class="fw-bold" id="sticky-total"></span>
      <span class="text-muted small ms-1" id="sticky-items"></span>
    </div>
    <button class="btn btn-mp" onclick="mpCheckout()" id="mp-sticky-checkout" disabled>
      <?= mpT('Submit Order', 'Soumettre') ?>
    </button>
  </div>
</div>

<?php endif; // catalogue not empty ?>

<?php else: ?>
<!-- ── STEP 1: Select Business ────────────────────────────────── -->
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
<div class="text-center py-5 card"><div class="card-body">
  <div style="font-size:3rem;">📭</div>
  <h4 class="mt-3"><?= mpT('No businesses in your portfolio', 'Aucune entreprise dans votre portefeuille') ?></h4>
  <p class="text-muted"><?= mpT(
      'You need an actively assigned business to place a service order.',
      'Vous avez besoin d\'une entreprise assignée active pour passer une commande.'
  ) ?></p>
</div></div>
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

</div><!-- /main container -->

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>

<?php if ($selectedBusiness && !$orderSubmitted && !empty($catalogue)): ?>
<script>
/* ── Marketplace Cart Engine ─────────────────────────── */
const LISTING_ID  = <?= $listingId ?>;
const LANG        = <?= json_encode($lang) ?>;
const CATALOGUE   = <?= $catalogueJson ?>;
const FMT_FCFA    = n => new Intl.NumberFormat('fr-FR').format(n) + ' FCFA';

// Cart: { productId: { product_id, price_id, product_name, cat_key, cat_name,
//                       due_now, price_type, setup_amount, recurring_amount,
//                       billing_interval, mailbox_limit, comm_rate, comm_amt,
//                       prod_sort, prod_count } }
let cart = {};

// --- Chevron toggle on accordion ---
document.querySelectorAll('[data-bs-target]').forEach(btn => {
  const targetId = btn.getAttribute('data-bs-target');
  const collapse = document.querySelector(targetId);
  if (!collapse) return;
  collapse.addEventListener('show.bs.collapse', () => {
    const ch = btn.querySelector('[id^="chevron-"]');
    if (ch) ch.style.transform = 'rotate(0deg)';
  });
  collapse.addEventListener('hide.bs.collapse', () => {
    const ch = btn.querySelector('[id^="chevron-"]');
    if (ch) ch.style.transform = 'rotate(-90deg)';
  });
});

function mpToggleCart(productId) {
  if (cart[productId]) {
    mpRemoveFromCart(productId);
  } else {
    mpAddToCart(productId);
  }
}

function mpAddToCart(productId) {
  const card = document.getElementById('card-' + productId);
  if (!card) return;
  const d = card.dataset;
  cart[productId] = {
    product_id:        parseInt(d.productId),
    price_id:          parseInt(d.priceId),
    product_name:      d.productName,
    cat_key:           d.catKey,
    cat_name:          d.catName,
    due_now:           parseInt(d.dueNow),
    price_type:        d.priceType,
    setup_amount:      parseInt(d.setupAmount),
    recurring_amount:  parseInt(d.recurringAmount),
    billing_interval:  d.billingInterval,
    mailbox_limit:     parseInt(d.mailboxLimit) || 0,
    comm_rate:         parseFloat(d.commRate) || 0,
    comm_amt:          parseInt(d.commAmt) || 0,
    prod_sort:         parseInt(d.prodSort),
    prod_count:        parseInt(d.prodCount),
  };
  mpRender();
  mpCheckUpsell(productId);
}

function mpRemoveFromCart(productId) {
  delete cart[productId];
  mpRender();
  mpCheckUpsell(productId);
  // Also hide upsell for items in same category
  mpCheckCategoryUpsells();
}

function mpRender() {
  const items      = Object.values(cart);
  const count      = items.length;
  const totalDue   = items.reduce((s, i) => s + i.due_now, 0);
  const totalComm  = items.reduce((s, i) => s + i.comm_amt, 0);

  // Badge
  document.getElementById('cart-count-badge').textContent = count;

  // Cart items HTML
  const itemsEl = document.getElementById('mp-cart-items');
  const emptyEl = document.getElementById('mp-cart-empty');
  if (count === 0) {
    // Reset items list to just the empty message
    itemsEl.innerHTML = '';
    itemsEl.appendChild(Object.assign(document.createElement('div'), {
      className: 'mp-empty-cart',
      id: 'mp-cart-empty',
      innerHTML: '<i class="bi bi-cart3 d-block mb-2" style="font-size:2rem;"></i>' +
        (LANG === 'fr'
          ? 'Aucun service sélectionné.<br>Parcourez le catalogue →'
          : 'No services selected yet.<br>Browse the catalogue →')
    }));
  } else {
    let html = '';
    for (const item of items) {
      const intervalLabel = item.billing_interval === 'MONTH'
        ? (LANG === 'fr' ? '/ mois' : '/ month')
        : item.billing_interval === 'YEAR' ? (LANG === 'fr' ? '/ an' : '/ year') : '';
      const priceLabel = item.price_type === 'setup'
        ? FMT_FCFA(item.setup_amount) + (LANG === 'fr' ? ' (mise en place)' : ' (setup)')
        : FMT_FCFA(item.recurring_amount) + ' ' + intervalLabel;
      html += `<div class="mp-cart-item d-flex justify-content-between align-items-start gap-2">
        <div style="flex:1;min-width:0;">
          <div class="fw-semibold text-truncate" title="${item.product_name}">${item.product_name}</div>
          <div class="text-muted" style="font-size:0.72rem;">${item.cat_name}</div>
        </div>
        <div class="text-end" style="white-space:nowrap;">
          <div class="fw-bold text-success" style="font-size:0.85rem;">${priceLabel}</div>
          ${item.comm_amt > 0 ? `<div style="font-size:0.68rem;color:var(--mp-green);">+${FMT_FCFA(item.comm_amt)}</div>` : ''}
          <button class="btn btn-link btn-sm text-danger p-0" style="font-size:0.72rem;" onclick="mpRemoveFromCart(${item.product_id})">
            <i class="bi bi-x-circle"></i> ${LANG === 'fr' ? 'Retirer' : 'Remove'}
          </button>
        </div>
      </div>`;
    }
    itemsEl.innerHTML = html;
  }

  // Totals
  const totalsEl = document.getElementById('mp-cart-totals');
  if (count > 0) {
    totalsEl.classList.remove('d-none');
    document.getElementById('cart-total-amount').textContent = FMT_FCFA(totalDue);
    const commRow = document.getElementById('cart-commission-row');
    if (totalComm > 0) {
      commRow.style.removeProperty('display');
      document.getElementById('cart-commission-amount').textContent = FMT_FCFA(totalComm);
    } else {
      commRow.style.display = 'none';
    }
  } else {
    totalsEl.classList.add('d-none');
  }

  // Note + checkout
  document.getElementById('mp-note-wrap').style.display    = count > 0 ? '' : 'none';
  document.getElementById('mp-checkout-wrap').style.display = count > 0 ? '' : 'none';
  const piEl = document.getElementById('mp-payment-info');
  if (piEl) piEl.style.display = count > 0 ? '' : 'none';

  // Sticky bar (mobile)
  const stickyBtn = document.getElementById('mp-sticky-checkout');
  document.getElementById('sticky-total').textContent  = count > 0 ? FMT_FCFA(totalDue) : '';
  document.getElementById('sticky-items').textContent  = count > 0
    ? `(${count} ${LANG === 'fr' ? (count > 1 ? 'services' : 'service') : (count > 1 ? 'services' : 'service')})`
    : '';
  stickyBtn.disabled = count === 0;

  // Card state (in-cart highlight + button label)
  document.querySelectorAll('.mp-product-card').forEach(card => {
    const pid = parseInt(card.dataset.productId);
    const inCart = !!cart[pid];
    card.classList.toggle('in-cart', inCart);
    const btn = card.querySelector('.mp-cart-btn');
    if (btn) {
      if (inCart) {
        btn.innerHTML = '<i class="bi bi-cart-dash me-1"></i>' + (LANG === 'fr' ? 'Retirer' : 'Remove');
        btn.style.background = '#6c757d';
      } else {
        btn.innerHTML = '<i class="bi bi-cart-plus me-1"></i>' + (LANG === 'fr' ? 'Ajouter à la commande' : 'Add to Order');
        btn.style.background = '';
      }
    }
  });
}

/* ── Upsell Logic ──────────────────────────────────────── */
// If a lower-tier product is in cart and a higher tier exists in same category,
// show upgrade prompt below the higher-tier card.
function mpCheckUpsell(changedProductId) {
  mpCheckCategoryUpsells();
}

function mpCheckCategoryUpsells() {
  // For each category, find if any product is in cart
  // then show upsell banner on the NEXT tier up (if not already in cart)
  for (const catKey in CATALOGUE) {
    const cat = CATALOGUE[catKey];
    const prods = Object.values(cat.products).sort((a, b) => a.sort_order - b.sort_order);
    // Find lowest-sort-order item in cart for this category
    let lowestInCart = null;
    for (const prod of prods) {
      if (cart[prod.id]) {
        lowestInCart = prod;
        break;
      }
    }
    // Hide all upsell banners for this category first
    for (const prod of prods) {
      const el = document.getElementById('upsell-' + prod.id);
      if (el) el.style.display = 'none';
    }
    // Show upsell for the next tier up
    if (lowestInCart !== null) {
      const idx = prods.findIndex(p => p.id === lowestInCart.id);
      if (idx < prods.length - 1) {
        const nextProd = prods[idx + 1];
        if (!cart[nextProd.id]) {
          const el = document.getElementById('upsell-' + nextProd.id);
          const textEl = document.getElementById('upsell-text-' + nextProd.id);
          if (el && textEl) {
            // Calculate upgrade price difference
            const currentPrice = lowestInCart.prices[0] || {};
            const nextPrice = nextProd.prices[0] || {};
            const currentDue = currentPrice.price_type === 'setup' ? currentPrice.setup_amount : currentPrice.recurring_amount;
            const nextDue = nextPrice.price_type === 'setup' ? nextPrice.setup_amount : nextPrice.recurring_amount;
            const diff = nextDue - currentDue;

            if (LANG === 'fr') {
              textEl.innerHTML = `Passez à <strong>${nextProd.name}</strong> pour seulement <strong>+${FMT_FCFA(diff)}</strong> supplémentaire et bénéficiez de plus de fonctionnalités.`;
            } else {
              textEl.innerHTML = `Upgrade to <strong>${nextProd.name}</strong> for just <strong>+${FMT_FCFA(diff)}</strong> more and unlock more features.`;
            }
            el.style.display = 'block';
          }
        }
      }
    }
  }
}

function mpUpsellSwitch(upgradedProductId) {
  // Find what's in cart in same category and remove it, add this one
  const upgradeCard = document.getElementById('card-' + upgradedProductId);
  if (!upgradeCard) return;
  const catKey = upgradeCard.dataset.catKey;

  // Remove any cart items in same category
  for (const pid in cart) {
    if (cart[pid].cat_key === catKey) {
      delete cart[pid];
    }
  }
  mpAddToCart(upgradedProductId);
}

/* ── Checkout ──────────────────────────────────────────── */
function mpCheckout() {
  const items = Object.values(cart);
  if (!items.length) return;

  const note = document.getElementById('mp-partner-note').value;
  document.getElementById('form-partner-note').value = note;

  // Build cart JSON for server
  const cartData = items.map(i => ({
    product_id:   i.product_id,
    price_id:     i.price_id,
    mailbox_count: i.mailbox_limit > 0 ? 1 : 0,
    fields: {}
  }));
  document.getElementById('form-cart-json').value = JSON.stringify(cartData);
  document.getElementById('mp-checkout-form').submit();
}

// Init
mpRender();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
