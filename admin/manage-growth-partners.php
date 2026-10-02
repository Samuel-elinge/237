<?php
/**
 * admin/manage-growth-partners.php — Manage Growth Partners
 */
require_once __DIR__ . '/../includes/config.php';
requireAdmin();

$pdo = db();
$tab = $_GET['tab'] ?? 'partners';
$msg = '';

// ── Handle POST actions ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'approve_partner') {
        $pid = (int)$_POST['partner_id'];
        $pdo->prepare("UPDATE partner_profiles SET status='approved', approved_by=?, approved_at=NOW() WHERE id=?")
            ->execute([$_SESSION['user_id'], $pid]);
        // Also update user role
        $pdo->prepare("UPDATE users u JOIN partner_profiles pp ON pp.user_id=u.id SET u.role='growth_partner' WHERE pp.id=?")
            ->execute([$pid]);
        $msg = 'Partner approved successfully.';
    }

    if ($action === 'suspend_partner') {
        $pid = (int)$_POST['partner_id'];
        $pdo->prepare("UPDATE partner_profiles SET status='suspended' WHERE id=?")->execute([$pid]);
        $msg = 'Partner suspended.';
    }

    if ($action === 'reactivate_partner') {
        $pid = (int)$_POST['partner_id'];
        $pdo->prepare("UPDATE partner_profiles SET status='active' WHERE id=?")->execute([$pid]);
        $msg = 'Partner reactivated.';
    }

    if ($action === 'assign_business') {
        $pid       = (int)$_POST['partner_id'];
        $lid       = (int)$_POST['listing_id'];
        $role      = in_array($_POST['assign_role'] ?? '', ['primary','supporting']) ? $_POST['assign_role'] : 'primary';
        $notes     = trim($_POST['notes'] ?? '');
        // Upsert: if removed/inactive reassign; else insert
        $existing = $pdo->prepare("SELECT id, status FROM partner_business_assignments WHERE partner_id=? AND listing_id=?");
        $existing->execute([$pid, $lid]);
        $ex = $existing->fetch();
        if ($ex) {
            $pdo->prepare("UPDATE partner_business_assignments SET status='active', role=?, assigned_by=?, assigned_at=NOW(), removed_at=NULL, notes=? WHERE id=?")
                ->execute([$role, $_SESSION['user_id'], $notes, $ex['id']]);
        } else {
            $pdo->prepare("INSERT INTO partner_business_assignments (partner_id, listing_id, role, assigned_by, notes) VALUES (?,?,?,?,?)")
                ->execute([$pid, $lid, $role, $_SESSION['user_id'], $notes]);
        }
        // Update managed count
        $pdo->prepare("UPDATE partner_profiles SET businesses_managed_count=(SELECT COUNT(*) FROM partner_business_assignments WHERE partner_id=? AND status='active') WHERE id=?")
            ->execute([$pid, $pid]);
        $msg = 'Business assigned successfully.';
    }

    if ($action === 'unassign_business') {
        $assignId = (int)$_POST['assignment_id'];
        $pdo->prepare("UPDATE partner_business_assignments SET status='removed', removed_at=NOW() WHERE id=?")->execute([$assignId]);
        $pid = (int)$_POST['partner_id'];
        $pdo->prepare("UPDATE partner_profiles SET businesses_managed_count=(SELECT COUNT(*) FROM partner_business_assignments WHERE partner_id=? AND status='active') WHERE id=?")
            ->execute([$pid, $pid]);
        $msg = 'Business unassigned.';
    }

    header('Location: ?tab=' . urlencode($tab) . ($pid ?? '' ? '&pid=' . ($pid ?? '') : '') . '&msg=' . urlencode($msg));
    exit;
}

if (isset($_GET['msg'])) $msg = htmlspecialchars($_GET['msg']);

// ── Data queries ──────────────────────────────────────────

// All partner profiles with user info
$partners = $pdo->query("
    SELECT pp.*, u.name AS user_name, u.email AS user_email,
           COUNT(DISTINCT pba.id) AS assigned_count
    FROM partner_profiles pp
    JOIN users u ON u.id = pp.user_id
    LEFT JOIN partner_business_assignments pba ON pba.partner_id = pp.id AND pba.status='active'
    GROUP BY pp.id
    ORDER BY FIELD(pp.status,'pending','applicant','approved','active','suspended','inactive','terminated'), pp.created_at DESC
")->fetchAll();

// If viewing a specific partner
$viewPartner = null;
$assignments = [];
$availableListings = [];
if (isset($_GET['pid'])) {
    $pid = (int)$_GET['pid'];
    $st = $pdo->prepare("SELECT pp.*, u.name AS user_name, u.email AS user_email FROM partner_profiles pp JOIN users u ON u.id=pp.user_id WHERE pp.id=?");
    $st->execute([$pid]);
    $viewPartner = $st->fetch();

    if ($viewPartner) {
        $asgSt = $pdo->prepare("
            SELECT pba.*, l.title AS listing_title, c.name_en AS cat_en, loc.name_en AS city,
                   u2.name AS assigned_by_name
            FROM partner_business_assignments pba
            JOIN listings l ON l.id = pba.listing_id
            JOIN categories c ON c.id = l.category_id
            JOIN locations loc ON loc.id = l.location_id
            LEFT JOIN users u2 ON u2.id = pba.assigned_by
            WHERE pba.partner_id = ? AND pba.status='active'
            ORDER BY pba.assigned_at DESC
        ");
        $asgSt->execute([$pid]);
        $assignments = $asgSt->fetchAll();

        // Listings not yet assigned to this partner
        $availableListings = $pdo->prepare("
            SELECT l.id, l.title, c.name_en AS cat_en, loc.name_en AS city
            FROM listings l
            JOIN categories c ON c.id=l.category_id
            JOIN locations loc ON loc.id=l.location_id
            WHERE l.status='approved'
              AND l.id NOT IN (SELECT listing_id FROM partner_business_assignments WHERE partner_id=? AND status='active')
            ORDER BY l.title ASC
        ");
        $availableListings->execute([$pid]);
        $availableListings = $availableListings->fetchAll();
    }
}

// Summary stats for overview tab
$overviewStats = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(status IN ('approved','active')) AS active,
        SUM(status='pending') AS pending,
        SUM(status='suspended') AS suspended
    FROM partner_profiles
")->fetch();

$pageTitle = 'Manage Growth Partners — Admin';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.admin-wrap { max-width:1400px; margin:0 auto; padding:2rem 1.5rem; }
.page-header { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1rem; margin-bottom:1.5rem; }
.page-header h1 { font-family:'Fraunces',serif; font-size:2rem; font-weight:900; margin:0; }

.tab-bar { display:flex; gap:0.4rem; border-bottom:2px solid var(--border); margin-bottom:1.5rem; }
.tab-btn { padding:0.6rem 1.2rem; border:none; background:none; cursor:pointer; font-weight:600; font-size:0.875rem;
  color:var(--muted); border-bottom:2px solid transparent; margin-bottom:-2px; transition:all .15s; }
.tab-btn.active { color:var(--primary); border-bottom-color:var(--primary); }

.stat-row { display:flex; gap:1rem; flex-wrap:wrap; margin-bottom:1.5rem; }
.stat-box { background:var(--card); border:1px solid var(--border); border-radius:12px; padding:1rem 1.5rem; flex:1; min-width:120px; text-align:center; }
.stat-box .n { font-size:2rem; font-weight:800; font-family:'Fraunces',serif; }
.stat-box .l { font-size:0.78rem; color:var(--muted); }

.partner-table { width:100%; border-collapse:collapse; }
.partner-table th, .partner-table td { padding:0.75rem 1rem; text-align:left; border-bottom:1px solid var(--border); font-size:0.875rem; }
.partner-table th { font-size:0.75rem; text-transform:uppercase; letter-spacing:.05em; color:var(--muted); background:var(--card); }
.partner-table tr:hover td { background:rgba(0,168,120,0.04); }

.status-badge { padding:2px 10px; border-radius:20px; font-size:0.72rem; font-weight:700; text-transform:uppercase; }
.status-active   { background:rgba(0,168,120,0.15); color:#00A878; }
.status-approved { background:rgba(0,168,120,0.15); color:#00A878; }
.status-pending  { background:rgba(252,209,22,0.2); color:#b8960f; }
.status-applicant{ background:rgba(200,200,200,0.3); color:var(--muted); }
.status-suspended{ background:rgba(230,57,70,0.15); color:#e63946; }
.status-inactive { background:rgba(150,150,150,0.2); color:var(--muted); }

.action-link { font-size:0.8rem; color:var(--primary); text-decoration:none; }
.action-link:hover { text-decoration:underline; }

.partner-detail { background:var(--card); border:1px solid var(--border); border-radius:14px; padding:1.5rem; margin-bottom:1.5rem; }
.partner-detail h2 { font-family:'Fraunces',serif; font-size:1.4rem; margin:0 0 0.5rem; }
.detail-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:0.75rem; margin-top:1rem; }
.detail-item .dl { font-size:0.75rem; color:var(--muted); margin-bottom:0.2rem; }
.detail-item .dv { font-size:0.9rem; font-weight:600; }

.assign-grid { display:grid; grid-template-columns:1fr 360px; gap:1.5rem; }
@media(max-width:900px){ .assign-grid { grid-template-columns:1fr; } }

.panel { background:var(--card); border:1px solid var(--border); border-radius:14px; padding:1.5rem; }
.panel h3 { font-size:1rem; font-weight:700; margin:0 0 1rem; }

.assign-row { display:flex; align-items:center; gap:0.75rem; padding:0.65rem 0; border-bottom:1px solid var(--border); font-size:0.875rem; }
.assign-row:last-child { border-bottom:none; }
</style>

<div class="admin-wrap">
  <div class="page-header">
    <h1>🤝 Growth Partners</h1>
    <a href="<?= SITE_URL ?>/admin" class="btn btn-outline" style="font-size:0.85rem;">← Admin</a>
  </div>

  <?php if ($msg): ?>
    <div style="background:rgba(0,168,120,0.1);border:1px solid #00A878;color:#00A878;padding:0.75rem 1rem;border-radius:9px;margin-bottom:1rem;"><?= e($msg) ?></div>
  <?php endif; ?>

  <!-- Summary stats -->
  <div class="stat-row">
    <div class="stat-box">
      <div class="n"><?= (int)$overviewStats['total'] ?></div>
      <div class="l">Total Partners</div>
    </div>
    <div class="stat-box">
      <div class="n" style="color:#00A878;"><?= (int)$overviewStats['active'] ?></div>
      <div class="l">Active</div>
    </div>
    <div class="stat-box">
      <div class="n" style="color:#b8960f;"><?= (int)$overviewStats['pending'] ?></div>
      <div class="l">Pending Approval</div>
    </div>
    <div class="stat-box">
      <div class="n" style="color:#e63946;"><?= (int)$overviewStats['suspended'] ?></div>
      <div class="l">Suspended</div>
    </div>
  </div>

  <?php if ($viewPartner): ?>
    <!-- ── Partner Detail View ── -->
    <div style="margin-bottom:1rem;">
      <a href="?" style="color:var(--primary); text-decoration:none; font-size:0.9rem;">← Back to all partners</a>
    </div>

    <div class="partner-detail">
      <div style="display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:1rem;">
        <div>
          <h2><?= e($viewPartner['user_name']) ?></h2>
          <div style="color:var(--muted); font-size:0.9rem;"><?= e($viewPartner['user_email']) ?></div>
          <?php if ($viewPartner['organisation']): ?>
            <div style="font-size:0.9rem; margin-top:0.25rem;">🏢 <?= e($viewPartner['organisation']) ?></div>
          <?php endif; ?>
        </div>
        <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
          <span class="status-badge status-<?= $viewPartner['status'] ?>"><?= $viewPartner['status'] ?></span>
          <?php if (in_array($viewPartner['status'], ['pending','applicant'])): ?>
            <form method="POST" style="display:inline;">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="approve_partner">
              <input type="hidden" name="partner_id" value="<?= $viewPartner['id'] ?>">
              <button class="btn btn-primary" style="font-size:0.85rem;" onclick="return confirm('Approve this partner?')">✓ Approve</button>
            </form>
          <?php elseif (in_array($viewPartner['status'], ['approved','active'])): ?>
            <form method="POST" style="display:inline;">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="suspend_partner">
              <input type="hidden" name="partner_id" value="<?= $viewPartner['id'] ?>">
              <button class="btn btn-outline" style="font-size:0.85rem; color:#e63946; border-color:#e63946;" onclick="return confirm('Suspend this partner?')">⊘ Suspend</button>
            </form>
          <?php elseif ($viewPartner['status'] === 'suspended'): ?>
            <form method="POST" style="display:inline;">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="reactivate_partner">
              <input type="hidden" name="partner_id" value="<?= $viewPartner['id'] ?>">
              <button class="btn btn-primary" style="font-size:0.85rem;" onclick="return confirm('Reactivate this partner?')">↺ Reactivate</button>
            </form>
          <?php endif; ?>
        </div>
      </div>

      <div class="detail-grid">
        <?php if ($viewPartner['phone']): ?>
        <div class="detail-item"><div class="dl">Phone</div><div class="dv"><?= e($viewPartner['phone']) ?></div></div>
        <?php endif; ?>
        <?php if ($viewPartner['region']): ?>
        <div class="detail-item"><div class="dl">Region</div><div class="dv"><?= e($viewPartner['region']) ?></div></div>
        <?php endif; ?>
        <div class="detail-item"><div class="dl">Businesses Managed</div><div class="dv"><?= (int)$viewPartner['businesses_managed_count'] ?></div></div>
        <div class="detail-item"><div class="dl">Applied</div><div class="dv"><?= date('j M Y', strtotime($viewPartner['created_at'])) ?></div></div>
        <?php if ($viewPartner['approved_at']): ?>
        <div class="detail-item"><div class="dl">Approved</div><div class="dv"><?= date('j M Y', strtotime($viewPartner['approved_at'])) ?></div></div>
        <?php endif; ?>
        <?php if ($viewPartner['referral_code']): ?>
        <div class="detail-item"><div class="dl">Referral Code</div><div class="dv"><code><?= e($viewPartner['referral_code']) ?></code></div></div>
        <?php endif; ?>
      </div>

      <?php if ($viewPartner['bio']): ?>
      <div style="margin-top:1rem;">
        <div style="font-size:0.75rem; color:var(--muted); margin-bottom:0.3rem;">Bio</div>
        <div style="font-size:0.875rem;"><?= nl2br(e($viewPartner['bio'])) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($viewPartner['why_join']): ?>
      <div style="margin-top:1rem;">
        <div style="font-size:0.75rem; color:var(--muted); margin-bottom:0.3rem;">Why they want to join</div>
        <div style="font-size:0.875rem;"><?= nl2br(e($viewPartner['why_join'])) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($viewPartner['experience']): ?>
      <div style="margin-top:1rem;">
        <div style="font-size:0.75rem; color:var(--muted); margin-bottom:0.3rem;">Experience</div>
        <div style="font-size:0.875rem;"><?= nl2br(e($viewPartner['experience'])) ?></div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Assignments + Assign form -->
    <div class="assign-grid">
      <!-- Current assignments -->
      <div class="panel">
        <h3>📋 Assigned Businesses (<?= count($assignments) ?>)</h3>
        <?php if ($assignments): ?>
          <?php foreach ($assignments as $asg): ?>
          <div class="assign-row">
            <div style="flex:1; min-width:0;">
              <div style="font-weight:600;"><?= e($asg['listing_title']) ?></div>
              <div style="font-size:0.78rem; color:var(--muted);"><?= e($asg['cat_en']) ?> · <?= e($asg['city']) ?> · <?= ucfirst($asg['role']) ?></div>
            </div>
            <a href="<?= SITE_URL ?>/admin/listings?id=<?= $asg['listing_id'] ?>" class="action-link" style="flex-shrink:0;">View</a>
            <form method="POST" style="display:inline; flex-shrink:0;">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="unassign_business">
              <input type="hidden" name="assignment_id" value="<?= $asg['id'] ?>">
              <input type="hidden" name="partner_id" value="<?= $viewPartner['id'] ?>">
              <button type="submit" style="background:none;border:none;color:#e63946;cursor:pointer;font-size:0.8rem;padding:0;"
                onclick="return confirm('Remove this assignment?')">✕ Unassign</button>
            </form>
          </div>
          <?php endforeach; ?>
        <?php else: ?>
          <p style="color:var(--muted); text-align:center; padding:1rem 0;">No businesses assigned yet.</p>
        <?php endif; ?>
      </div>

      <!-- Assign new business -->
      <div class="panel">
        <h3>➕ Assign a Business</h3>
        <?php if ($availableListings): ?>
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="assign_business">
          <input type="hidden" name="partner_id" value="<?= $viewPartner['id'] ?>">

          <div style="margin-bottom:0.75rem;">
            <label style="font-size:0.8rem; color:var(--muted); display:block; margin-bottom:0.3rem;">Business</label>
            <select name="listing_id" required style="width:100%;padding:0.5rem;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text);">
              <option value="">Select business…</option>
              <?php foreach ($availableListings as $l): ?>
              <option value="<?= $l['id'] ?>"><?= e($l['title']) ?> — <?= e($l['city']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div style="margin-bottom:0.75rem;">
            <label style="font-size:0.8rem; color:var(--muted); display:block; margin-bottom:0.3rem;">Role</label>
            <select name="assign_role" style="width:100%;padding:0.5rem;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text);">
              <option value="primary">Primary Partner</option>
              <option value="supporting">Supporting Partner</option>
            </select>
          </div>

          <div style="margin-bottom:1rem;">
            <label style="font-size:0.8rem; color:var(--muted); display:block; margin-bottom:0.3rem;">Notes (optional)</label>
            <textarea name="notes" rows="2" style="width:100%;padding:0.5rem;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text);resize:vertical;font-size:0.875rem;"></textarea>
          </div>

          <button type="submit" class="btn btn-primary" style="width:100%;">Assign Business</button>
        </form>
        <?php else: ?>
          <p style="color:var(--muted); font-size:0.875rem; text-align:center; padding:1rem 0;">All approved businesses are already assigned to this partner.</p>
        <?php endif; ?>
      </div>
    </div>

  <?php else: ?>
    <!-- ── Partner List ── -->
    <div style="background:var(--card); border:1px solid var(--border); border-radius:14px; overflow:hidden;">
      <div style="overflow-x:auto;">
        <table class="partner-table">
          <thead>
            <tr>
              <th>Partner</th>
              <th>Organisation</th>
              <th>Region</th>
              <th>Status</th>
              <th>Businesses</th>
              <th>Applied</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($partners): ?>
              <?php foreach ($partners as $p): ?>
              <tr>
                <td>
                  <div style="font-weight:600;"><?= e($p['user_name']) ?></div>
                  <div style="font-size:0.78rem; color:var(--muted);"><?= e($p['user_email']) ?></div>
                </td>
                <td><?= e($p['organisation'] ?: '—') ?></td>
                <td><?= e($p['region'] ?: '—') ?></td>
                <td><span class="status-badge status-<?= $p['status'] ?>"><?= $p['status'] ?></span></td>
                <td style="text-align:center; font-weight:700;"><?= (int)$p['assigned_count'] ?></td>
                <td style="white-space:nowrap;"><?= date('j M Y', strtotime($p['created_at'])) ?></td>
                <td style="white-space:nowrap;">
                  <a href="?pid=<?= $p['id'] ?>" class="action-link">View →</a>
                  <?php if (in_array($p['status'], ['pending','applicant'])): ?>
                    &nbsp;
                    <form method="POST" style="display:inline;">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="approve_partner">
                      <input type="hidden" name="partner_id" value="<?= $p['id'] ?>">
                      <button style="background:none;border:none;color:#00A878;cursor:pointer;font-size:0.8rem;font-weight:700;"
                        onclick="return confirm('Approve <?= e(addslashes($p['user_name'])) ?>?')">✓ Approve</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="7" style="text-align:center; padding:3rem; color:var(--muted);">No growth partner applications yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
