<?php
/**
 * events.php — 237Biz Browse Events
 * URL: /events
 */
require_once __DIR__ . '/includes/config.php';
$isFr = lang() === 'fr';

// ── Filters ───────────────────────────────────────────────────────────────
$filterType = $_GET['type']     ?? '';
$filterLoc  = $_GET['location'] ?? '';
$filterFree = isset($_GET['free']) ? 1 : null;
$filterDate = $_GET['when']     ?? ''; // upcoming | today | this_week | this_month
$search     = trim($_GET['q']   ?? '');
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 12;

$validTypes = ['workshop','networking','exhibition','conference','social','other'];

// ── Build WHERE ───────────────────────────────────────────────────────────
$where  = ["e.status = 'approved'", "e.starts_at > NOW()"];
$params = [];

if ($filterType && in_array($filterType, $validTypes)) {
    $where[]  = 'e.event_type = ?';
    $params[] = $filterType;
}
if ($filterLoc) {
    $where[]  = 'loc.slug = ?';
    $params[] = $filterLoc;
}
if ($filterFree !== null) {
    $where[]  = 'e.is_free = 1';
}
switch ($filterDate) {
    case 'today':
        $where[] = 'DATE(e.starts_at) = CURDATE()'; break;
    case 'this_week':
        $where[] = 'e.starts_at <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)'; break;
    case 'this_month':
        $where[] = 'MONTH(e.starts_at) = MONTH(NOW()) AND YEAR(e.starts_at) = YEAR(NOW())'; break;
}
if ($search) {
    $where[]  = '(e.title LIKE ? OR e.title_fr LIKE ? OR e.venue_name LIKE ?)';
    $s = '%' . $search . '%';
    array_push($params, $s, $s, $s);
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

// ── Count ─────────────────────────────────────────────────────────────────
try {
    $cnt = db()->prepare("
        SELECT COUNT(*) FROM events e
        LEFT JOIN locations loc ON loc.id = e.location_id
        $whereClause
    ");
    $cnt->execute($params);
    $total = (int)$cnt->fetchColumn();
} catch (Exception $e) { $total = 0; }

$pages = max(1, (int)ceil($total / $perPage));
$page  = min($page, $pages);

// ── Fetch events ──────────────────────────────────────────────────────────
$events = [];
try {
    $st = db()->prepare("
        SELECT e.*,
               loc.name_en AS loc_en, loc.name_fr AS loc_fr, loc.slug AS loc_slug,
               l.title AS biz_name, l.slug AS biz_slug
        FROM events e
        LEFT JOIN locations loc ON loc.id = e.location_id
        LEFT JOIN listings  l   ON l.id  = e.listing_id
        $whereClause
        ORDER BY e.featured DESC, e.starts_at ASC
        LIMIT $perPage OFFSET " . (($page - 1) * $perPage)
    );
    $st->execute($params);
    $events = $st->fetchAll();
} catch (Exception $ex) {}

// ── Locations for filter ──────────────────────────────────────────────────
$locations = [];
try {
    $ls = db()->query("SELECT slug, name_en, name_fr FROM locations ORDER BY name_en");
    $locations = $ls->fetchAll();
} catch (Exception $e) {}

// ── URL helper ────────────────────────────────────────────────────────────
function eventsUrl(array $ov = []): string {
    $p = array_merge([
        'type'     => $_GET['type']     ?? '',
        'location' => $_GET['location'] ?? '',
        'when'     => $_GET['when']     ?? '',
        'free'     => isset($_GET['free']) ? '1' : '',
        'q'        => $_GET['q']        ?? '',
        'page'     => '',
    ], $ov);
    $p = array_filter($p, fn($v) => $v !== '');
    return SITE_URL . '/events' . ($p ? '?' . http_build_query($p) : '');
}

$pageTitle = t('Events & Activities','Événements et Activités') . ' — 237Biz';
require_once __DIR__ . '/includes/header.php';

$typeEmoji = [
    'workshop'    => '🛠',
    'networking'  => '🤝',
    'exhibition'  => '🖼',
    'conference'  => '🎤',
    'social'      => '🎉',
    'other'       => '📌',
];
$typeLabel = [
    'workshop'    => t('Workshop','Atelier'),
    'networking'  => t('Networking','Réseautage'),
    'exhibition'  => t('Exhibition','Exposition'),
    'conference'  => t('Conference','Conférence'),
    'social'      => t('Social','Événement Social'),
    'other'       => t('Other','Autre'),
];
?>
<style>
.ev-hero {
  background:linear-gradient(135deg,rgba(0,168,120,0.12),rgba(8,20,16,0.8));
  border-bottom:1px solid rgba(0,168,120,0.1); padding:3rem 1rem 2rem;
  text-align:center;
}
.ev-hero h1 { font-family:'Fraunces',serif; font-weight:900;
  font-size:clamp(1.8rem,4vw,2.6rem); color:#f0faf5; margin-bottom:0.5rem; }
.ev-hero p  { color:#8db8a8; font-size:0.95rem; margin-bottom:1.5rem; }
.ev-search-row { display:flex; gap:0.5rem; justify-content:center; flex-wrap:wrap; }
.ev-search-row input {
  flex:1; max-width:380px; background:rgba(0,0,0,0.35); border:1px solid rgba(0,168,120,0.25);
  color:#e8f5ee; border-radius:10px; padding:0.65rem 1rem; font-size:0.9rem;
}
.ev-search-row input:focus { outline:none; border-color:#00A878; }
.ev-search-row button {
  background:#00A878; color:#fff; border:none; border-radius:10px;
  padding:0.65rem 1.3rem; font-weight:700; cursor:pointer;
}

.ev-wrap  { max-width:1100px; margin:0 auto; padding:1.5rem 1rem; }
.ev-layout { display:grid; grid-template-columns:220px 1fr; gap:1.5rem; }
@media(max-width:700px) { .ev-layout { grid-template-columns:1fr; } }

/* Sidebar filters */
.ev-filters { display:flex; flex-direction:column; gap:0.5rem; }
.ev-filter-group h4 {
  font-size:0.72rem; font-weight:800; letter-spacing:0.08em;
  color:#5a8878; text-transform:uppercase; margin-bottom:0.4rem;
}
.ev-filter-link {
  display:flex; align-items:center; justify-content:space-between;
  padding:0.35rem 0.65rem; border-radius:7px; font-size:0.82rem;
  text-decoration:none; color:#8db8a8; transition:all 0.12s;
}
.ev-filter-link:hover, .ev-filter-link.active {
  background:rgba(0,168,120,0.12); color:#e8f5ee;
}
.ev-filter-link.active { font-weight:700; }
.ev-filter-divider { border:none; border-top:1px solid rgba(0,168,120,0.1); margin:0.5rem 0; }

/* Grid */
.ev-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(270px,1fr)); gap:1.1rem; }
.ev-card {
  background:rgba(255,255,255,0.03); border:1px solid rgba(0,168,120,0.12);
  border-radius:12px; overflow:hidden; text-decoration:none;
  transition:border-color 0.15s, transform 0.15s; display:flex; flex-direction:column;
}
.ev-card:hover { border-color:rgba(0,168,120,0.4); transform:translateY(-2px); }
.ev-card-img { width:100%; aspect-ratio:16/7; object-fit:cover; background:rgba(0,168,120,0.06); }
.ev-card-thumb {
  width:100%; aspect-ratio:16/7;
  background:linear-gradient(135deg,rgba(0,168,120,0.1),rgba(0,168,120,0.03));
  display:flex; align-items:center; justify-content:center; font-size:3rem;
}
.ev-card-body { padding:1rem; flex:1; display:flex; flex-direction:column; gap:0.4rem; }
.ev-card-date {
  font-size:0.72rem; font-weight:700; letter-spacing:0.05em;
  color:#00A878; text-transform:uppercase;
}
.ev-card-title { font-family:'Fraunces',serif; font-weight:800; font-size:1rem;
  color:#f0faf5; line-height:1.25; }
.ev-card-venue { font-size:0.78rem; color:#5a8878; }
.ev-card-pills { display:flex; flex-wrap:wrap; gap:0.35rem; margin-top:auto; padding-top:0.5rem; }
.ev-pill {
  font-size:0.68rem; font-weight:700; padding:0.2rem 0.55rem;
  border-radius:99px; white-space:nowrap;
}
.ev-pill-free   { background:rgba(74,222,128,0.15); color:#4ade80; }
.ev-pill-paid   { background:rgba(251,191,36,0.15); color:#fbbf24; }
.ev-pill-online { background:rgba(96,165,250,0.15); color:#60a5fa; }
.ev-pill-spots  { background:rgba(0,168,120,0.12);  color:#00A878; }
.ev-pill-type   { background:rgba(255,255,255,0.06); color:#8db8a8; }
.ev-featured-badge {
  position:absolute; top:0.5rem; right:0.5rem;
  background:#00A878; color:#fff; font-size:0.65rem; font-weight:800;
  padding:0.15rem 0.5rem; border-radius:99px; letter-spacing:0.04em;
}
.ev-card-wrap { position:relative; }

.ev-meta-row { display:flex; align-items:center; justify-content:space-between;
  flex-wrap:wrap; gap:0.5rem; margin-bottom:1rem; font-size:0.82rem; color:#5a8878; }
.ev-clear { color:#00A878; text-decoration:none; }

.ev-empty { text-align:center; padding:4rem 1rem; color:#5a8878; }
.ev-empty big { font-size:3rem; display:block; margin-bottom:0.75rem; }

.ev-pagination { display:flex; gap:0.4rem; justify-content:center; flex-wrap:wrap; margin-top:1.5rem; }
.ev-pagination a, .ev-pagination span {
  padding:0.3rem 0.8rem; border-radius:7px; font-size:0.82rem; text-decoration:none;
  background:rgba(255,255,255,0.03); border:1px solid rgba(0,168,120,0.12); color:#8db8a8;
}
.ev-pagination a:hover   { border-color:rgba(0,168,120,0.4); color:#e8f5ee; }
.ev-pagination .cur      { background:#00A878; color:#fff; border-color:#00A878; }

.ev-host-cta {
  margin-top:2.5rem; background:rgba(0,168,120,0.06);
  border:1px solid rgba(0,168,120,0.15); border-radius:12px;
  padding:1.5rem; text-align:center;
}
.ev-host-cta h3 { font-family:'Fraunces',serif; font-weight:800; color:#e8f5ee;
  font-size:1.1rem; margin-bottom:0.4rem; }
.ev-host-cta p  { color:#5a8878; font-size:0.85rem; margin-bottom:1rem; }
.ev-host-btn {
  display:inline-block; background:#00A878; color:#fff;
  padding:0.6rem 1.4rem; border-radius:9px; font-weight:700; font-size:0.88rem;
  text-decoration:none;
}
.ev-host-btn:hover { background:#00c48f; }
</style>

<!-- Hero -->
<div class="ev-hero">
  <h1>📅 <?= t('Events & Activities','Événements et Activités') ?></h1>
  <p><?= t('Workshops, conferences, networking & more across Cameroon','Ateliers, conférences, réseautage et plus à travers le Cameroun') ?></p>
  <form method="GET" action="<?= SITE_URL ?>/events" class="ev-search-row">
    <?php foreach (['type','location','when','free'] as $k): if (!empty($_GET[$k])): ?>
      <input type="hidden" name="<?= $k ?>" value="<?= htmlspecialchars($_GET[$k], ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; endforeach; ?>
    <input type="text" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
           placeholder="<?= t('Search events…','Chercher des événements…') ?>">
    <button type="submit">🔍</button>
  </form>
</div>

<div class="ev-wrap">
  <div class="ev-layout">

    <!-- Sidebar -->
    <aside class="ev-filters">
      <div class="ev-filter-group">
        <h4><?= t('Type','Type') ?></h4>
        <a href="<?= eventsUrl(['type'=>'','page'=>'']) ?>" class="ev-filter-link <?= !$filterType ? 'active' : '' ?>">
          🗂 <?= t('All Types','Tous les Types') ?>
        </a>
        <?php foreach ($validTypes as $vt): ?>
          <a href="<?= eventsUrl(['type'=>$vt,'page'=>'']) ?>" class="ev-filter-link <?= $filterType===$vt?'active':'' ?>">
            <?= $typeEmoji[$vt] ?> <?= $typeLabel[$vt] ?>
          </a>
        <?php endforeach; ?>
      </div>
      <hr class="ev-filter-divider">
      <div class="ev-filter-group">
        <h4><?= t('When','Quand') ?></h4>
        <?php $whens = [
          ''           => t('Any Time','N\'importe Quand'),
          'today'      => t('Today','Aujourd\'hui'),
          'this_week'  => t('This Week','Cette Semaine'),
          'this_month' => t('This Month','Ce Mois-ci'),
        ]; foreach ($whens as $wv => $wl): ?>
          <a href="<?= eventsUrl(['when'=>$wv,'page'=>'']) ?>" class="ev-filter-link <?= $filterDate===$wv?'active':'' ?>">
            <?= $wl ?>
          </a>
        <?php endforeach; ?>
      </div>
      <hr class="ev-filter-divider">
      <div class="ev-filter-group">
        <h4><?= t('Price','Prix') ?></h4>
        <a href="<?= eventsUrl(['free'=>$filterFree?'':'1','page'=>'']) ?>" class="ev-filter-link <?= $filterFree?'active':'' ?>">
          <?= $filterFree ? '☑' : '☐' ?> <?= t('Free Only','Gratuits Seulement') ?>
        </a>
      </div>
      <?php if ($locations): ?>
        <hr class="ev-filter-divider">
        <div class="ev-filter-group">
          <h4><?= t('City','Ville') ?></h4>
          <a href="<?= eventsUrl(['location'=>'','page'=>'']) ?>" class="ev-filter-link <?= !$filterLoc?'active':'' ?>">
            <?= t('All Cities','Toutes les Villes') ?>
          </a>
          <?php foreach ($locations as $lc):
            $lcName = $isFr ? $lc['name_fr'] : $lc['name_en'];
          ?>
            <a href="<?= eventsUrl(['location'=>$lc['slug'],'page'=>'']) ?>"
               class="ev-filter-link <?= $filterLoc===$lc['slug']?'active':'' ?>">
              📍 <?= htmlspecialchars($lcName, ENT_QUOTES, 'UTF-8') ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </aside>

    <!-- Main -->
    <main>
      <!-- Meta row -->
      <div class="ev-meta-row">
        <span><?= $total ?> <?= t('events','événements') ?></span>
        <?php if ($filterType || $filterLoc || $filterDate || $filterFree || $search): ?>
          <a href="<?= SITE_URL ?>/events" class="ev-clear">✕ <?= t('Clear filters','Effacer les filtres') ?></a>
        <?php endif; ?>
      </div>

      <!-- Grid -->
      <?php if ($events): ?>
        <div class="ev-grid">
          <?php foreach ($events as $ev):
            $etitle  = $isFr && $ev['title_fr'] ? $ev['title_fr'] : $ev['title'];
            $locName = $isFr ? $ev['loc_fr'] : $ev['loc_en'];
            $emoji   = $typeEmoji[$ev['event_type']] ?? '📌';
            $tLabel  = $typeLabel[$ev['event_type']]  ?? $ev['event_type'];
            $spotsLeft = $ev['capacity'] ? max(0, $ev['capacity'] - $ev['registered_count']) : null;
          ?>
            <div class="ev-card-wrap">
              <a href="<?= SITE_URL ?>/event/<?= htmlspecialchars($ev['slug'], ENT_QUOTES, 'UTF-8') ?>" class="ev-card">
                <?php if ($ev['image']): ?>
                  <img src="<?= UPLOAD_URL . htmlspecialchars($ev['image'], ENT_QUOTES, 'UTF-8') ?>"
                       alt="<?= htmlspecialchars($etitle, ENT_QUOTES, 'UTF-8') ?>" class="ev-card-img" loading="lazy">
                <?php else: ?>
                  <div class="ev-card-thumb"><?= $emoji ?></div>
                <?php endif; ?>
                <div class="ev-card-body">
                  <div class="ev-card-date">
                    <?= date('D d M Y · H:i', strtotime($ev['starts_at'])) ?>
                  </div>
                  <div class="ev-card-title"><?= htmlspecialchars($etitle, ENT_QUOTES, 'UTF-8') ?></div>
                  <?php if ($ev['venue_name'] || $locName): ?>
                    <div class="ev-card-venue">
                      <?= $ev['is_online'] ? '🌐 '.t('Online','En ligne') : '📍 '.htmlspecialchars($ev['venue_name'] ?: $locName, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                  <?php endif; ?>
                  <div class="ev-card-pills">
                    <span class="ev-pill ev-pill-type"><?= $emoji ?> <?= $tLabel ?></span>
                    <?php if ($ev['is_free']): ?>
                      <span class="ev-pill ev-pill-free"><?= t('Free','Gratuit') ?></span>
                    <?php elseif ($ev['ticket_price']): ?>
                      <span class="ev-pill ev-pill-paid"><?= number_format($ev['ticket_price']) ?> XAF</span>
                    <?php endif; ?>
                    <?php if ($ev['is_online']): ?>
                      <span class="ev-pill ev-pill-online"><?= t('Online','En ligne') ?></span>
                    <?php endif; ?>
                    <?php if ($spotsLeft !== null && $spotsLeft <= 10): ?>
                      <span class="ev-pill ev-pill-spots">
                        <?= $spotsLeft > 0 ? $spotsLeft.' '.t('spots left','places restantes') : t('Full','Complet') ?>
                      </span>
                    <?php endif; ?>
                  </div>
                </div>
              </a>
              <?php if ($ev['featured']): ?>
                <div class="ev-featured-badge">⭐ <?= t('Featured','À la une') ?></div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>

      <?php else: ?>
        <div class="ev-empty">
          <big>📅</big>
          <p><?= t('No upcoming events match your filters.','Aucun événement à venir ne correspond à vos filtres.') ?></p>
          <a href="<?= SITE_URL ?>/events" style="color:#00A878;"><?= t('View all events','Voir tous les événements') ?></a>
        </div>
      <?php endif; ?>

      <!-- Pagination -->
      <?php if ($pages > 1): ?>
        <div class="ev-pagination">
          <?php for ($p = 1; $p <= min($pages, 10); $p++):
            $href = eventsUrl(['page' => $p]); ?>
            <?php if ($p === $page): ?><span class="cur"><?= $p ?></span>
            <?php else: ?><a href="<?= $href ?>"><?= $p ?></a><?php endif; ?>
          <?php endfor; ?>
        </div>
      <?php endif; ?>

      <!-- Host CTA -->
      <div class="ev-host-cta">
        <h3>🎤 <?= t('Hosting an Event?','Vous organisez un événement ?') ?></h3>
        <p><?= t('Promote your workshop, conference or networking event to thousands of Cameroonian businesses.',
                 'Faites la promotion de votre atelier, conférence ou événement de réseautage auprès de milliers d\'entreprises camerounaises.') ?></p>
        <a href="<?= SITE_URL ?>/partner/events" class="ev-host-btn">
          + <?= t('List Your Event','Lister Votre Événement') ?>
        </a>
      </div>
    </main>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
