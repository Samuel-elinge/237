<?php
/**
 * marketplace.php — 237Biz Marketplace
 * Landing page: search hero, active offers, upcoming events, bookable businesses.
 */
require_once __DIR__ . '/includes/config.php';

$isFr = lang() === 'fr';

// ── Active offers (up to 8) ───────────────────────────────────────────────
$featuredOffers = [];
try {
    $featuredOffers = db()->query("
        SELECT o.*, l.title AS biz_name, l.slug AS biz_slug, l.logo,
               c.name_en AS cat_en, c.name_fr AS cat_fr, c.icon AS cat_icon,
               loc.name_en AS loc_en, loc.name_fr AS loc_fr
        FROM offers o
        JOIN listings  l   ON l.id  = o.listing_id
        JOIN categories c  ON c.id  = l.category_id
        JOIN locations  loc ON loc.id = l.location_id
        WHERE o.status = 'active'
          AND o.starts_at  <= NOW()
          AND (o.expires_at IS NULL OR o.expires_at > NOW())
        ORDER BY o.created_at DESC
        LIMIT 8
    ")->fetchAll();
} catch (Exception $e) { $featuredOffers = []; }

// ── Upcoming events (up to 6) ─────────────────────────────────────────────
$upcomingEvents = [];
try {
    $upcomingEvents = db()->query("
        SELECT e.*,
               loc.name_en AS loc_en, loc.name_fr AS loc_fr,
               l.title AS biz_name, l.slug AS biz_slug
        FROM events e
        LEFT JOIN locations loc ON loc.id = e.location_id
        LEFT JOIN listings  l   ON l.id  = e.listing_id
        WHERE e.status = 'approved'
          AND e.starts_at > NOW()
        ORDER BY e.starts_at ASC
        LIMIT 6
    ")->fetchAll();
} catch (Exception $e) { $upcomingEvents = []; }

// ── Bookable businesses (up to 8) ─────────────────────────────────────────
$bookables = [];
try {
    $bookables = db()->query("
        SELECT l.*, c.name_en AS cat_en, c.name_fr AS cat_fr, c.icon AS cat_icon,
               loc.name_en AS loc_en, loc.name_fr AS loc_fr,
               (SELECT AVG(r.rating) FROM reviews r WHERE r.listing_id=l.id AND r.status='approved') AS avg_rating
        FROM listings l
        JOIN categories c   ON c.id  = l.category_id
        JOIN locations  loc ON loc.id = l.location_id
        WHERE l.status='approved'
          AND l.featured=1
          AND l.booking_enabled=1
        ORDER BY l.views DESC
        LIMIT 8
    ")->fetchAll();
} catch (Exception $e) { $bookables = []; }

// ── Categories for filter bar ─────────────────────────────────────────────
$cats = [];
try {
    $cats = db()->query("SELECT * FROM categories ORDER BY sort_order")->fetchAll();
} catch (Exception $e) { $cats = []; }

// ── Page meta ─────────────────────────────────────────────────────────────
$pageTitle = t('237Biz Marketplace — Deals, Events & Bookings',
               'Marketplace 237Biz — Offres, Événements & Réservations');
$pageDesc  = t('Discover exclusive deals from Cameroonian businesses, upcoming events in your city, and book services instantly.',
               'Découvrez des offres exclusives d\'entreprises camerounaises, des événements à venir et réservez des services en ligne.');

require_once __DIR__ . '/includes/header.php';
?>
<style>
/* ── Marketplace page ──────────────────────────────────────── */
.mp-hero {
  background: linear-gradient(160deg,#04220c 0%,#081410 55%,#0c2016 100%);
  padding: 4rem 0 3rem;
  text-align: center;
  position: relative;
  overflow: hidden;
}
.mp-hero::before {
  content: '';
  position: absolute; inset: 0;
  background: radial-gradient(ellipse 80% 60% at 50% 0%, rgba(0,168,120,0.14), transparent);
  pointer-events: none;
}
.mp-hero-label {
  display: inline-flex; align-items: center; gap: 0.5rem;
  background: rgba(0,168,120,0.12);
  border: 1px solid rgba(0,168,120,0.3);
  border-radius: 99px;
  padding: 0.3rem 1rem;
  font-size: 0.78rem; font-weight: 600; letter-spacing: 0.04em;
  color: #00A878; text-transform: uppercase;
  margin-bottom: 1.25rem;
}
.mp-hero h1 {
  font-family: 'Fraunces', serif; font-weight: 900;
  font-size: clamp(2rem, 5vw, 3.2rem);
  line-height: 1.1;
  color: #f0faf5;
  margin-bottom: 0.75rem;
}
.mp-hero h1 span { color: #00A878; }
.mp-hero p {
  color: #8db8a8; font-size: 1.05rem;
  max-width: 520px; margin: 0 auto 2rem;
}
.mp-search {
  display: flex; gap: 0; max-width: 600px; margin: 0 auto 1.5rem;
  background: rgba(255,255,255,0.05);
  border: 1px solid rgba(0,168,120,0.3); border-radius: 10px; overflow: hidden;
}
.mp-search input {
  flex: 1; padding: 0.85rem 1.1rem;
  background: transparent; border: none; outline: none;
  color: #f0faf5; font-size: 0.95rem;
}
.mp-search input::placeholder { color: #5a8878; }
.mp-search button {
  padding: 0.85rem 1.5rem;
  background: #00A878; border: none; cursor: pointer;
  color: #fff; font-weight: 700; font-size: 0.9rem;
  transition: background 0.15s;
}
.mp-search button:hover { background: #00c48f; }
.mp-tabs {
  display: flex; justify-content: center; gap: 0.5rem;
  flex-wrap: wrap;
}
.mp-tab {
  padding: 0.45rem 1rem; border-radius: 99px;
  border: 1px solid rgba(0,168,120,0.25);
  color: #8db8a8; font-size: 0.82rem; font-weight: 600;
  text-decoration: none; transition: all 0.15s;
}
.mp-tab:hover, .mp-tab.active {
  background: rgba(0,168,120,0.12);
  border-color: rgba(0,168,120,0.5);
  color: #00A878;
}

/* ── Sections ── */
.mp-section { padding: 2.5rem 0; }
.mp-section-alt { background: rgba(0,168,120,0.02); }
.mp-section-head {
  display: flex; align-items: baseline; justify-content: space-between;
  margin-bottom: 1.25rem; flex-wrap: wrap; gap: 0.5rem;
}
.mp-section-head h2 {
  font-family: 'Fraunces', serif; font-weight: 800;
  font-size: 1.45rem; color: #e8f5ee;
}
.mp-section-head a {
  font-size: 0.82rem; color: #00A878; text-decoration: none; font-weight: 600;
}
.mp-section-head a:hover { text-decoration: underline; }
.mp-section-label {
  font-size: 0.72rem; font-weight: 700; letter-spacing: 0.07em;
  color: #00A878; text-transform: uppercase; margin-bottom: 0.3rem;
}

/* ── Offer cards ── */
.offers-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(270px, 1fr));
  gap: 16px;
}
.offer-card {
  background: rgba(255,255,255,0.03);
  border: 1px solid rgba(0,168,120,0.15);
  border-radius: 12px; overflow: hidden;
  text-decoration: none; display: flex; flex-direction: column;
  transition: border-color 0.15s, transform 0.12s;
}
.offer-card:hover {
  border-color: rgba(0,168,120,0.4);
  transform: translateY(-2px);
}
.offer-img {
  aspect-ratio: 16/7; overflow: hidden;
  background: linear-gradient(135deg,rgba(0,168,120,0.15),rgba(0,168,120,0.05));
  position: relative;
}
.offer-img img { width: 100%; height: 100%; object-fit: cover; }
.offer-badge {
  position: absolute; top: 8px; left: 8px;
  background: #00A878; color: #fff;
  font-size: 0.72rem; font-weight: 800; letter-spacing: 0.03em;
  padding: 0.2rem 0.55rem; border-radius: 99px;
}
.offer-badge.bogo   { background: #7c3aed; }
.offer-badge.bundle { background: #0369a1; }
.offer-body { padding: 0.9rem 1rem; flex: 1; display: flex; flex-direction: column; gap: 0.35rem; }
.offer-biz { font-size: 0.75rem; color: #5a8878; }
.offer-title { font-weight: 700; color: #e8f5ee; font-size: 0.95rem; line-height: 1.3; }
.offer-desc  { font-size: 0.8rem; color: #8db8a8; flex: 1; line-height: 1.45; }
.offer-meta {
  display: flex; align-items: center; justify-content: space-between;
  margin-top: 0.5rem;
}
.offer-expires { font-size: 0.72rem; color: #5a8878; }
.offer-cta {
  font-size: 0.78rem; font-weight: 700; color: #00A878;
  background: rgba(0,168,120,0.1); border-radius: 99px;
  padding: 0.2rem 0.7rem;
}

/* ── Event cards ── */
.events-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 16px;
}
.event-card {
  background: rgba(255,255,255,0.03);
  border: 1px solid rgba(100,150,120,0.15);
  border-radius: 12px; overflow: hidden;
  text-decoration: none; display: flex; flex-direction: column;
  transition: border-color 0.15s, transform 0.12s;
}
.event-card:hover {
  border-color: rgba(0,168,120,0.4);
  transform: translateY(-2px);
}
.event-img {
  aspect-ratio: 16/7; overflow: hidden;
  background: linear-gradient(135deg,rgba(0,100,168,0.15),rgba(0,100,168,0.05));
}
.event-img img { width: 100%; height: 100%; object-fit: cover; }
.event-img-placeholder {
  width: 100%; height: 100%;
  display: flex; align-items: center; justify-content: center;
  font-size: 2.5rem;
}
.event-body { padding: 0.9rem 1rem; flex: 1; display: flex; flex-direction: column; gap: 0.35rem; }
.event-date {
  font-size: 0.72rem; font-weight: 700; letter-spacing: 0.04em;
  color: #60a080; text-transform: uppercase;
}
.event-title { font-weight: 700; color: #e8f5ee; font-size: 0.95rem; line-height: 1.3; }
.event-meta  { display: flex; gap: 0.6rem; align-items: center; flex-wrap: wrap; margin-top: auto; }
.event-pill  {
  font-size: 0.7rem; padding: 0.15rem 0.55rem; border-radius: 99px;
  background: rgba(0,168,120,0.1); color: #60a080; font-weight: 600;
}
.event-pill.free { background: rgba(34,197,94,0.12); color: #4ade80; }
.event-pill.online { background: rgba(99,102,241,0.12); color: #a5b4fc; }

/* ── Bookable business cards ── */
.book-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 16px;
}
.book-card {
  background: rgba(255,255,255,0.03);
  border: 1px solid rgba(0,168,120,0.12);
  border-radius: 12px; overflow: hidden;
  text-decoration: none; display: flex; flex-direction: column;
  transition: border-color 0.15s, transform 0.12s;
}
.book-card:hover {
  border-color: rgba(0,168,120,0.35);
  transform: translateY(-2px);
}
.book-logo {
  aspect-ratio: 16/6; background: rgba(0,168,120,0.06);
  display: flex; align-items: center; justify-content: center;
  overflow: hidden;
}
.book-logo img { max-height: 80px; max-width: 80%; object-fit: contain; }
.book-logo-placeholder { font-size: 2.2rem; }
.book-body { padding: 0.9rem 1rem; flex: 1; display: flex; flex-direction: column; gap: 0.3rem; }
.book-cat  { font-size: 0.72rem; color: #5a8878; }
.book-title { font-weight: 700; color: #e8f5ee; font-size: 0.95rem; }
.book-loc   { font-size: 0.78rem; color: #5a8878; }
.book-btn {
  margin: 0.6rem 1rem 1rem;
  display: block; text-align: center;
  background: #00A878; color: #fff; border-radius: 8px;
  padding: 0.55rem; font-weight: 700; font-size: 0.85rem;
  text-decoration: none; transition: background 0.15s;
}
.book-btn:hover { background: #00c48f; }

/* ── Empty state ── */
.mp-empty {
  text-align: center; padding: 2.5rem 1rem;
  color: #5a8878; font-size: 0.9rem;
}
.mp-empty span { font-size: 2rem; display: block; margin-bottom: 0.5rem; }

/* ── CTA banner ── */
.mp-cta {
  background: linear-gradient(135deg,rgba(0,168,120,0.12),rgba(0,168,120,0.05));
  border: 1px solid rgba(0,168,120,0.2); border-radius: 16px;
  padding: 2.5rem; text-align: center; margin: 2rem 0;
}
.mp-cta h3 {
  font-family: 'Fraunces', serif; font-weight: 800;
  font-size: 1.5rem; color: #e8f5ee; margin-bottom: 0.5rem;
}
.mp-cta p { color: #8db8a8; margin-bottom: 1.25rem; }
.mp-cta-btns { display: flex; gap: 0.75rem; justify-content: center; flex-wrap: wrap; }
.mp-cta-btn {
  padding: 0.65rem 1.4rem; border-radius: 8px;
  font-weight: 700; font-size: 0.9rem; text-decoration: none;
  transition: all 0.15s;
}
.mp-cta-btn.primary { background: #00A878; color: #fff; }
.mp-cta-btn.primary:hover { background: #00c48f; }
.mp-cta-btn.outline {
  border: 1px solid rgba(0,168,120,0.4); color: #00A878;
}
.mp-cta-btn.outline:hover { background: rgba(0,168,120,0.1); }
</style>

<!-- ── Hero ─────────────────────────────────────────────────── -->
<section class="mp-hero">
  <div class="container">
    <div class="mp-hero-label">🛍️ <?= t('Marketplace','Marketplace') ?></div>
    <h1><?= t('Deals, Events &<br><span>Bookings</span> in One Place',
               'Offres, Événements &<br><span>Réservations</span> en un endroit') ?></h1>
    <p><?= t('Exclusive offers from Cameroonian businesses, events in your city, and instant booking — all in one marketplace.',
             'Offres exclusives, événements dans votre ville et réservation instantanée — tout en un seul endroit.') ?></p>

    <form action="<?= SITE_URL ?>/marketplace" method="GET" class="mp-search">
      <input type="text" name="q" placeholder="<?= t('Search deals, events, businesses…','Rechercher offres, événements, entreprises…') ?>"
             value="<?= htmlspecialchars($_GET['q'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
      <button type="submit">🔍 <?= t('Search','Rechercher') ?></button>
    </form>

    <div class="mp-tabs">
      <a href="<?= SITE_URL ?>/marketplace" class="mp-tab active">🏠 <?= t('All','Tout') ?></a>
      <a href="<?= SITE_URL ?>/offers"      class="mp-tab">🏷️ <?= t('Deals & Offers','Offres') ?></a>
      <a href="<?= SITE_URL ?>/events"      class="mp-tab">📅 <?= t('Events','Événements') ?></a>
      <a href="<?= SITE_URL ?>/listings?booking=1" class="mp-tab">📅 <?= t('Bookable','Réservable') ?></a>
    </div>
  </div>
</section>

<?php $searchQ = trim($_GET['q'] ?? ''); ?>

<div class="container">

<?php if ($searchQ): ?>
<!-- ── Search results redirect hint ── -->
<div style="padding:1.5rem 0 0;color:#8db8a8;font-size:0.9rem;">
  <?= t('Showing results across offers, events and listings for','Résultats pour') ?>
  <strong style="color:#e8f5ee;">"<?= htmlspecialchars($searchQ, ENT_QUOTES, 'UTF-8') ?>"</strong> —
  <a href="<?= SITE_URL ?>/listings?q=<?= urlencode($searchQ) ?>" style="color:#00A878;"><?= t('see all businesses →','voir toutes les entreprises →') ?></a>
</div>
<?php endif; ?>

<!-- ════════════════════════════════════════════════════════ -->
<!-- OFFERS SECTION                                          -->
<!-- ════════════════════════════════════════════════════════ -->
<section class="mp-section">
  <div class="mp-section-head">
    <div>
      <div class="mp-section-label">🏷️ <?= t('Deals & Special Offers','Offres Spéciales') ?></div>
      <h2><?= t('Exclusive Discounts','Réductions Exclusives') ?></h2>
    </div>
    <a href="<?= SITE_URL ?>/offers"><?= t('View all offers →','Voir toutes les offres →') ?></a>
  </div>

<?php if ($featuredOffers): ?>
  <div class="offers-grid">
  <?php foreach ($featuredOffers as $o):
    $offerTitle = $isFr && $o['title_fr'] ? $o['title_fr'] : $o['title'];
    $offerDesc  = $isFr && $o['description_fr'] ? $o['description_fr'] : ($o['description'] ?? '');
    $catName    = $isFr ? $o['cat_fr'] : $o['cat_en'];
    $locName    = $isFr ? $o['loc_fr'] : $o['loc_en'];

    // Badge label
    $badgeLabel = '';
    $badgeClass = '';
    switch ($o['offer_type']) {
        case 'percent_off': $badgeLabel = '-' . (int)$o['discount_value'] . '%'; break;
        case 'fixed_off':   $badgeLabel = number_format($o['discount_value']) . ' XAF ' . t('off','rabais'); break;
        case 'bogo':        $badgeLabel = 'BOGO'; $badgeClass = 'bogo'; break;
        case 'free_item':   $badgeLabel = t('Free Item','Cadeau'); break;
        case 'bundle':      $badgeLabel = 'Bundle'; $badgeClass = 'bundle'; break;
    }
  ?>
    <a href="<?= SITE_URL ?>/offer/<?= htmlspecialchars($o['slug'], ENT_QUOTES, 'UTF-8') ?>" class="offer-card">
      <div class="offer-img">
        <?php if ($o['image']): ?>
          <img src="<?= UPLOAD_URL . htmlspecialchars($o['image'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($offerTitle, ENT_QUOTES, 'UTF-8') ?>" loading="lazy">
        <?php endif; ?>
        <?php if ($badgeLabel): ?>
          <span class="offer-badge <?= $badgeClass ?>"><?= htmlspecialchars($badgeLabel, ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
      </div>
      <div class="offer-body">
        <div class="offer-biz"><?= htmlspecialchars($o['biz_name'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($catName, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="offer-title"><?= htmlspecialchars($offerTitle, ENT_QUOTES, 'UTF-8') ?></div>
        <?php if ($offerDesc): ?>
          <div class="offer-desc"><?= htmlspecialchars(mb_substr(strip_tags($offerDesc), 0, 80), ENT_QUOTES, 'UTF-8') ?>…</div>
        <?php endif; ?>
        <div class="offer-meta">
          <span class="offer-expires">
            <?php if ($o['expires_at']): ?>
              ⏰ <?= t('Ends','Fin') ?> <?= date('d M', strtotime($o['expires_at'])) ?>
            <?php else: ?>
              ✅ <?= t('No expiry','Sans expiration') ?>
            <?php endif; ?>
          </span>
          <span class="offer-cta"><?= t('View Deal','Voir l\'offre') ?> →</span>
        </div>
      </div>
    </a>
  <?php endforeach; ?>
  </div>
<?php else: ?>
  <div class="mp-empty">
    <span>🏷️</span>
    <?= t('No active deals right now — check back soon!','Aucune offre active pour l\'instant — revenez bientôt !') ?>
    <?php if (isLoggedIn()): ?>
      <br><a href="<?= SITE_URL ?>/partner/offers" style="color:#00A878;font-weight:600;margin-top:0.5rem;display:inline-block;">
        + <?= t('Create an offer for your business','Créer une offre pour votre entreprise') ?>
      </a>
    <?php endif; ?>
  </div>
<?php endif; ?>
</section>

<!-- ════════════════════════════════════════════════════════ -->
<!-- EVENTS SECTION                                          -->
<!-- ════════════════════════════════════════════════════════ -->
<section class="mp-section mp-section-alt" style="margin:0 -1rem;padding-left:1rem;padding-right:1rem;">
  <div class="mp-section-head">
    <div>
      <div class="mp-section-label">📅 <?= t('Events & Activities','Événements & Activités') ?></div>
      <h2><?= t('Upcoming in Cameroon','À venir au Cameroun') ?></h2>
    </div>
    <a href="<?= SITE_URL ?>/events"><?= t('View all events →','Voir tous les événements →') ?></a>
  </div>

<?php if ($upcomingEvents): ?>
  <div class="events-grid">
  <?php foreach ($upcomingEvents as $ev):
    $evTitle   = $isFr && $ev['title_fr'] ? $ev['title_fr'] : $ev['title'];
    $evLocName = $isFr ? ($ev['loc_fr'] ?? '') : ($ev['loc_en'] ?? '');
    $evType    = match($ev['event_type']) {
      'workshop'     => '🔧',
      'networking'   => '🤝',
      'exhibition'   => '🎨',
      'conference'   => '🎤',
      'social'       => '🎉',
      default        => '📌'
    };
    $startsTs = strtotime($ev['starts_at']);
  ?>
    <a href="<?= SITE_URL ?>/event/<?= htmlspecialchars($ev['slug'], ENT_QUOTES, 'UTF-8') ?>" class="event-card">
      <div class="event-img">
        <?php if ($ev['image']): ?>
          <img src="<?= UPLOAD_URL . htmlspecialchars($ev['image'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($evTitle, ENT_QUOTES, 'UTF-8') ?>" loading="lazy">
        <?php else: ?>
          <div class="event-img-placeholder"><?= $evType ?></div>
        <?php endif; ?>
      </div>
      <div class="event-body">
        <div class="event-date"><?= date('D d M Y · H:i', $startsTs) ?></div>
        <div class="event-title"><?= htmlspecialchars($evTitle, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="event-meta">
          <?php if ($ev['is_free']): ?>
            <span class="event-pill free">✅ <?= t('Free','Gratuit') ?></span>
          <?php else: ?>
            <span class="event-pill"><?= number_format((float)$ev['ticket_price']) ?> XAF</span>
          <?php endif; ?>
          <?php if ($ev['is_online']): ?>
            <span class="event-pill online">🌐 <?= t('Online','En ligne') ?></span>
          <?php elseif ($evLocName): ?>
            <span class="event-pill">📍 <?= htmlspecialchars($evLocName, ENT_QUOTES, 'UTF-8') ?></span>
          <?php endif; ?>
          <?php if ($ev['capacity']): ?>
            <?php $spotsLeft = max(0, $ev['capacity'] - $ev['registered_count']); ?>
            <span class="event-pill" style="<?= $spotsLeft < 10 ? 'color:#f59e0b;background:rgba(245,158,11,0.1);' : '' ?>">
              <?= $spotsLeft ?> <?= t('spots','places') ?>
            </span>
          <?php endif; ?>
        </div>
      </div>
    </a>
  <?php endforeach; ?>
  </div>
<?php else: ?>
  <div class="mp-empty">
    <span>📅</span>
    <?= t('No upcoming events — check back soon!','Aucun événement à venir — revenez bientôt !') ?>
  </div>
<?php endif; ?>
</section>

<!-- ════════════════════════════════════════════════════════ -->
<!-- BOOKABLE BUSINESSES                                     -->
<!-- ════════════════════════════════════════════════════════ -->
<section class="mp-section">
  <div class="mp-section-head">
    <div>
      <div class="mp-section-label">📆 <?= t('Book Instantly','Réservez Maintenant') ?></div>
      <h2><?= t('Businesses You Can Book Online','Entreprises Réservables en Ligne') ?></h2>
    </div>
    <a href="<?= SITE_URL ?>/listings?booking=1"><?= t('View all →','Voir tout →') ?></a>
  </div>

<?php if ($bookables): ?>
  <div class="book-grid">
  <?php foreach ($bookables as $bk):
    $bkCat = $isFr ? $bk['cat_fr'] : $bk['cat_en'];
    $bkLoc = $isFr ? $bk['loc_fr'] : $bk['loc_en'];
    $bkRating = $bk['avg_rating'] ? round((float)$bk['avg_rating'], 1) : null;
  ?>
    <div class="book-card">
      <div class="book-logo">
        <?php if ($bk['logo']): ?>
          <img src="<?= UPLOAD_URL . htmlspecialchars($bk['logo'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($bk['title'], ENT_QUOTES, 'UTF-8') ?>">
        <?php else: ?>
          <div class="book-logo-placeholder"><?= $bk['cat_icon'] ?? '🏢' ?></div>
        <?php endif; ?>
      </div>
      <div class="book-body">
        <div class="book-cat"><?= htmlspecialchars($bk['cat_icon'] . ' ' . $bkCat, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="book-title"><?= htmlspecialchars($bk['title'], ENT_QUOTES, 'UTF-8') ?></div>
        <div class="book-loc">📍 <?= htmlspecialchars($bkLoc, ENT_QUOTES, 'UTF-8') ?>
          <?php if ($bkRating): ?>
            · ⭐ <?= $bkRating ?>
          <?php endif; ?>
        </div>
      </div>
      <a href="<?= SITE_URL ?>/booking/<?= htmlspecialchars($bk['slug'], ENT_QUOTES, 'UTF-8') ?>" class="book-btn">
        📆 <?= t('Book Now','Réserver Maintenant') ?>
      </a>
    </div>
  <?php endforeach; ?>
  </div>
<?php else: ?>
  <div class="mp-empty">
    <span>📆</span>
    <?= t('No bookable businesses yet.','Aucune entreprise réservable pour l\'instant.') ?>
  </div>
<?php endif; ?>
</section>

<!-- ── Business CTA ── -->
<div class="mp-cta">
  <h3><?= t('Own a Business in Cameroon?','Vous avez une entreprise au Cameroun ?') ?></h3>
  <p><?= t('Post a deal, host an event, or enable online booking — reach thousands of customers on the 237Biz marketplace.',
           'Publiez une offre, organisez un événement ou activez les réservations en ligne — touchez des milliers de clients.') ?></p>
  <div class="mp-cta-btns">
    <a href="<?= SITE_URL ?>/partner/offers" class="mp-cta-btn primary">🏷️ <?= t('Post a Deal','Publier une offre') ?></a>
    <a href="<?= SITE_URL ?>/partner/events" class="mp-cta-btn outline">📅 <?= t('Create an Event','Créer un Événement') ?></a>
    <a href="<?= SITE_URL ?>/add-listing"   class="mp-cta-btn outline">+ <?= t('List Your Business','Lister votre Entreprise') ?></a>
  </div>
</div>

</div><!-- /container -->

<?php require_once __DIR__ . '/includes/footer.php'; ?>
