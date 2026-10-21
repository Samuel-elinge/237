<?php
/**
 * my-enquiries.php — 237Biz
 * Business-owner enquiry inbox with CRM pipeline status management.
 * URL: /my-enquiries or /my-enquiries?listing_id=X&status=new
 * Phase 4 Sprint 2
 */
require_once __DIR__ . '/includes/config.php';
requireLogin();

$u = currentUser();

// ── Load this user's approved listings ───────────────────────────────────
if (isAdmin()) {
    // Admins always see all approved listings
    $myListings = db()->query("SELECT id,title,slug FROM listings WHERE status='approved' ORDER BY title ASC")->fetchAll();
} else {
    $myListings = db()->prepare("SELECT id, title, slug FROM listings WHERE user_id=? AND status='approved' ORDER BY title ASC");
    $myListings->execute([$u['id']]);
    $myListings = $myListings->fetchAll();
}

if (empty($myListings)) {
    $pageTitle = t('My Enquiries','Mes demandes') . ' — 237Biz';
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="page-header"><div class="container"><h1 style="font-family:\'Fraunces\',serif;font-weight:900;font-size:2rem;">📬 '.t('My Enquiries','Mes demandes').'</h1></div></div>';
    echo '<section class="page-section"><div class="container" style="text-align:center;padding:60px 20px;color:var(--muted);">';
    echo '<p style="font-size:15px;color:var(--white);font-weight:600;">'.t('You have no approved listings yet.','Vous n\'avez pas encore d\'annonce approuvée.').'</p>';
    echo '<a href="'.SITE_URL.'/add-listing" class="btn btn-primary" style="margin-top:14px;">+ '.t('Add your first listing','Ajouter votre première annonce').'</a>';
    echo '</div></section>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$listingIds = array_column($myListings, 'id');

// ── Selected listing (or all if no filter) ───────────────────────────────
$filterLid  = (int)($_GET['listing_id'] ?? 0);
if ($filterLid && !in_array($filterLid, $listingIds) && !isAdmin()) {
    $filterLid = 0; // reset if user doesn't own it
}

// ── Status filter ─────────────────────────────────────────────────────────
$validStatuses = ['all','new','contacted','quoted','won','lost'];
$filterStatus  = $_GET['status'] ?? 'all';
if (!in_array($filterStatus, $validStatuses)) $filterStatus = 'all';

// ── Handle POST: update CRM status ───────────────────────────────────────
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    $eid    = (int)($_POST['enquiry_id'] ?? 0);

    if ($action === 'update_status' && $eid) {
        $newStatus  = $_POST['new_status'] ?? '';
        $note       = trim($_POST['note'] ?? '');
        $followUpAt = trim($_POST['follow_up_at'] ?? '');
        $lostReason = trim($_POST['lost_reason'] ?? '');

        $allowed = ['new','contacted','quoted','won','lost'];
        if (in_array($newStatus, $allowed)) {
            // Verify this enquiry belongs to the user
            $check = db()->prepare("SELECT e.id, e.crm_status, e.listing_id FROM listing_enquiries e JOIN listings l ON l.id=e.listing_id WHERE e.id=? AND (l.user_id=? OR ?)");
            $check->execute([$eid, $u['id'], isAdmin() ? 1 : 0]);
            $enq = $check->fetch();

            if ($enq) {
                $oldStatus = $enq['crm_status'] ?? 'new';
                $sets  = ['crm_status = ?'];
                $parms = [$newStatus];

                if ($newStatus === 'won' && $oldStatus !== 'won') {
                    $sets[] = 'won_at = NOW()';
                }
                if ($newStatus === 'lost' && $oldStatus !== 'lost') {
                    $sets[] = 'lost_at = NOW()';
                    if ($lostReason) { $sets[] = 'lost_reason = ?'; $parms[] = $lostReason; }
                }
                if ($followUpAt) { $sets[] = 'follow_up_at = ?'; $parms[] = date('Y-m-d H:i:s', strtotime($followUpAt)); }
                if (!$enq['crm_status'] || $enq['crm_status'] === 'new') {
                    $sets[] = 'first_response_at = COALESCE(first_response_at, NOW())';
                }

                $parms[] = $eid;
                try {
                    db()->prepare("UPDATE listing_enquiries SET ".implode(', ',$sets)." WHERE id=?")->execute($parms);

                    // Log the status change
                    try {
                        db()->prepare("INSERT INTO enquiry_status_log (enquiry_id, old_status, new_status, changed_by, note) VALUES (?,?,?,?,?)")
                            ->execute([$eid, $oldStatus, $newStatus, $u['id'], $note ?: null]);
                    } catch (Exception $e) {}

                    // Log in phase4_audit_log
                    try {
                        db()->prepare("INSERT INTO phase4_audit_log (actor_id, target_type, target_id, action, detail, ip) VALUES (?,?,?,?,?,?)")
                            ->execute([$u['id'], 'enquiry', $eid, 'status_change', json_encode(['old'=>$oldStatus,'new'=>$newStatus]), $_SERVER['REMOTE_ADDR'] ?? '']);
                    } catch (Exception $e) {}

                    flash('success', t('Status updated.','Statut mis à jour.'));
                } catch (Exception $e) {
                    flash('error', t('Update failed — migration may be pending.','Échec — migration peut être en attente.'));
                }
            }
        }
        redirect(SITE_URL . '/my-enquiries?' . http_build_query(['listing_id'=>$filterLid,'status'=>$filterStatus]));
    }

    if ($action === 'update_notes' && $eid) {
        $notes = trim($_POST['notes'] ?? '');
        $check = db()->prepare("SELECT e.id FROM listing_enquiries e JOIN listings l ON l.id=e.listing_id WHERE e.id=? AND (l.user_id=? OR ?)");
        $check->execute([$eid, $u['id'], isAdmin() ? 1 : 0]);
        if ($check->fetch()) {
            try {
                db()->prepare("UPDATE listing_enquiries SET notes=? WHERE id=?")->execute([$notes, $eid]);
                flash('success', t('Notes saved.','Notes sauvegardées.'));
            } catch (Exception $e) {}
        }
        redirect(SITE_URL . '/my-enquiries?' . http_build_query(['listing_id'=>$filterLid,'status'=>$filterStatus]));
    }
}

// ── Fetch enquiries ───────────────────────────────────────────────────────
$inClause = implode(',', array_fill(0, count($listingIds), '?'));
$params   = $listingIds;

$whereParts = ["e.listing_id IN ($inClause)"];
if ($filterLid) { $whereParts[] = "e.listing_id = ?"; $params[] = $filterLid; }
if ($filterStatus !== 'all') {
    // Graceful: try crm_status first, fall back to old status column
    $whereParts[] = "(e.crm_status = ? OR (e.crm_status IS NULL AND e.status = ?))";
    $statusMap = ['new'=>'unread','contacted'=>'read'];
    $params[] = $filterStatus;
    $params[] = $statusMap[$filterStatus] ?? $filterStatus;
}

$whereSQL = implode(' AND ', $whereParts);

try {
    $eq = db()->prepare("
        SELECT e.*,
               l.title AS listing_title, l.slug AS listing_slug,
               COALESCE(e.crm_status, IF(e.status='unread','new','contacted')) AS eff_status
        FROM listing_enquiries e
        JOIN listings l ON l.id = e.listing_id
        WHERE $whereSQL
        ORDER BY e.created_at DESC
        LIMIT 150
    ");
    $eq->execute($params);
    $enquiries = $eq->fetchAll();
} catch (Exception $e) {
    // Fallback if crm_status column not yet added
    try {
        $eq2 = db()->prepare("
            SELECT e.*, l.title AS listing_title, l.slug AS listing_slug,
                   IF(e.status='unread','new','contacted') AS eff_status
            FROM listing_enquiries e
            JOIN listings l ON l.id = e.listing_id
            WHERE e.listing_id IN ($inClause)" . ($filterLid ? " AND e.listing_id = $filterLid" : "") . "
            ORDER BY e.created_at DESC LIMIT 150
        ");
        $eq2->execute($listingIds);
        $enquiries = $eq2->fetchAll();
    } catch (Exception $e2) {
        $enquiries = [];
    }
}

// ── Status counts for tab badges ─────────────────────────────────────────
$statusCounts = ['all'=>count($enquiries),'new'=>0,'contacted'=>0,'quoted'=>0,'won'=>0,'lost'=>0];
foreach ($enquiries as $enq) {
    $s = $enq['eff_status'] ?? 'new';
    if (isset($statusCounts[$s])) $statusCounts[$s]++;
}

// ── Page ──────────────────────────────────────────────────────────────────
$pageTitle = t('My Enquiries','Mes demandes') . ' — 237Biz';
require_once __DIR__ . '/includes/header.php';
?>

<style>
.enq-wrap  { max-width:960px;margin:0 auto;padding:20px 16px 60px; }
.enq-tabs  { display:flex;gap:6px;flex-wrap:wrap;margin-bottom:18px; }
.enq-tab   { padding:7px 14px;border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.04);color:var(--muted);border-radius:20px;text-decoration:none;font-size:12.5px;font-weight:600;transition:all .15s; }
.enq-tab.active,.enq-tab:hover { background:rgba(0,168,120,0.18);border-color:#00A878;color:#00A878; }
.enq-card  { background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.07);border-radius:12px;padding:18px 20px;margin-bottom:12px;transition:border-color .15s; }
.enq-card.status-new      { border-left:3px solid #56b4d3; }
.enq-card.status-contacted{ border-left:3px solid #00A878; }
.enq-card.status-quoted   { border-left:3px solid #f4a261; }
.enq-card.status-won      { border-left:3px solid #00A878;background:rgba(0,168,120,0.04); }
.enq-card.status-lost     { border-left:3px solid #e63946;opacity:.75; }
.enq-meta  { font-size:11.5px;color:var(--muted); }
.enq-badge { display:inline-block;padding:2px 9px;border-radius:99px;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.04em; }
.badge-new       { background:rgba(86,180,211,0.2);color:#56b4d3; }
.badge-contacted { background:rgba(0,168,120,0.18);color:#00A878; }
.badge-quoted    { background:rgba(244,162,97,0.2);color:#f4a261; }
.badge-won       { background:rgba(0,168,120,0.25);color:#00de9e; }
.badge-lost      { background:rgba(230,57,70,0.15);color:#e63946; }
.enq-body  { font-size:13.5px;color:rgba(255,255,255,0.8);line-height:1.6;margin:10px 0; }
.enq-details { display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:12px; }
@media(max-width:600px){ .enq-details{grid-template-columns:1fr;} }
.enq-action-row { display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:12px;padding-top:10px;border-top:1px solid rgba(255,255,255,0.06); }
.enq-sel   { padding:6px 10px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.12);border-radius:7px;color:var(--white);font-family:inherit;font-size:12px; }
.enq-notes { width:100%;padding:8px 10px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.1);border-radius:7px;color:var(--white);font-size:12.5px;font-family:inherit;resize:vertical;min-height:60px; }
.enq-expand-toggle { font-size:11.5px;color:var(--green);cursor:pointer;background:none;border:none;padding:0;font-family:inherit; }
.enq-expanded { display:none; }
.enq-expanded.open { display:block; }
.sel-listing { padding:8px 14px;background:rgba(255,255,255,0.05);border:1px solid var(--border);border-radius:8px;color:var(--white);font-family:inherit;font-size:13px; }
</style>

<div class="page-header">
  <div class="container">
    <nav class="breadcrumb">
      <a href="<?= SITE_URL ?>/"><?= t('Home','Accueil') ?></a> ›
      <a href="<?= SITE_URL ?>/dashboard"><?= t('Dashboard','Tableau de bord') ?></a> ›
      <span><?= t('My Enquiries','Mes demandes') ?></span>
    </nav>
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.5rem,3vw,2rem);">
      📬 <?= t('My Enquiries','Mes demandes') ?>
      <?php if ($statusCounts['new'] > 0): ?>
        <span class="enq-badge badge-new" style="font-size:0.7rem;vertical-align:middle;margin-left:8px;"><?= $statusCounts['new'] ?> <?= t('new','nouveau') ?></span>
      <?php endif; ?>
    </h1>
  </div>
</div>

<section class="page-section" style="padding-top:1.25rem;">
<div class="enq-wrap">

<?= flash() ?>

<!-- Listing selector + status tabs -->
<div style="display:flex;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:16px;">
  <?php if (count($myListings) > 1): ?>
  <form method="get" style="display:flex;align-items:center;gap:8px;">
    <select name="listing_id" class="sel-listing" onchange="this.form.submit()">
      <option value="0" <?= !$filterLid?'selected':'' ?>><?= t('All listings','Toutes les annonces') ?></option>
      <?php foreach ($myListings as $ml): ?>
      <option value="<?= $ml['id'] ?>" <?= $ml['id']===$filterLid?'selected':'' ?>><?= e($ml['title']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($filterStatus !== 'all'): ?><input type="hidden" name="status" value="<?= e($filterStatus) ?>"><?php endif; ?>
  </form>
  <?php endif; ?>

  <a href="<?= SITE_URL ?>/analytics<?= $filterLid?"?listing_id=$filterLid":'' ?>" style="font-size:12px;color:var(--green);">📊 <?= t('View Analytics →','Voir les analyses →') ?></a>
</div>

<?php
$tabLabels = [
    'all'       => t('All','Tout'),
    'new'       => t('New','Nouveau'),
    'contacted' => t('Contacted','Contacté'),
    'quoted'    => t('Quoted','Devis'),
    'won'       => t('Won','Gagné'),
    'lost'      => t('Lost','Perdu'),
];
?>
<div class="enq-tabs">
  <?php foreach ($tabLabels as $s => $lbl): ?>
  <a href="?<?= http_build_query(['listing_id'=>$filterLid,'status'=>$s]) ?>"
     class="enq-tab <?= $filterStatus===$s?'active':'' ?>">
    <?= $lbl ?>
    <?php if ($statusCounts[$s] > 0): ?><span style="margin-left:4px;opacity:.7;">(<?= $statusCounts[$s] ?>)</span><?php endif; ?>
  </a>
  <?php endforeach; ?>
</div>

<?php if (empty($enquiries)): ?>
<div style="text-align:center;padding:60px 0;color:var(--muted);">
  <div style="font-size:2.5rem;margin-bottom:12px;">📬</div>
  <p style="font-size:14px;"><?= t('No enquiries found.','Aucune demande trouvée.') ?></p>
</div>
<?php else: ?>

<?php foreach ($enquiries as $enq):
  $eid     = (int)$enq['id'];
  $eStatus = $enq['eff_status'] ?? 'new';
  $statusLabels = [
    'new'       => ['label'=>t('New','Nouveau'),       'class'=>'badge-new'],
    'contacted' => ['label'=>t('Contacted','Contacté'),'class'=>'badge-contacted'],
    'quoted'    => ['label'=>t('Quoted','Devis'),      'class'=>'badge-quoted'],
    'won'       => ['label'=>t('Won','Gagné'),         'class'=>'badge-won'],
    'lost'      => ['label'=>t('Lost','Perdu'),        'class'=>'badge-lost'],
  ];
  $sl = $statusLabels[$eStatus] ?? $statusLabels['new'];
  $expandId = 'exp-'.$eid;
?>
<div class="enq-card status-<?= $eStatus ?>">

  <!-- Header row -->
  <div style="display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap;">
    <div style="flex:1;min-width:0;">
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px;">
        <span class="enq-badge <?= $sl['class'] ?>"><?= $sl['label'] ?></span>
        <span style="font-size:13px;font-weight:700;color:rgba(255,255,255,0.9);"><?= e($enq['sender_name'] ?? t('Anonymous','Anonyme')) ?></span>
        <?php if (!empty($enq['ref'])): ?>
        <span style="font-size:11px;color:var(--muted);"><?= e($enq['ref']) ?></span>
        <?php endif; ?>
      </div>
      <div class="enq-meta">
        📋 <?= e($enq['listing_title']) ?>
        · <?= date('d M Y H:i', strtotime($enq['created_at'])) ?>
        <?php if (!empty($enq['sender_email'])): ?> · <a href="mailto:<?= e($enq['sender_email']) ?>" style="color:var(--green);"><?= e($enq['sender_email']) ?></a><?php endif; ?>
        <?php if (!empty($enq['sender_phone'])): ?> · <a href="tel:<?= e($enq['sender_phone']) ?>" style="color:var(--green);"><?= e($enq['sender_phone']) ?></a><?php endif; ?>
      </div>
    </div>
    <button class="enq-expand-toggle" onclick="toggleEnq('<?= $expandId ?>')">
      <?= t('Details ▾','Détails ▾') ?>
    </button>
  </div>

  <!-- Subject / message preview -->
  <?php if (!empty($enq['subject'])): ?>
  <div style="font-size:13px;font-weight:600;color:rgba(255,255,255,0.7);margin-top:8px;"><?= e($enq['subject']) ?></div>
  <?php endif; ?>
  <div class="enq-body">
    <?php
    $msg = $enq['message'] ?? '';
    $short = mb_strlen($msg) > 200;
    echo e(mb_substr($msg, 0, $short ? 200 : 9999));
    if ($short) echo '…';
    ?>
  </div>

  <!-- Expandable details -->
  <div class="enq-expanded" id="<?= $expandId ?>">
    <?php if ($short): ?>
    <div class="enq-body" style="margin-top:-6px;"><?= nl2br(e($msg)) ?></div>
    <?php endif; ?>

    <div class="enq-details" style="margin-bottom:12px;">
      <?php if (!empty($enq['follow_up_at'])): ?>
      <div>
        <div style="font-size:11px;color:var(--muted);margin-bottom:3px;"><?= t('Follow-up due','Relance prévue') ?></div>
        <div style="font-size:13px;color:<?= strtotime($enq['follow_up_at'])<time()?'#e63946':'var(--yellow)' ?>;"><?= date('d M Y H:i', strtotime($enq['follow_up_at'])) ?></div>
      </div>
      <?php endif; ?>
      <?php if (!empty($enq['source_type']) && $enq['source_type'] !== 'listing'): ?>
      <div>
        <div style="font-size:11px;color:var(--muted);margin-bottom:3px;"><?= t('Source','Source') ?></div>
        <div style="font-size:13px;color:rgba(255,255,255,0.7);"><?= e(ucfirst($enq['source_type'])) ?> #<?= $enq['source_id'] ?></div>
      </div>
      <?php endif; ?>
      <?php if (!empty($enq['contact_method'])): ?>
      <div>
        <div style="font-size:11px;color:var(--muted);margin-bottom:3px;"><?= t('Preferred contact','Contact préféré') ?></div>
        <div style="font-size:13px;color:rgba(255,255,255,0.7);"><?= e(ucfirst($enq['contact_method'])) ?></div>
      </div>
      <?php endif; ?>
      <?php if (!empty($enq['won_at'])): ?>
      <div>
        <div style="font-size:11px;color:var(--muted);margin-bottom:3px;"><?= t('Won on','Gagné le') ?></div>
        <div style="font-size:13px;color:#00de9e;"><?= date('d M Y', strtotime($enq['won_at'])) ?></div>
      </div>
      <?php endif; ?>
      <?php if (!empty($enq['lost_reason'])): ?>
      <div>
        <div style="font-size:11px;color:var(--muted);margin-bottom:3px;"><?= t('Lost reason','Raison de la perte') ?></div>
        <div style="font-size:13px;color:#e63946;"><?= e($enq['lost_reason']) ?></div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Notes -->
    <form method="post" style="margin-bottom:12px;">
      <?= csrf() ?>
      <input type="hidden" name="action" value="update_notes">
      <input type="hidden" name="enquiry_id" value="<?= $eid ?>">
      <textarea class="enq-notes" name="notes" placeholder="<?= t('Private notes (not visible to sender)...','Notes privées (invisibles à l\'expéditeur)...') ?>"><?= e($enq['notes'] ?? '') ?></textarea>
      <button type="submit" class="btn btn-outline btn-sm" style="margin-top:6px;font-size:12px;"><?= t('Save notes','Enregistrer les notes') ?></button>
    </form>

    <!-- Status change form -->
    <form method="post" class="enq-action-row">
      <?= csrf() ?>
      <input type="hidden" name="action" value="update_status">
      <input type="hidden" name="enquiry_id" value="<?= $eid ?>">
      <select name="new_status" class="enq-sel" id="sel-<?= $eid ?>" onchange="toggleLostReason(this, '<?= $eid ?>')">
        <?php foreach (['new','contacted','quoted','won','lost'] as $s): ?>
        <option value="<?= $s ?>" <?= $eStatus===$s?'selected':'' ?>><?= ($statusLabels[$s]['label'] ?? $s) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="follow_up_at" class="enq-sel" placeholder="<?= t('Follow-up date (YYYY-MM-DD)','Date relance (AAAA-MM-JJ)') ?>"
             value="<?= !empty($enq['follow_up_at']) ? date('Y-m-d', strtotime($enq['follow_up_at'])) : '' ?>"
             style="min-width:160px;">
      <input type="text" name="note" class="enq-sel" placeholder="<?= t('Optional note...','Note optionnelle...') ?>" style="flex:1;min-width:120px;">
      <div id="lost-<?= $eid ?>" style="display:<?= $eStatus==='lost'?'block':'none' ?>;width:100%;">
        <input type="text" name="lost_reason" class="enq-sel" style="width:100%;margin-top:4px;" placeholder="<?= t('Reason for loss (optional)','Raison de la perte (optionnel)') ?>" value="<?= e($enq['lost_reason'] ?? '') ?>">
      </div>
      <button type="submit" class="btn btn-primary btn-sm" style="font-size:12px;"><?= t('Update','Mettre à jour') ?></button>
    </form>

    <!-- Contact action buttons -->
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;">
      <?php if (!empty($enq['sender_email'])): ?>
      <a href="mailto:<?= e($enq['sender_email']) ?>?subject=Re:+<?= urlencode(t('Your enquiry to','Votre demande à').' '.($enq['listing_title']??'')) ?>"
         class="btn btn-outline btn-sm" style="font-size:11.5px;">✉️ <?= t('Reply by email','Répondre par email') ?></a>
      <?php endif; ?>
      <?php if (!empty($enq['sender_phone'])): ?>
      <a href="tel:<?= e($enq['sender_phone']) ?>" class="btn btn-outline btn-sm" style="font-size:11.5px;">📞 <?= t('Call','Appeler') ?></a>
      <a href="https://wa.me/<?= e(preg_replace('/\D/','',$enq['sender_phone'])) ?>" target="_blank" class="btn btn-outline btn-sm" style="font-size:11.5px;color:#25D366;border-color:#25D366;">💬 WhatsApp</a>
      <?php endif; ?>
    </div>
  </div>

</div>
<?php endforeach; ?>
<?php endif; ?>

</div>
</section>

<script>
function toggleEnq(id) {
  var el = document.getElementById(id);
  if (!el) return;
  el.classList.toggle('open');
  var btn = el.previousElementSibling ? el.closest('.enq-card').querySelector('.enq-expand-toggle') : null;
  // Find the toggle button
  var btns = document.querySelectorAll('.enq-expand-toggle');
  btns.forEach(function(b) {
    if (b.getAttribute('onclick') === "toggleEnq('"+id+"')") {
      b.textContent = el.classList.contains('open')
        ? '<?= t('Close ▴','Fermer ▴') ?>'
        : '<?= t('Details ▾','Détails ▾') ?>';
    }
  });
}
function toggleLostReason(sel, eid) {
  var div = document.getElementById('lost-' + eid);
  if (div) div.style.display = (sel.value === 'lost') ? 'block' : 'none';
}
// Auto-open new enquiries
document.querySelectorAll('.enq-card.status-new').forEach(function(card) {
  var toggle = card.querySelector('.enq-expand-toggle');
  if (toggle) toggle.click();
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
