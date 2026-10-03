<?php
/**
 * partner/tasks.php — All Tasks (across portfolio)
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';
require_once __DIR__ . '/../includes/partner-lang.php';

$partnerProfile = requireGrowthPartner();
$partnerId      = (int)$partnerProfile['id'];
$pdo            = db();

$statusFilter   = $_GET['status']   ?? 'open';
$priorityFilter = $_GET['priority'] ?? '';
$listingFilter  = (int)($_GET['listing'] ?? 0);
$search         = trim($_GET['q'] ?? '');

// Handle POST: update task status
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'complete_task') {
        $taskId = (int)$_POST['task_id'];
        // Verify ownership
        $st = $pdo->prepare("SELECT id, listing_id FROM growth_tasks WHERE id=? AND partner_id=?");
        $st->execute([$taskId, $partnerId]);
        $task = $st->fetch();
        if ($task) {
            $pdo->prepare("UPDATE growth_tasks SET status='completed', completed_at=NOW() WHERE id=?")->execute([$taskId]);
            partnerAuditLog($partnerId, currentUser()['id'], $task['listing_id'], 'task_completed', "Completed task #{$taskId}");
        }
    }
    if ($action === 'update_status') {
        $taskId = (int)$_POST['task_id'];
        $newStatus = in_array($_POST['new_status'], ['todo','in_progress','completed','cancelled']) ? $_POST['new_status'] : null;
        if ($newStatus) {
            $st = $pdo->prepare("SELECT id, listing_id FROM growth_tasks WHERE id=? AND partner_id=?");
            $st->execute([$taskId, $partnerId]);
            $task = $st->fetch();
            if ($task) {
                $completedAt = $newStatus === 'completed' ? ', completed_at=NOW()' : '';
                $pdo->prepare("UPDATE growth_tasks SET status=?{$completedAt} WHERE id=?")->execute([$newStatus, $taskId]);
                partnerAuditLog($partnerId, currentUser()['id'], $task['listing_id'], 'task_status_changed', "Task #{$taskId} → {$newStatus}");
            }
        }
    }
    header('Location: ?status=' . urlencode($statusFilter));
    exit;
}

// Build query
$where  = ["gt.partner_id = ?"];
$params = [$partnerId];

if ($statusFilter === 'open') {
    $where[] = "gt.status IN ('todo','in_progress')";
} elseif ($statusFilter === 'overdue') {
    $where[] = "gt.status IN ('todo','in_progress') AND gt.due_date < CURDATE()";
} elseif (in_array($statusFilter, ['todo','in_progress','completed','cancelled'])) {
    $where[] = "gt.status = ?";
    $params[] = $statusFilter;
}

if ($priorityFilter && in_array($priorityFilter, ['low','medium','high','urgent'])) {
    $where[] = "gt.priority = ?";
    $params[] = $priorityFilter;
}

if ($listingFilter) {
    $where[] = "gt.listing_id = ?";
    $params[] = $listingFilter;
}

if ($search) {
    $where[] = "(gt.title LIKE ? OR l.title LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like;
}

$st = $pdo->prepare("
    SELECT gt.*, l.title AS listing_title, c.name_en AS cat_en, loc.name_en AS city
    FROM growth_tasks gt
    JOIN listings l ON l.id = gt.listing_id
    JOIN categories c ON c.id = l.category_id
    JOIN locations loc ON loc.id = l.location_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY
        FIELD(gt.status,'in_progress','todo','completed','cancelled'),
        FIELD(gt.priority,'urgent','high','medium','low'),
        gt.due_date ASC, gt.created_at DESC
");
$st->execute($params);
$tasks = $st->fetchAll();

// Portfolio listings for filter dropdown
$myListings = fetchPartnerListings($partnerId);

$pageTitle = pt('Tasks') . ' — ' . pt('Partner Centre');
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.partner-wrap { max-width:1280px; margin:0 auto; padding:2rem 1.5rem; }
.partner-nav { display:flex; gap:0.5rem; flex-wrap:wrap; margin-bottom:2rem; }
.partner-nav a { padding:0.45rem 1rem; border-radius:9px; font-size:0.875rem; font-weight:600;
  text-decoration:none; background:var(--card); border:1px solid var(--border); color:var(--text); transition:all .15s; }
.partner-nav a:hover, .partner-nav a.active { background:var(--primary); color:#fff; border-color:var(--primary); }

.filter-bar { display:flex; gap:0.75rem; flex-wrap:wrap; align-items:center; margin-bottom:1.5rem; }
.filter-bar select, .filter-bar input { padding:0.45rem 0.75rem; border:1px solid var(--border); border-radius:8px;
  background:var(--card); color:var(--text); font-size:0.875rem; }

.status-tab { padding:0.35rem 0.9rem; border-radius:20px; border:1px solid var(--border);
  font-size:0.8rem; cursor:pointer; background:var(--card); color:var(--text); text-decoration:none; }
.status-tab.active { background:var(--primary); color:#fff; border-color:var(--primary); }

.task-card { background:var(--card); border:1px solid var(--border); border-radius:12px; padding:1rem 1.25rem;
  margin-bottom:0.75rem; display:flex; align-items:flex-start; gap:1rem; }
.task-card.overdue { border-left:3px solid #e63946; }
.task-card.in_progress { border-left:3px solid var(--primary); }

.priority-badge { padding:2px 8px; border-radius:20px; font-size:0.7rem; font-weight:700; white-space:nowrap; flex-shrink:0; }
.pb-urgent { background:#e63946; color:#fff; }
.pb-high   { background:#ff9f1c; color:#fff; }
.pb-medium { background:#2ec4b6; color:#fff; }
.pb-low    { background:var(--border); color:var(--muted); }

.task-status { font-size:0.72rem; padding:2px 8px; border-radius:20px; font-weight:700; }
.ts-todo        { background:rgba(150,150,150,.15); color:var(--muted); }
.ts-in_progress { background:rgba(0,168,120,.15); color:#00A878; }
.ts-completed   { background:rgba(46,196,182,.15); color:#2ec4b6; }
.ts-cancelled   { background:rgba(230,57,70,.1); color:#e63946; }
</style>

<div class="partner-wrap">
  <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1rem; margin-bottom:1.5rem;">
    <h1 style="font-family:'Fraunces',serif; font-size:2rem; font-weight:900; margin:0;">✅ <?= pt('Tasks') ?></h1>
    <span style="color:var(--muted);"><?= count($tasks) ?> <?= pt('task(s)') ?></span>
  </div>

  <nav class="partner-nav">
    <a href="<?= SITE_URL ?>/partner/dashboard">🏠 <?= pt('Dashboard') ?></a>
    <a href="<?= SITE_URL ?>/partner/portfolio">📋 <?= pt('Portfolio') ?></a>
    <a href="<?= SITE_URL ?>/partner/tasks" class="active">✅ <?= pt('Tasks') ?></a>
    <a href="<?= SITE_URL ?>/partner/leads">💬 <?= pt('Leads') ?></a>
    <a href="<?= SITE_URL ?>/partner/commissions">💰 <?= pt('Commissions') ?></a>
  </nav>

  <!-- Status tabs -->
  <div style="display:flex; gap:0.4rem; flex-wrap:wrap; margin-bottom:1rem;">
    <?php
    $tabs = ['open'=>pt('Open'),'overdue'=>'⚠ '.pt('Overdue'),'in_progress'=>pt('In Progress'),'todo'=>pt('To Do'),'completed'=>pt('Completed'),'cancelled'=>pt('Cancelled')];
    foreach ($tabs as $tk => $tl): ?>
    <a href="?status=<?= $tk ?><?= $priorityFilter ? '&priority='.$priorityFilter : '' ?><?= $listingFilter ? '&listing='.$listingFilter : '' ?>"
       class="status-tab <?= $statusFilter===$tk?'active':'' ?>"><?= $tl ?></a>
    <?php endforeach; ?>
  </div>

  <!-- Filters -->
  <form method="GET" class="filter-bar">
    <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
    <input type="text" name="q" value="<?= e($search) ?>" placeholder="<?= pt('Search tasks…') ?>" style="flex:1; min-width:180px;">
    <select name="priority">
      <option value=""><?= pt('All Priorities') ?></option>
      <?php foreach (['urgent','high','medium','low'] as $p): ?>
      <option value="<?= $p ?>" <?= $priorityFilter===$p?'selected':'' ?>><?= ucfirst($p) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="listing">
      <option value=""><?= pt('All Businesses') ?></option>
      <?php foreach ($myListings as $ml): ?>
      <option value="<?= $ml['id'] ?>" <?= $listingFilter===$ml['id']?'selected':'' ?>><?= e($ml['title']) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary" style="white-space:nowrap;"><?= pt('Filter') ?></button>
  </form>

  <?php if (!$tasks): ?>
    <div style="text-align:center; padding:4rem; background:var(--card); border:1px solid var(--border); border-radius:14px;">
      <div style="font-size:3rem; margin-bottom:1rem;">🎉</div>
      <h3 style="font-family:'Fraunces',serif;"><?= pt('No tasks found') ?></h3>
      <p style="color:var(--muted);"><?= pt('Try a different filter or visit a business to add tasks.') ?></p>
    </div>
  <?php else: ?>
    <?php foreach ($tasks as $task):
      $isOverdue = $task['due_date'] && $task['due_date'] < date('Y-m-d') && !in_array($task['status'], ['completed','cancelled']);
    ?>
    <div class="task-card <?= $isOverdue ? 'overdue' : $task['status'] ?>">
      <span class="priority-badge pb-<?= $task['priority'] ?>"><?= ucfirst($task['priority']) ?></span>
      <div style="flex:1; min-width:0;">
        <div style="font-weight:600; margin-bottom:0.25rem;"><?= e($task['title']) ?></div>
        <div style="font-size:0.78rem; color:var(--muted); display:flex; gap:0.75rem; flex-wrap:wrap;">
          <span>📍 <a href="<?= SITE_URL ?>/partner/business?id=<?= $task['listing_id'] ?>" style="color:var(--primary); text-decoration:none;"><?= e($task['listing_title']) ?></a></span>
          <span><?= e($task['cat_en']) ?> · <?= e($task['city']) ?></span>
          <?php if ($task['due_date']): ?>
          <span><?= $isOverdue ? '<span style="color:#e63946; font-weight:700;">⚠ ' . pt('Overdue') . ':</span> ' : '' ?><?= date('j M Y', strtotime($task['due_date'])) ?></span>
          <?php endif; ?>
        </div>
        <?php if ($task['description']): ?>
        <div style="font-size:0.83rem; color:var(--muted); margin-top:0.35rem;"><?= e(mb_strimwidth($task['description'], 0, 120, '…')) ?></div>
        <?php endif; ?>
      </div>
      <div style="display:flex; flex-direction:column; align-items:flex-end; gap:0.5rem; flex-shrink:0;">
        <span class="task-status ts-<?= $task['status'] ?>"><?= str_replace('_',' ', $task['status']) ?></span>
        <?php if (!in_array($task['status'], ['completed','cancelled'])): ?>
        <form method="POST" style="display:flex; gap:0.3rem; align-items:center;">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="update_status">
          <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
          <select name="new_status" style="font-size:0.75rem; padding:2px 4px; border:1px solid var(--border); border-radius:6px; background:var(--bg); color:var(--text);">
            <option value="todo" <?= $task['status']==='todo'?'selected':'' ?>><?= pt('To Do') ?></option>
            <option value="in_progress" <?= $task['status']==='in_progress'?'selected':'' ?>><?= pt('In Progress') ?></option>
            <option value="completed"><?= pt('Completed') ?></option>
            <option value="cancelled"><?= pt('Cancelled') ?></option>
          </select>
          <button type="submit" style="font-size:0.75rem; padding:2px 8px; background:var(--primary); color:#fff; border:none; border-radius:6px; cursor:pointer;"><?= pt('Save') ?></button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
