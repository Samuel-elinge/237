<?php
require_once __DIR__ . '/../includes/config.php';
requireAdmin();

$action = $_POST['action'] ?? '';
$id     = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

/* ── WRITE ACTIONS ─────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie();

    // Approve: mark paid, calculate expiry, insert addon_entitlement
    if ($action === 'approve' && $id) {
        $db = db();

        // Fetch the order + addon details
        $st = $db->prepare("
            SELECT ao.*, pa.addon_type, pa.quantity, pa.expiry_policy, pa.expiry_days,
                   bs.ends_at AS sub_ends_at
            FROM addon_orders ao
            JOIN plan_addons pa ON pa.id = ao.addon_id
            LEFT JOIN business_subscriptions bs ON bs.listing_id = ao.listing_id
                AND bs.status = 'active' AND (bs.ends_at IS NULL OR bs.ends_at > NOW())
            WHERE ao.id = ?
            LIMIT 1
        ");
        $st->execute([$id]);
        $order = $st->fetch();

        if (!$order) {
            flash('error', 'Order not found.');
            redirect(SITE_URL . '/admin/addon-orders.php');
        }
        if ($order['payment_status'] === 'paid') {
            flash('error', 'Order already approved.');
            redirect(SITE_URL . '/admin/addon-orders.php');
        }

        // Calculate expiry
        $activatedAt = date('Y-m-d H:i:s');
        $expiresAt   = null;
        if ($order['expiry_policy'] === 'subscription_end') {
            $expiresAt = $order['sub_ends_at']; // null = no expiry (lifetime sub)
        } elseif ($order['expiry_policy'] === 'fixed_days' && $order['expiry_days']) {
            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$order['expiry_days']} days"));
        }
        // 'never' → expiresAt stays null

        // Update order
        $db->prepare("UPDATE addon_orders SET
            payment_status='paid', activated_at=?, expires_at=?, notes=CONCAT(IFNULL(notes,''),' [Approved by admin]')
            WHERE id=?")
           ->execute([$activatedAt, $expiresAt, $id]);

        // capacity = units_bought × addon_capacity_per_unit
        $totalCapacity = (int)$order['quantity'] * (int)$order['addon_qty'];

        // Insert or replace entitlement (idempotent via UNIQUE on addon_order_id)
        $db->prepare("INSERT INTO addon_entitlements
            (listing_id, addon_order_id, addon_type, capacity, activated_at, expires_at)
            VALUES (?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
                capacity=VALUES(capacity), activated_at=VALUES(activated_at), expires_at=VALUES(expires_at)")
           ->execute([$order['listing_id'], $id, $order['addon_type'],
                      $totalCapacity, $activatedAt, $expiresAt]);

        // Notify listing owner
        $owner = $db->prepare("SELECT u.email, u.name, l.business_name
            FROM listings l JOIN users u ON u.id = l.user_id WHERE l.id=?");
        $owner->execute([$order['listing_id']]);
        $ownerRow = $owner->fetch();
        if ($ownerRow) {
            sendMail($ownerRow['email'], t('Your add-on is now active!', 'Votre extension est maintenant active !'),
                t("Hi {$ownerRow['name']},\n\nYour add-on order #{$order['ref']} has been approved and is now active on your listing \"{$ownerRow['business_name']}\".\n\nYou can now publish additional " . $order['addon_type'] . " up to your new limit.\n\nThe 237Biz Team",
                  "Bonjour {$ownerRow['name']},\n\nVotre commande d'extension #{$order['ref']} a été approuvée et est maintenant active sur votre annonce \"{$ownerRow['business_name']}\".\n\nVous pouvez désormais publier davantage de " . $order['addon_type'] . " jusqu'à votre nouvelle limite.\n\nL'équipe 237Biz")
            );
        }

        flash('success', "Order #{$order['ref']} approved — entitlement activated.");
        redirect(SITE_URL . '/admin/addon-orders.php');
    }

    // Reject / cancel
    if ($action === 'reject' && $id) {
        $notes = trim($_POST['notes'] ?? '');
        db()->prepare("UPDATE addon_orders SET payment_status='cancelled',
            notes=CONCAT(IFNULL(notes,''), ' [Rejected: ', ?, ']') WHERE id=?")
           ->execute([$notes ?: 'no reason given', $id]);

        // Remove any entitlement row (in case of double-click)
        db()->prepare("DELETE FROM addon_entitlements WHERE addon_order_id=?")->execute([$id]);

        flash('success', 'Order rejected and entitlement removed.');
        redirect(SITE_URL . '/admin/addon-orders.php');
    }

    // Revoke active entitlement
    if ($action === 'revoke' && $id) {
        db()->prepare("UPDATE addon_orders SET payment_status='refunded',
            notes=CONCAT(IFNULL(notes,''), ' [Revoked by admin]') WHERE id=?")
           ->execute([$id]);
        db()->prepare("DELETE FROM addon_entitlements WHERE addon_order_id=?")->execute([$id]);
        flash('success', 'Entitlement revoked.');
        redirect(SITE_URL . '/admin/addon-orders.php');
    }
}

/* ── READ ──────────────────────────────────────────────────── */
$filter = $_GET['status'] ?? 'pending_payment';
$allowed = ['pending_payment','paid','cancelled','refunded','all'];
if (!in_array($filter, $allowed)) $filter = 'pending_payment';

$where = $filter === 'all' ? '' : "WHERE ao.payment_status = " . db()->quote($filter);

$orders = db()->query("
    SELECT ao.*,
           pa.name_en AS addon_name, pa.addon_type, pa.quantity AS addon_qty,
           l.business_name, l.id AS lid,
           u.name AS owner_name, u.email AS owner_email
    FROM addon_orders ao
    JOIN plan_addons pa ON pa.id = ao.addon_id
    JOIN listings l ON l.id = ao.listing_id
    JOIN users u ON u.id = ao.user_id
    {$where}
    ORDER BY ao.created_at DESC
    LIMIT 200
")->fetchAll();

$counts = db()->query("
    SELECT payment_status, COUNT(*) AS n FROM addon_orders GROUP BY payment_status
")->fetchAll(PDO::FETCH_KEY_PAIR);

$pageTitle = 'Add-on Orders — Admin';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.order-card{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:1rem 1.25rem;margin-bottom:.75rem;}
.badge-pill{display:inline-block;padding:2px 10px;border-radius:99px;font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;}
.badge-pending{background:#fef9c3;color:#854d0e;}
.badge-paid{background:#dcfce7;color:#15803d;}
.badge-cancelled{background:#fee2e2;color:#991b1b;}
.badge-refunded{background:#f3f4f6;color:#6b7280;}
.order-meta{display:flex;flex-wrap:wrap;gap:.4rem 1.5rem;font-size:.82rem;color:var(--muted);margin:.5rem 0;}
.order-meta strong{color:var(--fg);}
.filter-tabs{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.5rem;}
.filter-tab{padding:.3rem .9rem;border-radius:99px;font-size:.82rem;text-decoration:none;border:1px solid var(--border);color:var(--fg);}
.filter-tab.active{background:var(--accent);color:#fff;border-color:var(--accent);}
.proof-thumb{max-width:180px;border-radius:6px;border:1px solid var(--border);cursor:pointer;}
</style>

<div class="page-header">
  <div class="container">
    <nav class="breadcrumb"><a href="<?= SITE_URL ?>/admin">Admin</a> › Add-on Orders</nav>
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.6rem,3vw,2.4rem);">
      🛒 Add-on Orders
    </h1>
    <p style="color:var(--muted);">Review, approve or reject add-on purchase requests.</p>
  </div>
</div>

<section class="page-section">
<div class="container">

<?php if ($flash = flash()): ?>
  <div class="alert alert-<?= $flash['type'] ?>" style="margin-bottom:1.5rem;"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<!-- FILTER TABS -->
<div class="filter-tabs">
  <?php
  $tabs = ['pending_payment' => '⏳ Pending', 'paid' => '✅ Paid/Active', 'cancelled' => '❌ Cancelled', 'refunded' => '↩️ Revoked', 'all' => 'All'];
  foreach ($tabs as $val => $label):
    $n = ($val === 'all') ? array_sum($counts) : ($counts[$val] ?? 0);
  ?>
    <a href="?status=<?= $val ?>" class="filter-tab <?= $filter === $val ? 'active' : '' ?>">
      <?= $label ?> <span style="opacity:.7;">(<?= $n ?>)</span>
    </a>
  <?php endforeach; ?>
</div>

<!-- ORDERS LIST -->
<?php if ($orders): foreach ($orders as $o): ?>
<div class="order-card">
  <div style="display:flex;align-items:flex-start;gap:.75rem;flex-wrap:wrap;">
    <div style="flex:1;min-width:240px;">
      <div style="font-weight:700;font-size:.95rem;">
        <?= e($o['business_name']) ?>
        <span style="font-weight:400;color:var(--muted);font-size:.85rem;">— <?= e($o['owner_name']) ?> (<?= e($o['owner_email']) ?>)</span>
      </div>
      <div class="order-meta">
        <span><strong>Ref:</strong> <?= e($o['ref']) ?></span>
        <span><strong>Add-on:</strong> <?= e($o['addon_name']) ?> (+<?= $o['addon_qty'] * $o['quantity'] ?> <?= $o['addon_type'] ?>)</span>
        <span><strong>Units bought:</strong> <?= $o['quantity'] ?></span>
        <span><strong>Total:</strong> <?= number_format($o['total_xaf']) ?> FCFA</span>
        <span><strong>Method:</strong> <?= e($o['payment_method'] ?? '—') ?></span>
        <span><strong>Ordered:</strong> <?= date('d M Y H:i', strtotime($o['created_at'])) ?></span>
        <?php if ($o['activated_at']): ?>
          <span><strong>Activated:</strong> <?= date('d M Y', strtotime($o['activated_at'])) ?></span>
        <?php endif; ?>
        <?php if ($o['expires_at']): ?>
          <span><strong>Expires:</strong> <?= date('d M Y', strtotime($o['expires_at'])) ?></span>
        <?php endif; ?>
      </div>
      <?php if ($o['notes']): ?>
        <p style="font-size:.8rem;color:var(--muted);margin:.25rem 0 0;"><?= e($o['notes']) ?></p>
      <?php endif; ?>
    </div>

    <div style="display:flex;flex-direction:column;align-items:flex-end;gap:.5rem;">
      <span class="badge-pill badge-<?= str_replace('_payment','',$o['payment_status']) === 'pending' ? 'pending' : $o['payment_status'] ?>">
        <?= match($o['payment_status']) {
          'pending_payment' => '⏳ Awaiting payment',
          'paid'            => '✅ Paid / Active',
          'cancelled'       => '❌ Cancelled',
          'refunded'        => '↩️ Revoked',
          default           => $o['payment_status'],
        } ?>
      </span>

      <?php if ($o['proof']): ?>
        <a href="<?= UPLOAD_URL . e($o['proof']) ?>" target="_blank">
          <img src="<?= UPLOAD_URL . e($o['proof']) ?>" class="proof-thumb" alt="Payment proof">
        </a>
      <?php endif; ?>

      <?php if ($o['payment_status'] === 'pending_payment'): ?>
        <div style="display:flex;gap:.4rem;">
          <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="id" value="<?= $o['id'] ?>">
            <button class="btn btn-primary btn-sm">✅ Approve</button>
          </form>
          <form method="post" style="display:flex;gap:.25rem;align-items:center;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="id" value="<?= $o['id'] ?>">
            <input type="text" name="notes" class="form-input" style="width:130px;font-size:.8rem;padding:.25rem .5rem;" placeholder="Reason…">
            <button class="btn btn-sm" style="background:#fee2e2;color:#991b1b;">❌ Reject</button>
          </form>
        </div>
      <?php elseif ($o['payment_status'] === 'paid'): ?>
        <form method="post" onsubmit="return confirm('Revoke this entitlement?')">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="revoke">
          <input type="hidden" name="id" value="<?= $o['id'] ?>">
          <button class="btn btn-sm" style="background:#fef3c7;color:#92400e;">↩️ Revoke</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; else: ?>
  <p style="color:var(--muted);">No orders found for this filter.</p>
<?php endif; ?>

</div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
