<?php
/**
 * manage-bookings.php — 237Biz
 * Business owner manages bookings and blocked times.
 */
require_once __DIR__ . '/includes/config.php';
requireLogin();

$u = currentUser();

// Load listings with booking enabled
$myListings = [];
try {
    if (isAdmin()) {
        $myListings = db()->query("SELECT id,title,slug FROM listings WHERE featured=1 AND booking_enabled=1 AND status='approved' ORDER BY title")->fetchAll();
    } else {
        $q = db()->prepare("SELECT id,title,slug FROM listings WHERE user_id=? AND featured=1 AND booking_enabled=1 AND status='approved' ORDER BY title");
        $q->execute([$u['id']]);
        $myListings = $q->fetchAll();
    }
} catch (Exception $e) {
    // booking_enabled column may not exist yet — fall back to all featured approved listings
    try {
        if (isAdmin()) {
            $myListings = db()->query("SELECT id,title,slug FROM listings WHERE featured=1 AND status='approved' ORDER BY title")->fetchAll();
        } else {
            $q = db()->prepare("SELECT id,title,slug FROM listings WHERE user_id=? AND featured=1 AND status='approved' ORDER BY title");
            $q->execute([$u['id']]);
            $myListings = $q->fetchAll();
        }
    } catch (Exception $e2) { $myListings = []; }
}

$lid = (int)($_GET['listing_id'] ?? ($myListings[0]['id'] ?? 0));
$activeListing = null;
foreach ($myListings as $ml) { if ($ml['id'] === $lid) { $activeListing = $ml; break; } }
if (!$activeListing && !empty($myListings)) { $activeListing = $myListings[0]; $lid = $activeListing['id']; }

// ── Handle actions ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // Booking status change
    if (in_array($action, ['confirmed','cancelled']) && !empty($_POST['booking_id'])) {
        $bkId = (int)$_POST['booking_id'];
        $notes = trim($_POST['notes'] ?? '');
        try {
            // Verify ownership — admin can manage any listing, user only their own
            if (isAdmin()) {
                $own = db()->prepare("SELECT b.id FROM listing_bookings b WHERE b.id=? AND b.listing_id=?");
                $own->execute([$bkId, $lid]);
            } else {
                $own = db()->prepare("SELECT b.id FROM listing_bookings b JOIN listings l ON l.id=b.listing_id WHERE b.id=? AND l.id=? AND l.user_id=?");
                $own->execute([$bkId, $lid, $u['id']]);
            }
            if ($own->fetch()) {
                db()->prepare("UPDATE listing_bookings SET status=?, business_notes=COALESCE(NULLIF(?,''),business_notes), updated_at=NOW() WHERE id=?")
                   ->execute([$action, $notes, $bkId]);
                flash('success', t('Booking updated.','Réservation mise à jour.'));
            }
        } catch (Exception $e) {
            flash('error', t('Could not update booking.','Impossible de mettre à jour.'));
        }
    }

    // Block a date/time
    if ($action === 'block_time' && $lid) {
        $blockDate = trim($_POST['block_date'] ?? '');
        $blockTime = trim($_POST['block_time'] ?? '') ?: null;
        $blockReason = trim($_POST['block_reason'] ?? '');
        if ($blockDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $blockDate)) {
            try {
                // Check not already blocked
                $exists = db()->prepare("SELECT COUNT(*) FROM listing_blocked_slots WHERE listing_id=? AND blocked_date=? AND blocked_time" . ($blockTime ? "=?" : " IS NULL"));
                $params = [$lid, $blockDate]; if ($blockTime) $params[] = $blockTime;
                $exists->execute($params);
                if (!(int)$exists->fetchColumn()) {
                    db()->prepare("INSERT INTO listing_blocked_slots (listing_id,blocked_date,blocked_time,reason) VALUES (?,?,?,?)")
                       ->execute([$lid, $blockDate, $blockTime, $blockReason ?: null]);
                    flash('success', t('Time blocked successfully.','Créneau bloqué avec succès.'));
                } else {
                    flash('error', t('This time is already blocked.','Ce créneau est déjà bloqué.'));
                }
            } catch (Exception $e) {
                flash('error', t('Could not block time. Run booking-db-v2.sql first.','Impossible de bloquer. Exécutez booking-db-v2.sql d\'abord.'));
            }
        }
    }

    // Unblock a slot
    if ($action === 'unblock' && !empty($_POST['block_id'])) {
        $blockId = (int)$_POST['block_id'];
        try {
            db()->prepare("DELETE FROM listing_blocked_slots WHERE id=? AND listing_id=?")->execute([$blockId, $lid]);
            flash('success', t('Block removed.','Blocage supprimé.'));
        } catch (Exception $e) {}
    }

    redirect(SITE_URL . '/manage-bookings?listing_id=' . $lid . '&status=' . ($_GET['status'] ?? 'pending') . '&tab=' . ($_GET['tab'] ?? 'bookings'));
}

// ── Load data ─────────────────────────────────────────────────────────
$filter  = $_GET['status'] ?? 'pending';
if (!in_array($filter, ['pending','confirmed','cancelled','all'])) $filter = 'pending';
$tab     = $_GET['tab'] ?? 'bookings';

$bookings = [];
$counts   = ['pending'=>0,'confirmed'=>0,'cancelled'=>0,'all'=>0];
$blocked  = [];
$slotDuration = 30;

if ($activeListing) {
    // Load slot duration
    try {
        $sd = db()->prepare("SELECT booking_slot_duration FROM listings WHERE id=?");
        $sd->execute([$lid]);
        $sdRow = $sd->fetch();
        $slotDuration = (int)($sdRow['booking_slot_duration'] ?? 30);
    } catch(Exception $e) {}

    // Bookings
    try {
        $where  = 'b.listing_id=?'; $params = [$lid];
        if ($filter !== 'all') { $where .= ' AND b.status=?'; $params[] = $filter; }
        $bq = db()->prepare("SELECT * FROM listing_bookings WHERE {$where} ORDER BY preferred_date ASC, preferred_time ASC, created_at DESC");
        $bq->execute($params);
        $bookings = $bq->fetchAll();

        foreach (['pending','confirmed','cancelled'] as $s) {
            $c = db()->prepare("SELECT COUNT(*) FROM listing_bookings WHERE listing_id=? AND status=?");
            $c->execute([$lid, $s]);
            $counts[$s] = (int)$c->fetchColumn();
        }
        $counts['all'] = array_sum($counts);
    } catch (Exception $e) {
        $bookings = [];
        $counts   = ['pending'=>0,'confirmed'=>0,'cancelled'=>0,'all'=>0];
    }

    // Blocked slots (upcoming)
    try {
        $bls = db()->prepare("SELECT * FROM listing_blocked_slots WHERE listing_id=? AND blocked_date >= CURDATE() ORDER BY blocked_date, blocked_time");
        $bls->execute([$lid]);
        $blocked = $bls->fetchAll();
    } catch (Exception $e) { $blocked = []; }
}

$pageTitle = t('Manage Bookings','Gérer les réservations') . ' — 237Biz';
require_once __DIR__ . '/includes/header.php';
?>

<style>
.mb-wrap   { max-width:920px; margin:0 auto; padding:24px 16px 60px; }
.mb-header { display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:18px; }
.mb-tabs   { display:flex;gap:4px;margin-bottom:16px;flex-wrap:wrap; }
.mb-tab    { padding:7px 15px;border-radius:8px;font-size:13px;font-weight:600;border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.03);color:var(--muted);text-decoration:none;transition:all .15s; }
.mb-tab:hover { border-color:rgba(0,168,120,0.4);color:#fff; }
.mb-tab.active           { background:rgba(0,168,120,0.2);border-color:#00A878;color:#00A878; }
.mb-tab.active-pending   { background:rgba(252,209,22,0.15);border-color:rgba(252,209,22,0.6);color:var(--yellow); }
.mb-tab.active-cancelled { background:rgba(206,17,38,0.12);border-color:rgba(206,17,38,0.4);color:#ff6b7a; }
/* Main nav tabs */
.main-tab  { padding:8px 18px;border-radius:8px;font-size:14px;font-weight:700;border:1.5px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.03);color:var(--muted);cursor:pointer;transition:all .15s;font-family:inherit; }
.main-tab.active { background:var(--yellow);border-color:var(--yellow);color:#0A1A0F; }
.tab-panel { display:none; } .tab-panel.active { display:block; }
/* Booking cards */
.bk-card   { border:1px solid rgba(255,255,255,0.08);border-radius:12px;margin-bottom:10px;overflow:hidden;background:rgba(255,255,255,0.015); }
.bk-card-head { display:flex;align-items:center;gap:10px;padding:12px 16px;border-bottom:1px solid rgba(255,255,255,0.05);flex-wrap:wrap; }
.bk-ref-badge { font-size:12px;font-weight:700;letter-spacing:1px;padding:3px 9px;border-radius:6px;background:rgba(255,255,255,0.07);color:rgba(255,255,255,0.8);font-family:monospace; }
.bk-status { font-size:11px;font-weight:700;padding:3px 10px;border-radius:99px; }
.status-pending   { background:rgba(252,209,22,0.15);color:var(--yellow);border:1px solid rgba(252,209,22,0.3); }
.status-confirmed { background:rgba(0,168,120,0.15);color:#00A878;border:1px solid rgba(0,168,120,0.3); }
.status-cancelled { background:rgba(206,17,38,0.12);color:#ff6b7a;border:1px solid rgba(206,17,38,0.3); }
.bk-card-body { padding:12px 16px; }
.bk-info   { display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:8px;margin-bottom:10px; }
.bk-info-item span { font-size:11px;color:var(--muted);display:block;margin-bottom:1px; }
.bk-info-item { font-size:13px;color:rgba(255,255,255,0.8); }
.btn-confirm { background:rgba(0,168,120,0.2);border:1px solid rgba(0,168,120,0.5);color:#00A878;border-radius:7px;padding:6px 14px;font-size:12.5px;font-weight:700;cursor:pointer;font-family:inherit; }
.btn-decline { background:rgba(206,17,38,0.1);border:1px solid rgba(206,17,38,0.4);color:#ff6b7a;border-radius:7px;padding:6px 14px;font-size:12.5px;font-weight:700;cursor:pointer;font-family:inherit; }
.notes-inp  { flex:1;min-width:130px;padding:6px 10px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:6px;color:var(--white);font-size:12.5px;font-family:inherit; }
.bk-wa-link { color:#25D366;font-size:12.5px;text-decoration:none; }
/* Block panel */
.block-form { background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:18px 20px;margin-bottom:16px; }
.block-form label { font-size:13px;font-weight:600;display:block;margin-bottom:5px;color:rgba(255,255,255,0.8); }
.block-form input, .block-form select { width:100%;box-sizing:border-box;padding:9px 12px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:7px;color:var(--white);font-family:inherit;font-size:13.5px; }
.block-form input:focus { outline:none;border-color:rgba(0,168,120,0.5); }
.block-row { display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:12px; }
.blocked-list { display:flex;flex-direction:column;gap:8px; }
.blocked-item { display:flex;align-items:center;gap:10px;padding:10px 14px;background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.07);border-radius:8px;flex-wrap:wrap; }
.blocked-date { font-size:13.5px;font-weight:700;color:var(--white); }
.blocked-time { font-size:12.5px;color:var(--muted); }
.blocked-reason { font-size:11.5px;color:var(--muted);font-style:italic; }
.btn-unblock { background:none;border:1px solid rgba(206,17,38,0.3);color:#ff6b7a;border-radius:6px;padding:4px 12px;font-size:12px;cursor:pointer;font-family:inherit;margin-left:auto; }
.btn-unblock:hover { background:rgba(206,17,38,0.1); }
/* Slot duration badge */
.slot-badge { display:inline-block;background:rgba(0,168,120,0.15);color:#00A878;border:1px solid rgba(0,168,120,0.3);border-radius:99px;padding:3px 12px;font-size:12px;font-weight:700; }
.empty-state { text-align:center;padding:40px 20px;color:var(--muted); }
</style>

<div class="page-header">
  <div class="container">
    <nav class="breadcrumb">
      <a href="<?= SITE_URL ?>/"><?= t('Home','Accueil') ?></a> ›
      <a href="<?= SITE_URL ?>/dashboard"><?= t('Dashboard','Tableau de bord') ?></a> ›
      <span><?= t('Bookings','Réservations') ?></span>
    </nav>
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.4rem,3vw,2rem);">
      📅 <?= t('Manage Bookings','Gérer les réservations') ?>
    </h1>
  </div>
</div>

<section class="page-section" style="padding-top:1.25rem;">
<div class="mb-wrap">

<?php if (empty($myListings)): ?>
<div class="empty-state">
  <div style="font-size:2.5rem;margin-bottom:14px;">📅</div>
  <p style="font-size:15px;color:var(--white);font-weight:600;margin-bottom:8px;"><?= t('No booking-enabled listings','Aucune annonce avec réservation activée') ?></p>
  <ol style="text-align:left;display:inline-block;font-size:13px;color:var(--muted);line-height:2;">
    <li><?= t('Run booking-db.sql and booking-db-v2.sql in phpMyAdmin','Exécutez booking-db.sql et booking-db-v2.sql dans phpMyAdmin') ?></li>
    <li><?= t('Go to Edit Listing → Appointment Booking section','Allez à Modifier l\'annonce → section Réservation') ?></li>
    <li><?= t('Enable booking and save','Activez la réservation et enregistrez') ?></li>
  </ol>
  <a href="<?= SITE_URL ?>/dashboard" class="btn btn-outline" style="margin-top:14px;">← <?= t('Dashboard','Tableau de bord') ?></a>
</div>

<?php else: ?>

<!-- Listing selector -->
<div class="mb-header">
  <div>
    <strong style="font-size:15px;"><?= e($activeListing['title']) ?></strong>
    <span class="slot-badge" style="margin-left:10px;"><?= $slotDuration ?>min <?= t('slots','créneaux') ?></span>
    <a href="<?= SITE_URL ?>/listing/<?= e($activeListing['slug']) ?>" style="font-size:12px;color:var(--green);margin-left:10px;" target="_blank"><?= t('View listing','Voir l\'annonce') ?> →</a>
  </div>
  <?php if (count($myListings) > 1): ?>
  <select style="padding:7px 12px;background:rgba(255,255,255,0.05);border:1px solid var(--border);border-radius:7px;color:var(--white);font-family:inherit;font-size:13px;"
          onchange="location.href='/manage-bookings?listing_id='+this.value">
    <?php foreach ($myListings as $ml): ?>
    <option value="<?= $ml['id'] ?>" <?= $ml['id']===$lid?'selected':'' ?>><?= e($ml['title']) ?></option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>
</div>

<!-- Main tabs: Bookings | Block Times -->
<div style="display:flex;gap:8px;margin-bottom:18px;">
  <button class="main-tab <?= $tab==='bookings'?'active':'' ?>" onclick="switchTab('bookings')"><?= t('📋 Bookings','📋 Réservations') ?> (<?= $counts['all'] ?>)</button>
  <button class="main-tab <?= $tab==='block'?'active':'' ?>" onclick="switchTab('block')">🚫 <?= t('Block Times','Bloquer des créneaux') ?> <?php if (!empty($blocked)): ?>(<?= count($blocked) ?>)<?php endif; ?></button>
  <a href="<?= SITE_URL ?>/booking?slug=<?= e($activeListing['slug']) ?>" target="_blank" class="main-tab" style="text-decoration:none;">🔗 <?= t('Booking Page','Page de réservation') ?></a>
</div>

<!-- ══ TAB 1: Bookings ══════════════════════════════════════════════════ -->
<div class="tab-panel <?= $tab==='bookings'?'active':'' ?>" id="tab-bookings">

  <!-- Status filter tabs -->
  <div class="mb-tabs">
    <?php foreach (['pending'=>'⏳','confirmed'=>'✅','cancelled'=>'✕','all'=>'📋'] as $s=>$icon):
      $label = ['pending'=>t('Pending','En attente'),'confirmed'=>t('Confirmed','Confirmées'),'cancelled'=>t('Cancelled','Annulées'),'all'=>t('All','Toutes')][$s];
      $cls = $filter===$s ? 'active '.($s!=='all'?"active-{$s}":'') : '';
    ?>
    <a href="?listing_id=<?= $lid ?>&status=<?= $s ?>&tab=bookings" class="mb-tab <?= $cls ?>">
      <?= $icon ?> <?= $label ?> <span style="opacity:.6;">(<?= $counts[$s] ?>)</span>
    </a>
    <?php endforeach; ?>
  </div>

  <?php if (empty($bookings)): ?>
  <div class="empty-state">
    <div style="font-size:2rem;margin-bottom:10px;">📭</div>
    <p style="color:var(--white);font-weight:600;"><?= $filter==='pending'?t('No pending bookings','Aucune réservation en attente'):t('No bookings found','Aucune réservation') ?></p>
  </div>
  <?php else: ?>
  <?php foreach ($bookings as $bk):
    $isPending   = $bk['status']==='pending';
    $isConfirmed = $bk['status']==='confirmed';
    $daysUntil   = (int)round((strtotime($bk['preferred_date'])-time())/86400);
    $isToday     = $daysUntil===0;
    $custWa      = $bk['customer_phone'] ? 'https://wa.me/'.preg_replace('/\D/','',$bk['customer_phone']) : null;
    $confirmMsg  = rawurlencode('Hi '.$bk['customer_name'].', your booking ['.$bk['reference'].'] is confirmed for '.date('D d M',strtotime($bk['preferred_date'])).($bk['preferred_time']?' at '.date('g:i A',strtotime($bk['preferred_time'])):'').'. — '.($activeListing['title']??''));
  ?>
  <div class="bk-card">
    <div class="bk-card-head">
      <span class="bk-ref-badge"><?= e($bk['reference']) ?></span>
      <span class="bk-status status-<?= $bk['status'] ?>">
        <?= ['pending'=>'⏳ '.t('Pending','En attente'),'confirmed'=>'✅ '.t('Confirmed','Confirmée'),'cancelled'=>'✕ '.t('Cancelled','Annulée')][$bk['status']] ?>
      </span>
      <div style="margin-left:auto;text-align:right;">
        <div style="font-size:14px;font-weight:700;"><?= date('D d M Y',strtotime($bk['preferred_date'])) ?> <?= $bk['preferred_time']?'@ '.date('g:i A',strtotime($bk['preferred_time'])):'' ?></div>
        <div style="font-size:11.5px;color:<?= $isToday?'#00A878':'var(--muted)' ?>;">
          <?= $isToday?'🔔 '.t('Today!','Aujourd\'hui !'):($daysUntil<0?t('Past','Passé'):'In '.$daysUntil.' '.t('days','jours')) ?>
        </div>
      </div>
    </div>
    <div class="bk-card-body">
      <div class="bk-info">
        <div class="bk-info-item"><span><?= t('Customer','Client') ?></span><?= e($bk['customer_name']) ?></div>
        <div class="bk-info-item"><span><?= t('Phone','Téléphone') ?></span>
          <?php if ($custWa): ?><a href="<?= e($custWa) ?>" class="bk-wa-link" target="_blank">💬 <?= e($bk['customer_phone']) ?></a><?php else: echo e($bk['customer_phone']); endif; ?>
        </div>
        <?php if ($bk['customer_email']): ?><div class="bk-info-item"><span>Email</span><a href="mailto:<?= e($bk['customer_email']) ?>" style="color:var(--green);font-size:13px;"><?= e($bk['customer_email']) ?></a></div><?php endif; ?>
        <?php if ($bk['service_name']): ?><div class="bk-info-item"><span><?= t('Service','Service') ?></span><?= e($bk['service_name']) ?></div><?php endif; ?>
      </div>
      <?php if ($bk['message']): ?><div style="font-size:12.5px;color:rgba(255,255,255,0.6);background:rgba(255,255,255,0.03);border-radius:6px;padding:7px 11px;margin-bottom:9px;font-style:italic;">"<?= e($bk['message']) ?>"</div><?php endif; ?>
      <?php if ($bk['business_notes']): ?><div style="font-size:11.5px;color:rgba(255,255,255,0.45);margin-bottom:8px;">📝 <?= e($bk['business_notes']) ?></div><?php endif; ?>

      <?php if ($isPending): ?>
      <form method="POST" style="display:flex;gap:7px;flex-wrap:wrap;align-items:center;">
        <input type="hidden" name="csrf" value="<?= csrf() ?>">
        <input type="hidden" name="booking_id" value="<?= $bk['id'] ?>">
        <input class="notes-inp" type="text" name="notes" placeholder="<?= e(t('Add a note...','Ajouter une note...')) ?>">
        <button type="submit" name="action" value="confirmed" class="btn-confirm">✅ <?= t('Confirm','Confirmer') ?></button>
        <button type="submit" name="action" value="cancelled" class="btn-decline" onclick="return confirm('<?= e(t('Decline this booking?','Décliner cette réservation ?')) ?>')">✕ <?= t('Decline','Décliner') ?></button>
        <?php if ($custWa): ?><a href="<?= e($custWa.'?text='.$confirmMsg) ?>" class="bk-wa-link" target="_blank">💬 <?= t('WhatsApp to confirm','WhatsApp pour confirmer') ?></a><?php endif; ?>
      </form>
      <?php elseif ($isConfirmed && $custWa): ?>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <a href="<?= e($custWa) ?>" class="bk-wa-link" target="_blank">💬 <?= t('WhatsApp customer','WhatsApp client') ?></a>
        <form method="POST" style="margin-left:auto;">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <input type="hidden" name="booking_id" value="<?= $bk['id'] ?>">
          <button type="submit" name="action" value="cancelled" class="btn-decline" onclick="return confirm('<?= e(t('Cancel this booking?','Annuler ?')) ?>')">✕ <?= t('Cancel','Annuler') ?></button>
        </form>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- ══ TAB 2: Block Times ═══════════════════════════════════════════════ -->
<div class="tab-panel <?= $tab==='block'?'active':'' ?>" id="tab-block">

  <!-- Add block form -->
  <div class="block-form">
    <h3 style="font-family:'Fraunces',serif;font-size:1.05rem;margin-bottom:14px;">🚫 <?= t('Block a Date or Time','Bloquer une date ou un créneau') ?></h3>
    <p style="font-size:13px;color:var(--muted);margin-bottom:14px;">
      <?= t('Blocked slots will not appear as available on the booking form. Block a whole day (e.g. for holidays) or specific times (e.g. for existing appointments).','Les créneaux bloqués n\'apparaîtront pas sur le formulaire de réservation. Bloquez une journée entière (ex. congés) ou des créneaux spécifiques (ex. rendez-vous existants).') ?>
    </p>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="action" value="block_time">
      <div class="block-row">
        <div>
          <label>📅 <?= t('Date','Date') ?> *</label>
          <input type="date" name="block_date" required min="<?= date('Y-m-d') ?>">
        </div>
        <div>
          <label>🕐 <?= t('Time','Heure') ?> <span style="font-size:11px;font-weight:400;color:var(--muted);"><?= t('(leave empty = whole day)','(vide = journée entière)') ?></span></label>
          <!-- Generate time options matching slot duration -->
          <select name="block_time">
            <option value=""><?= e(t('— Whole day —','— Journée entière —')) ?></option>
            <?php
            $safeDur = in_array($slotDuration,[15,30,45,60]) ? $slotDuration : 30;
            for ($h=7; $h<20; $h++) {
                for ($m=0; $m<60; $m+=$safeDur) {
                    $val = sprintf('%02d:%02d',$h,$m);
                    $lbl = date('g:i A',strtotime('2000-01-01 '.$val));
                    echo "<option value=\"{$val}\">{$lbl}</option>";
                }
            }
            ?>
          </select>
        </div>
        <div>
          <label><?= t('Reason','Raison') ?> <span style="font-size:11px;font-weight:400;color:var(--muted);"><?= t('(optional)','(optionnel)') ?></span></label>
          <input type="text" name="block_reason" placeholder="<?= e(t('e.g. Holiday, Staff meeting','ex. Congé, Réunion')) ?>">
        </div>
      </div>
      <button type="submit" class="btn btn-primary" style="padding:9px 22px;font-size:13.5px;">🚫 <?= t('Block This Time','Bloquer ce créneau') ?></button>
    </form>
  </div>

  <!-- Upcoming blocked slots -->
  <h3 style="font-size:14px;font-weight:700;margin-bottom:10px;color:rgba(255,255,255,0.7);">
    <?= t('Upcoming Blocked Slots','Créneaux bloqués à venir') ?>
    <?php if (!empty($blocked)): ?><span style="font-size:12px;color:var(--muted);font-weight:400;margin-left:6px;">(<?= count($blocked) ?>)</span><?php endif; ?>
  </h3>

  <?php if (empty($blocked)): ?>
  <div style="text-align:center;padding:24px;color:var(--muted);font-size:13.5px;border:1px dashed rgba(255,255,255,0.1);border-radius:10px;">
    ✅ <?= t('No upcoming blocked times. All your available slots are open for booking.','Aucun créneau bloqué à venir. Tous vos créneaux sont ouverts à la réservation.') ?>
  </div>
  <?php else: ?>
  <div class="blocked-list">
    <?php foreach ($blocked as $bl): ?>
    <div class="blocked-item">
      <span>🚫</span>
      <div>
        <div class="blocked-date"><?= date('D d M Y',strtotime($bl['blocked_date'])) ?></div>
        <div class="blocked-time">
          <?= $bl['blocked_time'] ? date('g:i A',strtotime($bl['blocked_time'])) : '<strong>'.t('Whole day','Journée entière').'</strong>' ?>
        </div>
        <?php if ($bl['reason']): ?><div class="blocked-reason"><?= e($bl['reason']) ?></div><?php endif; ?>
      </div>
      <form method="POST" style="margin-left:auto;">
        <input type="hidden" name="csrf" value="<?= csrf() ?>">
        <input type="hidden" name="action" value="unblock">
        <input type="hidden" name="block_id" value="<?= $bl['id'] ?>">
        <button type="submit" class="btn-unblock" onclick="return confirm('<?= e(t('Remove this block?','Supprimer ce blocage ?')) ?>')">✕ <?= t('Remove','Supprimer') ?></button>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div><!-- /tab-block -->

<?php endif; ?>
</div>
</section>

<script>
function switchTab(name) {
  document.querySelectorAll('.tab-panel').forEach(function(p){ p.classList.remove('active'); });
  document.querySelectorAll('.main-tab').forEach(function(b){ b.classList.remove('active'); });
  var panel = document.getElementById('tab-'+name);
  if (panel) panel.classList.add('active');
  // Find and activate the corresponding button
  document.querySelectorAll('.main-tab').forEach(function(b){
    if (b.textContent.toLowerCase().includes(name==='bookings'?'booking':'block')) {
      b.classList.add('active');
    }
  });
  // Update URL without reload
  var url = new URL(window.location);
  url.searchParams.set('tab', name);
  history.replaceState(null, '', url);
}
// Activate correct tab on load
switchTab('<?= $tab === 'block' ? 'block' : 'bookings' ?>');
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
