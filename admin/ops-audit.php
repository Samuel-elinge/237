<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/ops-admin-helpers.php';
requireAnyAdmin();
requirePermission('audit.view_own');

$u = currentUser();

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset  = ($page - 1) * $perPage;

// Ops admin sees only their own rows; super admin sees all
$where = isSuperAdmin() ? '' : "WHERE a.actor_id = {$u['id']}";

try {
    $total = (int)db()->query("SELECT COUNT(*) FROM ops_admin_audit_log a $where")->fetchColumn();
    $rows  = db()->query("
        SELECT a.*, u.name AS actor_name
        FROM ops_admin_audit_log a
        LEFT JOIN users u ON u.id = a.actor_id
        $where
        ORDER BY a.created_at DESC
        LIMIT $perPage OFFSET $offset
    ")->fetchAll();
} catch (Exception $e) { $rows = []; $total = 0; }

$totalPages = max(1, (int)ceil($total / $perPage));

require_once __DIR__ . '/../includes/admin-header.php';
?>
<style>
.ops-section{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:1.5rem;margin-bottom:1.5rem}
table.ops-table{width:100%;border-collapse:collapse;font-size:0.82rem}
table.ops-table th{text-align:left;padding:0.5rem 0.75rem;color:var(--muted);border-bottom:1px solid var(--border);font-weight:500}
table.ops-table td{padding:0.55rem 0.75rem;border-bottom:1px solid rgba(255,255,255,0.04);vertical-align:top}
</style>

<div style="max-width:1200px;margin:0 auto;padding:1.5rem 1rem">
  <a href="<?= SITE_URL ?>/admin/ops-dashboard.php" style="color:var(--muted);font-size:0.83rem;text-decoration:none">← Dashboard</a>
  <h1 style="font-size:1.3rem;font-weight:700;margin:0.5rem 0 1.5rem">
    🗒️ <?= isSuperAdmin() ? 'Full Audit Log' : 'My Activity Log' ?>
    <span style="font-size:0.85rem;color:var(--muted);font-weight:400">(<?= number_format($total) ?> entries)</span>
  </h1>

  <div class="ops-section">
    <?php if (empty($rows)): ?>
    <p style="color:var(--muted);text-align:center;padding:2rem">No audit entries found.</p>
    <?php else: ?>
    <table class="ops-table">
      <thead>
        <tr>
          <th>When</th>
          <?php if (isSuperAdmin()): ?><th>Actor</th><th>Role</th><?php endif; ?>
          <th>Action</th>
          <th>Record</th>
          <th>Detail</th>
          <th>IP</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
        <tr>
          <td style="color:var(--muted);white-space:nowrap"><?= date('d M Y H:i:s', strtotime($row['created_at'])) ?></td>
          <?php if (isSuperAdmin()): ?>
          <td><?= e($row['actor_name'] ?? 'System') ?></td>
          <td style="color:var(--muted)"><?= e($row['actor_role']) ?></td>
          <?php endif; ?>
          <td><code style="font-size:11px"><?= e($row['action']) ?></code></td>
          <td style="color:var(--muted)"><?= $row['record_type'] ? e($row['record_type']).' #'.$row['record_id'] : '—' ?></td>
          <td style="color:var(--muted);max-width:300px;word-break:break-word"><?= e(mb_strimwidth($row['detail'] ?? '', 0, 120, '…')) ?></td>
          <td style="color:var(--muted);font-size:11px"><?= e($row['ip'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div style="display:flex;gap:0.5rem;justify-content:center;margin-top:1.25rem;flex-wrap:wrap">
      <?php for ($p = 1; $p <= $totalPages; $p++): ?>
      <a href="?page=<?= $p ?>" style="padding:0.35rem 0.75rem;border-radius:5px;text-decoration:none;font-size:0.83rem;
         <?= $p===$page ? 'background:#00A878;color:#fff' : 'background:var(--card);border:1px solid var(--border);color:var(--white)' ?>">
        <?= $p ?>
      </a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>
