<?php
/**
 * partner/business.php — Single business view for Growth Partner
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';

$partnerProfile = requireGrowthPartner();
$partnerId      = (int)$partnerProfile['id'];
$user           = currentUser();
$pdo            = db();

$listingId = (int)($_GET['id'] ?? 0);
if (!$listingId || !partnerCanAccessListing($partnerId, $listingId)) {
    redirect(SITE_URL . '/partner/portfolio');
}

// Fetch full listing
$st = $pdo->prepare("
    SELECT l.*, c.name_en AS cat_en, c.icon AS cat_icon, loc.name_en AS city,
           u.name AS owner_name, u.email AS owner_email,
           (SELECT COUNT(*) FROM reviews r WHERE r.listing_id = l.id AND r.status = 'approved') AS review_count,
           (SELECT AVG(r.rating) FROM reviews r WHERE r.listing_id = l.id AND r.status = 'approved') AS avg_rating,
           (SELECT COUNT(*) FROM reviews r WHERE r.listing_id = l.id AND r.status = 'approved' AND r.created_at >= DATE_SUB(NOW(),INTERVAL 30 DAY)) AS reviews_this_month
    FROM listings l
    JOIN categories c ON c.id = l.category_id
    JOIN locations loc ON loc.id = l.location_id
    LEFT JOIN users u ON u.id = l.user_id
    WHERE l.id = ?
");
$st->execute([$listingId]);
$biz = $st->fetch();
if (!$biz) redirect(SITE_URL . '/partner/portfolio');

// Health score & recommendations
$health = calcHealthScore($biz);
$recommendations = getRecommendedActions($biz);

// Growth plans
$plans = $pdo->prepare("SELECT * FROM growth_plans WHERE listing_id = ? AND partner_id = ? ORDER BY created_at DESC");
$plans->execute([$listingId, $partnerId]);
$growthPlans = $plans->fetchAll();

$activePlan = null;
foreach ($growthPlans as $gp) { if ($gp['status'] === 'active') { $activePlan = $gp; break; } }

// Active plan objectives
$objectives = [];
if ($activePlan) {
    $objSt = $pdo->prepare("SELECT * FROM growth_plan_objectives WHERE plan_id = ? ORDER BY sort_order");
    $objSt->execute([$activePlan['id']]);
    $objectives = $objSt->fetchAll();
}

// Tasks
$taskSt = $pdo->prepare("SELECT * FROM growth_tasks WHERE listing_id = ? AND partner_id = ? ORDER BY FIELD(status,'in_progress','todo','completed','cancelled'), due_date ASC LIMIT 20");
$taskSt->execute([$listingId, $partnerId]);
$tasks = $taskSt->fetchAll();

// Leads
$leadSt = $pdo->prepare("SELECT * FROM partner_leads WHERE listing_id = ? AND partner_id = ? ORDER BY created_at DESC LIMIT 10");
$leadSt->execute([$listingId, $partnerId]);
$leads = $leadSt->fetchAll();

// Recent reviews
$revSt = $pdo->prepare("SELECT * FROM reviews WHERE listing_id = ? AND status = 'approved' ORDER BY created_at DESC LIMIT 5");
$revSt->execute([$listingId]);
$reviews = $revSt->fetchAll();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_task') {
        $title    = trim($_POST['title'] ?? '');
        $cat      = $_POST['category'] ?? 'other';
        $priority = $_POST['priority'] ?? 'medium';
        $due      = $_POST['due_date'] ?? null;
        $desc     = trim($_POST['description'] ?? '');
        if ($title) {
            $pdo->prepare("INSERT INTO growth_tasks (listing_id, partner_id, plan_id, title, category, priority, due_date, description) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$listingId, $partnerId, $activePlan['id'] ?? null, $title, $cat, $priority, $due ?: null, $desc]);
            partnerAuditLog($partnerId, $user['id'], $listingId, 'task_created', "Created task: $title");
            flash('success', 'Task added.');
        }
        redirect(SITE_URL . "/partner/business?id=$listingId&tab=tasks");
    }

    if ($action === 'create_plan') {
        $title = trim($_POST['plan_title'] ?? '');
        $start = $_POST['start_date'] ?? null;
        $end   = $_POST['end_date'] ?? null;
        $notes = trim($_POST['notes'] ?? '');
        if ($title) {
            $pdo->prepare("INSERT INTO growth_plans (listing_id, partner_id, title, start_date, end_date, notes, status) VALUES (?,?,?,?,?,?,'active')")
                ->execute([$listingId, $partnerId, $title, $start ?: null, $end ?: null, $notes]);
            $planId = (int)$pdo->lastInsertId();
            // Save objectives
            $metrics = $_POST['obj_metric'] ?? [];
            $targets = $_POST['obj_target'] ?? [];
            foreach ($metrics as $i => $metric) {
                if ($metric && isset($targets[$i]) && $targets[$i] > 0) {
                    $pdo->prepare("INSERT INTO growth_plan_objectives (plan_id, metric, target_value, sort_order) VALUES (?,?,?,?)")
                        ->execute([$planId, $metric, (int)$targets[$i], $i]);
                }
            }
            partnerAuditLog($partnerId, $user['id'], $listingId, 'plan_created', "Created growth plan: $title");
            flash('success', 'Growth plan created.');
        }
        redirect(SITE_URL . "/partner/business?id=$listingId&tab=plan");
    }

    if ($action === 'complete_task') {
        $tid = (int)($_POST['task_id'] ?? 0);
        $pdo->prepare("UPDATE growth_tasks SET status='completed', completed_at=NOW() WHERE id=? AND partner_id=?")->execute([$tid, $partnerId]);
        partnerAuditLog($partnerId, $user['id'], $listingId, 'task_completed', 'Marked task #'.$tid.' completed');
        redirect(SITE_URL . "/partner/business?id=$listingId&tab=tasks");
    }

    if ($action === 'add_lead') {
        $name   = trim($_POST['customer_name'] ?? '');
        $email  = trim($_POST['customer_email'] ?? '');
        $phone  = trim($_POST['customer_phone'] ?? '');
        $source = $_POST['source'] ?? 'other';
        $notes  = trim($_POST['notes'] ?? '');
        $pdo->prepare("INSERT INTO partner_leads (listing_id, partner_id, customer_name, customer_email, customer_phone, source, notes) VALUES (?,?,?,?,?,?,?)")
            ->execute([$listingId, $partnerId, $name, $email, $phone, $source, $notes]);
        partnerAuditLog($partnerId, $user['id'], $listingId, 'lead_added', "Added lead: $name");
        flash('success', 'Lead recorded.');
        redirect(SITE_URL . "/partner/business?id=$listingId&tab=leads");
    }
}

$tab = $_GET['tab'] ?? 'overview';
$pageTitle = e($biz['title']) . ' — Partner Centre';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.partner-wrap { max-width:1280px; margin:0 auto; padding:2rem 1.5rem; }
.biz-header { background:var(--card); border:1px solid var(--border); border-radius:14px; padding:1.5rem; margin-bottom:1.5rem;
  display:flex; align-items:flex-start; gap:1.25rem; flex-wrap:wrap; }
.biz-header .icon { font-size:3rem; }
.biz-header h1 { font-family:'Fraunces',serif; font-size:1.75rem; font-weight:900; margin:0 0 0.25rem; }
.biz-header .meta { color:var(--muted); font-size:0.875rem; }

.tab-nav { display:flex; gap:0; border-bottom:2px solid var(--border); margin-bottom:1.5rem; overflow-x:auto; }
.tab-link { padding:0.6rem 1.25rem; font-size:0.875rem; font-weight:600; color:var(--muted); text-decoration:none;
  border-bottom:3px solid transparent; margin-bottom:-2px; white-space:nowrap; }
.tab-link.active { color:var(--primary); border-bottom-color:var(--primary); }
.tab-link:hover { color:var(--text); }

.two-col { display:grid; grid-template-columns:1fr 300px; gap:1.5rem; }
@media(max-width:800px){ .two-col { grid-template-columns:1fr; } }

.panel { background:var(--card); border:1px solid var(--border); border-radius:14px; padding:1.25rem; margin-bottom:1.25rem; }
.panel h2 { font-size:0.95rem; font-weight:700; margin:0 0 1rem; }

.health-circle { text-align:center; padding:1rem; }
.health-score-num { font-family:'Fraunces',serif; font-size:3rem; font-weight:900; }
.health-component { display:flex; align-items:center; gap:0.75rem; margin-bottom:0.5rem; font-size:0.85rem; }
.hc-label { width:90px; flex-shrink:0; color:var(--muted); }
.hc-bar { flex:1; height:8px; background:var(--border); border-radius:4px; }
.hc-fill { height:100%; border-radius:4px; }
.hc-val { width:35px; text-align:right; font-weight:600; font-size:0.8rem; }

.action-item { display:flex; align-items:flex-start; gap:0.75rem; padding:0.5rem 0; border-bottom:1px solid var(--border); font-size:0.875rem; }
.action-item:last-child { border-bottom:none; }
.action-dot-warn { width:8px; height:8px; background:#e63946; border-radius:50%; flex-shrink:0; margin-top:5px; }
.action-dot-info { width:8px; height:8px; background:#fcd116; border-radius:50%; flex-shrink:0; margin-top:5px; }

.task-row { display:flex; align-items:flex-start; gap:0.75rem; padding:0.65rem 0; border-bottom:1px solid var(--border); }
.task-row:last-child { border-bottom:none; }
.task-badge { font-size:0.68rem; padding:2px 7px; border-radius:20px; font-weight:700; white-space:nowrap; }
.badge-urgent { background:#e63946; color:#fff; }
.badge-high   { background:#ff9f1c; color:#fff; }
.badge-medium { background:#2ec4b6; color:#fff; }
.badge-low    { background:var(--border); color:var(--muted); }
.status-chip  { font-size:0.68rem; padding:2px 8px; border-radius:20px; font-weight:600; }
.status-todo  { background:var(--border); color:var(--muted); }
.status-in_progress { background:rgba(0,168,120,0.15); color:#00A878; }
.status-completed   { background:rgba(0,168,120,0.3); color:#00A878; }
.status-cancelled   { background:rgba(230,57,70,0.1); color:#e63946; }

.objective-row { display:flex; align-items:center; gap:0.75rem; margin-bottom:0.75rem; font-size:0.875rem; }
.obj-label { width:140px; flex-shrink:0; }
.obj-bar { flex:1; height:10px; background:var(--border); border-radius:5px; }
.obj-fill { height:100%; border-radius:5px; background:var(--primary); }
.obj-val { width:60px; text-align:right; font-weight:600; }

.lead-row { padding:0.65rem 0; border-bottom:1px solid var(--border); font-size:0.875rem; }
.lead-row:last-child { border-bottom:none; }

.form-grid { display:grid; grid-template-columns:1fr 1fr; gap:0.75rem; }
@media(max-width:600px){ .form-grid { grid-template-columns:1fr; } }
</style>

<div class="partner-wrap">

  <!-- Breadcrumb -->
  <nav style="font-size:0.85rem; color:var(--muted); margin-bottom:1rem;">
    <a href="<?= SITE_URL ?>/partner/portfolio" style="color:var(--primary);">← My Portfolio</a>
  </nav>

  <!-- Business header -->
  <div class="biz-header">
    <div class="icon"><?= $biz['cat_icon'] ?></div>
    <div style="flex:1;">
      <h1><?= e($biz['title']) ?></h1>
      <div class="meta">
        <?= e($biz['cat_en']) ?> · 📍 <?= e($biz['city']) ?>
        <?php if ($biz['phone']): ?> · 📞 <?= e($biz['phone']) ?><?php endif; ?>
        <?php if ($biz['verified']): ?> · <span style="color:#00A878;">✓ Verified</span><?php endif; ?>
      </div>
      <div class="meta" style="margin-top:0.3rem;">
        Owner: <?= e($biz['owner_name'] ?? '—') ?>
        <?php if ($biz['owner_email']): ?>(<?= e($biz['owner_email']) ?>)<?php endif; ?>
      </div>
    </div>
    <div style="display:flex; gap:0.75rem; flex-wrap:wrap;">
      <a href="<?= SITE_URL ?>/listing/<?= e($biz['slug']) ?>" target="_blank" class="btn btn-outline" style="font-size:0.85rem;">View Listing ↗</a>
    </div>
  </div>

  <!-- Tabs -->
  <nav class="tab-nav">
    <?php
    $tabs = ['overview'=>'📊 Overview','plan'=>'📈 Growth Plan','tasks'=>'✅ Tasks','leads'=>'💬 Leads','reviews'=>'⭐ Reviews'];
    foreach ($tabs as $tk => $tl): ?>
    <a href="?id=<?= $listingId ?>&tab=<?= $tk ?>" class="tab-link <?= $tab===$tk?'active':'' ?>"><?= $tl ?></a>
    <?php endforeach; ?>
  </nav>

  <?php echo csrfField() ?? ''; ?>

  <!-- ══ OVERVIEW TAB ══ -->
  <?php if ($tab === 'overview'): ?>
  <div class="two-col">
    <div>
      <!-- Active plan objectives -->
      <?php if ($activePlan && $objectives): ?>
      <div class="panel">
        <h2>📈 <?= e($activePlan['title']) ?> — Objectives</h2>
        <?php
        $metricLabels = ['reviews'=>'Reviews','profile_views'=>'Profile Views','enquiries'=>'Enquiries','campaigns'=>'Campaigns','social_posts'=>'Social Posts'];
        foreach ($objectives as $obj):
          $pct = $obj['target_value'] > 0 ? min(100, round($obj['current_value'] / $obj['target_value'] * 100)) : 0;
        ?>
        <div class="objective-row">
          <div class="obj-label"><?= e($metricLabels[$obj['metric']] ?? ucfirst($obj['metric'])) ?></div>
          <div class="obj-bar"><div class="obj-fill" style="width:<?= $pct ?>%;"></div></div>
          <div class="obj-val"><?= $obj['current_value'] ?> / <?= $obj['target_value'] ?></div>
          <div style="width:35px; font-size:0.75rem; color:var(--muted);"><?= $pct ?>%</div>
        </div>
        <?php endforeach; ?>
        <a href="?id=<?= $listingId ?>&tab=plan" style="font-size:0.85rem; color:var(--primary);">Manage growth plan →</a>
      </div>
      <?php endif; ?>

      <!-- Recent tasks -->
      <div class="panel">
        <h2>✅ Recent Tasks</h2>
        <?php $recentTasks = array_slice($tasks, 0, 5); if ($recentTasks): ?>
          <?php foreach ($recentTasks as $task): ?>
          <div class="task-row">
            <span class="task-badge badge-<?= $task['priority'] ?>"><?= ucfirst($task['priority']) ?></span>
            <div style="flex:1;">
              <div style="font-size:0.875rem; font-weight:500;"><?= e($task['title']) ?></div>
              <?php if ($task['due_date']): ?><div style="font-size:0.75rem; color:var(--muted);">Due <?= date('j M', strtotime($task['due_date'])) ?></div><?php endif; ?>
            </div>
            <span class="status-chip status-<?= $task['status'] ?>"><?= str_replace('_',' ',ucfirst($task['status'])) ?></span>
          </div>
          <?php endforeach; ?>
          <a href="?id=<?= $listingId ?>&tab=tasks" style="font-size:0.85rem; color:var(--primary);">All tasks →</a>
        <?php else: ?>
          <p style="color:var(--muted); font-size:0.875rem;">No tasks yet. <a href="?id=<?= $listingId ?>&tab=tasks" style="color:var(--primary);">Add one →</a></p>
        <?php endif; ?>
      </div>
    </div>

    <!-- Right column: health score + recommendations -->
    <div>
      <div class="panel">
        <?php $score = $health['score']; $color = $score>=70?'#00A878':($score>=40?'#fcd116':'#e63946'); ?>
        <h2>💚 Business Health</h2>
        <div class="health-circle">
          <div class="health-score-num" style="color:<?= $color ?>;"><?= $score ?>%</div>
          <div style="font-size:0.8rem; color:var(--muted);">Overall Health Score</div>
        </div>
        <?php foreach ($health['components'] as $compName => $comp):
          $pct = $comp['max'] > 0 ? round($comp['score'] / $comp['max'] * 100) : 0;
          $cColor = $pct>=70?'#00A878':($pct>=40?'#fcd116':'#e63946');
        ?>
        <div class="health-component">
          <span class="hc-label"><?= $compName ?></span>
          <div class="hc-bar"><div class="hc-fill" style="width:<?= $pct ?>%;background:<?= $cColor ?>;"></div></div>
          <span class="hc-val" style="color:<?= $cColor ?>;"><?= $comp['score'] ?>/<?= $comp['max'] ?></span>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="panel">
        <h2>💡 Recommended Actions</h2>
        <?php foreach ($recommendations as $rec): ?>
        <div class="action-item">
          <div class="<?= $rec['type']==='warning' ? 'action-dot-warn' : 'action-dot-info' ?>"></div>
          <div><?= e($rec['text']) ?></div>
        </div>
        <?php endforeach; ?>
        <?php if (!$recommendations): ?>
          <p style="color:#00A878; text-align:center; padding:0.5rem;">✓ Profile looks good!</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ══ GROWTH PLAN TAB ══ -->
  <?php elseif ($tab === 'plan'): ?>
    <?php if ($activePlan): ?>
    <div class="panel">
      <h2>📈 <?= e($activePlan['title']) ?>
        <span style="margin-left:0.5rem; font-size:0.75rem; background:rgba(0,168,120,0.15); color:#00A878; padding:2px 8px; border-radius:20px; font-weight:700;">Active</span>
      </h2>
      <div style="display:flex; gap:2rem; flex-wrap:wrap; font-size:0.875rem; color:var(--muted); margin-bottom:1rem;">
        <?php if ($activePlan['start_date']): ?><span>Start: <?= date('j M Y', strtotime($activePlan['start_date'])) ?></span><?php endif; ?>
        <?php if ($activePlan['end_date']): ?><span>End: <?= date('j M Y', strtotime($activePlan['end_date'])) ?></span><?php endif; ?>
      </div>
      <?php if ($activePlan['notes']): ?><p style="color:var(--muted); font-size:0.875rem;"><?= nl2br(e($activePlan['notes'])) ?></p><?php endif; ?>

      <?php if ($objectives): ?>
      <h3 style="font-size:0.9rem; font-weight:700; margin:1.25rem 0 0.75rem;">Objectives & Progress</h3>
      <?php
      $metricLabels = ['reviews'=>'Reviews','profile_views'=>'Profile Views','enquiries'=>'Enquiries','campaigns'=>'Campaigns','social_posts'=>'Social Posts'];
      foreach ($objectives as $obj):
        $pct = $obj['target_value'] > 0 ? min(100, round($obj['current_value'] / $obj['target_value'] * 100)) : 0;
        $blocks = round($pct / 10);
      ?>
      <div style="margin-bottom:1rem;">
        <div style="display:flex; justify-content:space-between; font-size:0.875rem; margin-bottom:0.3rem;">
          <span style="font-weight:600;"><?= e($metricLabels[$obj['metric']] ?? ucfirst($obj['metric'])) ?></span>
          <span style="color:var(--muted);"><?= $obj['current_value'] ?> / <?= $obj['target_value'] ?></span>
        </div>
        <div style="display:flex; gap:2px;">
          <?php for($b=0;$b<10;$b++): ?>
          <div style="flex:1; height:12px; background:<?= $b<$blocks ? 'var(--primary)' : 'var(--border)' ?>; border-radius:2px;"></div>
          <?php endfor; ?>
        </div>
        <div style="font-size:0.75rem; color:var(--primary); margin-top:0.2rem;"><?= $pct ?>%</div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- All plans list -->
    <?php if (count($growthPlans) > 0): ?>
    <div class="panel">
      <h2>All Growth Plans</h2>
      <?php foreach ($growthPlans as $gp): ?>
      <div style="display:flex; align-items:center; gap:1rem; padding:0.6rem 0; border-bottom:1px solid var(--border); font-size:0.875rem;">
        <div style="flex:1;"><strong><?= e($gp['title']) ?></strong>
          <div style="color:var(--muted); font-size:0.78rem;"><?= $gp['start_date'] ? date('j M Y', strtotime($gp['start_date'])) : '—' ?> → <?= $gp['end_date'] ? date('j M Y', strtotime($gp['end_date'])) : 'Ongoing' ?></div>
        </div>
        <span style="font-size:0.75rem; padding:2px 8px; border-radius:20px; background:var(--border); color:var(--muted);"><?= ucfirst($gp['status']) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Create new plan form -->
    <div class="panel">
      <h2>➕ Create New Growth Plan</h2>
      <form method="POST">
        <?= csrfField() ?? '<input type="hidden" name="csrf_token" value="">' ?>
        <input type="hidden" name="action" value="create_plan">
        <div class="form-grid">
          <div>
            <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:0.3rem;">Plan Title *</label>
            <input type="text" name="plan_title" required placeholder="e.g. October Growth Plan" class="form-input" style="width:100%;">
          </div>
          <div></div>
          <div>
            <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:0.3rem;">Start Date</label>
            <input type="date" name="start_date" value="<?= date('Y-m-d') ?>" class="form-input" style="width:100%;">
          </div>
          <div>
            <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:0.3rem;">End Date</label>
            <input type="date" name="end_date" class="form-input" style="width:100%;">
          </div>
        </div>
        <div style="margin:0.75rem 0;">
          <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:0.3rem;">Notes / Overview</label>
          <textarea name="notes" rows="3" class="form-input" style="width:100%;" placeholder="What are you trying to achieve with this business this month?"></textarea>
        </div>
        <h3 style="font-size:0.9rem; font-weight:700; margin:1rem 0 0.5rem;">Target Objectives</h3>
        <?php
        $objMetrics = ['reviews'=>'Reviews','profile_views'=>'Profile Views','enquiries'=>'Enquiries','campaigns'=>'Campaigns','social_posts'=>'Social Posts'];
        $i = 0;
        foreach ($objMetrics as $mk => $ml):
        ?>
        <div style="display:flex; align-items:center; gap:1rem; margin-bottom:0.5rem;">
          <label style="width:160px; font-size:0.85rem;"><?= $ml ?></label>
          <input type="hidden" name="obj_metric[]" value="<?= $mk ?>">
          <input type="number" name="obj_target[]" min="0" placeholder="Target (0 = skip)" class="form-input" style="width:120px;">
        </div>
        <?php $i++; endforeach; ?>
        <div style="margin-top:1rem;">
          <button type="submit" class="btn btn-primary">Create Plan</button>
        </div>
      </form>
    </div>

  <!-- ══ TASKS TAB ══ -->
  <?php elseif ($tab === 'tasks'): ?>
    <!-- Add task form -->
    <div class="panel" style="margin-bottom:1.5rem;">
      <h2>➕ Add Task</h2>
      <form method="POST">
        <?= csrfField() ?? '' ?>
        <input type="hidden" name="action" value="add_task">
        <div class="form-grid">
          <div style="grid-column:span 2;">
            <input type="text" name="title" required placeholder="Task title *" class="form-input" style="width:100%;">
          </div>
          <div>
            <select name="category" class="form-input" style="width:100%;">
              <?php foreach(['profile','reviews','marketing','content','social_media','leads','customer_followup','campaign','website','business_email','other'] as $cat): ?>
              <option value="<?= $cat ?>"><?= ucwords(str_replace('_',' ',$cat)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div style="display:flex; gap:0.5rem;">
            <select name="priority" class="form-input" style="flex:1;">
              <option value="low">Low</option>
              <option value="medium" selected>Medium</option>
              <option value="high">High</option>
              <option value="urgent">Urgent</option>
            </select>
            <input type="date" name="due_date" class="form-input" style="flex:1;">
          </div>
          <div style="grid-column:span 2;">
            <textarea name="description" rows="2" class="form-input" style="width:100%;" placeholder="Details (optional)"></textarea>
          </div>
        </div>
        <button type="submit" class="btn btn-primary" style="margin-top:0.75rem;">Add Task</button>
      </form>
    </div>

    <!-- Task list -->
    <div class="panel">
      <h2>✅ Tasks (<?= count($tasks) ?>)</h2>
      <?php if ($tasks): ?>
        <?php foreach ($tasks as $task):
          $isOverdue = $task['due_date'] && $task['due_date'] < date('Y-m-d') && $task['status'] !== 'completed';
        ?>
        <div class="task-row">
          <span class="task-badge badge-<?= $task['priority'] ?>"><?= ucfirst($task['priority']) ?></span>
          <div style="flex:1; min-width:0;">
            <div style="font-weight:600; font-size:0.9rem; <?= $task['status']==='completed' ? 'text-decoration:line-through; opacity:.6;' : '' ?>"><?= e($task['title']) ?></div>
            <div style="font-size:0.75rem; color:var(--muted);">
              <?= ucwords(str_replace('_',' ',$task['category'])) ?>
              <?php if ($task['due_date']): ?>
                — <?php if ($isOverdue): ?><span style="color:#e63946;">⚠ Overdue</span> <?php endif; ?>
                Due <?= date('j M', strtotime($task['due_date'])) ?>
              <?php endif; ?>
            </div>
          </div>
          <span class="status-chip status-<?= $task['status'] ?>"><?= str_replace('_',' ',ucfirst($task['status'])) ?></span>
          <?php if ($task['status'] !== 'completed' && $task['status'] !== 'cancelled'): ?>
          <form method="POST" style="margin:0;">
            <?= csrfField() ?? '' ?>
            <input type="hidden" name="action" value="complete_task">
            <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
            <button type="submit" title="Mark complete" style="background:none; border:none; cursor:pointer; font-size:1.1rem; padding:2px;">✅</button>
          </form>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
        <p style="color:var(--muted); text-align:center; padding:1rem;">No tasks yet. Add one above.</p>
      <?php endif; ?>
    </div>

  <!-- ══ LEADS TAB ══ -->
  <?php elseif ($tab === 'leads'): ?>
    <div class="panel" style="margin-bottom:1.5rem;">
      <h2>➕ Record Lead</h2>
      <form method="POST">
        <?= csrfField() ?? '' ?>
        <input type="hidden" name="action" value="add_lead">
        <div class="form-grid">
          <div><input type="text" name="customer_name" placeholder="Customer name" class="form-input" style="width:100%;"></div>
          <div><input type="email" name="customer_email" placeholder="Email" class="form-input" style="width:100%;"></div>
          <div><input type="tel" name="customer_phone" placeholder="Phone" class="form-input" style="width:100%;"></div>
          <div>
            <select name="source" class="form-input" style="width:100%;">
              <?php foreach(['enquiry','booking','campaign','referral','walk_in','phone','other'] as $src): ?>
              <option value="<?= $src ?>"><?= ucfirst(str_replace('_',' ',$src)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div style="grid-column:span 2;">
            <textarea name="notes" rows="2" class="form-input" style="width:100%;" placeholder="Notes / Next action"></textarea>
          </div>
        </div>
        <button type="submit" class="btn btn-primary" style="margin-top:0.75rem;">Record Lead</button>
      </form>
    </div>

    <div class="panel">
      <h2>💬 Leads (<?= count($leads) ?>)</h2>
      <?php if ($leads): ?>
        <?php foreach ($leads as $lead): ?>
        <div class="lead-row">
          <div style="display:flex; align-items:flex-start; gap:0.75rem;">
            <div style="flex:1;">
              <div style="font-weight:600;"><?= e($lead['customer_name'] ?: 'Unknown') ?></div>
              <div style="font-size:0.78rem; color:var(--muted);">
                <?= e($lead['customer_email'] ?: '') ?> <?= e($lead['customer_phone'] ? '· '.$lead['customer_phone'] : '') ?>
                · Source: <?= ucfirst(str_replace('_',' ',$lead['source'])) ?>
                · <?= date('j M Y', strtotime($lead['created_at'])) ?>
              </div>
              <?php if ($lead['notes']): ?><div style="font-size:0.8rem; color:var(--muted); margin-top:0.2rem;"><?= e($lead['notes']) ?></div><?php endif; ?>
            </div>
            <span style="font-size:0.75rem; padding:2px 8px; border-radius:20px; background:var(--border); color:var(--muted); white-space:nowrap;"><?= ucfirst(str_replace('_',' ',$lead['status'])) ?></span>
          </div>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
        <p style="color:var(--muted); text-align:center; padding:1rem;">No leads recorded yet.</p>
      <?php endif; ?>
    </div>

  <!-- ══ REVIEWS TAB ══ -->
  <?php elseif ($tab === 'reviews'): ?>
    <div class="panel" style="display:flex; gap:2rem; align-items:flex-start; flex-wrap:wrap; margin-bottom:1.5rem;">
      <div style="text-align:center;">
        <div style="font-family:'Fraunces',serif; font-size:3rem; font-weight:900; color:#fcd116;"><?= $biz['avg_rating'] ? number_format($biz['avg_rating'],1) : '—' ?></div>
        <div style="font-size:0.8rem; color:var(--muted);">Average Rating</div>
      </div>
      <div>
        <div style="font-size:1.5rem; font-weight:700;"><?= (int)$biz['review_count'] ?></div>
        <div style="font-size:0.8rem; color:var(--muted);">Total Reviews</div>
      </div>
      <div>
        <div style="font-size:1.5rem; font-weight:700;"><?= (int)$biz['reviews_this_month'] ?></div>
        <div style="font-size:0.8rem; color:var(--muted);">This Month</div>
      </div>
    </div>

    <div class="panel">
      <h2>⭐ Recent Reviews</h2>
      <?php if ($reviews): ?>
        <?php foreach ($reviews as $rev): ?>
        <div style="padding:0.75rem 0; border-bottom:1px solid var(--border);">
          <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:0.3rem;">
            <span style="font-weight:700;"><?= e($rev['author_name'] ?? 'Anonymous') ?></span>
            <span style="color:#fcd116;"><?= str_repeat('★', (int)$rev['rating']) ?><?= str_repeat('☆', 5 - (int)$rev['rating']) ?></span>
            <span style="font-size:0.78rem; color:var(--muted);"><?= date('j M Y', strtotime($rev['created_at'])) ?></span>
          </div>
          <?php if ($rev['body'] ?? $rev['content'] ?? false): ?>
          <p style="font-size:0.875rem; margin:0; color:var(--muted);"><?= e(mb_substr($rev['body'] ?? $rev['content'] ?? '', 0, 200)) ?></p>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
        <p style="color:var(--muted); text-align:center; padding:1rem;">No reviews yet. Create a review campaign task to encourage customers.</p>
      <?php endif; ?>
    </div>

  <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
