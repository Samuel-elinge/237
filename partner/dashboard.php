<?php
/**
 * partner/dashboard.php — 237Biz Business Growth Partner Centre (Phase 2)
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/partner-helpers.php';

$partner   = requireGrowthPartner();
$pid       = (int)$partner['id'];
$user      = currentUser();
$pdo       = db();
$userId    = (int)($_SESSION['user_id'] ?? 0);

/* ── Portfolio stats ──────────────────────────────────────────── */
$st = $pdo->prepare("
    SELECT
        COUNT(*)                                                   AS total,
        SUM(l.status = 'approved')                                 AS active,
        SUM(pba.assigned_at >= DATE_SUB(NOW(), INTERVAL 30 DAY))   AS onboarding
    FROM partner_business_assignments pba
    JOIN listings l ON l.id = pba.listing_id
    WHERE pba.partner_id = ? AND pba.status = 'active'
");
$st->execute([$pid]);
$portfolio = $st->fetch();

/* ── Tasks ────────────────────────────────────────────────────── */
$st = $pdo->prepare("SELECT COUNT(*) FROM growth_tasks WHERE partner_id=? AND status IN ('todo','in_progress')");
$st->execute([$pid]); $openTaskCount = (int)$st->fetchColumn();

$st = $pdo->prepare("SELECT COUNT(*) FROM growth_tasks WHERE partner_id=? AND status IN ('todo','in_progress') AND due_date < CURDATE()");
$st->execute([$pid]); $overdueCount = (int)$st->fetchColumn();

$st = $pdo->prepare("
    SELECT gt.*, l.name AS biz_name
    FROM growth_tasks gt
    JOIN listings l ON l.id = gt.listing_id
    WHERE gt.partner_id=? AND gt.status IN ('todo','in_progress') AND gt.due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY gt.due_date ASC, gt.priority DESC
    LIMIT 8
");
$st->execute([$pid]); $upcomingTasks = $st->fetchAll();

/* ── Leads ────────────────────────────────────────────────────── */
$st = $pdo->prepare("SELECT COUNT(*) FROM partner_leads WHERE partner_id=? AND status IN ('new','contacted','follow_up')");
$st->execute([$pid]); $activeLeadCount = (int)$st->fetchColumn();

$st = $pdo->prepare("SELECT COUNT(*) FROM partner_leads WHERE partner_id=? AND follow_up_date < CURDATE() AND status NOT IN ('converted','lost','closed')");
$st->execute([$pid]); $overdueLeadsCount = (int)$st->fetchColumn();

/* ── Commissions ──────────────────────────────────────────────── */
$st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM partner_commissions WHERE partner_id=? AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())");
$st->execute([$pid]); $monthComm = (float)$st->fetchColumn();

$st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM partner_commissions WHERE partner_id=? AND status='pending'");
$st->execute([$pid]); $pendingComm = (float)$st->fetchColumn();

$st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM partner_commissions WHERE partner_id=?");
$st->execute([$pid]); $totalComm = (float)$st->fetchColumn();

/* ── Phase 2: Plans ───────────────────────────────────────────── */
$st = $pdo->prepare("SELECT COUNT(*) FROM growth_plans WHERE partner_id=? AND status='active'");
$st->execute([$pid]); $activePlanCount = (int)$st->fetchColumn();

/* ── Phase 2: Campaigns ───────────────────────────────────────── */
$st = $pdo->prepare("SELECT status, COUNT(*) cnt FROM campaigns WHERE partner_id=? GROUP BY status");
$st->execute([$pid]); $campCounts = [];
foreach ($st->fetchAll() as $r) $campCounts[$r['status']] = (int)$r['cnt'];
$activeCampaigns    = $campCounts['active']    ?? 0;
$scheduledCampaigns = $campCounts['scheduled'] ?? 0;

// Campaigns ending soon (next 7 days)
$st = $pdo->prepare("SELECT c.*, l.name AS biz_name FROM campaigns c JOIN listings l ON l.id=c.listing_id
                     WHERE c.partner_id=? AND c.status='active' AND c.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)
                     ORDER BY c.end_date ASC LIMIT 5");
$st->execute([$pid]); $endingSoonCampaigns = $st->fetchAll();

/* ── Phase 2: Attention queue ─────────────────────────────────── */
$attentionQueue = getAttentionQueue($pid);
$highPriority   = array_filter($attentionQueue, fn($a) => $a['severity'] === 'high');

/* ── Phase 3A: Recommendations ───────────────────────────────── */
// Generate if none exist
$recCheck = $pdo->prepare("SELECT COUNT(*) FROM ai_recommendations WHERE partner_id=? AND status IN ('new','viewed')");
$recCheck->execute([$pid]);
if ((int)$recCheck->fetchColumn() === 0) {
    generateRecommendations($pid, $pdo);
    generateGrowthAlerts($pid, $pdo);
}
$dashRecs = getActiveRecommendations($pid, $pdo, null, 5);

/* ── Phase 3A: Growth Alerts ──────────────────────────────────── */
$alertsSt = $pdo->prepare("SELECT COUNT(*) FROM growth_alerts WHERE partner_id=? AND status IN ('new','viewed')");
$alertsSt->execute([$pid]); $activeAlertsCount = (int)$alertsSt->fetchColumn();

$urgentAlertsSt = $pdo->prepare("SELECT COUNT(*) FROM growth_alerts WHERE partner_id=? AND status IN ('new','viewed') AND priority='urgent'");
$urgentAlertsSt->execute([$pid]); $urgentAlertsCount = (int)$urgentAlertsSt->fetchColumn();

/* ── Phase 2: Notifications ───────────────────────────────────── */
$unreadNotifs = getUnreadNotifications($userId);

/* ── Recent audit activity ────────────────────────────────────── */
$st = $pdo->prepare("
    SELECT pal.*, l.name AS biz_name
    FROM partner_audit_log pal
    LEFT JOIN listings l ON l.id = pal.listing_id
    WHERE pal.partner_id = ?
    ORDER BY pal.created_at DESC LIMIT 12
");
$st->execute([$pid]); $activityLog = $st->fetchAll();

/* ── Content scheduled today ──────────────────────────────────── */
$st = $pdo->prepare("SELECT ci.*, l.name AS biz_name FROM content_items ci JOIN listings l ON l.id=ci.listing_id
                     WHERE ci.partner_id=? AND ci.status='scheduled' AND DATE(ci.scheduled_date)=CURDATE()
                     ORDER BY ci.scheduled_date ASC LIMIT 6");
$st->execute([$pid]); $todayContent = $st->fetchAll();

$pageTitle = 'Partner Centre — 237Biz';
require_once __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/partner.css">
<style>
:root{--pblue:#1a56db;--pgreen:#16a34a;--pred:#dc2626;--pyellow:#ca8a04;--ppurple:#7c3aed;--pcyan:#0891b2}
.pw{max-width:1260px;margin:0 auto;padding:28px 16px}
/* Header */
.dash-header{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:22px}
.dash-header h1{margin:0 0 4px;font-size:1.7rem;font-weight:800;color:#111827}
.dash-header .sub{color:#6b7280;font-size:.9rem}
.dash-header .header-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
/* Nav */
.dash-nav{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:22px}
.dash-nav a{padding:7px 14px;border-radius:8px;font-size:.85rem;font-weight:600;text-decoration:none;
            background:#fff;border:1px solid #e5e7eb;color:#374151;transition:.15s;position:relative}
.dash-nav a:hover,.dash-nav a.active{background:var(--pblue);color:#fff;border-color:var(--pblue)}
.notif-dot{position:absolute;top:-4px;right:-4px;background:var(--pred);color:#fff;border-radius:50%;
           width:17px;height:17px;font-size:.65rem;display:flex;align-items:center;justify-content:center;font-weight:700}
/* Summary strip */
.sum-strip{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:12px;margin-bottom:22px}
.sum-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px 18px}
.sum-card .val{font-size:2rem;font-weight:800;line-height:1.1;color:#111827}
.sum-card .lbl{font-size:.78rem;color:#6b7280;margin-top:4px;text-transform:uppercase;letter-spacing:.04em}
.sum-card .sub{font-size:.8rem;margin-top:4px}
.sum-card.red-border{border-color:var(--pred)}
.sum-card.green-border{border-color:var(--pgreen)}
.sum-card.yellow-border{border-color:var(--pyellow)}
.sum-card.blue-border{border-color:var(--pblue)}
.sum-card.purple-border{border-color:var(--ppurple)}
/* Attention queue */
.attn-section{background:#fff;border:1px solid #fca5a5;border-radius:12px;padding:20px;margin-bottom:22px}
.attn-section h2{margin:0 0 14px;font-size:1rem;font-weight:700;color:var(--pred);display:flex;align-items:center;gap:8px}
.attn-grid{display:flex;flex-direction:column;gap:8px}
.attn-item{display:flex;align-items:center;gap:12px;padding:10px 14px;border-radius:8px;background:#fff7ed;border:1px solid #fed7aa;font-size:.88rem}
.attn-item.high{background:#fef2f2;border-color:#fca5a5}
.attn-item.medium{background:#fff7ed;border-color:#fed7aa}
.attn-item.low{background:#f9fafb;border-color:#e5e7eb}
.attn-biz{font-weight:600;color:#111827;flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.attn-msg{font-size:.8rem;color:#374151;flex:2;min-width:0}
.sev-badge{font-size:.7rem;font-weight:700;padding:2px 8px;border-radius:10px;flex-shrink:0}
.sev-high{background:#fee2e2;color:#991b1b}
.sev-medium{background:#fff7ed;color:#92400e}
.sev-low{background:#f9fafb;color:#4b5563}
/* Main grid */
.main-grid{display:grid;grid-template-columns:1fr 340px;gap:20px}
@media(max-width:900px){.main-grid{grid-template-columns:1fr}}
.panel{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px 20px;margin-bottom:20px}
.panel h2{margin:0 0 14px;font-size:1rem;font-weight:700;color:#111827;display:flex;align-items:center;gap:8px}
.panel h2 .badge{background:var(--pblue);color:#fff;border-radius:20px;padding:1px 8px;font-size:.72rem}
/* Task rows */
.task-row{display:flex;align-items:flex-start;gap:10px;padding:9px 0;border-bottom:1px solid #f3f4f6}
.task-row:last-child{border-bottom:none}
.pri-badge{font-size:.7rem;padding:2px 8px;border-radius:10px;font-weight:700;white-space:nowrap;flex-shrink:0}
.pri-urgent{background:#dc2626;color:#fff}
.pri-high{background:#f97316;color:#fff}
.pri-medium{background:#0891b2;color:#fff}
.pri-low{background:#e5e7eb;color:#4b5563}
.overdue-tag{font-size:.72rem;color:var(--pred);font-weight:700}
/* Activity */
.act-row{display:flex;gap:10px;padding:8px 0;border-bottom:1px solid #f3f4f6;font-size:.85rem}
.act-row:last-child{border-bottom:none}
.act-time{color:#9ca3af;font-size:.77rem;white-space:nowrap;flex-shrink:0;padding-top:2px}
/* Campaign ending */
.camp-ending-item{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #f3f4f6;font-size:.85rem}
.camp-ending-item:last-child{border-bottom:none}
.days-left{font-size:.72rem;font-weight:700;padding:2px 8px;border-radius:10px}
.days-1-2{background:#fee2e2;color:var(--pred)}
.days-3-5{background:#fff7ed;color:#92400e}
.days-6-7{background:#fef9c3;color:#854d0e}
/* Content today */
.today-item{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #f3f4f6;font-size:.85rem}
.today-item:last-child{border-bottom:none}
/* Commission panel */
.comm-summary{display:flex;flex-direction:column;gap:8px}
.comm-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f3f4f6;font-size:.9rem}
.comm-row .label{color:#374151}
.comm-row .value{font-weight:700;color:#111827}
/* Quick links */
.quick-links{display:flex;flex-direction:column;gap:8px}
.quick-link{display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;text-decoration:none;color:#374151;font-size:.87rem;font-weight:500;transition:.15s}
.quick-link:hover{background:#f0f5ff;border-color:var(--pblue);color:var(--pblue)}
.quick-link .icon{width:28px;text-align:center;font-size:1.1rem}
.quick-link .arrow{margin-left:auto;color:#9ca3af}
.empty-small{color:#9ca3af;font-size:.87rem;text-align:center;padding:16px 0;font-style:italic}
.btn-sm{padding:6px 14px;font-size:.83rem;border-radius:6px;text-decoration:none;display:inline-block}
.btn-sm.primary{background:var(--pblue);color:#fff;border:1px solid var(--pblue)}
.btn-sm.outline{background:#fff;color:#374151;border:1px solid #d1d5db}
.btn-sm.outline:hover{background:#f3f4f6}
/* ── Phase 3A additions ── */
.ai-priorities{background:linear-gradient(135deg,#eef2ff 0%,#f0fdf4 100%);border:1px solid #c7d2fe;border-radius:12px;padding:20px;margin-bottom:22px}
.ai-priorities h2{margin:0 0 14px;font-size:1rem;font-weight:700;color:#3730a3;display:flex;align-items:center;gap:8px}
.ai-badge-sm{background:#6366f1;color:#fff;font-size:.65rem;font-weight:700;padding:.15rem .45rem;border-radius:999px;letter-spacing:.05em}
.ai-stat-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px;margin-bottom:14px}
.ai-stat{background:#fff;border:1px solid #c7d2fe;border-radius:8px;padding:12px;text-align:center}
.ai-stat .n{font-size:1.6rem;font-weight:800;color:#4f46e5}
.ai-stat .l{font-size:.72rem;color:#6b7280}
.rec-strip{display:flex;flex-direction:column;gap:8px}
.rec-strip-item{display:flex;align-items:center;gap:10px;padding:10px 12px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;text-decoration:none;color:#111827;font-size:.87rem;transition:.15s}
.rec-strip-item:hover{border-color:#6366f1;background:#f5f3ff}
.rec-strip-item .rec-biz{font-size:.75rem;color:#6b7280;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rec-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.rec-dot.urgent{background:#ef4444}
.rec-dot.high{background:#f97316}
.rec-dot.medium{background:#3b82f6}
.rec-dot.low{background:#9ca3af}
.alerts-badge{background:#f59e0b;color:#fff;border-radius:999px;font-size:.65rem;font-weight:700;padding:.15rem .4rem;margin-left:.25rem}
.alerts-badge.urgent{background:#ef4444}
.score-ring{width:80px;height:80px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-direction:column;border:4px solid #e5e7eb;background:#fff;margin:0 auto}
.score-ring .score-num{font-size:1.5rem;font-weight:800;line-height:1}
.score-ring .score-sub{font-size:.6rem;color:#6b7280;text-align:center}
.score-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:10px}
.score-comp{font-size:.75rem;color:#374151;display:flex;justify-content:space-between;padding:3px 0;border-bottom:1px solid #f3f4f6}
</style>

<div class="pw">

<!-- ── Header ── -->
<div class="dash-header">
    <div>
        <h1>👔 Partner Centre</h1>
        <div class="sub">Good <?= date('G') < 12 ? 'morning' : (date('G') < 17 ? 'afternoon' : 'evening') ?>, <?= e(explode(' ', $user['name'])[0]) ?> — <?= date('l, j F Y') ?></div>
    </div>
    <div class="header-actions">
        <?php if ($unreadNotifs > 0): ?>
        <div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:6px 14px;font-size:.85rem;color:var(--pred);font-weight:600">
            🔔 <?= $unreadNotifs ?> new notification<?= $unreadNotifs > 1 ? 's' : '' ?>
        </div>
        <?php endif; ?>
        <a href="<?= SITE_URL ?>/partner/portfolio" class="btn-sm primary">📋 Portfolio</a>
    </div>
</div>

<!-- ── Nav ── -->
<nav class="dash-nav">
    <a href="<?= SITE_URL ?>/partner/dashboard" class="active">🏠 Dashboard</a>
    <a href="<?= SITE_URL ?>/partner/portfolio">📋 Portfolio</a>
    <a href="<?= SITE_URL ?>/partner/tasks">✅ Tasks <?php if ($overdueCount): ?><span class="notif-dot"><?= $overdueCount ?></span><?php endif; ?></a>
    <a href="<?= SITE_URL ?>/partner/leads">💬 Leads <?php if ($overdueLeadsCount): ?><span class="notif-dot"><?= $overdueLeadsCount ?></span><?php endif; ?></a>
    <a href="<?= SITE_URL ?>/partner/campaigns">📣 Campaigns</a>
    <a href="<?= SITE_URL ?>/partner/content">📅 Content</a>
    <a href="<?= SITE_URL ?>/partner/reports">📊 Reports</a>
    <a href="<?= SITE_URL ?>/partner/recommendations">✦ Recommendations <?php if (!empty($dashRecs)): ?><span class="notif-dot"><?= count($dashRecs) ?></span><?php endif; ?></a>
    <a href="<?= SITE_URL ?>/partner/alerts">🔔 Alerts <?php if ($activeAlertsCount): ?><span class="notif-dot <?= $urgentAlertsCount ? '' : '' ?>" style="<?= $urgentAlertsCount ? 'background:#f59e0b' : '' ?>"><?= $activeAlertsCount ?></span><?php endif; ?></a>
    <a href="<?= SITE_URL ?>/partner/commissions">💰 Commissions</a>
</nav>

<!-- ── Summary strip ── -->
<div class="sum-strip">
    <div class="sum-card blue-border">
        <div class="val"><?= (int)$portfolio['total'] ?></div>
        <div class="lbl">Businesses</div>
        <div class="sub" style="color:#16a34a"><?= (int)$portfolio['active'] ?> active · <?= (int)$portfolio['onboarding'] ?> onboarding</div>
    </div>
    <div class="sum-card <?= $overdueCount > 0 ? 'red-border' : '' ?>">
        <div class="val"><?= $openTaskCount ?></div>
        <div class="lbl">Open Tasks</div>
        <?php if ($overdueCount): ?>
        <div class="sub" style="color:var(--pred)">⚠ <?= $overdueCount ?> overdue</div>
        <?php else: ?>
        <div class="sub" style="color:#16a34a">✓ None overdue</div>
        <?php endif; ?>
    </div>
    <div class="sum-card <?= $overdueLeadsCount > 0 ? 'yellow-border' : '' ?>">
        <div class="val"><?= $activeLeadCount ?></div>
        <div class="lbl">Active Leads</div>
        <?php if ($overdueLeadsCount): ?>
        <div class="sub" style="color:var(--pyellow)">⚠ <?= $overdueLeadsCount ?> overdue follow-up</div>
        <?php else: ?>
        <div class="sub" style="color:#6b7280">Need follow-up</div>
        <?php endif; ?>
    </div>
    <div class="sum-card green-border">
        <div class="val"><?= $activeCampaigns ?></div>
        <div class="lbl">Active Campaigns</div>
        <div class="sub" style="color:#6b7280"><?= $scheduledCampaigns ?> scheduled</div>
    </div>
    <div class="sum-card yellow-border">
        <div class="val" style="font-size:1.35rem"><?= number_format($monthComm) ?></div>
        <div class="lbl">Commission (Month)</div>
        <div class="sub" style="color:#6b7280">XAF · <?= number_format($pendingComm) ?> pending</div>
    </div>
    <div class="sum-card purple-border">
        <div class="val"><?= $activePlanCount ?></div>
        <div class="lbl">Active Plans</div>
        <div class="sub" style="color:#6b7280">Growth plans running</div>
    </div>
</div>

<!-- ── Attention queue (only shown if items exist) ── -->
<?php if (!empty($attentionQueue)): ?>
<div class="attn-section">
    <h2>⚠️ Attention Needed <span style="background:#dc2626;color:#fff;border-radius:12px;padding:1px 8px;font-size:.72rem;font-weight:700"><?= count($attentionQueue) ?></span></h2>
    <div class="attn-grid">
    <?php foreach (array_slice($attentionQueue, 0, 8) as $a): ?>
    <div class="attn-item <?= $a['severity'] ?>">
        <a href="<?= SITE_URL ?>/partner/business?lid=<?= $a['listing_id'] ?>" style="text-decoration:none;flex:1;display:flex;align-items:center;gap:12px;overflow:hidden">
            <span class="attn-biz">🏢 <?= e($a['name']) ?></span>
            <span class="attn-msg"><?= e($a['reason']) ?></span>
        </a>
        <span class="sev-badge sev-<?= $a['severity'] ?>"><?= ucfirst($a['severity']) ?></span>
        <a href="<?= SITE_URL ?>/partner/business?lid=<?= $a['listing_id'] ?>" style="font-size:.8rem;color:var(--pblue);white-space:nowrap;text-decoration:none">Take action →</a>
    </div>
    <?php endforeach; ?>
    <?php if (count($attentionQueue) > 8): ?>
    <div style="text-align:center;padding:8px 0">
        <a href="<?= SITE_URL ?>/partner/portfolio" style="font-size:.85rem;color:var(--pblue);text-decoration:none">+ <?= count($attentionQueue)-8 ?> more → View Portfolio</a>
    </div>
    <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── Phase 3A: AI Priorities Panel ── -->
<div class="ai-priorities">
    <h2>✦ AI Priorities <span class="ai-badge-sm">SMART</span></h2>
    <div class="ai-stat-row">
        <div class="ai-stat">
            <div class="n"><?= (int)$portfolio['total'] ?></div>
            <div class="l">Businesses Managed</div>
        </div>
        <div class="ai-stat">
            <div class="n" style="color:<?= $urgentAlertsCount ? '#ef4444' : ($activeAlertsCount ? '#f59e0b' : '#10b981') ?>"><?= $activeAlertsCount ?></div>
            <div class="l">Active Alerts</div>
        </div>
        <div class="ai-stat">
            <div class="n" style="color:<?= $overdueLeadsCount ? '#f59e0b' : '#10b981' ?>"><?= $overdueLeadsCount ?></div>
            <div class="l">Leads Need Follow-Up</div>
        </div>
        <div class="ai-stat">
            <div class="n" style="color:<?= !empty($dashRecs) ? '#6366f1' : '#10b981' ?>"><?= count($dashRecs) ?></div>
            <div class="l">AI Recommendations</div>
        </div>
    </div>

    <?php if (!empty($dashRecs)): ?>
    <div class="rec-strip">
        <?php foreach ($dashRecs as $rec): ?>
        <a href="<?= $rec['action_url'] ? h($rec['action_url']) : SITE_URL.'/partner/recommendations' ?>" class="rec-strip-item">
            <span class="rec-dot <?= h($rec['priority']) ?>"></span>
            <div style="flex:1;min-width:0">
                <div style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= h($rec['title']) ?></div>
                <div class="rec-biz"><?= $rec['business_name'] ? '🏢 '.h($rec['business_name']) : '📊 Portfolio-wide' ?></div>
            </div>
            <?php if ($rec['recommended_action']): ?>
            <span style="font-size:.78rem;color:#6366f1;white-space:nowrap;flex-shrink:0"><?= h($rec['recommended_action']) ?> →</span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
    <div style="margin-top:10px;display:flex;gap:8px">
        <a href="<?= SITE_URL ?>/partner/recommendations" class="btn-sm outline">View all recommendations →</a>
        <a href="<?= SITE_URL ?>/partner/alerts" class="btn-sm outline">View alerts →</a>
    </div>
    <?php else: ?>
    <div style="text-align:center;padding:12px 0;color:#6b7280;font-size:.875rem">
        ✓ No active recommendations — your portfolio is in good shape.
        <div style="margin-top:8px"><a href="<?= SITE_URL ?>/partner/recommendations" class="btn-sm outline">Check recommendations →</a></div>
    </div>
    <?php endif; ?>
</div>

<!-- ── Main grid ── -->
<div class="main-grid">
<div><!-- left column -->

    <!-- Upcoming tasks -->
    <div class="panel">
        <h2>✅ Upcoming Tasks <span class="badge"><?= count($upcomingTasks) ?></span></h2>
        <?php if ($upcomingTasks): ?>
        <?php foreach ($upcomingTasks as $task):
            $isOverdue = $task['due_date'] && $task['due_date'] < date('Y-m-d');
        ?>
        <div class="task-row">
            <span class="pri-badge pri-<?= $task['priority'] ?>"><?= ucfirst($task['priority']) ?></span>
            <div style="flex:1;min-width:0">
                <div style="font-weight:600;font-size:.9rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($task['title']) ?></div>
                <div style="font-size:.78rem;color:#6b7280">
                    🏢 <?= e($task['biz_name']) ?>
                    <?php if ($task['due_date']): ?> ·
                    <?php if ($isOverdue): ?><span class="overdue-tag">⚠ Overdue:</span><?php endif; ?>
                    <?= date('j M', strtotime($task['due_date'])) ?>
                    <?php endif; ?>
                </div>
            </div>
            <a href="<?= SITE_URL ?>/partner/business?lid=<?= $task['listing_id'] ?>&tab=tasks" style="font-size:.8rem;color:var(--pblue);text-decoration:none;white-space:nowrap">View →</a>
        </div>
        <?php endforeach; ?>
        <div style="margin-top:12px">
            <a href="<?= SITE_URL ?>/partner/tasks" class="btn-sm outline">View all tasks →</a>
        </div>
        <?php else: ?>
        <div class="empty-small">🎉 No upcoming tasks this week. Great work!</div>
        <?php endif; ?>
    </div>

    <!-- Active leads -->
    <div class="panel">
        <h2>💬 Active Leads <span class="badge"><?= $activeLeadCount ?></span></h2>
        <?php
        $leadSt = $pdo->prepare("SELECT pl.*, l.name AS biz_name FROM partner_leads pl JOIN listings l ON l.id=pl.listing_id WHERE pl.partner_id=? AND pl.status IN ('new','contacted','follow_up') ORDER BY pl.follow_up_date ASC NULLS LAST, pl.created_at DESC LIMIT 6");
        $leadSt->execute([$pid]); $activeLeadList = $leadSt->fetchAll();
        ?>
        <?php if ($activeLeadList): ?>
        <?php foreach ($activeLeadList as $lead):
            $overdueL = $lead['follow_up_date'] && $lead['follow_up_date'] < date('Y-m-d');
        ?>
        <div class="task-row">
            <div style="flex:1;min-width:0">
                <div style="font-weight:600;font-size:.88rem"><?= e($lead['contact_name']) ?></div>
                <div style="font-size:.78rem;color:#6b7280">
                    🏢 <?= e($lead['biz_name']) ?> · <?= ucfirst($lead['status']) ?>
                    <?php if ($lead['follow_up_date']): ?> ·
                    <?php if ($overdueL): ?><span class="overdue-tag">⚠ Overdue follow-up</span>
                    <?php else: ?>📅 <?= date('j M', strtotime($lead['follow_up_date'])) ?><?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <a href="<?= SITE_URL ?>/partner/business?lid=<?= $lead['listing_id'] ?>&tab=leads" style="font-size:.8rem;color:var(--pblue);text-decoration:none;white-space:nowrap">Log →</a>
        </div>
        <?php endforeach; ?>
        <div style="margin-top:12px">
            <a href="<?= SITE_URL ?>/partner/leads" class="btn-sm outline">View all leads →</a>
        </div>
        <?php else: ?>
        <div class="empty-small">No active leads.</div>
        <?php endif; ?>
    </div>

    <!-- Campaigns ending soon -->
    <?php if (!empty($endingSoonCampaigns)): ?>
    <div class="panel">
        <h2>⏰ Campaigns Ending Soon</h2>
        <?php foreach ($endingSoonCampaigns as $camp):
            $daysLeft = (int)ceil((strtotime($camp['end_date']) - time()) / 86400);
            $dClass   = $daysLeft <= 2 ? 'days-1-2' : ($daysLeft <= 5 ? 'days-3-5' : 'days-6-7');
        ?>
        <div class="camp-ending-item">
            <div style="flex:1;min-width:0">
                <div style="font-weight:600;font-size:.88rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($camp['name']) ?></div>
                <div style="font-size:.78rem;color:#6b7280">🏢 <?= e($camp['biz_name']) ?></div>
            </div>
            <span class="days-left <?= $dClass ?>"><?= $daysLeft ?> day<?= $daysLeft !== 1?'s':'' ?></span>
            <a href="<?= SITE_URL ?>/partner/campaigns?view=metrics&cid=<?= $camp['id'] ?>" style="font-size:.8rem;color:var(--pblue);text-decoration:none">Metrics →</a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Today's scheduled content -->
    <?php if (!empty($todayContent)): ?>
    <div class="panel">
        <h2>📅 Publishing Today <span class="badge"><?= count($todayContent) ?></span></h2>
        <?php foreach ($todayContent as $ci): ?>
        <div class="today-item">
            <div style="flex:1;min-width:0">
                <div style="font-weight:600;font-size:.88rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                    <?= $ci['platform'] ? '['.$ci['platform'].'] ' : '' ?><?= e($ci['title']?:'(untitled)') ?>
                </div>
                <div style="font-size:.78rem;color:#6b7280">🏢 <?= e($ci['biz_name']) ?> · <?= date('H:i', strtotime($ci['scheduled_date'])) ?></div>
            </div>
            <a href="<?= SITE_URL ?>/partner/business?lid=<?= $ci['listing_id'] ?>&tab=content" style="font-size:.8rem;color:var(--pblue);text-decoration:none">View →</a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Recent activity -->
    <div class="panel">
        <h2>📋 Recent Activity</h2>
        <?php if ($activityLog): ?>
        <?php foreach ($activityLog as $entry): ?>
        <div class="act-row">
            <div style="flex:1;min-width:0">
                <div style="font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($entry['description'] ?: $entry['action']) ?></div>
                <?php if ($entry['biz_name']): ?>
                <div style="font-size:.77rem;color:#6b7280">🏢 <?= e($entry['biz_name']) ?></div>
                <?php endif; ?>
            </div>
            <div class="act-time"><?= date('d M H:i', strtotime($entry['created_at'])) ?></div>
        </div>
        <?php endforeach; ?>
        <?php else: ?>
        <div class="empty-small">No activity recorded yet.</div>
        <?php endif; ?>
    </div>

</div><!-- /left -->
<div><!-- right sidebar -->

    <!-- Phase 3A: Growth Score (portfolio average) -->
    <?php
    // Get average score across all partner listings for display
    $scoreSt = $pdo->prepare("
        SELECT AVG(gsh.score) AS avg_score, COUNT(DISTINCT gsh.listing_id) AS scored_count
        FROM growth_score_history gsh
        JOIN partner_business_assignments pba ON pba.listing_id=gsh.listing_id
        WHERE pba.partner_id=? AND pba.status='active'
          AND gsh.computed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $scoreSt->execute([$pid]);
    $scoreRow = $scoreSt->fetch();
    $avgScore = $scoreRow['avg_score'] !== null ? round($scoreRow['avg_score']) : null;
    // Score color
    $scoreColor = $avgScore === null ? '#9ca3af' : ($avgScore >= 70 ? '#10b981' : ($avgScore >= 40 ? '#f59e0b' : '#ef4444'));
    ?>
    <div class="panel">
        <h2>📈 Portfolio Growth Score <span style="font-size:.7rem;font-weight:400;color:#6b7280;margin-left:2px">237biz indicator</span></h2>
        <?php if ($avgScore !== null): ?>
        <div class="score-ring" style="border-color:<?= $scoreColor ?>">
            <div class="score-num" style="color:<?= $scoreColor ?>"><?= $avgScore ?></div>
            <div class="score-sub">/ 100</div>
        </div>
        <div style="text-align:center;font-size:.75rem;color:#6b7280;margin-top:6px">
            Avg. across <?= (int)$scoreRow['scored_count'] ?> business<?= $scoreRow['scored_count']!=1?'es':'' ?>
        </div>
        <div style="margin-top:10px;font-size:.72rem;color:#9ca3af;text-align:center;line-height:1.4">
            237biz activity indicator — reflects platform engagement,<br>not overall business performance.
        </div>
        <?php else: ?>
        <div style="text-align:center;padding:14px 0;color:#6b7280;font-size:.87rem">
            Visit a business workspace to compute its score.
        </div>
        <?php endif; ?>
    </div>

    <!-- Commission summary -->
    <div class="panel">
        <h2>💰 Commission</h2>
        <div class="comm-summary">
            <div class="comm-row"><span class="label">This Month</span><span class="value" style="color:var(--pgreen)"><?= number_format($monthComm,0) ?> XAF</span></div>
            <div class="comm-row"><span class="label">Pending</span><span class="value" style="color:var(--pyellow)"><?= number_format($pendingComm,0) ?> XAF</span></div>
            <div class="comm-row"><span class="label">Total Earned</span><span class="value"><?= number_format($totalComm,0) ?> XAF</span></div>
        </div>
        <div style="margin-top:12px">
            <a href="<?= SITE_URL ?>/partner/commissions" class="btn-sm outline" style="width:100%;text-align:center;display:block">View details →</a>
        </div>
    </div>

    <!-- Portfolio quick stats -->
    <div class="panel">
        <h2>📊 Portfolio Pulse</h2>
        <?php
        $totalLeadsAll = $pdo->prepare("SELECT COUNT(*) FROM partner_leads WHERE partner_id=?");
        $totalLeadsAll->execute([$pid]); $totalLeadsCount = (int)$totalLeadsAll->fetchColumn();
        $convLeads = $pdo->prepare("SELECT COUNT(*) FROM partner_leads WHERE partner_id=? AND status='converted'");
        $convLeads->execute([$pid]); $convLeadsCount = (int)$convLeads->fetchColumn();
        $convRate = $totalLeadsCount > 0 ? round($convLeadsCount/$totalLeadsCount*100) : 0;
        $totalCamps = $pdo->prepare("SELECT COUNT(*) FROM campaigns WHERE partner_id=?");
        $totalCamps->execute([$pid]); $totalCampsCount = (int)$totalCamps->fetchColumn();
        ?>
        <div class="comm-row"><span class="label">Total Leads</span><span class="value"><?= $totalLeadsCount ?></span></div>
        <div class="comm-row"><span class="label">Conversion Rate</span><span class="value" style="color:var(--pgreen)"><?= $convRate ?>%</span></div>
        <div class="comm-row"><span class="label">Total Campaigns</span><span class="value"><?= $totalCampsCount ?></span></div>
        <div class="comm-row"><span class="label">Active Campaigns</span><span class="value" style="color:var(--pblue)"><?= $activeCampaigns ?></span></div>
    </div>

    <!-- Quick links -->
    <div class="panel">
        <h2>⚡ Quick Actions</h2>
        <div class="quick-links">
            <a href="<?= SITE_URL ?>/partner/recommendations" class="quick-link"><span class="icon">✦</span>AI Recommendations<span class="arrow">→</span></a>
            <a href="<?= SITE_URL ?>/partner/ai-plan" class="quick-link"><span class="icon">🧠</span>AI Growth Plan<span class="arrow">→</span></a>
            <a href="<?= SITE_URL ?>/partner/alerts" class="quick-link"><span class="icon">🔔</span>Growth Alerts<span class="arrow">→</span></a>
            <a href="<?= SITE_URL ?>/partner/portfolio" class="quick-link"><span class="icon">📋</span>View Portfolio<span class="arrow">→</span></a>
            <a href="<?= SITE_URL ?>/partner/campaigns" class="quick-link"><span class="icon">📣</span>Manage Campaigns<span class="arrow">→</span></a>
            <a href="<?= SITE_URL ?>/partner/content" class="quick-link"><span class="icon">📅</span>Content Calendar<span class="arrow">→</span></a>
            <a href="<?= SITE_URL ?>/partner/templates" class="quick-link"><span class="icon">📨</span>Message Templates<span class="arrow">→</span></a>
            <a href="<?= SITE_URL ?>/partner/reports" class="quick-link"><span class="icon">📊</span>Generate Report<span class="arrow">→</span></a>
            <a href="<?= SITE_URL ?>/partner/tasks" class="quick-link"><span class="icon">✅</span>All Tasks<span class="arrow">→</span></a>
            <a href="<?= SITE_URL ?>/partner/leads" class="quick-link"><span class="icon">💬</span>All Leads<span class="arrow">→</span></a>
        </div>
    </div>

</div><!-- /right -->
</div><!-- /main-grid -->

</div><!-- /pw -->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
