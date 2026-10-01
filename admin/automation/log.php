<?php
require_once __DIR__ . '/../../includes/config.php';
requireAdmin();

$statusFilter = in_array($_GET['status'] ?? '', ['queued','sent','failed','skipped']) ? $_GET['status'] : '';
$search       = trim($_GET['q'] ?? '');
$page         = max(1,(int)($_GET['page']??1));
$perPage      = 30;

$where  = ['1=1'];
$params = [];
if ($statusFilter) { $where[] = "al.status=?"; $params[] = $statusFilter; }
if ($search) {
    $where[] = "(u.name LIKE ? OR u.email LIKE ? OR at.name LIKE ?)";
    $params  = array_merge($params, ["%$search%","%$search%","%$search%"]);
}
$whereStr = implode(' AND ',$where);

$total   = db()->prepare("SELECT COUNT(*) FROM automation_log al JOIN users u ON u.id=al.user_id JOIN automation_templates at ON at.id=al.template_id WHERE $whereStr")->execute($params) ? 0 : 0;
$cntSt   = db()->prepare("SELECT COUNT(*) FROM automation_log al JOIN users u ON u.id=al.user_id JOIN automation_templates at ON at.id=al.template_id WHERE $whereStr");
$cntSt->execute($params);
$total      = (int)$cntSt->fetchColumn();
$totalPages = max(1,ceil($total/$perPage));
$offset     = ($page-1)*$perPage;

$logSt = db()->prepare("
    SELECT al.*, u.name AS user_name, u.email AS user_email,
           at.name AS tpl_name, seq.name AS seq_name
    FROM automation_log al
    JOIN users u ON u.id=al.user_id
    JOIN automation_templates at ON at.id=al.template_id
    LEFT JOIN automation_sequences seq ON seq.id=al.sequence_id
    WHERE $whereStr
    ORDER BY al.queued_at DESC
    LIMIT $perPage OFFSET $offset
");
$logSt->execute($params);
$logs = $logSt->fetchAll();

// Stats
$stats = db()->query("SELECT status, COUNT(*) AS cnt FROM automation_log GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);

$pageTitle = 'Send Log — Admin 237Biz';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header"><div class="container">
  <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:2rem;">📊 Send Log</h1>
</div></div>

<section class="page-section"><div class="container">

  <div class="filter-tabs" style="margin-bottom:2rem;flex-wrap:wrap;">
    <a href="<?= SITE_URL ?>/admin/" class="filter-tab">📋 Listings</a>
    <a href="<?= SITE_URL ?>/admin/users.php" class="filter-tab">👤 Users</a>
    <a href="<?= SITE_URL ?>/admin/orders.php" class="filter-tab">📦 Orders</a>
    <a href="<?= SITE_URL ?>/admin/automation/" class="filter-tab">🤖 Automation</a>
    <a href="<?= SITE_URL ?>/admin/automation/templates.php" class="filter-tab">✉️ Templates</a>
    <a href="<?= SITE_URL ?>/admin/automation/sequences.php" class="filter-tab">🔗 Sequences</a>
    <a href="<?= SITE_URL ?>/admin/automation/segments.php" class="filter-tab">🏷️ Segments</a>
    <a href="<?= SITE_URL ?>/admin/automation/log.php" class="filter-tab active">📊 Send Log</a>
    <a href="<?= SITE_URL ?>/dashboard" class="filter-tab">← Dashboard</a>
  </div>

  <!-- Stats pills -->
  <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-bottom:1.5rem;">
    <a href="?" class="btn btn-sm <?= !$statusFilter?'btn-primary':'btn-outline' ?>" style="font-size:0.78rem;">All (<?= array_sum($stats) ?>)</a>
    <?php foreach (['queued'=>'pending','sent'=>'approved','failed'=>'rejected','skipped'=>'pending'] as $s=>$badge): ?>
      <a href="?status=<?= $s ?>" class="btn btn-sm <?= $statusFilter===$s?'btn-primary':'btn-outline' ?>" style="font-size:0.78rem;"><?= ucfirst($s) ?> (<?= $stats[$s]??0 ?>)</a>
    <?php endforeach; ?>
  </div>

  <!-- Search -->
  <form method="GET" style="margin-bottom:1rem;display:flex;gap:0.5rem;">
    <?php if ($statusFilter): ?><input type="hidden" name="status" value="<?= e($statusFilter) ?>"><?php endif; ?>
    <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search name, email, template..."
           style="flex:1;background:var(--card);border:1px solid var(--border);border-radius:8px;color:var(--white);padding:0.55rem 0.75rem;font-size:0.875rem;">
    <button type="submit" class="btn btn-primary btn-sm">Search</button>
    <?php if ($search||$statusFilter): ?><a href="?" class="btn btn-outline btn-sm">Clear</a><?php endif; ?>
  </form>

  <p style="font-size:0.78rem;color:var(--muted-2);margin-bottom:0.75rem;"><?= number_format($total) ?> records</p>

  <div class="listing-widget" style="padding:0;overflow:hidden;">
    <div style="overflow-x:auto;">
      <table class="data-table" style="min-width:820px;">
        <thead><tr><th>User</th><th>Template</th><th>Sequence</th><th>Lang</th><th>Subject</th><th>Status</th><th style='text-align:center'>Opens</th><th>Queued</th><th>Sent</th></tr></thead>
        <tbody>
          <?php foreach ($logs as $log): ?>
            <tr>
              <td>
                <div style="color:var(--white);font-size:0.85rem;"><?= e($log['user_name']) ?></div>
                <div style="font-size:0.72rem;color:var(--muted-2);"><?= e($log['user_email']) ?></div>
              </td>
              <td style="font-size:0.82rem;color:var(--muted);"><?= e($log['tpl_name']) ?></td>
              <td style="font-size:0.78rem;color:var(--muted-2);"><?= e($log['seq_name'] ?? '—') ?></td>
              <td style="font-size:0.78rem;"><span class="badge badge-<?= $log['lang']==='en'?'approved':'pending' ?>"><?= strtoupper($log['lang']) ?></span></td>
              <td style="font-size:0.78rem;color:var(--muted);max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($log['subject']) ?></td>
              <td><span class="badge badge-<?= $log['status']==='sent'?'approved':($log['status']==='queued'?'pending':($log['status']==='failed'?'rejected':'pending')) ?>"><?= $log['status'] ?></span></td>
              <td style="text-align:center;font-size:0.82rem;">
                <?php if (!empty($log['open_count']) && $log['open_count'] > 0): ?>
                  <span style="color:var(--green);" title="First opened <?= $log['opened_at'] ? date('d M H:i', strtotime($log['opened_at'])) : '' ?>">👁️ <?= $log['open_count'] ?></span>
                <?php else: ?><span style="color:var(--muted-2);">—</span><?php endif; ?>
              </td>
              <td style="font-size:0.75rem;color:var(--muted-2);white-space:nowrap;"><?= date('d M H:i',strtotime($log['queued_at'])) ?></td>
              <td style="font-size:0.75rem;color:var(--muted-2);white-space:nowrap;"><?= $log['sent_at'] ? date('d M H:i',strtotime($log['sent_at'])) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$logs): ?>
            <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:2rem;">No records found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($totalPages > 1): ?>
    <div style="display:flex;justify-content:center;gap:0.5rem;flex-wrap:wrap;margin-top:1.5rem;">
      <?php for ($i=1;$i<=$totalPages;$i++): ?>
        <?php $qs = array_filter(['status'=>$statusFilter,'q'=>$search,'page'=>$i>1?$i:'']); ?>
        <a href="?<?= http_build_query($qs) ?>" class="btn btn-sm <?= $i===$page?'btn-primary':'btn-outline' ?>"><?= $i ?></a>
      <?php endfor; ?>
    </div>
  <?php endif; ?>

</div></section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
