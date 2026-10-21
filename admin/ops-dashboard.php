<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/ops-admin-helpers.php';
requireAnyAdmin();

$u = currentUser();

// ── Stats ─────────────────────────────────────────────────────────────────────
$stats = [];
try {
    $stats['total_users']      = (int)db()->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $stats['total_listings']   = (int)db()->query("SELECT COUNT(*) FROM listings WHERE status='approved'")->fetchColumn();
    $stats['pending_listings'] = (int)db()->query("SELECT COUNT(*) FROM listings WHERE status='pending'")->fetchColumn();
    $stats['pending_reviews']  = (int)db()->query("SELECT COUNT(*) FROM reviews WHERE status='pending'")->fetchColumn();
    $stats['open_enquiries']   = (int)db()->query("SELECT COUNT(*) FROM listing_enquiries WHERE crm_status='new'")->fetchColumn();
    $stats['pending_requests'] = isSuperAdmin()
        ? (int)db()->query("SELECT COUNT(*) FROM ops_admin_requests WHERE status='pending'")->fetchColumn()
        : (int)db()->prepare("SELECT COUNT(*) FROM ops_admin_requests WHERE status='pending' AND requester_id=?")->execute([$u['id']]) && 0;
} catch (Exception $e) {}

// Pending ops-admin requests (super admin sees all; ops admin sees own)
try {
    if (isSuperAdmin()) {
        $reqSt = db()->query("
            SELECT r.*, u.name AS requester_name, u.email AS requester_email
            FROM ops_admin_requests r
            JOIN users u ON u.id = r.requester_id
            WHERE r.status='pending'
            ORDER BY r.created_at DESC LIMIT 10
        ");
    } else {
        $reqSt = db()->prepare("
            SELECT r.*, u.name AS requester_name, u.email AS requester_email
            FROM ops_admin_requests r
            JOIN users u ON u.id = r.requester_id
            WHERE r.requester_id=?
            ORDER BY r.created_at DESC LIMIT 10
        ");
        $reqSt->execute([$u['id']]);
    }
    $pendingRequests = $reqSt->fetchAll();
} catch (Exception $e) { $pendingRequests = []; }

// Recent audit entries visible to this user
try {
    if (isSuperAdmin()) {
        $auditRows = db()->query("
            SELECT a.*, u.name AS actor_name
            FROM ops_admin_audit_log a
            LEFT JOIN users u ON u.id = a.actor_id
            ORDER BY a.created_at DESC LIMIT 20
        ")->fetchAll();
    } else {
        $auditSt = db()->prepare("
            SELECT a.*, u.name AS actor_name
            FROM ops_admin_audit_log a
            LEFT JOIN users u ON u.id = a.actor_id
            WHERE a.actor_id=?
            ORDER BY a.created_at DESC LIMIT 20
        ");
        $auditSt->execute([$u['id']]);
        $auditRows = $auditSt->fetchAll();
    }
} catch (Exception $e) { $auditRows = []; }

require_once __DIR__ . '/../includes/admin-header.php';
?>
<style>
.ops-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem;margin-bottom:2rem}
.ops-stat{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:1.25rem;text-align:center}
.ops-stat .num{font-size:2rem;font-weight:700;color:var(--accent,#00A878)}
.ops-stat .lbl{font-size:0.78rem;color:var(--muted);margin-top:0.25rem}
.ops-stat.warn .num{color:#F5C842}
.ops-stat.alert .num{color:#ff6b6b}
.ops-section{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:1.5rem;margin-bottom:1.5rem}
.ops-section h2{font-size:1rem;font-weight:600;margin:0 0 1rem;display:flex;align-items:center;gap:0.5rem}
.nav-links{display:flex;flex-wrap:wrap;gap:0.5rem;margin-bottom:2rem}
.nav-links a{display:inline-flex;align-items:center;gap:0.4rem;padding:0.5rem 1rem;background:var(--card);border:1px solid var(--border);border-radius:6px;color:var(--white);text-decoration:none;font-size:0.85rem;transition:border-color 0.2s}
.nav-links a:hover{border-color:#00A878;color:#00A878}
.badge-pending{background:rgba(245,200,66,0.15);color:#F5C842;border:1px solid rgba(245,200,66,0.3)}
.badge-approved{background:rgba(0,168,120,0.15);color:#00A878;border:1px solid rgba(0,168,120,0.3)}
.badge-rejected{background:rgba(255,107,107,0.15);color:#ff6b6b;border:1px solid rgba(255,107,107,0.3)}
.badge{display:inline-block;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:600}
table.ops-table{width:100%;border-collapse:collapse;font-size:0.83rem}
table.ops-table th{text-align:left;padding:0.5rem 0.75rem;color:var(--muted);border-bottom:1px solid var(--border);font-weight:500}
table.ops-table td{padding:0.5rem 0.75rem;border-bottom:1px solid rgba(255,255,255,0.04);vertical-align:top}
.ops-role-badge{display:inline-block;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700;background:rgba(138,92,246,0.15);color:#a78bfa;border:1px solid rgba(138,92,246,0.3)}
</style>

<div style="max-width:1200px;margin:0 auto;padding:1.5rem 1rem">

  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem">
    <div>
      <h1 style="font-size:1.4rem;font-weight:700;margin:0">
        <?= isSuperAdmin() ? '🔑 Admin Dashboard' : '🛠️ Operations Dashboard' ?>
      </h1>
      <div style="font-size:0.8rem;color:var(--muted);margin-top:0.25rem">
        Logged in as <strong><?= e($u['name']) ?></strong>
        <span class="<?= isSuperAdmin() ? 'badge badge-approved' : 'ops-role-badge' ?>" style="margin-left:0.5rem">
          <?= isSuperAdmin() ? '🔑 Super Admin' : '🛠️ Operations Admin' ?>
        </span>
      </div>
    </div>
    <?php if (isSuperAdmin()): ?>
    <a href="<?= SITE_URL ?>/admin/ops-requests.php" style="padding:0.6rem 1.2rem;background:#00A878;color:#fff;border-radius:6px;text-decoration:none;font-size:0.85rem;font-weight:600">
      📋 Approval Requests
      <?php if (!empty($pendingRequests)): ?>
        <span style="background:#ff6b6b;color:#fff;border-radius:99px;padding:1px 7px;font-size:10px;margin-left:4px"><?= count($pendingRequests) ?></span>
      <?php endif; ?>
    </a>
    <?php endif; ?>
  </div>

  <!-- Platform overview stats -->
  <div class="ops-grid">
    <div class="ops-stat"><div class="num"><?= number_format($stats['total_users'] ?? 0) ?></div><div class="lbl">Total Users</div></div>
    <div class="ops-stat"><div class="num"><?= number_format($stats['total_listings'] ?? 0) ?></div><div class="lbl">Active Listings</div></div>
    <div class="ops-stat warn"><div class="num"><?= number_format($stats['pending_listings'] ?? 0) ?></div><div class="lbl">Pending Listings</div></div>
    <div class="ops-stat warn"><div class="num"><?= number_format($stats['pending_reviews'] ?? 0) ?></div><div class="lbl">Pending Reviews</div></div>
    <div class="ops-stat"><div class="num"><?= number_format($stats['open_enquiries'] ?? 0) ?></div><div class="lbl">Open Enquiries</div></div>
    <?php if (isSuperAdmin()): ?>
    <div class="ops-stat <?= ($stats['pending_requests'] ?? 0) > 0 ? 'alert' : '' ?>">
      <div class="num"><?= number_format($stats['pending_requests'] ?? 0) ?></div>
      <div class="lbl">Pending Requests</div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Navigation -->
  <div class="nav-links">
    <?php if (canOpsAdmin('user.view')): ?>
    <a href="<?= SITE_URL ?>/admin/users.php">👤 Users</a>
    <?php endif; ?>
    <?php if (canOpsAdmin('listing.view')): ?>
    <a href="<?= SITE_URL ?>/admin/dashboard.php">📋 Listings</a>
    <?php endif; ?>
    <?php if (canOpsAdmin('agent.view')): ?>
    <a href="<?= SITE_URL ?>/admin/manage-agents.php">👔 Agents</a>
    <?php endif; ?>
    <?php if (canOpsAdmin('creator.view')): ?>
    <a href="<?= SITE_URL ?>/admin/manage-creators.php">🎬 Creators</a>
    <?php endif; ?>
    <?php if (canOpsAdmin('partner.view')): ?>
    <a href="<?= SITE_URL ?>/admin/manage-growth-partners.php">🤝 Growth Partners</a>
    <?php endif; ?>
    <?php if (canOpsAdmin('enquiry.view')): ?>
    <a href="<?= SITE_URL ?>/my-enquiries.php">📬 Enquiries</a>
    <?php endif; ?>
    <?php if (canOpsAdmin('booking.view')): ?>
    <a href="<?= SITE_URL ?>/admin/dashboard.php#bookings">📅 Bookings</a>
    <?php endif; ?>
    <?php if (canOpsAdmin('review.view')): ?>
    <a href="<?= SITE_URL ?>/admin/dashboard.php#reviews">⭐ Reviews</a>
    <?php endif; ?>
    <?php if (canOpsAdmin('report.analytics')): ?>
    <a href="<?= SITE_URL ?>/analytics.php">📊 Analytics</a>
    <?php endif; ?>
    <?php if (canOpsAdmin('subscription.view')): ?>
    <a href="<?= SITE_URL ?>/admin/dashboard.php#subscriptions">💳 Subscriptions (view)</a>
    <?php endif; ?>
    <?php if (canOpsAdmin('request.submit') && isOpsAdmin()): ?>
    <a href="<?= SITE_URL ?>/admin/ops-requests.php">📋 My Requests</a>
    <?php endif; ?>
    <?php if (canOpsAdmin('audit.view_own')): ?>
    <a href="<?= SITE_URL ?>/admin/ops-audit.php">🗒️ Audit Log</a>
    <?php endif; ?>
  </div>

  <!-- Pending approval requests (super admin sees all, ops admin sees own) -->
  <?php if (!empty($pendingRequests)): ?>
  <div class="ops-section">
    <h2>📋 <?= isSuperAdmin() ? 'Pending Approval Requests' : 'My Pending Requests' ?></h2>
    <table class="ops-table">
      <thead>
        <tr>
          <th>Ref</th>
          <th>Requester</th>
          <th>Action</th>
          <th>Record</th>
          <th>Reason</th>
          <th>Submitted</th>
          <?php if (isSuperAdmin()): ?><th>Actions</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pendingRequests as $req): ?>
        <tr>
          <td style="color:var(--muted)">#<?= $req['id'] ?></td>
          <td><?= e($req['requester_name']) ?></td>
          <td><code style="font-size:11px"><?= e($req['action']) ?></code></td>
          <td style="color:var(--muted)"><?= e($req['record_type']) ?> #<?= $req['record_id'] ?></td>
          <td><?= e(mb_strimwidth($req['reason'], 0, 80, '…')) ?></td>
          <td style="color:var(--muted)"><?= date('d M H:i', strtotime($req['created_at'])) ?></td>
          <?php if (isSuperAdmin()): ?>
          <td>
            <a href="<?= SITE_URL ?>/admin/ops-requests.php?review=<?= $req['id'] ?>" style="color:#00A878;font-size:0.8rem">Review →</a>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div style="margin-top:0.75rem"><a href="<?= SITE_URL ?>/admin/ops-requests.php" style="color:#00A878;font-size:0.83rem">View all requests →</a></div>
  </div>
  <?php endif; ?>

  <!-- Recent audit log -->
  <?php if (!empty($auditRows)): ?>
  <div class="ops-section">
    <h2>🗒️ Recent Activity Log</h2>
    <table class="ops-table">
      <thead>
        <tr><th>When</th><th>Actor</th><th>Action</th><th>Record</th><th>Detail</th></tr>
      </thead>
      <tbody>
        <?php foreach ($auditRows as $row): ?>
        <tr>
          <td style="color:var(--muted);white-space:nowrap"><?= date('d M H:i', strtotime($row['created_at'])) ?></td>
          <td><?= e($row['actor_name'] ?? 'System') ?></td>
          <td><code style="font-size:11px"><?= e($row['action']) ?></code></td>
          <td style="color:var(--muted)"><?= $row['record_type'] ? e($row['record_type']).' #'.$row['record_id'] : '—' ?></td>
          <td style="color:var(--muted)"><?= e(mb_strimwidth($row['detail'] ?? '', 0, 100, '…')) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div style="margin-top:0.75rem"><a href="<?= SITE_URL ?>/admin/ops-audit.php" style="color:#00A878;font-size:0.83rem">Full audit log →</a></div>
  </div>
  <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>
