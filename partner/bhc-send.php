<?php
/**
 * partner/bhc-send.php
 * Generate & share the partner's tracked BHC referral link.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';
require_once __DIR__ . '/../includes/partner-lang.php';

$partner = requireGrowthPartner();
$pid     = (int)$partner['id'];
$pdo     = db();

// ── Get this partner's slug ───────────────────────────────────────────
$slugRow = $pdo->prepare("SELECT slug FROM partner_profiles WHERE id = ?");
$slugRow->execute([$pid]);
$slug = $slugRow->fetchColumn();

$slugMissing = empty($slug);
$referralUrl = (!$slugMissing && defined('SITE_URL'))
    ? rtrim(SITE_URL, '/') . '/business-health-check?gp=' . rawurlencode((string)$slug)
    : '';

// ── Attribution stats ─────────────────────────────────────────────────
$stats = $pdo->prepare("
    SELECT
        COUNT(*)                                              AS total,
        SUM(ba.status = 'completed')                        AS completed,
        SUM(ba.status != 'completed')                       AS in_progress,
        SUM(bl.status = 'converted')                        AS converted,
        SUM(bl.status = 'new')                              AS new_leads
    FROM bhc_leads bl
    JOIN bhc_assessments ba ON ba.id = bl.assessment_id
    WHERE bl.gp_id = ?
");
$stats->execute([$pid]);
$s = $stats->fetch(PDO::FETCH_ASSOC);

// ── Recent 10 leads ───────────────────────────────────────────────────
$recent = $pdo->prepare("
    SELECT
        ba.business_name,
        ba.total_score,
        ba.score_band,
        ba.status      AS assessment_status,
        bl.status      AS lead_status,
        bl.created_at
    FROM bhc_leads bl
    JOIN bhc_assessments ba ON ba.id = bl.assessment_id
    WHERE bl.gp_id = ?
    ORDER BY bl.created_at DESC
    LIMIT 10
");
$recent->execute([$pid]);
$recentLeads = $recent->fetchAll(PDO::FETCH_ASSOC);

// ── Band/status helpers (copied from bhc-assessments.php) ────────────
function bhcSendBandClass(string $band): string {
    return match($band) {
        'needs_attention'    => 'band-needs',
        'developing'         => 'band-dev',
        'good_progress'      => 'band-good',
        'strong_foundations' => 'band-strong',
        default              => '',
    };
}
function bhcSendBandLabel(string $band): string {
    return match($band) {
        'needs_attention'    => 'Needs Attention',
        'developing'         => 'Developing',
        'good_progress'      => 'Good Progress',
        'strong_foundations' => 'Strong Foundations',
        default              => ucwords(str_replace('_', ' ', $band)),
    };
}
function bhcSendLeadClass(string $s): string {
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
function bhcSendLeadLabel(string $s): string {
    return match($s) {
        'new'                    => 'New',
        'awaiting_followup'      => 'Awaiting Follow-up',
        'contact_requested'      => 'Contact Requested',
        'interested'             => 'Interested',
        'consultation_requested' => 'Consultation Req.',
        'converted'              => 'Converted',
        'not_interested'         => 'Not Interested',
        'closed'                 => 'Closed',
        default                  => ucwords(str_replace('_', ' ', $s)),
    };
}

$pageTitle = 'BHC Referral Link — Partner Centre';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/includes/nav.php';
partnerNavBadges($pid, $pdo);
?>
<?php partnerNavAssets(); ?>
<?php partnerNav('bhc-send'); ?>

<style>
.partner-wrap { max-width: 900px; margin: 0 auto; padding: 2rem 1.5rem; }

/* ── Page header ──────────────────────────────────────── */
.bhcs-head { margin-bottom: 2rem; }
.bhcs-head h1 { font-size: 1.4rem; font-weight: 700; margin: 0 0 0.3rem; }
.bhcs-head p  { margin: 0; color: var(--text-muted); font-size: 0.9rem; }

/* ── Stats row ────────────────────────────────────────── */
.bhcs-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}
.bhcs-stat {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 1rem 1.1rem;
    text-align: center;
}
.bhcs-stat-num  { font-size: 1.8rem; font-weight: 800; color: var(--primary); line-height: 1; }
.bhcs-stat-lbl  { font-size: 0.75rem; color: var(--text-muted); margin-top: 0.3rem; }

/* ── Link card ────────────────────────────────────────── */
.link-card {
    background: var(--card);
    border: 2px solid var(--primary);
    border-radius: 14px;
    padding: 1.5rem 1.5rem 1.25rem;
    margin-bottom: 2rem;
}
.link-card h2 { font-size: 1rem; font-weight: 700; margin: 0 0 0.75rem; }
.link-row { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
.link-input {
    flex: 1; min-width: 200px;
    padding: 0.55rem 0.9rem;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--bg);
    color: var(--text);
    font-size: 0.84rem;
    font-family: monospace;
    outline: none;
}
.btn-copy {
    padding: 0.55rem 1.1rem;
    background: var(--primary);
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 0.875rem;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    transition: opacity 0.15s;
}
.btn-copy:hover { opacity: 0.88; }
.copy-hint {
    font-size: 0.8rem;
    color: var(--text-muted);
    margin-top: 0.6rem;
}

/* ── WhatsApp / share buttons ─────────────────────────── */
.share-row { display: flex; gap: 0.6rem; flex-wrap: wrap; margin-top: 1rem; }
.share-btn {
    display: inline-flex; align-items: center; gap: 0.4rem;
    padding: 0.45rem 1rem;
    border-radius: 8px; font-size: 0.82rem; font-weight: 600;
    text-decoration: none; cursor: pointer; border: none;
    transition: opacity 0.15s;
}
.share-btn:hover { opacity: 0.85; }
.share-wa  { background: #25d366; color: #fff; }
.share-sms { background: #3b82f6; color: #fff; }
.share-email { background: var(--card); color: var(--text); border: 1px solid var(--border); }

/* ── How it works ─────────────────────────────────────── */
.how-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 1.25rem 1.5rem;
    margin-bottom: 2rem;
}
.how-card h2 { font-size: 1rem; font-weight: 700; margin: 0 0 1rem; }
.how-steps  { display: flex; gap: 1rem; flex-wrap: wrap; }
.how-step   { flex: 1; min-width: 140px; display: flex; gap: 0.6rem; align-items: flex-start; }
.how-num    {
    width: 28px; height: 28px; border-radius: 50%; background: var(--primary);
    color: #fff; font-size: 0.8rem; font-weight: 800;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.how-text p { margin: 0 0 0.2rem; font-size: 0.84rem; font-weight: 600; }
.how-text small { color: var(--text-muted); font-size: 0.78rem; }

/* ── Recent leads mini-table ──────────────────────────── */
.recent-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 12px;
    overflow: hidden;
}
.recent-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 1rem 1.25rem 0.75rem;
    border-bottom: 1px solid var(--border);
}
.recent-head h2 { font-size: 1rem; font-weight: 700; margin: 0; }
.recent-head a  { font-size: 0.82rem; color: var(--primary); text-decoration: none; }
.recent-head a:hover { text-decoration: underline; }
table.recent-tbl { width: 100%; border-collapse: collapse; font-size: 0.84rem; }
table.recent-tbl th {
    text-align: left; padding: 0.5rem 1rem;
    font-size: 0.72rem; text-transform: uppercase; letter-spacing: .04em;
    color: var(--text-muted); background: var(--card);
    border-bottom: 1px solid var(--border);
}
table.recent-tbl td { padding: 0.65rem 1rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
table.recent-tbl tbody tr:last-child td { border-bottom: none; }
table.recent-tbl tbody tr:hover { background: rgba(0,168,120,0.04); }

/* ── Band / lead-status pills (same as bhc-assessments) ─ */
.band-pill { display:inline-block; padding:2px 8px; border-radius:20px; font-size:0.7rem; font-weight:700; text-transform:uppercase; }
.band-needs  { background:#fef2f2; color:#dc2626; }
.band-dev    { background:#fff7ed; color:#d97706; }
.band-good   { background:#f0fdf4; color:#15803d; }
.band-strong { background:#eff6ff; color:#1d4ed8; }
.ls-pill { display:inline-block; padding:2px 8px; border-radius:20px; font-size:0.7rem; font-weight:600; }
.ls-new      { background:#f0f9ff; color:#0369a1; }
.ls-await    { background:#fefce8; color:#a16207; }
.ls-contact  { background:#fdf4ff; color:#7e22ce; }
.ls-interested { background:#f0fdf4; color:#166534; }
.ls-consult  { background:#eff6ff; color:#1d4ed8; }
.ls-converted { background:#dcfce7; color:#15803d; font-weight:700; }
.ls-no       { background:#f1f5f9; color:#64748b; }
.ls-closed   { background:#f1f5f9; color:#94a3b8; }

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

.empty-recent { text-align:center; padding:2rem; color:var(--text-muted); font-size:0.875rem; }
</style>

<div class="partner-wrap">

    <div class="bhcs-head">
        <h1>&#x1F517; BHC Referral Link</h1>
        <p>Share this link to attribute Business Health Check completions to you</p>
    </div>

    <?php if ($slugMissing): ?>
    <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:10px;padding:1rem 1.25rem;margin-bottom:1.5rem;color:#dc2626;font-size:0.875rem;">
        &#x26A0;&#xFE0F; <strong>Your partner profile does not have a referral slug set.</strong>
        Your referral link cannot be generated until an admin assigns your profile a unique slug.
        Please contact support.
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="bhcs-stats">
        <div class="bhcs-stat">
            <div class="bhcs-stat-num"><?= (int)($s['total'] ?? 0) ?></div>
            <div class="bhcs-stat-lbl">Total Leads</div>
        </div>
        <div class="bhcs-stat">
            <div class="bhcs-stat-num"><?= (int)($s['new_leads'] ?? 0) ?></div>
            <div class="bhcs-stat-lbl">New</div>
        </div>
        <div class="bhcs-stat">
            <div class="bhcs-stat-num"><?= (int)($s['completed'] ?? 0) ?></div>
            <div class="bhcs-stat-lbl">Completed</div>
        </div>
        <div class="bhcs-stat">
            <div class="bhcs-stat-num"><?= (int)($s['in_progress'] ?? 0) ?></div>
            <div class="bhcs-stat-lbl">In Progress</div>
        </div>
        <div class="bhcs-stat">
            <div class="bhcs-stat-num"><?= (int)($s['converted'] ?? 0) ?></div>
            <div class="bhcs-stat-lbl">Converted</div>
        </div>
    </div>

    <!-- Link card -->
    <div class="link-card">
        <h2>&#x1FA7A; Your Tracked Referral Link</h2>
        <div class="link-row">
            <input type="text" id="bhc-link" class="link-input" readonly
                   value="<?= e($referralUrl) ?>">
            <button class="btn-copy" onclick="copyBhcLink()">
                &#x1F4CB; Copy Link
            </button>
        </div>
        <p class="copy-hint">
            Anyone who completes the BHC via this link will be attributed to you.
            The attribution is tracked via a 30-day cookie.
        </p>

        <!-- Share shortcuts -->
        <div class="share-row">
            <?php
            $waText  = rawurlencode("Hi! Take this free Business Health Check to see how your business is performing: " . $referralUrl);
            $mailSub = rawurlencode("Free Business Health Check");
            $mailBody= rawurlencode("Hi,\n\nI'd like to invite you to complete a free Business Health Check.\n\nIt only takes a few minutes and gives you a personalised report on how your business is performing:\n\n" . $referralUrl . "\n\nBest regards");
            ?>
            <a href="https://wa.me/?text=<?= $waText ?>" target="_blank" rel="noopener" class="share-btn share-wa">
                &#x1F4F2; WhatsApp
            </a>
            <a href="mailto:?subject=<?= $mailSub ?>&body=<?= $mailBody ?>" class="share-btn share-email">
                &#x2709; Email
            </a>
            <a href="sms:?body=<?= rawurlencode("Take this free Business Health Check: " . $referralUrl) ?>" class="share-btn share-sms">
                &#x1F4AC; SMS
            </a>
        </div>
    </div>

    <!-- How it works -->
    <div class="how-card">
        <h2>How it works</h2>
        <div class="how-steps">
            <div class="how-step">
                <div class="how-num">1</div>
                <div class="how-text">
                    <p>Share the link</p>
                    <small>Send to prospects via WhatsApp, email, or any channel</small>
                </div>
            </div>
            <div class="how-step">
                <div class="how-num">2</div>
                <div class="how-text">
                    <p>They complete the BHC</p>
                    <small>A 21-question health check that takes ~5 minutes</small>
                </div>
            </div>
            <div class="how-step">
                <div class="how-num">3</div>
                <div class="how-text">
                    <p>They get a report</p>
                    <small>Personalised PDF with score and recommendations</small>
                </div>
            </div>
            <div class="how-step">
                <div class="how-num">4</div>
                <div class="how-text">
                    <p>You get the lead</p>
                    <small>Appears in your BHC Assessments list automatically</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent leads -->
    <div class="recent-card">
        <div class="recent-head">
            <h2>Recent Attributed Leads</h2>
            <?php if (!empty($recentLeads)): ?>
            <a href="<?= e(SITE_URL . '/partner/bhc-assessments') ?>">View all &#8594;</a>
            <?php endif; ?>
        </div>
        <?php if (empty($recentLeads)): ?>
        <p class="empty-recent">No leads yet — share your referral link to get started!</p>
        <?php else: ?>
        <table class="recent-tbl">
            <thead>
                <tr>
                    <th>Business</th>
                    <th>Score</th>
                    <th>Band</th>
                    <th>Status</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recentLeads as $r): ?>
            <tr>
                <td><?= e($r['business_name'] ?: '(unnamed)') ?></td>
                <td>
                    <?php if ($r['total_score'] !== null): ?>
                    <strong><?= (int)$r['total_score'] ?></strong><span style="color:var(--text-muted);font-size:0.78rem;">/100</span>
                    <?php else: ?>
                    <span style="color:var(--text-muted);">In progress</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($r['score_band']): ?>
                    <span class="band-pill <?= bhcSendBandClass($r['score_band']) ?>">
                        <?= e(bhcSendBandLabel($r['score_band'])) ?>
                    </span>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td>
                    <span class="ls-pill <?= bhcSendLeadClass($r['lead_status']) ?>">
                        <?= e(bhcSendLeadLabel($r['lead_status'])) ?>
                    </span>
                </td>
                <td style="color:var(--text-muted);font-size:0.8rem;white-space:nowrap;">
                    <?= e(date('d M Y', strtotime($r['created_at']))) ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

</div>

<script>
function copyBhcLink() {
    var input = document.getElementById('bhc-link');
    var btn   = document.querySelector('.btn-copy');
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(input.value).then(function() {
            btn.textContent = '✓ Copied!';
            setTimeout(function(){ btn.textContent = '📋 Copy Link'; }, 2000);
        }).catch(function() {
            fallbackCopy(input, btn);
        });
    } else {
        fallbackCopy(input, btn);
    }
}
function fallbackCopy(input, btn) {
    input.select();
    input.setSelectionRange(0, 99999);
    try {
        document.execCommand('copy');
        btn.textContent = '✓ Copied!';
        setTimeout(function(){ btn.textContent = '📋 Copy Link'; }, 2000);
    } catch(e) {}
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
