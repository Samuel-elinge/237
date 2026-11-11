<?php
/**
 * partner/pipeline.php — Lead Pipeline Board (Phase 5C)
 * Visual Kanban-style view of partner_leads with extended statuses.
 * Shows activity timeline per lead, integrates WA quick-send.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';
require_once __DIR__ . '/../includes/partner-lang.php';
require_once __DIR__ . '/includes/nav.php';
if (!function_exists('partnerNavBadges')) {
    function partnerNavBadges(int $pid, PDO $pdo): void {
        $st = $pdo->prepare("SELECT COUNT(*) FROM growth_tasks WHERE partner_id=? AND status IN ('todo','in_progress') AND due_date < CURDATE()");
        $st->execute([$pid]); $GLOBALS['_pnav_overdue_tasks'] = (int)$st->fetchColumn();
        $st = $pdo->prepare("SELECT COUNT(*) FROM partner_leads WHERE partner_id=? AND follow_up_date < CURDATE() AND status NOT IN ('converted','lost','closed')");
        $st->execute([$pid]); $GLOBALS['_pnav_overdue_leads'] = (int)$st->fetchColumn();
        $st = $pdo->prepare("SELECT COUNT(*) FROM growth_alerts WHERE partner_id=? AND status IN ('new','viewed')");
        $st->execute([$pid]); $GLOBALS['_pnav_alerts'] = (int)$st->fetchColumn();
        $st = $pdo->prepare("SELECT COUNT(*) FROM growth_alerts WHERE partner_id=? AND status IN ('new','viewed') AND priority='urgent'");
        $st->execute([$pid]); $GLOBALS['_pnav_urgent'] = (int)$st->fetchColumn();
        $st = $pdo->prepare("SELECT COUNT(*) FROM ai_recommendations WHERE partner_id=? AND status IN ('new','viewed')");
        $st->execute([$pid]); $GLOBALS['_pnav_recs'] = (int)$st->fetchColumn();
        $st = $pdo->prepare("SELECT COUNT(*) FROM marketplace_orders WHERE partner_profile_id=? AND order_state NOT IN ('COMPLETED','CANCELLED','REJECTED')");
        $st->execute([$pid]); $GLOBALS['_pnav_active_orders'] = (int)$st->fetchColumn();
    }
}

$partnerProfile = requireGrowthPartner();
$partnerId      = (int)$partnerProfile['id'];
$userId         = currentUser()['id'];
$pdo            = db();

// -- POST handler -------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // Move lead to new status
    if ($action === 'move_lead') {
        $leadId    = (int)($_POST['lead_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? '';
        $validStatuses = ['new','contacted','interested','follow_up','proposal_sent','negotiation','qualified','won','converted','lost','closed'];
        if ($leadId && in_array($newStatus, $validStatuses)) {
            $chk = $pdo->prepare("SELECT id, customer_name FROM partner_leads WHERE id=? AND partner_id=?");
            $chk->execute([$leadId, $partnerId]);
            $lead = $chk->fetch();
            if ($lead) {
                $pdo->prepare("UPDATE partner_leads SET status=?, last_activity=NOW() WHERE id=?")->execute([$newStatus, $leadId]);
                // Log activity
                $pdo->prepare("INSERT INTO lead_activities (lead_id, partner_id, activity_type, notes, created_by)
                    VALUES (?,?,?,?,?)")
                    ->execute([$leadId, $partnerId, 'note', "Status changed to: {$newStatus}", $userId]);
                partnerAuditLog($partnerId, $userId, null, 'lead_moved', "{$lead['customer_name']} → {$newStatus}");
                echo json_encode(['ok' => true]); exit;
            }
        }
        echo json_encode(['ok' => false]); exit;
    }

    // Add activity / note to lead
    if ($action === 'add_activity') {
        $leadId   = (int)($_POST['lead_id'] ?? 0);
        $actType  = $_POST['activity_type'] ?? 'note';
        $notes    = trim($_POST['notes'] ?? '');
        $fup      = $_POST['follow_up_date'] ?? null;
        $newStatus = trim($_POST['new_status'] ?? '');
        $validTypes = ['contacted','email_sent','message_sent','phone_call','follow_up','appointment','note','proposal_sent','negotiation'];
        $validStatuses = ['new','contacted','interested','follow_up','proposal_sent','negotiation','qualified','won','converted','lost','closed'];

        if ($leadId && in_array($actType, $validTypes) && $notes) {
            $chk = $pdo->prepare("SELECT id FROM partner_leads WHERE id=? AND partner_id=?");
            $chk->execute([$leadId, $partnerId]);
            if ($chk->fetch()) {
                $pdo->prepare("INSERT INTO lead_activities (lead_id, partner_id, activity_type, notes, follow_up_date, created_by)
                    VALUES (?,?,?,?,?,?)")
                    ->execute([$leadId, $partnerId, $actType, $notes, $fup ?: null, $userId]);
                // Update status if provided
                if ($newStatus && in_array($newStatus, $validStatuses)) {
                    $pdo->prepare("UPDATE partner_leads SET status=?, last_activity=NOW() WHERE id=?")->execute([$newStatus, $leadId]);
                } else {
                    $pdo->prepare("UPDATE partner_leads SET last_activity=NOW() WHERE id=?")->execute([$leadId]);
                }
                if ($fup) {
                    $pdo->prepare("UPDATE partner_leads SET follow_up_date=? WHERE id=?")->execute([$fup, $leadId]);
                }
                partnerAuditLog($partnerId, $userId, null, 'lead_activity_added', "Lead {$leadId}: {$actType}");
                setFlash('success', 'Activity logged.');
            }
        }
        redirect(SITE_URL . '/partner/pipeline' . ($leadId ? '?open=' . $leadId : ''));
    }

    // Quick add lead
    if ($action === 'add_lead') {
        $name       = trim($_POST['customer_name'] ?? '');
        $phone      = trim($_POST['customer_phone'] ?? '');
        $email      = trim($_POST['customer_email'] ?? '');
        $listingId  = (int)($_POST['listing_id'] ?? 0) ?: null;
        $notes      = trim($_POST['notes'] ?? '');
        if ($name) {
            if ($listingId && !partnerCanAccessListing($partnerId, $listingId)) {
                setFlash('error', 'Access denied.');
            } else {
                $pdo->prepare("INSERT INTO partner_leads (partner_id, listing_id, customer_name, customer_phone, customer_email, notes, status, source)
                    VALUES (?,?,?,?,?,?,'new','pipeline')")
                    ->execute([$partnerId, $listingId, $name, $phone ?: null, $email ?: null, $notes ?: null]);
                partnerAuditLog($partnerId, $userId, $listingId, 'lead_created', "New lead: {$name}");
                setFlash('success', "Lead \"{$name}\" added.");
            }
        }
        redirect(SITE_URL . '/partner/pipeline');
    }

    redirect(SITE_URL . '/partner/pipeline');
}

// -- Data ---------------------------------------------------------
// Pipeline stages in order
$stages = [
    'new'           => ['label' => 'New',           'icon' => '🆕', 'color' => '#6b7280'],
    'contacted'     => ['label' => 'Contacted',     'icon' => '📞', 'color' => '#3b82f6'],
    'interested'    => ['label' => 'Interested',    'icon' => '✨', 'color' => '#8b5cf6'],
    'proposal_sent' => ['label' => 'Proposal Sent', 'icon' => '📄', 'color' => '#f59e0b'],
    'negotiation'   => ['label' => 'Negotiation',   'icon' => '🤝', 'color' => '#f97316'],
    'won'           => ['label' => 'Won',            'icon' => '🏆', 'color' => '#10b981'],
    'lost'          => ['label' => 'Lost',           'icon' => '❌', 'color' => '#ef4444'],
];

// Also show follow_up, qualified, converted, closed in "other"
$allStatuses = array_keys($stages);

// Filter
$filterListing = (int)($_GET['biz'] ?? 0);
$openLeadId    = (int)($_GET['open'] ?? 0);

$where  = ['pl.partner_id = ?'];
$params = [$partnerId];
if ($filterListing) {
    $where[]  = 'pl.listing_id = ?';
    $params[] = $filterListing;
}

$leadsSt = $pdo->prepare("
    SELECT pl.*, l.title AS biz_name, l.whatsapp AS biz_wa
    FROM partner_leads pl
    LEFT JOIN listings l ON l.id = pl.listing_id
    WHERE " . implode(' AND ', $where) . "
    AND pl.status NOT IN ('closed','converted')
    ORDER BY pl.created_at DESC
");
$leadsSt->execute($params);
$allLeads = $leadsSt->fetchAll();

// Bucket by status
$buckets = array_fill_keys(array_keys($stages), []);
foreach ($allLeads as $lead) {
    $s = $lead['status'];
    if (!isset($buckets[$s])) $s = 'new'; // map unknown → new
    $buckets[$s][] = $lead;
}

// Open lead detail
$openLead = null;
$openActivities = [];
if ($openLeadId) {
    $ost = $pdo->prepare("SELECT pl.*, l.title AS biz_name, l.whatsapp AS biz_wa FROM partner_leads pl LEFT JOIN listings l ON l.id=pl.listing_id WHERE pl.id=? AND pl.partner_id=?");
    $ost->execute([$openLeadId, $partnerId]);
    $openLead = $ost->fetch() ?: null;
    if ($openLead) {
        $ast = $pdo->prepare("SELECT * FROM lead_activities WHERE lead_id=? ORDER BY created_at DESC LIMIT 30");
        $ast->execute([$openLeadId]);
        $openActivities = $ast->fetchAll();
    }
}

// WA templates for quick-send
$waTplSt = $pdo->prepare("SELECT id, name, wa_header, body, wa_footer, variables FROM communication_templates WHERE partner_id=? AND channel='whatsapp' AND status='active' ORDER BY use_count DESC LIMIT 20");
$waTplSt->execute([$partnerId]);
$waTemplates = $waTplSt->fetchAll();

$listings = fetchPartnerListings($partnerId);
$flash    = getFlash();

partnerNavBadges($partnerId, $pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lead Pipeline — 237Biz Partner</title>
<?php partnerNavAssets(); ?>
<style>
:root {
  --bg: #0a1a14;
  --card: #0f2318;
  --border: rgba(0,168,120,0.18);
  --accent: #00A878;
  --muted: #8db8a8;
  --text: #d4ede5;
  --danger: #ef4444;
  --wa: #25D366;
}
body { background: var(--bg); color: var(--text); font-family: system-ui, sans-serif; margin: 0; }
.pip-wrap { padding: 1.25rem 1rem 4rem; }
.pip-header { max-width: 1400px; margin: 0 auto 1rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
.pip-header h1 { font-size: 1.3rem; color: #fff; margin: 0; }
.pip-header-right { display: flex; gap: 0.6rem; align-items: center; }

/* Biz filter */
.biz-filter { display: flex; gap: 0.4rem; flex-wrap: wrap; max-width: 1400px; margin: 0 auto 1.25rem; }
.biz-pill { padding: 0.3rem 0.75rem; border-radius: 20px; font-size: 0.78rem; font-weight: 600;
  border: 1px solid var(--border); color: var(--muted); background: var(--card); cursor: pointer; text-decoration: none; }
.biz-pill.active, .biz-pill:hover { border-color: var(--accent); color: var(--accent); }
.biz-pill.active { background: rgba(0,168,120,0.12); }

/* Kanban board */
.kanban-board { display: flex; gap: 0.75rem; overflow-x: auto; padding-bottom: 1rem; max-width: 1400px; margin: 0 auto; }
.kanban-col { min-width: 220px; flex: 0 0 220px; display: flex; flex-direction: column; gap: 0.5rem; }
.kanban-head { padding: 0.5rem 0.75rem; border-radius: 8px 8px 0 0; display: flex; align-items: center; justify-content: space-between; }
.kanban-head-label { font-size: 0.8rem; font-weight: 800; color: #fff; display: flex; align-items: center; gap: 0.4rem; }
.kanban-count { font-size: 0.72rem; background: rgba(255,255,255,0.15); padding: 0.1rem 0.5rem; border-radius: 20px; }
.kanban-body { flex: 1; min-height: 200px; padding: 0.4rem; border-radius: 0 0 8px 8px;
  background: rgba(255,255,255,0.03); border: 1px solid var(--border); border-top: none; }

/* Lead cards */
.lead-card { background: var(--card); border: 1px solid var(--border); border-radius: 9px;
  padding: 0.75rem; margin-bottom: 0.5rem; cursor: pointer; transition: border-color 0.15s, transform 0.1s; }
.lead-card:hover { border-color: var(--accent); transform: translateY(-1px); }
.lead-card.overdue { border-left: 3px solid var(--danger); }
.lead-name { font-weight: 700; color: #fff; font-size: 0.88rem; margin-bottom: 0.2rem; }
.lead-biz  { font-size: 0.72rem; color: var(--accent); margin-bottom: 0.3rem; }
.lead-contact { font-size: 0.75rem; color: var(--muted); display: flex; gap: 0.5rem; flex-wrap: wrap; }
.lead-fup { font-size: 0.72rem; margin-top: 0.4rem; padding: 0.15rem 0.5rem; border-radius: 5px;
  display: inline-block; }
.lead-fup.overdue { background: rgba(239,68,68,0.15); color: #fca5a5; }
.lead-fup.soon    { background: rgba(245,158,11,0.15); color: #fcd34d; }
.lead-fup.ok      { background: rgba(0,168,120,0.1); color: var(--accent); }
.lead-wa { display: inline-flex; align-items: center; gap: 0.3rem; background: rgba(37,211,102,0.12);
  color: var(--wa); font-size: 0.7rem; padding: 0.15rem 0.4rem; border-radius: 5px; margin-top: 0.3rem; }

/* Detail panel */
.detail-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 100;
  display: none; align-items: flex-start; justify-content: flex-end; }
.detail-overlay.open { display: flex; }
.detail-panel { background: #0f2318; border-left: 1px solid var(--border); width: 100%; max-width: 440px;
  height: 100vh; overflow-y: auto; padding: 1.25rem; box-sizing: border-box; }
.detail-close { position: sticky; top: 0; background: #0f2318; border-bottom: 1px solid var(--border);
  margin: -1.25rem -1.25rem 1rem; padding: 0.75rem 1.25rem; display: flex; align-items: center; justify-content: space-between; z-index: 1; }
.detail-close h2 { font-size: 1rem; color: #fff; margin: 0; }
.close-btn { background: rgba(255,255,255,0.08); border: none; color: var(--muted); border-radius: 6px;
  padding: 0.3rem 0.7rem; cursor: pointer; font-size: 0.85rem; }
.close-btn:hover { color: #fff; }

/* Status mover */
.status-row { display: flex; gap: 0.4rem; flex-wrap: wrap; margin-bottom: 1rem; }
.status-btn { padding: 0.3rem 0.6rem; border-radius: 20px; font-size: 0.72rem; font-weight: 700;
  border: 1px solid rgba(255,255,255,0.1); color: var(--muted); background: rgba(255,255,255,0.04); cursor: pointer; }
.status-btn.current { border-color: var(--accent); color: var(--accent); background: rgba(0,168,120,0.12); }
.status-btn:hover:not(.current) { border-color: rgba(255,255,255,0.3); color: #fff; }

/* Activity timeline */
.timeline { margin-top: 0.75rem; }
.timeline-item { display: flex; gap: 0.75rem; padding: 0.6rem 0; border-bottom: 1px solid rgba(0,168,120,0.07); }
.timeline-icon { width: 28px; height: 28px; border-radius: 50%; background: rgba(0,168,120,0.1);
  border: 1px solid rgba(0,168,120,0.2); display: flex; align-items: center; justify-content: center;
  font-size: 0.75rem; flex-shrink: 0; }
.timeline-content { flex: 1; }
.timeline-type { font-size: 0.75rem; font-weight: 700; color: var(--accent); text-transform: uppercase; letter-spacing: 0.03em; }
.timeline-note { font-size: 0.83rem; color: var(--text); margin: 0.15rem 0; }
.timeline-time { font-size: 0.72rem; color: var(--muted); }

/* Add activity form */
.act-form { background: rgba(255,255,255,0.03); border: 1px solid var(--border); border-radius: 9px; padding: 1rem; margin-top: 1rem; }
.act-form h3 { font-size: 0.88rem; color: #fff; margin: 0 0 0.75rem; }
.form-group { margin-bottom: 0.75rem; }
.form-label { display: block; font-size: 0.78rem; color: var(--muted); margin-bottom: 0.3rem; font-weight: 600; }
.form-input, .form-select, .form-textarea {
  width: 100%; background: rgba(255,255,255,0.05); border: 1px solid var(--border);
  border-radius: 7px; color: #fff; font-size: 0.85rem; padding: 0.5rem 0.7rem; box-sizing: border-box; }
.form-textarea { resize: vertical; min-height: 60px; font-family: inherit; }
.form-input:focus, .form-select:focus, .form-textarea:focus { outline: none; border-color: var(--accent); }
.form-select option { background: #0f2318; }
.btn-primary { background: var(--accent); color: #fff; border: none; border-radius: 7px;
  padding: 0.5rem 1.1rem; font-weight: 700; cursor: pointer; font-size: 0.85rem; }
.btn-primary:hover { background: #00c490; }
.btn-wa { background: var(--wa); color: #fff; border: none; border-radius: 7px;
  padding: 0.5rem 1.1rem; font-weight: 700; cursor: pointer; font-size: 0.85rem;
  text-decoration: none; display: inline-block; }
.btn-wa:hover { background: #1db954; }

/* Add lead modal */
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 200; display: none; align-items: center; justify-content: center; }
.modal-overlay.open { display: flex; }
.modal-box { background: #0f2318; border: 1px solid var(--border); border-radius: 12px; padding: 1.5rem; width: 100%; max-width: 440px; max-height: 90vh; overflow-y: auto; }
.modal-title { font-size: 1rem; font-weight: 700; color: #fff; margin: 0 0 1rem; }
.modal-close { float: right; background: none; border: none; color: var(--muted); cursor: pointer; font-size: 1.1rem; margin-top: -0.2rem; }

.flash { padding: 0.65rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.85rem; font-weight: 600; max-width: 1400px; margin-left: auto; margin-right: auto; }
.flash-success { background: rgba(0,168,120,0.15); border: 1px solid rgba(0,168,120,0.3); color: #6ee7c8; }
.flash-error   { background: rgba(239,68,68,0.12); border: 1px solid rgba(239,68,68,0.3); color: #fca5a5; }

.btn-secondary { background: rgba(255,255,255,0.08); border: 1px solid var(--border); color: var(--muted);
  border-radius: 7px; padding: 0.5rem 1rem; font-weight: 600; cursor: pointer; font-size: 0.85rem; }

/* WA template picker inside detail */
.wa-quick { margin-top: 0.5rem; }
.wa-quick select { width: 100%; margin-bottom: 0.5rem; }
</style>
</head>
<body>
<?php partnerNav('pipeline'); ?>

<!-- Add Lead Modal -->
<div class="modal-overlay" id="add-lead-modal">
  <div class="modal-box">
    <div class="modal-title">➕ Add New Lead <button class="modal-close" onclick="closeAddLead()">✕</button></div>
    <form method="POST">
      <?= gpCsrfField() ?>
      <input type="hidden" name="action" value="add_lead">
      <div class="form-group">
        <label class="form-label">Client Name *</label>
        <input type="text" class="form-input" name="customer_name" required placeholder="e.g. Marie Nkeng">
      </div>
      <div class="form-group">
        <label class="form-label">Phone</label>
        <input type="tel" class="form-input" name="customer_phone" placeholder="+237 6xx xxx xxx">
      </div>
      <div class="form-group">
        <label class="form-label">Email</label>
        <input type="email" class="form-input" name="customer_email" placeholder="client@email.com">
      </div>
      <div class="form-group">
        <label class="form-label">Business</label>
        <select class="form-select" name="listing_id">
          <option value="">— Not assigned —</option>
          <?php foreach ($listings as $biz): ?>
          <option value="<?= $biz['id'] ?>" <?= ($filterListing === $biz['id']) ? 'selected' : '' ?>><?= htmlspecialchars($biz['title']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Notes</label>
        <textarea class="form-textarea" name="notes" rows="2" placeholder="How did you meet them? What do they need?"></textarea>
      </div>
      <div style="display:flex; gap:0.5rem;">
        <button type="submit" class="btn-primary">Add Lead</button>
        <button type="button" class="btn-secondary" onclick="closeAddLead()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Lead Detail Panel -->
<div class="detail-overlay <?= $openLead ? 'open' : '' ?>" id="detail-overlay" onclick="closeDetail(event)">
  <div class="detail-panel" id="detail-panel">
    <?php if ($openLead):
      $wa = preg_replace('/\D/', '', $openLead['biz_wa'] ?? '');
      if ($wa && strlen($wa) >= 8 && $wa[0] !== '2') $wa = '237' . $wa;
      $clientPhone = preg_replace('/\D/', '', $openLead['customer_phone'] ?? '');
      if ($clientPhone && strlen($clientPhone) >= 8 && $clientPhone[0] !== '2') $clientPhone = '237' . $clientPhone;
      $today = date('Y-m-d');
      $fup   = $openLead['follow_up_date'];
      $fupClass = $fup ? ($fup < $today ? 'overdue' : ($fup <= date('Y-m-d', strtotime('+3 days')) ? 'soon' : 'ok')) : '';
    ?>
    <div class="detail-close">
      <h2><?= htmlspecialchars($openLead['customer_name']) ?></h2>
      <button class="close-btn" onclick="closeDetail()">✕ Close</button>
    </div>

    <!-- Contact info -->
    <div style="margin-bottom:1rem;">
      <?php if ($openLead['biz_name']): ?><div style="font-size:0.8rem; color:var(--accent); margin-bottom:0.3rem;">📋 <?= htmlspecialchars($openLead['biz_name']) ?></div><?php endif; ?>
      <?php if ($openLead['customer_phone']): ?><div style="font-size:0.83rem; color:var(--muted); margin-bottom:0.2rem;">📞 <?= htmlspecialchars($openLead['customer_phone']) ?></div><?php endif; ?>
      <?php if ($openLead['customer_email']): ?><div style="font-size:0.83rem; color:var(--muted); margin-bottom:0.2rem;">✉️ <?= htmlspecialchars($openLead['customer_email']) ?></div><?php endif; ?>
      <?php if ($fup): ?>
      <div style="margin-top:0.5rem;">
        <span class="lead-fup <?= $fupClass ?>">📅 Follow-up: <?= date('d M Y', strtotime($fup)) ?><?= $fupClass === 'overdue' ? ' — OVERDUE' : '' ?></span>
      </div>
      <?php endif; ?>
    </div>

    <!-- WhatsApp quick-send -->
    <?php if ($clientPhone): ?>
    <div style="margin-bottom:1rem;">
      <div style="font-size:0.8rem; color:var(--muted); margin-bottom:0.4rem; font-weight:600;">💬 WhatsApp Client</div>
      <?php if (!empty($waTemplates)): ?>
      <div class="wa-quick">
        <select class="form-select" id="detail-wa-tpl" onchange="previewDetailTpl(this.value)" style="margin-bottom:0.4rem;">
          <option value="">— Custom message —</option>
          <?php foreach ($waTemplates as $t): ?>
          <option value="<?= $t['id'] ?>"
            data-body="<?= htmlspecialchars($t['body'], ENT_QUOTES) ?>"
            data-header="<?= htmlspecialchars($t['wa_header'] ?? '', ENT_QUOTES) ?>">
            <?= htmlspecialchars($t['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <textarea class="form-textarea" id="detail-wa-msg" rows="3" placeholder="Type your WhatsApp message..."><?=
        "Hello " . htmlspecialchars($openLead['customer_name']) . ", I wanted to follow up regarding your enquiry."
      ?></textarea>
      <div style="margin-top:0.5rem;">
        <a href="#" class="btn-wa" id="detail-wa-btn" target="_blank" onclick="openWa(this, '<?= $clientPhone ?>')">
          💬 Send WhatsApp
        </a>
      </div>
    </div>
    <?php endif; ?>

    <!-- Move status -->
    <div style="margin-bottom:1rem;">
      <div style="font-size:0.8rem; color:var(--muted); font-weight:600; margin-bottom:0.5rem;">📊 Move to Stage</div>
      <div class="status-row">
        <?php foreach ($stages as $sk => $sv): ?>
        <button class="status-btn <?= $openLead['status'] === $sk ? 'current' : '' ?>"
          onclick="moveLead(<?= $openLead['id'] ?>, '<?= $sk ?>')"
          style="<?= $openLead['status'] === $sk ? 'border-color:' . $sv['color'] . ';color:' . $sv['color'] . ';background:' . $sv['color'] . '20' : '' ?>">
          <?= $sv['icon'] . ' ' . $sv['label'] ?>
        </button>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Add activity -->
    <div class="act-form">
      <h3>📝 Log Activity</h3>
      <form method="POST">
        <?= gpCsrfField() ?>
        <input type="hidden" name="action" value="add_activity">
        <input type="hidden" name="lead_id" value="<?= $openLead['id'] ?>">
        <div class="form-group">
          <label class="form-label">Activity Type</label>
          <select class="form-select" name="activity_type">
            <option value="note">📝 Note</option>
            <option value="phone_call">📞 Phone Call</option>
            <option value="message_sent">💬 Message Sent</option>
            <option value="email_sent">✉️ Email Sent</option>
            <option value="follow_up">🔁 Follow-up</option>
            <option value="appointment">📅 Appointment</option>
            <option value="proposal_sent">📄 Proposal Sent</option>
            <option value="negotiation">🤝 Negotiation</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Notes *</label>
          <textarea class="form-textarea" name="notes" required rows="2" placeholder="What happened? What was discussed?"></textarea>
        </div>
        <div class="form-group">
          <label class="form-label">Update Stage (optional)</label>
          <select class="form-select" name="new_status">
            <option value="">— Keep current stage —</option>
            <?php foreach ($stages as $sk => $sv): ?>
            <option value="<?= $sk ?>" <?= $openLead['status'] === $sk ? 'selected' : '' ?>><?= $sv['icon'] . ' ' . $sv['label'] ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Follow-up Date</label>
          <input type="date" class="form-input" name="follow_up_date" min="<?= $today ?>" value="<?= htmlspecialchars($fup ?? '') ?>">
        </div>
        <button type="submit" class="btn-primary">Log Activity</button>
      </form>
    </div>

    <!-- Activity timeline -->
    <?php if (!empty($openActivities)): ?>
    <div style="margin-top:1.25rem;">
      <div style="font-size:0.8rem; color:var(--muted); font-weight:600; margin-bottom:0.5rem;">🕐 Activity Timeline</div>
      <div class="timeline">
        <?php $actIcons = ['note'=>'📝','phone_call'=>'📞','message_sent'=>'💬','email_sent'=>'✉️','follow_up'=>'🔁','appointment'=>'📅','contacted'=>'🤙','converted'=>'🏆','lost'=>'❌','proposal_sent'=>'📄','negotiation'=>'🤝','booking'=>'📗'];
        foreach ($openActivities as $act): ?>
        <div class="timeline-item">
          <div class="timeline-icon"><?= $actIcons[$act['activity_type']] ?? '•' ?></div>
          <div class="timeline-content">
            <div class="timeline-type"><?= htmlspecialchars(str_replace('_', ' ', $act['activity_type'])) ?></div>
            <div class="timeline-note"><?= htmlspecialchars($act['notes'] ?? '') ?></div>
            <?php if ($act['follow_up_date']): ?>
            <div style="font-size:0.72rem; color:var(--muted);">Follow-up: <?= date('d M Y', strtotime($act['follow_up_date'])) ?></div>
            <?php endif; ?>
            <div class="timeline-time"><?= date('d M Y H:i', strtotime($act['created_at'])) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<div class="pip-wrap">
  <?php if ($flash): ?>
  <div class="flash flash-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['message']) ?></div>
  <?php endif; ?>

  <div class="pip-header">
    <h1>📊 Lead Pipeline</h1>
    <div class="pip-header-right">
      <span style="font-size:0.82rem; color:var(--muted);"><?= count($allLeads) ?> active lead<?= count($allLeads) !== 1 ? 's' : '' ?></span>
      <button class="btn-primary" onclick="openAddLead()">➕ Add Lead</button>
    </div>
  </div>

  <!-- Business filter -->
  <div class="biz-filter">
    <a href="?<?= $filterListing ? '' : 'biz=0' ?>" class="biz-pill <?= !$filterListing ? 'active' : '' ?>">All Businesses</a>
    <?php foreach ($listings as $biz): ?>
    <a href="?biz=<?= $biz['id'] ?>" class="biz-pill <?= $filterListing === $biz['id'] ? 'active' : '' ?>">
      <?= htmlspecialchars($biz['title']) ?>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Kanban board -->
  <div class="kanban-board">
    <?php foreach ($stages as $sk => $sv):
      $cols = $buckets[$sk] ?? [];
      $today = date('Y-m-d');
    ?>
    <div class="kanban-col">
      <div class="kanban-head" style="background: <?= $sv['color'] ?>22; border: 1px solid <?= $sv['color'] ?>44;">
        <div class="kanban-head-label"><?= $sv['icon'] ?> <?= $sv['label'] ?></div>
        <span class="kanban-count"><?= count($cols) ?></span>
      </div>
      <div class="kanban-body" id="col-<?= $sk ?>">
        <?php if (empty($cols)): ?>
        <div style="text-align:center; padding:1.5rem 0.5rem; font-size:0.75rem; color:var(--muted); opacity:0.5;">Empty</div>
        <?php else: foreach ($cols as $lead):
          $lwa = preg_replace('/\D/', '', $lead['biz_wa'] ?? '');
          if ($lwa && strlen($lwa) >= 8 && $lwa[0] !== '2') $lwa = '237' . $lwa;
          $lfup = $lead['follow_up_date'];
          $isOverdue = $lfup && $lfup < $today;
        ?>
        <div class="lead-card <?= $isOverdue ? 'overdue' : '' ?>" onclick="openLead(<?= $lead['id'] ?>)">
          <div class="lead-name"><?= htmlspecialchars($lead['customer_name']) ?></div>
          <?php if ($lead['biz_name']): ?>
          <div class="lead-biz">📋 <?= htmlspecialchars($lead['biz_name']) ?></div>
          <?php endif; ?>
          <div class="lead-contact">
            <?php if ($lead['customer_phone']): ?><span>📞 <?= htmlspecialchars($lead['customer_phone']) ?></span><?php endif; ?>
          </div>
          <?php if ($lfup): ?>
          <div class="lead-fup <?= $isOverdue ? 'overdue' : (($lfup <= date('Y-m-d', strtotime('+3 days'))) ? 'soon' : 'ok') ?>">
            📅 <?= date('d M', strtotime($lfup)) ?><?= $isOverdue ? ' ⚠️' : '' ?>
          </div>
          <?php endif; ?>
          <?php if ($lwa): ?>
          <div class="lead-wa">💬 WA</div>
          <?php endif; ?>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<script>
// -- Modals ----------------------------------------------------
function openAddLead() { document.getElementById('add-lead-modal').classList.add('open'); }
function closeAddLead() { document.getElementById('add-lead-modal').classList.remove('open'); }

function openLead(id) {
  window.location.href = '<?= SITE_URL ?>/partner/pipeline?open=' + id + '<?= $filterListing ? "&biz={$filterListing}" : "" ?>';
}

function closeDetail(e) {
  if (!e || e.target === document.getElementById('detail-overlay')) {
    history.pushState({}, '', '<?= SITE_URL ?>/partner/pipeline<?= $filterListing ? "?biz={$filterListing}" : "" ?>');
    document.getElementById('detail-overlay').classList.remove('open');
  }
}

// -- Move lead via AJAX ----------------------------------------
function moveLead(id, status) {
  const data = new FormData();
  data.append('action', 'move_lead');
  data.append('lead_id', id);
  data.append('new_status', status);
  data.append('<?= gpCsrfToken() ?>', '<?= gpCsrfValue() ?>');

  fetch(window.location.pathname, { method: 'POST', body: data })
    .then(r => r.json())
    .then(res => {
      if (res.ok) {
        // Update button styles
        document.querySelectorAll('.status-btn').forEach(b => {
          b.classList.remove('current');
          b.style.borderColor = ''; b.style.color = ''; b.style.background = '';
        });
        event.target.classList.add('current');
        // Brief confirmation
        const btn = event.target;
        const orig = btn.textContent;
        btn.textContent = 'Moved!';
        setTimeout(() => { btn.textContent = orig; }, 1500);
      }
    }).catch(() => {});
}

// -- WA template preview ----------------------------------------
function previewDetailTpl(id) {
  if (!id) return;
  const opt = document.querySelector('#detail-wa-tpl option[value="' + id + '"]');
  if (!opt) return;
  const header = opt.dataset.header ? '*' + opt.dataset.header + '*\n\n' : '';
  const body   = opt.dataset.body || '';
  const name   = '<?= addslashes(htmlspecialchars($openLead['customer_name'] ?? '')) ?>';
  const filled = (header + body)
    .replace(/\{name\}/g, name)
    .replace(/\{business\}/g, '<?= addslashes(htmlspecialchars($openLead['biz_name'] ?? '')) ?>')
    .replace(/\{date\}/g, new Date().toLocaleDateString('en-GB'));
  document.getElementById('detail-wa-msg').value = filled;
}

function openWa(el, phone) {
  const msg = document.getElementById('detail-wa-msg')?.value || '';
  el.href = 'https://wa.me/' + phone + '?text=' + encodeURIComponent(msg);
}

// Add lead modal: close on overlay click
document.getElementById('add-lead-modal').addEventListener('click', function(e) {
  if (e.target === this) closeAddLead();
});
</script>
</body>
</html>
