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
require_once __DIR__ . '/../includes/partner-helpers.php';
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

        } elseif ($action === 'approve_order') {
            // Admin approves a pending order → move to AWAITING_PAYMENT (offline flow)
            $pdo->prepare("UPDATE marketplace_orders SET order_state='AWAITING_PAYMENT', updated_at=NOW() WHERE id=? AND order_state='PENDING_OWNER_APPROVAL'")
                ->execute([$orderId]);
            // Also mark any pending approval row as approved
            $pdo->prepare("UPDATE mp_order_approvals SET decision='APPROVED', decided_at=NOW(), notes=? WHERE order_id=? AND decision IS NULL")
                ->execute([$note ?: null, $orderId]);
            $pdo->prepare("INSERT INTO mp_audit_events (entity_type,entity_id,actor_user_id,actor_role,action,after_state,ip_address) VALUES ('marketplace_orders',?,?,'admin','order.approved',JSON_OBJECT('order_state','AWAITING_PAYMENT'),?)")
                ->execute([$orderId, $_SESSION['user_id'], $_SERVER['REMOTE_ADDR'] ?? null]);
            _notifyPartner($pdo, $orderId, 'order_approved', $note);
            flash('success', 'Order approved — partner notified to arrange payment.');

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
        'order_approved' => [
            'Order Approved — ' . $o['ref'],
            '<p>Your order has been approved' . ($note ? ': ' . e($note) : '.') . ' Please now arrange payment to activate the service.</p>',
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
           o.created_at, o.completed_at AS payment_confirmed_at,
           l.title AS listing_title,
           u_partner.name AS partner_name,
           u_owner.name AS owner_name,
           oa.decision AS approval_decision,
           (SELECT COUNT(*) FROM mp_payment_attempts pa WHERE pa.order_id=o.id) AS pay_attempts,
           (SELECT COUNT(*) FROM mp_fulfilment_tasks ft WHERE ft.order_id=o.id) AS task_count,
           (SELECT COUNT(*) FROM mp_fulfilment_tasks ft WHERE ft.order_id=o.id AND ft.status='COMPLETED') AS task_done
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
<link rel="stylesheet" href="<?= SITE_URL ?>/admin/includes/mp-admin.css">

<div class="mp-wrap">

  <!-- Page header -->
  <div class="mp-page-header">
    <div>
      <h1 class="mp-page-title">📋 Marketplace Orders</h1>
      <div class="mp-page-subtitle"><?= $total ?> order<?= $total !== 1 ? 's' : '' ?> total</div>
    </div>
    <div class="mp-nav-links">
      <a href="<?= SITE_URL ?>/admin/marketplace-catalogue" class="mp-btn secondary">🛒 Catalogue</a>
      <a href="<?= SITE_URL ?>/admin/marketplace-fulfilment" class="mp-btn secondary">⚙️ Fulfilment</a>
    </div>
  </div>

  <?php showFlash(); ?>

  <!-- Filter bar -->
  <div class="mp-filter-bar">
    <form method="GET" style="display:flex;flex-wrap:wrap;gap:0.5rem;align-items:center;">
      <input type="text" name="ref" value="<?= e($filterRef) ?>" class="form-control form-control-sm"
        placeholder="Search ref or business…" style="width:200px;">
      <select name="state" class="form-select form-select-sm" style="width:190px;">
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
      <button class="mp-btn primary sm">Filter</button>
      <?php if ($filterState || $filterRef): ?>
      <a href="<?= SITE_URL ?>/admin/marketplace-orders" class="mp-btn secondary sm">Clear</a>
      <?php endif; ?>
    </form>
    <!-- Quick state links -->
    <div style="display:flex;gap:0.4rem;flex-wrap:wrap;margin-left:auto;">
      <?php
      $quickStates = [
          'PAYMENT_REVIEW'         => ['⚡ Payment Review', 'yellow'],
          'PENDING_OWNER_APPROVAL' => ['🕐 Awaiting Approval', 'grey'],
          'READY_FOR_FULFILMENT'   => ['✅ Ready to Fulfil', 'green'],
      ];
      foreach ($quickStates as $qs => [$qlabel, $qcol]): ?>
      <a href="?state=<?= $qs ?>" class="mp-btn sm <?= $filterState===$qs ? 'primary' : 'secondary' ?>">
        <?= $qlabel ?>
        <?php if (isset($stateCounts[$qs]) && $stateCounts[$qs] > 0): ?>
        <span class="mp-badge <?= $qcol ?>"><?= $stateCounts[$qs] ?></span>
        <?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if ($detail): ?>
  <!-- ── Detail view ──────────────────────────────────────────── -->
  <div class="mp-detail-grid">
    <!-- Left: order info -->
    <div>

      <div class="mp-detail-card">
        <div class="mp-detail-card-header" style="display:flex;justify-content:space-between;align-items:center;">
          <span class="mp-ref"><?= e($detail['ref']) ?></span>
          <?php
          $badgeCol = match($detail['order_state']) {
              'PAYMENT_REVIEW','PAID','READY_FOR_FULFILMENT','IN_FULFILMENT','COMPLETED' => 'green',
              'PENDING_OWNER_APPROVAL','AWAITING_PAYMENT' => 'yellow',
              'REJECTED','CANCELLED','BLOCKED' => 'red',
              default => 'grey',
          };
          ?>
          <span class="mp-badge <?= $badgeCol ?>"><?= e($detail['order_state']) ?></span>
        </div>
        <div class="mp-detail-card-body">
          <dl class="mp-dl">
            <dt>Business</dt><dd><?= e($detail['listing_title']) ?></dd>
            <dt>Partner</dt><dd><?= e($detail['partner_name']) ?></dd>
            <dt>Owner</dt><dd><?= e($detail['owner_name']) ?></dd>
            <dt>Submitted</dt><dd><?= date('d M Y H:i', strtotime($detail['created_at'])) ?></dd>
            <dt>Amount</dt><dd style="color:#00A878;font-weight:700;"><?= mpFmt((int)$detail['due_now_amount']) ?></dd>
            <?php if ($detail['payment_confirmed_at']): ?>
            <dt>Paid at</dt><dd><?= date('d M Y H:i', strtotime($detail['payment_confirmed_at'])) ?></dd>
            <?php endif; ?>
          </dl>
        </div>
      </div>

      <!-- Items -->
      <div class="mp-detail-card">
        <div class="mp-detail-card-header">Order Items</div>
        <div class="mp-detail-card-body" style="padding:0;">
          <?php foreach ($detailItems as $item): ?>
          <div style="display:flex;justify-content:space-between;align-items:flex-start;padding:0.75rem 1rem;border-bottom:1px solid rgba(255,255,255,0.05);">
            <div>
              <div style="font-weight:600;color:#e8f4ee;font-size:0.85rem;"><?= e($item['pname']) ?></div>
              <?php if ($item['interval']): ?><div style="font-size:0.75rem;color:#6c9e88;"><?= e($item['interval']) ?></div><?php endif; ?>
              <?php $cfg = json_decode($item['configuration'] ?? '{}', true) ?: []; ?>
              <?php foreach ($cfg as $k => $v): if ($k==='partner_note') continue; ?>
              <div style="font-size:0.75rem;color:#6c9e88;"><?= e($k) ?>: <?= e($v) ?></div>
              <?php endforeach; ?>
            </div>
            <span style="color:#00A878;font-weight:700;font-size:0.85rem;white-space:nowrap;"><?= mpFmt((int)$item['amount_due_now']) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Payment attempts -->
      <?php if ($detailPayments): ?>
      <div class="mp-detail-card">
        <div class="mp-detail-card-header">Payment Attempts (<?= count($detailPayments) ?>)</div>
        <div class="mp-detail-card-body" style="padding:0;">
          <?php foreach ($detailPayments as $pa):
            $pCol = match($pa['payment_state']) {
                'SUCCEEDED' => 'green', 'PAYMENT_REVIEW' => 'blue', 'FAILED' => 'red', default => 'grey'
            };
          ?>
          <div style="padding:0.75rem 1rem;border-bottom:1px solid rgba(255,255,255,0.05);">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:0.5rem;">
              <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
                <span class="mp-badge <?= $pCol ?>"><?= e($pa['payment_state']) ?></span>
                <span style="font-size:0.82rem;color:#c8ddd4;"><?= e($pa['provider']) ?> · <?= mpFmt((int)$pa['amount']) ?></span>
                <?php if ($pa['provider_ref']): ?><span style="font-size:0.75rem;color:#6c9e88;">ref: <?= e($pa['provider_ref']) ?></span><?php endif; ?>
              </div>
              <span style="font-size:0.75rem;color:#6c9e88;white-space:nowrap;"><?= date('d M Y H:i', strtotime($pa['created_at'])) ?></span>
            </div>
            <?php if ($pa['proof_path']): ?>
            <div style="margin-top:0.5rem;">
              <a href="<?= UPLOAD_URL . e($pa['proof_path']) ?>" target="_blank" class="mp-btn primary sm">👁 View Proof</a>
            </div>
            <?php endif; ?>
            <?php if ($pa['reviewer_note']): ?>
            <div style="margin-top:0.4rem;font-size:0.75rem;color:#6c9e88;">Note: <?= e($pa['reviewer_note']) ?></div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Audit log -->
      <div class="mp-detail-card">
        <div class="mp-detail-card-header">Audit Log</div>
        <div class="mp-detail-card-body">
          <?php foreach ($detailAudit as $ev): ?>
          <div class="mp-audit-row">
            <span class="mp-audit-action"><?= e($ev['action']) ?></span>
            <span><?= e($ev['actor_name'] ?? $ev['actor_role']) ?> · <?= date('d M H:i', strtotime($ev['created_at'])) ?></span>
          </div>
          <?php endforeach; ?>
          <?php if (!$detailAudit): ?><div style="color:#6c9e88;font-size:0.82rem;">No events yet.</div><?php endif; ?>
        </div>
      </div>

    </div>

    <!-- Right: action panel -->
    <div>

      <?php $os = $detail['order_state']; ?>

      <?php if ($os === 'PENDING_OWNER_APPROVAL'): ?>
      <div class="mp-action-card" style="border-color:#00A878;">
        <div class="mp-action-header" style="color:#00A878;">✅ Approve Order</div>
        <div class="mp-action-body">
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="order_id" value="<?= $detail['id'] ?>">
            <input type="hidden" name="action" value="approve_order">
            <p style="font-size:0.8rem;color:#6c9e88;margin-bottom:0.75rem;">
              Approving moves the order to <strong>Awaiting Payment</strong> and notifies the partner to arrange offline payment.
            </p>
            <input type="text" name="admin_note" class="form-control form-control-sm"
                   style="margin-bottom:0.5rem;" placeholder="Optional note to partner">
            <button class="mp-btn success w100">✅ Approve &amp; Notify Partner</button>
          </form>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($os === 'PAYMENT_REVIEW'): ?>
      <div class="mp-action-card review">
        <div class="mp-action-header">🔍 Review Payment</div>
        <div class="mp-action-body">
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="order_id" value="<?= $detail['id'] ?>">
            <div style="margin-bottom:0.75rem;">
              <label class="mp-config-label">Admin note (optional)</label>
              <input type="text" name="admin_note" class="form-control form-control-sm" placeholder="e.g. verified on MTN portal">
            </div>
            <div style="display:flex;gap:0.5rem;">
              <button name="action" value="confirm_payment" class="mp-btn success w100">✅ Confirm Payment</button>
              <button name="action" value="reject_payment" class="mp-btn danger w100"
                onclick="return confirm('Reject this payment? Partner will need to resubmit.')">❌ Reject</button>
            </div>
          </form>
        </div>
      </div>
      <?php endif; ?>

      <?php if (in_array($os, ['IN_FULFILMENT','READY_FOR_FULFILMENT'])): ?>
      <div class="mp-action-card success">
        <div class="mp-action-header">✅ Mark Complete</div>
        <div class="mp-action-body">
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="order_id" value="<?= $detail['id'] ?>">
            <input type="hidden" name="action" value="mark_complete">
            <p style="font-size:0.8rem;color:#6c9e88;margin-bottom:0.75rem;">Use once the service has been fully delivered to the business.</p>
            <button class="mp-btn success w100">Mark as Completed</button>
          </form>
        </div>
      </div>
      <?php endif; ?>

      <?php if (!in_array($os, ['COMPLETED','CANCELLED','REJECTED'])): ?>
      <div class="mp-action-card danger">
        <div class="mp-action-header">🚫 Cancel Order</div>
        <div class="mp-action-body">
          <form method="POST" onsubmit="return confirm('Cancel this order?')">
            <?= csrfField() ?>
            <input type="hidden" name="order_id" value="<?= $detail['id'] ?>">
            <input type="hidden" name="action" value="cancel_order">
            <input type="text" name="admin_note" class="form-control form-control-sm" style="margin-bottom:0.5rem;" placeholder="Reason (shown to partner)" required>
            <button class="mp-btn danger w100">Cancel Order</button>
          </form>
        </div>
      </div>
      <?php endif; ?>

      <a href="<?= SITE_URL ?>/admin/marketplace-orders" class="mp-btn secondary w100" style="margin-top:0.5rem;">← All Orders</a>

    </div>
  </div>
  <?php endif; ?>

  <!-- ── Orders table ─────────────────────────────────────────── -->
  <div class="mp-category">
    <div class="mp-category-header">
      <span style="font-weight:600;color:#d4ede0;"><?= $total ?> order<?= $total !== 1 ? 's' : '' ?></span>
      <?php if ($pages > 1): ?>
      <div style="display:flex;gap:0.3rem;flex-wrap:wrap;">
        <?php for ($p = 1; $p <= $pages; $p++): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['page'=>$p])) ?>"
          class="mp-btn sm <?= $p===$page?'primary':'secondary' ?>"><?= $p ?></a>
        <?php endfor; ?>
      </div>
      <?php endif; ?>
    </div>
    <div style="overflow-x:auto;">
      <table class="mp-table mp-orders-table">
        <thead>
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
          <tr><td colspan="8" style="text-align:center;color:#6c9e88;padding:2rem;">No orders found.</td></tr>
          <?php endif; ?>
          <?php foreach ($orders as $o):
            $badgeCol = match($o['order_state']) {
                'PAYMENT_REVIEW','PAID','READY_FOR_FULFILMENT','IN_FULFILMENT','COMPLETED' => 'green',
                'PENDING_OWNER_APPROVAL','AWAITING_PAYMENT' => 'yellow',
                'REJECTED','CANCELLED','BLOCKED' => 'red',
                default => 'grey',
            };
          ?>
          <tr>
            <td><span class="mp-ref"><?= e($o['ref']) ?></span></td>
            <td><?= e($o['listing_title']) ?></td>
            <td style="color:#9ab8a8;"><?= e($o['partner_name']) ?></td>
            <td style="color:#00A878;font-weight:700;"><?= mpFmt((int)$o['due_now_amount']) ?></td>
            <td><span class="mp-badge <?= $badgeCol ?>"><?= e($o['order_state']) ?></span></td>
            <td>
              <?php if ($o['order_state'] === 'PAYMENT_REVIEW'): ?>
              <span class="mp-badge yellow">⚡ Review needed</span>
              <?php else: ?>
              <span style="font-size:0.75rem;color:#6c9e88;"><?= e($o['payment_state']) ?></span>
              <?php endif; ?>
            </td>
            <td style="color:#6c9e88;font-size:0.78rem;"><?= date('d M Y', strtotime($o['created_at'])) ?></td>
            <td><a href="?ref=<?= e($o['ref']) ?>" class="mp-btn primary sm">View</a></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
