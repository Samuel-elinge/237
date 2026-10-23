<?php
/**
 * admin/commissions.php — Phase 3 Commission Management
 *
 * Lists partner_commissions from subscription/promotion orders.
 * Admin can approve individual commissions, batch-approve pending,
 * create payout batches, and mark them paid.
 */
require_once __DIR__ . '/../includes/config.php';
requireAdmin();

$action = $_POST['action'] ?? '';

/* ── POST ACTIONS ─────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    /* ── Approve single commission ── */
    if ($action === 'approve') {
        $cid = (int)$_POST['commission_id'];
        db()->prepare("UPDATE partner_commissions SET status='approved' WHERE id=? AND status='pending'")
            ->execute([$cid]);
        flash('success', 'Commission approved.');
        redirect(SITE_URL . '/admin/commissions.php');
    }

    /* ── Batch approve all pending Phase 3 commissions ── */
    if ($action === 'batch_approve') {
        $updated = db()->exec("UPDATE partner_commissions
            SET status='approved'
            WHERE status='pending'
            AND order_type IN ('subscription','promotion')
            AND reversed_at IS NULL");
        flash('success', "Batch approved $updated commissions.");
        redirect(SITE_URL . '/admin/commissions.php');
    }

    /* ── Create payout batch ── */
    if ($action === 'create_batch') {
        // Find all approved commissions not in a batch, grouped by partner
        $toGroup = db()->query("SELECT pc.partner_id, SUM(pc.amount) AS total_xaf,
            COUNT(*) AS commission_count
            FROM partner_commissions pc
            WHERE pc.status='approved' AND pc.payout_batch_id IS NULL AND pc.reversed_at IS NULL
            GROUP BY pc.partner_id
            HAVING total_xaf > 0")->fetchAll();

        if (!$toGroup) {
            flash('error', 'No approved commissions to batch.');
            redirect(SITE_URL . '/admin/commissions.php');
        }

        $batchRef = 'BATCH-' . strtoupper(substr(md5(uniqid()), 0, 8));
        $adminId  = currentUser()['id'];

        $batchCount = 0;
        foreach ($toGroup as $row) {
            db()->prepare("INSERT INTO partner_payout_batches (ref, created_by, partner_id, total_xaf, status)
                VALUES (?,?,?,?,'pending')")
                ->execute([$batchRef . '-U' . $row['partner_id'], $adminId, $row['partner_id'], $row['total_xaf']]);
            $batchId = db()->lastInsertId();

            db()->prepare("UPDATE partner_commissions SET payout_batch_id=?, status='in_batch'
                WHERE partner_id=? AND status='approved' AND payout_batch_id IS NULL")
                ->execute([$batchId, $row['partner_id']]);
            $batchCount++;
        }

        flash('success', "Created $batchCount payout batch(es).");
        redirect(SITE_URL . '/admin/commissions.php?tab=batches');
    }

    /* ── Mark batch paid ── */
    if ($action === 'mark_batch_paid') {
        $batchId = (int)$_POST['batch_id'];
        $paidRef = trim($_POST['paid_ref'] ?? '');

        db()->prepare("UPDATE partner_payout_batches SET status='paid', paid_at=NOW(), payout_ref=? WHERE id=?")
            ->execute([$paidRef, $batchId]);
        db()->prepare("UPDATE partner_commissions SET status='paid' WHERE payout_batch_id=?")
            ->execute([$batchId]);

        // Notify partner
        $batchRow = db()->prepare("SELECT ppb.*, u.email, u.name FROM partner_payout_batches ppb
            JOIN users u ON u.id = ppb.partner_id WHERE ppb.id=?");
        $batchRow->execute([$batchId]);
        $batchRow = $batchRow->fetch();
        if ($batchRow) {
            sendMail($batchRow['email'],
                'Commission payout sent — ' . $batchRow['ref'],
                '<h2 style="color:#fff;font-family:Georgia,serif;">Commission Payout</h2>
                 <p style="color:rgba(255,255,255,0.7);">Hi ' . e($batchRow['name']) . ',</p>
                 <p style="color:rgba(255,255,255,0.7);">Your commission payout of <strong>' . number_format($batchRow['total_xaf']) . ' XAF</strong> has been sent.</p>
                 <p style="color:rgba(255,255,255,0.7);">Reference: ' . e($batchRow['ref']) . '</p>'
            );
        }

        flash('success', 'Batch marked as paid. Partner notified.');
        redirect(SITE_URL . '/admin/commissions.php?tab=batches');
    }

    /* ── Reverse commission ── */
    if ($action === 'reverse') {
        $cid    = (int)$_POST['commission_id'];
        $reason = trim($_POST['reason'] ?? 'Admin reversal');
        db()->prepare("UPDATE partner_commissions SET status='cancelled', reversed_at=NOW(), reversal_reason=? WHERE id=?")
            ->execute([$reason, $cid]);
        flash('success', 'Commission reversed.');
        redirect(SITE_URL . '/admin/commissions.php');
    }
}

/* ── DATA ─────────────────────────────────────────────────── */
$tab = $_GET['tab'] ?? 'commissions';

// Summary stats
$summaryStats = db()->query("SELECT
    SUM(CASE WHEN status='pending' THEN amount ELSE 0 END) AS pending_xaf,
    SUM(CASE WHEN status='approved' THEN amount ELSE 0 END) AS approved_xaf,
    SUM(CASE WHEN status='paid' THEN amount ELSE 0 END) AS paid_xaf,
    COUNT(CASE WHEN status='pending' THEN 1 END) AS pending_count,
    COUNT(CASE WHEN status='approved' THEN 1 END) AS approved_count
    FROM partner_commissions
    WHERE order_type IN ('subscription','promotion') AND reversed_at IS NULL")->fetch();

// Commission filter
$cfFilter = $_GET['cf'] ?? 'pending';
$cfPage   = max(1, (int)($_GET['cp'] ?? 1));
$cfPer    = 30;
$cfOffset = ($cfPage - 1) * $cfPer;

$allowedCfFilters = ['pending','approved','in_batch','paid','cancelled','all'];
if (!in_array($cfFilter, $allowedCfFilters, true)) $cfFilter = 'pending';
$cfWhere = "pc.order_type IN ('subscription','promotion') AND pc.reversed_at IS NULL";
if ($cfFilter !== 'all') $cfWhere .= " AND pc.status=" . db()->quote($cfFilter);

$cfTotal = db()->query("SELECT COUNT(*) FROM partner_commissions pc WHERE $cfWhere")->fetchColumn();
$commissions = db()->query("SELECT pc.*,
    u.name AS partner_name, u.email AS partner_email,
    l.title AS listing_title,
    mo.ref AS order_ref, mo.net_xaf AS gross_amount_xaf
    FROM partner_commissions pc
    JOIN users u ON u.id = pc.partner_id
    LEFT JOIN listings l ON l.id = pc.listing_id
    LEFT JOIN monetisation_orders mo ON mo.id = pc.order_id
    WHERE $cfWhere
    ORDER BY pc.created_at DESC
    LIMIT $cfPer OFFSET $cfOffset")->fetchAll();

// Payout batches
$batches = db()->query("SELECT ppb.*, u.name AS partner_name, u.email AS partner_email,
    creator.name AS created_by_name,
    COUNT(pc.id) AS commission_count
    FROM partner_payout_batches ppb
    JOIN users u ON u.id = ppb.partner_id
    LEFT JOIN users creator ON creator.id = ppb.created_by
    LEFT JOIN partner_commissions pc ON pc.payout_batch_id = ppb.id
    GROUP BY ppb.id
    ORDER BY ppb.created_at DESC LIMIT 50")->fetchAll();

$pageTitle = 'Commissions — Admin';
require_once __DIR__ . '/../includes/admin-header.php';
?>

<div class="admin-page-header">
  <div>
    <h1>Commission Management</h1>
    <p style="color:var(--muted);font-size:.875rem;">Phase 3 subscription & promotion commissions</p>
  </div>
  <div style="display:flex;gap:.5rem;">
    <?php if ($summaryStats['approved_count'] > 0): ?>
    <form method="post" style="display:inline;">
      <?= csrf() ?>
      <input type="hidden" name="action" value="create_batch">
      <button type="submit" class="btn btn-primary btn-sm">📦 Create Payout Batch (<?= $summaryStats['approved_count'] ?>)</button>
    </form>
    <?php endif; ?>
    <?php if ($summaryStats['pending_count'] > 0): ?>
    <form method="post" style="display:inline;" onsubmit="return confirm('Approve all <?= $summaryStats['pending_count'] ?> pending commissions?')">
      <?= csrf() ?>
      <input type="hidden" name="action" value="batch_approve">
      <button type="submit" class="btn btn-secondary btn-sm">✓ Batch Approve (<?= $summaryStats['pending_count'] ?>)</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<!-- Stats -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;margin-bottom:1.5rem;">
  <?php foreach ([
    ['Pending', number_format($summaryStats['pending_xaf'] ?: 0) . ' XAF', '⏳', '#f59e0b'],
    ['Approved (unpaid)', number_format($summaryStats['approved_xaf'] ?: 0) . ' XAF', '✅', '#00A878'],
    ['Paid Out', number_format($summaryStats['paid_xaf'] ?: 0) . ' XAF', '💰', '#3b82f6'],
  ] as [$label, $val, $icon, $color]): ?>
  <div style="background:var(--card);border:1px solid var(--border);border-radius:10px;padding:1rem;">
    <div style="font-size:.8rem;color:var(--muted);margin-bottom:.25rem;"><?= $icon ?> <?= $label ?></div>
    <div style="font-size:1.2rem;font-weight:900;font-family:'Fraunces',serif;color:<?= $color ?>;"><?= $val ?></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Tabs -->
<div style="display:flex;gap:.5rem;margin-bottom:1.25rem;border-bottom:1px solid var(--border);padding-bottom:.75rem;">
  <a href="?tab=commissions" class="btn btn-sm <?= $tab==='commissions' ? 'btn-primary' : 'btn-secondary' ?>">
    Commissions <?php if ($summaryStats['pending_count']): ?><span style="background:#ef4444;color:#fff;border-radius:99px;padding:.1rem .4rem;font-size:.7rem;margin-left:.3rem;"><?= $summaryStats['pending_count'] ?></span><?php endif; ?>
  </a>
  <a href="?tab=batches" class="btn btn-sm <?= $tab==='batches' ? 'btn-primary' : 'btn-secondary' ?>">Payout Batches</a>
</div>

<?php if ($tab === 'commissions'): ?>
<!-- Commission filter -->
<div style="display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:1rem;">
  <?php foreach (['pending'=>'Pending','approved'=>'Approved','in_batch'=>'In Batch','paid'=>'Paid','cancelled'=>'Cancelled','all'=>'All'] as $f => $lbl): ?>
  <a href="?tab=commissions&cf=<?= $f ?>" class="btn btn-sm <?= $cfFilter===$f ? 'btn-primary' : 'btn-secondary' ?>"><?= $lbl ?></a>
  <?php endforeach; ?>
</div>

<?php if (!$commissions): ?>
<div style="text-align:center;padding:2rem;background:var(--card);border:1px solid var(--border);border-radius:10px;">
  <p style="color:var(--muted);">No <?= $cfFilter ?> commissions.</p>
</div>
<?php else: ?>
<div class="admin-card" style="padding:0;overflow:hidden;">
  <table class="admin-table" style="font-size:.82rem;">
    <thead>
      <tr>
        <th>Partner</th>
        <th>Type</th>
        <th>Order Ref</th>
        <th>Listing</th>
        <th style="text-align:right;">Order Amt</th>
        <th style="text-align:right;" colspan="2">Commission</th>
        <th>Status</th>
        <th>Date</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($commissions as $c):
      $typeColors = ['subscription'=>['bg'=>'rgba(59,130,246,.12)','c'=>'#3b82f6'],'promotion'=>['bg'=>'rgba(109,40,217,.12)','c'=>'#7c3aed']];
      $tc = $typeColors[$c['order_type']] ?? ['bg'=>'rgba(107,114,128,.12)','c'=>'#6b7280'];
      $statusColors = ['pending'=>['bg'=>'rgba(245,158,11,.12)','c'=>'#f59e0b'],'approved'=>['bg'=>'rgba(0,168,120,.12)','c'=>'#00A878'],'paid'=>['bg'=>'rgba(0,168,120,.12)','c'=>'#00A878'],'in_batch'=>['bg'=>'rgba(59,130,246,.12)','c'=>'#3b82f6'],'cancelled'=>['bg'=>'rgba(239,68,68,.12)','c'=>'#ef4444']];
      $sc = $statusColors[$c['status']] ?? ['bg'=>'rgba(107,114,128,.12)','c'=>'#6b7280'];
    ?>
    <tr>
      <td>
        <div><?= e($c['partner_name']) ?></div>
        <div style="font-size:.73rem;color:var(--muted);"><?= e($c['partner_email']) ?></div>
      </td>
      <td><span style="display:inline-block;padding:.2rem .55rem;border-radius:5px;font-size:.72rem;font-weight:600;background:<?= $tc['bg'] ?>;color:<?= $tc['c'] ?>;"><?= ucfirst($c['order_type']) ?></span></td>
      <td><code style="font-size:.75rem;"><?= e($c['order_ref'] ?? '—') ?></code></td>
      <td style="max-width:120px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= e($c['listing_title'] ?? '—') ?></td>
      <td style="text-align:right;white-space:nowrap;"><?= $c['gross_amount_xaf'] ? number_format($c['gross_amount_xaf']) . ' XAF' : '—' ?></td>
      <td style="text-align:right;font-weight:700;color:var(--accent);white-space:nowrap;" colspan="2"><?= number_format($c['amount']) ?> <?= e($c['currency'] ?? 'XAF') ?></td>
      <td><span style="display:inline-block;padding:.2rem .55rem;border-radius:5px;font-size:.72rem;font-weight:600;background:<?= $sc['bg'] ?>;color:<?= $sc['c'] ?>;"><?= ucfirst(str_replace('_',' ',$c['status'])) ?></span></td>
      <td style="white-space:nowrap;color:var(--muted);"><?= date('d M Y', strtotime($c['created_at'])) ?></td>
      <td>
        <?php if ($c['status'] === 'pending'): ?>
        <form method="post" style="display:inline;">
          <?= csrf() ?>
          <input type="hidden" name="action" value="approve">
          <input type="hidden" name="commission_id" value="<?= $c['id'] ?>">
          <button type="submit" class="btn btn-sm" style="background:#00A878;color:#fff;padding:.2rem .6rem;">✓</button>
        </form>
        <?php endif; ?>
        <?php if (in_array($c['status'], ['pending','approved'])): ?>
        <button type="button" class="btn btn-sm btn-secondary" style="padding:.2rem .6rem;"
          onclick="document.getElementById('rev-form-<?= $c['id'] ?>').style.display='block'">↩</button>
        <form id="rev-form-<?= $c['id'] ?>" method="post" style="display:none;margin-top:.4rem;">
          <?= csrf() ?>
          <input type="hidden" name="action" value="reverse">
          <input type="hidden" name="commission_id" value="<?= $c['id'] ?>">
          <input type="text" name="reason" placeholder="Reason" class="form-control" style="font-size:.78rem;padding:.3rem .5rem;margin-bottom:.3rem;">
          <button type="submit" class="btn btn-sm" style="background:#ef4444;color:#fff;">Reverse</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php if ($cfTotal > $cfPer): ?>
<div style="display:flex;justify-content:center;gap:.5rem;margin-top:1rem;">
  <?php $pages = ceil($cfTotal / $cfPer);
  for ($i = 1; $i <= $pages; $i++): ?>
  <a href="?tab=commissions&cf=<?= $cfFilter ?>&cp=<?= $i ?>" class="btn btn-sm <?= $i===$cfPage ? 'btn-primary' : 'btn-secondary' ?>"><?= $i ?></a>
  <?php endfor; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php else: /* BATCHES TAB */ ?>
<?php if (!$batches): ?>
<div style="text-align:center;padding:2rem;background:var(--card);border:1px solid var(--border);border-radius:10px;">
  <p style="color:var(--muted);">No payout batches yet. Approve commissions and create a batch.</p>
</div>
<?php else: ?>
<div class="admin-card" style="padding:0;overflow:hidden;">
  <table class="admin-table" style="font-size:.83rem;">
    <thead>
      <tr>
        <th>Batch Ref</th>
        <th>Partner</th>
        <th style="text-align:right;">Amount</th>
        <th style="text-align:right;">Count</th>
        <th>Status</th>
        <th>Created</th>
        <th>Paid At</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($batches as $b):
      $bsc = ['pending'=>['bg'=>'rgba(245,158,11,.12)','c'=>'#f59e0b'],'paid'=>['bg'=>'rgba(0,168,120,.12)','c'=>'#00A878'],'cancelled'=>['bg'=>'rgba(239,68,68,.12)','c'=>'#ef4444']];
      $bc = $bsc[$b['status']] ?? $bsc['pending'];
    ?>
    <tr>
      <td><code style="font-size:.78rem;"><?= e($b['ref']) ?></code></td>
      <td>
        <div><?= e($b['partner_name']) ?></div>
        <div style="font-size:.73rem;color:var(--muted);"><?= e($b['partner_email']) ?></div>
      </td>
      <td style="text-align:right;font-weight:700;color:var(--accent);"><?= number_format($b['total_xaf']) ?> XAF</td>
      <td style="text-align:right;"><?= $b['commission_count'] ?></td>
      <td><span style="display:inline-block;padding:.2rem .55rem;border-radius:5px;font-size:.72rem;font-weight:600;background:<?= $bc['bg'] ?>;color:<?= $bc['c'] ?>;"><?= ucfirst($b['status']) ?></span></td>
      <td style="white-space:nowrap;color:var(--muted);"><?= date('d M Y', strtotime($b['created_at'])) ?></td>
      <td style="white-space:nowrap;color:var(--muted);"><?= $b['paid_at'] ? date('d M Y', strtotime($b['paid_at'])) : '—' ?></td>
      <td>
        <?php if ($b['status'] === 'pending'): ?>
        <button type="button" class="btn btn-sm btn-primary"
          onclick="document.getElementById('pay-form-<?= $b['id'] ?>').style.display='block'">Mark Paid</button>
        <form id="pay-form-<?= $b['id'] ?>" method="post" style="display:none;margin-top:.4rem;">
          <?= csrf() ?>
          <input type="hidden" name="action" value="mark_batch_paid">
          <input type="hidden" name="batch_id" value="<?= $b['id'] ?>">
          <input type="text" name="paid_ref" placeholder="Transfer ref (optional)" class="form-control" style="font-size:.78rem;padding:.3rem .5rem;margin-bottom:.3rem;">
          <button type="submit" class="btn btn-sm" style="background:#00A878;color:#fff;">Confirm Paid</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>
