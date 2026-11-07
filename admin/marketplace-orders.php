<?php
/**
 * admin/marketplace-orders.php — Marketplace order management
 *
 * List view + detail modal:
 *  - Confirm payment (PAYMENT_REVIEW → PAID → READY_FOR_FULFILMENT)
 *  - Reject payment proof
 *  - Cancel order
 *  - View full timeline
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/flash-helpers.php';
requireAdmin();

$pdo = db();

function mpFmt(int $xaf): string { return number_format($xaf) . ' FCFA'; }

/* ── POST: order actions ──────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action  = $_POST['action']   ?? '';
    $orderId = (int)($_POST['order_id'] ?? 0);
    $note    = trim($_POST['admin_note'] ?? '');

    if ($orderId) {

        if ($action === 'confirm_payment') {
            // 1. Get latest PAYMENT_REVIEW attempt
            $stA = $pdo->prepare("SELECT id FROM mp_payment_attempts WHERE order_id=? AND payment_state='PAYMENT_REVIEW' ORDER BY created_at DESC LIMIT 1");
            $stA->execute([$orderId]);
            $attempt = $stA->fetch();
            if ($attempt) {
                $pdo->prepare("UPDATE mp_payment_attempts SET payment_state='SUCCEEDED', reviewed_by_user_id=?, reviewer_note=?, reviewed_at=NOW() WHERE id=?")
                    ->execute([$_SESSION['user_id'], $note ?: null, $attempt['id']]);
            }
            // 2. Advance order
            $pdo->prepare("UPDATE marketplace_orders SET order_state='READY_FOR_FULFILMENT', payment_state='SUCCEEDED', payment_confirmed_at=NOW(), confirmed_by_user_id=?, updated_at=NOW() WHERE id=?")
                ->execute([$_SESSION['user_id'], $orderId]);
            // 3. Audit
            $pdo->prepare("INSERT INTO mp_audit_events (entity_type,entity_id,actor_user_id,actor_role,action,after_state,ip_address) VALUES ('marketplace_orders',?,?,'admin','payment.confirmed',JSON_OBJECT('order_state','READY_FOR_FULFILMENT'),?)")
                ->execute([$orderId, $_SESSION['user_id'], $_SERVER['REMOTE_ADDR'] ?? null]);
            // 4. Notify partner
            _notifyPartner($pdo, $orderId, 'payment_confirmed');
            flash('success', 'Payment confirmed — order is ready for fulfilment.');

        } elseif ($action === 'reject_payment') {
            $stA = $pdo->prepare("SELECT id FROM mp_payment_attempts WHERE order_id=? AND payment_state='PAYMENT_REVIEW' ORDER BY created_at DESC LIMIT 1");
            $stA->execute([$orderId]);
            $attempt = $stA->fetch();
            if ($attempt) {
                $pdo->prepare("UPDATE mp_payment_attempts SET payment_state='FAILED', reviewed_by_user_id=?, reviewer_note=?, reviewed_at=NOW() WHERE id=?")
                    ->execute([$_SESSION['user_id'], $note ?: null, $attempt['id']]);
            }
            $pdo->prepare("UPDATE marketplace_orders SET order_state='AWAITING_PAYMENT', payment_state='FAILED', updated_at=NOW() WHERE id=?")
                ->execute([$orderId]);
            $pdo->prepare("INSERT INTO mp_audit_events (entity_type,entity_id,actor_user_id,actor_role,action,after_state,ip_address) VALUES ('marketplace_orders',?,?,'admin','payment.rejected',JSON_OBJECT('order_state','AWAITING_PAYMENT','reason',?),?)")
                ->execute([$orderId, $_SESSION['user_id'], $note, $_SERVER['REMOTE_ADDR'] ?? null]);
            _notifyPartner($pdo, $orderId, 'payment_rejected', $note);
            flash('warning', 'Payment rejected — partner notified to resubmit.');

        } elseif ($action === 'cancel_order') {
            $pdo->prepare("UPDATE marketplace_orders SET order_state='CANCELLED', updated_at=NOW() WHERE id=?")
                ->execute([$orderId]);
            $pdo->prepare("INSERT INTO mp_audit_events (entity_type,entity_id,actor_user_id,actor_role,action,after_state,ip_address) VALUES ('marketplace_orders',?,?,'admin','order.cancelled',JSON_OBJECT('order_state','CANCELLED','reason',?),?)")
                ->execute([$orderId, $_SESSION['user_id'], $note, $_SERVER['REMOTE_ADDR'] ?? null]);
            _notifyPartner($pdo, $orderId, 'order_cancelled', $note);
            flash('warning', 'Order cancelled.');

        } elseif ($action === 'mark_complete') {
            $pdo->prepare("UPDATE marketplace_orders SET order_state='COMPLETED', completed_at=NOW(), updated_at=NOW() WHERE id=?")
                ->execute([$orderId]);
            $pdo->prepare("INSERT INTO mp_audit_events (entity_type,entity_id,actor_user_id,actor_role,action,after_state,ip_address) VALUES ('marketplace_orders',?,?,'admin','order.completed',JSON_OBJECT('order_state','COMPLETED'),?)")
                ->execute([$orderId, $_SESSION['user_id'], $_SERVER['REMOTE_ADDR'] ?? null]);
            _notifyPartner($pdo, $orderId, 'order_completed');
            flash('success', 'Order marked as completed.');
        }
    }
    redirect(SITE_URL . '/admin/marketplace-orders' . (isset($_GET['ref']) ? '?ref=' . urlencode($_GET['ref']) : ''));
}

/* ── Helper: notify partner ──────────────────────────────────── */
function _notifyPartner(PDO $pdo, int $orderId, string $event, string $note = ''): void {
    $stO = $pdo->prepare("
        SELECT o.ref, o.due_now_amount, l.title AS listing_title,
               u.email AS partner_email, u.name AS partner_name
        FROM marketplace_orders o
        JOIN listings l ON l.id = o.listing_id
        JOIN partner_profiles pp ON pp.id = o.partner_profile_id
        JOIN users u ON u.id = pp.user_id
        WHERE o.id = ?
    ");
    $stO->execute([$orderId]);
    $o = $stO->fetch();
    if (!$o) return;

    $url = SITE_URL . '/partner/marketplace-order?ref=' . $o['ref'];
    $msgs = [
        'payment_confirmed' => [
            'Payment Confirmed — ' . $o['ref'],
            '<p>Your payment has been confirmed. We will now begin fulfilment of your service.</p>',
        ],
        'payment_rejected' => [
            'Payment Not Confirmed — ' . $o['ref'],
            '<p>We could not verify your payment' . ($note ? ': ' . e($note) : '.') . ' Please resubmit your proof.</p>',
        ],
        'order_cancelled' => [
            'Order Cancelled — ' . $o['ref'],
            '<p>Your order has been cancelled' . ($note ? ': ' . e($note) : '.') . '</p>',
        ],
        'order_completed' => [
            'Service Delivered — ' . $o['ref'],
            '<p>Your service has been delivered. Thank you!</p>',
        ],
    ];
    [$subj, $body] = $msgs[$event] ?? ['Order Update — ' . $o['ref'], '<p>Your order has been updated.</p>'];
    $body .= '<p><a href="' . $url . '">View order →</a></p>';
    sendMail($o['partner_email'], $subj, $body);
}

/* ── Filters ─────────────────────────────────────────────────── */
$filterState = $_GET['state'] ?? '';
$filterRef   = trim($_GET['ref'] ?? '');
$page        = max(1, (int)($_GET['page'] ?? 1));
$perPage     = 25;

$where  = ['1=1'];
$params = [];

if ($filterState) {
    $where[]  = 'o.order_state = ?';
    $params[] = $filterState;
}
if ($filterRef) {
    $where[]  = '(o.ref LIKE ? OR l.title LIKE ?)';
    $params[] = "%{$filterRef}%";
    $params[] = "%{$filterRef}%";
}

$whereStr = implode(' AND ', $where);
$total = (int)$pdo->prepare("SELECT COUNT(*) FROM marketplace_orders o JOIN listings l ON l.id=o.listing_id WHERE $whereStr")->execute($params) ? $pdo->prepare("SELECT COUNT(*) FROM marketplace_orders o JOIN listings l ON l.id=o.listing_id WHERE $whereStr")->execute($params) : 0;

// Re-run with correct fetch
$stCount = $pdo->prepare("SELECT COUNT(*) FROM marketplace_orders o JOIN listings l ON l.id=o.listing_id WHERE $whereStr");
$stCount->execute($params);
$total  = (int)$stCount->fetchColumn();
$pages  = max(1, (int)ceil($total / $perPage));
$offset = ($page - 1) * $perPage;

$stOrders = $pdo->prepare("
    SELECT o.id, o.ref, o.order_state, o.payment_state, o.due_now_amount,
           o.created_at, o.payment_confirmed_at,
           l.title AS listing_title,
           u_partner.name AS partner_name,
           u_owner.name AS owner_name,
           oa.decision AS approval_decision,
           (SELECT COUNT(*) FROM mp_payment_attempts pa WHERE pa.order_id=o.id) AS pay_attempts,
           (SELECT COUNT(*) FROM mp_fulfilment_tasks ft WHERE ft.order_id=o.id) AS task_count,
           (SELECT COUNT(*) FROM mp_fulfilment_tasks ft WHERE ft.order_id=o.id AND ft.fulfilment_state='COMPLETED') AS task_done
    FROM marketplace_orders o
    JOIN listings l ON l.id = o.listing_id
    JOIN users u_owner ON u_owner.id = l.user_id
    JOIN partner_profiles pp ON pp.id = o.partner_profile_id
    JOIN users u_partner ON u_partner.id = pp.user_id
    LEFT JOIN mp_order_approvals oa ON oa.order_id = o.id
    WHERE $whereStr
    ORDER BY o.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stOrders->execute($params);
$orders = $stOrders->fetchAll();

/* ── Detail view if ref specified ────────────────────────────── */
$detail = null;
$detailItems = [];
$detailPayments = [];
$detailTasks = [];
$detailAudit = [];

if ($filterRef && count($orders) === 1) {
    $detail = $orders[0];
    $did    = $detail['id'];

    $stDI = $pdo->prepare("SELECT oi.*, COALESCE(JSON_UNQUOTE(JSON_EXTRACT(oi.product_snapshot,'$.name_en')),'') AS pname, COALESCE(JSON_UNQUOTE(JSON_EXTRACT(oi.price_snapshot,'$.billing_interval')),'') AS interval FROM marketplace_order_items oi WHERE oi.order_id=?");
    $stDI->execute([$did]);
    $detailItems = $stDI->fetchAll();

    $stDP = $pdo->prepare("SELECT pa.*, u.name AS reviewer_name FROM mp_payment_attempts pa LEFT JOIN users u ON u.id=pa.reviewed_by_user_id WHERE pa.order_id=? ORDER BY pa.created_at DESC");
    $stDP->execute([$did]);
    $detailPayments = $stDP->fetchAll();

    $stDT = $pdo->prepare("SELECT ft.*, u.name AS assignee_name FROM mp_fulfilment_tasks ft LEFT JOIN users u ON u.id=ft.assignee_id WHERE ft.order_id=? ORDER BY ft.created_at");
    $stDT->execute([$did]);
    $detailTasks = $stDT->fetchAll();

    $stDA = $pdo->prepare("SELECT ae.*, u.name AS actor_name FROM mp_audit_events ae LEFT JOIN users u ON u.id=ae.actor_user_id WHERE ae.entity_type='marketplace_orders' AND ae.entity_id=? ORDER BY ae.created_at ASC");
    $stDA->execute([$did]);
    $detailAudit = $stDA->fetchAll();
}

/* ── State counts for filter bar ─────────────────────────────── */
$stateCounts = [];
$stSC = $pdo->query("SELECT order_state, COUNT(*) n FROM marketplace_orders GROUP BY order_state");
foreach ($stSC->fetchAll() as $r) $stateCounts[$r['order_state']] = $r['n'];

$stateBadge = fn($s) => match($s) {
    'PENDING_OWNER_APPROVAL' => 'warning',
    'AWAITING_PAYMENT'       => 'info',
    'PAYMENT_REVIEW'         => 'primary',
    'PAID','READY_FOR_FULFILMENT','COMPLETED' => 'success',
    'IN_FULFILMENT'          => 'primary',
    'REJECTED','CANCELLED','BLOCKED' => 'danger',
    default => 'secondary',
};

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>

<div class="admin-wrap">
  <div class="admin-header">
    <h1>📋 Marketplace Orders</h1>
    <div class="admin-header-actions">
      <a href="<?= SITE_URL ?>/admin/marketplace-catalogue" class="btn btn-sm btn-outline-secondary">🛒 Catalogue</a>
      <a href="<?= SITE_URL ?>/admin/marketplace-fulfilment" class="btn btn-sm btn-outline-secondary">⚙️ Fulfilment</a>
    </div>
  </div>

  <?php showFlash(); ?>

  <!-- Filter bar -->
  <div class="card shadow-sm mb-4">
    <div class="card-body d-flex flex-wrap gap-2 align-items-center">
      <form method="GET" class="d-flex gap-2 flex-wrap align-items-center">
        <input type="text" name="ref" value="<?= e($filterRef) ?>" class="form-control form-control-sm"
          placeholder="Search ref or business…" style="width:220px;">
        <select name="state" class="form-select form-select-sm" style="width:200px;">
          <option value="">All states</option>
          <?php
          $allStates = ['PENDING_OWNER_APPROVAL','AWAITING_PAYMENT','PAYMENT_REVIEW','PAID','READY_FOR_FULFILMENT','IN_FULFILMENT','BLOCKED','COMPLETED','REJECTED','CANCELLED'];
          foreach ($allStates as $s):
          ?>
          <option value="<?= $s ?>" <?= $filterState===$s?'selected':'' ?>>
            <?= $s ?> <?= isset($stateCounts[$s]) ? '('.$stateCounts[$s].')' : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-primary">Filter</button>
        <?php if ($filterState || $filterRef): ?>
        <a href="<?= SITE_URL ?>/admin/marketplace-orders" class="btn btn-sm btn-outline-secondary">Clear</a>
        <?php endif; ?>
      </form>

      <!-- Quick links for important states -->
      <div class="ms-auto d-flex gap-2 flex-wrap">
        <?php foreach (['PAYMENT_REVIEW','PENDING_OWNER_APPROVAL','READY_FOR_FULFILMENT'] as $qs): ?>
        <a href="?state=<?= $qs ?>" class="btn btn-sm btn-outline-<?= $stateBadge($qs) ?>">
          <?= $qs ?> <?= isset($stateCounts[$qs]) ? '<span class="badge bg-'.$stateBadge($qs).'">'.$stateCounts[$qs].'</span>' : '' ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <?php if ($detail): ?>
  <!-- ── Detail view ──────────────────────────────────────────── -->
  <div class="row g-4 mb-4">
    <div class="col-lg-7">

      <div class="card shadow-sm mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <strong><?= e($detail['ref']) ?></strong>
          <span class="badge bg-<?= $stateBadge($detail['order_state']) ?>"><?= e($detail['order_state']) ?></span>
        </div>
        <div class="card-body">
          <dl class="row mb-0 small">
            <dt class="col-sm-4">Business</dt><dd class="col-sm-8"><?= e($detail['listing_title']) ?></dd>
            <dt class="col-sm-4">Partner</dt><dd class="col-sm-8"><?= e($detail['partner_name']) ?></dd>
            <dt class="col-sm-4">Owner</dt><dd class="col-sm-8"><?= e($detail['owner_name']) ?></dd>
            <dt class="col-sm-4">Submitted</dt><dd class="col-sm-8"><?= date('d M Y H:i', strtotime($detail['created_at'])) ?></dd>
            <dt class="col-sm-4">Amount</dt><dd class="col-sm-8 fw-bold text-success"><?= mpFmt((int)$detail['due_now_amount']) ?></dd>
            <?php if ($detail['payment_confirmed_at']): ?>
            <dt class="col-sm-4">Paid at</dt><dd class="col-sm-8"><?= date('d M Y H:i', strtotime($detail['payment_confirmed_at'])) ?></dd>
            <?php endif; ?>
          </dl>
        </div>
      </div>

      <!-- Items -->
      <div class="card shadow-sm mb-3">
        <div class="card-header fw-bold small">Items</div>
        <div class="list-group list-group-flush small">
          <?php foreach ($detailItems as $item): ?>
          <div class="list-group-item d-flex justify-content-between">
            <div>
              <strong><?= e($item['pname']) ?></strong>
              <div class="text-muted"><?= e($item['interval']) ?></div>
              <?php $cfg = json_decode($item['configuration'] ?? '{}', true) ?: []; ?>
              <?php foreach ($cfg as $k => $v): if ($k==='partner_note') continue; ?>
              <span class="text-muted"><?= e($k) ?>: <?= e($v) ?></span>
              <?php endforeach; ?>
            </div>
            <strong><?= mpFmt((int)$item['amount_due_now']) ?></strong>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Payment attempts -->
      <?php if ($detailPayments): ?>
      <div class="card shadow-sm mb-3">
        <div class="card-header fw-bold small">Payment Attempts (<?= count($detailPayments) ?>)</div>
        <div class="list-group list-group-flush small">
          <?php foreach ($detailPayments as $pa): ?>
          <div class="list-group-item">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <span class="badge bg-<?= in_array($pa['payment_state'],['SUCCEEDED']) ? 'success' : (in_array($pa['payment_state'],['PAYMENT_REVIEW']) ? 'primary' : 'secondary') ?>">
                  <?= e($pa['payment_state']) ?>
                </span>
                <?= e($pa['provider']) ?> · <?= mpFmt((int)$pa['amount']) ?>
                <?php if ($pa['provider_ref']): ?> · ref: <?= e($pa['provider_ref']) ?><?php endif; ?>
              </div>
              <span class="text-muted"><?= date('d M Y H:i', strtotime($pa['created_at'])) ?></span>
            </div>
            <?php if ($pa['proof_path']): ?>
            <div class="mt-1">
              <a href="<?= UPLOAD_URL . e($pa['proof_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                👁 View Proof
              </a>
            </div>
            <?php endif; ?>
            <?php if ($pa['reviewer_note']): ?>
            <div class="mt-1 text-muted">Admin note: <?= e($pa['reviewer_note']) ?></div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Audit log -->
      <div class="card shadow-sm mb-3">
        <div class="card-header fw-bold small">Audit Log</div>
        <div class="list-group list-group-flush small">
          <?php foreach ($detailAudit as $ev): ?>
          <div class="list-group-item d-flex justify-content-between text-muted">
            <span><?= e($ev['action']) ?> <span class="text-dark"><?= e($ev['actor_name'] ?? $ev['actor_role']) ?></span></span>
            <span><?= date('d M H:i', strtotime($ev['created_at'])) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

    </div>

    <!-- Actions panel -->
    <div class="col-lg-5">

      <?php $os = $detail['order_state']; ?>

      <?php if ($os === 'PAYMENT_REVIEW'): ?>
      <div class="card shadow-sm border-primary mb-3">
        <div class="card-header bg-primary text-white fw-bold">🔍 Review Payment</div>
        <div class="card-body">
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="order_id" value="<?= $detail['id'] ?>">
            <div class="mb-3">
              <label class="form-label small">Admin note (optional)</label>
              <input type="text" name="admin_note" class="form-control form-control-sm" placeholder="e.g. verified on MTN portal">
            </div>
            <div class="d-flex gap-2">
              <button name="action" value="confirm_payment" class="btn btn-success flex-grow-1">✅ Confirm Payment</button>
              <button name="action" value="reject_payment" class="btn btn-danger flex-grow-1"
                onclick="return confirm('Reject this payment? Partner will need to resubmit.')">❌ Reject</button>
            </div>
          </form>
        </div>
      </div>
      <?php endif; ?>

      <?php if (in_array($os, ['IN_FULFILMENT','READY_FOR_FULFILMENT'])): ?>
      <div class="card shadow-sm border-success mb-3">
        <div class="card-header bg-success text-white fw-bold">✅ Mark Complete</div>
        <div class="card-body">
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="order_id" value="<?= $detail['id'] ?>">
            <input type="hidden" name="action" value="mark_complete">
            <p class="small text-muted">Use this once the service has been fully delivered to the business.</p>
            <button class="btn btn-success w-100">Mark as Completed</button>
          </form>
        </div>
      </div>
      <?php endif; ?>

      <?php if (!in_array($os, ['COMPLETED','CANCELLED','REJECTED'])): ?>
      <div class="card shadow-sm border-danger mb-3">
        <div class="card-header fw-bold small">🚫 Cancel Order</div>
        <div class="card-body">
          <form method="POST" onsubmit="return confirm('Cancel this order?')">
            <?= csrfField() ?>
            <input type="hidden" name="order_id" value="<?= $detail['id'] ?>">
            <input type="hidden" name="action" value="cancel_order">
            <input type="text" name="admin_note" class="form-control form-control-sm mb-2" placeholder="Reason (shown to partner)" required>
            <button class="btn btn-danger btn-sm w-100">Cancel Order</button>
          </form>
        </div>
      </div>
      <?php endif; ?>

      <a href="<?= SITE_URL ?>/admin/marketplace-orders" class="btn btn-outline-secondary w-100">← All Orders</a>

    </div>
  </div>
  <?php endif; ?>

  <!-- ── Orders table ─────────────────────────────────────────── -->
  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center small fw-semibold">
      <span><?= $total ?> order<?= $total !== 1 ? 's' : '' ?></span>
      <?php if ($pages > 1): ?>
      <div class="d-flex gap-1">
        <?php for ($p = 1; $p <= $pages; $p++): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['page'=>$p])) ?>"
          class="btn btn-sm btn-<?= $p===$page?'primary':'outline-secondary' ?>"><?= $p ?></a>
        <?php endfor; ?>
      </div>
      <?php endif; ?>
    </div>
    <div class="table-responsive">
      <table class="table table-hover mb-0 align-middle small">
        <thead class="table-light">
          <tr>
            <th>Ref</th>
            <th>Business</th>
            <th>Partner</th>
            <th>Amount</th>
            <th>State</th>
            <th>Payment</th>
            <th>Date</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$orders): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">No orders found.</td></tr>
          <?php endif; ?>
          <?php foreach ($orders as $o): ?>
          <tr>
            <td><code><?= e($o['ref']) ?></code></td>
            <td><?= e($o['listing_title']) ?></td>
            <td><?= e($o['partner_name']) ?></td>
            <td class="fw-semibold text-success"><?= mpFmt((int)$o['due_now_amount']) ?></td>
            <td><span class="badge bg-<?= $stateBadge($o['order_state']) ?>"><?= e($o['order_state']) ?></span></td>
            <td>
              <?php if ($o['order_state'] === 'PAYMENT_REVIEW'): ?>
              <span class="badge bg-primary">⚡ Review needed</span>
              <?php else: ?>
              <span class="text-muted"><?= e($o['payment_state']) ?></span>
              <?php endif; ?>
            </td>
            <td class="text-muted"><?= date('d M Y', strtotime($o['created_at'])) ?></td>
            <td>
              <a href="?ref=<?= e($o['ref']) ?>" class="btn btn-sm btn-outline-primary">View</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
