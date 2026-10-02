<?php
/**
 * partner/reports.php — 237Biz Growth Partner
 * Growth report generation and management.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';

$partner = requireGrowthPartner();
$pid     = (int)$partner['id'];
$pdo     = db();
$userId  = (int)($_SESSION['user_id'] ?? 0);

/* ═══════════════════════════════════════════════════════════════
   Helper: snapshot metrics for a listing in a date range
   ═══════════════════════════════════════════════════════════════ */
function buildReportSnapshot(int $pid, int $lid, string $start, string $end, \PDO $pdo): array {
    $snap = [];

    // Tasks
    $r = $pdo->prepare("SELECT status, COUNT(*) AS cnt FROM growth_tasks WHERE partner_id=? AND listing_id=? GROUP BY status");
    $r->execute([$pid,$lid]); foreach ($r->fetchAll() as $row) $snap['tasks'][$row['status']] = (int)$row['cnt'];

    // Leads
    $r = $pdo->prepare("SELECT status, COUNT(*) AS cnt FROM partner_leads WHERE partner_id=? AND listing_id=? GROUP BY status");
    $r->execute([$pid,$lid]); foreach ($r->fetchAll() as $row) $snap['leads'][$row['status']] = (int)$row['cnt'];

    // Leads in period
    $r = $pdo->prepare("SELECT COUNT(*) FROM partner_leads WHERE partner_id=? AND listing_id=? AND created_at BETWEEN ? AND ?");
    $r->execute([$pid,$lid,$start.' 00:00:00',$end.' 23:59:59']); $snap['leads_in_period'] = (int)$r->fetchColumn();

    // Campaigns
    $r = $pdo->prepare("SELECT status, COUNT(*) AS cnt FROM partner_campaigns WHERE partner_id=? AND listing_id=? GROUP BY status");
    $r->execute([$pid,$lid]); foreach ($r->fetchAll() as $row) $snap['campaigns'][$row['status']] = (int)$row['cnt'];

    // Campaign metrics in period
    $r = $pdo->prepare("SELECT COALESCE(SUM(impressions),0) impr, COALESCE(SUM(clicks),0) cl,
                         COALESCE(SUM(leads),0) leads, COALESCE(SUM(conversions),0) conv,
                         COALESCE(SUM(profile_visits),0) pv, COALESCE(SUM(whatsapp_clicks),0) wa
                         FROM campaign_metrics cm
                         JOIN campaigns c ON c.id=cm.campaign_id
                         WHERE c.partner_id=? AND c.listing_id=? AND cm.metric_date BETWEEN ? AND ?");
    $r->execute([$pid,$lid,$start,$end]); $snap['campaign_metrics'] = $r->fetch(\PDO::FETCH_ASSOC);

    // Reviews
    $r = $pdo->prepare("SELECT COUNT(*) cnt, COALESCE(AVG(rating),0) avg_rating FROM reviews WHERE listing_id=?");
    $r->execute([$lid]); $snap['reviews'] = $r->fetch(\PDO::FETCH_ASSOC);

    $r = $pdo->prepare("SELECT COUNT(*) FROM reviews WHERE listing_id=? AND created_at BETWEEN ? AND ?");
    $r->execute([$lid,$start.' 00:00:00',$end.' 23:59:59']); $snap['reviews_in_period'] = (int)$r->fetchColumn();

    // Profile completion
    $r = $pdo->prepare("SELECT * FROM listings WHERE id=?"); $r->execute([$lid]);
    $listing = $r->fetch();
    $snap['profile_completion'] = $listing ? profileCompletion($listing) : 0;

    // Tasks completed in period
    $r = $pdo->prepare("SELECT COUNT(*) FROM growth_tasks WHERE partner_id=? AND listing_id=? AND status='completed' AND updated_at BETWEEN ? AND ?");
    $r->execute([$pid,$lid,$start.' 00:00:00',$end.' 23:59:59']); $snap['tasks_completed_period'] = (int)$r->fetchColumn();

    // Leads converted in period
    $r = $pdo->prepare("SELECT COUNT(*) FROM partner_leads WHERE partner_id=? AND listing_id=? AND status='converted' AND updated_at BETWEEN ? AND ?");
    $r->execute([$pid,$lid,$start.' 00:00:00',$end.' 23:59:59']); $snap['leads_converted_period'] = (int)$r->fetchColumn();

    $snap['generated_at'] = date('Y-m-d H:i:s');
    $snap['partner_id']   = $pid;
    $snap['listing_id']   = $lid;
    $snap['period_start'] = $start;
    $snap['period_end']   = $end;
    return $snap;
}

/* ── POST handlers ─────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'generate_report') {
        $lid    = (int)($_POST['listing_id'] ?? 0);
        if (!partnerCanAccessListing($pid, $lid)) die('Forbidden');
        $title  = trim($_POST['title'] ?? '');
        $start  = $_POST['period_start'] ?: date('Y-m-01');
        $end    = $_POST['period_end']   ?: date('Y-m-d');
        if (!$title) {
            $title = 'Growth Report: ' . date('M Y', strtotime($start)) . ($start !== $end ? ' – ' . date('M Y', strtotime($end)) : '');
        }
        $snap = buildReportSnapshot($pid, $lid, $start, $end, $pdo);
        $pdo->prepare("INSERT INTO growth_reports
                        (partner_id,listing_id,period_start,period_end,title,summary_json,status,generated_at,created_by)
                       VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$pid,$lid,$start,$end,$title,json_encode($snap),'generated',date('Y-m-d H:i:s'),$userId]);
        $rid = (int)$pdo->lastInsertId();
        logBusinessActivity($lid,$pid,$userId,'report_generated',"Growth report '$title' generated",'report',$rid);
        partnerAuditLog($pid,$userId,'report_generated',"Report $rid: $title",[]);
        setFlash('success','Report generated successfully.');
        redirect(SITE_URL.'/partner/reports?view=report&rid='.$rid);
    }

    if ($action === 'share_report') {
        $rid = (int)($_POST['report_id'] ?? 0);
        $r   = $pdo->prepare("SELECT * FROM growth_reports WHERE id=? AND partner_id=?");
        $r->execute([$rid,$pid]);
        $report = $r->fetch();
        if (!$report) die('Forbidden');
        $pdo->prepare("UPDATE growth_reports SET status='shared', shared_at=NOW() WHERE id=?")->execute([$rid]);
        setFlash('success','Report marked as shared with business owner.');
        redirect(SITE_URL.'/partner/reports?view=report&rid='.$rid);
    }
}

/* ── View params ───────────────────────────────────────────────── */
$view      = $_GET['view'] ?? 'list';    // list | report
$viewRid   = (int)($_GET['rid'] ?? 0);
$filterLid = (int)($_GET['lid'] ?? 0);

/* ── Listings ──────────────────────────────────────────────────── */
$listings = getPartnerListings($pid);

/* ── Reports list ──────────────────────────────────────────────── */
$where  = ["r.partner_id = $pid"];
$params = [];
if ($filterLid) { $where[] = "r.listing_id = ?"; $params[] = $filterLid; }
$st = $pdo->prepare("SELECT r.*, l.title AS biz_name
                     FROM growth_reports r
                     JOIN listings l ON l.id = r.listing_id
                     WHERE " . implode(' AND ', $where) . "
                     ORDER BY r.generated_at DESC");
$st->execute($params);
$reports = $st->fetchAll();

/* ── Single report view ────────────────────────────────────────── */
$reportDetail = null;
$snap         = null;
if ($view === 'report' && $viewRid) {
    $r = $pdo->prepare("SELECT r.*, l.title AS biz_name, c.name_en AS cat_en, loc.name_en AS city
                        FROM growth_reports r
                        JOIN listings l ON l.id=r.listing_id
                        JOIN categories c ON c.id=l.category_id
                        JOIN locations loc ON loc.id=l.location_id
                        WHERE r.id=? AND r.partner_id=?");
    $r->execute([$viewRid,$pid]);
    $reportDetail = $r->fetch();
    if ($reportDetail && $reportDetail['summary_json']) {
        $snap = json_decode($reportDetail['summary_json'], true);
    }
}

$pageTitle = 'Growth Reports';
require_once __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/partner.css">
<style>
.rep-header{background:linear-gradient(135deg,#7c3aed 0%,#5b21b6 100%);color:#fff;padding:28px 32px;border-radius:12px;margin-bottom:24px}
.rep-header h1{margin:0 0 6px;font-size:1.6rem}
.rep-header p{margin:0;opacity:.85;font-size:.95rem}
.create-form{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:24px;margin-bottom:24px}
.create-form h3{margin:0 0 18px;font-size:1.1rem;color:#111827}
.f-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.f-full{grid-column:1/-1}
.form-group{display:flex;flex-direction:column;gap:4px}
.form-group label{font-size:.85rem;font-weight:500;color:#374151}
.form-group input,.form-group select{padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;width:100%;box-sizing:border-box}
.btn-xs{padding:5px 12px;font-size:.8rem;border-radius:5px;border:1px solid #d1d5db;background:#fff;cursor:pointer;color:#374151;text-decoration:none;display:inline-block}
.btn-xs:hover{background:#f3f4f6}
.btn-xs.primary{background:#7c3aed;color:#fff;border-color:#7c3aed}
.btn-xs.primary:hover{background:#5b21b6}
.btn-xs.green{background:#16a34a;color:#fff;border-color:#16a34a}
.reports-list{display:flex;flex-direction:column;gap:12px}
.report-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px 20px;display:flex;align-items:center;gap:16px}
.report-card:hover{box-shadow:0 4px 12px rgba(0,0,0,.06)}
.report-icon{font-size:2rem;width:48px;text-align:center;flex-shrink:0}
.report-body{flex:1;min-width:0}
.report-title{font-weight:600;font-size:.97rem;color:#111827;margin-bottom:4px}
.report-meta{font-size:.82rem;color:#6b7280;display:flex;gap:12px;flex-wrap:wrap}
.report-actions{display:flex;gap:8px;flex-shrink:0}
.status-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.78rem;font-weight:600}
.st-draft{background:#f3f4f6;color:#374151}
.st-generated{background:#dbeafe;color:#1e40af}
.st-shared{background:#dcfce7;color:#166534}
/* Report view */
.report-view{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:36px;max-width:860px;margin:0 auto}
.report-view h2{margin:0 0 6px;font-size:1.5rem;color:#111827}
.report-byline{font-size:.9rem;color:#6b7280;margin-bottom:28px;padding-bottom:16px;border-bottom:2px solid #7c3aed}
.metric-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:16px;margin:20px 0}
.metric-box{background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:16px;text-align:center}
.metric-box .val{font-size:2rem;font-weight:700;color:#7c3aed;line-height:1}
.metric-box .lbl{font-size:.8rem;color:#6b7280;margin-top:6px}
.section-h{font-size:1.05rem;font-weight:600;color:#111827;margin:28px 0 12px;padding-bottom:8px;border-bottom:1px solid #f3f4f6}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin:16px 0}
.stat-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f9fafb;font-size:.9rem}
.stat-row .label{color:#374151}
.stat-row .value{font-weight:600;color:#111827}
.progress-bar{background:#e5e7eb;border-radius:10px;height:8px;overflow:hidden;margin-top:6px}
.progress-fill{height:100%;border-radius:10px;background:linear-gradient(90deg,#7c3aed,#a855f7);transition:width .4s}
.no-data{color:#9ca3af;font-style:italic;font-size:.9rem;padding:16px 0;text-align:center}
.back-link{display:inline-flex;align-items:center;gap:6px;color:#6b7280;font-size:.9rem;text-decoration:none;margin-bottom:16px}
.back-link:hover{color:#7c3aed}
.flash-success{background:#dcfce7;border:1px solid #86efac;color:#166534;padding:12px 18px;border-radius:8px;margin-bottom:18px;font-size:.92rem}
.filter-bar{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 20px;margin-bottom:20px;display:flex;gap:12px;flex-wrap:wrap;align-items:center}
.filter-bar select{padding:7px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem}
.empty-state{text-align:center;padding:48px 20px;color:#9ca3af}
@media print {
    .rep-header,.create-form,.filter-bar,.report-actions,.back-link,.btn-xs{display:none}
    .report-view{border:none;padding:0;max-width:100%}
}
</style>

<div class="container" style="max-width:1000px;margin:30px auto;padding:0 16px">

<?php if ($flash = getFlash('success')): ?>
<div class="flash-success">✓ <?= e($flash) ?></div>
<?php endif; ?>

<?php if ($view === 'report' && $reportDetail && $snap): ?>
<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- SINGLE REPORT VIEW                                              -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<a href="<?= SITE_URL ?>/partner/reports" class="back-link">← Back to Reports</a>

<div style="display:flex;gap:12px;align-items:center;margin-bottom:20px;flex-wrap:wrap">
    <div class="report-actions">
        <button onclick="window.print()" class="btn-xs">🖨 Print / PDF</button>
        <?php if ($reportDetail['status'] !== 'shared'): ?>
        <form method="post" style="display:inline">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="share_report">
            <input type="hidden" name="report_id" value="<?= $reportDetail['id'] ?>">
            <button type="submit" class="btn-xs green">✓ Mark Shared</button>
        </form>
        <?php else: ?>
        <span class="status-badge st-shared">✓ Shared with Owner</span>
        <?php endif; ?>
    </div>
</div>

<div class="report-view">
    <div style="text-align:center;margin-bottom:20px">
        <div style="font-size:2rem">📊</div>
        <h2><?= e($reportDetail['title']) ?></h2>
        <div class="report-byline">
            <?= e($reportDetail['biz_name']) ?> · <?= e($reportDetail['cat_en']) ?>, <?= e($reportDetail['city']) ?><br>
            Period: <?= date('d M Y', strtotime($reportDetail['period_start'])) ?> – <?= date('d M Y', strtotime($reportDetail['period_end'])) ?><br>
            Generated: <?= date('d M Y H:i', strtotime($reportDetail['generated_at'])) ?>
        </div>
    </div>

    <!-- Key metrics -->
    <h3 class="section-h">📈 Performance Overview</h3>
    <div class="metric-grid">
        <div class="metric-box">
            <div class="val"><?= $snap['reviews_in_period'] ?? 0 ?></div>
            <div class="lbl">New Reviews</div>
        </div>
        <div class="metric-box">
            <div class="val"><?= round($snap['reviews']['avg_rating'] ?? 0, 1) ?></div>
            <div class="lbl">Avg Rating</div>
        </div>
        <div class="metric-box">
            <div class="val"><?= $snap['leads_in_period'] ?? 0 ?></div>
            <div class="lbl">New Leads</div>
        </div>
        <div class="metric-box">
            <div class="val"><?= $snap['leads_converted_period'] ?? 0 ?></div>
            <div class="lbl">Leads Converted</div>
        </div>
        <div class="metric-box">
            <div class="val"><?= $snap['tasks_completed_period'] ?? 0 ?></div>
            <div class="lbl">Tasks Done</div>
        </div>
        <div class="metric-box">
            <div class="val"><?= $snap['profile_completion'] ?? 0 ?>%</div>
            <div class="lbl">Profile Complete</div>
        </div>
    </div>
    <?php if (!empty($snap['profile_completion'])): ?>
    <div style="margin-bottom:8px">
        <div style="font-size:.8rem;color:#6b7280;margin-bottom:4px">Profile Completion</div>
        <div class="progress-bar"><div class="progress-fill" style="width:<?= $snap['profile_completion'] ?>%"></div></div>
    </div>
    <?php endif; ?>

    <!-- Campaign metrics -->
    <?php if (!empty($snap['campaign_metrics']) && ($snap['campaign_metrics']['impr'] > 0)): $cm = $snap['campaign_metrics']; ?>
    <h3 class="section-h">📣 Campaign Performance (Period)</h3>
    <div class="two-col">
        <div>
            <div class="stat-row"><span class="label">Impressions</span><span class="value"><?= number_format($cm['impr']) ?></span></div>
            <div class="stat-row"><span class="label">Profile Visits</span><span class="value"><?= number_format($cm['pv']) ?></span></div>
            <div class="stat-row"><span class="label">Clicks</span><span class="value"><?= number_format($cm['cl']) ?></span></div>
        </div>
        <div>
            <div class="stat-row"><span class="label">WhatsApp Clicks</span><span class="value"><?= number_format($cm['wa']) ?></span></div>
            <div class="stat-row"><span class="label">Leads Generated</span><span class="value"><?= number_format($cm['leads']) ?></span></div>
            <div class="stat-row"><span class="label">Conversions</span><span class="value"><?= number_format($cm['conv']) ?></span></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Leads breakdown -->
    <h3 class="section-h">🎯 Lead Pipeline</h3>
    <?php if (!empty($snap['leads'])): ?>
    <div class="two-col">
        <div>
        <?php $totalLeads = array_sum($snap['leads']);
              foreach ($snap['leads'] as $st => $cnt): ?>
        <div class="stat-row"><span class="label"><?= ucfirst($st) ?></span><span class="value"><?= $cnt ?></span></div>
        <?php endforeach; ?>
        <div class="stat-row" style="border-top:2px solid #e5e7eb"><span class="label"><strong>Total</strong></span><span class="value"><strong><?= $totalLeads ?></strong></span></div>
        </div>
        <div>
            <?php $conv = $snap['leads']['converted'] ?? 0;
                  $rate = $totalLeads > 0 ? round($conv/$totalLeads*100) : 0; ?>
            <div class="metric-box" style="background:#f0fdf4;border-color:#86efac">
                <div class="val" style="color:#16a34a"><?= $rate ?>%</div>
                <div class="lbl">Conversion Rate</div>
            </div>
        </div>
    </div>
    <?php else: ?>
    <p class="no-data">No lead data for this period.</p>
    <?php endif; ?>

    <!-- Reviews -->
    <h3 class="section-h">⭐ Reviews & Reputation</h3>
    <div class="two-col">
        <div>
            <div class="stat-row"><span class="label">Total Reviews</span><span class="value"><?= $snap['reviews']['cnt'] ?? 0 ?></span></div>
            <div class="stat-row"><span class="label">Avg Rating</span><span class="value"><?= round($snap['reviews']['avg_rating'] ?? 0, 1) ?> / 5</span></div>
            <div class="stat-row"><span class="label">Reviews in Period</span><span class="value"><?= $snap['reviews_in_period'] ?? 0 ?></span></div>
        </div>
        <div>
            <?php $avgR = $snap['reviews']['avg_rating'] ?? 0; ?>
            <div class="metric-box" style="background:#fffbeb;border-color:#fcd34d">
                <div class="val" style="color:#b45309"><?= round($avgR,1) ?>⭐</div>
                <div class="lbl">Avg Rating</div>
            </div>
        </div>
    </div>

    <!-- Tasks -->
    <h3 class="section-h">✅ Task Progress</h3>
    <?php if (!empty($snap['tasks'])): ?>
    <?php $totalT = array_sum($snap['tasks']);
          $doneT = $snap['tasks']['completed'] ?? 0;
          $doneRate = $totalT > 0 ? round($doneT/$totalT*100) : 0; ?>
    <div class="stat-row"><span class="label">Completed</span><span class="value"><?= $doneT ?> / <?= $totalT ?></span></div>
    <div class="progress-bar" style="margin:8px 0 16px">
        <div class="progress-fill" style="width:<?= $doneRate ?>%;background:linear-gradient(90deg,#16a34a,#4ade80)"></div>
    </div>
    <?php else: ?>
    <p class="no-data">No tasks recorded.</p>
    <?php endif; ?>

    <!-- Campaigns summary -->
    <h3 class="section-h">📣 Campaign Summary</h3>
    <?php if (!empty($snap['campaigns'])): ?>
    <?php foreach ($snap['campaigns'] as $cst => $cnt): ?>
    <div class="stat-row"><span class="label"><?= ucfirst($cst) ?></span><span class="value"><?= $cnt ?></span></div>
    <?php endforeach; ?>
    <?php else: ?>
    <p class="no-data">No campaigns in portfolio.</p>
    <?php endif; ?>

    <div style="margin-top:32px;padding-top:16px;border-top:1px solid #e5e7eb;font-size:.8rem;color:#9ca3af;text-align:center">
        Report generated by 237Biz Growth Partner Portal · <?= date('d M Y H:i', strtotime($reportDetail['generated_at'])) ?>
    </div>
</div>

<?php else: ?>
<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- REPORTS LIST VIEW                                               -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="rep-header">
    <h1>📊 Growth Reports</h1>
    <p><?= count($reports) ?> report<?= count($reports) !== 1 ? 's' : '' ?> generated</p>
</div>

<!-- Generate report form -->
<div class="create-form">
    <h3>📋 Generate New Report</h3>
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="generate_report">
        <div class="f-grid">
            <div class="form-group">
                <label>Business <span style="color:red">*</span></label>
                <select name="listing_id" required>
                    <option value="">Select business…</option>
                    <?php foreach ($listings as $l): ?>
                    <option value="<?= $l['id'] ?>" <?= $filterLid==$l['id']?'selected':'' ?>><?= e($l['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Report Title (optional)</label>
                <input type="text" name="title" placeholder="Auto-generated if blank">
            </div>
            <div class="form-group">
                <label>Period Start <span style="color:red">*</span></label>
                <input type="date" name="period_start" value="<?= date('Y-m-01') ?>" required>
            </div>
            <div class="form-group">
                <label>Period End <span style="color:red">*</span></label>
                <input type="date" name="period_end" value="<?= date('Y-m-d') ?>" required>
            </div>
        </div>
        <div style="margin-top:14px">
            <button type="submit" class="btn-xs primary" style="padding:8px 20px;font-size:.9rem">📊 Generate Report</button>
        </div>
    </form>
</div>

<!-- Filter -->
<div class="filter-bar">
    <form method="get" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center">
        <select name="lid" onchange="this.form.submit()">
            <option value="">All Businesses</option>
            <?php foreach ($listings as $l): ?>
            <option value="<?= $l['id'] ?>" <?= $filterLid==$l['id']?'selected':'' ?>><?= e($l['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <a href="<?= SITE_URL ?>/partner/reports" class="btn-xs">Clear</a>
    </form>
</div>

<!-- Reports list -->
<?php if (empty($reports)): ?>
<div class="empty-state">
    <div style="font-size:3rem;margin-bottom:12px">📊</div>
    <p>No reports generated yet. Generate your first report above.</p>
</div>
<?php else: ?>
<div class="reports-list">
<?php foreach ($reports as $report): ?>
<div class="report-card">
    <div class="report-icon">📋</div>
    <div class="report-body">
        <div class="report-title"><?= e($report['title']) ?></div>
        <div class="report-meta">
            <span>🏢 <?= e($report['biz_name']) ?></span>
            <span>📅 <?= date('d M Y', strtotime($report['period_start'])) ?> – <?= date('d M Y', strtotime($report['period_end'])) ?></span>
            <?php if ($report['generated_at']): ?>
            <span>⏱ Generated <?= date('d M Y', strtotime($report['generated_at'])) ?></span>
            <?php endif; ?>
            <?php if ($report['shared_at']): ?>
            <span>✓ Shared <?= date('d M Y', strtotime($report['shared_at'])) ?></span>
            <?php endif; ?>
            <span class="status-badge st-<?= $report['status'] ?>"><?= ucfirst($report['status']) ?></span>
        </div>
    </div>
    <div class="report-actions" style="display:flex;gap:8px;flex-shrink:0">
        <a href="<?= SITE_URL ?>/partner/reports?view=report&rid=<?= $report['id'] ?>" class="btn-xs primary">View</a>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php endif; // end list/report view ?>

</div><!-- /container -->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
