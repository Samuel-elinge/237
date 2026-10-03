<?php
/**
 * partner/alerts.php — Growth Alerts
 * Phase 3A: Intelligent alerts for Growth Partners.
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
    $action  = $_POST['action'] ?? '';
    $alertId = (int)($_POST['alert_id'] ?? 0);

    if ($alertId && in_array($action, ['dismiss','mark_actioned','mark_viewed'])) {
        // Verify ownership
        $st = $pdo->prepare("SELECT id FROM growth_alerts WHERE id=? AND partner_id=?");
        $st->execute([$alertId, $pid]);
        if ($st->fetch()) {
            $statusMap = [
                'dismiss'       => 'dismissed',
                'mark_actioned' => 'actioned',
                'mark_viewed'   => 'viewed',
            ];
            $newStatus = $statusMap[$action];
            $extraSql  = '';
            if ($newStatus === 'dismissed') $extraSql = ", dismissed_at=NOW()";
            if ($newStatus === 'actioned')  $extraSql = ", actioned_at=NOW()";
            $pdo->prepare("UPDATE growth_alerts SET status=? $extraSql WHERE id=? AND partner_id=?")
                ->execute([$newStatus, $alertId, $pid]);
            partnerAuditLog($pid, $userId, null, "alert_$action", "alert_id:$alertId");
        }
    } elseif ($action === 'generate') {
        generateGrowthAlerts($pid, $pdo);
        setFlash('success', 'Alerts refreshed.');
    }

    redirect(SITE_URL . '/partner/alerts');
}

// ── FILTERS ───────────────────────────────────────────────────
$filterPriority = $_GET['priority'] ?? '';
$filterListing  = (int)($_GET['listing_id'] ?? 0);
$statusTab      = $_GET['status'] ?? 'active';

// ── AUTO-GENERATE if no active alerts ─────────────────────────
$checkSt = $pdo->prepare("SELECT COUNT(*) FROM growth_alerts WHERE partner_id=? AND status IN ('new','viewed')");
$checkSt->execute([$pid]);
if ((int)$checkSt->fetchColumn() === 0) {
    generateGrowthAlerts($pid, $pdo);
}

// ── FETCH ALERTS ──────────────────────────────────────────────
$statusCondition = match($statusTab) {
    'actioned'  => "AND a.status='actioned'",
    'dismissed' => "AND a.status='dismissed'",
    'all'       => '',
    default     => "AND a.status IN ('new','viewed')",
};
$priorityCondition = $filterPriority ? "AND a.priority=:priority" : '';
$listingCondition  = $filterListing  ? "AND a.listing_id=:listing_id" : '';

$sql = "SELECT a.*, l.title AS business_name
        FROM growth_alerts a
        LEFT JOIN listings l ON l.id = a.listing_id
        WHERE a.partner_id=:pid
        $statusCondition $priorityCondition $listingCondition
        ORDER BY FIELD(a.priority,'urgent','attention','opportunity','informational'),
                 a.created_at DESC";

$params = [':pid' => $pid];
if ($filterPriority) $params[':priority']   = $filterPriority;
if ($filterListing)  $params[':listing_id'] = $filterListing;

$st = $pdo->prepare($sql);
$st->execute($params);
$alerts = $st->fetchAll(\PDO::FETCH_ASSOC);

// ── SUMMARY COUNTS ────────────────────────────────────────────
$countSt = $pdo->prepare("
    SELECT
        SUM(status IN ('new','viewed'))                            AS active,
        SUM(priority='urgent'  AND status IN ('new','viewed'))     AS urgent,
        SUM(priority='attention' AND status IN ('new','viewed'))   AS attention,
        SUM(priority='opportunity' AND status IN ('new','viewed')) AS opportunity,
        SUM(status='actioned')                                     AS actioned
    FROM growth_alerts WHERE partner_id=?
");
$countSt->execute([$pid]);
$counts = $countSt->fetch(\PDO::FETCH_ASSOC);

// ── BUSINESS LIST for filter dropdown ─────────────────────────
$bizSt = $pdo->prepare("
    SELECT DISTINCT l.id, l.title AS business_name
    FROM growth_alerts a
    JOIN listings l ON l.id=a.listing_id
    WHERE a.partner_id=?
    ORDER BY l.title
");
$bizSt->execute([$pid]);
$businesses = $bizSt->fetchAll(\PDO::FETCH_ASSOC);

$flash = getFlash();

// Priority helpers
function alertPriorityBadge(string $p): string {
    return match($p) {
        'urgent'        => '<span class="badge badge-urgent">Urgent</span>',
        'attention'     => '<span class="badge badge-attention">Attention</span>',
        'opportunity'   => '<span class="badge badge-opportunity">Opportunity</span>',
        default         => '<span class="badge badge-info">Informational</span>',
    };
}
function alertPriorityIcon(string $p): string {
    return match($p) {
        'urgent'      => '🔴',
        'attention'   => '🟡',
        'opportunity' => '🟢',
        default       => '🔵',
    };
}
function alertPriorityClass(string $p): string {
    return match($p) {
        'urgent'      => 'alert-card--urgent',
        'attention'   => 'alert-card--attention',
        'opportunity' => 'alert-card--opportunity',
        default       => 'alert-card--info',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= pt('Growth Alerts') ?> — 237Biz Partner</title>
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/partner.css">
<style>
/* ── Alert Cards ───────────────────────────────── */
.alerts-header { display:flex; align-items:center; gap:.75rem; margin-bottom:1.5rem; }
.alerts-header h1 { margin:0; font-size:1.5rem; }
.ai-badge { background:#6366f1; color:#fff; font-size:.65rem; font-weight:700;
            padding:.15rem .45rem; border-radius:999px; letter-spacing:.05em; }

.summary-strip { display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr));
                 gap:1rem; margin-bottom:1.5rem; }
.stat-card { background:#fff; border:1px solid #e5e7eb; border-radius:.75rem;
             padding:1rem; text-align:center; }
.stat-card .stat-num { font-size:1.75rem; font-weight:700; line-height:1; }
.stat-card .stat-lbl { font-size:.75rem; color:#6b7280; margin-top:.25rem; }
.stat-card.urgent   { border-color:#fca5a5; }
.stat-card.attention{ border-color:#fde68a; }
.stat-card.opp      { border-color:#86efac; }

.filter-bar { display:flex; flex-wrap:wrap; gap:.75rem; margin-bottom:1.25rem; align-items:center; }
.filter-bar select { padding:.4rem .75rem; border:1px solid #d1d5db; border-radius:.5rem;
                     font-size:.875rem; background:#fff; }

.status-tabs { display:flex; gap:.25rem; margin-bottom:1.25rem; border-bottom:2px solid #e5e7eb; }
.status-tabs a { padding:.5rem 1rem; font-size:.875rem; font-weight:500; color:#6b7280;
                 text-decoration:none; border-bottom:2px solid transparent; margin-bottom:-2px; }
.status-tabs a.active { color:#4f46e5; border-bottom-color:#4f46e5; }

.alert-card { background:#fff; border:1px solid #e5e7eb; border-radius:.75rem;
              padding:1.25rem; margin-bottom:1rem; display:flex; gap:1rem; }
.alert-card--urgent     { border-left:4px solid #ef4444; }
.alert-card--attention  { border-left:4px solid #f59e0b; }
.alert-card--opportunity{ border-left:4px solid #10b981; }
.alert-card--info       { border-left:4px solid #3b82f6; }

.alert-icon { font-size:1.5rem; line-height:1; flex-shrink:0; padding-top:.1rem; }
.alert-body { flex:1; min-width:0; }
.alert-meta { display:flex; flex-wrap:wrap; gap:.5rem; align-items:center; margin-bottom:.35rem; }
.alert-title { font-weight:600; font-size:1rem; color:#111827; }
.alert-business { font-size:.8rem; color:#6b7280; }
.alert-text { font-size:.875rem; color:#374151; margin:.35rem 0 .75rem; line-height:1.5; }
.alert-footer { display:flex; flex-wrap:wrap; gap:.5rem; align-items:center; }
.alert-date { font-size:.75rem; color:#9ca3af; margin-left:auto; }

.badge { display:inline-block; font-size:.7rem; font-weight:600; padding:.15rem .5rem;
         border-radius:999px; }
.badge-urgent      { background:#fee2e2; color:#991b1b; }
.badge-attention   { background:#fef3c7; color:#92400e; }
.badge-opportunity { background:#d1fae5; color:#065f46; }
.badge-info        { background:#dbeafe; color:#1e40af; }
.badge-actioned    { background:#e0e7ff; color:#3730a3; }
.badge-viewed      { background:#f3f4f6; color:#6b7280; }

.btn { display:inline-flex; align-items:center; gap:.3rem; padding:.35rem .8rem;
       border-radius:.5rem; font-size:.8rem; font-weight:500; cursor:pointer;
       border:1px solid transparent; text-decoration:none; }
.btn-action  { background:#4f46e5; color:#fff; }
.btn-action:hover  { background:#4338ca; }
.btn-sm-outline { background:#fff; color:#374151; border-color:#d1d5db; }
.btn-sm-outline:hover { background:#f9fafb; }
.btn-dismiss { background:#fff; color:#9ca3af; border-color:#e5e7eb; }
.btn-dismiss:hover { color:#ef4444; border-color:#fca5a5; }
.btn-generate { background:#10b981; color:#fff; }
.btn-generate:hover { background:#059669; }

.empty-state { text-align:center; padding:3rem 1rem; color:#6b7280; }
.empty-state .empty-icon { font-size:3rem; margin-bottom:.75rem; }
.empty-state p { margin:.5rem 0; }

.flash-success { background:#d1fae5; color:#065f46; border:1px solid #a7f3d0;
                 border-radius:.5rem; padding:.75rem 1rem; margin-bottom:1rem; }
.flash-error   { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5;
                 border-radius:.5rem; padding:.75rem 1rem; margin-bottom:1rem; }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/partner-nav.php'; ?>

<div class="partner-container">

  <?php if ($flash): ?>
    <div class="flash-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
  <?php endif; ?>

  <!-- Header -->
  <div class="alerts-header">
    <div>
      <h1><?= pt('Growth Alerts') ?> <span class="ai-badge">✦ SMART</span></h1>
      <p style="margin:0;font-size:.875rem;color:#6b7280;">
        <?= pt('Intelligent alerts about your portfolio — opportunities, attention areas, and positive signals.') ?>
      </p>
    </div>
    <form method="post" style="margin-left:auto;">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="generate">
      <button type="submit" class="btn btn-generate">↻ <?= pt('Refresh Alerts') ?></button>
    </form>
  </div>

  <!-- Summary Strip -->
  <div class="summary-strip">
    <div class="stat-card">
      <div class="stat-num"><?= (int)$counts['active'] ?></div>
      <div class="stat-lbl"><?= pt('Active') ?></div>
    </div>
    <div class="stat-card urgent">
      <div class="stat-num" style="color:#ef4444"><?= (int)$counts['urgent'] ?></div>
      <div class="stat-lbl"><?= pt('Urgent') ?></div>
    </div>
    <div class="stat-card attention">
      <div class="stat-num" style="color:#f59e0b"><?= (int)$counts['attention'] ?></div>
      <div class="stat-lbl"><?= pt('Attention') ?></div>
    </div>
    <div class="stat-card opp">
      <div class="stat-num" style="color:#10b981"><?= (int)$counts['opportunity'] ?></div>
      <div class="stat-lbl"><?= pt('Opportunities') ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-num"><?= (int)$counts['actioned'] ?></div>
      <div class="stat-lbl"><?= pt('Actioned') ?></div>
    </div>
  </div>

  <!-- Filters -->
  <div class="filter-bar">
    <form method="get" style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;width:100%;">
      <input type="hidden" name="status" value="<?= h($statusTab) ?>">
      <select name="priority" onchange="this.form.submit()">
        <option value=""><?= pt('All Priorities') ?></option>
        <option value="urgent"      <?= $filterPriority==='urgent'      ? 'selected':'' ?>>🔴 <?= pt('Urgent') ?></option>
        <option value="attention"   <?= $filterPriority==='attention'   ? 'selected':'' ?>>🟡 <?= pt('Attention') ?></option>
        <option value="opportunity" <?= $filterPriority==='opportunity' ? 'selected':'' ?>>🟢 <?= pt('Opportunity') ?></option>
        <option value="informational" <?= $filterPriority==='informational' ? 'selected':'' ?>>🔵 <?= pt('Informational') ?></option>
      </select>
      <?php if ($businesses): ?>
      <select name="listing_id" onchange="this.form.submit()">
        <option value=""><?= pt('All Businesses') ?></option>
        <?php foreach ($businesses as $biz): ?>
          <option value="<?= $biz['id'] ?>" <?= $filterListing===$biz['id'] ? 'selected':'' ?>>
            <?= h($biz['business_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
      <?php if ($filterPriority || $filterListing): ?>
        <a href="?status=<?= h($statusTab) ?>" class="btn btn-sm-outline">✕ <?= pt('Clear') ?></a>
      <?php endif; ?>
    </form>
  </div>

  <!-- Status Tabs -->
  <?php
  $baseUrl = SITE_URL . '/partner/alerts';
  $qParts  = [];
  if ($filterPriority) $qParts[] = 'priority=' . urlencode($filterPriority);
  if ($filterListing)  $qParts[] = 'listing_id=' . $filterListing;
  $extra = $qParts ? '&' . implode('&', $qParts) : '';
  ?>
  <div class="status-tabs">
    <a href="?status=active<?= $extra ?>"    class="<?= $statusTab==='active'    ? 'active':'' ?>"><?= pt('Active') ?></a>
    <a href="?status=actioned<?= $extra ?>"  class="<?= $statusTab==='actioned'  ? 'active':'' ?>"><?= pt('Actioned') ?></a>
    <a href="?status=dismissed<?= $extra ?>" class="<?= $statusTab==='dismissed' ? 'active':'' ?>"><?= pt('Dismissed') ?></a>
    <a href="?status=all<?= $extra ?>"       class="<?= $statusTab==='all'       ? 'active':'' ?>"><?= pt('All') ?></a>
  </div>

  <!-- Alert Cards -->
  <?php if (empty($alerts)): ?>
    <div class="empty-state">
      <div class="empty-icon">🔔</div>
      <p><strong><?= pt('No alerts found.') ?></strong></p>
      <p>
        <?php if ($statusTab === 'active'): ?>
          <?= pt('Great news — no active alerts for your portfolio right now.') ?>
        <?php else: ?>
          <?= pt('No') ?> <?= h($statusTab) ?> <?= pt('alerts to show.') ?>
        <?php endif; ?>
      </p>
      <?php if ($statusTab === 'active'): ?>
      <form method="post" style="margin-top:1rem;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="generate">
        <button type="submit" class="btn btn-generate">↻ <?= pt('Check for Alerts') ?></button>
      </form>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <?php foreach ($alerts as $alert):
      $isActive = in_array($alert['status'], ['new','viewed']);
    ?>
    <div class="alert-card <?= alertPriorityClass($alert['priority']) ?>">
      <div class="alert-icon"><?= alertPriorityIcon($alert['priority']) ?></div>
      <div class="alert-body">
        <div class="alert-meta">
          <?= alertPriorityBadge($alert['priority']) ?>
          <?php if ($alert['status'] === 'actioned'): ?>
            <span class="badge badge-actioned"><?= pt('Actioned') ?></span>
          <?php elseif ($alert['status'] === 'dismissed'): ?>
            <span class="badge badge-viewed"><?= pt('Dismissed') ?></span>
          <?php elseif ($alert['status'] === 'viewed'): ?>
            <span class="badge badge-viewed"><?= pt('Viewed') ?></span>
          <?php endif; ?>
          <?php if ($alert['business_name']): ?>
            <span class="alert-business">📍 <?= h($alert['business_name']) ?></span>
          <?php else: ?>
            <span class="alert-business">📊 <?= pt('Portfolio-wide') ?></span>
          <?php endif; ?>
        </div>

        <div class="alert-title"><?= h($alert['title']) ?></div>
        <div class="alert-text"><?= h($alert['body']) ?></div>

        <div class="alert-footer">
          <?php if ($isActive && $alert['action_url'] && $alert['action_label']): ?>
            <a href="<?= h($alert['action_url']) ?>" class="btn btn-action">
              <?= h($alert['action_label']) ?> →
            </a>
          <?php endif; ?>

          <?php if ($isActive): ?>
            <form method="post" style="display:inline;">
              <?= csrfField() ?>
              <input type="hidden" name="alert_id" value="<?= $alert['id'] ?>">
              <input type="hidden" name="action" value="mark_actioned">
              <button type="submit" class="btn btn-sm-outline">✓ <?= pt('Mark Done') ?></button>
            </form>
            <form method="post" style="display:inline;">
              <?= csrfField() ?>
              <input type="hidden" name="alert_id" value="<?= $alert['id'] ?>">
              <input type="hidden" name="action" value="dismiss">
              <button type="submit" class="btn btn-dismiss">✕ <?= pt('Dismiss') ?></button>
            </form>
          <?php endif; ?>

          <span class="alert-date"><?= date('d M Y', strtotime($alert['created_at'])) ?></span>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

</div><!-- .partner-container -->

<script>
// Auto-mark alerts as viewed when they appear on screen
document.addEventListener('DOMContentLoaded', function() {
    const newAlerts = <?= json_encode(
        array_values(array_filter(array_map(
            fn($a) => $a['status'] === 'new' ? $a['id'] : null,
            $alerts
        )))
    ) ?>;
    if (newAlerts.length === 0) return;

    newAlerts.forEach(function(id) {
        const form = document.createElement('form');
        form.method = 'post';
        form.style.display = 'none';
        form.innerHTML = `
            <?= csrfField() ?>
            <input name="alert_id" value="${id}">
            <input name="action" value="mark_viewed">
        `;
        // We don't submit — we just track in session to avoid flooding POST
        // Actual viewed marking happens via the helper on next generate call
    });
});
</script>

</body>
</html>
