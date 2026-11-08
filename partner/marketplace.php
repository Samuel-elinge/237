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
// Partner's personal marketplace commission rate (default 10% if column not yet set)
$partnerCommRate = (float)($partnerProfile['marketplace_commission_rate'] ?? 10.0);
if ($partnerCommRate <= 0) $partnerCommRate = 10.0;

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
                // Use partner's personal rate; fall back to product rate; then 10%
                'commission_rate' => $partnerCommRate > 0
                    ? $partnerCommRate
                    : ((float)($row['partner_commission_rate'] ?? 0) ?: 10.0),
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
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">

<style>
/* ══════════════════════════════════════════════════
   Marketplace — clean, premium, mobile-first
   Site is always dark (#081410 bg), so we design
   for dark and let light surfaces be explicit cards.
   ══════════════════════════════════════════════════ */
:root {
  --g:      #00A878;
  --g-dim:  rgba(0,168,120,0.14);
  --g-glow: rgba(0,168,120,0.25);
  --g-str:  rgba(0,168,120,0.9);
  --bg:     #081410;
  --surface: #0f201b;
  --surface2: #162920;
  --border:  rgba(255,255,255,0.08);
  --border-g: rgba(0,168,120,0.3);
  --text:   #e4ede9;
  --muted:  rgba(228,237,233,0.55);
  --radius: 14px;
  --radius-sm: 9px;
  font-family: 'Inter', system-ui, sans-serif;
}

/* ── Layout shell ── */
#mp-wrap {
  max-width: 1240px;
  margin: 0 auto;
  padding: 1.5rem 1rem 7rem;
}

/* ── Commission hero banner ── */
#mp-hero {
  background: linear-gradient(135deg, #0a2318 0%, #0f2e20 50%, #061a10 100%);
  border: 1px solid var(--border-g);
  border-radius: var(--radius);
  padding: 1.5rem 2rem;
  margin-bottom: 2rem;
  position: relative;
  overflow: hidden;
}
#mp-hero::before {
  content: '';
  position: absolute;
  top: -40px; right: -40px;
  width: 180px; height: 180px;
  background: radial-gradient(circle, rgba(0,168,120,0.18) 0%, transparent 70%);
  pointer-events: none;
}
.mp-hero-stat {
  text-align: center;
  padding: 0.5rem 1.5rem;
  border-right: 1px solid var(--border-g);
}
.mp-hero-stat:last-child { border-right: none; }
.mp-hero-stat .value {
  font-size: 1.75rem;
  font-weight: 700;
  color: var(--g);
  line-height: 1;
}
.mp-hero-stat .label {
  font-size: 0.72rem;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: .06em;
  margin-top: .25rem;
}

/* ── Business pill ── */
#mp-biz-pill {
  display: inline-flex;
  align-items: center;
  gap: .5rem;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 50px;
  padding: .35rem .9rem .35rem .5rem;
  font-size: .82rem;
  color: var(--text);
  margin-bottom: 1.25rem;
}
#mp-biz-pill .dot {
  width: 8px; height: 8px;
  background: var(--g);
  border-radius: 50%;
  flex-shrink: 0;
}

/* ── Category tabs ── */
.mp-tabs {
  display: flex;
  gap: .5rem;
  overflow-x: auto;
  scrollbar-width: none;
  margin-bottom: 1.5rem;
  padding-bottom: 2px;
}
.mp-tabs::-webkit-scrollbar { display: none; }
.mp-tab {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  gap: .4rem;
  padding: .5rem 1.1rem;
  border-radius: 50px;
  border: 1px solid var(--border);
  background: var(--surface);
  color: var(--muted);
  font-size: .82rem;
  font-weight: 500;
  cursor: pointer;
  transition: all .15s;
  white-space: nowrap;
}
.mp-tab:hover { border-color: var(--border-g); color: var(--text); }
.mp-tab.active {
  background: var(--g);
  border-color: var(--g);
  color: #fff;
  font-weight: 600;
}
.mp-tab .icon { font-size: 1rem; }
.mp-tab .count {
  background: rgba(255,255,255,0.2);
  border-radius: 50px;
  padding: .05rem .4rem;
  font-size: .68rem;
}
.mp-tab.active .count { background: rgba(0,0,0,0.2); }

/* ── Product card grid ── */
.mp-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 1rem;
}

/* ── Product card ── */
.mp-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 1.25rem;
  display: flex;
  flex-direction: column;
  gap: .75rem;
  transition: border-color .2s, transform .15s, box-shadow .15s;
  position: relative;
  overflow: hidden;
}
.mp-card::after {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 3px;
  background: var(--tier-color, transparent);
  border-radius: var(--radius) var(--radius) 0 0;
}
.mp-card:hover {
  border-color: var(--border-g);
  transform: translateY(-2px);
  box-shadow: 0 8px 24px rgba(0,0,0,.35);
}
.mp-card.in-cart {
  border-color: var(--g);
  background: linear-gradient(135deg, #0d2419 0%, #112e1f 100%);
}
.mp-card.in-cart::after { background: var(--g); }

/* Tier colours */
.mp-card[data-prod-sort="0"] { --tier-color: #00A878; }
.mp-card[data-prod-sort="1"] { --tier-color: #f59e0b; }
.mp-card[data-prod-sort="2"] { --tier-color: #3b82f6; }
.mp-card[data-prod-sort="3"] { --tier-color: #8b5cf6; }

.mp-tier-badge {
  display: inline-flex;
  align-items: center;
  gap: .25rem;
  font-size: .68rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .07em;
  padding: .2rem .6rem;
  border-radius: 50px;
  border: 1px solid;
}
.mp-tier-0 { color: #00A878; border-color: rgba(0,168,120,.35); background: rgba(0,168,120,.12); }
.mp-tier-1 { color: #f59e0b; border-color: rgba(245,158,11,.35); background: rgba(245,158,11,.1); }
.mp-tier-2 { color: #60a5fa; border-color: rgba(96,165,250,.35); background: rgba(96,165,250,.1); }
.mp-tier-3 { color: #a78bfa; border-color: rgba(167,139,250,.35); background: rgba(167,139,250,.1); }

.mp-card-name {
  font-size: .95rem;
  font-weight: 600;
  color: var(--text);
  line-height: 1.3;
}
.mp-card-desc {
  font-size: .78rem;
  color: var(--muted);
  line-height: 1.5;
  flex-grow: 1;
}
.mp-price-block { margin-top: auto; }
.mp-price-main {
  font-size: 1.5rem;
  font-weight: 700;
  color: var(--g);
  line-height: 1;
}
.mp-price-sub { font-size: .75rem; color: var(--muted); margin-top: .2rem; }

/* Commission chip on card */
.mp-comm-chip {
  display: inline-flex;
  align-items: center;
  gap: .3rem;
  background: rgba(0,168,120,.15);
  border: 1px solid rgba(0,168,120,.3);
  color: var(--g);
  border-radius: 50px;
  padding: .22rem .65rem;
  font-size: .72rem;
  font-weight: 600;
}

/* Add to order button */
.mp-btn-add {
  width: 100%;
  padding: .6rem;
  border-radius: var(--radius-sm);
  border: 1.5px solid var(--g);
  background: transparent;
  color: var(--g);
  font-size: .82rem;
  font-weight: 600;
  cursor: pointer;
  transition: all .15s;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: .4rem;
}
.mp-btn-add:hover { background: var(--g); color: #fff; }
.mp-btn-add.added { background: var(--g); color: #fff; }
.mp-btn-add.added:hover { background: #c0392b; border-color: #c0392b; }

/* In-cart tick */
.mp-in-cart-tick {
  position: absolute;
  top: .75rem; right: .75rem;
  width: 22px; height: 22px;
  background: var(--g);
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: .7rem;
  color: #fff;
  opacity: 0;
  transform: scale(.5);
  transition: all .2s;
}
.mp-card.in-cart .mp-in-cart-tick { opacity: 1; transform: scale(1); }

/* Upsell banner */
.mp-upsell {
  background: linear-gradient(135deg, rgba(245,158,11,.12), rgba(245,158,11,.06));
  border: 1px dashed rgba(245,158,11,.4);
  border-radius: var(--radius-sm);
  padding: .65rem .9rem;
  font-size: .78rem;
  color: #fcd34d;
  display: none;
  gap: .5rem;
  align-items: flex-start;
  margin-top: .25rem;
}
.mp-upsell.show { display: flex; }
.mp-upsell-btn {
  flex-shrink: 0;
  background: rgba(245,158,11,.2);
  border: 1px solid rgba(245,158,11,.4);
  color: #fcd34d;
  border-radius: 6px;
  padding: .2rem .6rem;
  font-size: .72rem;
  font-weight: 600;
  cursor: pointer;
  transition: background .15s;
  white-space: nowrap;
}
.mp-upsell-btn:hover { background: rgba(245,158,11,.35); }

/* ── CART PANEL (desktop sidebar) ── */
#mp-cart {
  position: sticky;
  top: 72px;
  max-height: calc(100vh - 90px);
  overflow-y: auto;
  background: var(--surface);
  border: 1px solid var(--border-g);
  border-radius: var(--radius);
  padding: 1.25rem;
  color: var(--text);
  scrollbar-width: thin;
  scrollbar-color: var(--border) transparent;
}
.mp-cart-title {
  font-size: .82rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .07em;
  color: var(--muted);
  margin-bottom: 1rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.mp-cart-badge {
  background: var(--g);
  color: #fff;
  border-radius: 50px;
  padding: .05rem .5rem;
  font-size: .72rem;
  font-weight: 700;
}
.mp-cart-empty {
  text-align: center;
  padding: 2rem 1rem;
  color: var(--muted);
  font-size: .82rem;
}
.mp-cart-empty i { font-size: 2rem; display: block; margin-bottom: .5rem; opacity: .4; }
.mp-cart-item {
  display: flex;
  align-items: flex-start;
  gap: .6rem;
  padding: .6rem 0;
  border-bottom: 1px solid var(--border);
  font-size: .82rem;
}
.mp-cart-item:last-child { border-bottom: none; }
.mp-cart-item-name {
  flex: 1;
  min-width: 0;
  font-weight: 500;
  color: var(--text);
}
.mp-cart-item-cat { font-size: .68rem; color: var(--muted); }
.mp-cart-item-price {
  text-align: right;
  flex-shrink: 0;
}
.mp-cart-item-price .amount { color: var(--text); font-weight: 600; font-size: .82rem; }
.mp-cart-item-price .comm { font-size: .72rem; color: var(--g); font-weight: 500; }
.mp-cart-remove {
  background: none; border: none; color: var(--muted);
  cursor: pointer; font-size: .8rem; padding: 0; line-height: 1;
  transition: color .15s;
}
.mp-cart-remove:hover { color: #ef4444; }

/* Cart totals */
.mp-cart-total-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: .4rem 0;
  font-size: .82rem;
  color: var(--muted);
}
.mp-cart-total-row.main {
  font-size: 1rem;
  font-weight: 700;
  color: var(--text);
  border-top: 1px solid var(--border);
  padding-top: .75rem;
  margin-top: .25rem;
}
.mp-cart-total-row .val { color: var(--g); }
.mp-cart-commission-total {
  background: var(--g-dim);
  border: 1px solid var(--border-g);
  border-radius: var(--radius-sm);
  padding: .6rem .9rem;
  margin-top: .75rem;
  display: none;
}
.mp-cart-commission-total .label { font-size: .75rem; font-weight: 600; color: var(--g); text-transform: uppercase; letter-spacing: .04em; }
.mp-cart-commission-total .amount { font-size: 1.15rem; font-weight: 700; color: var(--g); }

/* Checkout button */
.mp-checkout-btn {
  width: 100%;
  background: var(--g);
  color: #fff;
  border: none;
  border-radius: var(--radius-sm);
  padding: .75rem;
  font-size: .9rem;
  font-weight: 700;
  cursor: pointer;
  transition: opacity .15s;
  margin-top: 1rem;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: .4rem;
}
.mp-checkout-btn:hover { opacity: .88; }
.mp-checkout-btn:disabled { opacity: .4; cursor: not-allowed; }

/* ── MOBILE BOTTOM BAR ── */
#mp-mobile-bar {
  position: fixed;
  bottom: 0; left: 0; right: 0;
  background: #0d2419;
  border-top: 1px solid var(--border-g);
  padding: .75rem 1rem;
  display: none;
  align-items: center;
  justify-content: space-between;
  gap: .75rem;
  z-index: 1040;
  box-shadow: 0 -4px 20px rgba(0,0,0,.4);
}
@media (max-width: 991px) { #mp-mobile-bar { display: flex; } }
#mp-mobile-bar .summary { flex: 1; }
#mp-mobile-bar .total-label { font-size: .68rem; color: var(--muted); }
#mp-mobile-bar .total-amount { font-size: 1.1rem; font-weight: 700; color: var(--g); line-height: 1; }
#mp-mobile-bar .btn-cart-open {
  background: var(--g);
  color: #fff;
  border: none;
  border-radius: var(--radius-sm);
  padding: .6rem 1.2rem;
  font-size: .85rem;
  font-weight: 700;
  cursor: pointer;
  display: flex; align-items: center; gap: .4rem;
  flex-shrink: 0;
}

/* ── MOBILE CART DRAWER ── */
#mp-drawer-backdrop {
  display: none;
  position: fixed; inset: 0;
  background: rgba(0,0,0,.55);
  z-index: 1045;
}
#mp-drawer-backdrop.open { display: block; }
#mp-drawer {
  position: fixed;
  bottom: 0; left: 0; right: 0;
  background: #0d2419;
  border-top: 2px solid var(--border-g);
  border-radius: 20px 20px 0 0;
  padding: 1.25rem 1.25rem 2.5rem;
  z-index: 1050;
  max-height: 85vh;
  overflow-y: auto;
  transform: translateY(100%);
  transition: transform .3s cubic-bezier(.32,0,.67,0);
}
#mp-drawer.open { transform: translateY(0); }
.mp-drawer-handle {
  width: 36px; height: 4px;
  background: var(--border);
  border-radius: 50px;
  margin: 0 auto .75rem;
}

/* Partner note field */
.mp-note-field {
  width: 100%;
  background: var(--surface2);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  color: var(--text);
  padding: .6rem .75rem;
  font-size: .82rem;
  resize: none;
  margin-top: .75rem;
}
.mp-note-field:focus { outline: none; border-color: var(--border-g); }
.mp-note-field::placeholder { color: var(--muted); }

/* Approval notice */
.mp-approval-notice {
  background: rgba(245,158,11,.1);
  border: 1px solid rgba(245,158,11,.25);
  border-radius: var(--radius-sm);
  padding: .6rem .8rem;
  font-size: .75rem;
  color: #fcd34d;
  margin-top: .75rem;
  display: flex;
  gap: .4rem;
  align-items: flex-start;
}

/* ── Success screen ── */
.mp-success { text-align: center; padding: 4rem 1rem; }
.mp-success .icon { font-size: 3.5rem; }
.mp-success h2 { color: var(--text); font-weight: 700; margin-top: 1rem; }
.mp-success p { color: var(--muted); }
.mp-ref-pill {
  display: inline-block;
  background: var(--surface);
  border: 1px solid var(--border-g);
  border-radius: 50px;
  padding: .3rem 1rem;
  font-size: .82rem;
  color: var(--g);
  font-weight: 600;
  margin: .2rem;
}

/* ── Step 1: Business list ── */
.mp-biz-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 1rem 1.25rem;
  text-decoration: none;
  color: var(--text);
  transition: border-color .15s, background .15s;
  margin-bottom: .6rem;
}
.mp-biz-card:hover {
  border-color: var(--border-g);
  background: var(--surface2);
  color: var(--text);
}
.mp-biz-card .arrow { color: var(--g); font-size: 1.1rem; }

/* ── Responsive ── */
@media (max-width: 575px) {
  .mp-grid { grid-template-columns: 1fr; }
  #mp-hero { padding: 1rem; }
  .mp-hero-stat .value { font-size: 1.3rem; }
  #mp-wrap { padding: 1rem .75rem 7rem; }
}
@media (min-width: 576px) and (max-width: 767px) {
  .mp-grid { grid-template-columns: repeat(2, 1fr); }
}
</style>

<div id="mp-wrap">

<?php if ($orderSubmitted): ?>
<!-- ── ORDER SUCCESS ── -->
<div class="mp-success">
  <div class="icon">✅</div>
  <h2><?= count($submittedRefs) > 1
      ? mpT('Orders Submitted!', 'Commandes soumises !')
      : mpT('Order Submitted!', 'Commande soumise !') ?></h2>
  <p><?= mpT(
      'The business owner has been notified and must approve before payment is collected.',
      'Le propriétaire de l\'entreprise a été notifié et doit approuver avant que le paiement soit collecté.'
  ) ?></p>
  <div style="margin:1.25rem 0;">
    <?php foreach ($submittedRefs as $r): ?>
    <span class="mp-ref-pill"><?= e($r) ?></span>
    <?php endforeach; ?>
  </div>
  <div style="display:flex;gap:.75rem;justify-content:center;flex-wrap:wrap;margin-top:1rem;">
    <?php foreach ($submittedRefs as $r): ?>
    <a href="<?= SITE_URL ?>/partner/marketplace-order?ref=<?= e($r) ?>"
       style="background:var(--g);color:#fff;padding:.6rem 1.2rem;border-radius:9px;text-decoration:none;font-weight:600;font-size:.85rem;">
      <?= mpT('Track', 'Suivre') ?> <?= e($r) ?> →
    </a>
    <?php endforeach; ?>
    <a href="<?= SITE_URL ?>/partner/marketplace?listing_id=<?= $listingId ?>"
       style="border:1px solid var(--border-g);color:var(--g);padding:.6rem 1.2rem;border-radius:9px;text-decoration:none;font-weight:600;font-size:.85rem;">
      <?= mpT('Place Another Order', 'Passer une autre commande') ?>
    </a>
  </div>
</div>

<?php elseif ($selectedBusiness): ?>
<!-- ── STEP 2: Commission Hero + Catalogue + Cart ── -->

<?php if ($errors): ?>
<div style="background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);border-radius:9px;padding:.75rem 1rem;margin-bottom:1rem;font-size:.82rem;color:#fca5a5;">
  <?php foreach ($errors as $err): ?><div>⚠ <?= e($err) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<?php
/* ─── Compute commission stats for hero ─── */
$heroCommMin = null; $heroCommMax = null; $heroCommPct = null;
foreach ($catalogue as $cat) {
  foreach ($cat['products'] as $prod) {
    if ($prod['commission_rate'] > 0) {
      if ($heroCommPct === null) $heroCommPct = $prod['commission_rate'];
      foreach ($prod['prices'] as $pr) {
        $dn = $pr['price_type'] === 'setup' ? $pr['setup_amount'] : $pr['recurring_amount'];
        $cm = (int)round($dn * $prod['commission_rate'] / 100);
        if ($heroCommMin === null || $cm < $heroCommMin) $heroCommMin = $cm;
        if ($heroCommMax === null || $cm > $heroCommMax) $heroCommMax = $cm;
      }
    }
  }
}
?>

<!-- Commission hero -->
<div id="mp-hero">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
    <div>
      <div style="font-size:.7rem;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:.3rem;">
        <?= mpT('Your Earning Opportunity', 'Votre opportunité de revenus') ?>
      </div>
      <div style="font-size:1.15rem;font-weight:700;color:var(--text);">
        💰 <?= mpT('Earn commission on every service you sell', 'Gagnez une commission sur chaque service vendu') ?>
      </div>
      <?php if ($heroCommPct): ?>
      <div style="font-size:.82rem;color:var(--muted);margin-top:.3rem;">
        <?= mpT('Commission is paid to you once the order is approved by the business owner.', 'La commission vous est versée une fois la commande approuvée par le propriétaire.') ?>
      </div>
      <?php endif; ?>
    </div>
    <div style="display:flex;align-items:stretch;gap:0;">
      <?php if ($heroCommPct): ?>
      <div class="mp-hero-stat">
        <div class="value"><?= $heroCommPct ?>%</div>
        <div class="label"><?= mpT('Commission rate', 'Taux de commission') ?></div>
      </div>
      <?php endif; ?>
      <div class="mp-hero-stat" id="hero-order-comm-wrap" style="display:none;">
        <div class="value" id="hero-order-comm">—</div>
        <div class="label">FCFA <?= mpT('on current order', 'sur la commande') ?></div>
      </div>
    </div>
  </div>
</div>

<!-- Business pill + change link -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;margin-bottom:1.25rem;">
  <div id="mp-biz-pill">
    <span class="dot"></span>
    <strong><?= e($selectedBusiness['title']) ?></strong>
    <span style="color:var(--muted);">· <?= e($selectedBusiness['city']) ?></span>
  </div>
  <a href="<?= SITE_URL ?>/partner/marketplace"
     style="font-size:.78rem;color:var(--muted);text-decoration:none;"><?= mpT('← Change business', '← Changer d\'entreprise') ?></a>
</div>

<?php if (empty($catalogue)): ?>
<div style="text-align:center;padding:4rem 1rem;background:var(--surface);border-radius:var(--radius);border:1px solid var(--border);">
  <div style="font-size:3rem;">🏗️</div>
  <h4 style="color:var(--text);margin:.75rem 0 .5rem;"><?= mpT('Marketplace coming soon', 'Marketplace bientôt disponible') ?></h4>
  <p style="color:var(--muted);font-size:.85rem;"><?= mpT(
      'The admin is currently configuring the service catalogue. Please check back shortly.',
      'L\'administrateur configure actuellement le catalogue de services. Veuillez revenir bientôt.'
  ) ?></p>
</div>
<?php else: ?>

<!-- Two-column layout: catalogue + cart -->
<div style="display:grid;grid-template-columns:1fr 320px;gap:1.5rem;align-items:start;" id="mp-layout">

  <!-- ── LEFT: Catalogue ── -->
  <div>
    <!-- Category tabs -->
    <div class="mp-tabs" id="mp-tabs">
      <?php $firstCat = true; foreach ($catalogue as $cat):
        $catProdCount = count($cat['products']); ?>
      <button class="mp-tab <?= $firstCat ? 'active' : '' ?>"
              data-cat="<?= e($cat['key']) ?>"
              onclick="mpSwitchTab('<?= e($cat['key']) ?>')">
        <span class="icon"><?= e($cat['icon']) ?></span>
        <?= e($cat['name']) ?>
        <span class="count"><?= $catProdCount ?></span>
      </button>
      <?php $firstCat = false; endforeach; ?>
    </div>

    <!-- Category panels -->
    <?php $firstCat = true; foreach ($catalogue as $cat):
      $products = array_values($cat['products']);
      usort($products, fn($a, $b) => $a['sort_order'] <=> $b['sort_order']);
      $prodCount = count($products);
    ?>
    <div class="mp-cat-panel" id="panel-<?= e($cat['key']) ?>" style="display:<?= $firstCat ? 'block' : 'none' ?>;">
      <div class="mp-grid">
        <?php foreach ($products as $prodIdx => $prod):
          $mainPrice = null; $renewalPrice = null;
          foreach ($prod['prices'] as $pr) {
            if ($pr['price_type'] === 'setup' && !$mainPrice) $mainPrice = $pr;
            if ($pr['price_type'] === 'recurring' && !$renewalPrice) $renewalPrice = $pr;
          }
          if (!$mainPrice) $mainPrice = $renewalPrice;
          if (!$mainPrice) continue;

          // For monthly recurring (Business Email), bill at 12-month total
          $isAnnualised = ($mainPrice['price_type'] === 'recurring' && ($mainPrice['billing_interval'] ?? '') === 'MONTH');
          $dueNow   = $mainPrice['price_type'] === 'setup'
            ? $mainPrice['setup_amount']
            : ($isAnnualised ? $mainPrice['recurring_amount'] * 12 : $mainPrice['recurring_amount']);
          $commRate = $prod['commission_rate'];
          $commAmt  = $commRate > 0 ? (int)round($dueNow * $commRate / 100) : 0;
          $tierLabels = ['Starter','Professional','Premium','Enterprise'];
          $tierLabelsFr = ['Démarrage','Professionnel','Premium','Entreprise'];
          $tierLabel = $tierLabels[$prodIdx] ?? 'Enterprise';
          $tierLabelFr = $tierLabelsFr[$prodIdx] ?? 'Entreprise';
        ?>
        <div class="mp-card"
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
             data-annualised="<?= $isAnnualised ? '1' : '0' ?>"
             data-prod-sort="<?= $prodIdx ?>"
             data-prod-count="<?= $prodCount ?>">

          <!-- In-cart tick -->
          <div class="mp-in-cart-tick"><i class="bi bi-check"></i></div>

          <!-- Tier badge -->
          <div class="mp-tier-badge mp-tier-<?= $prodIdx <= 3 ? $prodIdx : 3 ?>">
            <?= e(mpT($tierLabel, $tierLabelFr)) ?>
          </div>

          <!-- Name -->
          <div class="mp-card-name"><?= e($prod['name']) ?></div>

          <!-- Description -->
          <?php if ($prod['description']): ?>
          <div class="mp-card-desc"><?= e($prod['description']) ?></div>
          <?php endif; ?>

          <!-- Price + commission -->
          <div class="mp-price-block">
            <div class="mp-price-main"><?= mpFmt($dueNow) ?></div>
            <?php if ($mainPrice['price_type'] === 'setup'): ?>
            <div class="mp-price-sub"><?= mpT('one-time setup', 'mise en place unique') ?>
              <?php if ($renewalPrice && $renewalPrice['recurring_amount'] > 0): ?>
              · +<?= mpFmt($renewalPrice['recurring_amount']) ?> <?= mpInterval($renewalPrice['billing_interval']) ?>
              <?php endif; ?>
            </div>
            <?php elseif ($isAnnualised): ?>
            <div class="mp-price-sub">
              <?= mpFmt($mainPrice['recurring_amount']) ?> <?= mpT('/ month', '/ mois') ?> · <?= mpT('billed as 12 months', 'facturé 12 mois') ?>
              <?php if ($mainPrice['mailbox_limit']): ?>
              · <?= mpT('up to', 'jusqu\'à') ?> <?= (int)$mainPrice['mailbox_limit'] ?> <?= mpT('mailboxes', 'boîtes mail') ?>
              <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="mp-price-sub"><?= mpInterval($mainPrice['billing_interval']) ?>
              <?php if ($mainPrice['mailbox_limit']): ?>
              · <?= mpT('up to', 'jusqu\'à') ?> <?= (int)$mainPrice['mailbox_limit'] ?> <?= mpT('mailboxes', 'boîtes mail') ?>
              <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($commRate > 0): ?>
            <div style="margin-top:.5rem;">
              <span class="mp-comm-chip">
                <i class="bi bi-currency-exchange"></i>
                +<?= mpFmt($commAmt) ?> <?= mpT('commission', 'commission') ?>
                <span style="opacity:.65;">(<?= $commRate ?>%)</span>
              </span>
            </div>
            <?php endif; ?>
          </div>

          <!-- Add to order button -->
          <button type="button" class="mp-btn-add" data-product-id="<?= $prod['id'] ?>"
                  onclick="mpToggleCart(<?= $prod['id'] ?>)">
            <i class="bi bi-cart-plus"></i>
            <span><?= mpT('Add to Order', 'Ajouter à la commande') ?></span>
          </button>

          <!-- Upsell hint -->
          <div class="mp-upsell" id="upsell-<?= $prod['id'] ?>">
            <i class="bi bi-arrow-up-circle-fill" style="flex-shrink:0;margin-top:.1rem;"></i>
            <div style="flex:1;">
              <span id="upsell-text-<?= $prod['id'] ?>"></span>
              <button class="mp-upsell-btn" onclick="mpUpsellSwitch(<?= $prod['id'] ?>)">
                <?= mpT('Upgrade', 'Mettre à niveau') ?> →
              </button>
            </div>
          </div>

        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php $firstCat = false; endforeach; ?>
  </div><!-- /catalogue -->

  <!-- ── RIGHT: Cart sidebar ── -->
  <div id="mp-cart">
    <div class="mp-cart-title">
      <?= mpT('Your Order', 'Votre commande') ?>
      <span class="mp-cart-badge" id="cart-count-badge">0</span>
    </div>

    <div id="mp-cart-items">
      <div class="mp-cart-empty" id="mp-cart-empty">
        <i class="bi bi-bag"></i>
        <?= mpT('No services selected.<br>Browse the catalogue.', 'Aucun service sélectionné.<br>Parcourez le catalogue.') ?>
      </div>
    </div>

    <div class="mp-cart-commission-total" id="mp-cart-comm-block">
      <div class="label">💰 <?= mpT('Commission', 'Commission') ?></div>
      <div class="amount" id="cart-commission-amount">—</div>
    </div>

    <div id="mp-cart-totals" style="display:none;">
      <div class="mp-cart-total-row">
        <span><?= mpT('Subtotal', 'Sous-total') ?></span>
        <span class="val" id="cart-total-amount"></span>
      </div>
      <div class="mp-approval-notice">
        <i class="bi bi-info-circle-fill" style="flex-shrink:0;"></i>
        <?= mpT('Owner approval required before payment is collected.', 'Approbation du propriétaire requise avant collecte du paiement.') ?>
      </div>

      <?php if ($mtnNum || $orangeNum): ?>
      <div id="mp-payment-info" style="margin-top:.75rem;padding:.6rem .8rem;background:var(--surface2);border-radius:var(--radius-sm);font-size:.75rem;color:var(--muted);">
        <?php if ($payInstruction): ?><div style="margin-bottom:.35rem;"><?= nl2br(e($payInstruction)) ?></div><?php endif; ?>
        <?php if ($mtnNum): ?><div>📱 MTN MoMo: <strong style="color:var(--text);"><?= e($mtnNum) ?></strong></div><?php endif; ?>
        <?php if ($orangeNum): ?><div>🍊 Orange Money: <strong style="color:var(--text);"><?= e($orangeNum) ?></strong></div><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <textarea class="mp-note-field" id="mp-partner-note" rows="2"
              placeholder="<?= mpT('Note to admin (optional)…', 'Note à l\'admin (facultatif)…') ?>"
              style="display:none;"></textarea>

    <button class="mp-checkout-btn" id="mp-checkout-btn" disabled onclick="mpCheckout()">
      <i class="bi bi-check2-circle"></i>
      <?= mpT('Submit Order Request', 'Soumettre la commande') ?>
    </button>
  </div><!-- /cart -->

</div><!-- /mp-layout -->

<!-- Mobile bottom bar -->
<div id="mp-mobile-bar">
  <div class="summary">
    <div class="total-label"><?= mpT('Order total', 'Total commande') ?></div>
    <div class="total-amount" id="mobile-total">—</div>
  </div>
  <button class="btn-cart-open" onclick="mpOpenDrawer()">
    <i class="bi bi-bag-check"></i>
    <span><?= mpT('Review Order', 'Voir la commande') ?></span>
    <span style="background:rgba(255,255,255,.25);border-radius:50px;padding:.05rem .45rem;font-size:.7rem;" id="mobile-badge">0</span>
  </button>
</div>

<!-- Mobile drawer backdrop -->
<div id="mp-drawer-backdrop" onclick="mpCloseDrawer()"></div>

<!-- Mobile cart drawer -->
<div id="mp-drawer">
  <div class="mp-drawer-handle"></div>
  <div class="mp-cart-title">
    <?= mpT('Your Order', 'Votre commande') ?>
    <span class="mp-cart-badge" id="drawer-count-badge">0</span>
  </div>
  <div id="mp-drawer-items"></div>
  <div class="mp-cart-commission-total" id="mp-drawer-comm-block">
    <div class="label">💰 <?= mpT('Commission', 'Commission') ?></div>
    <div class="amount" id="drawer-commission-amount">—</div>
  </div>
  <div id="mp-drawer-totals" style="display:none;">
    <div class="mp-cart-total-row main">
      <span><?= mpT('Total due now', 'Total à payer') ?></span>
      <span class="val" id="drawer-total-amount"></span>
    </div>
    <div class="mp-approval-notice">
      <i class="bi bi-info-circle-fill" style="flex-shrink:0;"></i>
      <?= mpT('Owner must approve before payment.', 'Le propriétaire doit approuver avant paiement.') ?>
    </div>
  </div>
  <textarea class="mp-note-field" id="mp-drawer-note" rows="2"
            placeholder="<?= mpT('Note to admin (optional)…', 'Note à l\'admin (facultatif)…') ?>"
            style="display:none;"></textarea>
  <button class="mp-checkout-btn" id="mp-drawer-checkout-btn" disabled onclick="mpCheckout()">
    <i class="bi bi-check2-circle"></i>
    <?= mpT('Submit Order Request', 'Soumettre la commande') ?>
  </button>
</div>

<!-- Hidden checkout form -->
<form id="mp-checkout-form" method="POST" action="<?= SITE_URL ?>/partner/marketplace?listing_id=<?= $listingId ?>" style="display:none;">
  <?= csrfField() ?>
  <input type="hidden" name="listing_id"  value="<?= $listingId ?>">
  <input type="hidden" name="submit_order" value="1">
  <input type="hidden" name="partner_note" id="form-partner-note">
  <input type="hidden" name="cart_json"    id="form-cart-json">
</form>

<?php endif; // catalogue not empty ?>

<?php else: ?>
<!-- ── STEP 1: Select Business ── -->
<div style="margin-bottom:2rem;">
  <div style="font-size:.7rem;text-transform:uppercase;letter-spacing:.09em;color:var(--muted);margin-bottom:.5rem;">
    <?= mpT('Partner Portal', 'Portail Partenaire') ?>
  </div>
  <h1 style="font-size:1.6rem;font-weight:700;color:var(--text);margin:0 0 .4rem;">
    <?= mpT('Services Marketplace', 'Marketplace de Services') ?>
  </h1>
  <p style="color:var(--muted);font-size:.88rem;margin:0;">
    <?= mpT('Request professional services for businesses in your portfolio — and earn commission on every sale.', 'Commandez des services pour les entreprises de votre portefeuille et gagnez une commission sur chaque vente.') ?>
  </p>
</div>

<?php if (!$businessList): ?>
<div style="text-align:center;padding:3rem 1rem;background:var(--surface);border-radius:var(--radius);border:1px solid var(--border);">
  <div style="font-size:3rem;">📭</div>
  <h4 style="color:var(--text);margin:.75rem 0 .5rem;"><?= mpT('No businesses in your portfolio', 'Aucune entreprise dans votre portefeuille') ?></h4>
  <p style="color:var(--muted);font-size:.82rem;"><?= mpT(
      'You need an actively assigned business to place a service order.',
      'Vous avez besoin d\'une entreprise assignée active pour passer une commande.'
  ) ?></p>
</div>
<?php else: ?>
<div style="font-size:.8rem;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin-bottom:.75rem;">
  <?= mpT('Select a Business', 'Sélectionner une entreprise') ?>
</div>
<?php foreach ($businessList as $biz): ?>
<a href="<?= SITE_URL ?>/partner/marketplace?listing_id=<?= $biz['id'] ?>" class="mp-biz-card">
  <div>
    <div style="font-weight:600;font-size:.9rem;"><?= e($biz['title']) ?></div>
    <div style="font-size:.75rem;color:var(--muted);margin-top:.2rem;"><?= e($biz['cat_en']) ?> · <?= e($biz['city']) ?></div>
  </div>
  <i class="bi bi-arrow-right arrow"></i>
</a>
<?php endforeach; ?>
<?php endif; ?>
<?php endif; ?>

</div><!-- /#mp-wrap -->

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>

<?php if ($selectedBusiness && !$orderSubmitted && !empty($catalogue)): ?>
<script>
/* ═══════════════════════════════════════════════════
   Marketplace Cart Engine — v2 (clean redesign)
   ═══════════════════════════════════════════════════ */
const LANG      = <?= json_encode($lang) ?>;
const CATALOGUE = <?= $catalogueJson ?>;
const FMT       = n => new Intl.NumberFormat('fr-FR').format(n) + ' FCFA';
let cart = {};

/* ── Category tabs ── */
function mpSwitchTab(catKey) {
  document.querySelectorAll('.mp-tab').forEach(t =>
    t.classList.toggle('active', t.dataset.cat === catKey));
  document.querySelectorAll('.mp-cat-panel').forEach(p =>
    p.style.display = p.id === 'panel-' + catKey ? 'block' : 'none');
}

/* ── Cart logic ── */
function mpToggleCart(productId) {
  cart[productId] ? mpRemoveFromCart(productId) : mpAddToCart(productId);
}

function mpAddToCart(productId) {
  const card = document.getElementById('card-' + productId);
  if (!card) return;
  const d = card.dataset;
  cart[productId] = {
    product_id:       parseInt(d.productId),
    price_id:         parseInt(d.priceId),
    product_name:     d.productName,
    cat_key:          d.catKey,
    cat_name:         d.catName,
    due_now:          parseInt(d.dueNow),
    price_type:       d.priceType,
    setup_amount:     parseInt(d.setupAmount),
    recurring_amount: parseInt(d.recurringAmount),
    billing_interval: d.billingInterval,
    mailbox_limit:    parseInt(d.mailboxLimit) || 0,
    comm_rate:        parseFloat(d.commRate) || 0,
    comm_amt:         parseInt(d.commAmt) || 0,
    annualised:       d.annualised === '1',
    prod_sort:        parseInt(d.prodSort),
    prod_count:       parseInt(d.prodCount),
  };
  mpRender();
  mpCheckCategoryUpsells();
}

function mpRemoveFromCart(productId) {
  delete cart[productId];
  mpRender();
  mpCheckCategoryUpsells();
}

/* ── Render ── */
function mpRender() {
  const items     = Object.values(cart);
  const count     = items.length;
  const totalDue  = items.reduce((s, i) => s + i.due_now, 0);
  const totalComm = items.reduce((s, i) => s + i.comm_amt, 0);

  /* Card states */
  document.querySelectorAll('.mp-card[data-product-id]').forEach(card => {
    const pid = parseInt(card.dataset.productId);
    const inCart = !!cart[pid];
    card.classList.toggle('in-cart', inCart);
    const btn = card.querySelector('.mp-btn-add');
    const icon = btn ? btn.querySelector('i') : null;
    const lbl  = btn ? btn.querySelector('span') : null;
    if (btn) {
      btn.classList.toggle('added', inCart);
      if (icon) icon.className = inCart ? 'bi bi-cart-dash' : 'bi bi-cart-plus';
      if (lbl)  lbl.textContent = inCart
        ? (LANG === 'fr' ? 'Retirer' : 'Remove')
        : (LANG === 'fr' ? 'Ajouter à la commande' : 'Add to Order');
    }
  });

  /* Build cart HTML */
  const renderCartHTML = () => {
    if (!count) return `<div class="mp-cart-empty">
      <i class="bi bi-bag"></i>
      ${LANG === 'fr' ? 'Aucun service sélectionné.' : 'No services selected.'}
    </div>`;
    return items.map(item => {
      let priceLabel;
      if (item.price_type === 'setup') {
        priceLabel = FMT(item.setup_amount) + (LANG==='fr'?' (mise en place)':' (setup)');
      } else if (item.annualised) {
        priceLabel = FMT(item.due_now) + (LANG==='fr'?' (12 mois)':' (12 months)');
      } else {
        const itvl = item.billing_interval === 'YEAR' ? (LANG==='fr'?'/ an':'/ year') : '';
        priceLabel = FMT(item.recurring_amount) + ' ' + itvl;
      }
      return `<div class="mp-cart-item">
        <div class="mp-cart-item-name">
          ${item.product_name}
          <div class="mp-cart-item-cat">${item.cat_name}</div>
        </div>
        <div class="mp-cart-item-price">
          <div class="amount">${priceLabel}</div>
          ${item.comm_amt > 0 ? `<div class="comm">💰 +${FMT(item.comm_amt)}</div>` : ''}
        </div>
        <button class="mp-cart-remove" onclick="mpRemoveFromCart(${item.product_id})" title="Remove">
          <i class="bi bi-x"></i>
        </button>
      </div>`;
    }).join('');
  };

  /* Desktop cart */
  document.getElementById('mp-cart-items').innerHTML = renderCartHTML();
  document.getElementById('cart-count-badge').textContent = count;
  const totals = document.getElementById('mp-cart-totals');
  totals.style.display = count ? '' : 'none';
  const commBlock = document.getElementById('mp-cart-comm-block');
  commBlock.style.display = count ? '' : 'none';
  if (count) {
    document.getElementById('cart-total-amount').textContent = FMT(totalDue);
    document.getElementById('cart-commission-amount').textContent = totalComm > 0 ? FMT(totalComm) : '—';
  }
  /* Hero live stat */
  const heroWrap = document.getElementById('hero-order-comm-wrap');
  if (heroWrap) {
    heroWrap.style.display = (count && totalComm > 0) ? '' : 'none';
    if (count && totalComm > 0) document.getElementById('hero-order-comm').textContent = FMT(totalComm);
  }
  const noteEl = document.getElementById('mp-partner-note');
  noteEl.style.display = count ? '' : 'none';
  const checkBtn = document.getElementById('mp-checkout-btn');
  checkBtn.disabled = !count;

  /* Mobile bar */
  document.getElementById('mobile-total').textContent = count ? FMT(totalDue) : '—';
  document.getElementById('mobile-badge').textContent = count;

  /* Drawer */
  document.getElementById('mp-drawer-items').innerHTML = renderCartHTML();
  document.getElementById('drawer-count-badge').textContent = count;
  const dTotals = document.getElementById('mp-drawer-totals');
  dTotals.style.display = count ? '' : 'none';
  const dCommBlock = document.getElementById('mp-drawer-comm-block');
  dCommBlock.style.display = count ? '' : 'none';
  if (count) {
    document.getElementById('drawer-total-amount').textContent = FMT(totalDue);
    document.getElementById('drawer-commission-amount').textContent = totalComm > 0 ? FMT(totalComm) : '—';
  }
  document.getElementById('mp-drawer-note').style.display = count ? '' : 'none';
  document.getElementById('mp-drawer-checkout-btn').disabled = !count;
}

/* ── Upsell logic ── */
function mpCheckCategoryUpsells() {
  for (const catKey in CATALOGUE) {
    const prods = Object.values(CATALOGUE[catKey].products).sort((a, b) => a.sort_order - b.sort_order);
    let lowestInCart = null;
    for (const p of prods) {
      if (cart[p.id]) { lowestInCart = p; break; }
    }
    // Hide all upsells for this category
    prods.forEach(p => {
      const el = document.getElementById('upsell-' + p.id);
      if (el) el.classList.remove('show');
    });
    if (!lowestInCart) continue;
    const idx = prods.findIndex(p => p.id === lowestInCart.id);
    if (idx < prods.length - 1) {
      const next = prods[idx + 1];
      if (!cart[next.id]) {
        const el   = document.getElementById('upsell-' + next.id);
        const txt  = document.getElementById('upsell-text-' + next.id);
        if (el && txt) {
          // Use the DOM card's data-due-now (already annualised if needed)
          const curCard = document.getElementById('card-' + lowestInCart.id);
          const nxtCard = document.getElementById('card-' + next.id);
          const curD = curCard ? parseInt(curCard.dataset.dueNow) : 0;
          const nxtD = nxtCard ? parseInt(nxtCard.dataset.dueNow) : 0;
          const diff  = nxtD - curD;
          txt.innerHTML = LANG === 'fr'
            ? `Passez à <strong>${next.name}</strong> pour +<strong>${FMT(diff)}</strong> seulement.`
            : `Upgrade to <strong>${next.name}</strong> for just +<strong>${FMT(diff)}</strong> more.`;
          el.classList.add('show');
        }
      }
    }
  }
}

function mpUpsellSwitch(upgradedId) {
  const card = document.getElementById('card-' + upgradedId);
  if (!card) return;
  const catKey = card.dataset.catKey;
  for (const pid in cart) {
    if (cart[pid].cat_key === catKey) delete cart[pid];
  }
  mpAddToCart(upgradedId);
}

/* ── Drawer (mobile) ── */
function mpOpenDrawer() {
  document.getElementById('mp-drawer').classList.add('open');
  document.getElementById('mp-drawer-backdrop').classList.add('open');
}
function mpCloseDrawer() {
  document.getElementById('mp-drawer').classList.remove('open');
  document.getElementById('mp-drawer-backdrop').classList.remove('open');
}

/* ── Responsive cart hide on desktop ── */
function mpHandleResize() {
  const layout = document.getElementById('mp-layout');
  if (!layout) return;
  if (window.innerWidth < 992) {
    layout.style.gridTemplateColumns = '1fr';
    document.getElementById('mp-cart').style.display = 'none';
  } else {
    layout.style.gridTemplateColumns = '1fr 320px';
    document.getElementById('mp-cart').style.display = '';
    mpCloseDrawer();
  }
}
window.addEventListener('resize', mpHandleResize);
mpHandleResize();

/* ── Checkout ── */
function mpCheckout() {
  const items = Object.values(cart);
  if (!items.length) return;
  // Sync notes from whichever field is visible
  const noteDesktop = document.getElementById('mp-partner-note').value;
  const noteDrawer  = document.getElementById('mp-drawer-note').value;
  document.getElementById('form-partner-note').value = noteDesktop || noteDrawer;
  document.getElementById('form-cart-json').value = JSON.stringify(items.map(i => ({
    product_id:   i.product_id,
    price_id:     i.price_id,
    mailbox_count: i.mailbox_limit > 0 ? 1 : 0,
    fields: {}
  })));
  document.getElementById('mp-checkout-form').submit();
}

/* Init */
mpRender();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
