<?php
/**
 * partner/dashboard.php — 237Biz Business Growth Partner Centre
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';

$partnerProfile = requireGrowthPartner();
$partnerId      = (int)$partnerProfile['id'];
$user           = currentUser();
$pdo            = db();

// ── Portfolio stats ───────────────────────────────────────
$stats = $pdo->prepare("
    SELECT
        COUNT(*)                                               AS total,
        SUM(l.status = 'approved')                            AS active,
        SUM(pba.assigned_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS onboarding
    FROM partner_business_assignments pba
    JOIN listings l ON l.id = pba.listing_id
    WHERE pba.partner_id = ? AND pba.status = 'active'
");
$stats->execute([$partnerId]);
$portfolio = $stats->fetch();

$openTasks = $pdo->prepare("SELECT COUNT(*) FROM growth_tasks WHERE partner_id = ? AND status IN ('todo','in_progress')");
$openTasks->execute([$partnerId]);
$openTaskCount = (int)$openTasks->fetchColumn();

$overdueTasks = $pdo->prepare("SELECT COUNT(*) FROM growth_tasks WHERE partner_id = ? AND status IN ('todo','in_progress') AND due_date < CURDATE()");
$overdueTasks->execute([$partnerId]);
$overdueCount = (int)$overdueTasks->fetchColumn();

$activePlans = $pdo->prepare("SELECT COUNT(*) FROM growth_plans WHERE partner_id = ? AND status = 'active'");
$activePlans->execute([$partnerId]);
$activePlanCount = (int)$activePlans->fetchColumn();

$activeLeads = $pdo->prepare("SELECT COUNT(*) FROM partner_leads WHERE partner_id = ? AND status IN ('new','contacted','follow_up')");
$activeLeads->execute([$partnerId]);
$activeLeadCount = (int)$activeLeads->fetchColumn();

$monthCommission = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM partner_commissions WHERE partner_id = ? AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())");
$monthCommission->execute([$partnerId]);
$monthComm = (float)$monthCommission->fetchColumn();

$pendingCommission = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM partner_commissions WHERE partner_id = ? AND status='pending'");
$pendingCommission->execute([$partnerId]);
$pendingComm = (float)$pendingCommission->fetchColumn();

// ── Recent activity (audit log) ───────────────────────────
$activity = $pdo->prepare("
    SELECT pal.*, l.title AS listing_title
    FROM partner_audit_log pal
    LEFT JOIN listings l ON l.id = pal.listing_id
    WHERE pal.partner_id = ?
    ORDER BY pal.created_at DESC
    LIMIT 15
");
$activity->execute([$partnerId]);
$activityLog = $activity->fetchAll();

// ── Tasks due today / this week ───────────────────────────
$tasksDue = $pdo->prepare("
    SELECT gt.*, l.title AS listing_title
    FROM growth_tasks gt
    JOIN listings l ON l.id = gt.listing_id
    WHERE gt.partner_id = ? AND gt.status IN ('todo','in_progress') AND gt.due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY gt.due_date ASC, gt.priority DESC
    LIMIT 8
");
$tasksDue->execute([$partnerId]);
$upcomingTasks = $tasksDue->fetchAll();

$pageTitle = 'Partner Centre — 237Biz';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.partner-wrap { max-width:1280px; margin:0 auto; padding:2rem 1.5rem; }
.partner-header { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1rem; margin-bottom:2rem; }
.partner-header h1 { font-family:'Fraunces',serif; font-size:2rem; font-weight:900; margin:0; }
.partner-header .subtitle { color:var(--muted); font-size:0.9rem; margin-top:0.2rem; }
.stat-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:1rem; margin-bottom:2rem; }
.stat-card { background:var(--card); border:1px solid var(--border); border-radius:14px; padding:1.25rem 1.5rem; }
.stat-card .stat-label { font-size:0.78rem; text-transform:uppercase; letter-spacing:.06em; color:var(--muted); margin-bottom:0.4rem; }
.stat-card .stat-value { font-size:2rem; font-weight:700; font-family:'Fraunces',serif; }
.stat-card .stat-sub { font-size:0.8rem; color:var(--muted); margin-top:0.2rem; }
.stat-card.accent-green { border-color:#00A878; }
.stat-card.accent-yellow { border-color:#fcd116; }
.stat-card.accent-red { border-color:#e63946; }

.two-col { display:grid; grid-template-columns:1fr 360px; gap:1.5rem; }
@media(max-width:900px){ .two-col { grid-template-columns:1fr; } }

.panel { background:var(--card); border:1px solid var(--border); border-radius:14px; padding:1.5rem; }
.panel h2 { font-size:1rem; font-weight:700; margin:0 0 1rem; display:flex; align-items:center; gap:0.5rem; }
.panel h2 .count { background:var(--primary); color:#fff; border-radius:20px; padding:1px 8px; font-size:0.75rem; }

/* Task list */
.task-row { display:flex; align-items:flex-start; gap:0.75rem; padding:0.7rem 0; border-bottom:1px solid var(--border); }
.task-row:last-child { border-bottom:none; }
.task-badge { font-size:0.7rem; padding:2px 8px; border-radius:20px; font-weight:700; white-space:nowrap; }
.badge-urgent { background:#e63946; color:#fff; }
.badge-high   { background:#ff9f1c; color:#fff; }
.badge-medium { background:#2ec4b6; color:#fff; }
.badge-low    { background:var(--border); color:var(--muted); }
.overdue-tag  { font-size:0.7rem; color:#e63946; font-weight:700; }

/* Activity feed */
.activity-row { display:flex; gap:0.75rem; padding:0.6rem 0; border-bottom:1px solid var(--border); font-size:0.875rem; }
.activity-row:last-child { border-bottom:none; }
.activity-time { color:var(--muted); font-size:0.78rem; white-space:nowrap; flex-shrink:0; }

/* Portfolio bar */
.portfolio-bar { background:var(--card); border:1px solid var(--border); border-radius:14px; padding:1.25rem 1.5rem;
  display:flex; align-items:center; gap:2rem; flex-wrap:wrap; margin-bottom:2rem; }
.portfolio-bar .pb-num { font-family:'Fraunces',serif; font-size:2.5rem; font-weight:900; color:var(--primary); }
.portfolio-bar .pb-label { color:var(--muted); font-size:0.85rem; }
.pb-split { display:flex; gap:2rem; flex-wrap:wrap; }
.pb-split-item { text-align:center; }
.pb-split-item .n { font-size:1.5rem; font-weight:700; }
.pb-split-item .l { font-size:0.78rem; color:var(--muted); }

.partner-nav { display:flex; gap:0.5rem; flex-wrap:wrap; margin-bottom:2rem; }
.partner-nav a { padding:0.45rem 1rem; border-radius:9px; font-size:0.875rem; font-weight:600;
  text-decoration:none; background:var(--card); border:1px solid var(--border); color:var(--text);
  transition:all .15s; }
.partner-nav a:hover { background:var(--primary); color:#fff; border-color:var(--primary); }
.partner-nav a.active { background:var(--primary); color:#fff; border-color:var(--primary); }
</style>

<div class="partner-wrap">

  <div class="partner-header">
    <div>
      <h1>👔 Partner Centre</h1>
      <div class="subtitle">Welcome back, <?= e(explode(' ', $user['name'])[0]) ?> — <?= date('l, j F Y') ?></div>
    </div>
    <a href="<?= SITE_URL ?>/partner/portfolio" class="btn btn-primary">📋 My Portfolio</a>
  </div>

  <!-- Sub-nav -->
  <nav class="partner-nav">
    <a href="<?= SITE_URL ?>/partner/dashboard" class="active">🏠 Dashboard</a>
    <a href="<?= SITE_URL ?>/partner/portfolio">📋 Portfolio</a>
    <a href="<?= SITE_URL ?>/partner/tasks">✅ Tasks</a>
    <a href="<?= SITE_URL ?>/partner/leads">💬 Leads</a>
    <a href="<?= SITE_URL ?>/partner/commissions">💰 Commissions</a>
  </nav>

  <!-- Portfolio summary bar -->
  <div class="portfolio-bar">
    <div>
      <div class="pb-num"><?= (int)$portfolio['total'] ?></div>
      <div class="pb-label">Businesses in Portfolio</div>
    </div>
    <div class="pb-split">
      <div class="pb-split-item">
        <div class="n" style="color:#00A878;"><?= (int)$portfolio['active'] ?></div>
        <div class="l">Active</div>
      </div>
      <div class="pb-split-item">
        <div class="n" style="color:#fcd116;"><?= (int)$portfolio['onboarding'] ?></div>
        <div class="l">Onboarding</div>
      </div>
      <div class="pb-split-item">
        <div class="n" style="color:#e63946;"><?= $overdueCount ?></div>
        <div class="l">Overdue Tasks</div>
      </div>
    </div>
  </div>

  <!-- Stat cards -->
  <div class="stat-grid">
    <div class="stat-card accent-green">
      <div class="stat-label">Active Plans</div>
      <div class="stat-value"><?= $activePlanCount ?></div>
      <div class="stat-sub">Growth plans in progress</div>
    </div>
    <div class="stat-card <?= $overdueCount > 0 ? 'accent-red' : '' ?>">
      <div class="stat-label">Open Tasks</div>
      <div class="stat-value"><?= $openTaskCount ?></div>
      <div class="stat-sub"><?= $overdueCount ?> overdue</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Active Leads</div>
      <div class="stat-value"><?= $activeLeadCount ?></div>
      <div class="stat-sub">Require follow-up</div>
    </div>
    <div class="stat-card accent-yellow">
      <div class="stat-label">This Month</div>
      <div class="stat-value" style="font-size:1.4rem;"><?= number_format($monthComm) ?> XAF</div>
      <div class="stat-sub">Commission earned</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Pending</div>
      <div class="stat-value" style="font-size:1.4rem;"><?= number_format($pendingComm) ?> XAF</div>
      <div class="stat-sub">Awaiting approval</div>
    </div>
  </div>

  <!-- Two-column layout -->
  <div class="two-col">

    <!-- Upcoming tasks -->
    <div class="panel">
      <h2>✅ Upcoming Tasks <span class="count"><?= count($upcomingTasks) ?></span></h2>
      <?php if ($upcomingTasks): ?>
        <?php foreach ($upcomingTasks as $task):
          $isOverdue = $task['due_date'] && $task['due_date'] < date('Y-m-d');
        ?>
        <div class="task-row">
          <span class="task-badge badge-<?= $task['priority'] ?>"><?= ucfirst($task['priority']) ?></span>
          <div style="flex:1; min-width:0;">
            <div style="font-weight:600; font-size:0.9rem;"><?= e($task['title']) ?></div>
            <div style="font-size:0.78rem; color:var(--muted);">
              📍 <?= e($task['listing_title']) ?>
              <?php if ($task['due_date']): ?>
                — <?php if ($isOverdue): ?><span class="overdue-tag">⚠ Overdue:</span><?php endif; ?>
                <?= date('j M', strtotime($task['due_date'])) ?>
              <?php endif; ?>
            </div>
          </div>
          <a href="<?= SITE_URL ?>/partner/tasks?listing=<?= $task['listing_id'] ?>" style="font-size:0.8rem; color:var(--primary);">View →</a>
        </div>
        <?php endforeach; ?>
        <div style="margin-top:1rem;">
          <a href="<?= SITE_URL ?>/partner/tasks" class="btn btn-outline" style="font-size:0.85rem;">View all tasks →</a>
        </div>
      <?php else: ?>
        <p style="color:var(--muted); text-align:center; padding:1rem 0;">No upcoming tasks. Great work! 🎉</p>
      <?php endif; ?>
    </div>

    <!-- Activity feed -->
    <div class="panel">
      <h2>📋 Recent Activity</h2>
      <?php if ($activityLog): ?>
        <?php foreach ($activityLog as $entry): ?>
        <div class="activity-row">
          <div style="flex:1;">
            <div style="font-weight:500;"><?= e($entry['description'] ?: $entry['action']) ?></div>
            <?php if ($entry['listing_title']): ?>
              <div style="font-size:0.78rem; color:var(--muted);">📍 <?= e($entry['listing_title']) ?></div>
            <?php endif; ?>
          </div>
          <div class="activity-time"><?= date('d M H:i', strtotime($entry['created_at'])) ?></div>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
        <p style="color:var(--muted); text-align:center; padding:1rem 0;">No activity yet. Start by opening a business from your portfolio.</p>
      <?php endif; ?>
    </div>

  </div><!-- /two-col -->

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
