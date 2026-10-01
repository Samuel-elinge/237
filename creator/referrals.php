<?php
/**
 * creator/referrals.php — Referral tracking for creators
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/referral-helpers.php';
$u = currentUser();
if (!$u || !in_array($u['role'],['creator','admin'])) redirect(SITE_URL . '/login');
$uid = (int)$u['id'];
$pdo = db();

$link = $pdo->prepare("SELECT * FROM referral_links WHERE user_id=?");
$link->execute([$uid]);
$link = $link->fetch();

$commSummary = getUserCommissionSummary($uid);

// All conversions
$conversions = [];
if ($link) {
    $cv = $pdo->prepare("
        SELECT rc.*, l.title AS listing_title, l.slug AS listing_slug
        FROM referral_conversions rc
        LEFT JOIN listings l ON l.id=rc.listing_id
        WHERE rc.link_id=? ORDER BY rc.created_at DESC
    ");
    $cv->execute([$link['id']]);
    $conversions = $cv->fetchAll();
}

// All commission entries
$commissions = $pdo->prepare("SELECT * FROM commissions WHERE user_id=? ORDER BY created_at DESC");
$commissions->execute([$uid]);
$commissions = $commissions->fetchAll();

// Click stats last 30 days
$clickStats = [];
if ($link) {
    $cs = $pdo->prepare("
        SELECT DATE(clicked_at) AS day, COUNT(*) AS total, SUM(is_unique) AS unique_count, SUM(converted) AS converted
        FROM referral_clicks WHERE link_id=? AND clicked_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY DATE(clicked_at) ORDER BY day DESC
    ");
    $cs->execute([$link['id']]);
    $clickStats = $cs->fetchAll();
}

$pageTitle = 'My Referrals — 237Biz Creator';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.nav-pill{padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;color:rgba(255,255,255,0.6);border:1px solid rgba(255,255,255,0.1);}
.nav-pill:hover,.nav-pill.active{background:rgba(0,168,120,0.15);color:#00A878;border-color:rgba(0,168,120,0.35);}
.stat-card{background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:14px 18px;}
.stat-card .val{font-size:1.7rem;font-weight:900;font-family:'Fraunces',serif;}
.stat-card .lbl{font-size:11.5px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;}
.row-item{padding:10px 0;border-bottom:1px solid rgba(255,255,255,0.05);display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
</style>

<div style="height:65px;"></div>
<div class="page-header"><div class="container">
  <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.4rem,3vw,1.9rem);">🔗 My Referrals</h1>
</div></div>

<section class="page-section" style="padding-top:1.25rem;"><div class="container">

  <div style="display:flex;gap:8px;margin-bottom:1.5rem;flex-wrap:wrap;">
    <a href="<?= SITE_URL ?>/creator/dashboard"   class="nav-pill">🏠 Dashboard</a>
    <a href="<?= SITE_URL ?>/creator/profile"     class="nav-pill">👤 My Profile</a>
    <a href="<?= SITE_URL ?>/creator/referrals"   class="nav-pill active">🔗 Referrals</a>
    <a href="<?= SITE_URL ?>/creator/campaigns"   class="nav-pill">📣 Campaigns</a>
    <a href="<?= SITE_URL ?>/creator/submissions" class="nav-pill">📤 Submissions</a>
  </div>

  <!-- Referral link box -->
  <?php if ($link): ?>
  <div style="background:rgba(0,168,120,0.06);border:1px solid rgba(0,168,120,0.25);border-radius:12px;padding:16px 20px;margin-bottom:1.5rem;">
    <div style="font-size:12.5px;color:var(--muted);margin-bottom:6px;">🔗 Your referral link</div>
    <div style="font-family:monospace;font-size:14px;color:#00A878;background:rgba(0,0,0,0.2);border-radius:7px;padding:8px 14px;display:inline-block;margin-bottom:10px;"><?= SITE_URL ?>/r/<?= e($link['code']) ?></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <button onclick="navigator.clipboard.writeText('<?= SITE_URL ?>/r/<?= e($link['code']) ?>').then(()=>this.textContent='✅ Copied!')"
              style="background:rgba(0,168,120,0.2);border:1px solid rgba(0,168,120,0.4);color:#00A878;border-radius:7px;padding:6px 14px;font-size:12.5px;cursor:pointer;font-family:inherit;font-weight:600;">📋 Copy</button>
      <a href="https://wa.me/?text=<?= urlencode('Find Cameroon businesses on 237Biz: ' . SITE_URL . '/r/' . $link['code']) ?>" target="_blank"
         style="background:rgba(37,211,102,0.12);border:1px solid rgba(37,211,102,0.3);color:#25d366;border-radius:7px;padding:6px 14px;font-size:12.5px;text-decoration:none;font-weight:600;">💬 Share</a>
    </div>
  </div>
  <?php endif; ?>

  <!-- Stats -->
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;margin-bottom:1.5rem;">
    <div class="stat-card"><div class="lbl">Total clicks</div><div class="val"><?= $link?(int)$link['clicks']:0 ?></div></div>
    <div class="stat-card"><div class="lbl">Unique clicks</div><div class="val"><?= $link?(int)$link['unique_clicks']:0 ?></div></div>
    <div class="stat-card"><div class="lbl">Conversions</div><div class="val" style="color:#00A878;"><?= count($conversions) ?></div></div>
    <div class="stat-card"><div class="lbl">Pending</div><div class="val" style="font-size:1.2rem;color:#fcd116;"><?= formatXaf($commSummary['pending_xaf']) ?></div></div>
    <div class="stat-card"><div class="lbl">Approved</div><div class="val" style="font-size:1.2rem;color:#00A878;"><?= formatXaf($commSummary['approved_xaf']) ?></div></div>
    <div class="stat-card"><div class="lbl">Paid out</div><div class="val" style="font-size:1.2rem;color:#8ab4f8;"><?= formatXaf($commSummary['paid_xaf']) ?></div></div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;" class="ref-layout">

    <!-- Conversions -->
    <div>
      <h2 style="font-size:1rem;font-weight:700;margin-bottom:12px;">📈 Businesses Referred</h2>
      <?php if (empty($conversions)): ?>
      <p style="color:var(--muted);font-size:13.5px;">No conversions yet. Share your link to start earning.</p>
      <?php else: ?>
      <?php foreach ($conversions as $cv): ?>
      <div class="row-item">
        <div style="flex:1;">
          <?php if ($cv['listing_title']): ?>
          <div style="font-weight:600;font-size:13.5px;"><?= e($cv['listing_title']) ?></div>
          <a href="<?= SITE_URL ?>/listing/<?= e($cv['listing_slug']) ?>" target="_blank" style="font-size:12px;color:var(--green);">View →</a>
          <?php else: ?>
          <div style="font-size:13.5px;color:var(--muted);">Listing #<?= $cv['listing_id'] ?></div>
          <?php endif; ?>
          <div style="font-size:12px;color:var(--muted);margin-top:3px;"><?= str_replace('_',' ',ucfirst($cv['conversion_type'])) ?> · <?= date('d M Y',strtotime($cv['created_at'])) ?></div>
        </div>
        <div style="text-align:right;">
          <div style="font-weight:700;font-size:13.5px;color:#00A878;"><?= formatXaf((int)$cv['commission_xaf']) ?></div>
          <span style="font-size:11.5px;font-weight:700;padding:2px 9px;border-radius:99px;background:rgba(255,255,255,0.05);color:<?= ['pending'=>'#fcd116','approved'=>'#00A878','paid'=>'#8ab4f8','rejected'=>'#ff6b7a'][$cv['status']] ?? 'var(--muted)' ?>;"><?= ucfirst($cv['status']) ?></span>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- All commissions -->
    <div>
      <h2 style="font-size:1rem;font-weight:700;margin-bottom:12px;">💰 All Earnings</h2>
      <?php if (empty($commissions)): ?>
      <p style="color:var(--muted);font-size:13.5px;">No earnings recorded yet.</p>
      <?php else: ?>
      <?php foreach ($commissions as $c): ?>
      <div class="row-item">
        <div style="flex:1;">
          <div style="font-size:13px;font-weight:600;"><?= str_replace('_',' ',ucfirst($c['type'])) ?></div>
          <?php if ($c['description']): ?><div style="font-size:12px;color:var(--muted);"><?= e($c['description']) ?></div><?php endif; ?>
          <div style="font-size:11.5px;color:var(--muted);"><?= date('d M Y',strtotime($c['created_at'])) ?></div>
        </div>
        <div style="text-align:right;">
          <div style="font-weight:700;color:#00A878;">+<?= formatXaf((int)$c['amount_xaf']) ?></div>
          <?= commissionStatusBadge($c['status']) ?>
          <?php if ($c['status']==='paid' && $c['payment_ref']): ?>
          <div style="font-size:11px;color:var(--muted);margin-top:2px;">Ref: <?= e($c['payment_ref']) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>

  </div>

</div></section>

<style>@media(max-width:700px){.ref-layout{grid-template-columns:1fr!important;}}</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
