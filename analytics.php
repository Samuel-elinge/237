<?php
/**
 * analytics.php — 237Biz
 * Business analytics dashboard: views, enquiries, bookings, QR code, profile score.
 * URL: /analytics or /analytics?listing_id=X
 */
require_once __DIR__ . '/includes/config.php';
requireLogin();

$u = currentUser();

// Load user's approved listings
$myListings = db()->prepare("
    SELECT id, title, slug, featured, logo, views,
           tagline, description, keywords, services_json,
           hours_json, whatsapp, phone, email, video_url, cta_url
    FROM listings
    WHERE user_id=? AND status='approved'
    ORDER BY featured DESC, title ASC
");
$myListings->execute([$u['id']]);
$myListings = $myListings->fetchAll();

if (isAdmin() && empty($myListings)) {
    $myListings = db()->query("SELECT id,title,slug,featured,logo,views,tagline,description,keywords,services_json,hours_json,whatsapp,phone,email,video_url,cta_url FROM listings WHERE status='approved' ORDER BY views DESC LIMIT 20")->fetchAll();
}

$lid = (int)($_GET['listing_id'] ?? ($myListings[0]['id'] ?? 0));
$l   = null;
foreach ($myListings as $ml) { if ($ml['id'] === $lid) { $l = $ml; break; } }
if (!$l && !empty($myListings)) { $l = $myListings[0]; $lid = $l['id']; }

// ── Date range ────────────────────────────────────────────────────────────
$range   = $_GET['range'] ?? '30';
$ranges  = ['7'=>'Last 7 days','30'=>'Last 30 days','90'=>'Last 90 days'];
if (!isset($ranges[$range])) $range = '30';
$rangeSQL = "DATE_SUB(NOW(), INTERVAL {$range} DAY)";

// ── Stats ─────────────────────────────────────────────────────────────────
$stats = ['views_total'=>0,'views_period'=>0,'enquiries_total'=>0,'enquiries_period'=>0,'bookings_total'=>0,'bookings_period'=>0];

if ($l) {
    // Total views (from listings table)
    $stats['views_total'] = (int)($l['views'] ?? 0);

    // Views in period (from listing_views)
    try {
        $vp = db()->prepare("SELECT COUNT(*) FROM listing_views WHERE listing_id=? AND viewed_at >= {$rangeSQL}");
        $vp->execute([$lid]);
        $stats['views_period'] = (int)$vp->fetchColumn();
    } catch (Exception $e) {}

    // Enquiries total
    try {
        $eq = db()->prepare("SELECT COUNT(*) FROM listing_enquiries WHERE listing_id=?");
        $eq->execute([$lid]);
        $stats['enquiries_total'] = (int)$eq->fetchColumn();
        $ep = db()->prepare("SELECT COUNT(*) FROM listing_enquiries WHERE listing_id=? AND created_at >= {$rangeSQL}");
        $ep->execute([$lid]);
        $stats['enquiries_period'] = (int)$ep->fetchColumn();
    } catch (Exception $e) {}

    // Bookings
    try {
        $bq = db()->prepare("SELECT COUNT(*) FROM listing_bookings WHERE listing_id=?");
        $bq->execute([$lid]);
        $stats['bookings_total'] = (int)$bq->fetchColumn();
        $bp = db()->prepare("SELECT COUNT(*) FROM listing_bookings WHERE listing_id=? AND created_at >= {$rangeSQL}");
        $bp->execute([$lid]);
        $stats['bookings_period'] = (int)$bp->fetchColumn();
    } catch (Exception $e) {}
}

// ── Daily views for chart ─────────────────────────────────────────────────
$chartData = [];
if ($l) {
    try {
        $cv = db()->prepare("
            SELECT DATE(viewed_at) AS day, COUNT(*) AS cnt
            FROM listing_views
            WHERE listing_id=? AND viewed_at >= {$rangeSQL}
            GROUP BY DATE(viewed_at)
            ORDER BY day ASC
        ");
        $cv->execute([$lid]);
        $rows = $cv->fetchAll();
        // Fill gaps
        $start = strtotime("-{$range} days");
        for ($d=$start; $d<=time(); $d+=86400) {
            $key = date('Y-m-d',$d);
            $chartData[$key] = 0;
        }
        foreach ($rows as $r) { $chartData[$r['day']] = (int)$r['cnt']; }
    } catch (Exception $e) {}
}

// ── Profile completion score ──────────────────────────────────────────────
function profileScore(array $l): array {
    $checks = [
        'Business name'         => !empty($l['title']),
        'Tagline'               => !empty($l['tagline']),
        'Description (50+ w)'  => str_word_count(strip_tags($l['description']??'')) >= 50,
        'Phone or WhatsApp'     => !empty($l['phone']) || !empty($l['whatsapp']),
        'Services listed'       => !empty($l['services_json']) && $l['services_json']!=='[]',
        'Keywords'              => !empty($l['keywords']),
        'Business hours'        => !empty($l['hours_json']) && $l['hours_json']!=='{}',
        'CTA button'            => !empty($l['cta_url']),
        'Video'                 => !empty($l['video_url']),
    ];
    $done = array_filter($checks);
    return [
        'checks' => $checks,
        'score'  => count($done),
        'total'  => count($checks),
        'pct'    => (int)round(count($done)/count($checks)*100),
    ];
}
$profile = $l ? profileScore($l) : ['checks'=>[],'score'=>0,'total'=>9,'pct'=>0];

// ── Active announcements ──────────────────────────────────────────────────
$announcements = [];
if ($l) {
    try {
        $ann = db()->prepare("SELECT * FROM listing_announcements WHERE listing_id=? ORDER BY created_at DESC LIMIT 5");
        $ann->execute([$lid]);
        $announcements = $ann->fetchAll();
    } catch (Exception $e) {}
}

$pageTitle = t('Analytics','Analytiques') . ' — 237Biz';
require_once __DIR__ . '/includes/header.php';
?>

<style>
.an-wrap   { max-width:960px; margin:0 auto; padding:24px 16px 60px; }
.an-header { display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:20px; }
.an-grid   { display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;margin-bottom:24px; }
.stat-card { background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:18px 20px; }
.stat-main { font-size:2rem;font-weight:900;font-family:'Fraunces',serif;line-height:1; }
.stat-label{ font-size:12px;color:var(--muted);margin-bottom:4px;text-transform:uppercase;letter-spacing:.06em; }
.stat-sub  { font-size:12px;color:var(--muted);margin-top:5px; }
.stat-trend{ font-size:11.5px;font-weight:600; }
.trend-up  { color:#00A878; } .trend-down { color:#e63946; } .trend-flat { color:var(--muted); }
/* Chart */
.chart-card { background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.07);border-radius:12px;padding:20px;margin-bottom:20px; }
.chart-title{ font-size:14px;font-weight:700;margin-bottom:16px;color:rgba(255,255,255,0.8); }
.chart-bars { display:flex;align-items:flex-end;gap:3px;height:100px;padding-bottom:20px;position:relative; }
.chart-bar  { flex:1;background:rgba(0,168,120,0.5);border-radius:3px 3px 0 0;min-height:2px;position:relative;cursor:default;transition:background .15s; }
.chart-bar:hover { background:rgba(0,168,120,0.9); }
.chart-bar::after { content:attr(data-val);position:absolute;bottom:-18px;left:50%;transform:translateX(-50%);font-size:9px;color:var(--muted);white-space:nowrap; }
.chart-bar .tip { display:none;position:absolute;bottom:calc(100%+6px);left:50%;transform:translateX(-50%);background:rgba(0,0,0,0.85);color:#fff;font-size:11px;padding:4px 8px;border-radius:5px;white-space:nowrap;pointer-events:none; }
.chart-bar:hover .tip { display:block; }
/* Profile score */
.profile-card { background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.07);border-radius:12px;padding:20px;margin-bottom:20px; }
.profile-item { display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid rgba(255,255,255,0.04); }
.profile-item:last-child { border-bottom:none; }
.profile-icon { font-size:14px;flex-shrink:0; }
.profile-label{ font-size:13px;flex:1; }
.profile-status { font-size:12px;font-weight:600; }
/* QR */
.qr-card { background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.07);border-radius:12px;padding:20px;text-align:center; }
.qr-img  { width:180px;height:180px;border-radius:10px;background:#fff;padding:8px;margin:0 auto 12px; }
/* Listing selector */
.an-listing-sel { padding:8px 14px;background:rgba(255,255,255,0.05);border:1px solid var(--border);border-radius:8px;color:var(--white);font-family:inherit;font-size:14px; }
.an-range-btns  { display:flex;gap:4px; }
.an-range-btn   { padding:6px 14px;border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.03);color:var(--muted);border-radius:6px;text-decoration:none;font-size:12.5px;font-weight:600; }
.an-range-btn.active { background:rgba(0,168,120,0.2);border-color:#00A878;color:#00A878; }
/* Two-col layout */
.an-cols { display:grid;grid-template-columns:1fr 300px;gap:20px;align-items:start; }
@media(max-width:700px){ .an-cols { grid-template-columns:1fr; } }
</style>

<div class="page-header">
  <div class="container">
    <nav class="breadcrumb">
      <a href="<?= SITE_URL ?>/"><?= t('Home','Accueil') ?></a> ›
      <a href="<?= SITE_URL ?>/dashboard"><?= t('Dashboard','Tableau de bord') ?></a> ›
      <span><?= t('Analytics','Analytiques') ?></span>
    </nav>
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.5rem,3vw,2rem);">
      📊 <?= t('Analytics','Analytiques') ?>
    </h1>
  </div>
</div>

<section class="page-section" style="padding-top:1.25rem;">
<div class="an-wrap">

<?php if (empty($myListings)): ?>
<div style="text-align:center;padding:60px 20px;color:var(--muted);">
  <div style="font-size:2.5rem;margin-bottom:14px;">📊</div>
  <p style="font-size:15px;color:var(--white);font-weight:600;"><?= t('No listings yet','Aucune annonce encore') ?></p>
  <a href="<?= SITE_URL ?>/add-listing" class="btn btn-primary" style="margin-top:14px;">+ <?= t('Add your first listing','Ajouter votre première annonce') ?></a>
</div>
<?php else: ?>

<!-- Header controls -->
<div class="an-header">
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
    <?php if (count($myListings) > 1): ?>
    <select class="an-listing-sel" onchange="location.href='/analytics?listing_id='+this.value+'&range=<?= $range ?>'">
      <?php foreach ($myListings as $ml): ?>
      <option value="<?= $ml['id'] ?>" <?= $ml['id']===$lid?'selected':'' ?>><?= e($ml['title']) ?> <?= $ml['featured']?'⭐':'' ?></option>
      <?php endforeach; ?>
    </select>
    <?php else: ?>
    <strong style="font-size:15px;"><?= e($l['title']) ?> <?= $l['featured']?'⭐':'' ?></strong>
    <?php endif; ?>
    <a href="<?= SITE_URL ?>/listing/<?= e($l['slug']) ?>" style="font-size:12px;color:var(--green);" target="_blank"><?= t('View listing →','Voir l\'annonce →') ?></a>
  </div>
  <div class="an-range-btns">
    <?php foreach ($ranges as $r=>$lbl): ?>
    <a href="?listing_id=<?= $lid ?>&range=<?= $r ?>" class="an-range-btn <?= $range===$r?'active':'' ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
  </div>
</div>

<!-- Stat cards -->
<div class="an-grid">
  <div class="stat-card">
    <div class="stat-label">👁 <?= t('Total Views','Vues totales') ?></div>
    <div class="stat-main" style="color:#00A878;"><?= number_format($stats['views_total']) ?></div>
    <div class="stat-sub"><?= number_format($stats['views_period']) ?> <?= t('in last','dans les derniers') ?> <?= $range ?> <?= t('days','jours') ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">📬 <?= t('Enquiries','Demandes') ?></div>
    <div class="stat-main" style="color:var(--yellow);"><?= number_format($stats['enquiries_total']) ?></div>
    <div class="stat-sub"><?= number_format($stats['enquiries_period']) ?> <?= t('this period','cette période') ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">📅 <?= t('Bookings','Réservations') ?></div>
    <div class="stat-main" style="color:#8ab4f8;"><?= number_format($stats['bookings_total']) ?></div>
    <div class="stat-sub"><?= number_format($stats['bookings_period']) ?> <?= t('this period','cette période') ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">📈 <?= t('Profile Score','Score profil') ?></div>
    <div class="stat-main" style="color:<?= $profile['pct']>=80?'#00A878':($profile['pct']>=50?'var(--yellow)':'#e63946') ?>;"><?= $profile['pct'] ?>%</div>
    <div class="stat-sub"><?= $profile['score'] ?>/<?= $profile['total'] ?> <?= t('sections complete','sections complètes') ?></div>
  </div>
</div>

<div class="an-cols">
<div>

<!-- Views chart -->
<?php if (!empty($chartData)): ?>
<div class="chart-card">
  <div class="chart-title">📈 <?= t('Views — last','Vues — derniers') ?> <?= $range ?> <?= t('days','jours') ?></div>
  <?php
  $maxV = max(array_values($chartData) ?: [1]);
  $maxV = max($maxV, 1);
  ?>
  <div class="chart-bars" id="chartBars">
    <?php
    $i = 0;
    $total_shown = count($chartData);
    foreach ($chartData as $day => $cnt):
      $pct = (int)round($cnt/$maxV*100);
      $pct = max($pct, 2); // min visible height
      $lbl = ($i % max(1, intdiv($total_shown,7)) === 0) ? date('d M',strtotime($day)) : '';
      $i++;
    ?>
    <div class="chart-bar" style="height:<?= $pct ?>%;" data-val="<?= $lbl ?>">
      <div class="tip"><?= date('d M',strtotime($day)) ?>: <?= $cnt ?> <?= t('views','vues') ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php if (array_sum($chartData) === 0): ?>
  <p style="font-size:13px;color:var(--muted);text-align:center;margin-top:8px;"><?= t('No views recorded in this period yet.','Aucune vue enregistrée dans cette période.') ?></p>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Profile completion -->
<div class="profile-card">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
    <div style="font-size:14px;font-weight:700;">🎯 <?= t('Profile Completion','Complétude du profil') ?></div>
    <a href="<?= SITE_URL ?>/edit-listing?id=<?= $lid ?>" style="font-size:12px;color:var(--green);"><?= t('Edit listing →','Modifier →') ?></a>
  </div>
  <!-- Score bar -->
  <div style="height:8px;background:rgba(255,255,255,0.07);border-radius:99px;overflow:hidden;margin-bottom:14px;">
    <div style="height:100%;width:<?= $profile['pct'] ?>%;background:<?= $profile['pct']>=80?'linear-gradient(90deg,#00A878,#6fcf97)':($profile['pct']>=50?'linear-gradient(90deg,#B8860B,#fcd116)':'linear-gradient(90deg,#c0392b,#e63946)') ?>;border-radius:99px;transition:width .5s;"></div>
  </div>
  <?php foreach ($profile['checks'] as $label => $done): ?>
  <div class="profile-item">
    <span class="profile-icon"><?= $done ? '✅' : '○' ?></span>
    <span class="profile-label" style="color:<?= $done?'rgba(255,255,255,0.8)':'rgba(255,255,255,0.4)' ?>;"><?= $label ?></span>
    <?php if (!$done): ?><a href="<?= SITE_URL ?>/edit-listing?id=<?= $lid ?>" style="font-size:11px;color:var(--yellow);"><?= t('Add →','Ajouter →') ?></a><?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<!-- Announcements summary -->
<div class="chart-card">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
    <div style="font-size:14px;font-weight:700;">📢 <?= t('Announcements','Annonces') ?></div>
    <a href="<?= SITE_URL ?>/manage-announcements?listing_id=<?= $lid ?>" style="font-size:12px;color:var(--green);"><?= t('Manage →','Gérer →') ?></a>
  </div>
  <?php if (empty($announcements)): ?>
  <p style="font-size:13px;color:var(--muted);"><?= t('No announcements yet. Add a promotion or event to boost visibility.','Aucune annonce. Ajoutez une promotion ou un événement pour booster la visibilité.') ?></p>
  <a href="<?= SITE_URL ?>/manage-announcements?listing_id=<?= $lid ?>" class="btn btn-primary btn-sm" style="margin-top:8px;">+ <?= t('Add Announcement','Ajouter une annonce') ?></a>
  <?php else: ?>
  <?php foreach ($announcements as $ann):
    $isActive = $ann['active'] && strtotime($ann['ends_at']) > time();
    $colors = ['offer'=>'var(--yellow)','event'=>'#8ab4f8','info'=>'#00A878','urgent'=>'#e63946'];
    $col = $colors[$ann['type']] ?? '#00A878';
  ?>
  <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,0.05);">
    <span style="width:8px;height:8px;border-radius:50%;background:<?= $isActive?$col:'var(--muted)' ?>;flex-shrink:0;"></span>
    <div style="flex:1;min-width:0;">
      <div style="font-size:13px;font-weight:600;color:<?= $isActive?'rgba(255,255,255,0.9)':'var(--muted)' ?>;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= e($ann['title']) ?></div>
      <div style="font-size:11px;color:var(--muted);"><?= $isActive ? t('Active until','Actif jusqu\'au').' '.date('d M',strtotime($ann['ends_at'])) : t('Expired','Expiré') ?></div>
    </div>
    <span style="font-size:11px;color:var(--muted);"><?= number_format($ann['views']) ?> <?= t('views','vues') ?></span>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>

</div><!-- /main col -->

<!-- Sidebar -->
<div>

  <!-- QR Code -->
  <div class="qr-card">
    <div style="font-size:14px;font-weight:700;margin-bottom:8px;">📱 <?= t('QR Code','Code QR') ?></div>
    <p style="font-size:12px;color:var(--muted);margin-bottom:12px;"><?= t('Share or print this QR code to link directly to your listing.','Partagez ou imprimez ce code QR pour accéder directement à votre annonce.') ?></p>
    <?php $listingUrl = SITE_URL . '/listing/' . e($l['slug']); ?>
    <img class="qr-img"
         src="https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=<?= urlencode($listingUrl) ?>&color=08472F&bgcolor=ffffff"
         alt="QR Code for <?= e($l['title']) ?>">
    <a href="https://api.qrserver.com/v1/create-qr-code/?size=600x600&data=<?= urlencode($listingUrl) ?>&color=08472F&bgcolor=ffffff&format=png"
       download="<?= e($l['slug']) ?>-qr.png" target="_blank"
       class="btn btn-outline" style="width:100%;text-align:center;display:block;margin-bottom:8px;">
      ⬇️ <?= t('Download QR Code','Télécharger le code QR') ?>
    </a>
    <div style="font-size:10.5px;color:var(--muted);text-align:center;word-break:break-all;"><?= $listingUrl ?></div>
  </div>

  <!-- Quick actions -->
  <div style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.07);border-radius:12px;padding:16px;margin-top:14px;">
    <div style="font-size:13px;font-weight:700;margin-bottom:12px;color:rgba(255,255,255,0.8);"><?= t('Quick Actions','Actions rapides') ?></div>
    <div style="display:flex;flex-direction:column;gap:7px;">
      <a href="<?= SITE_URL ?>/edit-listing?id=<?= $lid ?>" style="font-size:13px;color:var(--green);text-decoration:none;">✏️ <?= t('Edit Listing','Modifier l\'annonce') ?></a>
      <a href="<?= SITE_URL ?>/manage-announcements?listing_id=<?= $lid ?>" style="font-size:13px;color:var(--green);text-decoration:none;">📢 <?= t('Manage Announcements','Gérer les annonces') ?></a>
      <?php if (!empty($l['booking_enabled'])): ?>
      <a href="<?= SITE_URL ?>/manage-bookings?listing_id=<?= $lid ?>" style="font-size:13px;color:var(--green);text-decoration:none;">📅 <?= t('Manage Bookings','Gérer les réservations') ?></a>
      <?php endif; ?>
      <a href="<?= SITE_URL ?>/listing/<?= e($l['slug']) ?>" style="font-size:13px;color:var(--green);text-decoration:none;" target="_blank">👁 <?= t('View Live Listing','Voir l\'annonce en ligne') ?></a>
    </div>
  </div>

</div><!-- /sidebar -->
</div><!-- /an-cols -->

<?php endif; ?>
</div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
