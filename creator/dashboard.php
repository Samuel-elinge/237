<?php
/**
 * creator/dashboard.php — 237Biz Creator Portal
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/referral-helpers.php';

$u = currentUser();
if (!$u || !in_array($u['role'], ['creator','admin'])) {
    redirect(SITE_URL . '/login');
}
$uid = (int)$u['id'];
$pdo = db();

// Creator profile
$profile = $pdo->prepare("SELECT * FROM creator_profiles WHERE user_id=?");
$profile->execute([$uid]);
$profile = $profile->fetch();

// If no profile yet, redirect to profile setup
if (!$profile) {
    redirect(SITE_URL . '/creator/profile?setup=1');
}

// Referral link
$link = $pdo->prepare("SELECT * FROM referral_links WHERE user_id=?");
$link->execute([$uid]);
$link = $link->fetch();

// Auto-create referral link if approved creator doesn't have one yet
if (!$link && $profile && $profile['status'] === 'approved') {
    try {
        $base = strtolower(preg_replace('/[^a-z0-9]/i', '', $u['name']));
        $base = substr($base, 0, 10) ?: 'creator';
        $code = $base; $i = 1;
        while (true) {
            $chk = $pdo->prepare("SELECT id FROM referral_links WHERE code=?");
            $chk->execute([$code]);
            if (!$chk->fetch()) break;
            $code = $base . $i++;
        }
        $pdo->prepare("INSERT INTO referral_links (user_id, programme_id, code) VALUES (?, 1, ?)")
            ->execute([$uid, $code]);
        $lr = $pdo->prepare("SELECT * FROM referral_links WHERE user_id=?");
        $lr->execute([$uid]);
        $link = $lr->fetch();
    } catch (Exception $e) { $link = null; }
}

// Commission summary
$commSummary = getUserCommissionSummary($uid);

// Recent commissions
$commissions = $pdo->prepare("SELECT * FROM commissions WHERE user_id=? ORDER BY created_at DESC LIMIT 10");
$commissions->execute([$uid]);
$commissions = $commissions->fetchAll();

// Referral conversions
$conversions = $pdo->prepare("
    SELECT rc.*, l.title AS listing_title, l.slug AS listing_slug
    FROM referral_conversions rc
    JOIN referral_links rl ON rl.id = rc.link_id
    LEFT JOIN listings l ON l.id = rc.listing_id
    WHERE rl.user_id=? ORDER BY rc.created_at DESC LIMIT 10
");
$conversions->execute([$uid]);
$conversions = $conversions->fetchAll();

// Referral conversion breakdown (free vs featured vs upgrade)
$convBreakdown = ['free_listing'=>['cnt'=>0,'xaf'=>0],'featured_listing'=>['cnt'=>0,'xaf'=>0],'upgrade'=>['cnt'=>0,'xaf'=>0]];
try {
    $cb = $pdo->prepare("
        SELECT rc.conversion_type, COUNT(*) AS cnt, SUM(rc.commission_xaf) AS xaf
        FROM referral_conversions rc
        JOIN referral_links rl ON rl.id = rc.link_id
        WHERE rl.user_id = ?
        GROUP BY rc.conversion_type
    ");
    $cb->execute([$uid]);
    foreach ($cb->fetchAll() as $row) {
        if (isset($convBreakdown[$row['conversion_type']])) {
            $convBreakdown[$row['conversion_type']] = ['cnt'=>(int)$row['cnt'],'xaf'=>(int)$row['xaf']];
        }
    }
} catch (Exception $e) {}

// My campaigns
$myCampaigns = $pdo->prepare("
    SELECT c.*, cp.status AS part_status, cp.joined_at,
        (SELECT COUNT(*) FROM content_submissions cs WHERE cs.campaign_id=c.id AND cs.user_id=?) AS my_submissions
    FROM campaign_participants cp
    JOIN campaigns c ON c.id=cp.campaign_id
    WHERE cp.user_id=? ORDER BY cp.joined_at DESC
");
$myCampaigns->execute([$uid, $uid]);
$myCampaigns = $myCampaigns->fetchAll();

// Active campaigns available to join
$availableCampaigns = $pdo->prepare("
    SELECT c.* FROM campaigns c
    WHERE c.status='active'
    AND c.id NOT IN (SELECT campaign_id FROM campaign_participants WHERE user_id=?)
    ORDER BY c.created_at DESC LIMIT 5
");
$availableCampaigns->execute([$uid]);
$availableCampaigns = $availableCampaigns->fetchAll();

// Min payout
$prog = $pdo->query("SELECT minimum_payout FROM referral_programmes WHERE id=1 LIMIT 1")->fetch();
$minPayout = (int)($prog['minimum_payout'] ?? 5000);

$isApproved = $profile['status'] === 'approved';

$pageTitle = 'Creator Dashboard — 237Biz';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.creator-stat { background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:16px 20px; }
.creator-stat .val { font-size:1.8rem;font-weight:900;font-family:'Fraunces',serif; }
.creator-stat .lbl { font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px; }
.ref-box { background:rgba(0,168,120,0.06);border:1px solid rgba(0,168,120,0.25);border-radius:12px;padding:18px 22px; }
.ref-url { font-family:monospace;font-size:14px;color:#00A878;background:rgba(0,0,0,0.2);border-radius:7px;padding:8px 14px;word-break:break-all;display:block;margin:10px 0; }
.campaign-chip { background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);border-radius:10px;padding:14px 16px;margin-bottom:8px; }
.nav-pill { padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;color:rgba(255,255,255,0.6);border:1px solid rgba(255,255,255,0.1); }
.nav-pill:hover,.nav-pill.active { background:rgba(0,168,120,0.15);color:#00A878;border-color:rgba(0,168,120,0.35); }
</style>

<div style="height:65px;"></div>

<div class="page-header">
  <div class="container">
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.4rem,3vw,1.9rem);">🎬 Creator Dashboard</h1>
    <p style="color:rgba(255,255,255,0.6);margin:4px 0 0;">Welcome, <?= e($u['name']) ?></p>
  </div>
</div>

<section class="page-section" style="padding-top:1.25rem;">
<div class="container">

  <!-- Creator nav -->
  <div style="display:flex;gap:8px;margin-bottom:1.5rem;flex-wrap:wrap;">
    <a href="<?= SITE_URL ?>/creator/dashboard" class="nav-pill active">🏠 Dashboard</a>
    <a href="<?= SITE_URL ?>/creator/profile"    class="nav-pill">👤 My Profile</a>
    <a href="<?= SITE_URL ?>/creator/referrals"  class="nav-pill">🔗 Referrals</a>
    <a href="<?= SITE_URL ?>/creator/campaigns"  class="nav-pill">📣 Campaigns</a>
    <a href="<?= SITE_URL ?>/creator/submissions" class="nav-pill">📤 Submissions</a>
  </div>

  <!-- Pending approval banner -->
  <?php if ($profile['status'] === 'pending'): ?>
  <div style="background:rgba(252,209,22,0.08);border:1px solid rgba(252,209,22,0.3);border-radius:12px;padding:16px 20px;margin-bottom:1.5rem;display:flex;align-items:center;gap:12px;">
    <div style="font-size:1.5rem;">⏳</div>
    <div>
      <div style="font-weight:700;font-size:14px;color:#fcd116;">Your application is under review</div>
      <div style="font-size:13px;color:rgba(255,255,255,0.6);">You'll receive a notification once approved. Your referral link will be activated at that point.</div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($profile['status'] === 'suspended'): ?>
  <div style="background:rgba(206,17,38,0.08);border:1px solid rgba(206,17,38,0.25);border-radius:12px;padding:16px 20px;margin-bottom:1.5rem;">
    <div style="font-weight:700;color:#ff6b7a;">⚠️ Your creator account is suspended.</div>
    <div style="font-size:13px;color:rgba(255,255,255,0.6);">Contact support@237biz.net for assistance.</div>
  </div>
  <?php endif; ?>

  <!-- Stats -->
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-bottom:1.5rem;">
    <div class="creator-stat">
      <div class="lbl">Link clicks</div>
      <div class="val"><?= $link ? (int)$link['clicks'] : 0 ?></div>
    </div>
    <div class="creator-stat">
      <div class="lbl">Unique clicks</div>
      <div class="val"><?= $link ? (int)$link['unique_clicks'] : 0 ?></div>
    </div>
    <div class="creator-stat">
      <div class="lbl">Referrals converted</div>
      <div class="val" style="color:#00A878;"><?= count($conversions) ?></div>
    </div>
    <div class="creator-stat">
      <div class="lbl">Approved earnings</div>
      <div class="val" style="font-size:1.2rem;color:#fcd116;"><?= formatXaf($commSummary['approved_xaf']) ?></div>
    </div>
    <div class="creator-stat">
      <div class="lbl">Total paid out</div>
      <div class="val" style="font-size:1.2rem;color:#8ab4f8;"><?= formatXaf($commSummary['paid_xaf']) ?></div>
    </div>
    <div class="creator-stat">
      <div class="lbl">Campaigns joined</div>
      <div class="val"><?= count($myCampaigns) ?></div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 300px;gap:1.5rem;align-items:start;" class="creator-layout">

    <div>
      <!-- Referral link -->
      <?php if ($isApproved && $link): ?>
      <div class="ref-box" style="margin-bottom:1rem;">
        <h3 style="margin:0 0 6px;font-size:14px;font-weight:700;">🔗 Your Referral Link</h3>
        <p style="font-size:12.5px;color:var(--muted);margin-bottom:4px;">Share this link. When someone adds a featured listing after clicking it, you earn commission.</p>
        <span class="ref-url"><?= SITE_URL ?>/r/<?= e($link['code']) ?></span>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <button onclick="navigator.clipboard.writeText('<?= SITE_URL ?>/r/<?= e($link['code']) ?>').then(()=>this.textContent='✅ Copied!')"
                  style="background:rgba(0,168,120,0.2);border:1px solid rgba(0,168,120,0.4);color:#00A878;border-radius:7px;padding:7px 14px;font-size:12.5px;cursor:pointer;font-family:inherit;font-weight:600;">📋 Copy</button>
          <a href="https://wa.me/?text=<?= urlencode('Find local Cameroon businesses at 237Biz.net — ' . SITE_URL . '/r/' . $link['code']) ?>" target="_blank"
             style="background:rgba(37,211,102,0.12);border:1px solid rgba(37,211,102,0.3);color:#25d366;border-radius:7px;padding:7px 14px;font-size:12.5px;text-decoration:none;font-weight:600;">💬 WhatsApp</a>
          <a href="https://www.tiktok.com/" target="_blank"
             style="background:rgba(0,0,0,0.3);border:1px solid rgba(255,255,255,0.15);color:rgba(255,255,255,0.7);border-radius:7px;padding:7px 14px;font-size:12.5px;text-decoration:none;font-weight:600;">🎵 TikTok Bio</a>
        </div>
      </div>

      <!-- Conversion breakdown -->
      <div style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.07);border-radius:12px;padding:16px 18px;margin-bottom:1.5rem;">
        <h3 style="font-size:13.5px;font-weight:700;margin:0 0 12px;">📋 Listings Referred via Your Link</h3>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;">
          <div style="background:rgba(255,255,255,0.04);border-radius:9px;padding:11px 12px;text-align:center;">
            <div style="font-size:1.5rem;font-weight:900;font-family:'Fraunces',serif;"><?= $convBreakdown['free_listing']['cnt'] ?></div>
            <div style="font-size:11px;color:var(--muted);margin-top:2px;">🆓 Free</div>
            <?php if ($convBreakdown['free_listing']['xaf']): ?>
            <div style="font-size:11px;color:#00A878;margin-top:2px;"><?= formatXaf($convBreakdown['free_listing']['xaf']) ?></div>
            <?php endif; ?>
          </div>
          <div style="background:rgba(245,200,66,0.07);border-radius:9px;padding:11px 12px;text-align:center;border:1px solid rgba(245,200,66,0.15);">
            <div style="font-size:1.5rem;font-weight:900;font-family:'Fraunces',serif;color:#fcd116;"><?= $convBreakdown['featured_listing']['cnt'] ?></div>
            <div style="font-size:11px;color:var(--muted);margin-top:2px;">⭐ Featured</div>
            <?php if ($convBreakdown['featured_listing']['xaf']): ?>
            <div style="font-size:11px;color:#00A878;margin-top:2px;"><?= formatXaf($convBreakdown['featured_listing']['xaf']) ?></div>
            <?php endif; ?>
          </div>
          <div style="background:rgba(0,168,120,0.06);border-radius:9px;padding:11px 12px;text-align:center;border:1px solid rgba(0,168,120,0.15);">
            <div style="font-size:1.5rem;font-weight:900;font-family:'Fraunces',serif;color:#00A878;"><?= $convBreakdown['upgrade']['cnt'] ?></div>
            <div style="font-size:11px;color:var(--muted);margin-top:2px;">⬆️ Upgrades</div>
            <?php if ($convBreakdown['upgrade']['xaf']): ?>
            <div style="font-size:11px;color:#00A878;margin-top:2px;"><?= formatXaf($convBreakdown['upgrade']['xaf']) ?></div>
            <?php endif; ?>
          </div>
        </div>
        <a href="<?= SITE_URL ?>/creator/referrals" style="display:block;text-align:center;margin-top:10px;font-size:12.5px;color:var(--green);">View all referrals →</a>
      </div>
      <?php elseif ($isApproved && !$link): ?>
      <div style="background:rgba(252,209,22,0.07);border:1px solid rgba(252,209,22,0.25);border-radius:10px;padding:14px 18px;margin-bottom:1.5rem;">
        ⚠️ Your referral link hasn't been generated yet. Contact the admin.
      </div>
      <?php endif; ?>

      <!-- My campaigns -->
      <h2 style="font-size:1rem;font-weight:700;margin-bottom:10px;">📣 My Campaigns</h2>
      <?php if (empty($myCampaigns)): ?>
      <p style="color:var(--muted);font-size:13.5px;">You haven't joined any campaigns yet. <a href="<?= SITE_URL ?>/creator/campaigns" style="color:var(--green);">Browse campaigns →</a></p>
      <?php else: ?>
      <?php foreach ($myCampaigns as $mc): ?>
      <div class="campaign-chip">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap;">
          <div>
            <div style="font-weight:700;font-size:13.5px;"><?= e($mc['title']) ?></div>
            <div style="font-size:12px;color:var(--muted);">
              <?= formatXaf((int)$mc['commission_xaf']) ?>/submission
              <?php if ($mc['ends_at']): ?> · Ends <?= date('d M Y',strtotime($mc['ends_at'])) ?><?php endif; ?>
            </div>
            <div style="font-size:12px;color:var(--muted);margin-top:3px;"><?= (int)$mc['my_submissions'] ?> submission<?= $mc['my_submissions']!=1?'s':'' ?> submitted</div>
          </div>
          <div style="display:flex;gap:6px;align-items:center;">
            <span style="font-size:11.5px;font-weight:700;padding:3px 10px;border-radius:99px;background:rgba(0,168,120,0.15);color:#00A878;"><?= ucfirst($mc['part_status']) ?></span>
            <a href="<?= SITE_URL ?>/creator/submissions?campaign=<?= $mc['id'] ?>" style="font-size:12px;color:var(--green);">Submit →</a>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>

      <!-- Available campaigns -->
      <?php if ($availableCampaigns): ?>
      <h2 style="font-size:1rem;font-weight:700;margin:1.5rem 0 10px;">🆕 Available Campaigns</h2>
      <?php foreach ($availableCampaigns as $ac): ?>
      <div class="campaign-chip" style="border-color:rgba(0,168,120,0.2);">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap;">
          <div>
            <div style="font-weight:700;font-size:13.5px;"><?= e($ac['title']) ?></div>
            <div style="font-size:12px;color:var(--muted);"><?= formatXaf((int)$ac['commission_xaf']) ?> per approved submission<?= $ac['bonus_xaf']?' + ' . formatXaf((int)$ac['bonus_xaf']) . ' top bonus':'' ?></div>
            <?php if ($ac['target_city']): ?><div style="font-size:12px;color:var(--muted);">📍 <?= e($ac['target_city']) ?></div><?php endif; ?>
          </div>
          <a href="<?= SITE_URL ?>/creator/campaigns" class="btn btn-primary" style="font-size:12px;padding:6px 14px;">Join →</a>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Right: commissions -->
    <div>
      <div style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:16px 18px;margin-bottom:12px;">
        <h3 style="margin:0 0 12px;font-size:14px;font-weight:700;">💰 Earnings</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px;">
          <div style="background:rgba(252,209,22,0.08);border-radius:8px;padding:10px 12px;">
            <div style="font-size:11px;color:var(--muted);">Pending</div>
            <div style="font-size:1rem;font-weight:700;color:#fcd116;"><?= formatXaf($commSummary['pending_xaf']) ?></div>
          </div>
          <div style="background:rgba(0,168,120,0.08);border-radius:8px;padding:10px 12px;">
            <div style="font-size:11px;color:var(--muted);">Approved</div>
            <div style="font-size:1rem;font-weight:700;color:#00A878;"><?= formatXaf($commSummary['approved_xaf']) ?></div>
          </div>
        </div>
        <?php $minOk = $commSummary['approved_xaf'] >= $minPayout; ?>
        <?php if ($minOk): ?>
        <div style="background:rgba(0,168,120,0.1);border:1px solid rgba(0,168,120,0.3);border-radius:8px;padding:10px 12px;font-size:13px;color:#00A878;">
          ✅ Payout threshold reached. Contact support@237biz.net to request payment via MoMo.
        </div>
        <?php else: ?>
        <div style="font-size:12px;color:var(--muted);">Need <?= formatXaf($minPayout - $commSummary['approved_xaf']) ?> more to reach minimum payout of <?= formatXaf($minPayout) ?>.</div>
        <?php endif; ?>
      </div>

      <!-- Recent earnings -->
      <?php if ($commissions): ?>
      <div style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:16px 18px;">
        <h3 style="margin:0 0 10px;font-size:14px;font-weight:700;">Recent Earnings</h3>
        <?php foreach ($commissions as $c): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid rgba(255,255,255,0.05);gap:6px;">
          <div>
            <div style="font-size:12.5px;font-weight:600;"><?= str_replace('_',' ',ucfirst($c['type'])) ?></div>
            <div style="font-size:11px;color:var(--muted);"><?= date('d M Y',strtotime($c['created_at'])) ?></div>
          </div>
          <div style="text-align:right;">
            <div style="font-size:13px;font-weight:700;color:#00A878;">+<?= formatXaf((int)$c['amount_xaf']) ?></div>
            <?= commissionStatusBadge($c['status']) ?>
          </div>
        </div>
        <?php endforeach; ?>
        <a href="<?= SITE_URL ?>/creator/referrals" style="display:block;text-align:center;margin-top:10px;font-size:12.5px;color:var(--green);">View all earnings →</a>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div>
</section>

<style>@media(max-width:700px){.creator-layout{grid-template-columns:1fr!important;}}</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>