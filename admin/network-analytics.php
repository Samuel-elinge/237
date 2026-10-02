<?php
/**
 * admin/network-analytics.php — Partner Network Analytics (Task 58)
 *
 * High-level analytics across the partner network: activity trends,
 * revenue pipeline, referral funnel, lead conversion, top performers.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';

$pdo = db();
requireAdmin();

$period = in_array($_GET['period'] ?? '30', ['7','30','90','365']) ? $_GET['period'] : '30';

// ── Network-level KPIs ────────────────────────────────────
$kpis = $pdo->query("
    SELECT
        (SELECT COUNT(*) FROM partner_profiles WHERE status='active') AS active_partners,
        (SELECT COUNT(*) FROM partner_profiles WHERE status='active' AND created_at >= DATE_SUB(NOW(), INTERVAL {$period} DAY)) AS new_partners,
        (SELECT SUM(active_clients) FROM partner_profiles WHERE status='active') AS total_clients,
        (SELECT SUM(monthly_revenue) FROM partner_profiles WHERE status='active') AS total_revenue,
        (SELECT COUNT(*) FROM partner_leads WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$period} DAY)) AS leads_period,
        (SELECT COUNT(*) FROM partner_leads WHERE status='converted' AND updated_at >= DATE_SUB(NOW(), INTERVAL {$period} DAY)) AS conversions_period,
        (SELECT COUNT(*) FROM partner_referrals WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$period} DAY)) AS referrals_period,
        (SELECT SUM(commission_amount) FROM partner_commissions WHERE status='paid' AND paid_at >= DATE_SUB(NOW(), INTERVAL {$period} DAY)) AS commissions_paid
")->fetch();

// ── Lead funnel ───────────────────────────────────────────
$funnel = $pdo->query("
    SELECT status, COUNT(*) AS cnt
    FROM partner_leads
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$period} DAY)
    GROUP BY status
    ORDER BY FIELD(status,'new','contacted','qualified','proposal','negotiation','converted','lost')
")->fetchAll(\PDO::FETCH_KEY_PAIR);

$funnelOrder = ['new','contacted','qualified','proposal','negotiation','converted','lost'];
$funnelMax   = $funnel ? max(array_values($funnel)) : 1;

// ── Partner tier breakdown ────────────────────────────────
$tiers = $pdo->query("
    SELECT tier, COUNT(*) AS cnt, SUM(active_clients) AS clients, SUM(monthly_revenue) AS revenue
    FROM partner_profiles WHERE status='active'
    GROUP BY tier ORDER BY revenue DESC
")->fetchAll();

// ── Top performers ────────────────────────────────────────
$topPerformers = $pdo->query("
    SELECT pp.display_name, pp.tier, pp.active_clients, pp.monthly_revenue,
           COUNT(DISTINCT pl.id) AS leads_count,
           SUM(CASE WHEN pl.status='converted' THEN 1 ELSE 0 END) AS leads_converted,
           pp.performance_score
    FROM partner_profiles pp
    LEFT JOIN partner_leads pl ON pl.partner_id = pp.id
        AND pl.created_at >= DATE_SUB(NOW(), INTERVAL {$period} DAY)
    WHERE pp.status = 'active'
    GROUP BY pp.id
    ORDER BY pp.monthly_revenue DESC, pp.active_clients DESC
    LIMIT 10
")->fetchAll();

// ── Referral funnel ───────────────────────────────────────
$refFunnel = $pdo->query("
    SELECT status, COUNT(*) AS cnt, SUM(commission_amount) AS total_commission
    FROM partner_referrals
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$period} DAY)
    GROUP BY status
")->fetchAll();

// ── New partner trend (by week) ───────────────────────────
$partnerTrend = $pdo->query("
    SELECT DATE_FORMAT(created_at,'%Y-%m-%d') AS d, COUNT(*) AS cnt
    FROM partner_profiles
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
    GROUP BY DATE(created_at)
    ORDER BY d ASC
")->fetchAll();
$trendLabels = array_map(fn($r) => date('d M', strtotime($r['d'])), $partnerTrend);
$trendValues = array_map(fn($r) => (int)$r['cnt'], $partnerTrend);

// ── Commission pipeline ───────────────────────────────────
$commPipeline = $pdo->query("
    SELECT
        SUM(CASE WHEN status='pending' THEN amount ELSE 0 END) AS pending,
        SUM(CASE WHEN status='approved' THEN amount ELSE 0 END) AS approved,
        SUM(CASE WHEN status='paid' AND paid_at >= DATE_SUB(NOW(), INTERVAL {$period} DAY) THEN amount ELSE 0 END) AS paid_period
    FROM partner_commissions
")->fetch();

// ── Activity heatmap data (hour-of-day × day-of-week) ─────
$heatmap = $pdo->query("
    SELECT DAYOFWEEK(created_at)-1 AS dow, HOUR(created_at) AS hr, COUNT(*) AS cnt
    FROM partner_leads
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
    GROUP BY dow, hr
")->fetchAll();
$heatGrid = array_fill(0, 7, array_fill(0, 24, 0));
foreach ($heatmap as $h) { $heatGrid[(int)$h['dow']][(int)$h['hr']] = (int)$h['cnt']; }
$heatMax = max(1, max(array_map('max', $heatGrid)));
$dows = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

$pageTitle = 'Network Analytics';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.page-wrap{max-width:1200px;margin:0 auto;padding:1.5rem}
.stats-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:.75rem;margin-bottom:1.5rem}
.stat-card{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;padding:.9rem 1.1rem}
.stat-val{font-size:1.6rem;font-weight:800;color:#111827}
.stat-sub{font-size:.78rem;color:#059669;font-weight:600}
.stat-lbl{font-size:.72rem;color:#9ca3af;margin-top:.1rem}
.grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:1.1rem;margin-bottom:1.25rem}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:1.1rem;margin-bottom:1.25rem}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;overflow:hidden}
.card-header{padding:.65rem 1rem;border-bottom:1px solid #e5e7eb}
.card-header h2{margin:0;font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6b7280}
.card-body{padding:.85rem 1rem}
.funnel-bar{display:flex;align-items:center;gap:.5rem;margin-bottom:.4rem;font-size:.8rem}
.funnel-name{width:90px;color:#374151;font-weight:500;text-align:right}
.funnel-track{flex:1;height:20px;background:#f3f4f6;border-radius:.25rem;overflow:hidden}
.funnel-fill{height:100%;border-radius:.25rem;background:#3b82f6;transition:width .4s}
.funnel-cnt{width:40px;text-align:right;font-weight:700;color:#374151}
.perf-row{display:flex;align-items:center;gap:.5rem;padding:.45rem .7rem;border-bottom:1px solid #f3f4f6;font-size:.8rem}
.perf-row:last-child{border-bottom:none}
.perf-name{flex:1;font-weight:600;color:#111827;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.perf-meta{font-size:.7rem;color:#9ca3af}
.sparkline{display:flex;align-items:flex-end;gap:2px;height:60px}
.sp-bar{flex:1;background:#3b82f6;border-radius:2px 2px 0 0;min-height:2px;position:relative}
.sp-bar:hover::after{content:attr(data-tip);position:absolute;bottom:calc(100%+4px);left:50%;transform:translateX(-50%);background:#111;color:#fff;font-size:.65rem;padding:.15rem .35rem;border-radius:.3rem;white-space:nowrap;z-index:1}
.heatmap{display:flex;flex-direction:column;gap:2px;overflow-x:auto}
.hm-row{display:flex;gap:2px;align-items:center}
.hm-label{width:30px;font-size:.65rem;color:#9ca3af;text-align:right;flex-shrink:0}
.hm-cell{width:18px;height:14px;border-radius:2px;flex-shrink:0}
.badge{font-size:.68rem;font-weight:600;padding:.12rem .4rem;border-radius:99px}
.badge-blue{background:#dbeafe;color:#1d4ed8}
.badge-gold{background:#fef3c7;color:#92400e}
.btn{padding:.35rem .7rem;border-radius:.4rem;font-size:.78rem;font-weight:600;cursor:pointer;border:none;text-decoration:none;display:inline-block}
.btn-primary{background:#2563eb;color:#fff}
.btn-ghost{background:#f3f4f6;color:#374151}
.pipeline-bar{display:flex;border-radius:.35rem;overflow:hidden;height:20px;font-size:.7rem}
.pipe-seg{display:flex;align-items:center;justify-content:center;color:#fff;font-weight:600;min-width:1px}
</style>

<div class="page-wrap">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:.5rem">
        <div>
            <h1 style="margin:0;font-size:1.3rem;color:#111827">Network Analytics</h1>
            <p style="margin:.2rem 0 0;font-size:.83rem;color:#6b7280">Partner network performance and activity insights</p>
        </div>
        <div style="display:flex;gap:.35rem;align-items:center">
            <?php foreach (['7'=>'7d','30'=>'30d','90'=>'90d','365'=>'1y'] as $v => $l): ?>
            <a href="?period=<?= $v ?>" class="btn <?= $period==$v?'btn-primary':'btn-ghost' ?>"><?= $l ?></a>
            <?php endforeach ?>
            <a href="<?= SITE_URL ?>/admin" class="btn btn-ghost" style="margin-left:.25rem">← Admin</a>
        </div>
    </div>

    <!-- KPIs -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-val"><?= $kpis['active_partners'] ?></div>
            <div class="stat-sub">+<?= $kpis['new_partners'] ?> new</div>
            <div class="stat-lbl">Active Partners</div>
        </div>
        <div class="stat-card">
            <div class="stat-val"><?= number_format($kpis['total_clients']) ?></div>
            <div class="stat-lbl">Total Clients</div>
        </div>
        <div class="stat-card">
            <div class="stat-val">£<?= number_format($kpis['total_revenue']/1000,1) ?>k</div>
            <div class="stat-lbl">Network Revenue</div>
        </div>
        <div class="stat-card">
            <div class="stat-val"><?= $kpis['leads_period'] ?></div>
            <div class="stat-lbl">Leads (<?= $period ?>d)</div>
        </div>
        <div class="stat-card">
            <div class="stat-val" style="color:#059669"><?= $kpis['conversions_period'] ?></div>
            <div class="stat-lbl">Conversions</div>
        </div>
        <div class="stat-card">
            <div class="stat-val"><?= $kpis['referrals_period'] ?></div>
            <div class="stat-lbl">Referrals</div>
        </div>
        <div class="stat-card">
            <div class="stat-val">£<?= number_format($kpis['commissions_paid'] ?? 0) ?></div>
            <div class="stat-lbl">Commissions Paid</div>
        </div>
    </div>

    <!-- Commission pipeline -->
    <?php
    $commTotal = ($commPipeline['pending'] ?? 0) + ($commPipeline['approved'] ?? 0) + ($commPipeline['paid_period'] ?? 0);
    if ($commTotal > 0):
        $pendPct   = round(($commPipeline['pending'] ?? 0)  / $commTotal * 100);
        $appPct    = round(($commPipeline['approved'] ?? 0) / $commTotal * 100);
        $paidPct   = 100 - $pendPct - $appPct;
    ?>
    <div class="card" style="margin-bottom:1.25rem">
        <div class="card-header"><h2>Commission Pipeline</h2></div>
        <div class="card-body">
            <div style="display:flex;gap:1.5rem;font-size:.82rem;margin-bottom:.6rem;flex-wrap:wrap">
                <span>🟡 Pending: <strong>£<?= number_format($commPipeline['pending'] ?? 0) ?></strong></span>
                <span>🟢 Approved: <strong>£<?= number_format($commPipeline['approved'] ?? 0) ?></strong></span>
                <span>🔵 Paid (period): <strong>£<?= number_format($commPipeline['paid_period'] ?? 0) ?></strong></span>
            </div>
            <div class="pipeline-bar">
                <div class="pipe-seg" style="width:<?= $pendPct ?>%;background:#f59e0b"><?= $pendPct > 8 ? $pendPct.'%' : '' ?></div>
                <div class="pipe-seg" style="width:<?= $appPct ?>%;background:#10b981"><?= $appPct > 8 ? $appPct.'%' : '' ?></div>
                <div class="pipe-seg" style="width:<?= $paidPct ?>%;background:#3b82f6"><?= $paidPct > 8 ? $paidPct.'%' : '' ?></div>
            </div>
        </div>
    </div>
    <?php endif ?>

    <div class="grid-3">
        <!-- Lead funnel -->
        <div class="card">
            <div class="card-header"><h2>Lead Funnel (<?= $period ?>d)</h2></div>
            <div class="card-body">
                <?php foreach ($funnelOrder as $s): ?>
                <?php $cnt = $funnel[$s] ?? 0; ?>
                <div class="funnel-bar">
                    <div class="funnel-name"><?= ucfirst($s) ?></div>
                    <div class="funnel-track">
                        <div class="funnel-fill" style="width:<?= $funnelMax > 0 ? round($cnt/$funnelMax*100) : 0 ?>%;background:<?= $s==='converted'?'#10b981':($s==='lost'?'#ef4444':'#3b82f6') ?>"></div>
                    </div>
                    <div class="funnel-cnt"><?= $cnt ?></div>
                </div>
                <?php endforeach ?>
            </div>
        </div>

        <!-- Tier breakdown -->
        <div class="card">
            <div class="card-header"><h2>Partner Tiers</h2></div>
            <div class="card-body">
                <?php foreach ($tiers as $t): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.6rem;font-size:.83rem">
                    <span style="font-weight:600;color:#374151;text-transform:capitalize"><?= e($t['tier'] ?: 'Standard') ?></span>
                    <span style="color:#9ca3af"><?= $t['cnt'] ?> partners</span>
                    <span style="font-weight:700;color:#059669">£<?= number_format($t['revenue']/1000,1) ?>k</span>
                </div>
                <?php endforeach ?>
                <?php if (!$tiers): ?><p style="color:#9ca3af;font-size:.8rem;text-align:center">No data yet.</p><?php endif ?>
            </div>
        </div>

        <!-- Referral funnel -->
        <div class="card">
            <div class="card-header"><h2>Referral Funnel (<?= $period ?>d)</h2></div>
            <div class="card-body">
                <?php foreach ($refFunnel as $rf): ?>
                <div style="display:flex;justify-content:space-between;font-size:.82rem;margin-bottom:.4rem">
                    <span style="text-transform:capitalize;color:#374151"><?= e($rf['status']) ?></span>
                    <span style="font-weight:700"><?= $rf['cnt'] ?></span>
                    <span style="color:#059669">£<?= number_format($rf['total_commission'] ?? 0) ?></span>
                </div>
                <?php endforeach ?>
                <?php if (!$refFunnel): ?><p style="color:#9ca3af;font-size:.8rem;text-align:center">No referrals this period.</p><?php endif ?>
            </div>
        </div>
    </div>

    <div class="grid-2">
        <!-- Top performers -->
        <div class="card">
            <div class="card-header"><h2>Top Partners (<?= $period ?>d)</h2></div>
            <?php foreach ($topPerformers as $i => $p): ?>
            <div class="perf-row">
                <div style="width:1.4rem;font-size:.75rem;color:#9ca3af;font-weight:700"><?= $i+1 ?></div>
                <div style="flex:1">
                    <div class="perf-name"><?= e($p['display_name']) ?></div>
                    <div class="perf-meta"><?= ucfirst($p['tier'] ?? 'std') ?> · <?= $p['active_clients'] ?> clients · <?= $p['leads_count'] ?> leads</div>
                </div>
                <div style="text-align:right">
                    <div style="font-weight:700;font-size:.85rem;color:#059669">£<?= number_format($p['monthly_revenue']/1000,1) ?>k</div>
                    <?php if ($p['leads_converted'] > 0): ?><div style="font-size:.7rem;color:#2563eb"><?= $p['leads_converted'] ?> conv.</div><?php endif ?>
                </div>
            </div>
            <?php endforeach ?>
            <?php if (!$topPerformers): ?><div style="padding:2rem;text-align:center;color:#9ca3af;font-size:.83rem">No data yet.</div><?php endif ?>
        </div>

        <!-- Partner growth trend + activity heatmap -->
        <div>
            <?php if ($partnerTrend): ?>
            <div class="card" style="margin-bottom:1rem">
                <div class="card-header"><h2>Partner Growth (90d)</h2></div>
                <div style="padding:.75rem 1rem">
                    <?php $maxT = max(1, max($trendValues)); ?>
                    <div class="sparkline">
                        <?php foreach ($trendValues as $i => $v): ?>
                        <div class="sp-bar" style="height:<?= round($v/$maxT*100) ?>%" data-tip="<?= e($trendLabels[$i]) ?>: <?= $v ?>"></div>
                        <?php endforeach ?>
                    </div>
                </div>
            </div>
            <?php endif ?>

            <!-- Lead activity heatmap -->
            <div class="card">
                <div class="card-header"><h2>Lead Activity Heatmap (90d)</h2></div>
                <div style="padding:.6rem .85rem;overflow-x:auto">
                    <div style="font-size:.65rem;color:#9ca3af;margin-bottom:.25rem;padding-left:34px;display:flex;gap:2px">
                        <?php for ($h=0;$h<24;$h++): ?><div style="width:18px;text-align:center;flex-shrink:0"><?= $h%6===0?$h:'' ?></div><?php endfor ?>
                    </div>
                    <div class="heatmap">
                        <?php foreach ($dows as $di => $dn): ?>
                        <div class="hm-row">
                            <div class="hm-label"><?= $dn ?></div>
                            <?php for ($h=0;$h<24;$h++):
                                $v = $heatGrid[$di][$h];
                                $opacity = $v > 0 ? max(0.1, $v / $heatMax) : 0;
                            ?>
                            <div class="hm-cell" style="background:rgba(37,99,235,<?= round($opacity,2) ?>)" title="<?= $dn ?> <?= $h ?>:00 — <?= $v ?> leads"></div>
                            <?php endfor ?>
                        </div>
                        <?php endforeach ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
