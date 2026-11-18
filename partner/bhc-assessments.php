<?php
ini_set('display_errors', 1); error_reporting(E_ALL); // TEMP DEBUG
/**
 * partner/bhc-assessments.php
 * BHC leads attributed to this Growth Partner via bhc_leads.gp_id
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';
require_once __DIR__ . '/../includes/partner-lang.php';

$partner = requireGrowthPartner();
$pid     = (int)$partner['id'];
$pdo     = db();

// ── Filters ─────────────────────────────────────────────────────────
$statusFilter = trim($_GET['status'] ?? '');
$search       = trim($_GET['q']      ?? '');

$allowedStatuses = [
    'new', 'awaiting_followup', 'contact_requested',
    'interested', 'consultation_requested', 'converted',
    'not_interested', 'closed',
];

// ── Query ────────────────────────────────────────────────────────────
$where  = ['bl.gp_id = ?'];
$params = [$pid];

if ($statusFilter && in_array($statusFilter, $allowedStatuses, true)) {
    $where[]  = 'bl.status = ?';
    $params[] = $statusFilter;
}
if ($search !== '') {
    $where[]  = '(ba.business_name LIKE ? OR ba.email LIKE ?)';
    $like     = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}

$whereSql = implode(' AND ', $where);

$rows = $pdo->prepare("
    SELECT
        ba.id               AS assessment_id,
        ba.business_name,
        ba.email,
        ba.total_score,
        ba.score_band,
        ba.status           AS assessment_status,
        ba.created_at       AS submitted_at,
        ba.pdf_path,
        bl.id               AS lead_id,
        bl.status           AS lead_status,
        bl.created_at       AS lead_created_at
    FROM bhc_leads bl
    JOIN bhc_assessments ba ON ba.id = bl.assessment_id
    WHERE {$whereSql}
    ORDER BY bl.created_at DESC
    LIMIT 200
");
$rows->execute($params);
$assessments = $rows->fetchAll(PDO::FETCH_ASSOC);

// ── Counts per status (for tab badges) ───────────────────────────────
$counts = $pdo->prepare("
    SELECT bl.status, COUNT(*) AS n
    FROM bhc_leads bl
    WHERE bl.gp_id = ?
    GROUP BY bl.status
");
$counts->execute([$pid]);
$statusCounts = $counts->fetchAll(PDO::FETCH_KEY_PAIR);

$totalCount = array_sum($statusCounts);

// ── Score band helper ─────────────────────────────────────────────────
function bhcBandLabel(string $band): string {
    return match($band) {
        'needs_attention'   => 'Needs Attention',
        'developing'        => 'Developing',
        'good_progress'     => 'Good Progress',
        'strong_foundations'=> 'Strong Foundations',
        default             => ucwords(str_replace('_', ' ', $band)),
    };
}
function bhcBandClass(string $band): string {
    return match($band) {
        'needs_attention'    => 'band-needs',
        'developing'         => 'band-dev',
        'good_progress'      => 'band-good',
        'strong_foundations' => 'band-strong',
        default              => '',
    };
}
function bhcLeadStatusLabel(string $s): string {
    return match($s) {
        'new'                    => 'New',
        'awaiting_followup'      => 'Awaiting Follow-up',
        'contact_requested'      => 'Contact Requested',
        'interested'             => 'Interested',
        'consultation_requested' => 'Consultation Requested',
        'converted'              => 'Converted',
        'not_interested'         => 'Not Interested',
        'closed'                 => 'Closed',
        default                  => ucwords(str_replace('_', ' ', $s)),
    };
}
function bhcLeadStatusClass(string $s): string {
    return match($s) {
        'new'                    => 'ls-new',
        'awaiting_followup'      => 'ls-await',
        'contact_requested'      => 'ls-contact',
        'interested'             => 'ls-interested',
        'consultation_requested' => 'ls-consult',
        'converted'              => 'ls-converted',
        'not_interested'         => 'ls-no',
        'closed'                 => 'ls-closed',
        default                  => '',
    };
}

$pageTitle = 'BHC Assessments — Partner Centre';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/includes/nav.php';
partnerNavBadges($pid, $pdo);
?>
<?php partnerNavAssets(); ?>
<?php partnerNav('bhc-assessments'); ?>

<style>
.partner-wrap { max-width: 1200px; margin: 0 auto; padding: 2rem 1.5rem; }

/* ── Page header ─────────────────────────────────────── */
.bhca-head { display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; flex-wrap:wrap; margin-bottom:1.5rem; }
.bhca-head h1 { font-size:1.4rem; font-weight:700; margin:0; }
.bhca-head p  { margin:0.25rem 0 0; color:var(--text-muted); font-size:0.9rem; }
.btn-send-link {
    display:inline-flex; align-items:center; gap:0.4rem;
    background:var(--primary); color:#fff; text-decoration:none;
    padding:0.5rem 1.1rem; border-radius:8px; font-size:0.875rem; font-weight:600;
    white-space:nowrap; transition:opacity 0.15s;
}
.btn-send-link:hover { opacity:0.88; }

/* ── Filters bar ─────────────────────────────────────── */
.bhca-filters { display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center; margin-bottom:1.25rem; }
.status-tab {
    padding:0.3rem 0.85rem; border-radius:20px; border:1px solid var(--border);
    font-size:0.8rem; cursor:pointer; background:var(--card); color:var(--text); text-decoration:none;
    transition:background 0.12s, color 0.12s;
}
.status-tab:hover { background:rgba(0,168,120,0.1); }
.status-tab.active { background:var(--primary); color:#fff; border-color:var(--primary); }
.bhca-search { flex:1; min-width:160px; max-width:260px; }
.bhca-search input {
    width:100%; padding:0.35rem 0.75rem; border:1px solid var(--border);
    border-radius:20px; background:var(--card); color:var(--text);
    font-size:0.82rem; outline:none;
}
.bhca-search input:focus { border-color:var(--primary); }

/* ── Score band pills ────────────────────────────────── */
.band-pill { display:inline-block; padding:2px 9px; border-radius:20px; font-size:0.72rem; font-weight:700; text-transform:uppercase; }
.band-needs  { background:#fef2f2; color:#dc2626; }
.band-dev    { background:#fff7ed; color:#d97706; }
.band-good   { background:#f0fdf4; color:#15803d; }
.band-strong { background:#eff6ff; color:#1d4ed8; }

/* ── Lead status pills ───────────────────────────────── */
.ls-pill { display:inline-block; padding:2px 9px; border-radius:20px; font-size:0.72rem; font-weight:600; }
.ls-new      { background:#f0f9ff; color:#0369a1; }
.ls-await    { background:#fefce8; color:#a16207; }
.ls-contact  { background:#fdf4ff; color:#7e22ce; }
.ls-interested { background:#f0fdf4; color:#166534; }
.ls-consult  { background:#eff6ff; color:#1d4ed8; }
.ls-converted { background:#dcfce7; color:#15803d; font-weight:700; }
.ls-no       { background:#f1f5f9; color:#64748b; }
.ls-closed   { background:#f1f5f9; color:#94a3b8; }

/* Dark mode adjustments */
@media (prefers-color-scheme: dark) {
    .band-needs  { background:rgba(220,38,38,0.15);  color:#fca5a5; }
    .band-dev    { background:rgba(217,119,6,0.15);   color:#fcd34d; }
    .band-good   { background:rgba(21,128,61,0.15);   color:#86efac; }
    .band-strong { background:rgba(29,78,216,0.15);   color:#93c5fd; }
    .ls-new      { background:rgba(3,105,161,0.15);  color:#7dd3fc; }
    .ls-await    { background:rgba(161,98,7,0.15);   color:#fde68a; }
    .ls-contact  { background:rgba(126,34,206,0.15); color:#e9d5ff; }
    .ls-interested { background:rgba(22,101,52,0.15); color:#86efac; }
    .ls-consult  { background:rgba(29,78,216,0.15);  color:#93c5fd; }
    .ls-converted { background:rgba(21,128,61,0.2);  color:#4ade80; }
    .ls-no       { background:rgba(100,116,139,0.15); color:#94a3b8; }
    .ls-closed   { background:rgba(148,163,184,0.1);  color:#64748b; }
}

/* ── Assessment table ────────────────────────────────── */
.bhca-table-wrap { overflow-x:auto; }
table.bhca-tbl { width:100%; border-collapse:collapse; font-size:0.86rem; }
table.bhca-tbl th {
    text-align:left; padding:0.55rem 0.9rem;
    background:var(--card); border-bottom:2px solid var(--border);
    font-size:0.75rem; text-transform:uppercase; letter-spacing:.04em; color:var(--text-muted);
    white-space:nowrap;
}
table.bhca-tbl td {
    padding:0.7rem 0.9rem; border-bottom:1px solid var(--border);
    vertical-align:middle;
}
table.bhca-tbl tbody tr:hover { background:rgba(0,168,120,0.04); }
.biz-name { font-weight:600; }
.biz-email { font-size:0.8rem; color:var(--text-muted); }
.score-num { font-weight:700; font-size:0.95rem; }
.pdf-link { font-size:0.8rem; }
.pdf-link a { color:var(--primary); text-decoration:none; }
.pdf-link a:hover { text-decoration:underline; }

/* ── Empty state ─────────────────────────────────────── */
.bhca-empty { text-align:center; padding:3rem 1rem; color:var(--text-muted); }
.bhca-empty h3 { font-size:1.1rem; margin-bottom:0.5rem; }
</style>

<div class="partner-wrap">

    <div class="bhca-head">
        <div>
            <h1>&#x1FA7A; BHC Assessments</h1>
            <p>Leads attributed to your referral link — <?= $totalCount ?> total</p>
        </div>
        <a href="<?= e(SITE_URL . '/partner/bhc-send') ?>" class="btn-send-link">
            &#x1F517; Get Referral Link
        </a>
    </div>

    <!-- Filter tabs -->
    <form method="get" action="" id="bhca-filter-form">
        <div class="bhca-filters">
            <?php
            $allUrl = '?' . http_build_query(array_filter(['q' => $search]));
            ?>
            <a href="<?= e($allUrl) ?>" class="status-tab <?= $statusFilter === '' ? 'active' : '' ?>">
                All <strong>(<?= $totalCount ?>)</strong>
            </a>
            <?php
            $activeTabs = [
                'new'                    => 'New',
                'contact_requested'      => 'Contact Req.',
                'consultation_requested' => 'Consultation',
                'converted'              => 'Converted',
            ];
            foreach ($activeTabs as $val => $lbl):
                $cnt = $statusCounts[$val] ?? 0;
                $url = '?' . http_build_query(array_filter(['status' => $val, 'q' => $search]));
            ?>
            <a href="<?= e($url) ?>" class="status-tab <?= $statusFilter === $val ? 'active' : '' ?>">
                <?= e($lbl) ?> <strong>(<?= $cnt ?>)</strong>
            </a>
            <?php endforeach; ?>

            <div class="bhca-search">
                <input type="text" name="q" placeholder="Search business / email…"
                       value="<?= e($search) ?>"
                       onchange="this.form.submit()">
                <?php if ($statusFilter): ?>
                <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
                <?php endif; ?>
            </div>
        </div>
    </form>

    <!-- Table -->
    <?php if (empty($assessments)): ?>
    <div class="bhca-empty">
        <div style="font-size:2.5rem;margin-bottom:0.75rem;">&#x1FA7A;</div>
        <h3>No assessments found</h3>
        <p>Share your referral link to start receiving BHC leads attributed to you.</p>
        <a href="<?= e(SITE_URL . '/partner/bhc-send') ?>" class="btn-send-link" style="display:inline-flex;margin-top:1rem;">
            &#x1F517; Get Your Referral Link
        </a>
    </div>
    <?php else: ?>
    <div class="bhca-table-wrap">
        <table class="bhca-tbl">
            <thead>
                <tr>
                    <th>Business</th>
                    <th>Score</th>
                    <th>Band</th>
                    <th>Lead Status</th>
                    <th>Submitted</th>
                    <th>Report</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($assessments as $row): ?>
            <tr>
                <td>
                    <div class="biz-name"><?= e($row['business_name'] ?: '(unnamed)') ?></div>
                    <?php if ($row['email']): ?>
                    <div class="biz-email"><?= e($row['email']) ?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($row['total_score'] !== null): ?>
                    <span class="score-num"><?= (int)$row['total_score'] ?></span>
                    <span style="color:var(--text-muted);font-size:0.78rem;">/100</span>
                    <?php else: ?>
                    <span style="color:var(--text-muted);font-size:0.8rem;">In progress</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($row['score_band']): ?>
                    <span class="band-pill <?= bhcBandClass($row['score_band']) ?>">
                        <?= e(bhcBandLabel($row['score_band'])) ?>
                    </span>
                    <?php else: ?>
                    <span style="color:var(--text-muted);font-size:0.8rem;">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="ls-pill <?= bhcLeadStatusClass($row['lead_status']) ?>">
                        <?= e(bhcLeadStatusLabel($row['lead_status'])) ?>
                    </span>
                </td>
                <td style="white-space:nowrap;color:var(--text-muted);font-size:0.82rem;">
                    <?= e(date('d M Y', strtotime($row['submitted_at']))) ?>
                </td>
                <td class="pdf-link">
                    <?php if ($row['pdf_path'] && $row['assessment_status'] === 'completed'): ?>
                    <a href="<?= e(SITE_URL . '/' . ltrim($row['pdf_path'], '/')) ?>" target="_blank" rel="noopener">
                        &#x1F4C4; View PDF
                    </a>
                    <?php else: ?>
                    <span style="color:var(--text-muted);font-size:0.8rem;">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
