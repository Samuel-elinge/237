<?php
/**
 * admin/digital-cards.php — Digital Card & QR Feature Management
 * URL: /admin/digital-cards
 * Admin controls: global toggle, platform analytics, per-business card enable/disable
 */
require_once __DIR__ . '/../includes/config.php';
requireAdmin();

// ── Ensure card_disabled column exists on listings ────────────────────────
try {
    db()->exec("ALTER TABLE listings ADD COLUMN card_disabled TINYINT(1) NOT NULL DEFAULT 0");
} catch (Exception $e) {
    // Column already exists — ignore
}

// ── POST handlers ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // Global feature toggle
    if ($action === 'global_toggle') {
        $val = ($_POST['enabled'] ?? '0') === '1' ? '1' : '0';
        db()->prepare("INSERT INTO site_settings (`key`, value) VALUES ('card_feature_enabled', ?) ON DUPLICATE KEY UPDATE value=?")
             ->execute([$val, $val]);
        flash('success', $val === '1' ? 'Digital Card feature enabled globally.' : 'Digital Card feature disabled globally.');
        redirect(SITE_URL . '/admin/digital-cards');
    }

    // Per-listing toggle
    if ($action === 'toggle_card') {
        $lid = (int)($_POST['listing_id'] ?? 0);
        if ($lid) {
            $cur = db()->prepare("SELECT card_disabled FROM listings WHERE id=?");
            $cur->execute([$lid]);
            $row = $cur->fetch();
            if ($row !== false) {
                $newVal = $row['card_disabled'] ? 0 : 1;
                db()->prepare("UPDATE listings SET card_disabled=? WHERE id=?")->execute([$newVal, $lid]);
                flash('success', $newVal ? 'Card disabled for this business.' : 'Card enabled for this business.');
            }
        }
        redirect(SITE_URL . '/admin/digital-cards?' . http_build_query(array_filter([
            'q'    => $_GET['q'] ?? '',
            'page' => $_GET['page'] ?? '',
        ])));
    }

    // Bulk toggle
    if (in_array($action, ['bulk_disable', 'bulk_enable']) && !empty($_POST['listing_ids'])) {
        $ids   = array_filter(array_map('intval', (array)$_POST['listing_ids']));
        $newVal = ($action === 'bulk_disable') ? 1 : 0;
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            db()->prepare("UPDATE listings SET card_disabled=$newVal WHERE id IN ($ph)")->execute($ids);
            flash('success', count($ids) . ' listing(s) ' . ($newVal ? 'disabled.' : 'enabled.'));
        }
        redirect(SITE_URL . '/admin/digital-cards');
    }

    redirect(SITE_URL . '/admin/digital-cards');
}

// ── Global feature state ──────────────────────────────────────────────────
$globalRow = db()->query("SELECT value FROM site_settings WHERE `key`='card_feature_enabled'")->fetchColumn();
$globalEnabled = ($globalRow === false) ? true : (bool)(int)$globalRow; // default ON

// ── Platform analytics summary ────────────────────────────────────────────
$range    = in_array($_GET['range'] ?? '30', ['7','30','90']) ? $_GET['range'] : '30';
$rangeSQL = "created_at >= DATE_SUB(NOW(), INTERVAL {$range} DAY)";

$platformStats = [];
try {
    $pst = db()->prepare("
        SELECT
            SUM(event_subtype = 'card_card_view')        AS total_views,
            SUM(event_subtype = 'card_contact_download') AS total_downloads,
            SUM(event_subtype = 'card_phone_click')      AS total_phone,
            SUM(event_subtype = 'card_whatsapp_click')   AS total_whatsapp,
            SUM(event_subtype = 'card_website_click')    AS total_website,
            SUM(event_subtype = 'card_share_click')      AS total_shares,
            COUNT(DISTINCT entity_id)                    AS active_businesses
        FROM marketplace_impressions
        WHERE entity_type='listing' AND event_subtype LIKE 'card_%' AND $rangeSQL
    ");
    $pst->execute();
    $platformStats = $pst->fetch() ?: [];
} catch (Exception $e) {}

// Top businesses by scans
$topBusinesses = [];
try {
    $tb = db()->prepare("
        SELECT l.id, l.title, l.slug, l.logo, l.card_disabled,
               u.email AS user_email,
               COUNT(*) AS total_events,
               SUM(mi.event_subtype = 'card_card_view') AS scan_count
        FROM marketplace_impressions mi
        JOIN listings l ON l.id = mi.entity_id
        LEFT JOIN users u ON u.id = l.user_id
        WHERE mi.entity_type='listing' AND mi.event_subtype LIKE 'card_%' AND mi.$rangeSQL
        GROUP BY l.id
        ORDER BY scan_count DESC
        LIMIT 10
    ");
    $tb->execute();
    $topBusinesses = $tb->fetchAll();
} catch (Exception $e) {}

// Daily platform trend (last $range days)
$trendDays  = [];
$trendViews = [];
try {
    $td = db()->prepare("
        SELECT DATE(created_at) AS day, COUNT(*) AS cnt
        FROM marketplace_impressions
        WHERE entity_type='listing' AND event_subtype='card_card_view' AND $rangeSQL
        GROUP BY DATE(created_at)
        ORDER BY day ASC
    ");
    $td->execute();
    $byDay = [];
    foreach ($td->fetchAll() as $row) { $byDay[$row['day']] = (int)$row['cnt']; }
    for ($i = (int)$range - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $trendDays[]  = date('d M', strtotime($d));
        $trendViews[] = $byDay[$d] ?? 0;
    }
} catch (Exception $e) {}

// ── Listings table with search/filter ────────────────────────────────────
$search  = trim($_GET['q'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$where  = ["l.status='approved'"];
$params = [];
if ($search) {
    $where[] = "(l.title LIKE ? OR u.name LIKE ? OR u.email LIKE ?)";
    $params  = ["%$search%", "%$search%", "%$search%"];
}
$whereStr = implode(' AND ', $where);

try {
    $cntSt = db()->prepare("
        SELECT COUNT(*) FROM listings l
        LEFT JOIN users u ON u.id=l.user_id
        WHERE $whereStr
    ");
    $cntSt->execute($params);
    $total      = (int)$cntSt->fetchColumn();
    $totalPages = max(1, ceil($total / $perPage));

    $lst = db()->prepare("
        SELECT l.id, l.title, l.slug, l.logo, l.card_disabled, l.views,
               u.name AS user_name, u.email AS user_email,
               sp.slug AS plan_slug,
               (SELECT COUNT(*) FROM marketplace_impressions mi
                WHERE mi.entity_type='listing' AND mi.entity_id=l.id
                  AND mi.event_subtype='card_card_view'
                  AND mi.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS card_scans_30d
        FROM listings l
        LEFT JOIN users u ON u.id=l.user_id
        LEFT JOIN business_subscriptions bs ON bs.listing_id=l.id AND bs.status='active'
        LEFT JOIN subscription_plans sp ON sp.id=bs.plan_id
        WHERE $whereStr
        ORDER BY card_scans_30d DESC, l.title ASC
        LIMIT $perPage OFFSET $offset
    ");
    $lst->execute($params);
    $listings = $lst->fetchAll();
} catch (Exception $e) {
    $listings   = [];
    $total      = 0;
    $totalPages = 1;
}

require_once __DIR__ . '/includes/nav.php';
adminNavAssets();
adminNav('digital-cards');
?>
<style>
body{background:#060e0a;color:#cde8d8;font-family:system-ui,sans-serif;margin:0}

.dc-wrap{max-width:1200px;margin:0 auto;padding:1.5rem 1rem 3rem}
.dc-title{font-size:1.4rem;font-weight:800;color:#fff;margin-bottom:1.5rem}

/* ── Global toggle card ─── */
.dc-global{
  display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;
  background:#0d1f16;border:1px solid rgba(0,168,120,.25);border-radius:14px;
  padding:1.25rem 1.5rem;margin-bottom:1.5rem;
}
.dc-global-info h3{font-size:1rem;font-weight:700;color:#fff;margin:0 0 .2rem}
.dc-global-info p{font-size:.8rem;color:#8db8a8;margin:0}
.dc-toggle-form{display:flex;align-items:center;gap:.75rem}
.dc-pill{
  display:inline-block;padding:.3rem .9rem;border-radius:50px;font-size:.78rem;font-weight:700;
}
.dc-pill-on{background:#00A878;color:#0A1A0F}
.dc-pill-off{background:#c0392b;color:#fff}
.btn-toggle-on{
  background:#00A878;color:#0A1A0F;border:none;border-radius:8px;
  padding:.5rem 1.1rem;font-size:.82rem;font-weight:700;cursor:pointer;
}
.btn-toggle-off{
  background:#c0392b;color:#fff;border:none;border-radius:8px;
  padding:.5rem 1.1rem;font-size:.82rem;font-weight:700;cursor:pointer;
}

/* ── Stats grid ─── */
.dc-stats{
  display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));
  gap:.75rem;margin-bottom:1.5rem;
}
.dc-stat{
  background:#0d1f16;border:1px solid rgba(0,168,120,.15);border-radius:12px;
  padding:.9rem 1rem;
}
.dc-stat-val{font-size:1.6rem;font-weight:800;color:#00A878;line-height:1}
.dc-stat-label{font-size:.72rem;color:#8db8a8;margin-top:.3rem}

/* ── Chart ─── */
.dc-chart{
  background:#0d1f16;border:1px solid rgba(0,168,120,.15);border-radius:14px;
  padding:1.1rem 1.25rem;margin-bottom:1.5rem;
}
.dc-chart-title{font-size:.78rem;font-weight:700;color:#8db8a8;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.85rem}
.dc-chart-wrap{position:relative;height:160px}

/* ── Top businesses ─── */
.dc-top{
  background:#0d1f16;border:1px solid rgba(0,168,120,.15);border-radius:14px;
  padding:1.1rem 1.25rem;margin-bottom:1.5rem;
}
.dc-top-title{font-size:.78rem;font-weight:700;color:#8db8a8;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.75rem}
.dc-top-table{width:100%;border-collapse:collapse;font-size:.82rem}
.dc-top-table th{text-align:left;padding:.4rem .6rem;color:#5a8878;font-size:.72rem;text-transform:uppercase;border-bottom:1px solid rgba(0,168,120,.15)}
.dc-top-table td{padding:.55rem .6rem;border-bottom:1px solid rgba(0,168,120,.07);vertical-align:middle}
.dc-top-table tr:last-child td{border-bottom:none}

/* ── Filter + range bar ─── */
.dc-filter{
  display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;
  margin-bottom:1rem;
}
.dc-filter form{display:flex;gap:.4rem;flex-wrap:wrap}
.dc-filter input[type=text]{
  padding:.4rem .7rem;border:1px solid rgba(0,168,120,.25);border-radius:8px;
  background:#0a1812;color:#cde8d8;font-size:.82rem;width:220px;
}
.dc-filter button,.dc-btn{
  padding:.4rem .9rem;border:none;border-radius:8px;cursor:pointer;
  background:rgba(0,168,120,.15);color:#00A878;font-size:.82rem;font-weight:600;
  text-decoration:none;
}
.dc-btn-sm{padding:.25rem .6rem;font-size:.75rem}
.dc-btn-dis{background:rgba(192,57,43,.15);color:#e74c3c}
.dc-btn-en{background:rgba(0,168,120,.15);color:#00A878}

/* ── Listings table ─── */
.dc-table{width:100%;border-collapse:collapse;font-size:.82rem}
.dc-table th{text-align:left;padding:.5rem .6rem;color:#5a8878;font-size:.72rem;text-transform:uppercase;border-bottom:1px solid rgba(0,168,120,.15)}
.dc-table td{padding:.55rem .6rem;border-bottom:1px solid rgba(0,168,120,.07);vertical-align:middle}
.dc-table tr:last-child td{border-bottom:none}
.dc-table tr:hover td{background:rgba(0,168,120,.04)}
.dc-logo{width:30px;height:30px;border-radius:50%;object-fit:cover;border:1px solid rgba(0,168,120,.2)}
.dc-initials{
  width:30px;height:30px;border-radius:50%;background:rgba(0,168,120,.2);
  display:inline-flex;align-items:center;justify-content:center;
  font-size:.7rem;font-weight:700;color:#00A878;
}
.badge-on{background:rgba(0,168,120,.2);color:#00A878;padding:.15rem .5rem;border-radius:50px;font-size:.7rem;font-weight:700}
.badge-off{background:rgba(192,57,43,.2);color:#e74c3c;padding:.15rem .5rem;border-radius:50px;font-size:.7rem;font-weight:700}
.plan-badge{padding:.15rem .5rem;border-radius:50px;font-size:.68rem;font-weight:700;text-transform:uppercase}
.plan-free{background:rgba(255,255,255,.08);color:#8db8a8}
.plan-growth{background:rgba(0,120,200,.2);color:#66b3ff}
.plan-premium{background:rgba(200,150,0,.2);color:#ffc107}

.dc-wrap-card{background:#0d1f16;border:1px solid rgba(0,168,120,.15);border-radius:14px;padding:1.1rem 1.25rem;overflow-x:auto}

/* ── Pagination ─── */
.dc-pages{display:flex;gap:.4rem;flex-wrap:wrap;margin-top:1rem}
.dc-pages a,.dc-pages span{
  padding:.35rem .65rem;border-radius:7px;font-size:.78rem;text-decoration:none;
  background:rgba(0,168,120,.1);color:#8db8a8;border:1px solid rgba(0,168,120,.15);
}
.dc-pages a:hover{background:rgba(0,168,120,.2);color:#fff}
.dc-pages .cur{background:#00A878;color:#0A1A0F;font-weight:700;border-color:#00A878}

/* ── Range tabs ─── */
.range-tabs{display:flex;gap:.35rem;margin-bottom:1rem}
.range-tab{
  padding:.3rem .75rem;border-radius:7px;font-size:.78rem;text-decoration:none;
  color:#8db8a8;border:1px solid rgba(0,168,120,.15);transition:background .12s;
}
.range-tab:hover{background:rgba(0,168,120,.12);color:#cde8d8}
.range-tab.active{background:#00A878;color:#0A1A0F;font-weight:700;border-color:#00A878}

/* ── Flash ─── */
.flash-success{background:rgba(0,168,120,.2);border:1px solid rgba(0,168,120,.4);color:#00A878;padding:.6rem 1rem;border-radius:8px;margin-bottom:1rem;font-size:.85rem}

@media(max-width:600px){
  .dc-stats{grid-template-columns:repeat(2,1fr)}
  .dc-filter input[type=text]{width:100%}
}
</style>

<div class="dc-wrap">
  <div class="dc-title">🪪 Digital Card & QR Management</div>

  <?php if ($msg = getFlash('success')): ?>
  <div class="flash-success">✓ <?= e($msg) ?></div>
  <?php endif; ?>

  <!-- ── Global Toggle ─────────────────────────────────────── -->
  <div class="dc-global">
    <div class="dc-global-info">
      <h3>Global Feature Switch</h3>
      <p>Enable or disable digital card pages and QR functionality for all businesses on the platform.</p>
    </div>
    <div class="dc-toggle-form">
      <span class="dc-pill <?= $globalEnabled ? 'dc-pill-on' : 'dc-pill-off' ?>">
        <?= $globalEnabled ? 'ENABLED' : 'DISABLED' ?>
      </span>
      <form method="POST" action="<?= SITE_URL ?>/admin/digital-cards">
        <?= csrf() ?>
        <input type="hidden" name="action" value="global_toggle">
        <?php if ($globalEnabled): ?>
          <input type="hidden" name="enabled" value="0">
          <button type="submit" class="btn-toggle-off" onclick="return confirm('Disable digital cards for ALL businesses?')">🔴 Disable All Cards</button>
        <?php else: ?>
          <input type="hidden" name="enabled" value="1">
          <button type="submit" class="btn-toggle-on">🟢 Enable All Cards</button>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <!-- ── Range tabs ─────────────────────────────────────────── -->
  <div class="range-tabs">
    <?php foreach (['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days'] as $k => $v): ?>
    <a href="?range=<?= $k ?>&q=<?= urlencode($search) ?>" class="range-tab <?= $range === $k ? 'active' : '' ?>"><?= $v ?></a>
    <?php endforeach; ?>
  </div>

  <!-- ── Platform Stats ─────────────────────────────────────── -->
  <div class="dc-stats">
    <div class="dc-stat">
      <div class="dc-stat-val"><?= number_format((int)($platformStats['total_views'] ?? 0)) ?></div>
      <div class="dc-stat-label">Card Views / Scans</div>
    </div>
    <div class="dc-stat">
      <div class="dc-stat-val"><?= number_format((int)($platformStats['total_downloads'] ?? 0)) ?></div>
      <div class="dc-stat-label">Contact Downloads</div>
    </div>
    <div class="dc-stat">
      <div class="dc-stat-val"><?= number_format((int)($platformStats['total_phone'] ?? 0)) ?></div>
      <div class="dc-stat-label">Phone Clicks</div>
    </div>
    <div class="dc-stat">
      <div class="dc-stat-val"><?= number_format((int)($platformStats['total_whatsapp'] ?? 0)) ?></div>
      <div class="dc-stat-label">WhatsApp Clicks</div>
    </div>
    <div class="dc-stat">
      <div class="dc-stat-val"><?= number_format((int)($platformStats['total_website'] ?? 0)) ?></div>
      <div class="dc-stat-label">Website Clicks</div>
    </div>
    <div class="dc-stat">
      <div class="dc-stat-val"><?= number_format((int)($platformStats['total_shares'] ?? 0)) ?></div>
      <div class="dc-stat-label">Card Shares</div>
    </div>
    <div class="dc-stat">
      <div class="dc-stat-val"><?= number_format((int)($platformStats['active_businesses'] ?? 0)) ?></div>
      <div class="dc-stat-label">Active Businesses</div>
    </div>
  </div>

  <!-- ── Platform Trend Chart ───────────────────────────────── -->
  <?php if (!empty($trendDays)): ?>
  <div class="dc-chart">
    <div class="dc-chart-title">📈 Platform Card Views — Last <?= $range ?> days</div>
    <div class="dc-chart-wrap"><canvas id="platformChart"></canvas></div>
  </div>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
  <script>
  (function(){
    var ctx = document.getElementById('platformChart').getContext('2d');
    new Chart(ctx,{
      type:'line',
      data:{
        labels:<?= json_encode($trendDays) ?>,
        datasets:[{
          label:'Card Views',
          data:<?= json_encode($trendViews) ?>,
          borderColor:'#00A878',
          backgroundColor:'rgba(0,168,120,.12)',
          borderWidth:2,tension:.35,fill:true,
          pointRadius:<?= count($trendDays) > 30 ? 0 : 2 ?>
        }]
      },
      options:{
        responsive:true,maintainAspectRatio:false,
        plugins:{legend:{display:false}},
        scales:{
          x:{ticks:{color:'#5a8878',font:{size:10},maxTicksLimit:12,maxRotation:45},grid:{color:'rgba(0,168,120,.07)'}},
          y:{beginAtZero:true,ticks:{color:'#5a8878',font:{size:10},stepSize:1,precision:0},grid:{color:'rgba(0,168,120,.07)'}}
        }
      }
    });
  })();
  </script>
  <?php endif; ?>

  <!-- ── Top 10 Businesses by Scans ────────────────────────── -->
  <?php if (!empty($topBusinesses)): ?>
  <div class="dc-top">
    <div class="dc-top-title">🏆 Top Businesses by Card Scans (last <?= $range ?> days)</div>
    <table class="dc-top-table">
      <thead>
        <tr>
          <th>#</th><th>Business</th><th>Owner</th><th>Scans</th><th>Events</th><th>Status</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($topBusinesses as $i => $b): ?>
        <tr>
          <td style="color:#5a8878"><?= $i + 1 ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:.5rem">
              <?php if ($b['logo']): ?>
                <img src="<?= UPLOAD_URL ?>/<?= e($b['logo']) ?>" class="dc-logo" alt="">
              <?php else: ?>
                <span class="dc-initials"><?= mb_strtoupper(mb_substr($b['title'], 0, 2)) ?></span>
              <?php endif; ?>
              <a href="<?= SITE_URL ?>/card/<?= e($b['slug']) ?>" target="_blank" style="color:#cde8d8;text-decoration:none"><?= e($b['title']) ?></a>
            </div>
          </td>
          <td style="color:#8db8a8;font-size:.78rem"><?= e($b['user_email'] ?? '—') ?></td>
          <td><strong style="color:#00A878"><?= number_format((int)$b['scan_count']) ?></strong></td>
          <td style="color:#8db8a8"><?= number_format((int)$b['total_events']) ?></td>
          <td>
            <?php if ($b['card_disabled']): ?>
              <span class="badge-off">Disabled</span>
            <?php else: ?>
              <span class="badge-on">Active</span>
            <?php endif; ?>
          </td>
          <td>
            <form method="POST" action="<?= SITE_URL ?>/admin/digital-cards" style="display:inline">
              <?= csrf() ?>
              <input type="hidden" name="action" value="toggle_card">
              <input type="hidden" name="listing_id" value="<?= (int)$b['id'] ?>">
              <button type="submit" class="dc-btn dc-btn-sm <?= $b['card_disabled'] ? 'dc-btn-en' : 'dc-btn-dis' ?>">
                <?= $b['card_disabled'] ? 'Enable' : 'Disable' ?>
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- ── All Businesses Table ───────────────────────────────── -->
  <div class="dc-filter">
    <form method="GET" action="<?= SITE_URL ?>/admin/digital-cards" style="display:flex;gap:.4rem;flex-wrap:wrap">
      <input type="hidden" name="range" value="<?= e($range) ?>">
      <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search business / owner…">
      <button type="submit" class="dc-btn">Search</button>
      <?php if ($search): ?>
        <a href="<?= SITE_URL ?>/admin/digital-cards?range=<?= $range ?>" class="dc-btn">Clear</a>
      <?php endif; ?>
    </form>
    <span style="margin-left:auto;font-size:.78rem;color:#5a8878"><?= number_format($total) ?> approved listing<?= $total !== 1 ? 's' : '' ?></span>
  </div>

  <form method="POST" action="<?= SITE_URL ?>/admin/digital-cards" id="bulkForm">
    <?= csrf() ?>
    <div style="display:flex;gap:.5rem;margin-bottom:.75rem;flex-wrap:wrap">
      <button type="submit" name="action" value="bulk_disable" class="dc-btn dc-btn-dis" onclick="return document.querySelectorAll('.bulk-chk:checked').length>0||alert('Select at least one listing')&&false">Disable selected</button>
      <button type="submit" name="action" value="bulk_enable"  class="dc-btn dc-btn-en" onclick="return document.querySelectorAll('.bulk-chk:checked').length>0||alert('Select at least one listing')&&false">Enable selected</button>
    </div>

  <div class="dc-wrap-card">
    <table class="dc-table">
      <thead>
        <tr>
          <th><input type="checkbox" id="chkAll" onchange="document.querySelectorAll('.bulk-chk').forEach(function(c){c.checked=this.checked},this)"></th>
          <th>Business</th>
          <th>Owner</th>
          <th>Plan</th>
          <th>Card Scans (30d)</th>
          <th>Card Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($listings)): ?>
        <tr><td colspan="7" style="text-align:center;color:#5a8878;padding:2rem">No listings found.</td></tr>
        <?php else: ?>
        <?php foreach ($listings as $l): ?>
        <tr>
          <td><input type="checkbox" name="listing_ids[]" value="<?= (int)$l['id'] ?>" class="bulk-chk"></td>
          <td>
            <div style="display:flex;align-items:center;gap:.5rem">
              <?php if ($l['logo']): ?>
                <img src="<?= UPLOAD_URL ?>/<?= e($l['logo']) ?>" class="dc-logo" alt="">
              <?php else: ?>
                <span class="dc-initials"><?= mb_strtoupper(mb_substr($l['title'], 0, 2)) ?></span>
              <?php endif; ?>
              <div>
                <div style="font-weight:600;color:#cde8d8"><?= e($l['title']) ?></div>
                <div style="font-size:.7rem;color:#5a8878">/card/<?= e($l['slug']) ?></div>
              </div>
            </div>
          </td>
          <td style="font-size:.78rem;color:#8db8a8"><?= e($l['user_name'] ?? '—') ?><br><span style="color:#5a8878"><?= e($l['user_email'] ?? '') ?></span></td>
          <td>
            <?php $ps = $l['plan_slug'] ?? 'free'; ?>
            <span class="plan-badge plan-<?= e($ps) ?>"><?= e($ps) ?></span>
          </td>
          <td style="text-align:center">
            <?php $scans = (int)$l['card_scans_30d']; ?>
            <?php if ($scans > 0): ?>
              <strong style="color:#00A878"><?= number_format($scans) ?></strong>
            <?php else: ?>
              <span style="color:#3a5848">—</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($l['card_disabled']): ?>
              <span class="badge-off">Disabled</span>
            <?php else: ?>
              <span class="badge-on">Active</span>
            <?php endif; ?>
          </td>
          <td>
            <div style="display:flex;gap:.4rem;flex-wrap:wrap">
              <a href="<?= SITE_URL ?>/card/<?= e($l['slug']) ?>" target="_blank" class="dc-btn dc-btn-sm" style="color:#8db8a8">View</a>
              <a href="<?= SITE_URL ?>/card-analytics?listing_id=<?= (int)$l['id'] ?>" class="dc-btn dc-btn-sm">Analytics</a>
              <form method="POST" action="<?= SITE_URL ?>/admin/digital-cards" style="display:inline">
                <?= csrf() ?>
                <input type="hidden" name="action" value="toggle_card">
                <input type="hidden" name="listing_id" value="<?= (int)$l['id'] ?>">
                <input type="hidden" name="q" value="<?= e($search) ?>">
                <button type="submit" class="dc-btn dc-btn-sm <?= $l['card_disabled'] ? 'dc-btn-en' : 'dc-btn-dis' ?>">
                  <?= $l['card_disabled'] ? '🟢 Enable' : '🔴 Disable' ?>
                </button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  </form>

  <!-- ── Pagination ─────────────────────────────────────────── -->
  <?php if ($totalPages > 1): ?>
  <div class="dc-pages">
    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
      <?php $url = SITE_URL . '/admin/digital-cards?' . http_build_query(array_filter(['q' => $search, 'range' => $range, 'page' => $i > 1 ? $i : ''])); ?>
      <?php if ($i === $page): ?>
        <span class="cur"><?= $i ?></span>
      <?php else: ?>
        <a href="<?= $url ?>"><?= $i ?></a>
      <?php endif; ?>
    <?php endfor; ?>
  </div>
  <?php endif; ?>

</div>

<?php
// Also honour the global flag on card.php — inject a note in the page footer
// (card.php itself will read site_settings in a follow-up if needed)
?>
