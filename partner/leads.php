<?php
/**
 * partner/leads.php — All Leads (General + BHC Health Check)
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';
require_once __DIR__ . '/../includes/partner-lang.php';

$partnerProfile = requireGrowthPartner();
$partnerId      = (int)$partnerProfile['id'];
$pdo            = db();

// ── Which tab? general | bhc ──────────────────────────────────────────────────
$type          = in_array($_GET['type'] ?? '', ['bhc']) ? 'bhc' : 'general';
$statusFilter  = trim($_GET['status'] ?? '');
$search        = trim($_GET['q'] ?? '');

// ── Handle POST: update general lead status ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    gpVerifyCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'update_lead') {
        $leadId = (int)$_POST['lead_id'];
        $st = $pdo->prepare("SELECT id, listing_id FROM partner_leads WHERE id=? AND partner_id=?");
        $st->execute([$leadId, $partnerId]);
        $lead = $st->fetch();
        if ($lead) {
            $newStatus  = in_array($_POST['new_status'], ['new','contacted','follow_up','qualified','converted','lost','closed']) ? $_POST['new_status'] : null;
            $nextAction = trim($_POST['next_action'] ?? '');
            if ($newStatus) {
                $pdo->prepare("UPDATE partner_leads SET status=?, next_action=?, last_activity=NOW() WHERE id=?")
                    ->execute([$newStatus, $nextAction ?: null, $leadId]);
                partnerAuditLog($partnerId, currentUser()['id'], $lead['listing_id'], 'lead_updated', "Lead #{$leadId} status → {$newStatus}");
            }
        }
    }
    header('Location: ?type=general&status=' . urlencode($statusFilter));
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// GENERAL LEADS
// ═══════════════════════════════════════════════════════════════════════════════
$generalStatusAllowed = ['new','contacted','follow_up','qualified','converted','lost','closed'];

$gWhere  = ["pl.partner_id = ?"];
$gParams = [$partnerId];

if ($statusFilter === 'active') {
    $gWhere[] = "pl.status IN ('new','contacted','follow_up')";
} elseif ($statusFilter === 'pipeline') {
    $gWhere[] = "pl.status IN ('new','contacted','follow_up','qualified')";
} elseif (in_array($statusFilter, $generalStatusAllowed)) {
    $gWhere[] = "pl.status = ?";
    $gParams[] = $statusFilter;
}
if ($search) {
    $gWhere[] = "(pl.customer_name LIKE ? OR pl.customer_email LIKE ? OR pl.customer_phone LIKE ? OR l.title LIKE ?)";
    $like = "%$search%";
    $gParams = array_merge($gParams, [$like, $like, $like, $like]);
}

$gSt = $pdo->prepare("
    SELECT pl.*, l.title AS listing_title, c.name_en AS cat_en, loc.name_en AS city
    FROM partner_leads pl
    LEFT JOIN listings l ON l.id = pl.listing_id
    LEFT JOIN categories c ON c.id = l.category_id
    LEFT JOIN locations loc ON loc.id = l.location_id
    WHERE " . implode(' AND ', $gWhere) . "
    ORDER BY FIELD(pl.status,'new','follow_up','contacted','qualified','converted','lost','closed'),
             pl.last_activity DESC, pl.created_at DESC
");
$gSt->execute($gParams);
$generalLeads = $gSt->fetchAll();

// Audit responses for general leads
$auditResponses = [];
$auditQuestions = [];
if ($generalLeads) {
    $leadIds = array_column($generalLeads, 'id');
    $in = implode(',', array_fill(0, count($leadIds), '?'));
    $arSt = $pdo->prepare("SELECT * FROM audit_responses WHERE lead_id IN ($in) AND partner_id=?");
    $arSt->execute(array_merge($leadIds, [$partnerId]));
    foreach ($arSt->fetchAll() as $ar) {
        $auditResponses[$ar['lead_id']] = $ar;
    }
    if ($auditResponses) {
        $auditQuestions = $pdo->query("SELECT id, question_text, sort_order FROM audit_questions ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
    }
}

$myListings = fetchPartnerListings($partnerId);

// ═══════════════════════════════════════════════════════════════════════════════
// BHC LEADS
// ═══════════════════════════════════════════════════════════════════════════════
$bhcStatusAllowed = ['new','awaiting_followup','contact_requested','interested','consultation_requested','converted','not_interested','closed'];

$bWhere  = ['bl.gp_id = ?'];
$bParams = [$partnerId];

if ($statusFilter && in_array($statusFilter, $bhcStatusAllowed)) {
    $bWhere[]  = 'bl.status = ?';
    $bParams[] = $statusFilter;
}
if ($search !== '') {
    $bWhere[]  = '(ba.business_name LIKE ? OR ba.contact_email LIKE ?)';
    $like      = '%' . $search . '%';
    $bParams[] = $like;
    $bParams[] = $like;
}

$bRows = $pdo->prepare("
    SELECT
        ba.id               AS assessment_id,
        ba.business_name,
        ba.contact_email    AS email,
        ba.contact_name,
        ba.total_score,
        ba.score_band,
        ba.status           AS assessment_status,
        ba.created_at       AS submitted_at,
        bl.id               AS lead_id,
        bl.status           AS lead_status,
        bl.created_at       AS lead_created_at
    FROM bhc_leads bl
    JOIN bhc_assessments ba ON ba.id = bl.assessment_id
    WHERE " . implode(' AND ', $bWhere) . "
    ORDER BY bl.created_at DESC
    LIMIT 200
");
$bRows->execute($bParams);
$bhcLeads = $bRows->fetchAll(PDO::FETCH_ASSOC);

// BHC status counts for tab badges
$bCountSt = $pdo->prepare("SELECT bl.status, COUNT(*) AS n FROM bhc_leads bl WHERE bl.gp_id = ? GROUP BY bl.status");
$bCountSt->execute([$partnerId]);
$bhcStatusCounts = $bCountSt->fetchAll(PDO::FETCH_KEY_PAIR);
$bhcTotal = array_sum($bhcStatusCounts);

// ── Band / status helpers ─────────────────────────────────────────────────────
function bhcBandLabel(string $band): string {
    return match($band) {
        'needs_attention'    => 'Needs Attention',
        'developing'         => 'Developing',
        'good_progress'      => 'Good Progress',
        'strong_foundations' => 'Strong Foundations',
        default              => ucwords(str_replace('_', ' ', $band)),
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
        'new'                    => 'ls-bhc-new',
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

$pageTitle = pt('Leads') . ' — ' . pt('Partner Centre');
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/includes/nav.php';
partnerNavBadges($partnerId, $pdo);
?>
<?php partnerNavAssets(); ?>
<?php partnerNav('leads'); ?>

<style>
.partner-wrap { max-width:1280px; margin:0 auto; padding:2rem 1.5rem; }

/* ── Type switcher tabs ───────────────────────────── */
.type-tabs { display:flex; gap:0; border:1px solid var(--border); border-radius:10px; overflow:hidden; margin-bottom:1.5rem; width:fit-content; }
.type-tab  { padding:0.5rem 1.25rem; font-size:0.875rem; font-weight:600; text-decoration:none;
             background:var(--card); color:var(--text); border:none; cursor:pointer; transition:background 0.12s, color 0.12s; }
.type-tab + .type-tab { border-left:1px solid var(--border); }
.type-tab.active { background:var(--primary); color:#fff; }

/* ── Status filter tabs ──────────────────────────── */
.status-tab { padding:0.35rem 0.9rem; border-radius:20px; border:1px solid var(--border);
  font-size:0.8rem; cursor:pointer; background:var(--card); color:var(--text); text-decoration:none; }
.status-tab.active { background:var(--primary); color:#fff; border-color:var(--primary); }

/* ── General lead cards ──────────────────────────── */
.lead-card { background:var(--card); border:1px solid var(--border); border-radius:12px; padding:1.1rem 1.25rem; margin-bottom:0.75rem; }
.lead-header { display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; flex-wrap:wrap; }
.lead-status { padding:3px 10px; border-radius:20px; font-size:0.72rem; font-weight:700; text-transform:uppercase; }
.ls-new        { background:rgba(252,209,22,.2);  color:#b8960f; }
.ls-contacted  { background:rgba(0,168,120,.12);  color:#00A878; }
.ls-follow_up  { background:rgba(255,159,28,.2);  color:#d4780f; }
.ls-qualified  { background:rgba(46,196,182,.15); color:#1a8f88; }
.ls-converted  { background:rgba(0,168,120,.25);  color:#006e50; }
.ls-lost       { background:rgba(230,57,70,.12);  color:#e63946; }
.ls-closed     { background:rgba(150,150,150,.15);color:var(--muted); }

/* ── BHC table ───────────────────────────────────── */
.bhca-table-wrap { overflow-x:auto; }
table.bhca-tbl { width:100%; border-collapse:collapse; font-size:0.86rem; }
table.bhca-tbl th {
    text-align:left; padding:0.55rem 0.9rem;
    background:var(--card); border-bottom:2px solid var(--border);
    font-size:0.75rem; text-transform:uppercase; letter-spacing:.04em; color:var(--muted);
    white-space:nowrap;
}
table.bhca-tbl td { padding:0.7rem 0.9rem; border-bottom:1px solid var(--border); vertical-align:middle; }
table.bhca-tbl tbody tr:hover { background:rgba(0,168,120,0.04); }
.biz-name { font-weight:600; }
.biz-email { font-size:0.8rem; color:var(--muted); }
.score-num { font-weight:700; font-size:0.95rem; }

/* ── Band pills ──────────────────────────────────── */
.band-pill { display:inline-block; padding:2px 9px; border-radius:20px; font-size:0.72rem; font-weight:700; text-transform:uppercase; }
.band-needs  { background:#fef2f2; color:#dc2626; }
.band-dev    { background:#fff7ed; color:#d97706; }
.band-good   { background:#f0fdf4; color:#15803d; }
.band-strong { background:#eff6ff; color:#1d4ed8; }
@media (prefers-color-scheme: dark) {
    .band-needs  { background:rgba(220,38,38,0.15);  color:#fca5a5; }
    .band-dev    { background:rgba(217,119,6,0.15);   color:#fcd34d; }
    .band-good   { background:rgba(21,128,61,0.15);   color:#86efac; }
    .band-strong { background:rgba(29,78,216,0.15);   color:#93c5fd; }
}

/* ── BHC status pills ────────────────────────────── */
.ls-pill { display:inline-block; padding:2px 9px; border-radius:20px; font-size:0.72rem; font-weight:600; }
.ls-bhc-new    { background:#f0f9ff; color:#0369a1; }
.ls-await      { background:#fefce8; color:#a16207; }
.ls-contact    { background:#fdf4ff; color:#7e22ce; }
.ls-interested { background:#f0fdf4; color:#166534; }
.ls-consult    { background:#eff6ff; color:#1d4ed8; }
.ls-no         { background:#f1f5f9; color:#64748b; }
@media (prefers-color-scheme: dark) {
    .ls-bhc-new    { background:rgba(3,105,161,0.15);  color:#7dd3fc; }
    .ls-await      { background:rgba(161,98,7,0.15);   color:#fde68a; }
    .ls-contact    { background:rgba(126,34,206,0.15); color:#e9d5ff; }
    .ls-interested { background:rgba(22,101,52,0.15);  color:#86efac; }
    .ls-consult    { background:rgba(29,78,216,0.15);  color:#93c5fd; }
    .ls-no         { background:rgba(100,116,139,0.15);color:#94a3b8; }
    .ls-closed     { background:rgba(148,163,184,0.1); color:#64748b; }
}
</style>

<div class="partner-wrap">

  <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1rem; margin-bottom:1.25rem;">
    <h1 style="font-family:'Fraunces',serif; font-size:2rem; font-weight:900; margin:0;">💬 <?= pt('Leads') ?></h1>
    <?php if ($type === 'bhc'): ?>
    <a href="<?= e(SITE_URL . '/partner/bhc-send') ?>" style="display:inline-flex;align-items:center;gap:0.4rem;background:var(--primary);color:#fff;text-decoration:none;padding:0.5rem 1.1rem;border-radius:8px;font-size:0.875rem;font-weight:600;">
      🔗 Get Referral Link
    </a>
    <?php endif; ?>
  </div>

  <!-- Type switcher -->
  <div class="type-tabs">
    <a href="?type=general<?= $search ? '&q='.urlencode($search) : '' ?>"
       class="type-tab <?= $type === 'general' ? 'active' : '' ?>">
      💬 General Leads <span style="font-size:0.78rem;opacity:.7;">(<?= count($generalLeads) ?>)</span>
    </a>
    <a href="?type=bhc<?= $search ? '&q='.urlencode($search) : '' ?>"
       class="type-tab <?= $type === 'bhc' ? 'active' : '' ?>">
      🩺 Health Check Leads <span style="font-size:0.78rem;opacity:.7;">(<?= $bhcTotal ?>)</span>
    </a>
  </div>

<?php if ($type === 'general'): ?>
  <!-- ── GENERAL LEADS ──────────────────────────────────────────────────── -->

  <!-- Status tabs -->
  <div style="display:flex; gap:0.4rem; flex-wrap:wrap; margin-bottom:1rem;">
    <?php
    $tabs = ['active'=>pt('Active'),'pipeline'=>pt('Full Pipeline'),'new'=>pt('New'),'contacted'=>pt('Contacted'),'follow_up'=>pt('Follow Up'),'qualified'=>pt('Qualified'),'converted'=>pt('Converted'),'lost'=>pt('Lost')];
    foreach ($tabs as $tk => $tl): ?>
    <a href="?type=general&status=<?= $tk ?>" class="status-tab <?= $statusFilter===$tk?'active':'' ?>"><?= $tl ?></a>
    <?php endforeach; ?>
  </div>

  <!-- Search + listing filter -->
  <form method="GET" style="display:flex; gap:0.75rem; flex-wrap:wrap; align-items:center; margin-bottom:1.5rem;">
    <input type="hidden" name="type" value="general">
    <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
    <input type="text" name="q" value="<?= e($search) ?>" placeholder="<?= pt('Search leads…') ?>"
      style="flex:1; min-width:180px; padding:0.45rem 0.75rem; border:1px solid var(--border); border-radius:8px; background:var(--card); color:var(--text); font-size:0.875rem;">
    <select name="listing" style="padding:0.45rem 0.75rem; border:1px solid var(--border); border-radius:8px; background:var(--card); color:var(--text); font-size:0.875rem;">
      <option value=""><?= pt('All Businesses') ?></option>
      <?php foreach ($myListings as $ml): ?>
      <option value="<?= $ml['id'] ?>"><?= e($ml['title']) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary" style="white-space:nowrap;"><?= pt('Filter') ?></button>
  </form>

  <?php if (!$generalLeads): ?>
    <div style="text-align:center; padding:4rem; background:var(--card); border:1px solid var(--border); border-radius:14px;">
      <div style="font-size:3rem; margin-bottom:1rem;">📭</div>
      <h3 style="font-family:'Fraunces',serif;"><?= pt('No leads found') ?></h3>
      <p style="color:var(--muted);"><?= pt('Leads are added from the business management page.') ?></p>
    </div>
  <?php else: ?>
    <?php foreach ($generalLeads as $lead): ?>
    <div class="lead-card">
      <div class="lead-header">
        <div style="flex:1; min-width:0;">
          <div style="display:flex; align-items:center; gap:0.6rem; flex-wrap:wrap; margin-bottom:0.3rem;">
            <strong><?= e($lead['customer_name'] ?: 'Unknown Customer') ?></strong>
            <span class="lead-status ls-<?= $lead['status'] ?>"><?= str_replace('_',' ', $lead['status']) ?></span>
            <?php if ($lead['source']): ?><span style="font-size:0.78rem; color:var(--muted);">via <?= e(ucfirst($lead['source'])) ?></span><?php endif; ?>
          </div>
          <div style="font-size:0.78rem; color:var(--muted); display:flex; gap:1rem; flex-wrap:wrap;">
            <?php if ($lead['customer_email']): ?><span>✉ <?= e($lead['customer_email']) ?></span><?php endif; ?>
            <?php if ($lead['customer_phone']): ?><span>📞 <?= e($lead['customer_phone']) ?></span><?php endif; ?>
            <?php
              $bizLabel = $lead['listing_title'];
              if (!$bizLabel && $lead['notes']) {
                  if (preg_match('/^Business:\s*(.+)/m', $lead['notes'], $m)) $bizLabel = trim($m[1]);
              }
              if (!$bizLabel) $bizLabel = ($lead['source'] === 'audit') ? pt('Audit lead') : '—';
            ?>
            <?php if ($lead['listing_id']): ?>
            <span>📍 <a href="<?= SITE_URL ?>/partner/business?id=<?= $lead['listing_id'] ?>" style="color:var(--primary); text-decoration:none;"><?= e($bizLabel) ?></a></span>
            <?php else: ?>
            <span>📍 <?= e($bizLabel) ?></span>
            <?php endif; ?>
            <span><?= date('j M Y', strtotime($lead['created_at'])) ?></span>
          </div>
        </div>
        <?php if ($lead['listing_id']): ?>
        <a href="<?= SITE_URL ?>/partner/business?id=<?= $lead['listing_id'] ?>&tab=leads" style="font-size:0.8rem; color:var(--primary); text-decoration:none; flex-shrink:0;"><?= pt('View') ?> →</a>
        <?php endif; ?>
      </div>

      <?php if ($lead['next_action']): ?>
      <div style="margin-top:0.5rem; font-size:0.83rem; color:var(--muted);">
        <div>→ <strong><?= pt('Next:') ?></strong> <?= e($lead['next_action']) ?></div>
      </div>
      <?php endif; ?>

      <?php
        $auditResp = $auditResponses[$lead['id']] ?? null;
        if ($auditResp && $auditQuestions):
          $answers = json_decode($auditResp['answers_json'] ?? '{}', true) ?: [];
      ?>
      <details style="margin-top:0.75rem;">
        <summary style="font-size:0.8rem; color:var(--accent); cursor:pointer; user-select:none; font-weight:600;">📋 <?= pt('Audit Form Answers') ?></summary>
        <div style="margin-top:0.5rem; border:1px solid var(--border); border-radius:8px; overflow:hidden;">
          <?php foreach ($auditQuestions as $q):
            $ans = $answers[$q['id']] ?? '';
            if (is_array($ans)) $ans = implode(', ', $ans);
            if ($ans === '') continue;
          ?>
          <div style="padding:0.4rem 0.75rem; border-bottom:1px solid var(--border); font-size:0.78rem;">
            <div style="color:var(--muted); margin-bottom:0.1rem;"><?= htmlspecialchars($q['question_text']) ?></div>
            <div style="color:var(--text); font-weight:500;"><?= nl2br(htmlspecialchars($ans)) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </details>
      <?php elseif ($lead['notes'] && $lead['source'] !== 'audit'): ?>
      <div style="margin-top:0.5rem; font-size:0.83rem; color:var(--muted);">
        <div><?= e(mb_strimwidth($lead['notes'], 0, 140, '…')) ?></div>
      </div>
      <?php endif; ?>

      <?php if (!in_array($lead['status'], ['converted','lost','closed'])): ?>
      <details style="margin-top:0.75rem;">
        <summary style="font-size:0.8rem; color:var(--primary); cursor:pointer; user-select:none;"><?= pt('Update status') ?></summary>
        <form method="POST" style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:flex-end; margin-top:0.5rem;">
          <?= gpCsrfField() ?>
          <input type="hidden" name="action" value="update_lead">
          <input type="hidden" name="lead_id" value="<?= $lead['id'] ?>">
          <select name="new_status" style="padding:0.4rem; border:1px solid var(--border); border-radius:6px; background:var(--card); color:var(--text); font-size:0.83rem;">
            <?php foreach (['new','contacted','follow_up','qualified','converted','lost','closed'] as $s): ?>
            <option value="<?= $s ?>" <?= $lead['status']===$s?'selected':'' ?>><?= ucwords(str_replace('_',' ',$s)) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" name="next_action" value="<?= e($lead['next_action'] ?? '') ?>" placeholder="<?= pt('Next action…') ?>"
            style="flex:1; min-width:140px; padding:0.4rem 0.6rem; border:1px solid var(--border); border-radius:6px; background:var(--bg); color:var(--text); font-size:0.83rem;">
          <button type="submit" class="btn btn-primary" style="font-size:0.83rem; padding:0.35rem 0.75rem;"><?= pt('Save') ?></button>
        </form>
      </details>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

<?php else: ?>
  <!-- ── BHC HEALTH CHECK LEADS ──────────────────────────────────────── -->

  <!-- Status tabs -->
  <div style="display:flex; gap:0.4rem; flex-wrap:wrap; margin-bottom:1rem;">
    <?php
    $allUrl = '?type=bhc' . ($search ? '&q='.urlencode($search) : '');
    ?>
    <a href="<?= e($allUrl) ?>" class="status-tab <?= $statusFilter === '' ? 'active' : '' ?>">
      All <strong>(<?= $bhcTotal ?>)</strong>
    </a>
    <?php
    $bhcTabs = ['new'=>'New','contact_requested'=>'Contact Req.','consultation_requested'=>'Consultation','converted'=>'Converted'];
    foreach ($bhcTabs as $val => $lbl):
      $cnt = $bhcStatusCounts[$val] ?? 0;
      $url = '?type=bhc&status=' . $val . ($search ? '&q='.urlencode($search) : '');
    ?>
    <a href="<?= e($url) ?>" class="status-tab <?= $statusFilter === $val ? 'active' : '' ?>">
      <?= e($lbl) ?> <strong>(<?= $cnt ?>)</strong>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Search -->
  <form method="get" style="margin-bottom:1.5rem;">
    <input type="hidden" name="type" value="bhc">
    <?php if ($statusFilter): ?><input type="hidden" name="status" value="<?= e($statusFilter) ?>"><?php endif; ?>
    <input type="text" name="q" value="<?= e($search) ?>"
           placeholder="Search business / email…"
           style="padding:0.45rem 0.75rem; border:1px solid var(--border); border-radius:8px; background:var(--card); color:var(--text); font-size:0.875rem; min-width:220px;">
  </form>

  <?php if (empty($bhcLeads)): ?>
  <div style="text-align:center; padding:3rem 1rem; background:var(--card); border:1px solid var(--border); border-radius:14px; color:var(--muted);">
    <div style="font-size:2.5rem;margin-bottom:0.75rem;">🩺</div>
    <h3 style="font-size:1.1rem;margin-bottom:0.5rem;">No health check leads yet</h3>
    <p>Share your referral link to start receiving BHC leads attributed to you.</p>
    <a href="<?= e(SITE_URL . '/partner/bhc-send') ?>" style="display:inline-flex;align-items:center;gap:0.4rem;background:var(--primary);color:#fff;text-decoration:none;padding:0.5rem 1.1rem;border-radius:8px;font-size:0.875rem;font-weight:600;margin-top:1rem;">
      🔗 Get Your Referral Link
    </a>
  </div>
  <?php else: ?>
  <div class="bhca-table-wrap">
    <table class="bhca-tbl">
      <thead>
        <tr>
          <th>Business / Contact</th>
          <th>Score</th>
          <th>Band</th>
          <th>Lead Status</th>
          <th>Submitted</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($bhcLeads as $row): ?>
      <tr>
        <td>
          <div class="biz-name"><?= e($row['business_name'] ?: '(unnamed)') ?></div>
          <?php if ($row['contact_name']): ?>
          <div class="biz-email"><?= e($row['contact_name']) ?></div>
          <?php endif; ?>
          <?php if ($row['email']): ?>
          <div class="biz-email">✉ <?= e($row['email']) ?></div>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($row['total_score'] !== null): ?>
          <span class="score-num"><?= (int)$row['total_score'] ?></span>
          <span style="color:var(--muted);font-size:0.78rem;">/100</span>
          <?php else: ?>
          <span style="color:var(--muted);font-size:0.8rem;">In progress</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($row['score_band']): ?>
          <span class="band-pill <?= bhcBandClass($row['score_band']) ?>">
            <?= e(bhcBandLabel($row['score_band'])) ?>
          </span>
          <?php else: ?>
          <span style="color:var(--muted);font-size:0.8rem;">—</span>
          <?php endif; ?>
        </td>
        <td>
          <span class="ls-pill <?= bhcLeadStatusClass($row['lead_status']) ?>">
            <?= e(bhcLeadStatusLabel($row['lead_status'])) ?>
          </span>
        </td>
        <td style="white-space:nowrap;color:var(--muted);font-size:0.82rem;">
          <?= e(date('d M Y', strtotime($row['submitted_at']))) ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

<?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
