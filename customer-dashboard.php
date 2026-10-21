<?php
require_once __DIR__ . '/includes/config.php';
requireLogin();

$u = currentUser();
$userId = (int)$u['id'];

// Active tab
$tab = $_GET['tab'] ?? 'bookings';
$validTabs = ['bookings', 'events', 'favourites', 'reviews', 'follows', 'offers', 'preferences'];
if (!in_array($tab, $validTabs)) $tab = 'bookings';

// ── My Bookings ──────────────────────────────────────────────
$myBookings = [];
if ($tab === 'bookings') {
    try {
        $bq = db()->prepare("
            SELECT b.*, l.title AS listing_title, l.slug AS listing_slug,
                   l.phone AS listing_phone, c.icon AS cat_icon
            FROM listing_bookings b
            JOIN listings l ON l.id = b.listing_id
            LEFT JOIN categories c ON c.id = l.category_id
            WHERE b.user_id = ?
            ORDER BY b.preferred_date DESC, b.created_at DESC
            LIMIT 50
        ");
        $bq->execute([$userId]);
        $myBookings = $bq->fetchAll();
    } catch (Exception $e) {}
}

// ── My Event Registrations ───────────────────────────────────
$myEventRegs = [];
if ($tab === 'events') {
    try {
        $eq = db()->prepare("
            SELECT er.*, e.title_en, e.title_fr, e.start_date, e.end_date,
                   e.location_text, e.slug AS event_slug,
                   l.title AS organiser_name, l.slug AS organiser_slug
            FROM event_registrations er
            JOIN events e ON e.id = er.event_id
            LEFT JOIN listings l ON l.id = e.listing_id
            WHERE er.user_id = ?
            ORDER BY e.start_date DESC, er.created_at DESC
            LIMIT 50
        ");
        $eq->execute([$userId]);
        $myEventRegs = $eq->fetchAll();
    } catch (Exception $e) {}
}

// ── My Favourites ────────────────────────────────────────────
$myFavs = ['listing' => [], 'offer' => [], 'event' => []];
$favCounts = ['listing' => 0, 'offer' => 0, 'event' => 0];
if ($tab === 'favourites') {
    try {
        // Listings
        $flq = db()->prepare("
            SELECT f.id AS fav_id, f.created_at AS saved_at,
                   l.id, l.title, l.slug, l.status, l.featured,
                   c.name_en AS cat_en, c.icon AS cat_icon,
                   loc.name_en AS loc_en
            FROM user_favourites f
            JOIN listings l ON l.id = f.item_id AND f.item_type = 'listing'
            LEFT JOIN categories c ON c.id = l.category_id
            LEFT JOIN locations loc ON loc.id = l.location_id
            WHERE f.user_id = ? AND f.item_type = 'listing'
            ORDER BY f.created_at DESC
        ");
        $flq->execute([$userId]);
        $myFavs['listing'] = $flq->fetchAll();
        $favCounts['listing'] = count($myFavs['listing']);

        // Offers
        $foq = db()->prepare("
            SELECT f.id AS fav_id, f.created_at AS saved_at,
                   o.id, o.title_en, o.title_fr, o.slug, o.discount_pct,
                   o.original_price_xaf, o.offer_price_xaf,
                   o.valid_until, o.status,
                   l.title AS listing_title, l.slug AS listing_slug
            FROM user_favourites f
            JOIN offers o ON o.id = f.item_id AND f.item_type = 'offer'
            JOIN listings l ON l.id = o.listing_id
            WHERE f.user_id = ? AND f.item_type = 'offer'
            ORDER BY f.created_at DESC
        ");
        $foq->execute([$userId]);
        $myFavs['offer'] = $foq->fetchAll();
        $favCounts['offer'] = count($myFavs['offer']);

        // Events
        $feq = db()->prepare("
            SELECT f.id AS fav_id, f.created_at AS saved_at,
                   e.id, e.title_en, e.title_fr, e.slug,
                   e.start_date, e.end_date, e.location_text, e.status,
                   l.title AS listing_title, l.slug AS listing_slug
            FROM user_favourites f
            JOIN events e ON e.id = f.item_id AND f.item_type = 'event'
            JOIN listings l ON l.id = e.listing_id
            WHERE f.user_id = ? AND f.item_type = 'event'
            ORDER BY f.created_at DESC
        ");
        $feq->execute([$userId]);
        $myFavs['event'] = $feq->fetchAll();
        $favCounts['event'] = count($myFavs['event']);
    } catch (Exception $e) {}
}

// ── Businesses I Follow ──────────────────────────────────────
$myFollows = [];
if ($tab === 'follows') {
    try {
        $fwq = db()->prepare("
            SELECT uf.id AS follow_row_id, uf.created_at AS followed_at, uf.notify,
                   l.id AS listing_id, l.title, l.slug, l.status,
                   c.name_en AS cat_en, c.icon AS cat_icon,
                   loc.name_en AS loc_en
            FROM user_follows uf
            JOIN listings l ON l.id = uf.follow_id AND uf.follow_type = 'listing'
            LEFT JOIN categories c ON c.id = l.category_id
            LEFT JOIN locations loc ON loc.id = l.location_id
            WHERE uf.user_id = ? AND uf.unfollowed_at IS NULL
            ORDER BY uf.created_at DESC
        ");
        $fwq->execute([$userId]);
        $myFollows = $fwq->fetchAll();
    } catch (Exception $e) {}
}

// ── Notification Preferences ─────────────────────────────────
$notifPrefs = null;
$prefsFlash = '';
if ($tab === 'preferences') {
    // Handle save
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_prefs'])) {
        verifyCsrfOrDie();
        $cols = ['booking_email','booking_bell','event_email','event_bell','follow_email','follow_bell','promo_email'];
        $vals = [];
        foreach ($cols as $col) { $vals[$col] = isset($_POST[$col]) ? 1 : 0; }
        try {
            $upd = db()->prepare("
                INSERT INTO notification_prefs (user_id, booking_email, booking_bell, event_email, event_bell, follow_email, follow_bell, promo_email)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                  booking_email=VALUES(booking_email), booking_bell=VALUES(booking_bell),
                  event_email=VALUES(event_email), event_bell=VALUES(event_bell),
                  follow_email=VALUES(follow_email), follow_bell=VALUES(follow_bell),
                  promo_email=VALUES(promo_email)
            ");
            $upd->execute([$userId, $vals['booking_email'], $vals['booking_bell'],
                           $vals['event_email'], $vals['event_bell'],
                           $vals['follow_email'], $vals['follow_bell'], $vals['promo_email']]);
            $prefsFlash = 'saved';
        } catch (Exception $e) {
            $prefsFlash = 'error';
        }
    }
    // Load prefs (defaults if none)
    try {
        $pq = db()->prepare("SELECT * FROM notification_prefs WHERE user_id = ? LIMIT 1");
        $pq->execute([$userId]);
        $notifPrefs = $pq->fetch() ?: [
            'booking_email'=>1,'booking_bell'=>1,
            'event_email'=>1,'event_bell'=>1,
            'follow_email'=>1,'follow_bell'=>1,
            'promo_email'=>0,
        ];
    } catch (Exception $e) {
        $notifPrefs = ['booking_email'=>1,'booking_bell'=>1,'event_email'=>1,'event_bell'=>1,
                       'follow_email'=>1,'follow_bell'=>1,'promo_email'=>0];
    }
}

// ── My Offers (discount codes) ───────────────────────────────
$myOffers = [];
if ($tab === 'offers') {
    try {
        $oq = db()->prepare("
            SELECT dc.code, dc.status AS code_status, dc.created_at AS claimed_at, dc.redeemed_at,
                   o.title, o.title_fr, o.slug AS offer_slug, o.offer_type, o.discount_value,
                   o.expires_at, o.image AS offer_image,
                   l.title AS biz_title, l.slug AS biz_slug
            FROM discount_codes dc
            JOIN offer_claims oc ON oc.id = dc.claim_id
            JOIN offers o ON o.id = oc.offer_id
            JOIN listings l ON l.id = o.listing_id
            WHERE dc.user_id = ?
            ORDER BY dc.created_at DESC
        ");
        $oq->execute([$userId]);
        $myOffers = $oq->fetchAll();
    } catch (Exception $e) { /* tables not yet created */ }
}

// ── My Reviews ───────────────────────────────────────────────
$myReviews = [];
if ($tab === 'reviews') {
    try {
        $rq = db()->prepare("
            SELECT r.*, l.title AS listing_title, l.slug AS listing_slug
            FROM reviews r
            JOIN listings l ON l.id = r.listing_id
            WHERE r.user_id = ?
            ORDER BY r.created_at DESC
            LIMIT 50
        ");
        $rq->execute([$userId]);
        $myReviews = $rq->fetchAll();
    } catch (Exception $e) {}
}

// ── Booking status helpers ───────────────────────────────────
function bookingStatusLabel(string $status, bool $isFr): string {
    $map = [
        'pending'   => ['Pending',    'En attente'],
        'confirmed' => ['Confirmed',  'Confirmée'],
        'declined'  => ['Declined',   'Refusée'],
        'cancelled' => ['Cancelled',  'Annulée'],
        'completed' => ['Completed',  'Terminée'],
    ];
    $pair = $map[$status] ?? [ucfirst($status), ucfirst($status)];
    return $isFr ? $pair[1] : $pair[0];
}

function bookingStatusClass(string $status): string {
    return match($status) {
        'confirmed', 'completed' => 'approved',
        'declined',  'cancelled' => 'rejected',
        default => 'pending',
    };
}

$pageTitle = t('My Account — 237Biz', 'Mon Compte — 237Biz');
require_once __DIR__ . '/includes/header.php';
?>

<style>
/* ── Customer dashboard layout ─────────────── */
.cdash-tabs {
  display: flex;
  gap: 4px;
  border-bottom: 1px solid var(--border);
  margin-bottom: 1.75rem;
  overflow-x: auto;
  scrollbar-width: none;
  -webkit-overflow-scrolling: touch;
}
.cdash-tabs::-webkit-scrollbar { display: none; }
.cdash-tab {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 10px 16px;
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--muted);
  text-decoration: none;
  border-bottom: 2px solid transparent;
  white-space: nowrap;
  transition: color .15s, border-color .15s;
}
.cdash-tab:hover { color: var(--white); }
.cdash-tab.active {
  color: var(--green);
  border-bottom-color: var(--green);
}
.cdash-tab-badge {
  font-size: 10px;
  font-weight: 700;
  background: rgba(0,168,120,0.18);
  color: var(--green);
  border-radius: 99px;
  padding: 1px 7px;
}

/* ── Table card ──────────────────────────────── */
.cdash-card {
  background: rgba(255,255,255,0.02);
  border: 1px solid var(--border);
  border-radius: 12px;
  overflow: hidden;
}
.cdash-card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 1rem 1.25rem;
  border-bottom: 1px solid var(--border);
}
.cdash-card-header h4 { margin: 0; font-size: 0.95rem; }

/* ── Empty state ─────────────────────────────── */
.cdash-empty {
  text-align: center;
  padding: 3rem 1.5rem;
}
.cdash-empty-icon { font-size: 2.5rem; margin-bottom: .75rem; }
.cdash-empty h3 { font-family: 'Fraunces', serif; margin-bottom: .4rem; font-size: 1.15rem; }
.cdash-empty p  { color: var(--muted); font-size: .875rem; }

/* ── Favourites grid ─────────────────────────── */
.fav-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: 1rem;
  padding: 1.25rem;
}
.fav-card {
  background: rgba(255,255,255,0.03);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 1rem;
  position: relative;
  transition: border-color .15s;
}
.fav-card:hover { border-color: rgba(0,168,120,.35); }
.fav-card-title {
  font-weight: 600;
  font-size: .9rem;
  color: var(--white);
  margin-bottom: .3rem;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.fav-card-meta { font-size: .78rem; color: var(--muted); }
.fav-card-actions { display: flex; gap: .5rem; margin-top: .75rem; align-items: center; }
.fav-unsave-btn {
  background: none;
  border: 1px solid rgba(206,17,38,.3);
  color: rgba(220,80,80,.8);
  border-radius: 6px;
  padding: 3px 8px;
  font-size: .75rem;
  cursor: pointer;
  transition: background .15s;
}
.fav-unsave-btn:hover { background: rgba(206,17,38,.12); }
.fav-badge-expired {
  font-size: .72rem; font-weight: 700;
  background: rgba(206,17,38,.15); color: #e63946;
  border: 1px solid rgba(206,17,38,.3);
  border-radius: 4px; padding: 1px 6px;
}
.fav-badge-inactive {
  font-size: .72rem; font-weight: 700;
  background: rgba(255,255,255,.06); color: var(--muted);
  border: 1px solid var(--border);
  border-radius: 4px; padding: 1px 6px;
}

/* ── Upcoming highlight ──────────────────────── */
.upcoming-banner {
  background: linear-gradient(135deg, rgba(0,168,120,.08), rgba(0,168,120,.04));
  border: 1px solid rgba(0,168,120,.25);
  border-radius: 10px;
  padding: 1rem 1.25rem;
  margin-bottom: 1.5rem;
  display: flex;
  align-items: center;
  gap: 1rem;
  flex-wrap: wrap;
}
.upcoming-banner-icon { font-size: 2rem; flex-shrink: 0; }
.upcoming-banner-text strong { display: block; font-size: .95rem; color: var(--white); }
.upcoming-banner-text span  { font-size: .82rem; color: var(--muted); }

/* ── Mobile ──────────────────────────────────── */
@media (max-width: 600px) {
  .data-table th:nth-child(3),
  .data-table td:nth-child(3),
  .data-table th:nth-child(5),
  .data-table td:nth-child(5) { display: none; }
}
</style>

<div class="page-header">
  <div class="container">
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.6rem,3vw,2.2rem);">
      <?= t('My Account', 'Mon Compte') ?>
    </h1>
    <p style="color:var(--muted);margin-top:.3rem;"><?= e($u['name']) ?> · <?= e($u['email']) ?></p>
  </div>
</div>

<section class="page-section">
  <div class="container">

    <!-- Tabs -->
    <nav class="cdash-tabs">
      <a href="?tab=bookings"     class="cdash-tab <?= $tab==='bookings'     ? 'active' : '' ?>">📅 <?= t('My Bookings','Mes Réservations') ?></a>
      <a href="?tab=events"       class="cdash-tab <?= $tab==='events'       ? 'active' : '' ?>">🎟 <?= t('My Events','Mes Événements') ?></a>
      <a href="?tab=favourites"   class="cdash-tab <?= $tab==='favourites'   ? 'active' : '' ?>">❤️ <?= t('Favourites','Favoris') ?></a>
      <a href="?tab=follows"      class="cdash-tab <?= $tab==='follows'      ? 'active' : '' ?>">🔔 <?= t('Following','Abonnements') ?></a>
      <a href="?tab=reviews"      class="cdash-tab <?= $tab==='reviews'      ? 'active' : '' ?>">⭐ <?= t('My Reviews','Mes Avis') ?></a>
      <a href="?tab=offers"       class="cdash-tab <?= $tab==='offers'       ? 'active' : '' ?>">🎟️ <?= t('My Offers','Mes Offres') ?></a>
      <a href="?tab=preferences"  class="cdash-tab <?= $tab==='preferences'  ? 'active' : '' ?>">⚙️ <?= t('Preferences','Préférences') ?></a>
    </nav>

    <!-- ─── MY BOOKINGS ─────────────────────────── -->
    <?php if ($tab === 'bookings'): ?>

      <?php
      // Show upcoming confirmed bookings as a banner
      $upcomingBookings = array_filter($myBookings, fn($b) =>
          $b['status'] === 'confirmed' &&
          !empty($b['preferred_date']) &&
          strtotime($b['preferred_date']) >= strtotime('today')
      );
      usort($upcomingBookings, fn($a,$b) => strtotime($a['preferred_date']) <=> strtotime($b['preferred_date']));
      $nextBooking = reset($upcomingBookings);
      ?>

      <?php if ($nextBooking): ?>
      <div class="upcoming-banner">
        <div class="upcoming-banner-icon"><?= $nextBooking['cat_icon'] ?? '📅' ?></div>
        <div class="upcoming-banner-text">
          <strong><?= t('Upcoming booking:', 'Réservation à venir :') ?> <?= e($nextBooking['listing_title']) ?></strong>
          <span>
            <?= date('D d M Y', strtotime($nextBooking['preferred_date'])) ?>
            <?php if ($nextBooking['preferred_time']): ?> · <?= e(substr($nextBooking['preferred_time'], 0, 5)) ?><?php endif; ?>
            · <?= e($nextBooking['reference']) ?>
          </span>
        </div>
      </div>
      <?php endif; ?>

      <div class="cdash-card">
        <div class="cdash-card-header">
          <h4>📅 <?= t('My Bookings','Mes Réservations') ?> <?php if ($myBookings): ?><span class="cdash-tab-badge"><?= count($myBookings) ?></span><?php endif; ?></h4>
          <a href="<?= SITE_URL ?>/listings" class="btn btn-outline btn-sm"><?= t('Find Businesses','Trouver des Entreprises') ?></a>
        </div>

        <?php if ($myBookings): ?>
        <div style="overflow-x:auto;">
          <table class="data-table">
            <thead>
              <tr>
                <th><?= t('Business','Entreprise') ?></th>
                <th><?= t('Service','Service') ?></th>
                <th><?= t('Date','Date') ?></th>
                <th><?= t('Status','Statut') ?></th>
                <th><?= t('Ref','Réf') ?></th>
                <th><?= t('Actions','Actions') ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($myBookings as $b): ?>
              <tr>
                <td>
                  <div style="display:flex;align-items:center;gap:.5rem;">
                    <span><?= $b['cat_icon'] ?? '🏢' ?></span>
                    <div>
                      <div style="font-weight:500;font-size:.875rem;color:var(--white);"><?= e($b['listing_title']) ?></div>
                      <?php if ($b['preferred_date']): ?>
                      <div style="font-size:.75rem;color:var(--muted);"><?= date('d M Y', strtotime($b['preferred_date'])) ?><?php if ($b['preferred_time']): ?> <?= e(substr($b['preferred_time'],0,5)) ?><?php endif; ?></div>
                      <?php endif; ?>
                    </div>
                  </div>
                </td>
                <td style="font-size:.825rem;color:var(--muted-2);"><?= e($b['service_name'] ?: ($b['booking_type'] ?: '—')) ?></td>
                <td style="font-size:.8rem;color:var(--muted-2);"><?= $b['preferred_date'] ? date('d M', strtotime($b['preferred_date'])) : '—' ?></td>
                <td>
                  <span class="badge badge-<?= bookingStatusClass($b['status']) ?>">
                    <?= bookingStatusLabel($b['status'], $isFr) ?>
                  </span>
                </td>
                <td style="font-size:.75rem;color:var(--yellow);font-family:monospace;"><?= e($b['reference']) ?></td>
                <td>
                  <div style="display:flex;gap:.35rem;flex-wrap:wrap;">
                    <a href="<?= SITE_URL ?>/listing/<?= e($b['listing_slug']) ?>" class="btn btn-outline btn-sm"><?= t('View','Voir') ?></a>
                    <?php if ($b['listing_phone']): ?>
                    <a href="tel:<?= e($b['listing_phone']) ?>" class="btn btn-sm" style="background:rgba(0,168,120,.1);color:var(--green);border:1px solid rgba(0,168,120,.25);">📞</a>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="cdash-empty">
          <div class="cdash-empty-icon">📅</div>
          <h3><?= t('No bookings yet','Aucune réservation') ?></h3>
          <p><?= t('When you book a service, it will appear here.','Vos réservations apparaîtront ici.') ?></p>
          <a href="<?= SITE_URL ?>/listings" class="btn btn-primary" style="margin-top:1rem;"><?= t('Find Businesses','Trouver des Entreprises') ?></a>
        </div>
        <?php endif; ?>
      </div>

    <?php endif; ?>

    <!-- ─── MY EVENT REGISTRATIONS ───────────────── -->
    <?php if ($tab === 'events'): ?>

      <?php
      $upcomingEvents = array_filter($myEventRegs, fn($r) =>
          in_array($r['status'], ['confirmed','registered']) &&
          !empty($r['start_date']) &&
          strtotime($r['start_date']) >= strtotime('today')
      );
      usort($upcomingEvents, fn($a,$b) => strtotime($a['start_date']) <=> strtotime($b['start_date']));
      $nextEvent = reset($upcomingEvents);
      ?>

      <?php if ($nextEvent): ?>
      <div class="upcoming-banner">
        <div class="upcoming-banner-icon">🎟</div>
        <div class="upcoming-banner-text">
          <strong><?= t('Next event:', 'Prochain événement :') ?> <?= e($isFr && $nextEvent['title_fr'] ? $nextEvent['title_fr'] : $nextEvent['title_en']) ?></strong>
          <span><?= date('D d M Y', strtotime($nextEvent['start_date'])) ?> · <?= e($nextEvent['location_text'] ?? '') ?></span>
        </div>
      </div>
      <?php endif; ?>

      <div class="cdash-card">
        <div class="cdash-card-header">
          <h4>🎟 <?= t('My Event Registrations','Mes Inscriptions') ?> <?php if ($myEventRegs): ?><span class="cdash-tab-badge"><?= count($myEventRegs) ?></span><?php endif; ?></h4>
          <a href="<?= SITE_URL ?>/events" class="btn btn-outline btn-sm"><?= t('Browse Events','Voir les Événements') ?></a>
        </div>

        <?php if ($myEventRegs): ?>
        <div style="overflow-x:auto;">
          <table class="data-table">
            <thead>
              <tr>
                <th><?= t('Event','Événement') ?></th>
                <th><?= t('Date','Date') ?></th>
                <th><?= t('Location','Lieu') ?></th>
                <th><?= t('Status','Statut') ?></th>
                <th><?= t('Actions','Actions') ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($myEventRegs as $r): ?>
              <?php $eTitle = $isFr && $r['title_fr'] ? $r['title_fr'] : $r['title_en']; ?>
              <?php $isPast = !empty($r['start_date']) && strtotime($r['start_date']) < strtotime('today'); ?>
              <tr style="<?= $isPast ? 'opacity:.65;' : '' ?>">
                <td>
                  <div style="font-weight:500;font-size:.875rem;color:var(--white);"><?= e($eTitle) ?></div>
                  <?php if ($r['organiser_name']): ?>
                  <div style="font-size:.75rem;color:var(--muted);"><?= e($r['organiser_name']) ?></div>
                  <?php endif; ?>
                </td>
                <td style="font-size:.82rem;color:var(--muted-2);">
                  <?= $r['start_date'] ? date('d M Y', strtotime($r['start_date'])) : '—' ?>
                </td>
                <td style="font-size:.82rem;color:var(--muted-2);">
                  <?= e(mb_strimwidth($r['location_text'] ?? '—', 0, 28, '…')) ?>
                </td>
                <td>
                  <?php
                  $rStatus = $r['status'] ?? 'registered';
                  $rClass  = in_array($rStatus, ['confirmed','registered','attended']) ? 'approved'
                           : ($rStatus === 'cancelled' ? 'rejected' : 'pending');
                  $rLabel  = match($rStatus) {
                    'confirmed'  => t('Confirmed','Confirmée'),
                    'registered' => t('Registered','Inscrit·e'),
                    'attended'   => t('Attended','Présent·e'),
                    'cancelled'  => t('Cancelled','Annulée'),
                    default      => ucfirst($rStatus),
                  };
                  ?>
                  <span class="badge badge-<?= $rClass ?>"><?= $rLabel ?></span>
                </td>
                <td>
                  <div style="display:flex;gap:.35rem;flex-wrap:wrap;">
                    <?php if ($r['event_slug']): ?>
                    <a href="<?= SITE_URL ?>/event/<?= e($r['event_slug']) ?>" class="btn btn-outline btn-sm"><?= t('View','Voir') ?></a>
                    <?php endif; ?>
                    <?php if (!empty($r['qr_token'])): ?>
                    <a href="https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=<?= urlencode($r['qr_token']) ?>&color=08472F&bgcolor=ffffff"
                       target="_blank" rel="noopener"
                       class="btn btn-sm" style="background:rgba(0,168,120,.1);color:var(--green);border:1px solid rgba(0,168,120,.25);">📱 QR</a>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="cdash-empty">
          <div class="cdash-empty-icon">🎟</div>
          <h3><?= t('No event registrations','Aucune inscription à un événement') ?></h3>
          <p><?= t('Registered events will appear here.','Vos inscriptions aux événements apparaîtront ici.') ?></p>
          <a href="<?= SITE_URL ?>/events" class="btn btn-primary" style="margin-top:1rem;"><?= t('Browse Events','Voir les Événements') ?></a>
        </div>
        <?php endif; ?>
      </div>

    <?php endif; ?>

    <!-- ─── FAVOURITES ───────────────────────────── -->
    <?php if ($tab === 'favourites'): ?>

      <!-- Sub-filter tabs -->
      <?php $favTab = $_GET['fav'] ?? 'listing'; if (!in_array($favTab, ['listing','offer','event'])) $favTab = 'listing'; ?>
      <nav style="display:flex;gap:6px;margin-bottom:1.25rem;flex-wrap:wrap;">
        <?php foreach (['listing'=>['🏢',t('Businesses','Entreprises')], 'offer'=>['🏷',t('Offers','Offres')], 'event'=>['🎉',t('Events','Événements')]] as $ft=>[$fi,$fl]): ?>
        <a href="?tab=favourites&fav=<?= $ft ?>"
           class="btn btn-sm <?= $favTab===$ft ? 'btn-primary' : 'btn-outline' ?>">
          <?= $fi ?> <?= $fl ?>
          <?php if ($favCounts[$ft]): ?><span class="cdash-tab-badge"><?= $favCounts[$ft] ?></span><?php endif; ?>
        </a>
        <?php endforeach; ?>
      </nav>

      <div class="cdash-card">
        <div class="cdash-card-header">
          <h4>❤️ <?= t('Saved','Enregistrés') ?></h4>
          <?php if ($favTab === 'listing'): ?>
          <a href="<?= SITE_URL ?>/listings" class="btn btn-outline btn-sm"><?= t('Find More','Trouver plus') ?></a>
          <?php elseif ($favTab === 'offer'): ?>
          <a href="<?= SITE_URL ?>/offers" class="btn btn-outline btn-sm"><?= t('Browse Offers','Voir les offres') ?></a>
          <?php else: ?>
          <a href="<?= SITE_URL ?>/events" class="btn btn-outline btn-sm"><?= t('Browse Events','Voir les événements') ?></a>
          <?php endif; ?>
        </div>

        <?php if ($myFavs[$favTab]): ?>
        <div class="fav-grid">
          <?php foreach ($myFavs[$favTab] as $fav): ?>

            <?php if ($favTab === 'listing'): ?>
            <div class="fav-card">
              <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.4rem;">
                <span style="font-size:1.3rem;"><?= $fav['cat_icon'] ?? '🏢' ?></span>
                <?php if ($fav['status'] !== 'approved'): ?>
                <span class="fav-badge-inactive"><?= t('Unavailable','Indisponible') ?></span>
                <?php endif; ?>
              </div>
              <div class="fav-card-title"><?= e($fav['title']) ?></div>
              <div class="fav-card-meta"><?= e($fav['cat_en']) ?> · <?= e($fav['loc_en']) ?></div>
              <div class="fav-card-actions">
                <?php if ($fav['status'] === 'approved'): ?>
                <a href="<?= SITE_URL ?>/listing/<?= e($fav['slug']) ?>" class="btn btn-outline btn-sm"><?= t('View','Voir') ?></a>
                <?php endif; ?>
                <button class="fav-unsave-btn" onclick="unsaveFav(<?= $fav['fav_id'] ?>, this)">✕ <?= t('Remove','Retirer') ?></button>
              </div>
            </div>

            <?php elseif ($favTab === 'offer'): ?>
            <?php $expired = $fav['valid_until'] && strtotime($fav['valid_until']) < time(); ?>
            <div class="fav-card">
              <?php if ($fav['discount_pct']): ?>
              <div style="font-size:.75rem;font-weight:700;color:var(--green);margin-bottom:.25rem;">-<?= $fav['discount_pct'] ?>%</div>
              <?php endif; ?>
              <?php if ($expired): ?><span class="fav-badge-expired"><?= t('Expired','Expiré') ?></span><?php endif; ?>
              <div class="fav-card-title"><?= e($isFr && $fav['title_fr'] ? $fav['title_fr'] : $fav['title_en']) ?></div>
              <div class="fav-card-meta"><?= e($fav['listing_title']) ?></div>
              <?php if ($fav['offer_price_xaf']): ?>
              <div style="font-family:'Fraunces',serif;font-weight:700;color:var(--white);font-size:.9rem;margin-top:.3rem;">
                <?= number_format($fav['offer_price_xaf']) ?> XAF
              </div>
              <?php endif; ?>
              <div class="fav-card-actions">
                <?php if (!$expired && $fav['slug']): ?>
                <a href="<?= SITE_URL ?>/offer/<?= e($fav['slug']) ?>" class="btn btn-outline btn-sm"><?= t('View','Voir') ?></a>
                <?php endif; ?>
                <button class="fav-unsave-btn" onclick="unsaveFav(<?= $fav['fav_id'] ?>, this)">✕ <?= t('Remove','Retirer') ?></button>
              </div>
            </div>

            <?php elseif ($favTab === 'event'): ?>
            <?php $eventPast = $fav['end_date'] && strtotime($fav['end_date']) < time(); ?>
            <div class="fav-card">
              <?php if ($eventPast): ?><span class="fav-badge-inactive"><?= t('Past','Passé') ?></span><?php endif; ?>
              <?php if ($fav['status'] === 'cancelled'): ?><span class="fav-badge-expired"><?= t('Cancelled','Annulé') ?></span><?php endif; ?>
              <div class="fav-card-title"><?= e($isFr && $fav['title_fr'] ? $fav['title_fr'] : $fav['title_en']) ?></div>
              <div class="fav-card-meta">
                <?= $fav['start_date'] ? date('d M Y', strtotime($fav['start_date'])) : '' ?>
                <?php if ($fav['location_text']): ?> · <?= e(mb_strimwidth($fav['location_text'],0,24,'…')) ?><?php endif; ?>
              </div>
              <div class="fav-card-meta" style="margin-top:.2rem;"><?= e($fav['listing_title']) ?></div>
              <div class="fav-card-actions">
                <?php if ($fav['slug']): ?>
                <a href="<?= SITE_URL ?>/event/<?= e($fav['slug']) ?>" class="btn btn-outline btn-sm"><?= t('View','Voir') ?></a>
                <?php endif; ?>
                <button class="fav-unsave-btn" onclick="unsaveFav(<?= $fav['fav_id'] ?>, this)">✕ <?= t('Remove','Retirer') ?></button>
              </div>
            </div>
            <?php endif; ?>

          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="cdash-empty">
          <div class="cdash-empty-icon">❤️</div>
          <h3><?= t('Nothing saved yet','Rien d\'enregistré') ?></h3>
          <p><?= t('Tap the heart icon on listings, offers and events to save them here.','Appuyez sur le cœur sur les annonces, offres et événements pour les enregistrer ici.') ?></p>
        </div>
        <?php endif; ?>
      </div>

    <?php endif; ?>

    <!-- ─── BUSINESSES I FOLLOW ────────────────────── -->
    <?php if ($tab === 'follows'): ?>

      <div class="cdash-card">
        <div class="cdash-card-header">
          <h4>🔔 <?= t('Businesses I Follow','Entreprises Suivies') ?> <?php if ($myFollows): ?><span class="cdash-tab-badge"><?= count($myFollows) ?></span><?php endif; ?></h4>
          <a href="<?= SITE_URL ?>/listings" class="btn btn-outline btn-sm"><?= t('Find Businesses','Trouver des Entreprises') ?></a>
        </div>

        <?php if ($myFollows): ?>
        <div class="fav-grid">
          <?php foreach ($myFollows as $fw): ?>
          <div class="fav-card" id="follow-card-<?= $fw['follow_row_id'] ?>">
            <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.4rem;">
              <span style="font-size:1.3rem;"><?= $fw['cat_icon'] ?? '🏢' ?></span>
              <?php if ($fw['status'] !== 'approved'): ?>
              <span class="fav-badge-inactive"><?= t('Unlisted','Non répertoriée') ?></span>
              <?php endif; ?>
            </div>
            <div class="fav-card-title"><?= e($fw['title']) ?></div>
            <div class="fav-card-meta"><?= e($fw['cat_en']) ?><?= $fw['loc_en'] ? ' · '.e($fw['loc_en']) : '' ?></div>
            <div class="fav-card-meta" style="margin-top:.2rem;">
              <?= t('Following since','Suivi depuis') ?> <?= date('d M Y', strtotime($fw['followed_at'])) ?>
            </div>
            <div class="fav-card-actions">
              <?php if ($fw['status'] === 'approved'): ?>
              <a href="<?= SITE_URL ?>/listing/<?= e($fw['slug']) ?>" class="btn btn-outline btn-sm"><?= t('View','Voir') ?></a>
              <?php endif; ?>
              <button class="fav-unsave-btn"
                      onclick="unfollowBiz(<?= $fw['follow_row_id'] ?>, <?= $fw['listing_id'] ?>, this)">
                🔕 <?= t('Unfollow','Ne plus suivre') ?>
              </button>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="cdash-empty">
          <div class="cdash-empty-icon">🔔</div>
          <h3><?= t('Not following anyone yet','Aucun abonnement') ?></h3>
          <p><?= t('Follow businesses to get updates on their offers and events.','Suivez des entreprises pour recevoir leurs mises à jour.') ?></p>
          <a href="<?= SITE_URL ?>/listings" class="btn btn-primary" style="margin-top:1rem;"><?= t('Find Businesses','Trouver des Entreprises') ?></a>
        </div>
        <?php endif; ?>
      </div>

    <?php endif; ?>

    <!-- ─── PREFERENCES ─────────────────────────── -->
    <?php if ($tab === 'preferences'): ?>

      <?php if ($prefsFlash === 'saved'): ?>
      <div class="alert alert-success" style="margin-bottom:1.25rem;">✅ <?= t('Preferences saved.','Préférences enregistrées.') ?></div>
      <?php elseif ($prefsFlash === 'error'): ?>
      <div class="alert alert-error" style="margin-bottom:1.25rem;">⚠️ <?= t('Could not save. Please try again.','Impossible d\'enregistrer. Réessayez.') ?></div>
      <?php endif; ?>

      <!-- My Profile card -->
      <div class="cdash-card" style="margin-bottom:1.25rem;">
        <div class="cdash-card-header">
          <h4>👤 <?= t('My Profile','Mon Profil') ?></h4>
        </div>
        <div style="padding:1.25rem;">
          <table style="width:100%;border-collapse:collapse;font-size:.9rem;">
            <tr>
              <td style="padding:.55rem .5rem;color:var(--muted);width:38%;vertical-align:top;"><?= t('Name','Nom') ?></td>
              <td style="padding:.55rem .5rem;font-weight:600;"><?= e($u['name'] ?? '') ?></td>
            </tr>
            <tr style="border-top:1px solid var(--border);">
              <td style="padding:.55rem .5rem;color:var(--muted);vertical-align:top;"><?= t('Email','E-mail') ?></td>
              <td style="padding:.55rem .5rem;">
                <?= e($u['email'] ?? '') ?>
                <?php if (!empty($u['verified'])): ?>
                  <span style="display:inline-flex;align-items:center;gap:3px;background:rgba(16,185,129,.12);color:#10b981;font-size:.72rem;font-weight:600;padding:2px 7px;border-radius:99px;margin-left:6px;">✅ <?= t('Verified','Vérifié') ?></span>
                <?php else: ?>
                  <span style="display:inline-flex;align-items:center;gap:3px;background:rgba(245,158,11,.12);color:#f59e0b;font-size:.72rem;font-weight:600;padding:2px 7px;border-radius:99px;margin-left:6px;">⚠️ <?= t('Not verified','Non vérifié') ?></span>
                  <br><a href="<?= SITE_URL ?>/verify-email.php?resend=1" style="font-size:.8rem;color:var(--accent);margin-top:4px;display:inline-block;"><?= t('Resend verification email','Renvoyer l\'e-mail de vérification') ?></a>
                <?php endif; ?>
              </td>
            </tr>
            <tr style="border-top:1px solid var(--border);">
              <td style="padding:.55rem .5rem;color:var(--muted);vertical-align:middle;"><?= t('Language','Langue') ?></td>
              <td style="padding:.55rem .5rem;">
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                  <a href="?tab=preferences&lang=en" style="padding:4px 14px;border-radius:99px;font-size:.82rem;font-weight:600;text-decoration:none;<?= (lang()==='en') ? 'background:var(--accent);color:#fff;' : 'border:1px solid var(--border);color:var(--muted);' ?>">English</a>
                  <a href="?tab=preferences&lang=fr" style="padding:4px 14px;border-radius:99px;font-size:.82rem;font-weight:600;text-decoration:none;<?= (lang()==='fr') ? 'background:var(--accent);color:#fff;' : 'border:1px solid var(--border);color:var(--muted);' ?>">Français</a>
                </div>
              </td>
            </tr>
          </table>
        </div>
      </div>

      <div class="cdash-card">
        <div class="cdash-card-header">
          <h4>⚙️ <?= t('Notification Preferences','Préférences de Notification') ?></h4>
        </div>
        <form method="POST" style="padding:1.25rem;">
          <?= csrf() ? '<input type="hidden" name="csrf" value="'.csrf().'">' : '' ?>
          <input type="hidden" name="save_prefs" value="1">

          <?php
          $prefRows = [
            ['booking_email','booking_bell', t('Bookings','Réservations'),        t('Receive email updates on your bookings','Mises à jour e-mail de vos réservations')],
            ['event_email',  'event_bell',   t('Events','Événements'),             t('Reminders and updates for registered events','Rappels et mises à jour pour les événements')],
            ['follow_email', 'follow_bell',  t('Followed Businesses','Abonnements'),t('New offers and events from businesses you follow','Nouvelles offres et événements des entreprises suivies')],
            ['promo_email',  null,            t('Promotions','Promotions'),          t('Special offers and platform news','Offres spéciales et actualités de la plateforme')],
          ];
          ?>

          <table style="width:100%;border-collapse:collapse;">
            <thead>
              <tr>
                <th style="text-align:left;padding:.6rem .5rem;font-size:.8rem;color:var(--muted);font-weight:600;"><?= t('Notification type','Type') ?></th>
                <th style="text-align:center;padding:.6rem .5rem;font-size:.8rem;color:var(--muted);font-weight:600;">✉️ <?= t('Email','Email') ?></th>
                <th style="text-align:center;padding:.6rem .5rem;font-size:.8rem;color:var(--muted);font-weight:600;">🔔 <?= t('In-app','In-app') ?></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($prefRows as [$emailCol, $bellCol, $label, $desc]): ?>
            <tr style="border-top:1px solid var(--border);">
              <td style="padding:.85rem .5rem;">
                <div style="font-weight:600;font-size:.875rem;color:var(--white);"><?= $label ?></div>
                <div style="font-size:.775rem;color:var(--muted);"><?= $desc ?></div>
              </td>
              <td style="text-align:center;padding:.85rem .5rem;">
                <label style="display:inline-flex;align-items:center;cursor:pointer;gap:.35rem;">
                  <input type="checkbox" name="<?= $emailCol ?>" value="1" <?= ($notifPrefs[$emailCol] ?? 1) ? 'checked' : '' ?>
                         style="width:1rem;height:1rem;accent-color:var(--green);">
                </label>
              </td>
              <td style="text-align:center;padding:.85rem .5rem;">
                <?php if ($bellCol): ?>
                <label style="display:inline-flex;align-items:center;cursor:pointer;gap:.35rem;">
                  <input type="checkbox" name="<?= $bellCol ?>" value="1" <?= ($notifPrefs[$bellCol] ?? 1) ? 'checked' : '' ?>
                         style="width:1rem;height:1rem;accent-color:var(--green);">
                </label>
                <?php else: ?>
                <span style="color:var(--muted);font-size:.8rem;">—</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>

          <div style="margin-top:1.5rem;display:flex;gap:.75rem;flex-wrap:wrap;align-items:center;">
            <button type="submit" class="btn btn-primary"><?= t('Save Preferences','Enregistrer') ?></button>
            <span style="font-size:.8rem;color:var(--muted);"><?= t('Changes take effect immediately.','Les modifications prennent effet immédiatement.') ?></span>
          </div>
        </form>
      </div>

    <?php endif; ?>

    <!-- ─── MY REVIEWS ───────────────────────────── -->
    <?php if ($tab === 'reviews'): ?>

      <div class="cdash-card">
        <div class="cdash-card-header">
          <h4>⭐ <?= t('My Reviews','Mes Avis') ?> <?php if ($myReviews): ?><span class="cdash-tab-badge"><?= count($myReviews) ?></span><?php endif; ?></h4>
        </div>

        <?php if ($myReviews): ?>
        <div style="padding:.5rem 0;">
          <?php foreach ($myReviews as $r): ?>
          <div style="padding:1rem 1.25rem;border-bottom:1px solid var(--border);">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:.75rem;flex-wrap:wrap;">
              <div>
                <div style="font-weight:600;font-size:.9rem;color:var(--white);"><?= e($r['listing_title']) ?></div>
                <div style="display:flex;gap:2px;margin:.3rem 0;">
                  <?php for ($s=1;$s<=5;$s++): ?>
                  <span style="color:<?= $s<=$r['rating'] ? '#f5c842' : 'rgba(255,255,255,.18)' ?>;font-size:.95rem;">★</span>
                  <?php endfor; ?>
                </div>
              </div>
              <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
                <span class="badge badge-<?= $r['status']==='approved'?'approved':($r['status']==='rejected'?'rejected':'pending') ?>">
                  <?= $r['status']==='approved' ? t('Published','Publié') : ($r['status']==='rejected' ? t('Removed','Supprimé') : t('Pending','En attente')) ?>
                </span>
                <span style="font-size:.75rem;color:var(--muted-2);"><?= date('d M Y', strtotime($r['created_at'])) ?></span>
                <a href="<?= SITE_URL ?>/listing/<?= e($r['listing_slug']) ?>" class="btn btn-outline btn-sm"><?= t('View','Voir') ?></a>
              </div>
            </div>
            <?php if ($r['comment']): ?>
            <p style="font-size:.875rem;color:var(--muted-2);margin:.5rem 0 0;"><?= e($r['comment']) ?></p>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="cdash-empty">
          <div class="cdash-empty-icon">⭐</div>
          <h3><?= t('No reviews yet','Aucun avis') ?></h3>
          <p><?= t('After completing a booking or attending an event, you can leave a review.','Après une réservation ou un événement, vous pouvez laisser un avis.') ?></p>
        </div>
        <?php endif; ?>
      </div>

    <?php endif; ?>

    <!-- ─── MY OFFERS ───────────────────────────── -->
    <?php if ($tab === 'offers'): ?>

      <div class="cdash-card">
        <div class="cdash-card-header">
          <h4>🎟️ <?= t('My Offers','Mes Offres') ?> <?php if ($myOffers): ?><span class="cdash-tab-badge"><?= count($myOffers) ?></span><?php endif; ?></h4>
        </div>

        <?php if ($myOffers): ?>
        <div style="padding:.5rem 0;">
          <?php foreach ($myOffers as $o):
            $isActive   = $o['code_status'] === 'active';
            $isRedeemed = $o['code_status'] === 'redeemed';
            $isExpired  = $o['code_status'] === 'expired' || (!empty($o['expires_at']) && strtotime($o['expires_at']) < time());
            $statusLabel = $isRedeemed ? t('Used','Utilisé') : ($isExpired ? t('Expired','Expiré') : t('Active','Actif'));
            $statusClass = $isRedeemed ? 'pending' : ($isExpired ? 'rejected' : 'approved');
            $offerTitle  = ($isFr && !empty($o['title_fr'])) ? $o['title_fr'] : $o['title'];
          ?>
          <div style="padding:1rem 1.25rem;border-bottom:1px solid var(--border);">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:.75rem;flex-wrap:wrap;">
              <div style="flex:1;min-width:0;">
                <div style="font-weight:600;font-size:.9rem;color:var(--white);margin-bottom:.2rem;">
                  <?= e($offerTitle) ?>
                </div>
                <div style="font-size:.8rem;color:var(--muted-2);margin-bottom:.5rem;">
                  <?= e($o['biz_title']) ?>
                </div>
                <!-- Discount code display -->
                <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
                  <code id="code-<?= e($o['code']) ?>" style="background:rgba(0,168,120,.12);border:1px dashed rgba(0,168,120,.4);color:#00A878;padding:.3rem .6rem;border-radius:4px;font-size:.95rem;font-weight:700;letter-spacing:.05em;user-select:all;"><?= e($o['code']) ?></code>
                  <?php if ($isActive): ?>
                  <button onclick="copyCode('<?= e($o['code']) ?>')" title="<?= t('Copy code','Copier le code') ?>" style="background:none;border:1px solid rgba(0,168,120,.35);color:#00A878;border-radius:4px;padding:.25rem .55rem;font-size:.75rem;cursor:pointer;transition:background .15s;" onmouseover="this.style.background='rgba(0,168,120,.12)'" onmouseout="this.style.background='none'">
                    <?= t('Copy','Copier') ?>
                  </button>
                  <?php endif; ?>
                </div>
              </div>
              <div style="display:flex;flex-direction:column;align-items:flex-end;gap:.4rem;flex-shrink:0;">
                <span class="badge badge-<?= $statusClass ?>"><?= $statusLabel ?></span>
                <span style="font-size:.75rem;color:var(--muted-2);"><?= t('Claimed','Obtenu') ?> <?= date('d M Y', strtotime($o['claimed_at'])) ?></span>
                <?php if ($isRedeemed && $o['redeemed_at']): ?>
                <span style="font-size:.72rem;color:var(--muted-2);"><?= t('Used on','Utilisé le') ?> <?= date('d M Y', strtotime($o['redeemed_at'])) ?></span>
                <?php endif; ?>
                <?php if (!empty($o['expires_at']) && $isActive): ?>
                <span style="font-size:.72rem;color:<?= strtotime($o['expires_at']) < (time()+86400*3) ? '#f5a623' : 'var(--muted-2)' ?>;">
                  <?= t('Expires','Expire') ?> <?= date('d M Y', strtotime($o['expires_at'])) ?>
                </span>
                <?php endif; ?>
                <a href="<?= SITE_URL ?>/offer/<?= e($o['offer_slug']) ?>" class="btn btn-outline btn-sm"><?= t('View Offer','Voir l\'offre') ?></a>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="cdash-empty">
          <div class="cdash-empty-icon">🎟️</div>
          <h3><?= t('No offers claimed yet','Aucune offre réclamée') ?></h3>
          <p><?= t('Browse businesses and claim discount codes from their offers page.','Parcourez les entreprises et réclamez des codes de réduction sur leur page d\'offres.') ?></p>
          <a href="<?= SITE_URL ?>/listings" class="btn btn-primary btn-sm" style="margin-top:.75rem;"><?= t('Browse Businesses','Voir les entreprises') ?></a>
        </div>
        <?php endif; ?>
      </div>

    <?php endif; ?>

  </div>
</section>

<script>
function copyCode(code) {
  navigator.clipboard.writeText(code).then(function() {
    // Briefly highlight the code element
    var el = document.getElementById('code-' + code);
    if (el) {
      var orig = el.style.background;
      el.style.background = 'rgba(0,168,120,.3)';
      setTimeout(function(){ el.style.background = orig; }, 600);
    }
  }).catch(function() {
    // Fallback: select the text
    var el = document.getElementById('code-' + code);
    if (el) {
      var range = document.createRange();
      range.selectNodeContents(el);
      window.getSelection().removeAllRanges();
      window.getSelection().addRange(range);
    }
  });
}

function unfollowBiz(rowId, listingId, btn) {
  if (!confirm('<?= t('Unfollow this business?','Ne plus suivre cette entreprise ?') ?>')) return;
  btn.disabled = true;
  fetch('<?= SITE_URL ?>/api/follows', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({action:'unfollow', follow_type:'listing', follow_id:listingId,
                          row_id:rowId, csrf:'<?= csrf() ?>'}),
  })
  .then(r => r.json())
  .then(d => {
    if (d.ok) {
      const card = document.getElementById('follow-card-' + rowId);
      if (card) { card.style.transition='opacity .25s'; card.style.opacity='0'; setTimeout(()=>card.remove(),260); }
    } else { btn.disabled=false; alert(d.error||'Error'); }
  })
  .catch(()=>{ btn.disabled=false; });
}

function unsaveFav(favId, btn) {
  if (!confirm('<?= t('Remove from favourites?','Retirer des favoris ?') ?>')) return;
  btn.disabled = true;
  fetch('<?= SITE_URL ?>/api/favourites.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({action: 'remove', fav_id: favId,
                          csrf: '<?= csrf() ?>'}),
  })
  .then(r => r.json())
  .then(d => {
    if (d.ok) {
      const card = btn.closest('.fav-card');
      card.style.transition = 'opacity .25s';
      card.style.opacity = '0';
      setTimeout(() => card.remove(), 260);
    } else {
      btn.disabled = false;
      alert(d.error || 'Error');
    }
  })
  .catch(() => { btn.disabled = false; });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
