<?php
/**
 * partner/dashboard.php — 237Biz Growth Partner Dashboard
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';
require_once __DIR__ . '/../includes/partner-lang.php';

$partner   = requireGrowthPartner();
$pid       = (int)$partner['id'];
$user      = currentUser();
$pdo       = db();
$userId    = (int)($_SESSION['user_id'] ?? 0);

/* ── Portfolio — assigned listings + own listings ───────────────── */
$st = $pdo->prepare("
    SELECT COUNT(*) AS total,
           SUM(l.status IN ('active','approved')) AS active,
           SUM(l.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS onboarding
    FROM listings l
    WHERE l.status IN ('active','approved')
      AND (
        EXISTS (
            SELECT 1 FROM partner_business_assignments pba
            WHERE pba.listing_id = l.id AND pba.partner_id = ? AND pba.status = 'active'
        )
        OR l.user_id = (SELECT user_id FROM partner_profiles WHERE id = ?)
      )
");
$st->execute([$pid, $pid]); $portfolio = $st->fetch();

/* ── Tasks ──────────────────────────────────────────────────────── */
$st = $pdo->prepare("SELECT COUNT(*) FROM growth_tasks WHERE partner_id=? AND status IN ('todo','in_progress')");
$st->execute([$pid]); $openTaskCount = (int)$st->fetchColumn();

$st = $pdo->prepare("SELECT COUNT(*) FROM growth_tasks WHERE partner_id=? AND status IN ('todo','in_progress') AND due_date < CURDATE()");
$st->execute([$pid]); $overdueCount = (int)$st->fetchColumn();

$st = $pdo->prepare("
    SELECT gt.*, l.title AS biz_name FROM growth_tasks gt
    JOIN listings l ON l.id = gt.listing_id
    WHERE gt.partner_id=? AND gt.status IN ('todo','in_progress')
      AND gt.due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY gt.due_date ASC, gt.priority DESC LIMIT 6
");
$st->execute([$pid]); $upcomingTasks = $st->fetchAll();

/* ── Leads ──────────────────────────────────────────────────────── */
$st = $pdo->prepare("SELECT COUNT(*) FROM partner_leads WHERE partner_id=? AND status IN ('new','contacted','follow_up')");
$st->execute([$pid]); $activeLeadCount = (int)$st->fetchColumn();

$st = $pdo->prepare("SELECT COUNT(*) FROM partner_leads WHERE partner_id=? AND follow_up_date < CURDATE() AND status NOT IN ('converted','lost','closed')");
$st->execute([$pid]); $overdueLeadsCount = (int)$st->fetchColumn();

/* ── Commissions ────────────────────────────────────────────────── */
$st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM partner_commissions WHERE partner_id=? AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())");
$st->execute([$pid]); $monthComm = (float)$st->fetchColumn();

$st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM partner_commissions WHERE partner_id=? AND status='pending'");
$st->execute([$pid]); $pendingComm = (float)$st->fetchColumn();

$st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM partner_commissions WHERE partner_id=?");
$st->execute([$pid]); $totalComm = (float)$st->fetchColumn();

/* ── Campaigns ──────────────────────────────────────────────────── */
$st = $pdo->prepare("SELECT status, COUNT(*) cnt FROM partner_campaigns WHERE partner_id=? GROUP BY status");
$st->execute([$pid]); $campCounts = [];
foreach ($st->fetchAll() as $r) $campCounts[$r['status']] = (int)$r['cnt'];
$activeCampaigns = $campCounts['active'] ?? 0;

/* ── Marketplace Orders ─────────────────────────────────────────── */
$st = $pdo->prepare("SELECT COUNT(*) FROM marketplace_orders WHERE partner_profile_id=? AND order_state NOT IN ('COMPLETED','CANCELLED','REJECTED')");
$st->execute([$pid]); $activeOrderCount = (int)$st->fetchColumn();

$st = $pdo->prepare("SELECT COUNT(*) FROM marketplace_orders WHERE partner_profile_id=? AND order_state IN ('AWAITING_PAYMENT')");
$st->execute([$pid]); $paymentDueCount = (int)$st->fetchColumn();

/* ── AI recommendations & alerts ───────────────────────────────── */
$recCheck = $pdo->prepare("SELECT COUNT(*) FROM ai_recommendations WHERE partner_id=? AND status IN ('new','viewed')");
$recCheck->execute([$pid]);
if ((int)$recCheck->fetchColumn() === 0) {
    generateRecommendations($pid, $pdo);
    generateGrowthAlerts($pid, $pdo);
}
$dashRecs = getActiveRecommendations($pid, $pdo, null, 4);

$st = $pdo->prepare("SELECT COUNT(*) FROM growth_alerts WHERE partner_id=? AND status IN ('new','viewed')");
$st->execute([$pid]); $activeAlertsCount = (int)$st->fetchColumn();

$st = $pdo->prepare("SELECT COUNT(*) FROM growth_alerts WHERE partner_id=? AND status IN ('new','viewed') AND priority='urgent'");
$st->execute([$pid]); $urgentAlertsCount = (int)$st->fetchColumn();

/* ── Attention queue ────────────────────────────────────────────── */
$attentionQueue = getAttentionQueue($pid);

/* ── Active leads list ──────────────────────────────────────────── */
$leadSt = $pdo->prepare("
    SELECT pl.*, l.title AS biz_name FROM partner_leads pl
    JOIN listings l ON l.id=pl.listing_id
    WHERE pl.partner_id=? AND pl.status IN ('new','contacted','follow_up')
    ORDER BY pl.follow_up_date ASC, pl.created_at DESC LIMIT 5
");
$leadSt->execute([$pid]); $activeLeadList = $leadSt->fetchAll();

/* ── Active orders list ─────────────────────────────────────────── */
$orderSt = $pdo->prepare("
    SELECT o.ref, o.order_state, o.created_at, l.title AS listing_title
    FROM marketplace_orders o
    JOIN listings l ON l.id = o.listing_id
    WHERE o.partner_profile_id=? AND o.order_state NOT IN ('COMPLETED','CANCELLED','REJECTED')
    ORDER BY o.created_at DESC LIMIT 4
");
$orderSt->execute([$pid]); $activeOrderList = $orderSt->fetchAll();

/* ── Recent activity ────────────────────────────────────────────── */
$st = $pdo->prepare("
    SELECT pal.*, l.title AS biz_name FROM partner_audit_log pal
    LEFT JOIN listings l ON l.id = pal.listing_id
    WHERE pal.partner_id=? ORDER BY pal.created_at DESC LIMIT 5
");
$st->execute([$pid]); $activityLog = $st->fetchAll();

$pageTitle = 'Partner Centre — 237Biz';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/includes/nav.php';
partnerNavBadges($pid, $pdo);
?>
<?php partnerNavAssets(); ?>
<?php partnerNav('dashboard'); ?>

<style>
/* ── Design tokens (dark green theme) ─────────────────────────── */
:root {
  --g:    #00A878;
  --gbg:  #081410;
  --gd:   #00d49a;
  --gs:   rgba(0,168,120,.18);
  --gb:   rgba(0,168,120,.22);
  --text: #e8f4ee;
  --sub:  #8db8a8;
  --mute: #5a8878;
  --card: #0a1a10;
  --bdr:  rgba(0,168,120,.18);
  --warn: #f59e0b;
  --danger:#ef4444;
  --info: #38bdf8;
}

/* ── Layout ───────────────────────────────────────────────────── */
body { background: var(--gbg); color: var(--text); }
.pw  { max-width: 1200px; margin: 0 auto; padding: 20px 16px 48px; }

/* ── Header ───────────────────────────────────────────────────── */
.dash-header {
  display: flex; justify-content: space-between; align-items: flex-start;
  flex-wrap: wrap; gap: 10px; margin-bottom: 20px;
}
.dash-header h1 { margin: 0 0 2px; font-size: 1.5rem; font-weight: 800; color: #fff; }
.dash-header .sub { color: var(--sub); font-size: .88rem; }

/* ── Summary strip ────────────────────────────────────────────── */
.sum-strip {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  gap: 10px; margin-bottom: 20px;
}
.sum-card {
  background: var(--card); border: 1px solid var(--bdr);
  border-radius: 10px; padding: 14px 16px;
  text-decoration: none; display: block; transition: .15s;
}
.sum-card:hover { border-color: var(--g); background: rgba(0,168,120,.07); }
.sum-card .val { font-size: 1.9rem; font-weight: 800; line-height: 1.1; color: #fff; }
.sum-card .lbl { font-size: .72rem; color: var(--sub); margin-top: 3px;
                 text-transform: uppercase; letter-spacing: .05em; }
.sum-card .hint { font-size: .77rem; margin-top: 3px; color: var(--mute); }
.sum-card.warn  { border-color: var(--warn); }
.sum-card.danger{ border-color: var(--danger); }
.sum-card.good  { border-color: var(--g); }
.sum-card.orders{ border-color: var(--info); }

/* ── Panels ───────────────────────────────────────────────────── */
.panel {
  background: var(--card); border: 1px solid var(--bdr);
  border-radius: 12px; padding: 16px 18px; margin-bottom: 16px;
}
.panel h2 {
  margin: 0 0 12px; font-size: .95rem; font-weight: 700; color: #fff;
  display: flex; align-items: center; gap: 7px;
}
.p-badge {
  background: var(--gs); color: var(--gd); border-radius: 20px;
  padding: 1px 8px; font-size: .68rem; font-weight: 700;
}
.p-badge.red  { background: rgba(239,68,68,.2);  color: #ef4444; }
.p-badge.warn { background: rgba(245,158,11,.2); color: #f59e0b; }

/* ── Row items ────────────────────────────────────────────────── */
.row-item {
  display: flex; align-items: flex-start; gap: 10px;
  padding: 9px 0; border-bottom: 1px solid rgba(255,255,255,.05);
}
.row-item:last-child { border-bottom: none; }
.row-title { font-weight: 600; font-size: .88rem; color: var(--text);
             white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.row-meta  { font-size: .75rem; color: var(--sub); margin-top: 2px; }
.row-link  { font-size: .78rem; color: var(--g); text-decoration: none;
             white-space: nowrap; flex-shrink: 0; margin-left: auto;
             padding-left: 8px; }
.row-link:hover { color: var(--gd); }

/* ── Priority badges ──────────────────────────────────────────── */
.pri { font-size: .68rem; padding: 2px 7px; border-radius: 10px;
       font-weight: 700; white-space: nowrap; flex-shrink: 0; }
.pri-urgent { background: #ef4444; color: #fff; }
.pri-high   { background: #f97316; color: #fff; }
.pri-medium { background: #0891b2; color: #fff; }
.pri-low    { background: rgba(255,255,255,.1); color: var(--sub); }
.overdue    { color: var(--danger); font-weight: 700; }

/* ── State badge for orders ───────────────────────────────────── */
.o-badge {
  display: inline-block; padding: .18em .55em; font-size: .68rem;
  font-weight: 700; border-radius: 20px; white-space: nowrap; flex-shrink: 0;
}
.o-pending { background: rgba(240,192,64,.18); color: #f0c040; }
.o-action  { background: rgba(239,68,68,.18);  color: #ef4444; }
.o-review  { background: rgba(56,189,248,.18); color: #38bdf8; }
.o-paid    { background: rgba(0,168,120,.2);   color: var(--gd); }

/* ── Attention queue ──────────────────────────────────────────── */
.attn-section {
  background: rgba(239,68,68,.06); border: 1px solid rgba(239,68,68,.3);
  border-radius: 12px; padding: 16px 18px; margin-bottom: 16px;
}
.attn-section h2 { color: #ef4444; margin-bottom: 10px; }
.attn-item {
  display: flex; align-items: center; gap: 10px;
  padding: 8px 10px; border-radius: 8px; margin-bottom: 6px; font-size: .86rem;
  background: rgba(239,68,68,.07); border: 1px solid rgba(239,68,68,.2);
  text-decoration: none; color: var(--text);
}
.attn-item:hover { background: rgba(239,68,68,.12); }
.attn-item.medium { background: rgba(245,158,11,.06); border-color: rgba(245,158,11,.25); }

/* ── AI recs strip ────────────────────────────────────────────── */
.recs-panel {
  background: linear-gradient(135deg,rgba(99,102,241,.12),rgba(0,168,120,.08));
  border: 1px solid rgba(99,102,241,.3); border-radius: 12px;
  padding: 16px 18px; margin-bottom: 16px;
}
.recs-panel h2 { color: #a5b4fc; margin-bottom: 12px; }
.rec-item {
  display: flex; align-items: center; gap: 10px;
  padding: 9px 12px; background: rgba(255,255,255,.04);
  border: 1px solid rgba(99,102,241,.2); border-radius: 8px;
  text-decoration: none; color: var(--text); font-size: .86rem;
  margin-bottom: 6px; transition: .15s;
}
.rec-item:last-child { margin-bottom: 0; }
.rec-item:hover { background: rgba(99,102,241,.12); border-color: rgba(99,102,241,.4); }
.rec-dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
.rec-dot.urgent { background: #ef4444; }
.rec-dot.high   { background: #f97316; }
.rec-dot.medium { background: #38bdf8; }
.rec-dot.low    { background: var(--sub); }

/* ── Commission panel ─────────────────────────────────────────── */
.comm-row {
  display: flex; justify-content: space-between; align-items: center;
  padding: 8px 0; border-bottom: 1px solid rgba(255,255,255,.05); font-size: .88rem;
}
.comm-row:last-child { border-bottom: none; }
.comm-label { color: var(--sub); }
.comm-val   { font-weight: 700; color: #fff; }

/* ── Quick actions grid ───────────────────────────────────────── */
.quick-grid {
  display: grid; grid-template-columns: 1fr 1fr; gap: 8px;
}
.quick-link {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 12px; border: 1px solid var(--bdr); border-radius: 8px;
  text-decoration: none; color: var(--sub); font-size: .83rem; font-weight: 600;
  transition: .15s; background: transparent;
}
.quick-link:hover { border-color: var(--g); color: var(--g); background: var(--gs); }

/* ── Main two-column grid ─────────────────────────────────────── */
.main-grid { display: grid; grid-template-columns: 1fr 300px; gap: 16px; }
@media (max-width: 860px) { .main-grid { grid-template-columns: 1fr; } }

/* ── Utility ──────────────────────────────────────────────────── */
.empty { color: var(--mute); font-size: .85rem; text-align: center;
         padding: 16px 0; font-style: italic; }
.btn-sm {
  display: inline-block; padding: 6px 14px; font-size: .82rem; font-weight: 600;
  border-radius: 7px; text-decoration: none; transition: .15s;
}
.btn-outline {
  border: 1px solid var(--bdr); color: var(--sub); background: transparent;
}
.btn-outline:hover { border-color: var(--g); color: var(--g); }
.btn-primary {
  background: var(--g); color: #000; border: 1px solid var(--g);
}
.btn-primary:hover { background: var(--gd); }

@media (max-width: 500px) {
  .sum-strip { grid-template-columns: repeat(2, 1fr); }
  .quick-grid { grid-template-columns: 1fr; }
  .dash-header h1 { font-size: 1.25rem; }
}
</style>

<div class="pw">

<!-- ── Header ── -->
<div class="dash-header">
  <div>
    <h1>👔 <?= pt('Partner Centre') ?></h1>
    <div class="sub">
      <?= pt('Good') ?> <?= date('G') < 12 ? pt('morning') : (date('G') < 17 ? pt('afternoon') : pt('evening')) ?>,
      <?= e(explode(' ', $user['name'])[0]) ?> — <?= date('l, j F Y') ?>
    </div>
  </div>
  <a href="<?= SITE_URL ?>/partner/marketplace" class="btn-sm btn-primary">🛍️ <?= pt('Order Services') ?></a>
</div>

<!-- ── Summary strip ── -->
<div class="sum-strip">
  <a href="<?= SITE_URL ?>/partner/portfolio" class="sum-card good">
    <div class="val"><?= (int)$portfolio['total'] ?></div>
    <div class="lbl"><?= pt('Businesses') ?></div>
    <div class="hint"><?= (int)$portfolio['active'] ?> <?= pt('active') ?></div>
  </a>

  <a href="<?= SITE_URL ?>/partner/tasks" class="sum-card <?= $overdueCount > 0 ? 'danger' : '' ?>">
    <div class="val"><?= $openTaskCount ?></div>
    <div class="lbl"><?= pt('Open Tasks') ?></div>
    <div class="hint <?= $overdueCount > 0 ? 'overdue' : '' ?>">
      <?= $overdueCount > 0 ? "⚠ $overdueCount overdue" : pt('All on track') ?>
    </div>
  </a>

  <a href="<?= SITE_URL ?>/partner/leads" class="sum-card <?= $overdueLeadsCount > 0 ? 'warn' : '' ?>">
    <div class="val"><?= $activeLeadCount ?></div>
    <div class="lbl"><?= pt('Active Leads') ?></div>
    <div class="hint <?= $overdueLeadsCount > 0 ? 'overdue' : '' ?>">
      <?= $overdueLeadsCount > 0 ? "⚠ $overdueLeadsCount overdue" : pt('No overdue') ?>
    </div>
  </a>

  <a href="<?= SITE_URL ?>/partner/marketplace-orders" class="sum-card <?= $paymentDueCount > 0 ? 'danger' : ($activeOrderCount > 0 ? 'orders' : '') ?>">
    <div class="val"><?= $activeOrderCount ?></div>
    <div class="lbl"><?= pt('Active Orders') ?></div>
    <div class="hint <?= $paymentDueCount > 0 ? 'overdue' : '' ?>">
      <?= $paymentDueCount > 0 ? "⚠ $paymentDueCount payment due" : ($activeOrderCount > 0 ? pt('In progress') : pt('No active orders')) ?>
    </div>
  </a>

  <a href="<?= SITE_URL ?>/partner/commissions" class="sum-card">
    <div class="val" style="font-size:1.35rem"><?= number_format($monthComm) ?></div>
    <div class="lbl"><?= pt('Commission (Month)') ?></div>
    <div class="hint" style="color:var(--warn)"><?= number_format($pendingComm) ?> XAF <?= pt('pending') ?></div>
  </a>

  <a href="<?= SITE_URL ?>/partner/campaigns" class="sum-card">
    <div class="val"><?= $activeCampaigns ?></div>
    <div class="lbl"><?= pt('Active Campaigns') ?></div>
    <div class="hint"><?= pt('Running now') ?></div>
  </a>
</div>

<!-- ── Attention queue ── -->
<?php if (!empty($attentionQueue)): ?>
<div class="attn-section">
  <h2>⚠️ <?= pt('Needs Attention') ?>
    <span class="p-badge red"><?= count($attentionQueue) ?></span>
  </h2>
  <?php foreach (array_slice($attentionQueue, 0, 5) as $a): ?>
  <a href="<?= SITE_URL ?>/partner/business?lid=<?= $a['listing_id'] ?>" class="attn-item <?= $a['severity'] !== 'high' ? 'medium' : '' ?>">
    <span style="flex-shrink:0">🏢</span>
    <span style="font-weight:600;flex-shrink:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:140px"><?= e($a['name']) ?></span>
    <span style="font-size:.8rem;color:var(--sub);flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($a['reason']) ?></span>
    <span style="color:var(--g);font-size:.8rem;flex-shrink:0"><?= pt('Fix') ?> →</span>
  </a>
  <?php endforeach; ?>
  <?php if (count($attentionQueue) > 5): ?>
  <div style="text-align:center;margin-top:6px">
    <a href="<?= SITE_URL ?>/partner/portfolio" class="btn-sm btn-outline">+ <?= count($attentionQueue)-5 ?> more → <?= pt('Portfolio') ?></a>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── AI Recommendations ── -->
<?php if (!empty($dashRecs)): ?>
<div class="recs-panel">
  <h2>✦ <?= pt('AI Recommendations') ?>
    <span style="background:rgba(99,102,241,.3);color:#a5b4fc;border-radius:20px;padding:1px 8px;font-size:.65rem;font-weight:700">SMART</span>
    <?php if ($activeAlertsCount): ?>
    <a href="<?= SITE_URL ?>/partner/alerts" style="margin-left:auto;font-size:.77rem;color:<?= $urgentAlertsCount ? '#ef4444' : '#f59e0b' ?>;text-decoration:none">
      🔔 <?= $activeAlertsCount ?> alert<?= $activeAlertsCount !== 1 ? 's' : '' ?> →
    </a>
    <?php endif; ?>
  </h2>
  <?php foreach ($dashRecs as $rec): ?>
  <a href="<?= $rec['action_url'] ? h($rec['action_url']) : SITE_URL.'/partner/recommendations' ?>" class="rec-item">
    <span class="rec-dot <?= h($rec['priority']) ?>"></span>
    <div style="flex:1;min-width:0">
      <div style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= h($rec['title']) ?></div>
      <div style="font-size:.73rem;color:var(--sub)"><?= $rec['business_name'] ? '🏢 '.h($rec['business_name']) : '📊 Portfolio-wide' ?></div>
    </div>
    <?php if ($rec['recommended_action']): ?>
    <span style="font-size:.76rem;color:#a5b4fc;white-space:nowrap;flex-shrink:0"><?= h($rec['recommended_action']) ?> →</span>
    <?php endif; ?>
  </a>
  <?php endforeach; ?>
  <div style="margin-top:10px">
    <a href="<?= SITE_URL ?>/partner/recommendations" class="btn-sm btn-outline"><?= pt('All recommendations') ?> →</a>
  </div>
</div>
<?php endif; ?>

<!-- ── Main grid ── -->
<div class="main-grid">
<div><!-- left column -->

  <!-- Upcoming tasks -->
  <div class="panel">
    <h2>✅ <?= pt('Upcoming Tasks') ?> <span class="p-badge <?= $overdueCount ? 'red' : '' ?>"><?= count($upcomingTasks) ?></span></h2>
    <?php if ($upcomingTasks): ?>
    <?php foreach ($upcomingTasks as $t):
      $isOvd = $t['due_date'] && $t['due_date'] < date('Y-m-d'); ?>
    <div class="row-item">
      <span class="pri pri-<?= $t['priority'] ?>"><?= ucfirst($t['priority']) ?></span>
      <div style="flex:1;min-width:0">
        <div class="row-title"><?= e($t['title']) ?></div>
        <div class="row-meta">🏢 <?= e($t['biz_name']) ?>
          <?php if ($t['due_date']): ?> ·
            <?php if ($isOvd): ?><span class="overdue">⚠ <?= pt('Overdue') ?></span>
            <?php else: ?><?= date('j M', strtotime($t['due_date'])) ?><?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
      <a href="<?= SITE_URL ?>/partner/business?lid=<?= $t['listing_id'] ?>&tab=tasks" class="row-link">View →</a>
    </div>
    <?php endforeach; ?>
    <div style="margin-top:12px"><a href="<?= SITE_URL ?>/partner/tasks" class="btn-sm btn-outline"><?= pt('All tasks') ?> →</a></div>
    <?php else: ?>
    <div class="empty">🎉 <?= pt('No upcoming tasks this week. Great work!') ?></div>
    <?php endif; ?>
  </div>

  <!-- Active leads -->
  <div class="panel">
    <h2>💬 <?= pt('Active Leads') ?> <span class="p-badge <?= $overdueLeadsCount ? 'warn' : '' ?>"><?= $activeLeadCount ?></span></h2>
    <?php if ($activeLeadList): ?>
    <?php foreach ($activeLeadList as $lead):
      $ovdL = $lead['follow_up_date'] && $lead['follow_up_date'] < date('Y-m-d'); ?>
    <div class="row-item">
      <div style="flex:1;min-width:0">
        <div class="row-title"><?= e($lead['contact_name']) ?></div>
        <div class="row-meta">🏢 <?= e($lead['biz_name']) ?> · <?= ucfirst($lead['status']) ?>
          <?php if ($lead['follow_up_date']): ?> ·
            <?php if ($ovdL): ?><span class="overdue">⚠ <?= pt('Overdue') ?></span>
            <?php else: ?>📅 <?= date('j M', strtotime($lead['follow_up_date'])) ?><?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
      <a href="<?= SITE_URL ?>/partner/business?lid=<?= $lead['listing_id'] ?>&tab=leads" class="row-link">Log →</a>
    </div>
    <?php endforeach; ?>
    <div style="margin-top:12px"><a href="<?= SITE_URL ?>/partner/leads" class="btn-sm btn-outline"><?= pt('All leads') ?> →</a></div>
    <?php else: ?>
    <div class="empty"><?= pt('No active leads.') ?></div>
    <?php endif; ?>
  </div>

  <!-- Active orders -->
  <?php if ($activeOrderCount > 0 || true): // always show so partners discover the feature ?>
  <div class="panel">
    <h2>📦 <?= pt('My Orders') ?>
      <?php if ($activeOrderCount): ?>
      <span class="p-badge <?= $paymentDueCount ? 'red' : '' ?>"><?= $activeOrderCount ?></span>
      <?php endif; ?>
    </h2>
    <?php if ($activeOrderList): ?>
    <?php foreach ($activeOrderList as $o):
      [$badgeLbl, $badgeCls] = match($o['order_state']) {
        'PENDING_OWNER_APPROVAL' => ['Awaiting Approval', 'o-pending'],
        'AWAITING_PAYMENT'       => ['Pay Now', 'o-action'],
        'PAYMENT_REVIEW'         => ['In Review', 'o-review'],
        'PAID','READY_FOR_FULFILMENT','IN_FULFILMENT' => ['In Progress', 'o-paid'],
        default                  => [$o['order_state'], 'o-pending'],
      }; ?>
    <div class="row-item">
      <div style="flex:1;min-width:0">
        <div class="row-title"><?= e($o['listing_title']) ?></div>
        <div class="row-meta">🏷 <code style="font-size:.72rem;color:var(--gd)"><?= e($o['ref']) ?></code> · <?= date('j M', strtotime($o['created_at'])) ?></div>
      </div>
      <span class="o-badge <?= $badgeCls ?>"><?= $badgeLbl ?></span>
      <a href="<?= SITE_URL ?>/partner/marketplace-order?ref=<?= urlencode($o['ref']) ?>" class="row-link">View →</a>
    </div>
    <?php endforeach; ?>
    <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
      <a href="<?= SITE_URL ?>/partner/marketplace-orders" class="btn-sm btn-outline"><?= pt('All orders') ?> →</a>
      <a href="<?= SITE_URL ?>/partner/marketplace" class="btn-sm btn-outline">🛍️ <?= pt('Order new') ?> →</a>
    </div>
    <?php else: ?>
    <div class="empty" style="padding:12px 0">
      <?= pt('No active orders.') ?>
      <div style="margin-top:8px">
        <a href="<?= SITE_URL ?>/partner/marketplace" class="btn-sm btn-primary">🛍️ <?= pt('Browse Marketplace') ?></a>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Recent activity -->
  <?php if ($activityLog): ?>
  <div class="panel">
    <h2>📋 <?= pt('Recent Activity') ?></h2>
    <?php foreach ($activityLog as $entry): ?>
    <div class="row-item">
      <div style="flex:1;min-width:0">
        <div class="row-title" style="font-weight:500"><?= e($entry['description'] ?: $entry['action']) ?></div>
        <?php if ($entry['biz_name']): ?>
        <div class="row-meta">🏢 <?= e($entry['biz_name']) ?></div>
        <?php endif; ?>
      </div>
      <div style="font-size:.73rem;color:var(--mute);white-space:nowrap;flex-shrink:0;padding-left:8px"><?= date('d M H:i', strtotime($entry['created_at'])) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div><!-- /left -->
<div><!-- right sidebar -->

  <!-- Commission -->
  <div class="panel">
    <h2>💰 <?= pt('Commission') ?></h2>
    <div class="comm-row">
      <span class="comm-label"><?= pt('This Month') ?></span>
      <span class="comm-val" style="color:var(--gd)"><?= number_format($monthComm, 0) ?> XAF</span>
    </div>
    <div class="comm-row">
      <span class="comm-label"><?= pt('Pending') ?></span>
      <span class="comm-val" style="color:var(--warn)"><?= number_format($pendingComm, 0) ?> XAF</span>
    </div>
    <div class="comm-row">
      <span class="comm-label"><?= pt('Total Earned') ?></span>
      <span class="comm-val"><?= number_format($totalComm, 0) ?> XAF</span>
    </div>
    <div style="margin-top:12px">
      <a href="<?= SITE_URL ?>/partner/commissions" class="btn-sm btn-outline" style="display:block;text-align:center">
        💰 <?= pt('Commission details') ?> →
      </a>
    </div>
  </div>

  <!-- Quick actions -->
  <div class="panel">
    <h2>⚡ <?= pt('Quick Actions') ?></h2>
    <div class="quick-grid">
      <a href="<?= SITE_URL ?>/partner/marketplace"        class="quick-link">🛍️ <?= pt('Order Services') ?></a>
      <a href="<?= SITE_URL ?>/partner/marketplace-orders" class="quick-link">📦 <?= pt('My Orders') ?></a>
      <a href="<?= SITE_URL ?>/partner/portfolio"          class="quick-link">📋 <?= pt('Portfolio') ?></a>
      <a href="<?= SITE_URL ?>/partner/leads"              class="quick-link">💬 <?= pt('Leads') ?></a>
      <a href="<?= SITE_URL ?>/partner/tasks"              class="quick-link">✅ <?= pt('Tasks') ?></a>
      <a href="<?= SITE_URL ?>/partner/reports"            class="quick-link">📊 <?= pt('Reports') ?></a>
    </div>
  </div>

  <!-- Growth score -->
  <?php
  $scoreSt = $pdo->prepare("
      SELECT AVG(gsh.score) AS avg_score, COUNT(DISTINCT gsh.listing_id) AS scored_count
      FROM growth_score_history gsh
      JOIN partner_business_assignments pba ON pba.listing_id=gsh.listing_id
      WHERE pba.partner_id=? AND pba.status='active'
        AND gsh.computed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
  ");
  $scoreSt->execute([$pid]); $scoreRow = $scoreSt->fetch();
  $avgScore   = $scoreRow['avg_score'] !== null ? round($scoreRow['avg_score']) : null;
  $scoreColor = $avgScore === null ? 'var(--mute)' : ($avgScore >= 70 ? 'var(--gd)' : ($avgScore >= 40 ? 'var(--warn)' : 'var(--danger)'));
  ?>
  <div class="panel" style="text-align:center">
    <h2 style="justify-content:center">📈 <?= pt('Portfolio Score') ?></h2>
    <?php if ($avgScore !== null): ?>
    <div style="width:80px;height:80px;border-radius:50%;border:4px solid <?= $scoreColor ?>;display:flex;flex-direction:column;align-items:center;justify-content:center;margin:0 auto">
      <span style="font-size:1.6rem;font-weight:800;color:<?= $scoreColor ?>;line-height:1"><?= $avgScore ?></span>
      <span style="font-size:.6rem;color:var(--mute)">/100</span>
    </div>
    <div style="margin-top:8px;font-size:.75rem;color:var(--sub)">
      <?= pt('Avg. across') ?> <?= (int)$scoreRow['scored_count'] ?> <?= pt('business(es)') ?>
    </div>
    <div style="margin-top:5px;font-size:.7rem;color:var(--mute);line-height:1.4">
      <?= pt('Platform engagement indicator') ?>
    </div>
    <?php else: ?>
    <div style="color:var(--mute);font-size:.87rem;padding:10px 0">
      <?= pt('Visit a business to compute its score.') ?>
    </div>
    <?php endif; ?>
  </div>

</div><!-- /right -->
</div><!-- /main-grid -->

</div><!-- /pw -->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
