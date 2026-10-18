<?php
/**
 * admin/analytics-overview.php — 237Biz
 * Cameroon-wide analytics: listings, users, referrals, commissions, top performers.
 */
require_once __DIR__ . '/../includes/config.php';
if (file_exists(__DIR__ . '/../includes/referral-helpers.php')) {
    require_once __DIR__ . '/../includes/referral-helpers.php';
}
requireAdmin();
$pdo = db();

if (!function_exists('formatXaf')) {
    function formatXaf(int $amount): string { return number_format($amount) . ' XAF'; }
}

// ── Date range filter ─────────────────────────────────────────────────────
$range = $_GET['range'] ?? '30';
$days  = in_array($range, ['7','30','90','365']) ? (int)$range : 30;
$since = date('Y-m-d', strtotime("-{$days} days"));

// ── Core platform stats ───────────────────────────────────────────────────
$listings = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(status='approved') AS approved,
        SUM(status='pending')  AS pending,
        SUM(status='rejected') AS rejected,
        SUM(featured=1 AND status='approved') AS featured,
        SUM(views) AS total_views
    FROM listings
")->fetch();

$users = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(role='user')        AS regular,
        SUM(role='sales_staff') AS agents,
        SUM(role='creator')     AS creators,
        SUM(role='admin')       AS admins,
        SUM(verified=1)         AS verified
    FROM users
")->fetch();

// Views in period
$periodViews = $pdo->prepare("SELECT COALESCE(SUM(view_count),0) FROM listing_views WHERE date >= ?")->execute([$since]) ? null : 0;
try {
    $pv = $pdo->prepare("SELECT COALESCE(SUM(view_count),0) FROM listing_views WHERE date >= ?");
    $pv->execute([$since]);
    $periodViews = (int)$pv->fetchColumn();
} catch (Exception $e) { $periodViews = 0; }

// New listings in period
try {
    $nl = $pdo->prepare("SELECT COUNT(*) FROM listings WHERE created_at >= ?");
    $nl->execute([$since]);
    $newListings = (int)$nl->fetchColumn();
} catch (Exception $e) { $newListings = 0; }

// New users in period
try {
    $nu = $pdo->prepare("SELECT COUNT(*) FROM users WHERE created_at >= ?");
    $nu->execute([$since]);
    $newUsers = (int)$nu->fetchColumn();
} catch (Exception $e) { $newUsers = 0; }

// Bookings in period
try {
    $bk = $pdo->prepare("SELECT COUNT(*) FROM listing_bookings WHERE created_at >= ?");
    $bk->execute([$since]);
    $bookings = (int)$bk->fetchColumn();
} catch (Exception $e) { $bookings = 0; }

// ── Referral stats ────────────────────────────────────────────────────────
$refStats = ['total_clicks'=>0,'unique_clicks'=>0,'conversions'=>0,'commission_generated'=>0,'commission_paid'=>0];
try {
    $rs = $pdo->query("SELECT SUM(clicks) AS total_clicks, SUM(unique_clicks) AS unique_clicks FROM referral_links");
    $row = $rs->fetch();
    $refStats['total_clicks']  = (int)($row['total_clicks']  ?? 0);
    $refStats['unique_clicks'] = (int)($row['unique_clicks'] ?? 0);
} catch (Exception $e) {}

try {
    $rc = $pdo->prepare("SELECT COUNT(*) AS conv, SUM(commission_xaf) AS xaf FROM referral_conversions WHERE created_at >= ?");
    $rc->execute([$since]);
    $row = $rc->fetch();
    $refStats['conversions']           = (int)($row['conv'] ?? 0);
    $refStats['commission_generated']  = (int)($row['xaf']  ?? 0);
} catch (Exception $e) {}

try {
    $cp = $pdo->prepare("SELECT SUM(amount_xaf) FROM commissions WHERE status='paid' AND paid_at >= ?");
    $cp->execute([$since]);
    $refStats['commission_paid'] = (int)($cp->fetchColumn() ?? 0);
} catch (Exception $e) {}

// Commission totals by status
$commTotals = ['pending'=>0,'approved'=>0,'paid'=>0];
try {
    $ct = $pdo->query("SELECT status, SUM(amount_xaf) AS total FROM commissions GROUP BY status");
    foreach ($ct->fetchAll() as $row) {
        if (isset($commTotals[$row['status']])) $commTotals[$row['status']] = (int)$row['total'];
    }
} catch (Exception $e) {}

// ── Top agents ────────────────────────────────────────────────────────────
$topAgents = [];
try {
    $topAgents = $pdo->query("
        SELECT u.id, u.name, u.email,
            rl.clicks, rl.unique_clicks,
            (SELECT COUNT(*) FROM agent_assignments aa WHERE aa.agent_id=u.id) AS leads,
            (SELECT COUNT(*) FROM agent_assignments aa WHERE aa.agent_id=u.id AND aa.status='converted') AS converted,
            (SELECT SUM(c.amount_xaf) FROM commissions c WHERE c.user_id=u.id AND c.status IN ('approved','paid')) AS earned_xaf
        FROM users u
        LEFT JOIN referral_links rl ON rl.user_id=u.id
        WHERE u.role='sales_staff'
        ORDER BY earned_xaf DESC, converted DESC
        LIMIT 10
    ")->fetchAll();
} catch (Exception $e) {}

// ── Top creators ──────────────────────────────────────────────────────────
$topCreators = [];
try {
    $topCreators = $pdo->query("
        SELECT u.id, u.name,
            rl.clicks, rl.unique_clicks,
            (SELECT COUNT(*) FROM referral_conversions rc2 JOIN referral_links rl2 ON rl2.id=rc2.link_id WHERE rl2.user_id=u.id) AS conversions,
            (SELECT SUM(c.amount_xaf) FROM commissions c WHERE c.user_id=u.id AND c.status IN ('approved','paid')) AS earned_xaf,
            (SELECT COUNT(*) FROM content_submissions cs WHERE cs.user_id=u.id AND cs.status='approved') AS approved_submissions
        FROM users u
        LEFT JOIN referral_links rl ON rl.user_id=u.id
        WHERE u.role='creator'
        ORDER BY earned_xaf DESC, conversions DESC
        LIMIT 10
    ")->fetchAll();
} catch (Exception $e) {}

// ── Recent conversions ────────────────────────────────────────────────────
$recentConversions = [];
try {
    $recentConversions = $pdo->query("
        SELECT rc.*, u.name AS referrer_name, u.role AS referrer_role,
               l.title AS listing_title
        FROM referral_conversions rc
        JOIN referral_links rl ON rl.id=rc.link_id
        JOIN users u ON u.id=rl.user_id
        LEFT JOIN listings l ON l.id=rc.listing_id
        ORDER BY rc.created_at DESC LIMIT 10
    ")->fetchAll();
} catch (Exception $e) {}

// ── Views chart data (last 30 days) ──────────────────────────────────────
$chartDays  = [];
$chartViews = [];
try {
    $vc = $pdo->prepare("
        SELECT date, SUM(view_count) AS views
        FROM listing_views
        WHERE date >= ?
        GROUP BY date ORDER BY date ASC
    ");
    $vc->execute([$since]);
    foreach ($vc->fetchAll() as $row) {
        $chartDays[]  = date('d M', strtotime($row['date']));
        $chartViews[] = (int)$row['views'];
    }
} catch (Exception $e) {}

// ── Listings by city ─────────────────────────────────────────────────────
$byCity = [];
try {
    $byCity = $pdo->query("
        SELECT loc.name_en AS city, COUNT(*) AS cnt, SUM(l.featured) AS featured
        FROM listings l JOIN locations loc ON loc.id=l.location_id
        WHERE l.status='approved'
        GROUP BY loc.id ORDER BY cnt DESC LIMIT 8
    ")->fetchAll();
} catch (Exception $e) {}

// ── Campaign stats ────────────────────────────────────────────────────────
$campaignStats = [];
try {
    $campaignStats = $pdo->query("
        SELECT c.title, c.status, c.commission_xaf,
            (SELECT COUNT(*) FROM campaign_participants cp WHERE cp.campaign_id=c.id) AS participants,
            (SELECT COUNT(*) FROM content_submissions cs WHERE cs.campaign_id=c.id AND cs.status='approved') AS approved_subs
        FROM campaigns c ORDER BY c.created_at DESC LIMIT 5
    ")->fetchAll();
} catch (Exception $e) {}

$pageTitle = 'Analytics Overview — Admin — 237Biz';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.stat-card { background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:18px 20px; }
.stat-card .val { font-size:1.9rem;font-weight:900;font-family:'Fraunces',serif;line-height:1; }
.stat-card .lbl { font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px; }
.stat-card .sub { font-size:12px;color:var(--muted);margin-top:4px; }
.section-title { font-size:1rem;font-weight:700;margin:2rem 0 1rem; }
.perf-row { display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid rgba(255,255,255,0.05);flex-wrap:wrap; }
.perf-row:last-child { border-bottom:none; }
.bar-wrap { flex:1;min-width:80px;height:6px;background:rgba(255,255,255,0.06);border-radius:3px;overflow:hidden; }
.bar-fill { height:100%;border-radius:3px;background:var(--green); }
.city-card { background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.07);border-radius:10px;padding:12px 16px;display:flex;align-items:center;justify-content:space-between; }
.conv-type { font-size:11px;font-weight:700;padding:2px 8px;border-radius:99px; }
.ct-free     { background:rgba(255,255,255,0.06);color:rgba(255,255,255,0.6); }
.ct-featured { background:rgba(245,200,66,0.12);color:#fcd116; }
.ct-upgrade  { background:rgba(0,168,120,0.12);color:#00A878; }
</style>

<div class="page-header">
  <div class="container">
    <nav class="breadcrumb"><a href="<?= SITE_URL ?>/admin/">Admin</a> › <span>Analytics Overview</span></nav>
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
      <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.5rem,3vw,2rem);margin:0;">📊 Cameroon Analytics</h1>
      <div style="display:flex;gap:6px;">
        <?php foreach (['7'=>'7 days','30'=>'30 days','90'=>'90 days','365'=>'1 year'] as $v=>$l): ?>
        <a href="?range=<?= $v ?>" style="padding:6px 14px;border-radius:7px;font-size:12.5px;font-weight:600;text-decoration:none;<?= $range===$v?'background:rgba(0,168,120,0.2);color:#00A878;border:1px solid rgba(0,168,120,0.4);':'color:var(--muted);border:1px solid rgba(255,255,255,0.1);' ?>"><?= $l ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<section class="page-section" style="padding-top:1.25rem;">
<div class="container">

  <!-- Admin nav -->
  <div class="filter-tabs" style="margin-bottom:2rem;flex-wrap:wrap;">
    <a href="<?= SITE_URL ?>/admin/"                          class="filter-tab">📋 Listings</a>
    <a href="<?= SITE_URL ?>/admin/users.php"                 class="filter-tab">👤 Users</a>
    <a href="<?= SITE_URL ?>/admin/orders.php"                class="filter-tab">📦 Orders</a>
    <a href="<?= SITE_URL ?>/admin/leads.php"                 class="filter-tab">🌐 Website Leads</a>
    <a href="<?= SITE_URL ?>/admin/enquiries.php"             class="filter-tab">📬 Enquiries</a>
    <a href="<?= SITE_URL ?>/admin/claims.php"                class="filter-tab">🏢 Claims</a>
    <a href="<?= SITE_URL ?>/admin/reviews.php"               class="filter-tab">⭐ Reviews</a>
    <a href="<?= SITE_URL ?>/admin/categories.php"            class="filter-tab">📂 Categories</a>
    <a href="<?= SITE_URL ?>/admin/locations.php"             class="filter-tab">📍 Locations</a>
    <a href="<?= SITE_URL ?>/admin/subscribers.php"           class="filter-tab">📬 Newsletter</a>
    <a href="<?= SITE_URL ?>/admin/promos.php"                class="filter-tab">🎁 Promo Codes</a>
    <a href="<?= SITE_URL ?>/admin/packages.php"              class="filter-tab">💳 Packages</a>
    <a href="<?= SITE_URL ?>/admin/seo-content.php"           class="filter-tab">📝 SEO Content</a>
    <a href="<?= SITE_URL ?>/admin/emails.php"                class="filter-tab">✉️ Emails</a>
    <a href="<?= SITE_URL ?>/admin/automation/"               class="filter-tab">🤖 Automation</a>
    <a href="<?= SITE_URL ?>/admin/dashboard.php"             class="filter-tab">📊 Admin Dashboard</a>
    <a href="<?= SITE_URL ?>/admin/analytics-overview.php"    class="filter-tab active">🌍 Analytics</a>
    <a href="<?= SITE_URL ?>/admin/health-check-overview.php" class="filter-tab">🩺 Health Check</a>
    <a href="<?= SITE_URL ?>/admin/manage-agents.php"         class="filter-tab">👔 Agents</a>
    <a href="<?= SITE_URL ?>/admin/manage-creators.php"       class="filter-tab">🎬 Creators</a>
    <a href="<?= SITE_URL ?>/admin/manage-campaigns.php"      class="filter-tab">📣 Campaigns</a>
    <a href="<?= SITE_URL ?>/admin/manage-referrals.php"      class="filter-tab">💰 Referrals</a>
    <a href="<?= SITE_URL ?>/dashboard"                       class="filter-tab">← Dashboard</a>
  </div>

  <!-- Platform stats -->
  <h2 class="section-title">🏪 Platform — last <?= $days ?> days</h2>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;margin-bottom:1.5rem;">
    <div class="stat-card">
      <div class="lbl">Total listings</div>
      <div class="val"><?= number_format((int)$listings['total']) ?></div>
      <div class="sub">+<?= $newListings ?> new</div>
    </div>
    <div class="stat-card">
      <div class="lbl">Approved</div>
      <div class="val" style="color:#00A878;"><?= number_format((int)$listings['approved']) ?></div>
      <div class="sub">⭐ <?= number_format((int)$listings['featured']) ?> featured</div>
    </div>
    <div class="stat-card" style="<?= (int)$listings['pending']>0?'border-color:rgba(252,209,22,0.3);':'' ?>">
      <div class="lbl">Pending review</div>
      <div class="val" style="color:#fcd116;"><?= (int)$listings['pending'] ?></div>
      <?php if ((int)$listings['pending']>0): ?><div class="sub"><a href="<?= SITE_URL ?>/admin/?status=pending" style="color:var(--yellow);">Review →</a></div><?php endif; ?>
    </div>
    <div class="stat-card">
      <div class="lbl">Total views</div>
      <div class="val"><?= number_format((int)$listings['total_views']) ?></div>
      <div class="sub"><?= number_format($periodViews) ?> in period</div>
    </div>
    <div class="stat-card">
      <div class="lbl">Total users</div>
      <div class="val"><?= number_format((int)$users['total']) ?></div>
      <div class="sub">+<?= $newUsers ?> new</div>
    </div>
    <div class="stat-card">
      <div class="lbl">Agents / Creators</div>
      <div class="val" style="font-size:1.3rem;"><?= (int)$users['agents'] ?> <span style="font-size:1rem;color:var(--muted);">/</span> <?= (int)$users['creators'] ?></div>
      <div class="sub"><?= (int)$users['admins'] ?> admins</div>
    </div>
    <div class="stat-card">
      <div class="lbl">Bookings</div>
      <div class="val"><?= $bookings ?></div>
      <div class="sub">in period</div>
    </div>
  </div>

  <!-- Referral stats -->
  <h2 class="section-title">🔗 Referral Performance — last <?= $days ?> days</h2>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-bottom:1.5rem;">
    <div class="stat-card">
      <div class="lbl">Total clicks</div>
      <div class="val"><?= number_format($refStats['total_clicks']) ?></div>
      <div class="sub"><?= number_format($refStats['unique_clicks']) ?> unique</div>
    </div>
    <div class="stat-card">
      <div class="lbl">Conversions</div>
      <div class="val" style="color:#00A878;"><?= $refStats['conversions'] ?></div>
      <div class="sub">in period</div>
    </div>
    <div class="stat-card">
      <div class="lbl">Commission earned</div>
      <div class="val" style="font-size:1.2rem;color:#fcd116;"><?= formatXaf($refStats['commission_generated']) ?></div>
      <div class="sub">in period</div>
    </div>
    <div class="stat-card">
      <div class="lbl">Pending approval</div>
      <div class="val" style="font-size:1.2rem;color:#fcd116;"><?= formatXaf($commTotals['pending']) ?></div>
      <div class="sub"><a href="<?= SITE_URL ?>/admin/manage-referrals.php?tab=ledger&status=pending" style="color:var(--muted);">Review →</a></div>
    </div>
    <div class="stat-card">
      <div class="lbl">Approved (owed)</div>
      <div class="val" style="font-size:1.2rem;color:#00A878;"><?= formatXaf($commTotals['approved']) ?></div>
      <div class="sub"><a href="<?= SITE_URL ?>/admin/manage-referrals.php?tab=ledger&status=approved" style="color:var(--muted);">Pay →</a></div>
    </div>
    <div class="stat-card">
      <div class="lbl">Total paid out</div>
      <div class="val" style="font-size:1.2rem;color:#8ab4f8;"><?= formatXaf($commTotals['paid']) ?></div>
    </div>
  </div>

  <!-- Two column layout: top agents + top creators -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem;" class="perf-grid">

    <!-- Top agents -->
    <div style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.07);border-radius:12px;padding:18px 20px;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
        <h3 style="margin:0;font-size:14px;font-weight:700;">👔 Top Sales Agents</h3>
        <a href="<?= SITE_URL ?>/admin/manage-agents.php" style="font-size:12px;color:var(--green);">Manage →</a>
      </div>
      <?php if (empty($topAgents)): ?>
      <p style="color:var(--muted);font-size:13px;">No agents yet.</p>
      <?php else: ?>
      <?php
      $maxEarned = max(1, max(array_column($topAgents, 'earned_xaf')));
      foreach ($topAgents as $a):
        $pct = min(100, round(($a['earned_xaf']/$maxEarned)*100));
      ?>
      <div class="perf-row">
        <div style="min-width:110px;">
          <div style="font-size:13px;font-weight:600;"><?= e($a['name']) ?></div>
          <div style="font-size:11.5px;color:var(--muted);"><?= (int)$a['converted'] ?>/<?= (int)$a['leads'] ?> converted</div>
        </div>
        <div class="bar-wrap"><div class="bar-fill" style="width:<?= $pct ?>%;background:#8ab4f8;"></div></div>
        <div style="text-align:right;min-width:90px;">
          <div style="font-size:12.5px;font-weight:700;color:#8ab4f8;"><?= formatXaf((int)$a['earned_xaf']) ?></div>
          <div style="font-size:11px;color:var(--muted);"><?= (int)$a['clicks'] ?> clicks</div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Top creators -->
    <div style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.07);border-radius:12px;padding:18px 20px;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
        <h3 style="margin:0;font-size:14px;font-weight:700;">🎬 Top Creators</h3>
        <a href="<?= SITE_URL ?>/admin/manage-creators.php" style="font-size:12px;color:var(--green);">Manage →</a>
      </div>
      <?php if (empty($topCreators)): ?>
      <p style="color:var(--muted);font-size:13px;">No creators yet.</p>
      <?php else: ?>
      <?php
      $maxEarned = max(1, max(array_column($topCreators, 'earned_xaf')));
      foreach ($topCreators as $c):
        $pct = min(100, round(($c['earned_xaf']/$maxEarned)*100));
      ?>
      <div class="perf-row">
        <div style="min-width:110px;">
          <div style="font-size:13px;font-weight:600;"><?= e($c['name']) ?></div>
          <div style="font-size:11.5px;color:var(--muted);"><?= (int)$c['conversions'] ?> conversions · <?= (int)$c['approved_submissions'] ?> submissions</div>
        </div>
        <div class="bar-wrap"><div class="bar-fill" style="width:<?= $pct ?>%;background:#e07be0;"></div></div>
        <div style="text-align:right;min-width:90px;">
          <div style="font-size:12.5px;font-weight:700;color:#e07be0;"><?= formatXaf((int)$c['earned_xaf']) ?></div>
          <div style="font-size:11px;color:var(--muted);"><?= (int)$c['clicks'] ?> clicks</div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>

  </div>

  <!-- Listings by city + campaigns side by side -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem;" class="perf-grid">

    <!-- By city -->
    <div>
      <h2 class="section-title" style="margin-top:0;">📍 Listings by City</h2>
      <?php if (empty($byCity)): ?>
      <p style="color:var(--muted);">No data.</p>
      <?php else:
        $maxCity = max(1, max(array_column($byCity,'cnt')));
        foreach ($byCity as $city):
          $pct = min(100, round(($city['cnt']/$maxCity)*100));
      ?>
      <div class="city-card" style="margin-bottom:6px;">
        <div style="flex:1;">
          <div style="font-size:13px;font-weight:600;"><?= e($city['city']) ?></div>
          <div style="background:rgba(0,168,120,0.2);height:5px;border-radius:3px;margin-top:5px;width:<?= $pct ?>%;min-width:4px;"></div>
        </div>
        <div style="text-align:right;margin-left:12px;">
          <div style="font-size:13px;font-weight:700;"><?= (int)$city['cnt'] ?></div>
          <div style="font-size:11px;color:var(--muted);">⭐ <?= (int)$city['featured'] ?></div>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>

    <!-- Campaign performance -->
    <div>
      <h2 class="section-title" style="margin-top:0;">📣 Recent Campaigns</h2>
      <?php if (empty($campaignStats)): ?>
      <p style="color:var(--muted);font-size:13px;">No campaigns yet. <a href="<?= SITE_URL ?>/admin/manage-campaigns.php?tab=form" style="color:var(--green);">Create one →</a></p>
      <?php else: foreach ($campaignStats as $camp):
        $cStatusColor = ['draft'=>'rgba(255,255,255,0.5)','active'=>'#00A878','completed'=>'#8ab4f8','cancelled'=>'#ff6b7a'][$camp['status']] ?? 'var(--muted)';
      ?>
      <div class="city-card" style="margin-bottom:6px;flex-direction:column;align-items:flex-start;gap:6px;">
        <div style="display:flex;justify-content:space-between;width:100%;align-items:center;">
          <div style="font-size:13px;font-weight:600;"><?= e($camp['title']) ?></div>
          <span style="font-size:11px;font-weight:700;color:<?= $cStatusColor ?>;"><?= ucfirst($camp['status']) ?></span>
        </div>
        <div style="display:flex;gap:14px;font-size:12px;color:var(--muted);">
          <span>👥 <?= (int)$camp['participants'] ?> joined</span>
          <span>✅ <?= (int)$camp['approved_subs'] ?> approved</span>
          <span>💰 <?= formatXaf((int)$camp['commission_xaf']) ?>/sub</span>
        </div>
      </div>
      <?php endforeach; endif; ?>
      <a href="<?= SITE_URL ?>/admin/manage-campaigns.php" style="font-size:12.5px;color:var(--green);display:block;margin-top:8px;">View all campaigns →</a>
    </div>

  </div>

  <!-- Recent referral conversions -->
  <?php if ($recentConversions): ?>
  <h2 class="section-title">🔄 Recent Referral Conversions</h2>
  <div style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.07);border-radius:12px;overflow:hidden;">
    <table style="width:100%;border-collapse:collapse;">
      <thead>
        <tr>
          <?php foreach (['Referrer','Type','Listing','Commission','Status','Date'] as $h): ?>
          <th style="padding:10px 14px;text-align:left;font-size:11.5px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid rgba(255,255,255,0.06);"><?= $h ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($recentConversions as $cv): ?>
        <tr>
          <td style="padding:10px 14px;font-size:13px;">
            <div style="font-weight:600;"><?= e($cv['referrer_name']) ?></div>
            <div style="font-size:11px;color:var(--muted);"><?= ucfirst(str_replace('_',' ',$cv['referrer_role'])) ?></div>
          </td>
          <td style="padding:10px 14px;">
            <?php
            $typeClass = ['free_listing'=>'ct-free','featured_listing'=>'ct-featured','upgrade'=>'ct-upgrade'][$cv['conversion_type']] ?? 'ct-free';
            $typeLabel = str_replace('_',' ',ucfirst($cv['conversion_type']));
            ?>
            <span class="conv-type <?= $typeClass ?>"><?= $typeLabel ?></span>
          </td>
          <td style="padding:10px 14px;font-size:12.5px;color:rgba(255,255,255,0.7);"><?= e($cv['listing_title'] ?? '—') ?></td>
          <td style="padding:10px 14px;font-size:13px;font-weight:700;color:#00A878;"><?= formatXaf((int)$cv['commission_xaf']) ?></td>
          <td style="padding:10px 14px;">
            <?php
            $sc = ['pending'=>['#fcd116','⏳'],'approved'=>['#00A878','✅'],'paid'=>['#8ab4f8','💰'],'rejected'=>['#ff6b7a','✕']];
            [$col,$icon] = $sc[$cv['status']] ?? ['var(--muted)','?'];
            ?>
            <span style="font-size:11.5px;font-weight:700;color:<?= $col ?>;"><?= $icon ?> <?= ucfirst($cv['status']) ?></span>
          </td>
          <td style="padding:10px 14px;font-size:12px;color:var(--muted);"><?= date('d M Y', strtotime($cv['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

</div>
</section>

<style>
@media(max-width:700px){
  .perf-grid { grid-template-columns:1fr !important; }
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
