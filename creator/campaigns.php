<?php
/**
 * creator/campaigns.php — Browse campaigns, join, and get a per-campaign
 * tracked link. Also hosts the standing "General 237Biz Promotion" campaign
 * so every creator always has an active link to use.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/referral-helpers.php';
$u = currentUser();
if (!$u || !in_array($u['role'],['creator','admin'])) redirect(SITE_URL . '/login');
$uid = (int)$u['id'];
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'join') {
    verifyCsrf();
    $cid = (int)($_POST['campaign_id'] ?? 0);
    if ($cid) {
        $check = $pdo->prepare("SELECT id FROM campaign_participants WHERE campaign_id=? AND user_id=?");
        $check->execute([$cid,$uid]);
        if (!$check->fetch()) {
            $pdo->prepare("INSERT INTO campaign_participants (campaign_id,user_id) VALUES (?,?)")->execute([$cid,$uid]);

            // Auto-create this creator's tracked link for the campaign.
            $camp = $pdo->prepare("SELECT title FROM campaigns WHERE id=?");
            $camp->execute([$cid]);
            $campTitle = $camp->fetchColumn() ?: 'Campaign';
            createCreatorLink($uid, $campTitle, $cid, 'campaign');

            flash('success', 'You\'ve joined the campaign! Your tracked link is ready below — submit content from the Submissions page.');
        }
    }
    redirect(SITE_URL . '/creator/campaigns');
}

// Ensure the standing general campaign + link exist for this creator.
ensureGeneralCampaignAndLink($uid);

$myIds = $pdo->prepare("SELECT campaign_id FROM campaign_participants WHERE user_id=?");
$myIds->execute([$uid]);
$myIds = array_column($myIds->fetchAll(), 'campaign_id');

// This creator's own links keyed by campaign_id (for showing the tracked link per card).
$myLinksByCampaign = [];
foreach (getCreatorLinks($uid) as $l) {
    if ($l['campaign_id']) $myLinksByCampaign[(int)$l['campaign_id']] = $l;
}

$active = $pdo->query("
    SELECT c.*,(SELECT COUNT(*) FROM campaign_participants cp WHERE cp.campaign_id=c.id) AS participants
    FROM campaigns c WHERE c.status='active'
    ORDER BY c.is_general DESC, c.created_at DESC
")->fetchAll();

// Target-audience filter: a 'selected' campaign only shows to creators listed in campaign_targets.
$active = array_filter($active, function($c) use ($uid, $pdo) {
    if ($c['audience'] !== 'selected') return true;
    $chk = $pdo->prepare("SELECT id FROM campaign_targets WHERE campaign_id=? AND user_id=?");
    $chk->execute([$c['id'], $uid]);
    return (bool)$chk->fetch();
});

$pageTitle = 'Campaigns — 237Biz Creator';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>
<?php creatorNavAssets(); ?>
<?php creatorNav('campaigns'); ?>

<style>
.camp-card{background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:20px 22px;margin-bottom:12px;}
.camp-link-box{background:rgba(0,168,120,0.06);border:1px solid rgba(0,168,120,0.25);border-radius:9px;padding:10px 14px;margin-top:12px;}
.camp-stat{text-align:center;}
.camp-stat .v{font-size:1.1rem;font-weight:900;font-family:'Fraunces',serif;}
.camp-stat .l{font-size:10.5px;color:var(--muted);text-transform:uppercase;}
</style>

<div class="page-header"><div class="container">
  <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.4rem,3vw,1.9rem);">📣 Campaigns</h1>
</div></div>

<section class="page-section" style="padding-top:1.25rem;"><div class="container">


  <h2 style="font-size:1rem;font-weight:700;margin-bottom:14px;">🟢 Active Campaigns</h2>
  <?php if (empty($active)): ?>
  <p style="color:var(--muted);">No active campaigns right now — check back soon.</p>
  <?php else: ?>
  <?php foreach ($active as $c):
    $joined = in_array($c['id'], $myIds);
    $myLink = $myLinksByCampaign[(int)$c['id']] ?? null;
  ?>
  <div class="camp-card" style="<?= $joined?'border-color:rgba(0,168,120,0.3);':'' ?>">
    <div style="display:flex;align-items:flex-start;gap:14px;flex-wrap:wrap;">
      <div style="flex:1;min-width:220px;">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px;">
          <strong style="font-size:15px;"><?= e($c['title']) ?></strong>
          <?php if ($c['is_general']): ?><span style="font-size:11.5px;font-weight:700;padding:2px 9px;border-radius:99px;background:rgba(138,180,248,0.15);color:#8ab4f8;">🌐 Always-on</span><?php endif; ?>
          <?php if ($joined): ?><span style="font-size:11.5px;font-weight:700;padding:2px 9px;border-radius:99px;background:rgba(0,168,120,0.15);color:#00A878;">✅ Joined</span><?php endif; ?>
        </div>
        <?php if ($c['description']): ?>
        <p style="font-size:13.5px;color:rgba(255,255,255,0.75);margin:0 0 10px;"><?= e($c['description']) ?></p>
        <?php endif; ?>
        <?php if ($c['brief']): ?>
        <div style="background:rgba(255,255,255,0.03);border-radius:8px;padding:10px 14px;font-size:13px;color:rgba(255,255,255,0.65);margin-bottom:10px;">
          <strong style="color:rgba(255,255,255,0.85);">📋 Content brief:</strong><br><?= nl2br(e($c['brief'])) ?>
        </div>
        <?php endif; ?>
        <div style="display:flex;gap:10px;flex-wrap:wrap;font-size:12.5px;color:var(--muted);">
          <?php if ($c['target_city']): ?><span>📍 <?= e($c['target_city']) ?></span><?php endif; ?>
          <?php if ($c['ends_at']): ?><span>🗓 Ends <?= date('d M Y',strtotime($c['ends_at'])) ?></span><?php endif; ?>
          <span>👥 <?= (int)$c['participants'] ?> creator<?= $c['participants']!=1?'s':'' ?> joined</span>
          <?php if ($c['max_participants']): ?><span>Max <?= (int)$c['max_participants'] ?></span><?php endif; ?>
        </div>

        <!-- My tracked link for this campaign + per-campaign stats -->
        <?php if ($joined && $myLink): ?>
        <div class="camp-link-box">
          <div style="font-size:11.5px;color:var(--muted);margin-bottom:4px;">🔗 Your tracked link for this campaign</div>
          <div style="font-family:monospace;font-size:13px;color:#00A878;margin-bottom:8px;word-break:break-all;"><?= SITE_URL ?>/r/<?= e($myLink['code']) ?></div>
          <div style="display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap;">
            <button onclick="navigator.clipboard.writeText('<?= SITE_URL ?>/r/<?= e($myLink['code']) ?>').then(()=>this.textContent='✅ Copied!')"
                    style="background:rgba(0,168,120,0.2);border:1px solid rgba(0,168,120,0.4);color:#00A878;border-radius:7px;padding:6px 14px;font-size:12px;cursor:pointer;font-family:inherit;font-weight:600;">📋 Copy</button>
          </div>
          <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;">
            <div class="camp-stat"><div class="v"><?= (int)$myLink['clicks'] ?></div><div class="l">Clicks</div></div>
            <div class="camp-stat"><div class="v" style="color:#00A878;"><?= (int)$myLink['conversions'] ?></div><div class="l">Sales</div></div>
            <div class="camp-stat"><div class="v" style="color:#fcd116;"><?= formatXaf((int)$myLink['commission_xaf']) ?></div><div class="l">Commission</div></div>
          </div>
        </div>
        <?php endif; ?>
      </div>
      <div style="text-align:center;min-width:140px;">
        <div style="font-size:1.6rem;font-weight:900;font-family:'Fraunces',serif;color:#00A878;"><?= formatXaf((int)$c['commission_xaf']) ?></div>
        <div style="font-size:11.5px;color:var(--muted);">per approved submission</div>
        <?php if ($c['bonus_xaf']): ?>
        <div style="font-size:12px;color:#fcd116;margin-top:4px;">🏆 +<?= formatXaf((int)$c['bonus_xaf']) ?> top performer</div>
        <?php endif; ?>
        <div style="margin-top:12px;">
          <?php if ($joined): ?>
          <a href="<?= SITE_URL ?>/creator/submissions?campaign=<?= $c['id'] ?>" class="btn btn-primary" style="font-size:12.5px;padding:7px 16px;display:block;">📤 Submit Content</a>
          <?php else: ?>
          <form method="POST">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="join">
            <input type="hidden" name="campaign_id" value="<?= $c['id'] ?>">
            <button type="submit" class="btn btn-outline" style="width:100%;font-size:12.5px;padding:7px 16px;">+ Join Campaign</button>
          </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

</div></section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
