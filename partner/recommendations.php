<?php
/**
 * partner/recommendations.php — AI Recommendations
 * Phase 3A: Rule-based intelligence recommendations for Growth Partners.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';
require_once __DIR__ . '/../includes/partner-lang.php';

$profile = requireGrowthPartner();
$pid     = (int)$profile['id'];
$userId  = (int)currentUser()['id'];
$pdo     = db();

// ── POST HANDLERS ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    $rid    = (int)($_POST['rec_id'] ?? 0);

    if ($rid && in_array($action, ['dismiss','mark_actioned','mark_viewed'])) {
        // Verify ownership
        $st = $pdo->prepare("SELECT id FROM ai_recommendations WHERE id=? AND partner_id=?");
        $st->execute([$rid, $pid]);
        if ($st->fetch()) {
            $statusMap = [
                'dismiss'        => 'dismissed',
                'mark_actioned'  => 'actioned',
                'mark_viewed'    => 'viewed',
            ];
            $newStatus = $statusMap[$action];
            $extraSql  = '';
            if ($newStatus === 'dismissed')  $extraSql = ", dismissed_at=NOW()";
            if ($newStatus === 'actioned')   $extraSql = ", actioned_at=NOW()";
            $pdo->prepare("UPDATE ai_recommendations SET status=? $extraSql WHERE id=? AND partner_id=?")
                ->execute([$newStatus, $rid, $pid]);
            partnerAuditLog($pid, $userId, "recommendation_$action", 'recommendation', $rid, $pdo);
        }
    } elseif ($action === 'generate') {
        // Manually trigger recommendation generation
        generateRecommendations($pid, $pdo);
        generateGrowthAlerts($pid, $pdo);
        setFlash('success', 'Recommendations refreshed.');
    }

    redirect(SITE_URL . '/partner/recommendations');
}

// ── FILTERS ───────────────────────────────────────────────────
$filterPriority = $_GET['priority'] ?? '';
$filterListing  = (int)($_GET['listing_id'] ?? 0);
$filterStatus   = $_GET['status'] ?? 'active'; // active | all | actioned | dismissed

// Auto-generate on first load if none exist
$st = $pdo->prepare("SELECT COUNT(*) FROM ai_recommendations WHERE partner_id=? AND status IN ('new','viewed')");
$st->execute([$pid]);
if ((int)$st->fetchColumn() === 0) {
    generateRecommendations($pid, $pdo);
    generateGrowthAlerts($pid, $pdo);
}

// ── FETCH RECOMMENDATIONS ─────────────────────────────────────
$sql    = "SELECT r.*, l.title AS business_name FROM ai_recommendations r
           LEFT JOIN listings l ON l.id = r.listing_id
           WHERE r.partner_id = ?";
$params = [$pid];

if ($filterStatus === 'active') {
    $sql .= " AND r.status IN ('new','viewed')";
} elseif ($filterStatus === 'actioned') {
    $sql .= " AND r.status = 'actioned'";
} elseif ($filterStatus === 'dismissed') {
    $sql .= " AND r.status = 'dismissed'";
} else {
    $sql .= " AND r.status != 'expired'";
}

if ($filterPriority) { $sql .= " AND r.priority = ?"; $params[] = $filterPriority; }
if ($filterListing)  { $sql .= " AND r.listing_id = ?"; $params[] = $filterListing; }

$sql .= " ORDER BY FIELD(r.priority,'urgent','high','medium','low'), r.created_at DESC";
$st = $pdo->prepare($sql);
$st->execute($params);
$recommendations = $st->fetchAll();

// ── SUMMARY COUNTS ────────────────────────────────────────────
$st = $pdo->prepare("SELECT status, priority, COUNT(*) cnt FROM ai_recommendations
    WHERE partner_id=? AND status != 'expired'
    GROUP BY status, priority");
$st->execute([$pid]);
$counts = ['new'=>0,'viewed'=>0,'actioned'=>0,'dismissed'=>0,'urgent'=>0,'high'=>0];
foreach ($st->fetchAll() as $row) {
    $counts[$row['status']] = ($counts[$row['status']] ?? 0) + (int)$row['cnt'];
    $counts[$row['priority']] = ($counts[$row['priority']] ?? 0) + (int)$row['cnt'];
}
$activeCount = ($counts['new'] ?? 0) + ($counts['viewed'] ?? 0);

// ── PORTFOLIO LISTINGS FOR FILTER ─────────────────────────────
$st = $pdo->prepare("SELECT l.id, l.title AS name FROM listings l
    JOIN partner_business_assignments pp ON pp.listing_id = l.id
    WHERE pp.partner_id=? AND pp.status='active' ORDER BY l.title");
$st->execute([$pid]);
$portfolioListings = $st->fetchAll();

// ── HELPERS ───────────────────────────────────────────────────
$priorityColors = [
    'urgent' => '#dc3545', 'high' => '#fd7e14',
    'medium' => '#0d6efd', 'low'  => '#6c757d',
];
$priorityLabels = [
    'urgent' => '🔴 Urgent', 'high' => '🟠 High',
    'medium' => '🔵 Medium', 'low'  => '⚪ Low',
];
$statusBadge = [
    'new'       => '<span class="badge bg-primary">New</span>',
    'viewed'    => '<span class="badge bg-secondary">Viewed</span>',
    'actioned'  => '<span class="badge bg-success">Actioned</span>',
    'dismissed' => '<span class="badge bg-light text-dark">Dismissed</span>',
];

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= pt('AI Recommendations') ?> — 237biz Partner</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Inter', sans-serif; background: #f0f2f5; color: #1a1d23; min-height: 100vh; }

/* ── NAV ── */
.top-nav { background: #1a1d23; color: #fff; padding: 0 24px; display: flex; align-items: center; gap: 24px; height: 56px; }
.top-nav .brand { font-weight: 700; font-size: 1.1rem; color: #fff; text-decoration: none; }
.top-nav nav { display: flex; gap: 4px; margin-left: auto; }
.top-nav nav a { color: rgba(255,255,255,.7); text-decoration: none; padding: 6px 12px; border-radius: 6px; font-size: .85rem; transition: all .2s; }
.top-nav nav a:hover, .top-nav nav a.active { background: rgba(255,255,255,.1); color: #fff; }

/* ── LAYOUT ── */
.page-wrap { max-width: 1100px; margin: 0 auto; padding: 28px 20px; }
.page-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; }
.page-title { font-size: 1.5rem; font-weight: 700; }
.page-subtitle { color: #6c757d; font-size: .9rem; margin-top: 4px; }

/* ── SUMMARY STRIP ── */
.summary-strip { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 12px; margin-bottom: 24px; }
.stat-card { background: #fff; border-radius: 10px; padding: 16px 18px; border: 1px solid #e9ecef; }
.stat-card .label { font-size: .75rem; color: #6c757d; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 4px; }
.stat-card .value { font-size: 1.6rem; font-weight: 700; line-height: 1; }
.stat-card .sub { font-size: .75rem; color: #6c757d; margin-top: 2px; }
.stat-card.urgent .value { color: #dc3545; }
.stat-card.high   .value { color: #fd7e14; }

/* ── FILTERS ── */
.filter-bar { background: #fff; border-radius: 10px; padding: 14px 16px; display: flex; gap: 12px; flex-wrap: wrap; align-items: center; margin-bottom: 20px; border: 1px solid #e9ecef; }
.filter-bar select, .filter-bar button { padding: 7px 12px; border: 1px solid #dee2e6; border-radius: 6px; font-size: .85rem; background: #fff; cursor: pointer; }
.filter-bar button { background: #1a1d23; color: #fff; border-color: #1a1d23; font-weight: 500; }
.filter-bar button:hover { background: #2d3139; }
.tab-strip { display: flex; gap: 6px; margin-left: auto; }
.tab-strip a { padding: 6px 14px; border-radius: 6px; text-decoration: none; font-size: .83rem; font-weight: 500; color: #6c757d; background: #f8f9fa; border: 1px solid #e9ecef; }
.tab-strip a.active { background: #1a1d23; color: #fff; border-color: #1a1d23; }

/* ── RECOMMENDATION CARDS ── */
.rec-list { display: flex; flex-direction: column; gap: 12px; }
.rec-card { background: #fff; border-radius: 12px; border: 1px solid #e9ecef; overflow: hidden; transition: box-shadow .2s; }
.rec-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.08); }
.rec-card .rec-header { display: flex; align-items: flex-start; gap: 12px; padding: 16px 18px 12px; }
.priority-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; margin-top: 5px; }
.rec-meta { flex: 1; min-width: 0; }
.rec-title { font-size: .95rem; font-weight: 600; line-height: 1.3; }
.rec-business { font-size: .78rem; color: #6c757d; margin-top: 2px; }
.rec-badges { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
.badge { padding: 3px 9px; border-radius: 20px; font-size: .72rem; font-weight: 600; }
.bg-primary  { background: #dbeafe; color: #1d4ed8; }
.bg-secondary { background: #f1f3f5; color: #495057; }
.bg-success  { background: #d1fae5; color: #065f46; }
.bg-light    { background: #f8f9fa; }
.priority-badge { padding: 3px 9px; border-radius: 20px; font-size: .72rem; font-weight: 600; }

.rec-body { padding: 0 18px 14px 18px; }
.rec-desc { font-size: .87rem; color: #495057; line-height: 1.55; margin-bottom: 12px; }
.rec-footer { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
.rec-objective { font-size: .78rem; color: #6c757d; }
.rec-objective span { font-weight: 600; color: #495057; }
.rec-actions { display: flex; gap: 8px; align-items: center; }

.btn { padding: 7px 16px; border-radius: 7px; font-size: .83rem; font-weight: 600; cursor: pointer; text-decoration: none; border: none; display: inline-flex; align-items: center; gap: 6px; }
.btn-action { background: #1a1d23; color: #fff; }
.btn-action:hover { background: #2d3139; color: #fff; }
.btn-outline { background: transparent; border: 1px solid #dee2e6; color: #6c757d; }
.btn-outline:hover { background: #f8f9fa; }
.btn-generate { background: linear-gradient(135deg, #667eea, #764ba2); color: #fff; }
.btn-generate:hover { opacity: .9; }

.empty-state { text-align: center; padding: 60px 20px; background: #fff; border-radius: 12px; border: 1px solid #e9ecef; }
.empty-icon { font-size: 3rem; margin-bottom: 16px; }
.empty-state h3 { font-size: 1.1rem; margin-bottom: 8px; }
.empty-state p { color: #6c757d; font-size: .9rem; max-width: 380px; margin: 0 auto 20px; }

.alert-box { padding: 12px 16px; border-radius: 8px; margin-bottom: 18px; font-size: .9rem; }
.alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
.alert-info    { background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; }

.rec-date { font-size: .75rem; color: #adb5bd; margin-top: 4px; }
.ai-badge { display: inline-flex; align-items: center; gap: 4px; background: linear-gradient(135deg,#667eea,#764ba2); color:#fff; padding: 3px 10px; border-radius: 20px; font-size:.72rem; font-weight:600; }
</style>
</head>
<body>

<!-- NAV -->
<header class="top-nav">
  <a href="<?= SITE_URL ?>/partner/dashboard" class="brand">237biz Partner</a>
  <nav>
    <a href="<?= SITE_URL ?>/partner/dashboard"><?= pt('Dashboard') ?></a>
    <a href="<?= SITE_URL ?>/partner/portfolio"><?= pt('Portfolio') ?></a>
    <a href="<?= SITE_URL ?>/partner/tasks"><?= pt('Tasks') ?></a>
    <a href="<?= SITE_URL ?>/partner/leads"><?= pt('Leads') ?></a>
    <a href="<?= SITE_URL ?>/partner/campaigns"><?= pt('Campaigns') ?></a>
    <a href="<?= SITE_URL ?>/partner/content"><?= pt('Content') ?></a>
    <a href="<?= SITE_URL ?>/partner/reports"><?= pt('Reports') ?></a>
    <a href="<?= SITE_URL ?>/partner/recommendations" class="active">AI</a>
  </nav>
</header>

<div class="page-wrap">

  <?php if ($flash): ?>
    <div class="alert-box alert-<?= htmlspecialchars($flash['type']) ?>">
      <?= htmlspecialchars($flash['message']) ?>
    </div>
  <?php endif; ?>

  <!-- PAGE HEADER -->
  <div class="page-header">
    <div>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;">
        <h1 class="page-title"><?= pt('Recommendations') ?></h1>
        <span class="ai-badge">✦ AI</span>
      </div>
      <p class="page-subtitle"><?= pt('Action-ready insights generated from your portfolio activity') ?></p>
    </div>
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="generate">
      <button type="submit" class="btn btn-generate">↻ <?= pt('Refresh Recommendations') ?></button>
    </form>
  </div>

  <!-- SUMMARY STRIP -->
  <div class="summary-strip">
    <div class="stat-card">
      <div class="label"><?= pt('Active') ?></div>
      <div class="value"><?= $activeCount ?></div>
      <div class="sub"><?= pt('need attention') ?></div>
    </div>
    <div class="stat-card urgent">
      <div class="label"><?= pt('Urgent') ?></div>
      <div class="value"><?= $counts['urgent'] ?? 0 ?></div>
      <div class="sub"><?= pt('highest priority') ?></div>
    </div>
    <div class="stat-card high">
      <div class="label"><?= pt('High') ?></div>
      <div class="value"><?= $counts['high'] ?? 0 ?></div>
    </div>
    <div class="stat-card">
      <div class="label"><?= pt('Actioned') ?></div>
      <div class="value"><?= $counts['actioned'] ?? 0 ?></div>
      <div class="sub"><?= pt('completed') ?></div>
    </div>
    <div class="stat-card">
      <div class="label"><?= pt('Businesses') ?></div>
      <div class="value"><?= count($portfolioListings) ?></div>
      <div class="sub"><?= pt('in portfolio') ?></div>
    </div>
  </div>

  <!-- FILTER BAR -->
  <form method="get" class="filter-bar">
    <select name="priority" onchange="this.form.submit()">
      <option value=""><?= pt('All priorities') ?></option>
      <option value="urgent" <?= $filterPriority==='urgent'?'selected':'' ?>>🔴 <?= pt('Urgent') ?></option>
      <option value="high"   <?= $filterPriority==='high'  ?'selected':'' ?>>🟠 <?= pt('High') ?></option>
      <option value="medium" <?= $filterPriority==='medium'?'selected':'' ?>>🔵 <?= pt('Medium') ?></option>
      <option value="low"    <?= $filterPriority==='low'   ?'selected':'' ?>>⚪ <?= pt('Low') ?></option>
    </select>
    <select name="listing_id" onchange="this.form.submit()">
      <option value=""><?= pt('All businesses') ?></option>
      <?php foreach ($portfolioListings as $l): ?>
        <option value="<?= $l['id'] ?>" <?= $filterListing===$l['id']?'selected':'' ?>>
          <?= htmlspecialchars($l['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
    <div class="tab-strip">
      <a href="?status=active<?= $filterPriority?"&priority=$filterPriority":'' ?><?= $filterListing?"&listing_id=$filterListing":'' ?>"
         class="<?= $filterStatus==='active'?'active':'' ?>"><?= pt('Active') ?> (<?= $activeCount ?>)</a>
      <a href="?status=actioned<?= $filterPriority?"&priority=$filterPriority":'' ?>"
         class="<?= $filterStatus==='actioned'?'active':'' ?>"><?= pt('Actioned') ?></a>
      <a href="?status=dismissed<?= $filterPriority?"&priority=$filterPriority":'' ?>"
         class="<?= $filterStatus==='dismissed'?'active':'' ?>"><?= pt('Dismissed') ?></a>
      <a href="?status=all<?= $filterPriority?"&priority=$filterPriority":'' ?>"
         class="<?= $filterStatus==='all'?'active':'' ?>"><?= pt('All') ?></a>
    </div>
  </form>

  <!-- RECOMMENDATIONS LIST -->
  <?php if (empty($recommendations)): ?>
    <div class="empty-state">
      <div class="empty-icon">✦</div>
      <h3><?= pt('No recommendations') ?></h3>
      <p>
        <?php if ($filterStatus === 'active'): ?>
          <?= pt('Your portfolio looks healthy — no active recommendations right now. Click Refresh to re-analyse.') ?>
        <?php else: ?>
          <?= pt('No recommendations found for the selected filters.') ?>
        <?php endif; ?>
      </p>
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="generate">
        <button type="submit" class="btn btn-generate">↻ <?= pt('Generate Recommendations') ?></button>
      </form>
    </div>
  <?php else: ?>
    <div class="rec-list">
      <?php foreach ($recommendations as $rec):
        $color = $priorityColors[$rec['priority']] ?? '#6c757d';
        $isActive = in_array($rec['status'], ['new','viewed']);
      ?>
      <div class="rec-card">
        <div class="rec-header">
          <div class="priority-dot" style="background:<?= $color ?>;margin-top:6px;"></div>
          <div class="rec-meta">
            <div class="rec-title"><?= htmlspecialchars($rec['title']) ?></div>
            <?php if ($rec['business_name']): ?>
              <div class="rec-business">📍 <?= htmlspecialchars($rec['business_name']) ?></div>
            <?php endif; ?>
            <div class="rec-date"><?= date('j M Y', strtotime($rec['created_at'])) ?></div>
          </div>
          <div class="rec-badges">
            <span class="priority-badge" style="background:<?= $color ?>20;color:<?= $color ?>">
              <?= $priorityLabels[$rec['priority']] ?? ucfirst($rec['priority']) ?>
            </span>
            <?= $statusBadge[$rec['status']] ?? '' ?>
          </div>
        </div>

        <div class="rec-body">
          <p class="rec-desc"><?= htmlspecialchars($rec['description']) ?></p>
          <div class="rec-footer">
            <div class="rec-objective">
              <?php if ($rec['objective']): ?>
                <span><?= pt('Objective:') ?></span> <?= htmlspecialchars($rec['objective']) ?>
              <?php endif; ?>
            </div>
            <div class="rec-actions">
              <?php if ($isActive): ?>
                <?php if ($rec['action_url']): ?>
                  <a href="<?= htmlspecialchars($rec['action_url']) ?>" class="btn btn-action">
                    <?= htmlspecialchars($rec['recommended_action'] ?? pt('Take Action')) ?> →
                  </a>
                <?php endif; ?>
                <form method="post" style="display:inline">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="mark_actioned">
                  <input type="hidden" name="rec_id" value="<?= $rec['id'] ?>">
                  <button type="submit" class="btn btn-outline">✓ <?= pt('Done') ?></button>
                </form>
                <form method="post" style="display:inline">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="dismiss">
                  <input type="hidden" name="rec_id" value="<?= $rec['id'] ?>">
                  <button type="submit" class="btn btn-outline" onclick="return confirm('<?= pt('Dismiss this recommendation?') ?>')">✕</button>
                </form>
              <?php else: ?>
                <span style="font-size:.8rem;color:#adb5bd;">
                  <?= $rec['status'] === 'actioned' ? '✓ ' . pt('Actioned') . ' ' . date('j M', strtotime($rec['actioned_at'] ?? $rec['updated_at'])) : pt('Dismissed') ?>
                </span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- INFO NOTE -->
    <div class="alert-box alert-info" style="margin-top:20px;">
      <strong>✦ <?= pt('About these recommendations') ?></strong><br>
      <?= pt('Recommendations are generated automatically by analysing activity across your portfolio. They highlight opportunities and issues — you decide which to action, dismiss, or ignore.') ?>
    </div>

  <?php endif; ?>
</div>
</body>
</html>
