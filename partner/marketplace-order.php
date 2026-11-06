<?php
/**
 * partner/marketplace-order.php — View a single marketplace order
 *
 * Shows the full order timeline: approval status, payment status, fulfilment.
 * Partner can upload payment proof here when state = AWAITING_PAYMENT.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';

$partnerProfile = requireGrowthPartner();
$partnerId      = (int)$partnerProfile['id'];
$user           = currentUser();
$pdo            = db();
$lang           = $user['lang'] ?? 'en';

function mpT(string $en, string $fr): string {
    global $lang;
    return $lang === 'fr' ? $fr : $en;
}
function mpFmt(int $xaf): string {
    return number_format($xaf) . ' FCFA';
}

$ref = trim($_GET['ref'] ?? '');
if (!$ref) {
    redirect(SITE_URL . '/partner/marketplace');
}

/* ── Load order (must belong to this partner) ─────────────────── */
$stO = $pdo->prepare("
    SELECT o.*,
           l.title AS listing_title, l.user_id AS owner_id,
           u_owner.name AS owner_name, u_owner.email AS owner_email,
           oa.decision AS approval_decision, oa.decided_at AS approval_decided_at,
           oa.reviewer_note AS approval_note, oa.expires_at AS approval_expires_at
    FROM marketplace_orders o
    JOIN listings l ON l.id = o.listing_id
    JOIN users u_owner ON u_owner.id = l.user_id
    LEFT JOIN mp_order_approvals oa ON oa.order_id = o.id
    WHERE o.ref = ? AND o.partner_profile_id = ?
    LIMIT 1
");
$stO->execute([$ref, $partnerId]);
$order = $stO->fetch();

if (!$order) {
    redirect(SITE_URL . '/partner/marketplace');
}

$orderId = (int)$order['id'];

/* ── Load order items ─────────────────────────────────────────── */
$items = $pdo->prepare("
    SELECT oi.*, ps.name_en, ps.name_fr,
           COALESCE(JSON_UNQUOTE(JSON_EXTRACT(oi.product_snapshot,'$.name_en')), ps.name_en) AS display_name_en,
           COALESCE(JSON_UNQUOTE(JSON_EXTRACT(oi.product_snapshot,'$.name_fr')), ps.name_fr) AS display_name_fr
    FROM marketplace_order_items oi
    LEFT JOIN mp_service_products ps ON ps.id = oi.product_id
    WHERE oi.order_id = ?
");
$items->execute([$orderId]);
$orderItems = $items->fetchAll();

/* ── Load payment attempts ───────────────────────────────────── */
$pays = $pdo->prepare("
    SELECT * FROM mp_payment_attempts
    WHERE order_id = ?
    ORDER BY created_at DESC
");
$pays->execute([$orderId]);
$payAttempts = $pays->fetchAll();

/* ── Load fulfilment tasks ───────────────────────────────────── */
$tasks = $pdo->prepare("
    SELECT ft.*, u.name AS assignee_name
    FROM mp_fulfilment_tasks ft
    LEFT JOIN users u ON u.id = ft.assignee_id
    WHERE ft.order_id = ?
    ORDER BY ft.created_at ASC
");
$tasks->execute([$orderId]);
$fulfilTasks = $tasks->fetchAll();

/* ── State helpers ───────────────────────────────────────────── */
$orderState   = $order['order_state'];
$payState     = $order['payment_state'];
$canPay       = $orderState === 'AWAITING_PAYMENT';
$isComplete   = in_array($orderState, ['COMPLETED', 'CANCELLED']);

$stateLabels = [
    'PENDING_OWNER_APPROVAL' => ['🕐', mpT('Pending Owner Approval', 'En attente d\'approbation'), 'warning'],
    'AWAITING_PAYMENT'       => ['💳', mpT('Awaiting Payment', 'En attente de paiement'), 'info'],
    'PAYMENT_REVIEW'         => ['🔍', mpT('Payment Under Review', 'Paiement en cours de vérification'), 'info'],
    'PAID'                   => ['✅', mpT('Paid', 'Payé'), 'success'],
    'READY_FOR_FULFILMENT'   => ['🚀', mpT('Ready for Fulfilment', 'Prêt pour exécution'), 'success'],
    'IN_FULFILMENT'          => ['⚙️', mpT('In Progress', 'En cours'), 'primary'],
    'BLOCKED'                => ['🚫', mpT('Blocked', 'Bloqué'), 'danger'],
    'COMPLETED'              => ['🎉', mpT('Completed', 'Terminé'), 'success'],
    'REJECTED'               => ['❌', mpT('Rejected', 'Rejeté'), 'danger'],
    'CANCELLED'              => ['🚫', mpT('Cancelled', 'Annulé'), 'secondary'],
];
[$stateIcon, $stateLabel, $stateBadge] = $stateLabels[$orderState] ?? ['❓', $orderState, 'secondary'];

$mtnNum    = getSettingCached('mtn_momo_number')    ?: '';
$orangeNum = getSettingCached('orange_money_number') ?: '';

$pageTitle = mpT('Order', 'Commande') . ' ' . e($ref);
require_once __DIR__ . '/../includes/partner-header.php';
?>

<div class="container py-4" style="max-width:960px;">

  <nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb small">
      <li class="breadcrumb-item"><a href="<?= SITE_URL ?>/partner/marketplace"><?= mpT('Marketplace', 'Marketplace') ?></a></li>
      <li class="breadcrumb-item active"><?= e($ref) ?></li>
    </ol>
  </nav>

  <div class="d-flex flex-wrap gap-3 align-items-start mb-4">
    <div>
      <h1 style="font-family:'Fraunces',serif;font-size:1.8rem;font-weight:900;margin:0;">
        <?= mpT('Order', 'Commande') ?> <span class="text-muted"><?= e($ref) ?></span>
      </h1>
      <div class="text-muted mt-1"><?= e($order['listing_title']) ?></div>
    </div>
    <span class="badge bg-<?= $stateBadge ?> fs-6 ms-auto align-self-center px-3 py-2">
      <?= $stateIcon ?> <?= $stateLabel ?>
    </span>
  </div>

  <div class="row g-4">
    <!-- Left column: timeline + items -->
    <div class="col-lg-7">

      <!-- Order items -->
      <div class="card shadow-sm mb-4">
        <div class="card-header fw-bold"><?= mpT('Items', 'Articles') ?></div>
        <div class="list-group list-group-flush">
          <?php foreach ($orderItems as $item):
            $name = $lang === 'fr' ? ($item['display_name_fr'] ?: $item['display_name_en']) : $item['display_name_en'];
            $ps = json_decode($item['price_snapshot'] ?? '{}', true) ?: [];
          ?>
          <div class="list-group-item">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <strong><?= e($name) ?></strong>
                <?php if ($ps['billing_interval'] ?? null): ?>
                <div class="text-muted small">
                  <?php if ($ps['setup_amount'] > 0): ?>
                    <?= mpT('Setup:', 'Mise en place :') ?> <?= mpFmt((int)$ps['setup_amount']) ?>
                  <?php endif; ?>
                  <?php if (($ps['recurring_amount'] ?? 0) > 0): ?>
                    · <?= mpT('Renewal:', 'Renouvellement :') ?> <?= mpFmt((int)$ps['recurring_amount']) ?>/<?= strtolower($ps['billing_interval']) ?>
                  <?php endif; ?>
                </div>
                <?php endif; ?>
              </div>
              <strong class="text-success"><?= mpFmt((int)$item['amount_due_now']) ?></strong>
            </div>
            <?php $cfg = json_decode($item['configuration'] ?? '{}', true) ?: []; ?>
            <?php if ($cfg): ?>
            <div class="mt-2 small text-muted">
              <?php foreach ($cfg as $k => $v): if ($k === 'partner_note') continue; ?>
              <span class="me-3"><strong><?= e($k) ?>:</strong> <?= e($v) ?></span>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="card-footer d-flex justify-content-between fw-bold">
          <span><?= mpT('Total due now', 'Total à payer maintenant') ?></span>
          <span class="text-success"><?= mpFmt((int)$order['due_now_amount']) ?></span>
        </div>
      </div>

      <!-- Order timeline -->
      <div class="card shadow-sm mb-4">
        <div class="card-header fw-bold"><?= mpT('Timeline', 'Historique') ?></div>
        <div class="card-body p-0">
          <ul class="list-group list-group-flush">

            <!-- Step 1: submitted -->
            <li class="list-group-item d-flex gap-3 align-items-start py-3">
              <span class="mt-1" style="font-size:1.3rem;">📝</span>
              <div>
                <div class="fw-semibold"><?= mpT('Order submitted', 'Commande soumise') ?></div>
                <div class="text-muted small"><?= date('d M Y H:i', strtotime($order['created_at'])) ?></div>
              </div>
              <span class="badge bg-success ms-auto align-self-center"><?= mpT('Done', 'Fait') ?></span>
            </li>

            <!-- Step 2: owner approval -->
            <li class="list-group-item d-flex gap-3 align-items-start py-3">
              <span class="mt-1" style="font-size:1.3rem;">
                <?= $order['approval_decision'] === 'APPROVED' ? '✅' : ($order['approval_decision'] === 'REJECTED' ? '❌' : '🕐') ?>
              </span>
              <div>
                <div class="fw-semibold"><?= mpT('Owner approval', 'Approbation du propriétaire') ?></div>
                <?php if ($order['approval_decided_at']): ?>
                <div class="text-muted small"><?= date('d M Y H:i', strtotime($order['approval_decided_at'])) ?></div>
                <?php elseif ($order['approval_expires_at']): ?>
                <div class="text-muted small">
                  <?= mpT('Expires', 'Expire') ?> <?= date('d M Y', strtotime($order['approval_expires_at'])) ?>
                </div>
                <?php endif; ?>
                <?php if ($order['approval_note']): ?>
                <div class="mt-1 small p-2 bg-light rounded"><?= e($order['approval_note']) ?></div>
                <?php endif; ?>
              </div>
              <span class="badge bg-<?= $order['approval_decision'] === 'APPROVED' ? 'success' : ($order['approval_decision'] === 'REJECTED' ? 'danger' : 'warning text-dark') ?> ms-auto align-self-center">
                <?= $order['approval_decision'] === 'APPROVED' ? mpT('Approved', 'Approuvé') : ($order['approval_decision'] === 'REJECTED' ? mpT('Rejected', 'Rejeté') : mpT('Pending', 'En attente')) ?>
              </span>
            </li>

            <!-- Step 3: payment -->
            <li class="list-group-item d-flex gap-3 align-items-start py-3">
              <span class="mt-1" style="font-size:1.3rem;">
                <?= in_array($payState, ['SUCCEEDED']) ? '✅' : (in_array($payState, ['PAYMENT_REVIEW','PENDING_PROVIDER']) ? '🔍' : (in_array($orderState, ['AWAITING_PAYMENT']) ? '💳' : '⬜')) ?>
              </span>
              <div>
                <div class="fw-semibold"><?= mpT('Payment', 'Paiement') ?></div>
                <?php if ($payAttempts): ?>
                <div class="text-muted small">
                  <?= count($payAttempts) ?> <?= mpT('attempt(s)', 'tentative(s)') ?>
                  · <?= mpT('Last:', 'Dernier :') ?> <?= date('d M Y H:i', strtotime($payAttempts[0]['created_at'])) ?>
                </div>
                <?php endif; ?>
              </div>
              <span class="badge bg-<?= in_array($payState, ['SUCCEEDED']) ? 'success' : (in_array($payState, ['PAYMENT_REVIEW','PENDING_PROVIDER']) ? 'info' : (in_array($orderState, ['AWAITING_PAYMENT']) ? 'warning text-dark' : 'secondary')) ?> ms-auto align-self-center">
                <?= match($payState) {
                    'NOT_STARTED'    => mpT('Not Started', 'Non commencé'),
                    'INITIATED'      => mpT('Initiated', 'Initié'),
                    'PENDING_PROVIDER' => mpT('Pending', 'En attente'),
                    'PAYMENT_REVIEW' => mpT('Under Review', 'En vérification'),
                    'SUCCEEDED'      => mpT('Paid', 'Payé'),
                    'FAILED'         => mpT('Failed', 'Échoué'),
                    'CANCELLED'      => mpT('Cancelled', 'Annulé'),
                    default          => $payState,
                } ?>
              </span>
            </li>

            <!-- Step 4: fulfilment -->
            <li class="list-group-item d-flex gap-3 align-items-start py-3">
              <span class="mt-1" style="font-size:1.3rem;">
                <?= $orderState === 'COMPLETED' ? '🎉' : (in_array($orderState, ['IN_FULFILMENT','READY_FOR_FULFILMENT']) ? '⚙️' : '⬜') ?>
              </span>
              <div class="flex-grow-1">
                <div class="fw-semibold"><?= mpT('Fulfilment', 'Exécution') ?></div>
                <?php if ($fulfilTasks): ?>
                <div class="mt-1">
                  <?php foreach ($fulfilTasks as $ft): ?>
                  <div class="d-flex justify-content-between small py-1 border-bottom">
                    <span><?= e($ft['title']) ?></span>
                    <span class="badge bg-<?= match($ft['fulfilment_state']) {
                        'COMPLETED' => 'success',
                        'IN_PROGRESS' => 'primary',
                        'ASSIGNED' => 'info',
                        'BLOCKED' => 'danger',
                        default => 'secondary'
                    } ?>"><?= e($ft['fulfilment_state']) ?></span>
                  </div>
                  <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="text-muted small"><?= mpT('Not started yet', 'Pas encore commencé') ?></div>
                <?php endif; ?>
              </div>
            </li>

          </ul>
        </div>
      </div>

    </div>

    <!-- Right column: actions + summary -->
    <div class="col-lg-5">

      <!-- Pay now CTA -->
      <?php if ($canPay): ?>
      <div class="card shadow-sm border-warning mb-4">
        <div class="card-header bg-warning fw-bold">💳 <?= mpT('Payment Required', 'Paiement requis') ?></div>
        <div class="card-body">
          <p><?= mpT('Your order has been approved. Please submit your payment proof.', 'Votre commande a été approuvée. Veuillez soumettre votre preuve de paiement.') ?></p>
          <div class="mb-3">
            <div class="fw-bold mb-1"><?= mpT('Amount due:', 'Montant dû :') ?> <span class="text-success"><?= mpFmt((int)$order['due_now_amount']) ?></span></div>
            <?php if ($mtnNum): ?><div class="small">📱 MTN MoMo: <strong><?= e($mtnNum) ?></strong></div><?php endif; ?>
            <?php if ($orangeNum): ?><div class="small">🍊 Orange Money: <strong><?= e($orangeNum) ?></strong></div><?php endif; ?>
          </div>
          <a href="<?= SITE_URL ?>/partner/marketplace-pay?ref=<?= e($ref) ?>" class="btn btn-warning w-100 fw-bold">
            <?= mpT('Submit Payment Proof →', 'Soumettre la preuve de paiement →') ?>
          </a>
        </div>
      </div>
      <?php endif; ?>

      <!-- Payment attempts history -->
      <?php if ($payAttempts): ?>
      <div class="card shadow-sm mb-4">
        <div class="card-header fw-bold"><?= mpT('Payment History', 'Historique de paiement') ?></div>
        <div class="list-group list-group-flush">
          <?php foreach ($payAttempts as $pa): ?>
          <div class="list-group-item small">
            <div class="d-flex justify-content-between">
              <span><?= date('d M Y H:i', strtotime($pa['created_at'])) ?></span>
              <span class="badge bg-<?= in_array($pa['payment_state'], ['SUCCEEDED']) ? 'success' : (in_array($pa['payment_state'], ['PAYMENT_REVIEW','PENDING_PROVIDER']) ? 'info' : 'secondary') ?>">
                <?= e($pa['payment_state']) ?>
              </span>
            </div>
            <div class="text-muted mt-1">
              <?= e($pa['provider']) ?> · <?= mpFmt((int)$pa['amount']) ?>
              <?php if ($pa['provider_ref']): ?> · ref: <?= e($pa['provider_ref']) ?><?php endif; ?>
            </div>
            <?php if ($pa['proof_path']): ?>
            <div class="mt-1"><a href="<?= UPLOAD_URL . e($pa['proof_path']) ?>" target="_blank" class="text-primary"><?= mpT('View proof', 'Voir la preuve') ?> ↗</a></div>
            <?php endif; ?>
            <?php if ($pa['reviewer_note']): ?>
            <div class="mt-1 p-2 bg-light rounded"><?= e($pa['reviewer_note']) ?></div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Order meta -->
      <div class="card shadow-sm mb-4">
        <div class="card-header fw-bold"><?= mpT('Order Details', 'Détails de la commande') ?></div>
        <div class="card-body small">
          <dl class="row mb-0">
            <dt class="col-sm-5"><?= mpT('Reference', 'Référence') ?></dt>
            <dd class="col-sm-7"><code><?= e($ref) ?></code></dd>
            <dt class="col-sm-5"><?= mpT('Business', 'Entreprise') ?></dt>
            <dd class="col-sm-7"><?= e($order['listing_title']) ?></dd>
            <dt class="col-sm-5"><?= mpT('Owner', 'Propriétaire') ?></dt>
            <dd class="col-sm-7"><?= e($order['owner_name']) ?></dd>
            <dt class="col-sm-5"><?= mpT('Submitted', 'Soumis le') ?></dt>
            <dd class="col-sm-7"><?= date('d M Y', strtotime($order['created_at'])) ?></dd>
            <?php if ($order['partner_note']): ?>
            <dt class="col-sm-5"><?= mpT('Your note', 'Votre note') ?></dt>
            <dd class="col-sm-7"><?= e($order['partner_note']) ?></dd>
            <?php endif; ?>
          </dl>
        </div>
      </div>

      <a href="<?= SITE_URL ?>/partner/marketplace" class="btn btn-outline-secondary w-100">
        ← <?= mpT('Back to Marketplace', 'Retour au Marketplace') ?>
      </a>

    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
