<?php
/**
 * partner/bookings.php — 237Biz Partner Booking Management
 * URL: /partner/bookings
 *
 * Business owners view and manage all incoming bookings, filtered by type,
 * status and date. Supports: appointment, table, service, experience.
 */
require_once __DIR__ . '/../includes/config.php';
requireLogin();

$u = currentUser();

// ── Load owned listings ───────────────────────────────────────────────
$myListings = [];
try {
    $st = db()->prepare("
        SELECT id, title, slug
        FROM listings
        WHERE user_id = ? AND status = 'approved'
        ORDER BY title
    ");
    $st->execute([$u['id']]);
    $myListings = $st->fetchAll();
} catch (Exception $e) {}

if (empty($myListings)) {
    flash('error', t(
        'You don\'t have any approved listings yet.',
        'Vous n\'avez pas encore d\'annonces approuvées.'
    ));
    redirect(SITE_URL . '/partner/dashboard');
}

// Validate selected listing
$lidParam = (int)($_GET['listing_id'] ?? 0);
$lid = 0;
$activeListing = null;
foreach ($myListings as $ml) {
    if ($ml['id'] === $lidParam) { $lid = $lidParam; $activeListing = $ml; break; }
}
if (!$activeListing) { $activeListing = $myListings[0]; $lid = $activeListing['id']; }

// ── Handle POST actions ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    $bkId   = (int)($_POST['booking_id'] ?? 0);

    if ($bkId && in_array($action, ['confirmed','cancelled','pending'])) {
        $notes = trim($_POST['notes'] ?? '');

        // Ownership check
        $own = db()->prepare("
            SELECT b.id FROM listing_bookings b
            JOIN listings l ON l.id = b.listing_id
            WHERE b.id = ? AND l.id = ? AND l.user_id = ?
        ");
        $own->execute([$bkId, $lid, $u['id']]);

        if ($own->fetch()) {
            db()->prepare("
                UPDATE listing_bookings
                SET status = ?,
                    business_notes = COALESCE(NULLIF(?, ''), business_notes),
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$action, $notes, $bkId]);
            flash('success', t('Booking updated.', 'Réservation mise à jour.'));
        }
    }

    // Build redirect preserving filters
    $qs = http_build_query(array_filter([
        'listing_id' => $lid,
        'type'       => $_GET['type']   ?? '',
        'status'     => $_GET['status'] ?? '',
        'date'       => $_GET['date']   ?? '',
    ]));
    redirect(SITE_URL . '/partner/bookings?' . $qs);
}

// ── Filters ───────────────────────────────────────────────────────────
$filterType   = $_GET['type']   ?? '';
$filterStatus = $_GET['status'] ?? 'pending';
$filterDate   = $_GET['date']   ?? '';
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 20;

$validTypes   = ['', 'appointment', 'table', 'service', 'experience'];
$validStatus  = ['', 'pending', 'confirmed', 'cancelled'];
if (!in_array($filterType,   $validTypes))  $filterType   = '';
if (!in_array($filterStatus, $validStatus)) $filterStatus = '';

// ── Count by status (for tabs) ────────────────────────────────────────
$statusCounts = ['pending' => 0, 'confirmed' => 0, 'cancelled' => 0];
try {
    $sc = db()->prepare("
        SELECT status, COUNT(*) AS n
        FROM listing_bookings
        WHERE listing_id = ?
        GROUP BY status
    ");
    $sc->execute([$lid]);
    foreach ($sc->fetchAll() as $row) {
        if (isset($statusCounts[$row['status']])) {
            $statusCounts[$row['status']] = (int)$row['n'];
        }
    }
} catch (Exception $e) {}

// ── Count by type ─────────────────────────────────────────────────────
$typeCounts = [];
try {
    $tc = db()->prepare("
        SELECT COALESCE(booking_type,'appointment') AS btype, COUNT(*) AS n
        FROM listing_bookings
        WHERE listing_id = ?
        GROUP BY btype
    ");
    $tc->execute([$lid]);
    foreach ($tc->fetchAll() as $row) $typeCounts[$row['btype']] = (int)$row['n'];
} catch (Exception $e) {
    // booking_type column not yet added — graceful fallback
    $typeCounts = ['appointment' => array_sum($statusCounts)];
}

// ── Fetch bookings ────────────────────────────────────────────────────
$where  = ['b.listing_id = ?'];
$params = [$lid];

if ($filterStatus) { $where[] = 'b.status = ?';         $params[] = $filterStatus; }
if ($filterType)   { $where[] = 'COALESCE(b.booking_type,\'appointment\') = ?'; $params[] = $filterType; }
if ($filterDate)   { $where[] = 'b.preferred_date = ?'; $params[] = $filterDate; }

$whereSQL = implode(' AND ', $where);

// Total count
$totalCount = 0;
try {
    $cnt = db()->prepare("SELECT COUNT(*) FROM listing_bookings b WHERE $whereSQL");
    $cnt->execute($params);
    $totalCount = (int)$cnt->fetchColumn();
} catch (Exception $e) {}

$totalPages = max(1, (int)ceil($totalCount / $perPage));
$page       = min($page, $totalPages);

// Fetch page
$bookings = [];
try {
    $bq = db()->prepare("
        SELECT b.*,
               COALESCE(b.booking_type, 'appointment') AS btype
        FROM listing_bookings b
        WHERE $whereSQL
        ORDER BY
          FIELD(b.status, 'pending', 'confirmed', 'cancelled') ASC,
          b.preferred_date ASC,
          b.preferred_time ASC
        LIMIT $perPage OFFSET " . (($page - 1) * $perPage)
    );
    $bq->execute($params);
    $bookings = $bq->fetchAll();
} catch (Exception $e) {
    // Fallback without booking_type column
    try {
        $bq2 = db()->prepare("
            SELECT b.*, 'appointment' AS btype
            FROM listing_bookings b
            WHERE $whereSQL
            ORDER BY FIELD(b.status,'pending','confirmed','cancelled') ASC,
                     b.preferred_date ASC, b.preferred_time ASC
            LIMIT $perPage OFFSET " . (($page - 1) * $perPage)
        );
        $bq2->execute($params);
        $bookings = $bq2->fetchAll();
    } catch (Exception $e2) {}
}

// ── URL helper ────────────────────────────────────────────────────────
function bkUrl(array $ov = []): string {
    global $lid, $filterType, $filterStatus, $filterDate;
    $base = ['listing_id' => $lid, 'type' => $filterType, 'status' => $filterStatus, 'date' => $filterDate];
    $p    = array_merge($base, $ov);
    return SITE_URL . '/partner/bookings?' . http_build_query(array_filter($p, fn($v) => $v !== ''));
}

// ── Type/status display helpers ───────────────────────────────────────
$typeLabel = [
    'appointment' => t('Appointment','Rendez-vous'),
    'table'       => t('Table','Table'),
    'service'     => t('Service','Service'),
    'experience'  => t('Experience','Expérience'),
];
$typeIcon = [
    'appointment' => '📅',
    'table'       => '🍽️',
    'service'     => '🛠️',
    'experience'  => '🎟️',
];
$statusBadge = [
    'pending'   => '<span style="background:rgba(255,165,0,0.15);color:#ffa500;border:1px solid rgba(255,165,0,0.35);padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700;">' . t('Pending','En attente') . '</span>',
    'confirmed' => '<span style="background:rgba(0,168,120,0.15);color:#00A878;border:1px solid rgba(0,168,120,0.35);padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700;">' . t('Confirmed','Confirmé') . '</span>',
    'cancelled' => '<span style="background:rgba(206,17,38,0.1);color:#ff6b7a;border:1px solid rgba(206,17,38,0.25);padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700;">' . t('Cancelled','Annulé') . '</span>',
];

$pageTitle = t('My Bookings', 'Mes réservations');
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.pb-wrap  { max-width:900px; margin:0 auto; padding:24px 16px 60px; }
.pb-head  { display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:20px; }
.pb-head h1 { font-family:'Fraunces',serif; font-size:1.6rem; font-weight:900; margin:0; flex:1; }

/* Listing tabs */
.listing-tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:16px; }
.listing-tab  { padding:6px 14px; border-radius:99px; font-size:13px; font-weight:600;
                border:1.5px solid rgba(255,255,255,0.1); color:rgba(255,255,255,0.5); text-decoration:none; }
.listing-tab.active { background:rgba(0,168,120,0.15); border-color:#00A878; color:#00A878; }

/* Filters bar */
.filter-bar  { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:18px; }
.filter-pill { padding:6px 14px; border-radius:99px; font-size:12.5px; font-weight:600;
               border:1.5px solid rgba(255,255,255,0.1); color:rgba(255,255,255,0.5); text-decoration:none; }
.filter-pill.active { background:rgba(0,168,120,0.15); border-color:#00A878; color:#00A878; }

/* Stats row */
.stat-row { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin-bottom:18px; }
.stat-box { background:rgba(255,255,255,0.025); border:1px solid var(--border); border-radius:10px;
            padding:12px 16px; text-align:center; }
.stat-box .stat-n { font-family:'Fraunces',serif; font-size:1.7rem; font-weight:900; color:#fff; }
.stat-box .stat-l { font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:.06em; }

/* Table */
.bk-table { width:100%; border-collapse:collapse; }
.bk-table th { font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:var(--muted);
               font-weight:700; padding:8px 10px; border-bottom:1px solid var(--border); text-align:left; }
.bk-table td { padding:12px 10px; border-bottom:1px solid rgba(255,255,255,0.04); vertical-align:top; }
.bk-table tr:hover td { background:rgba(255,255,255,0.015); }
.bk-ref-code { font-size:12px; font-family:monospace; background:rgba(255,255,255,0.06);
               padding:2px 6px; border-radius:4px; color:#00A878; letter-spacing:1px; }
.bk-name { font-weight:700; font-size:14px; margin-bottom:2px; }
.bk-meta { font-size:12px; color:var(--muted); }

/* Actions */
.bk-actions { display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
.bk-btn { padding:5px 12px; border-radius:6px; font-size:12px; font-weight:700;
          border:1.5px solid; cursor:pointer; font-family:inherit; background:transparent; }
.bk-btn-confirm { border-color:rgba(0,168,120,0.5); color:#00A878; }
.bk-btn-confirm:hover { background:rgba(0,168,120,0.15); }
.bk-btn-cancel  { border-color:rgba(206,17,38,0.4); color:#ff6b7a; }
.bk-btn-cancel:hover  { background:rgba(206,17,38,0.1); }
.bk-btn-note    { border-color:rgba(255,255,255,0.2); color:rgba(255,255,255,0.6); }
.bk-btn-note:hover    { background:rgba(255,255,255,0.05); }

/* Inline notes form */
.note-form { display:none; margin-top:8px; }
.note-form textarea { width:100%; box-sizing:border-box; padding:8px 10px;
  background:rgba(255,255,255,0.04); border:1.5px solid rgba(255,255,255,0.1);
  border-radius:6px; color:#fff; font-family:inherit; font-size:13px; resize:vertical; }
.note-form textarea:focus { outline:none; border-color:rgba(0,168,120,0.5); }
.note-form .note-btns { display:flex; gap:6px; margin-top:6px; }

/* Date filter input */
.date-filter { padding:7px 10px; background:rgba(255,255,255,0.04); border:1.5px solid rgba(255,255,255,0.1);
               border-radius:8px; color:#fff; font-size:13px; font-family:inherit; }
.date-filter:focus { outline:none; border-color:rgba(0,168,120,0.5); }

@media(max-width:600px){
  .bk-table thead { display:none; }
  .bk-table tr  { display:block; margin-bottom:14px; background:rgba(255,255,255,0.02); border:1px solid var(--border); border-radius:10px; padding:12px; }
  .bk-table td  { display:block; padding:4px 0; border:none; }
  .stat-row { grid-template-columns:1fr 1fr; }
}
</style>

<div class="pb-wrap">

  <div class="pb-head">
    <h1>📋 <?= t('Bookings','Réservations') ?></h1>
    <a href="<?= SITE_URL ?>/partner/dashboard" style="font-size:13px;color:var(--muted);">← <?= t('Dashboard','Tableau de bord') ?></a>
  </div>

  <?php if (count($myListings) > 1): ?>
  <!-- Listing selector tabs -->
  <div class="listing-tabs">
    <?php foreach ($myListings as $ml): ?>
    <a href="<?= e(bkUrl(['listing_id' => $ml['id'], 'page' => 1])) ?>"
       class="listing-tab <?= $ml['id'] === $lid ? 'active' : '' ?>">
      <?= e($ml['title']) ?>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Stats row -->
  <div class="stat-row">
    <div class="stat-box">
      <div class="stat-n" style="color:#ffa500;"><?= $statusCounts['pending'] ?></div>
      <div class="stat-l"><?= t('Pending','En attente') ?></div>
    </div>
    <div class="stat-box">
      <div class="stat-n" style="color:#00A878;"><?= $statusCounts['confirmed'] ?></div>
      <div class="stat-l"><?= t('Confirmed','Confirmés') ?></div>
    </div>
    <div class="stat-box">
      <div class="stat-n" style="color:rgba(255,255,255,0.5);"><?= $statusCounts['cancelled'] ?></div>
      <div class="stat-l"><?= t('Cancelled','Annulés') ?></div>
    </div>
  </div>

  <!-- Filter bar: status tabs -->
  <div class="filter-bar">
    <a href="<?= e(bkUrl(['status' => '', 'page' => 1])) ?>"
       class="filter-pill <?= $filterStatus === '' ? 'active' : '' ?>">
      <?= t('All','Tous') ?> (<?= array_sum($statusCounts) ?>)
    </a>
    <?php foreach (['pending' => t('Pending','En attente'), 'confirmed' => t('Confirmed','Confirmés'), 'cancelled' => t('Cancelled','Annulés')] as $st => $stl): ?>
    <a href="<?= e(bkUrl(['status' => $st, 'page' => 1])) ?>"
       class="filter-pill <?= $filterStatus === $st ? 'active' : '' ?>">
      <?= $stl ?> (<?= $statusCounts[$st] ?>)
    </a>
    <?php endforeach; ?>

    <!-- Separator -->
    <span style="color:rgba(255,255,255,0.15);padding:0 2px;">|</span>

    <!-- Type pills -->
    <a href="<?= e(bkUrl(['type' => '', 'page' => 1])) ?>"
       class="filter-pill <?= $filterType === '' ? 'active' : '' ?>">
      <?= t('All types','Tous types') ?>
    </a>
    <?php foreach ($typeLabel as $bt => $btl): ?>
    <?php if (!empty($typeCounts[$bt])): ?>
    <a href="<?= e(bkUrl(['type' => $bt, 'page' => 1])) ?>"
       class="filter-pill <?= $filterType === $bt ? 'active' : '' ?>">
      <?= $typeIcon[$bt] ?> <?= $btl ?> (<?= $typeCounts[$bt] ?>)
    </a>
    <?php endif; ?>
    <?php endforeach; ?>
  </div>

  <!-- Date filter -->
  <form method="GET" action="<?= SITE_URL ?>/partner/bookings" style="display:flex;gap:8px;align-items:center;margin-bottom:16px;flex-wrap:wrap;">
    <input type="hidden" name="listing_id" value="<?= $lid ?>">
    <input type="hidden" name="status"     value="<?= e($filterStatus) ?>">
    <input type="hidden" name="type"       value="<?= e($filterType) ?>">
    <input type="date" name="date" value="<?= e($filterDate) ?>" class="date-filter"
           placeholder="<?= e(t('Filter by date','Filtrer par date')) ?>">
    <button type="submit" class="bk-btn bk-btn-note" style="padding:7px 14px;">
      <?= t('Filter','Filtrer') ?>
    </button>
    <?php if ($filterDate): ?>
    <a href="<?= e(bkUrl(['date' => '', 'page' => 1])) ?>" style="font-size:12px;color:var(--muted);">
      ✕ <?= t('Clear date','Effacer') ?>
    </a>
    <?php endif; ?>
  </form>

  <?php if (($f = flash('success')) || ($fErr = flash('error'))): ?>
  <div style="padding:10px 16px;border-radius:8px;margin-bottom:14px;font-size:13px;
    <?= isset($f) ? 'background:rgba(0,168,120,0.1);border:1px solid rgba(0,168,120,0.3);color:#00A878;' : 'background:rgba(206,17,38,0.1);border:1px solid rgba(206,17,38,0.3);color:#ff6b7a;' ?>">
    <?= e($f ?? $fErr) ?>
  </div>
  <?php endif; ?>

  <?php if (empty($bookings)): ?>
  <div style="text-align:center;padding:60px 20px;color:var(--muted);">
    <div style="font-size:2.5rem;margin-bottom:12px;">📋</div>
    <p style="font-size:15px;font-weight:600;"><?= t('No bookings found','Aucune réservation trouvée') ?></p>
    <p style="font-size:13px;margin-top:6px;"><?= t('When customers book through your listing, they\'ll appear here.','Quand des clients réservent via votre annonce, ils apparaîtront ici.') ?></p>
    <a href="<?= SITE_URL ?>/listing/<?= e($activeListing['slug']) ?>" class="btn btn-outline" style="margin-top:16px;">
      <?= t('View listing','Voir l\'annonce') ?>
    </a>
  </div>

  <?php else: ?>
  <!-- Bookings table -->
  <div style="overflow-x:auto;">
    <table class="bk-table">
      <thead>
        <tr>
          <th><?= t('Reference','Référence') ?></th>
          <th><?= t('Customer','Client') ?></th>
          <th><?= t('Type / Service','Type / Service') ?></th>
          <th><?= t('Date & Time','Date et heure') ?></th>
          <th><?= t('Status','Statut') ?></th>
          <th><?= t('Actions','Actions') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($bookings as $bk): ?>
        <?php $bType = $bk['btype'] ?? 'appointment'; ?>
        <tr id="row-<?= $bk['id'] ?>">

          <!-- Reference + type icon -->
          <td>
            <span class="bk-ref-code"><?= e($bk['reference']) ?></span>
            <div style="font-size:12px;color:var(--muted);margin-top:4px;">
              <?= $typeIcon[$bType] ?? '📅' ?> <?= $typeLabel[$bType] ?? $bType ?>
            </div>
            <div style="font-size:11px;color:rgba(255,255,255,0.3);margin-top:2px;">
              <?= date('d M Y', strtotime($bk['created_at'])) ?>
            </div>
          </td>

          <!-- Customer -->
          <td>
            <div class="bk-name"><?= e($bk['customer_name']) ?></div>
            <div class="bk-meta">📞 <?= e($bk['customer_phone']) ?></div>
            <?php if ($bk['customer_email']): ?>
            <div class="bk-meta">✉️ <?= e($bk['customer_email']) ?></div>
            <?php endif; ?>
          </td>

          <!-- Type / Service / Party -->
          <td>
            <?php if ($bk['service_name']): ?>
            <div style="font-size:13px;">🛠️ <?= e($bk['service_name']) ?></div>
            <?php endif; ?>
            <?php
            $partyVal = $bk['party_size'] ?? null;
            if ($partyVal > 0): ?>
            <div style="font-size:13px;">👥 <?= (int)$partyVal ?> <?= t('guests','convives') ?></div>
            <?php endif; ?>
            <?php
            $specReq = $bk['special_requests'] ?? $bk['message'] ?? null;
            if ($specReq): ?>
            <div class="bk-meta" style="margin-top:3px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= e($specReq) ?>">
              💬 <?= e($specReq) ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($bk['business_notes'])): ?>
            <div class="bk-meta" style="color:rgba(0,168,120,0.7);margin-top:3px;">
              📝 <?= e($bk['business_notes']) ?>
            </div>
            <?php endif; ?>
          </td>

          <!-- Date & Time -->
          <td>
            <div style="font-weight:700;font-size:14px;"><?= date('D d M', strtotime($bk['preferred_date'])) ?></div>
            <div class="bk-meta"><?= e($bk['preferred_time']) ?></div>
          </td>

          <!-- Status badge -->
          <td><?= $statusBadge[$bk['status']] ?? e($bk['status']) ?></td>

          <!-- Actions -->
          <td>
            <div class="bk-actions">
              <?php if ($bk['status'] === 'pending'): ?>
              <!-- Confirm -->
              <form method="POST">
                <input type="hidden" name="csrf"       value="<?= csrf() ?>">
                <input type="hidden" name="action"     value="confirmed">
                <input type="hidden" name="booking_id" value="<?= $bk['id'] ?>">
                <button type="submit" class="bk-btn bk-btn-confirm">✓ <?= t('Confirm','Confirmer') ?></button>
              </form>
              <!-- Cancel with note -->
              <button type="button" class="bk-btn bk-btn-cancel"
                      onclick="toggleNoteForm('cancel-<?= $bk['id'] ?>')">
                ✕ <?= t('Cancel','Annuler') ?>
              </button>
              <?php elseif ($bk['status'] === 'confirmed'): ?>
              <!-- Mark pending again -->
              <form method="POST">
                <input type="hidden" name="csrf"       value="<?= csrf() ?>">
                <input type="hidden" name="action"     value="pending">
                <input type="hidden" name="booking_id" value="<?= $bk['id'] ?>">
                <button type="submit" class="bk-btn bk-btn-note">↩ <?= t('Unconfirm','Retirer') ?></button>
              </form>
              <button type="button" class="bk-btn bk-btn-cancel"
                      onclick="toggleNoteForm('cancel-<?= $bk['id'] ?>')">
                ✕ <?= t('Cancel','Annuler') ?>
              </button>
              <?php endif; ?>
              <!-- Add/edit note -->
              <button type="button" class="bk-btn bk-btn-note"
                      onclick="toggleNoteForm('note-<?= $bk['id'] ?>')">
                📝 <?= t('Note','Note') ?>
              </button>
              <?php if (!empty($bk['customer_phone'])): ?>
              <?php $wa = 'https://wa.me/' . preg_replace('/\D/', '', $bk['customer_phone']); ?>
              <a href="<?= e($wa) ?>" target="_blank" class="bk-btn" style="border-color:rgba(37,211,102,0.4);color:#25D366;">💬</a>
              <?php endif; ?>
            </div>

            <!-- Inline cancel form -->
            <div class="note-form" id="cancel-<?= $bk['id'] ?>">
              <form method="POST">
                <input type="hidden" name="csrf"       value="<?= csrf() ?>">
                <input type="hidden" name="action"     value="cancelled">
                <input type="hidden" name="booking_id" value="<?= $bk['id'] ?>">
                <textarea name="notes" rows="2"
                          placeholder="<?= e(t('Reason for cancellation (sent to customer)…','Raison de l\'annulation…')) ?>"></textarea>
                <div class="note-btns">
                  <button type="submit" class="bk-btn bk-btn-cancel">✕ <?= t('Confirm Cancel','Confirmer annulation') ?></button>
                  <button type="button" class="bk-btn bk-btn-note"
                          onclick="toggleNoteForm('cancel-<?= $bk['id'] ?>')">
                    <?= t('Close','Fermer') ?>
                  </button>
                </div>
              </form>
            </div>

            <!-- Inline note form -->
            <div class="note-form" id="note-<?= $bk['id'] ?>">
              <form method="POST">
                <input type="hidden" name="csrf"       value="<?= csrf() ?>">
                <input type="hidden" name="action"     value="<?= e($bk['status']) ?>">
                <input type="hidden" name="booking_id" value="<?= $bk['id'] ?>">
                <textarea name="notes" rows="2"
                          placeholder="<?= e(t('Internal note (not shown to customer)…','Note interne…')) ?>"><?= e($bk['business_notes'] ?? '') ?></textarea>
                <div class="note-btns">
                  <button type="submit" class="bk-btn bk-btn-confirm">💾 <?= t('Save note','Sauvegarder') ?></button>
                  <button type="button" class="bk-btn bk-btn-note"
                          onclick="toggleNoteForm('note-<?= $bk['id'] ?>')">
                    <?= t('Close','Fermer') ?>
                  </button>
                </div>
              </form>
            </div>

          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($totalPages > 1): ?>
  <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-top:24px;">
    <?php if ($page > 1): ?>
    <a href="<?= e(bkUrl(['page' => $page - 1])) ?>" class="btn btn-outline" style="padding:7px 16px;font-size:13px;">← <?= t('Prev','Préc.') ?></a>
    <?php endif; ?>
    <span style="padding:7px 14px;font-size:13px;color:var(--muted);">
      <?= t('Page','Page') ?> <?= $page ?> / <?= $totalPages ?>
    </span>
    <?php if ($page < $totalPages): ?>
    <a href="<?= e(bkUrl(['page' => $page + 1])) ?>" class="btn btn-outline" style="padding:7px 16px;font-size:13px;"><?= t('Next','Suiv.') ?> →</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php endif; ?>

</div>

<script>
function toggleNoteForm(id) {
  // Close all open note forms first
  document.querySelectorAll('.note-form').forEach(function(f) {
    if (f.id !== id) f.style.display = 'none';
  });
  var el = document.getElementById(id);
  if (!el) return;
  el.style.display = (el.style.display === 'block') ? 'none' : 'block';
  if (el.style.display === 'block') {
    var ta = el.querySelector('textarea');
    if (ta) ta.focus();
  }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
