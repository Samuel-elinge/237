<?php
/**
 * admin/manage-referrals.php — 237Biz
 * Admin: set commission rates, view and approve/pay commission ledger.
 */
require_once __DIR__ . '/../includes/config.php';
if (file_exists(__DIR__ . '/../includes/referral-helpers.php')) {
    require_once __DIR__ . '/../includes/referral-helpers.php';
}
requireAdmin();
$pdo = db();

if (!function_exists('formatXaf')) {
    function formatXaf(int $amount): string { return number_format($amount) . ' XAF'; }
}
if (!function_exists('commissionStatusBadge')) {
    function commissionStatusBadge(string $status): string {
        $map = [
            'pending'   => ['⏳','rgba(252,209,22,0.15)','#fcd116'],
            'approved'  => ['✅','rgba(0,168,120,0.15)','#00A878'],
            'paid'      => ['💰','rgba(138,180,248,0.15)','#8ab4f8'],
            'cancelled' => ['✕','rgba(206,17,38,0.12)','#ff6b7a'],
        ];
        [$icon,$bg,$color] = $map[$status] ?? ['?','rgba(255,255,255,0.05)','var(--muted)'];
        return "<span style=\"background:{$bg};color:{$color};border-radius:99px;padding:2px 10px;font-size:11.5px;font-weight:700;\">{$icon} " . ucfirst($status) . "</span>";
    }
}

$tab = $_GET['tab'] ?? 'ledger';

// ── Handle POST actions ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // Update programme rates
    if ($action === 'update_programme') {
        $pid = (int)($_POST['programme_id'] ?? 1);
        try {
            $pdo->prepare("UPDATE referral_programmes SET
                commission_free_listing=?, commission_featured_listing=?, commission_upgrade=?,
                minimum_payout=?, cookie_days=?, updated_at=NOW() WHERE id=?
            ")->execute([
                (int)($_POST['commission_free']     ?? 0),
                (int)($_POST['commission_featured'] ?? 0),
                (int)($_POST['commission_upgrade']  ?? 0),
                (int)($_POST['minimum_payout']      ?? 5000),
                (int)($_POST['cookie_days']         ?? 30),
                $pid,
            ]);
            flash('success', 'Commission rates updated.');
        } catch (Exception $e) {
            flash('error', 'Could not save rates. Ensure the referral_programmes table exists.');
        }
        redirect(SITE_URL . '/admin/manage-referrals.php?tab=settings');
    }

    // Approve commission
    if ($action === 'approve_commission') {
        $cid = (int)($_POST['commission_id'] ?? 0);
        $u   = currentUser();
        try {
            $pdo->prepare("UPDATE commissions SET status='approved', approved_at=NOW(), approved_by=? WHERE id=? AND status='pending'")
                ->execute([$u['id'], $cid]);
            flash('success', 'Commission approved.');
        } catch (Exception $e) { flash('error', 'Could not approve commission.'); }
        redirect(SITE_URL . '/admin/manage-referrals.php?tab=ledger');
    }

    // Mark paid
    if ($action === 'mark_paid') {
        $cid = (int)($_POST['commission_id'] ?? 0);
        $ref = trim($_POST['payment_ref'] ?? '');
        $u   = currentUser();
        try {
            $pdo->prepare("UPDATE commissions SET status='paid', paid_at=NOW(), paid_by=?, payment_ref=? WHERE id=? AND status='approved'")
                ->execute([$u['id'], $ref, $cid]);
            flash('success', 'Payment recorded.');
        } catch (Exception $e) { flash('error', 'Could not record payment.'); }
        redirect(SITE_URL . '/admin/manage-referrals.php?tab=ledger');
    }

    // Bulk approve all pending for a user
    if ($action === 'bulk_approve') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $u   = currentUser();
        if ($uid) {
            try {
                $pdo->prepare("UPDATE commissions SET status='approved', approved_at=NOW(), approved_by=? WHERE user_id=? AND status='pending'")
                    ->execute([$u['id'], $uid]);
                flash('success', 'All pending commissions approved.');
            } catch (Exception $e) { flash('error', 'Could not bulk approve.'); }
        }
        redirect(SITE_URL . '/admin/manage-referrals.php?tab=ledger');
    }

    // Cancel
    if ($action === 'cancel_commission') {
        $cid = (int)($_POST['commission_id'] ?? 0);
        try {
            $pdo->prepare("UPDATE commissions SET status='cancelled' WHERE id=? AND status='pending'")->execute([$cid]);
            flash('success', 'Commission cancelled.');
        } catch (Exception $e) { flash('error', 'Could not cancel commission.'); }
        redirect(SITE_URL . '/admin/manage-referrals.php?tab=ledger');
    }
}

// ── Load data ────────────────────────────────────────────────────────────

// Programme settings
$prog = null;
try { $prog = $pdo->query("SELECT * FROM referral_programmes WHERE id=1")->fetch(); } catch (Exception $e) {}
if (!$prog) $prog = ['commission_free_listing'=>0,'commission_featured_listing'=>0,'commission_upgrade'=>0,'minimum_payout'=>5000,'cookie_days'=>30,'id'=>1];

// Commission ledger
$statusFilter = $_GET['status'] ?? 'all';
$whereStatus  = ($statusFilter !== 'all') ? "AND c.status='{$statusFilter}'" : '';

$ledger = [];
try {
    $ledger = $pdo->query("
        SELECT c.*, u.name AS user_name, u.email AS user_email, u.role AS user_role
        FROM commissions c
        JOIN users u ON u.id = c.user_id
        WHERE 1=1 {$whereStatus}
        ORDER BY c.created_at DESC
        LIMIT 200
    ")->fetchAll();
} catch (Exception $e) { $ledger = []; }

// Summary by user
$summary = [];
try {
    $summary = $pdo->query("
        SELECT u.id, u.name, u.role, u.email,
            SUM(CASE WHEN c.status='pending'  THEN c.amount_xaf ELSE 0 END) AS pending_xaf,
            SUM(CASE WHEN c.status='approved' THEN c.amount_xaf ELSE 0 END) AS approved_xaf,
            SUM(CASE WHEN c.status='paid'     THEN c.amount_xaf ELSE 0 END) AS paid_xaf,
            COUNT(*) AS entries
        FROM commissions c JOIN users u ON u.id=c.user_id
        GROUP BY u.id ORDER BY approved_xaf DESC
    ")->fetchAll();
} catch (Exception $e) { $summary = []; }

// Conversion stats
try {
    $convStats = $pdo->query("
        SELECT conversion_type, COUNT(*) AS cnt, SUM(commission_xaf) AS total_xaf
        FROM referral_conversions GROUP BY conversion_type
    ")->fetchAll();
} catch (Exception $e) { $convStats = []; }

$pageTitle = 'Manage Referrals & Commissions — Admin — 237Biz';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.ref-stat { background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.08); border-radius:12px; padding:16px 20px; }
.ref-stat .val { font-size:1.8rem; font-weight:900; font-family:'Fraunces',serif; }
.ref-stat .lbl { font-size:12px; color:var(--muted); text-transform:uppercase; letter-spacing:.05em; margin-bottom:4px; }
.ledger-table { width:100%; border-collapse:collapse; }
.ledger-table th, .ledger-table td { padding:10px 12px; text-align:left; font-size:13px; border-bottom:1px solid rgba(255,255,255,0.05); }
.ledger-table th { font-size:11.5px; color:var(--muted); text-transform:uppercase; letter-spacing:.05em; }
.ledger-table tr:hover td { background:rgba(255,255,255,0.02); }
.rate-card { background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.08); border-radius:12px; padding:22px 24px; margin-bottom:16px; }
.rate-card h3 { margin:0 0 14px; font-size:15px; }
.rate-row { display:flex; align-items:center; gap:12px; margin-bottom:12px; flex-wrap:wrap; }
.rate-row label { flex:1; min-width:200px; font-size:13.5px; color:rgba(255,255,255,0.8); }
.rate-input { width:140px; padding:8px 12px; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); border-radius:8px; color:var(--white); font-size:14px; text-align:right; font-family:inherit; }
.summary-card { background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.08); border-radius:10px; padding:14px 16px; margin-bottom:8px; display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
.pay-form { display:inline-flex; gap:6px; align-items:center; }
.pay-input { padding:5px 10px; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); border-radius:6px; color:var(--white); font-size:12.5px; width:160px; font-family:inherit; }
</style>

<div class="page-header">
  <div class="container">
    <nav class="breadcrumb"><a href="<?= SITE_URL ?>/admin/">Admin</a> › <span>Referrals & Commissions</span></nav>
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.5rem,3vw,2rem);">💰 Referrals & Commissions</h1>
  </div>
</div>

<section class="page-section" style="padding-top:1.25rem;">
<div class="container">

  <!-- Tabs -->
  <div class="filter-tabs" style="margin-bottom:1.5rem;flex-wrap:wrap;">
    <a href="?tab=ledger"   class="filter-tab <?= $tab==='ledger'   ?'active':'' ?>">📋 Commission Ledger</a>
    <a href="?tab=summary"  class="filter-tab <?= $tab==='summary'  ?'active':'' ?>">👤 By Person</a>
    <a href="?tab=settings" class="filter-tab <?= $tab==='settings' ?'active':'' ?>">⚙️ Commission Rates</a>
  </div>

  <?php if ($tab === 'settings'): ?>
  <!-- Settings -->
  <div class="rate-card">
    <h3>⚙️ Commission Rates</h3>
    <p style="font-size:13px;color:var(--muted);margin-bottom:16px;">Set how much (in XAF) is earned for each type of referral conversion. Applies to both agents and creators using the default programme.</p>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="action" value="update_programme">
      <input type="hidden" name="programme_id" value="1">

      <div class="rate-row">
        <label>🆓 Free listing referral <span style="font-size:11px;color:var(--muted);">(when someone adds a free listing)</span></label>
        <input class="rate-input" type="number" name="commission_free" value="<?= (int)($prog['commission_free_listing'] ?? 0) ?>" min="0" step="1">
        <span style="font-size:13px;color:var(--muted);">XAF</span>
      </div>
      <div class="rate-row">
        <label>⭐ Featured listing referral <span style="font-size:11px;color:var(--muted);">(when someone adds a paid listing)</span></label>
        <input class="rate-input" type="number" name="commission_featured" value="<?= (int)($prog['commission_featured_listing'] ?? 0) ?>" min="0" step="1">
        <span style="font-size:13px;color:var(--muted);">XAF</span>
      </div>
      <div class="rate-row">
        <label>⬆️ Upgrade commission <span style="font-size:11px;color:var(--muted);">(when a free listing upgrades to featured)</span></label>
        <input class="rate-input" type="number" name="commission_upgrade" value="<?= (int)($prog['commission_upgrade'] ?? 0) ?>" min="0" step="1">
        <span style="font-size:13px;color:var(--muted);">XAF</span>
      </div>

      <div style="border-top:1px solid rgba(255,255,255,0.07);margin:16px 0;"></div>

      <div class="rate-row">
        <label>💸 Minimum payout <span style="font-size:11px;color:var(--muted);">(min balance before payment is processed)</span></label>
        <input class="rate-input" type="number" name="minimum_payout" value="<?= (int)($prog['minimum_payout'] ?? 5000) ?>" min="0" step="1">
        <span style="font-size:13px;color:var(--muted);">XAF</span>
      </div>
      <div class="rate-row">
        <label>🍪 Attribution cookie <span style="font-size:11px;color:var(--muted);">(days referral click is valid)</span></label>
        <input class="rate-input" type="number" name="cookie_days" value="<?= (int)($prog['cookie_days'] ?? 30) ?>" min="1" max="365">
        <span style="font-size:13px;color:var(--muted);">days</span>
      </div>

      <button type="submit" class="btn btn-primary" style="margin-top:8px;">💾 Save Rates</button>
    </form>
  </div>

  <!-- Conversion stats -->
  <?php if ($convStats): ?>
  <div class="rate-card">
    <h3>📊 Conversion Overview</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;">
      <?php foreach ($convStats as $s): ?>
      <div class="ref-stat">
        <div class="lbl"><?= str_replace('_',' ',ucfirst($s['conversion_type'])) ?></div>
        <div class="val"><?= (int)$s['cnt'] ?></div>
        <div style="font-size:12px;color:#00A878;margin-top:2px;"><?= formatXaf((int)$s['total_xaf']) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php elseif ($tab === 'summary'): ?>
  <!-- By person summary -->
  <p style="font-size:13px;color:var(--muted);margin-bottom:16px;">Showing all agents and creators with commission entries.</p>
  <?php if (empty($summary)): ?>
  <p style="color:var(--muted);">No commission entries yet.</p>
  <?php else: ?>
  <?php foreach ($summary as $s): ?>
  <div class="summary-card">
    <div style="flex:1;min-width:160px;">
      <div style="font-weight:700;font-size:14px;"><?= e($s['name']) ?></div>
      <div style="font-size:12px;color:var(--muted);"><?= e($s['email']) ?> · <?= ucfirst(str_replace('_',' ',$s['role'])) ?></div>
    </div>
    <div style="text-align:center;">
      <div style="font-size:11px;color:var(--muted);">Pending</div>
      <div style="font-size:14px;font-weight:700;color:#fcd116;"><?= formatXaf((int)$s['pending_xaf']) ?></div>
    </div>
    <div style="text-align:center;">
      <div style="font-size:11px;color:var(--muted);">Approved</div>
      <div style="font-size:14px;font-weight:700;color:#00A878;"><?= formatXaf((int)$s['approved_xaf']) ?></div>
    </div>
    <div style="text-align:center;">
      <div style="font-size:11px;color:var(--muted);">Paid out</div>
      <div style="font-size:14px;font-weight:700;color:#8ab4f8;"><?= formatXaf((int)$s['paid_xaf']) ?></div>
    </div>
    <?php if ((int)$s['pending_xaf'] > 0): ?>
    <form method="POST" style="display:inline;">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="action" value="bulk_approve">
      <input type="hidden" name="user_id" value="<?= $s['id'] ?>">
      <button type="submit" class="btn btn-outline" style="font-size:12px;padding:6px 14px;" onclick="return confirm('Approve all pending commissions for <?= e($s['name']) ?>?')">
        ✅ Approve all
      </button>
    </form>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <?php else: ?>
  <!-- Ledger -->
  <div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;align-items:center;">
    <?php foreach (['all','pending','approved','paid','cancelled'] as $s): ?>
    <a href="?tab=ledger&status=<?= $s ?>"
       style="padding:5px 14px;border-radius:6px;font-size:12.5px;font-weight:600;text-decoration:none;<?= ($statusFilter===$s)?'background:rgba(0,168,120,0.2);color:#00A878;border:1px solid rgba(0,168,120,0.4);':'color:var(--muted);border:1px solid rgba(255,255,255,0.1);' ?>">
      <?= ucfirst($s) ?>
    </a>
    <?php endforeach; ?>
  </div>

  <?php if (empty($ledger)): ?>
  <p style="color:var(--muted);">No commission entries.</p>
  <?php else: ?>
  <div style="overflow-x:auto;">
  <table class="ledger-table">
    <thead>
      <tr>
        <th>Person</th><th>Type</th><th>Amount</th><th>Description</th><th>Status</th><th>Date</th><th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($ledger as $row): ?>
    <tr>
      <td>
        <div style="font-weight:600;"><?= e($row['user_name']) ?></div>
        <div style="font-size:11px;color:var(--muted);"><?= ucfirst(str_replace('_',' ',$row['user_role'])) ?></div>
      </td>
      <td style="font-size:12.5px;"><?= str_replace('_',' ',ucfirst($row['type'])) ?></td>
      <td style="font-weight:700;font-family:'Fraunces',serif;color:#00A878;"><?= formatXaf((int)$row['amount_xaf']) ?></td>
      <td style="font-size:12.5px;color:var(--muted);"><?= e($row['description'] ?? '') ?></td>
      <td><?= commissionStatusBadge($row['status']) ?></td>
      <td style="font-size:12px;color:var(--muted);"><?= date('d M Y', strtotime($row['created_at'])) ?></td>
      <td>
        <?php if ($row['status'] === 'pending'): ?>
        <div style="display:flex;gap:5px;flex-wrap:wrap;">
          <form method="POST" style="display:inline;">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="approve_commission">
            <input type="hidden" name="commission_id" value="<?= $row['id'] ?>">
            <button type="submit" style="background:rgba(0,168,120,0.2);border:1px solid rgba(0,168,120,0.4);color:#00A878;border-radius:6px;padding:4px 12px;font-size:12px;cursor:pointer;font-family:inherit;">✅ Approve</button>
          </form>
          <form method="POST" style="display:inline;">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="cancel_commission">
            <input type="hidden" name="commission_id" value="<?= $row['id'] ?>">
            <button type="submit" style="background:rgba(206,17,38,0.1);border:1px solid rgba(206,17,38,0.3);color:#ff6b7a;border-radius:6px;padding:4px 12px;font-size:12px;cursor:pointer;font-family:inherit;">✕</button>
          </form>
        </div>
        <?php elseif ($row['status'] === 'approved'): ?>
        <form method="POST" class="pay-form">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <input type="hidden" name="action" value="mark_paid">
          <input type="hidden" name="commission_id" value="<?= $row['id'] ?>">
          <input type="text" name="payment_ref" class="pay-input" placeholder="MoMo ref #" required>
          <button type="submit" style="background:rgba(138,180,248,0.2);border:1px solid rgba(138,180,248,0.4);color:#8ab4f8;border-radius:6px;padding:4px 12px;font-size:12px;cursor:pointer;font-family:inherit;">💰 Paid</button>
        </form>
        <?php elseif ($row['status'] === 'paid'): ?>
        <span style="font-size:11.5px;color:var(--muted);"><?= e($row['payment_ref'] ?? '') ?></span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
  <?php endif; ?>

</div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
