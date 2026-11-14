<?php
/**
 * card-analytics.php — 237Biz Digital Card & QR Analytics
 * URL: /card-analytics?listing_id=X&range=30
 * Subscription-gated: free=basic totals, growth=detailed, premium=advanced+custom range
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/entitlement-helpers.php';
requireLogin();

$u = currentUser();

// Load user's approved listings
if (isAdmin()) {
    $myListings = db()->query("SELECT id,title,slug,logo FROM listings WHERE status='approved' ORDER BY title ASC")->fetchAll();
} else {
    $st = db()->prepare("SELECT id,title,slug,logo FROM listings WHERE user_id=? AND status='approved' ORDER BY title ASC");
    $st->execute([$u['id']]);
    $myListings = $st->fetchAll();
}

if (empty($myListings)) {
    redirect(SITE_URL . '/dashboard');
}

$lid = (int)($_GET['listing_id'] ?? ($myListings[0]['id'] ?? 0));
$l   = null;
foreach ($myListings as $ml) { if ((int)$ml['id'] === $lid) { $l = $ml; break; } }
if (!$l) { $l = $myListings[0]; $lid = (int)$l['id']; }

// ── Entitlements ─────────────────────────────────────────────────────────
$ent      = getListingEntitlements($lid);
$planSlug = $ent['plan_slug'] ?? 'free';
// Analytics tier: free=basic, growth=detailed, premium=advanced
$isGrowth  = in_array($planSlug, ['growth', 'premium']) || isAdmin();
$isPremium = ($planSlug === 'premium') || isAdmin();

// ── Date range ────────────────────────────────────────────────────────────
$validRanges = ['today' => 'Today', '7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days'];
if ($isPremium) $validRanges['custom'] = 'Custom range';

$range    = $_GET['range'] ?? '30';
if (!isset($validRanges[$range])) $range = '30';

$customFrom = $_GET['from'] ?? '';
$customTo   = $_GET['to']   ?? '';
$useCustom  = ($isPremium && $range === 'custom' && $customFrom && $customTo);

if ($useCustom) {
    $dateFrom = date('Y-m-d', strtotime($customFrom)) . ' 00:00:00';
    $dateTo   = date('Y-m-d', strtotime($customTo))   . ' 23:59:59';
    $rangeSQL = "created_at BETWEEN '$dateFrom' AND '$dateTo'";
} elseif ($range === 'today') {
    $rangeSQL = "DATE(created_at) = CURDATE()";
} else {
    $rangeSQL = "created_at >= DATE_SUB(NOW(), INTERVAL {$range} DAY)";
}

// Previous period for comparison (growth+)
$prevRangeSQL = '';
if ($isGrowth && $range !== 'today' && !$useCustom) {
    $days = (int)$range;
    $prevRangeSQL = "created_at >= DATE_SUB(NOW(), INTERVAL " . ($days * 2) . " DAY) AND created_at < DATE_SUB(NOW(), INTERVAL {$days} DAY)";
}

// ── Card prefix for event types ────────────────────────────────────────────
// All card events stored as event_subtype = 'card_{type}' in marketplace_impressions
$cardEvents = [
    'card_card_view'          => ['label_en' => 'Total Scans / Views',    'label_fr' => 'Vues totales',         'icon' => '👁️'],
    'card_contact_download'   => ['label_en' => 'Contact Downloads',       'label_fr' => 'Téléch. contacts',    'icon' => '💾'],
    'card_phone_click'        => ['label_en' => 'Phone Clicks',            'label_fr' => 'Clics Téléphone',     'icon' => '📞'],
    'card_whatsapp_click'     => ['label_en' => 'WhatsApp Clicks',         'label_fr' => 'Clics WhatsApp',      'icon' => '💬'],
    'card_website_click'      => ['label_en' => 'Website Clicks',          'label_fr' => 'Clics Site Web',      'icon' => '🌐'],
    'card_directions_click'   => ['label_en' => 'Direction Requests',      'label_fr' => 'Demandes Itinéraire', 'icon' => '🗺️'],
    'card_social_click'       => ['label_en' => 'Social Media Clicks',     'label_fr' => 'Clics Réseaux Soc.',  'icon' => '🔗'],
    'card_profile_click'      => ['label_en' => 'Full Profile Visits',     'label_fr' => 'Visites Profil',      'icon' => '📋'],
    'card_share_click'        => ['label_en' => 'Card Shares',             'label_fr' => 'Partages',            'icon' => '📤'],
    'card_product_click'      => ['label_en' => 'Product Clicks',          'label_fr' => 'Clics Produits',      'icon' => '🛍️'],
    'card_offer_click'        => ['label_en' => 'Offer Clicks',            'label_fr' => 'Clics Offres',        'icon' => '🎟️'],
    'card_event_click'        => ['label_en' => 'Event Clicks',            'label_fr' => 'Clics Événements',    'icon' => '📅'],
];

// Free plan shows only the first 4 metrics
$visibleEvents = $isGrowth ? $cardEvents : array_slice($cardEvents, 0, 4, true);

// ── Fetch metrics ─────────────────────────────────────────────────────────
$metrics = [];
$prevMetrics = [];

try {
    foreach ($visibleEvents as $subtype => $meta) {
        $q = db()->prepare("
            SELECT COUNT(*) FROM marketplace_impressions
            WHERE entity_type='listing' AND entity_id=? AND event_subtype=? AND $rangeSQL
        ");
        $q->execute([$lid, $subtype]);
        $metrics[$subtype] = (int)$q->fetchColumn();

        if ($isGrowth && $prevRangeSQL) {
            $pq = db()->prepare("
                SELECT COUNT(*) FROM marketplace_impressions
                WHERE entity_type='listing' AND entity_id=? AND event_subtype=? AND $prevRangeSQL
            ");
            $pq->execute([$lid, $subtype]);
            $prevMetrics[$subtype] = (int)$pq->fetchColumn();
        }
    }
} catch (Exception $e) {
    $metrics = [];
}

// ── Daily breakdown chart data (growth+) ─────────────────────────────────
$chartDays  = [];
$chartViews = [];
$chartContacts = [];

if ($isGrowth) {
    try {
        $days = ($range === 'today') ? 1 : (($useCustom) ? max(1, (int)((strtotime($dateTo) - strtotime($dateFrom)) / 86400) + 1) : (int)$range);
        $days = min($days, 90);

        $cq = db()->prepare("
            SELECT DATE(created_at) AS day,
                   SUM(event_subtype = 'card_card_view') AS views,
                   SUM(event_subtype = 'card_contact_download') AS contacts
            FROM marketplace_impressions
            WHERE entity_type='listing' AND entity_id=? AND $rangeSQL
            GROUP BY DATE(created_at)
            ORDER BY day ASC
        ");
        $cq->execute([$lid]);
        $rows = $cq->fetchAll();
        $byDay = [];
        foreach ($rows as $row) { $byDay[$row['day']] = $row; }

        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $chartDays[]    = date('d M', strtotime($d));
            $chartViews[]   = (int)($byDay[$d]['views']    ?? 0);
            $chartContacts[]= (int)($byDay[$d]['contacts'] ?? 0);
        }
    } catch (Exception $e) {}
}

// ── Source breakdown (premium only) ───────────────────────────────────────
$sourceCounts = [];
if ($isPremium) {
    try {
        $sq = db()->prepare("
            SELECT page_context AS src, COUNT(*) AS cnt
            FROM marketplace_impressions
            WHERE entity_type='listing' AND entity_id=? AND event_subtype='card_card_view' AND $rangeSQL
            GROUP BY page_context
            ORDER BY cnt DESC
            LIMIT 10
        ");
        $sq->execute([$lid]);
        $sourceCounts = $sq->fetchAll();
    } catch (Exception $e) {}
}

// Slug for QR preview
$slug = $l['slug'];

// Page title
$pageTitle = t('Digital Card & QR Analytics', 'Analytiques Carte & QR');

require_once __DIR__ . '/includes/header.php';
?>
<style>
/* ── Layout ─────────────────────────────────────────── */
.ca-wrap{max-width:1100px;margin:0 auto;padding:1.5rem 1rem 3rem}
.ca-top{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;margin-bottom:1.5rem}
.ca-title{font-size:1.4rem;font-weight:800;color:var(--text,#1a1a1a)}
.ca-subtitle{font-size:.82rem;color:var(--muted,#666);margin-top:.15rem}

/* ── Filter bar ─────────────────────────────────────── */
.filter-bar{
  display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;
  background:#fff;border-radius:12px;padding:.75rem 1rem;
  box-shadow:0 1px 8px rgba(0,0,0,.07);margin-bottom:1.5rem;
}
.filter-bar select,.filter-bar input[type=date]{
  padding:.4rem .6rem;border:1px solid #dde;border-radius:8px;
  font-size:.82rem;color:var(--text,#1a1a1a);background:#fafafa;
}
.filter-btn{
  padding:.4rem .9rem;border-radius:8px;border:none;cursor:pointer;
  background:var(--primary,#00A878);color:#fff;font-size:.82rem;font-weight:600;
}
.listing-sel{
  padding:.4rem .6rem;border:1px solid #dde;border-radius:8px;
  font-size:.82rem;max-width:200px;
}

/* ── Metric cards grid ──────────────────────────────── */
.metrics-grid{
  display:grid;
  grid-template-columns:repeat(auto-fill,minmax(170px,1fr));
  gap:.85rem;margin-bottom:1.5rem;
}
.metric-card{
  background:#fff;border-radius:14px;padding:1rem;
  box-shadow:0 1px 8px rgba(0,0,0,.07);
  position:relative;overflow:hidden;
}
.metric-icon{font-size:1.5rem;margin-bottom:.4rem}
.metric-val{font-size:1.8rem;font-weight:800;color:var(--text,#1a1a1a);line-height:1}
.metric-label{font-size:.74rem;color:var(--muted,#666);margin-top:.3rem;line-height:1.3}
.metric-delta{
  font-size:.72rem;font-weight:600;margin-top:.4rem;
  display:inline-flex;align-items:center;gap:.2rem;padding:.15rem .4rem;
  border-radius:50px;
}
.delta-up{background:#e8f5f1;color:#00A878}
.delta-down{background:#fce8e8;color:#cc3333}
.delta-flat{background:#f0f0f0;color:#888}

/* ── Chart card ─────────────────────────────────────── */
.chart-card{
  background:#fff;border-radius:14px;padding:1.25rem;
  box-shadow:0 1px 8px rgba(0,0,0,.07);margin-bottom:1.5rem;
}
.chart-title{font-size:.85rem;font-weight:700;color:var(--muted,#666);margin-bottom:1rem;text-transform:uppercase;letter-spacing:.04em}
.chart-wrap{position:relative;height:220px}

/* ── Source table ───────────────────────────────────── */
.source-table{width:100%;border-collapse:collapse;font-size:.84rem}
.source-table th{text-align:left;padding:.5rem .75rem;border-bottom:2px solid #eee;color:var(--muted,#666);font-weight:600;font-size:.75rem;text-transform:uppercase}
.source-table td{padding:.6rem .75rem;border-bottom:1px solid #f0f0f0}
.source-table tr:last-child td{border-bottom:none}
.source-bar-wrap{width:120px;background:#f0f0f0;border-radius:50px;height:6px;overflow:hidden}
.source-bar{height:100%;background:var(--primary,#00A878);border-radius:50px}

/* ── QR preview ─────────────────────────────────────── */
.qr-card{
  background:#fff;border-radius:14px;padding:1.25rem;
  box-shadow:0 1px 8px rgba(0,0,0,.07);
  display:flex;align-items:center;gap:1rem;flex-wrap:wrap;
  margin-bottom:1.5rem;
}
.qr-img{width:90px;height:90px;border-radius:8px}
.qr-info{flex:1;min-width:0}
.qr-info h3{font-size:1rem;font-weight:700;margin-bottom:.25rem}
.qr-info p{font-size:.8rem;color:var(--muted,#666);word-break:break-all}
.qr-actions{display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.5rem}
.qr-btn{
  display:inline-block;text-decoration:none;font-size:.78rem;font-weight:600;
  padding:.35rem .75rem;border-radius:50px;border:1px solid;
  transition:background .15s;
}
.qr-btn-dl{background:var(--primary,#00A878);color:#fff;border-color:var(--primary,#00A878)}
.qr-btn-preview{background:#fff;color:var(--primary,#00A878);border-color:var(--primary,#00A878)}
.qr-btn-copy{background:#f5f5f5;color:#444;border-color:#ddd;cursor:pointer}

/* ── Upgrade prompt ─────────────────────────────────── */
.upgrade-prompt{
  background:linear-gradient(135deg,#00A878 0%,#008060 100%);
  color:#fff;border-radius:14px;padding:1.25rem 1.5rem;
  text-align:center;margin-bottom:1.5rem;
}
.upgrade-prompt h3{font-size:1rem;font-weight:700;margin-bottom:.4rem}
.upgrade-prompt p{font-size:.82rem;opacity:.88;margin-bottom:.85rem}
.upgrade-prompt a{
  display:inline-block;background:#fff;color:var(--primary,#00A878);
  font-weight:700;font-size:.85rem;padding:.5rem 1.25rem;
  border-radius:50px;text-decoration:none;
}
.upgrade-prompt a:hover{background:#f0fff8}

/* ── Toast ──────────────────────────────────────────── */
.ca-toast{
  position:fixed;bottom:1.5rem;left:50%;transform:translateX(-50%);
  background:#222;color:#fff;border-radius:50px;
  padding:.6rem 1.2rem;font-size:.82rem;font-weight:500;
  opacity:0;pointer-events:none;transition:opacity .25s;white-space:nowrap;z-index:999;
}
.ca-toast.show{opacity:1}

@media(max-width:600px){
  .metrics-grid{grid-template-columns:repeat(2,1fr)}
  .ca-top{flex-direction:column;align-items:flex-start}
}
</style>

<main style="padding-top:1rem">
<div class="ca-wrap">

  <!-- Top bar -->
  <div class="ca-top">
    <div>
      <div class="ca-title">🪪 <?= $pageTitle ?></div>
      <div class="ca-subtitle">
        <?= e($l['title']) ?>
        <?php if (count($myListings) > 1): ?>
          · <a href="#" onclick="document.getElementById('listingPick').focus();return false" style="color:var(--primary,#00A878);text-decoration:none;font-size:.8rem"><?= t('Switch listing','Changer d\'annonce') ?></a>
        <?php endif; ?>
      </div>
    </div>
    <a href="<?= SITE_URL ?>/card/<?= e($slug) ?>" target="_blank"
       style="text-decoration:none;font-size:.82rem;font-weight:600;color:var(--primary,#00A878);border:1px solid var(--primary,#00A878);padding:.35rem .9rem;border-radius:50px">
      <?= t('Preview Card','Voir la carte') ?> →
    </a>
  </div>

  <!-- Filter bar -->
  <form method="GET" class="filter-bar">
    <input type="hidden" name="listing_id" value="<?= $lid ?>">

    <?php if (count($myListings) > 1): ?>
    <select id="listingPick" name="listing_id" onchange="this.form.submit()" class="listing-sel">
      <?php foreach ($myListings as $ml): ?>
        <option value="<?= (int)$ml['id'] ?>" <?= ($ml['id'] == $lid) ? 'selected' : '' ?>>
          <?= e(mb_substr($ml['title'], 0, 28)) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>

    <select name="range" onchange="document.getElementById('customFields').style.display=this.value==='custom'?'flex':'none';this.form.submit()">
      <?php foreach ($validRanges as $k => $v): ?>
        <option value="<?= $k ?>" <?= ($range === $k) ? 'selected' : '' ?>><?= t($v, $v) ?></option>
      <?php endforeach; ?>
    </select>

    <?php if ($isPremium): ?>
    <span id="customFields" style="display:<?= $useCustom ? 'flex' : 'none' ?>;gap:.4rem;align-items:center">
      <input type="date" name="from" value="<?= e($customFrom) ?>" max="<?= date('Y-m-d') ?>">
      <span style="font-size:.8rem;color:#888"><?= t('to','à') ?></span>
      <input type="date" name="to"   value="<?= e($customTo) ?>"   max="<?= date('Y-m-d') ?>">
      <button type="submit" class="filter-btn"><?= t('Apply','Appliquer') ?></button>
    </span>
    <?php endif; ?>

    <span style="font-size:.78rem;color:var(--muted,#666);margin-left:auto">
      <?= t('Plan','Plan') ?>: <strong><?= e(lang($ent['plan_name_en'] ?? 'Free', $ent['plan_name_fr'] ?? 'Gratuit')) ?></strong>
    </span>
  </form>

  <!-- QR + card URL bar -->
  <div class="qr-card">
    <img class="qr-img"
         src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=<?= urlencode(SITE_URL.'/card/'.$slug) ?>&color=08472F&bgcolor=ffffff"
         alt="QR Code" loading="lazy" style="width:100px;height:100px;">
    <div class="qr-info">
      <h3><?= t('Your Digital Card QR','Votre QR Carte Digitale') ?></h3>
      <p id="cardUrlText"><?= SITE_URL ?>/card/<?= e($slug) ?></p>
      <div class="qr-actions">
        <a class="qr-btn qr-btn-dl"
           href="https://api.qrserver.com/v1/create-qr-code/?size=600x600&data=<?= urlencode(SITE_URL.'/card/'.$slug) ?>&color=08472F&bgcolor=ffffff&format=png"
           download="<?= e($slug) ?>-qr.png" target="_blank">
          ⬇ <?= t('Download QR','Télécharger QR') ?>
        </a>
        <a class="qr-btn qr-btn-preview" href="<?= SITE_URL ?>/card/<?= e($slug) ?>" target="_blank">
          👁 <?= t('Preview','Aperçu') ?>
        </a>
        <button type="button" class="qr-btn qr-btn-copy" onclick="copyCardUrl()">
          📋 <?= t('Copy Link','Copier lien') ?>
        </button>
      </div>
      <details style="margin-top:10px;">
        <summary style="font-size:11.5px;color:var(--muted,#888);cursor:pointer;"><?= t('💡 How to use your QR code','💡 Comment utiliser votre code QR') ?></summary>
        <div style="font-size:11px;color:var(--muted,#888);margin-top:6px;line-height:1.6;">
          <strong><?= t('On mobile:','Sur mobile :') ?></strong><br>
          1. <?= t('Tap "Download QR" above','Appuyez sur "Télécharger QR" ci-dessus') ?><br>
          2. <?= t('Save the image to your phone gallery','Enregistrez l\'image dans votre galerie') ?><br>
          3. <?= t('Share via WhatsApp, Instagram, or print it','Partagez via WhatsApp, Instagram, ou imprimez-le') ?><br>
          <strong><?= t('Print tip:','Conseil impression :') ?></strong> <?= t('Add the QR code to your business card, flyer or signage','Ajoutez le code QR sur votre carte de visite, flyer ou enseigne') ?>
        </div>
      </details>
    </div>
  </div>

  <!-- Metric cards -->
  <?php if (!empty($metrics)): ?>
  <div class="metrics-grid">
    <?php foreach ($visibleEvents as $subtype => $meta): ?>
    <?php
      $val  = $metrics[$subtype] ?? 0;
      $prev = $prevMetrics[$subtype] ?? null;
      $deltaHtml = '';
      if ($isGrowth && $prev !== null && $prevRangeSQL) {
          if ($prev == 0 && $val == 0) {
              $deltaHtml = '<span class="metric-delta delta-flat">— ' . t('no change','sans changement') . '</span>';
          } elseif ($prev == 0) {
              $deltaHtml = '<span class="metric-delta delta-up">↑ ' . t('new','nouveau') . '</span>';
          } else {
              $pct = round((($val - $prev) / $prev) * 100);
              $cls = $pct >= 0 ? 'delta-up' : 'delta-down';
              $arrow = $pct >= 0 ? '↑' : '↓';
              $deltaHtml = '<span class="metric-delta '.$cls.'">'.$arrow.' '.abs($pct).'%</span>';
          }
      }
    ?>
    <div class="metric-card">
      <div class="metric-icon"><?= $meta['icon'] ?></div>
      <div class="metric-val"><?= number_format($val) ?></div>
      <div class="metric-label"><?= t($meta['label_en'], $meta['label_fr']) ?></div>
      <?= $deltaHtml ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div style="text-align:center;padding:2rem;color:var(--muted,#666)">
    <?= t('No card activity recorded yet. Share your digital card to start tracking.','Aucune activité encore enregistrée. Partagez votre carte pour commencer le suivi.') ?>
  </div>
  <?php endif; ?>

  <!-- Free plan upgrade prompt -->
  <?php if (!$isGrowth): ?>
  <div class="upgrade-prompt">
    <h3>📊 <?= t('Unlock Detailed Analytics','Débloquez les Analytiques Détaillées') ?></h3>
    <p><?= t('Upgrade to Growth or Premium to see all 12 engagement metrics, trend charts, and period-over-period comparison.','Passez à Growth ou Premium pour voir les 12 métriques d\'engagement, les graphiques de tendance et la comparaison de périodes.') ?></p>
    <a href="<?= SITE_URL ?>/my-subscription"><?= t('Upgrade Now','Mettre à niveau') ?></a>
  </div>
  <?php endif; ?>

  <!-- Trend chart (growth+) -->
  <?php if ($isGrowth && !empty($chartDays)): ?>
  <div class="chart-card">
    <div class="chart-title">📈 <?= t('Scan & Contact Trend','Tendance Vues & Contacts') ?></div>
    <div class="chart-wrap">
      <canvas id="trendChart"></canvas>
    </div>
  </div>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
  <script>
  (function() {
    var ctx = document.getElementById('trendChart').getContext('2d');
    new Chart(ctx, {
      type: 'line',
      data: {
        labels: <?= json_encode($chartDays) ?>,
        datasets: [
          {
            label: <?= json_encode(t('Views / Scans','Vues / Scans')) ?>,
            data: <?= json_encode($chartViews) ?>,
            borderColor: '#00A878',
            backgroundColor: 'rgba(0,168,120,.1)',
            borderWidth: 2,
            tension: 0.35,
            fill: true,
            pointRadius: <?= count($chartDays) > 30 ? 0 : 3 ?>,
          },
          {
            label: <?= json_encode(t('Contact Downloads','Téléch. contacts')) ?>,
            data: <?= json_encode($chartContacts) ?>,
            borderColor: '#FF6B2C',
            backgroundColor: 'rgba(255,107,44,.08)',
            borderWidth: 2,
            tension: 0.35,
            fill: true,
            pointRadius: <?= count($chartDays) > 30 ? 0 : 3 ?>,
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'top', labels: { font: { size: 12 }, boxWidth: 14 } }
        },
        scales: {
          x: {
            ticks: { font: { size: 11 }, maxTicksLimit: 12, maxRotation: 45 },
            grid: { color: 'rgba(0,0,0,.05)' }
          },
          y: {
            beginAtZero: true,
            ticks: { font: { size: 11 }, stepSize: 1, precision: 0 },
            grid: { color: 'rgba(0,0,0,.05)' }
          }
        }
      }
    });
  })();
  </script>
  <?php elseif ($isGrowth): ?>
  <div class="chart-card" style="text-align:center;color:var(--muted,#666);font-size:.88rem">
    <?= t('No scan data available for this period.','Aucune donnée de scan pour cette période.') ?>
  </div>
  <?php endif; ?>

  <!-- Growth upgrade prompt (non-premium) -->
  <?php if ($isGrowth && !$isPremium): ?>
  <div class="upgrade-prompt" style="background:linear-gradient(135deg,#4062d8 0%,#2a44b8 100%)">
    <h3>🚀 <?= t('Unlock Advanced Analytics','Débloquez les Analytiques Avancées') ?></h3>
    <p><?= t('Upgrade to Premium for custom date ranges, source tracking (QR vs direct link vs shared), and 90-day history.','Passez à Premium pour les plages de dates personnalisées, le suivi des sources (QR vs lien direct vs partagé) et 90 jours d\'historique.') ?></p>
    <a href="<?= SITE_URL ?>/my-subscription"><?= t('Go Premium','Passer Premium') ?></a>
  </div>
  <?php endif; ?>

  <!-- Source breakdown (premium) -->
  <?php if ($isPremium && !empty($sourceCounts)): ?>
  <div class="chart-card">
    <div class="chart-title">📡 <?= t('Traffic Sources','Sources de Trafic') ?></div>
    <?php
      $maxSrc = max(array_column($sourceCounts, 'cnt') ?: [1]);
    ?>
    <table class="source-table">
      <thead>
        <tr>
          <th><?= t('Source','Source') ?></th>
          <th><?= t('Scans','Scans') ?></th>
          <th style="width:140px"><?= t('Share','Part') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($sourceCounts as $row): ?>
        <?php
          $srcLabel = match($row['src']) {
              'card_qr'     => '📱 ' . t('QR Code Scan','Scan QR Code'),
              'card_direct' => '🔗 ' . t('Direct Link','Lien direct'),
              'card_share'  => '📤 ' . t('Shared Link','Lien partagé'),
              default       => '🌐 ' . e($row['src'] ?: t('Unknown','Inconnu')),
          };
          $pct = $maxSrc ? round(($row['cnt'] / $maxSrc) * 100) : 0;
        ?>
        <tr>
          <td><?= $srcLabel ?></td>
          <td><strong><?= number_format((int)$row['cnt']) ?></strong></td>
          <td>
            <div class="source-bar-wrap">
              <div class="source-bar" style="width:<?= $pct ?>%"></div>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php elseif ($isPremium): ?>
  <div class="chart-card" style="text-align:center;color:var(--muted,#666);font-size:.88rem">
    <?= t('No source data available yet.','Aucune donnée de source disponible.') ?>
  </div>
  <?php endif; ?>

</div>
</main>

<div class="ca-toast" id="caToast"></div>

<script>
function copyCardUrl() {
  var url = <?= json_encode(SITE_URL . '/card/' . $slug) ?>;
  if (navigator.clipboard) {
    navigator.clipboard.writeText(url).then(function() {
      showToast(<?= json_encode(t('Link copied!','Lien copié !')) ?>);
    });
  } else {
    var el = document.getElementById('cardUrlText');
    var range = document.createRange(); range.selectNode(el);
    window.getSelection().removeAllRanges(); window.getSelection().addRange(range);
    document.execCommand('copy');
    showToast(<?= json_encode(t('Link copied!','Lien copié !')) ?>);
  }
}
function showToast(msg) {
  var t = document.getElementById('caToast');
  t.textContent = msg; t.classList.add('show');
  setTimeout(function() { t.classList.remove('show'); }, 2500);
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
