<?php
require_once __DIR__ . '/../includes/config.php';
requireAdmin();

$action = $_POST['action'] ?? '';
$errors = [];

/* ── POST ACTIONS ─────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie();

    $orderId = (int)($_POST['order_id'] ?? 0);

    // Load the monetisation order + linked subscription
    $stOrd = db()->prepare("SELECT mo.*, bs.id AS sub_id, bs.plan_id,
        sp.price_xaf, sp.duration_days, sp.slug AS plan_slug, sp.name_en AS plan_name_en,
        u.email AS user_email, u.name AS user_name,
        l.title AS listing_title
        FROM monetisation_orders mo
        JOIN business_subscriptions bs ON bs.id = mo.linked_id AND mo.product_type='subscription'
        JOIN subscription_plans sp ON sp.id = bs.plan_id
        JOIN users u ON u.id = mo.user_id
        LEFT JOIN listings l ON l.id = mo.listing_id
        WHERE mo.id=?");
    $stOrd->execute([$orderId]);
    $ord = $stOrd->fetch();

    if (!$ord) {
        flash('error', 'Order not found.');
        redirect(SITE_URL . '/admin/subscriptions.php');
    }

    /* ── APPROVE ── */
    if ($action === 'approve' && $ord['payment_status'] === 'pending') {
        $adminUser = currentUser();
        $now = date('Y-m-d H:i:s');
        $endsAt = date('Y-m-d H:i:s', strtotime("+{$ord['duration_days']} days"));

        // Cancel any other active subscription for this listing
        db()->prepare("UPDATE business_subscriptions
            SET status='cancelled', cancelled_at=NOW(), cancellation_reason='Superseded by new subscription'
            WHERE listing_id=? AND status='active' AND id != ?")
            ->execute([$ord['listing_id'], $ord['sub_id']]);

        // Activate subscription
        db()->prepare("UPDATE business_subscriptions
            SET status='active', starts_at=?, ends_at=?, admin_note='Approved by admin'
            WHERE id=?")
            ->execute([$now, $endsAt, $ord['sub_id']]);

        // Mark monetisation order paid
        db()->prepare("UPDATE monetisation_orders
            SET payment_status='paid', payment_confirmed_at=?, confirmed_by=?
            WHERE id=?")
            ->execute([$now, $adminUser['id'], $orderId]);

        // Commission creation — if a partner referred this
        if ($ord['partner_id'] && !$ord['commission_created']) {
            // Determine commission rate from partner tier
            $stPartner = db()->prepare("SELECT p.*, pt.commission_rate FROM users p
                LEFT JOIN partner_tiers pt ON pt.id = p.partner_tier_id
                WHERE p.id=?");
            $stPartner->execute([$ord['partner_id']]);
            $partner = $stPartner->fetch();

            if ($partner) {
                $rate = $partner['commission_rate'] ?? 25.00;
                $commissionAmount = round($ord['net_xaf'] * $rate / 100);

                db()->prepare("INSERT INTO partner_commissions
                    (user_id, listing_id, order_id, order_type, event_type, gross_amount_xaf, commission_rate, commission_amount_xaf, status, referral_code, notes)
                    VALUES (?,?,?,'subscription','subscription_new',?,?,?,'pending',?,?)")
                    ->execute([
                        $ord['partner_id'],
                        $ord['listing_id'],
                        $orderId,
                        $ord['net_xaf'],
                        $rate,
                        $commissionAmount,
                        $ord['referral_code'],
                        'Auto-created on subscription approval'
                    ]);

                db()->prepare("UPDATE monetisation_orders SET commission_created=1 WHERE id=?")
                    ->execute([$orderId]);
            }
        }

        // Notify user
        if (file_exists(__DIR__ . '/../includes/notify.php')) {
            require_once __DIR__ . '/../includes/notify.php';
        }
        sendMail($ord['user_email'],
            'Your subscription is now active!',
            '<h2 style="color:#fff;font-family:Georgia,serif;">Subscription Activated</h2>
             <p style="color:rgba(255,255,255,0.7);">Hi ' . e($ord['user_name']) . ',</p>
             <p style="color:rgba(255,255,255,0.7);">Your <strong>' . e($ord['plan_name_en']) . '</strong> subscription for <strong>' . e($ord['listing_title']) . '</strong> is now active.</p>
             <p style="color:rgba(255,255,255,0.7);"><strong>Active until:</strong> ' . date('d M Y', strtotime($endsAt)) . '</p>
             <p style="color:rgba(255,255,255,0.7);">Reference: ' . e($ord['ref']) . '</p>
             <a href="' . SITE_URL . '/dashboard" style="background:#00A878;color:#fff;padding:.75rem 1.5rem;border-radius:5px;text-decoration:none;display:inline-block;margin-top:1rem;">View Dashboard</a>'
        );

        flash('success', 'Subscription approved and activated. User has been notified.');
        redirect(SITE_URL . '/admin/subscriptions.php');
    }

    /* ── REJECT ── */
    if ($action === 'reject' && $ord['payment_status'] === 'pending') {
        $reason = trim($_POST['rejection_reason'] ?? '');
        if (!$reason) {
            flash('error', 'Please provide a rejection reason.');
            redirect(SITE_URL . '/admin/subscriptions.php?view=' . $orderId);
        }

        db()->prepare("UPDATE business_subscriptions SET status='payment_failed', admin_note=? WHERE id=?")
            ->execute([$reason, $ord['sub_id']]);

        db()->prepare("UPDATE monetisation_orders SET payment_status='failed', notes=? WHERE id=?")
            ->execute([$reason, $orderId]);

        sendMail($ord['user_email'],
            'Subscription payment not approved — ' . $ord['ref'],
            '<h2 style="color:#fff;font-family:Georgia,serif;">Payment Not Approved</h2>
             <p style="color:rgba(255,255,255,0.7);">Hi ' . e($ord['user_name']) . ',</p>
             <p style="color:rgba(255,255,255,0.7);">Unfortunately your subscription payment (Ref: <strong>' . e($ord['ref']) . '</strong>) could not be approved for the following reason:</p>
             <blockquote style="border-left:3px solid #f59e0b;padding:.75rem 1rem;margin:1rem 0;color:#f59e0b;">' . e($reason) . '</blockquote>
             <p style="color:rgba(255,255,255,0.7);">Please resubmit with a valid payment proof.</p>
             <a href="' . SITE_URL . '/subscribe?listing_id=' . $ord['listing_id'] . '" style="background:#00A878;color:#fff;padding:.75rem 1.5rem;border-radius:5px;text-decoration:none;display:inline-block;margin-top:1rem;">Resubmit Payment</a>'
        );

        flash('success', 'Subscription payment rejected. User notified.');
        redirect(SITE_URL . '/admin/subscriptions.php');
    }

    /* ── MARK PAID (manual override) ── */
    if ($action === 'mark_paid' && in_array($ord['payment_status'], ['pending','failed'])) {
        $adminUser = currentUser();
        $now = date('Y-m-d H:i:s');
        $endsAt = date('Y-m-d H:i:s', strtotime("+{$ord['duration_days']} days"));

        db()->prepare("UPDATE business_subscriptions SET status='active', starts_at=?, ends_at=? WHERE id=?")
            ->execute([$now, $endsAt, $ord['sub_id']]);
        db()->prepare("UPDATE monetisation_orders SET payment_status='paid', payment_confirmed_at=?, confirmed_by=? WHERE id=?")
            ->execute([$now, $adminUser['id'], $orderId]);

        flash('success', 'Subscription manually marked as paid and activated.');
        redirect(SITE_URL . '/admin/subscriptions.php');
    }
}

/* ── SINGLE ORDER VIEW ────────────────────────────────────── */
$viewId = (int)($_GET['view'] ?? 0);
$viewOrder = null;
if ($viewId) {
    $stV = db()->prepare("SELECT mo.*, bs.id AS sub_id, bs.status AS sub_status,
        bs.starts_at, bs.ends_at, bs.admin_note,
        sp.name_en AS plan_name_en, sp.name_fr AS plan_name_fr, sp.slug AS plan_slug,
        sp.price_xaf, sp.duration_days, sp.billing_interval,
        u.email AS user_email, u.name AS user_name,
        l.title AS listing_title, l.id AS listing_id_val,
        confirmer.name AS confirmed_by_name
        FROM monetisation_orders mo
        JOIN business_subscriptions bs ON bs.id = mo.linked_id AND mo.product_type='subscription'
        JOIN subscription_plans sp ON sp.id = bs.plan_id
        JOIN users u ON u.id = mo.user_id
        LEFT JOIN listings l ON l.id = mo.listing_id
        LEFT JOIN users confirmer ON confirmer.id = mo.confirmed_by
        WHERE mo.id=?");
    $stV->execute([$viewId]);
    $viewOrder = $stV->fetch();
}

/* ── LIST ─────────────────────────────────────────────────── */
$filter = $_GET['filter'] ?? 'pending';
$page   = max(1, (int)($_GET['p'] ?? 1));
$perPage = 25;
$offset  = ($page - 1) * $perPage;

$allowedFilters = ['pending','active','all','failed'];
if (!in_array($filter, $allowedFilters)) $filter = 'pending';

$whereClause = "mo.product_type='subscription'";
if ($filter === 'pending')  $whereClause .= " AND mo.payment_status='pending'";
if ($filter === 'active')   $whereClause .= " AND mo.payment_status='paid'";
if ($filter === 'failed')   $whereClause .= " AND mo.payment_status IN ('failed','refunded')";

$total = db()->query("SELECT COUNT(*) FROM monetisation_orders mo WHERE $whereClause")->fetchColumn();
$orders = db()->query("SELECT mo.*, bs.status AS sub_status, bs.starts_at, bs.ends_at,
    sp.name_en AS plan_name_en, sp.slug AS plan_slug,
    u.name AS user_name, u.email AS user_email,
    l.title AS listing_title
    FROM monetisation_orders mo
    JOIN business_subscriptions bs ON bs.id = mo.linked_id AND mo.product_type='subscription'
    JOIN subscription_plans sp ON sp.id = bs.plan_id
    JOIN users u ON u.id = mo.user_id
    LEFT JOIN listings l ON l.id = mo.listing_id
    WHERE $whereClause
    ORDER BY mo.created_at DESC
    LIMIT $perPage OFFSET $offset")->fetchAll();

$pendingCount = db()->query("SELECT COUNT(*) FROM monetisation_orders WHERE product_type='subscription' AND payment_status='pending'")->fetchColumn();

$pageTitle = 'Subscriptions — Admin';
require_once __DIR__ . '/../includes/admin-header.php';
?>
<style>
.sub-status-badge{display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .65rem;border-radius:99px;font-size:.72rem;font-weight:600;}
.order-row:hover{background:rgba(255,255,255,.03);}
.proof-thumb{width:56px;height:56px;object-fit:cover;border-radius:6px;border:1px solid var(--border);cursor:pointer;}
</style>

<div class="admin-page-header">
  <div>
    <h1>Subscription Orders</h1>
    <p style="color:var(--muted);font-size:.875rem;">Manage business subscription payments</p>
  </div>
  <a href="<?= SITE_URL ?>/admin/subscription-plans.php" class="btn btn-secondary btn-sm">⚙ Manage Plans</a>
</div>

<?php if ($viewOrder): ?>
<!-- ═══ SINGLE ORDER DETAIL ═══ -->
<div style="margin-bottom:1.5rem;">
  <a href="<?= SITE_URL ?>/admin/subscriptions.php" style="color:var(--muted);text-decoration:none;font-size:.875rem;">← Back to list</a>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;align-items:start;">

  <!-- Left: order details -->
  <div class="admin-card">
    <h3 style="margin-bottom:1rem;">Order Details</h3>
    <table style="width:100%;font-size:.875rem;border-collapse:collapse;">
      <?php $rows = [
        'Ref'            => e($viewOrder['ref']),
        'User'           => e($viewOrder['user_name']) . ' &lt;' . e($viewOrder['user_email']) . '&gt;',
        'Listing'        => e($viewOrder['listing_title']),
        'Plan'           => e($viewOrder['plan_name_en']) . ' (' . e($viewOrder['plan_slug']) . ')',
        'Amount'         => number_format($viewOrder['price_xaf']) . ' XAF',
        'Duration'       => $viewOrder['duration_days'] . ' days / ' . $viewOrder['billing_interval'],
        'Submitted'      => date('d M Y H:i', strtotime($viewOrder['created_at'])),
        'Payment Status' => '<span class="sub-status-badge" style="background:' . (['pending'=>'rgba(245,158,11,.12)','paid'=>'rgba(0,168,120,.12)','failed'=>'rgba(239,68,68,.12)'][$viewOrder['payment_status']]??'rgba(107,114,128,.12)') . ';color:' . (['pending'=>'#f59e0b','paid'=>'#00A878','failed'=>'#ef4444'][$viewOrder['payment_status']]??'#6b7280') . ';">' . ucfirst($viewOrder['payment_status']) . '</span>',
        'Sub Status'     => e($viewOrder['sub_status']),
      ];
      if ($viewOrder['starts_at']) $rows['Active Period'] = date('d M Y', strtotime($viewOrder['starts_at'])) . ' → ' . date('d M Y', strtotime($viewOrder['ends_at']));
      if ($viewOrder['confirmed_by_name']) $rows['Approved By'] = e($viewOrder['confirmed_by_name']);
      if ($viewOrder['partner_id']) $rows['Partner'] = 'Partner #' . e($viewOrder['partner_id']) . ' (ref: ' . e($viewOrder['referral_code']) . ')';
      foreach ($rows as $label => $val): ?>
      <tr>
        <td style="padding:.55rem 0;border-bottom:1px solid var(--border);color:var(--muted);width:38%;"><?= $label ?></td>
        <td style="padding:.55rem 0;border-bottom:1px solid var(--border);"><?= $val ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <!-- Right: proof + actions -->
  <div style="display:flex;flex-direction:column;gap:1rem;">
    <?php if ($viewOrder['proof']): ?>
    <div class="admin-card">
      <h4 style="margin-bottom:.75rem;">Payment Proof</h4>
      <?php $ext = strtolower(pathinfo($viewOrder['proof'], PATHINFO_EXTENSION));
            $proofUrl = UPLOAD_URL . '/' . $viewOrder['proof']; ?>
      <?php if ($ext === 'pdf'): ?>
        <a href="<?= $proofUrl ?>" target="_blank" class="btn btn-secondary btn-sm">📄 View PDF Proof</a>
      <?php else: ?>
        <a href="<?= $proofUrl ?>" target="_blank">
          <img src="<?= $proofUrl ?>" alt="Payment proof" style="max-width:100%;border-radius:8px;border:1px solid var(--border);">
        </a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($viewOrder['payment_status'] === 'pending'): ?>
    <!-- APPROVE FORM -->
    <div class="admin-card" style="background:rgba(0,168,120,.05);border-color:rgba(0,168,120,.2);">
      <h4 style="color:#00A878;margin-bottom:.75rem;">✅ Approve Payment</h4>
      <p style="color:var(--muted);font-size:.83rem;margin-bottom:1rem;">Activates the subscription immediately for <?= $viewOrder['duration_days'] ?> days.</p>
      <form method="post">
        <?= csrf() ?>
        <input type="hidden" name="action" value="approve">
        <input type="hidden" name="order_id" value="<?= $viewOrder['id'] ?>">
        <button type="submit" class="btn btn-primary btn-sm">Approve &amp; Activate</button>
      </form>
    </div>

    <!-- REJECT FORM -->
    <div class="admin-card" style="background:rgba(239,68,68,.05);border-color:rgba(239,68,68,.2);">
      <h4 style="color:#ef4444;margin-bottom:.75rem;">❌ Reject Payment</h4>
      <form method="post">
        <?= csrf() ?>
        <input type="hidden" name="action" value="reject">
        <input type="hidden" name="order_id" value="<?= $viewOrder['id'] ?>">
        <div class="form-group" style="margin-bottom:.75rem;">
          <label style="font-size:.8rem;">Reason *</label>
          <textarea name="rejection_reason" rows="2" class="form-control" placeholder="e.g. Blurry screenshot, wrong amount..." style="font-size:.875rem;"></textarea>
        </div>
        <button type="submit" class="btn btn-sm" style="background:#ef4444;color:#fff;">Reject</button>
      </form>
    </div>
    <?php endif; ?>

    <?php if (in_array($viewOrder['payment_status'], ['pending','failed'])): ?>
    <!-- MANUAL OVERRIDE -->
    <div class="admin-card">
      <h4 style="margin-bottom:.75rem;">🔧 Manual Override</h4>
      <p style="font-size:.8rem;color:var(--muted);margin-bottom:.75rem;">Force-activate without proof verification.</p>
      <form method="post" onsubmit="return confirm('Force-activate this subscription?')">
        <?= csrf() ?>
        <input type="hidden" name="action" value="mark_paid">
        <input type="hidden" name="order_id" value="<?= $viewOrder['id'] ?>">
        <button type="submit" class="btn btn-secondary btn-sm">Mark Paid &amp; Activate</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php else: ?>
<!-- ═══ ORDER LIST ═══ -->

<!-- Filter tabs -->
<div style="display:flex;gap:.5rem;margin-bottom:1.5rem;flex-wrap:wrap;">
  <?php foreach (['pending'=>'⏳ Pending','active'=>'✅ Active','failed'=>'❌ Failed/Refunded','all'=>'All'] as $f => $label): ?>
  <a href="?filter=<?= $f ?>" class="btn btn-sm <?= $filter===$f ? 'btn-primary' : 'btn-secondary' ?>">
    <?= $label ?>
    <?php if ($f==='pending' && $pendingCount): ?>
      <span style="background:#ef4444;color:#fff;border-radius:99px;padding:.1rem .45rem;font-size:.7rem;margin-left:.3rem;"><?= $pendingCount ?></span>
    <?php endif; ?>
  </a>
  <?php endforeach; ?>
</div>

<?php if (!$orders): ?>
<div style="text-align:center;padding:3rem;background:var(--card);border:1px solid var(--border);border-radius:12px;">
  <div style="font-size:2rem;margin-bottom:.5rem;">📭</div>
  <p style="color:var(--muted);">No <?= $filter ?> subscription orders.</p>
</div>
<?php else: ?>
<div class="admin-card" style="padding:0;overflow:hidden;">
  <table class="admin-table" style="font-size:.83rem;">
    <thead>
      <tr>
        <th>Ref</th>
        <th>User</th>
        <th>Listing</th>
        <th>Plan</th>
        <th>Amount</th>
        <th>Status</th>
        <th>Date</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($orders as $o):
      $statusColors = [
        'pending'  => ['bg'=>'rgba(245,158,11,.12)','c'=>'#f59e0b'],
        'paid'     => ['bg'=>'rgba(0,168,120,.12)','c'=>'#00A878'],
        'failed'   => ['bg'=>'rgba(239,68,68,.12)','c'=>'#ef4444'],
        'refunded' => ['bg'=>'rgba(107,114,128,.12)','c'=>'#6b7280'],
      ];
      $sc = $statusColors[$o['payment_status']] ?? $statusColors['failed'];
    ?>
    <tr class="order-row">
      <td><code style="font-size:.78rem;"><?= e($o['ref']) ?></code></td>
      <td>
        <div><?= e($o['user_name']) ?></div>
        <div style="font-size:.75rem;color:var(--muted);"><?= e($o['user_email']) ?></div>
      </td>
      <td style="max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= e($o['listing_title']) ?></td>
      <td>
        <span style="font-weight:600;"><?= e($o['plan_name_en']) ?></span>
        <?php if ($o['starts_at']): ?>
        <div style="font-size:.72rem;color:var(--muted);">→ <?= date('d M Y', strtotime($o['ends_at'])) ?></div>
        <?php endif; ?>
      </td>
      <td style="white-space:nowrap;"><?= number_format($o['amount_xaf']) ?> XAF</td>
      <td>
        <span class="sub-status-badge" style="background:<?= $sc['bg'] ?>;color:<?= $sc['c'] ?>;">
          <?= ucfirst($o['payment_status']) ?>
        </span>
      </td>
      <td style="white-space:nowrap;color:var(--muted);"><?= date('d M Y', strtotime($o['created_at'])) ?></td>
      <td>
        <a href="?view=<?= $o['id'] ?>" class="btn btn-secondary btn-sm">View</a>
        <?php if ($o['payment_status'] === 'pending'): ?>
        <form method="post" style="display:inline;">
          <?= csrf() ?>
          <input type="hidden" name="action" value="approve">
          <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
          <button type="submit" class="btn btn-sm" style="background:#00A878;color:#fff;">✓ Approve</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Pagination -->
<?php if ($total > $perPage): ?>
<div style="display:flex;justify-content:center;gap:.5rem;margin-top:1rem;">
  <?php $pages = ceil($total / $perPage);
  for ($i = 1; $i <= $pages; $i++): ?>
  <a href="?filter=<?= $filter ?>&p=<?= $i ?>" class="btn btn-sm <?= $i===$page ? 'btn-primary' : 'btn-secondary' ?>"><?= $i ?></a>
  <?php endfor; ?>
</div>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>
