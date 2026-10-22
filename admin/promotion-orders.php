<?php
require_once __DIR__ . '/../includes/config.php';
requireAdmin();

$action = $_POST['action'] ?? '';

/* ── POST ACTIONS ─────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $orderId = (int)($_POST['order_id'] ?? 0);

    // Load the promotion order
    $stOrd = db()->prepare("SELECT po.*, pp.duration_days, pp.name_en AS prod_name_en, pp.price_xaf,
        mo.id AS mon_id, mo.payment_status AS mon_payment_status,
        mo.partner_id, mo.referral_code, mo.net_xaf, mo.commission_created,
        u.email AS user_email, u.name AS user_name,
        l.title AS listing_title
        FROM promotion_orders po
        JOIN promotion_products pp ON pp.id = po.product_id
        LEFT JOIN monetisation_orders mo ON mo.linked_id = po.id AND mo.product_type='promotion'
        JOIN users u ON u.id = po.user_id
        LEFT JOIN listings l ON l.id = po.listing_id
        WHERE po.id=?");
    $stOrd->execute([$orderId]);
    $ord = $stOrd->fetch();

    if (!$ord) {
        flash('error', 'Promotion order not found.');
        redirect(SITE_URL . '/admin/promotion-orders.php');
    }

    /* ── APPROVE ── */
    if ($action === 'approve' && $ord['payment_status'] === 'pending') {
        $adminUser = currentUser();
        $now     = date('Y-m-d H:i:s');
        $endsAt  = date('Y-m-d H:i:s', strtotime("+{$ord['duration_days']} days"));

        db()->prepare("UPDATE promotion_orders
            SET payment_status='paid', promo_status='active',
                starts_at=?, ends_at=?, approved_by=?, approved_at=?
            WHERE id=?")
            ->execute([$now, $endsAt, $adminUser['id'], $now, $orderId]);

        if ($ord['mon_id']) {
            db()->prepare("UPDATE monetisation_orders
                SET payment_status='paid', payment_confirmed_at=?, confirmed_by=?
                WHERE id=?")
                ->execute([$now, $adminUser['id'], $ord['mon_id']]);
        }

        // Commission creation
        if ($ord['partner_id'] && !$ord['commission_created']) {
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
                    VALUES (?,?,?,'promotion','promotion_purchase',?,?,?,'pending',?,?)")
                    ->execute([
                        $ord['partner_id'],
                        $ord['listing_id'],
                        $ord['mon_id'] ?: $orderId,
                        $ord['net_xaf'],
                        $rate,
                        $commissionAmount,
                        $ord['referral_code'],
                        'Auto-created on promotion approval'
                    ]);

                if ($ord['mon_id']) {
                    db()->prepare("UPDATE monetisation_orders SET commission_created=1 WHERE id=?")
                        ->execute([$ord['mon_id']]);
                }
            }
        }

        sendMail($ord['user_email'],
            'Your promotion is now live!',
            '<h2 style="color:#fff;font-family:Georgia,serif;">Promotion Activated</h2>
             <p style="color:rgba(255,255,255,0.7);">Hi ' . e($ord['user_name']) . ',</p>
             <p style="color:rgba(255,255,255,0.7);">Your <strong>' . e($ord['prod_name_en']) . '</strong> promotion for <strong>' . e($ord['listing_title']) . '</strong> is now live!</p>
             <p style="color:rgba(255,255,255,0.7);"><strong>Active until:</strong> ' . date('d M Y', strtotime($endsAt)) . '</p>
             <p style="color:rgba(255,255,255,0.7);">Reference: ' . e($ord['ref']) . '</p>
             <a href="' . SITE_URL . '/dashboard" style="background:#00A878;color:#fff;padding:.75rem 1.5rem;border-radius:5px;text-decoration:none;display:inline-block;margin-top:1rem;">View Dashboard</a>'
        );

        flash('success', 'Promotion approved and activated.');
        redirect(SITE_URL . '/admin/promotion-orders.php');
    }

    /* ── REJECT ── */
    if ($action === 'reject' && $ord['payment_status'] === 'pending') {
        $reason = trim($_POST['rejection_reason'] ?? '');
        if (!$reason) {
            flash('error', 'Please provide a rejection reason.');
            redirect(SITE_URL . '/admin/promotion-orders.php?view=' . $orderId);
        }

        db()->prepare("UPDATE promotion_orders
            SET payment_status='failed', promo_status='rejected', rejection_reason=?
            WHERE id=?")
            ->execute([$reason, $orderId]);

        if ($ord['mon_id']) {
            db()->prepare("UPDATE monetisation_orders SET payment_status='failed', notes=? WHERE id=?")
                ->execute([$reason, $ord['mon_id']]);
        }

        sendMail($ord['user_email'],
            'Promotion payment not approved — ' . $ord['ref'],
            '<h2 style="color:#fff;font-family:Georgia,serif;">Payment Not Approved</h2>
             <p style="color:rgba(255,255,255,0.7);">Hi ' . e($ord['user_name']) . ',</p>
             <p style="color:rgba(255,255,255,0.7);">Your promotion payment (Ref: <strong>' . e($ord['ref']) . '</strong>) was not approved:</p>
             <blockquote style="border-left:3px solid #f59e0b;padding:.75rem 1rem;margin:1rem 0;color:#f59e0b;">' . e($reason) . '</blockquote>
             <a href="' . SITE_URL . '/promote?listing_id=' . $ord['listing_id'] . '" style="background:#00A878;color:#fff;padding:.75rem 1.5rem;border-radius:5px;text-decoration:none;display:inline-block;margin-top:1rem;">Resubmit</a>'
        );

        flash('success', 'Promotion rejected. User notified.');
        redirect(SITE_URL . '/admin/promotion-orders.php');
    }
}

/* ── SINGLE VIEW ───────────────────────────────────────────── */
$viewId = (int)($_GET['view'] ?? 0);
$viewOrder = null;
if ($viewId) {
    $stV = db()->prepare("SELECT po.*, pp.duration_days, pp.name_en AS prod_name_en, pp.name_fr AS prod_name_fr,
        pp.promo_type, pp.price_xaf,
        mo.id AS mon_id, mo.payment_status AS mon_status, mo.partner_id, mo.referral_code,
        u.email AS user_email, u.name AS user_name,
        l.title AS listing_title, l.id AS listing_id_val,
        approver.name AS approver_name
        FROM promotion_orders po
        JOIN promotion_products pp ON pp.id = po.product_id
        LEFT JOIN monetisation_orders mo ON mo.linked_id = po.id AND mo.product_type='promotion'
        JOIN users u ON u.id = po.user_id
        LEFT JOIN listings l ON l.id = po.listing_id
        LEFT JOIN users approver ON approver.id = po.approved_by
        WHERE po.id=?");
    $stV->execute([$viewId]);
    $viewOrder = $stV->fetch();
}

/* ── LIST ─────────────────────────────────────────────────── */
$filter  = $_GET['filter'] ?? 'pending';
$page    = max(1, (int)($_GET['p'] ?? 1));
$perPage = 25;
$offset  = ($page - 1) * $perPage;

$allowedFilters = ['pending','active','all','rejected'];
if (!in_array($filter, $allowedFilters)) $filter = 'pending';

$whereClause = "1=1";
if ($filter === 'pending')  $whereClause = "po.payment_status='pending'";
if ($filter === 'active')   $whereClause = "po.promo_status='active'";
if ($filter === 'rejected') $whereClause = "po.promo_status IN ('rejected','cancelled')";

$total  = db()->query("SELECT COUNT(*) FROM promotion_orders po WHERE $whereClause")->fetchColumn();
$orders = db()->query("SELECT po.*, pp.name_en AS prod_name_en, pp.promo_type,
    u.name AS user_name, u.email AS user_email,
    l.title AS listing_title
    FROM promotion_orders po
    JOIN promotion_products pp ON pp.id = po.product_id
    JOIN users u ON u.id = po.user_id
    LEFT JOIN listings l ON l.id = po.listing_id
    WHERE $whereClause
    ORDER BY po.created_at DESC
    LIMIT $perPage OFFSET $offset")->fetchAll();

$pendingCount = db()->query("SELECT COUNT(*) FROM promotion_orders WHERE payment_status='pending'")->fetchColumn();

$pageTitle = 'Promotion Orders — Admin';
require_once __DIR__ . '/../includes/admin-header.php';
?>
<style>
.promo-badge{display:inline-block;padding:.2rem .6rem;border-radius:6px;font-size:.72rem;font-weight:600;background:rgba(109,40,217,.12);color:#7c3aed;}
</style>

<div class="admin-page-header">
  <div>
    <h1>Promotion Orders</h1>
    <p style="color:var(--muted);font-size:.875rem;">Manage business promotion payments</p>
  </div>
  <a href="<?= SITE_URL ?>/admin/promotion-products.php" class="btn btn-secondary btn-sm">⚙ Manage Products</a>
</div>

<?php if ($viewOrder): ?>
<!-- ═══ SINGLE VIEW ═══ -->
<div style="margin-bottom:1.5rem;">
  <a href="<?= SITE_URL ?>/admin/promotion-orders.php" style="color:var(--muted);text-decoration:none;font-size:.875rem;">← Back</a>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;align-items:start;">
  <div class="admin-card">
    <h3 style="margin-bottom:1rem;">Order Details</h3>
    <table style="width:100%;font-size:.875rem;border-collapse:collapse;">
      <?php $rows = [
        'Ref'          => e($viewOrder['ref']),
        'User'         => e($viewOrder['user_name']) . ' &lt;' . e($viewOrder['user_email']) . '&gt;',
        'Listing'      => e($viewOrder['listing_title']),
        'Product'      => e($viewOrder['prod_name_en']),
        'Promo Type'   => '<span class="promo-badge">' . e($viewOrder['promo_type']) . '</span>',
        'Amount'       => number_format($viewOrder['price_xaf']) . ' XAF',
        'Duration'     => $viewOrder['duration_days'] . ' days',
        'Submitted'    => date('d M Y H:i', strtotime($viewOrder['created_at'])),
        'Pay Status'   => ucfirst($viewOrder['payment_status']),
        'Promo Status' => ucfirst(str_replace('_', ' ', $viewOrder['promo_status'])),
      ];
      if ($viewOrder['starts_at']) $rows['Period'] = date('d M Y', strtotime($viewOrder['starts_at'])) . ' → ' . date('d M Y', strtotime($viewOrder['ends_at']));
      if ($viewOrder['approver_name']) $rows['Approved By'] = e($viewOrder['approver_name']);
      if ($viewOrder['partner_id']) $rows['Partner'] = '#' . $viewOrder['partner_id'] . ' (ref: ' . e($viewOrder['referral_code'] ?? '—') . ')';
      if ($viewOrder['rejection_reason']) $rows['Rejection'] = e($viewOrder['rejection_reason']);
      foreach ($rows as $label => $val): ?>
      <tr>
        <td style="padding:.5rem 0;border-bottom:1px solid var(--border);color:var(--muted);width:38%;"><?= $label ?></td>
        <td style="padding:.5rem 0;border-bottom:1px solid var(--border);"><?= $val ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div style="display:flex;flex-direction:column;gap:1rem;">
    <?php if ($viewOrder['proof']): ?>
    <div class="admin-card">
      <h4 style="margin-bottom:.75rem;">Payment Proof</h4>
      <?php $ext = strtolower(pathinfo($viewOrder['proof'], PATHINFO_EXTENSION));
            $proofUrl = UPLOAD_URL . '/' . $viewOrder['proof']; ?>
      <?php if ($ext === 'pdf'): ?>
        <a href="<?= $proofUrl ?>" target="_blank" class="btn btn-secondary btn-sm">📄 View PDF</a>
      <?php else: ?>
        <a href="<?= $proofUrl ?>" target="_blank">
          <img src="<?= $proofUrl ?>" alt="Proof" style="max-width:100%;border-radius:8px;border:1px solid var(--border);">
        </a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($viewOrder['payment_status'] === 'pending'): ?>
    <div class="admin-card" style="background:rgba(0,168,120,.05);border-color:rgba(0,168,120,.2);">
      <h4 style="color:#00A878;margin-bottom:.75rem;">✅ Approve</h4>
      <p style="color:var(--muted);font-size:.83rem;margin-bottom:1rem;">Activates promotion for <?= $viewOrder['duration_days'] ?> days immediately.</p>
      <form method="post">
        <?= csrf() ?>
        <input type="hidden" name="action" value="approve">
        <input type="hidden" name="order_id" value="<?= $viewOrder['id'] ?>">
        <button type="submit" class="btn btn-primary btn-sm">Approve &amp; Activate</button>
      </form>
    </div>

    <div class="admin-card" style="background:rgba(239,68,68,.05);border-color:rgba(239,68,68,.2);">
      <h4 style="color:#ef4444;margin-bottom:.75rem;">❌ Reject</h4>
      <form method="post">
        <?= csrf() ?>
        <input type="hidden" name="action" value="reject">
        <input type="hidden" name="order_id" value="<?= $viewOrder['id'] ?>">
        <div class="form-group" style="margin-bottom:.75rem;">
          <label style="font-size:.8rem;">Reason *</label>
          <textarea name="rejection_reason" rows="2" class="form-control" style="font-size:.875rem;"></textarea>
        </div>
        <button type="submit" class="btn btn-sm" style="background:#ef4444;color:#fff;">Reject</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php else: ?>
<!-- ═══ LIST ═══ -->
<div style="display:flex;gap:.5rem;margin-bottom:1.5rem;flex-wrap:wrap;">
  <?php foreach (['pending'=>'⏳ Pending','active'=>'✅ Active','rejected'=>'❌ Rejected','all'=>'All'] as $f => $label): ?>
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
  <p style="color:var(--muted);">No <?= $filter ?> promotion orders.</p>
</div>
<?php else: ?>
<div class="admin-card" style="padding:0;overflow:hidden;">
  <table class="admin-table" style="font-size:.83rem;">
    <thead>
      <tr>
        <th>Ref</th>
        <th>User</th>
        <th>Listing</th>
        <th>Product</th>
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
    <tr>
      <td><code style="font-size:.78rem;"><?= e($o['ref']) ?></code></td>
      <td>
        <div><?= e($o['user_name']) ?></div>
        <div style="font-size:.75rem;color:var(--muted);"><?= e($o['user_email']) ?></div>
      </td>
      <td style="max-width:140px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= e($o['listing_title']) ?></td>
      <td>
        <div><?= e($o['prod_name_en']) ?></div>
        <span class="promo-badge" style="margin-top:.2rem;"><?= e($o['promo_type']) ?></span>
      </td>
      <td style="white-space:nowrap;"><?= number_format($o['amount_xaf']) ?> XAF</td>
      <td>
        <span style="display:inline-flex;align-items:center;padding:.2rem .65rem;border-radius:99px;font-size:.72rem;font-weight:600;background:<?= $sc['bg'] ?>;color:<?= $sc['c'] ?>;">
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
          <button type="submit" class="btn btn-sm" style="background:#00A878;color:#fff;">✓</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
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
