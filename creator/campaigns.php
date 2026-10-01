<?php
/**
 * creator/campaigns.php — Browse and join campaigns
 */
require_once __DIR__ . '/../includes/config.php';
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
            flash('success', 'You\'ve joined the campaign! Submit your content from the Submissions page.');
        }
    }
    redirect(SITE_URL . '/creator/campaigns');
}

// Campaigns
$myCampaignIds = array_column(
    $pdo->prepare("SELECT campaign_id FROM campaign_participants WHERE user_id=?")->execute([$uid]) ? $pdo->query("SELECT campaign_id FROM campaign_participants WHERE user_id={$uid}")->fetchAll() : [],
    'campaign_id'
);

$myIds = $pdo->prepare("SELECT campaign_id FROM campaign_participants WHERE user_id=?");
$myIds->execute([$uid]);
$myIds = array_column($myIds->fetchAll(), 'campaign_id');

$active = $pdo->query("SELECT c.*,(SELECT COUNT(*) FROM campaign_participants cp WHERE cp.campaign_id=c.id) AS participants FROM campaigns c WHERE c.status='active' ORDER BY c.created_at DESC")->fetchAll();
$past   = $pdo->query("SELECT c.* FROM campaigns c WHERE c.status IN ('completed','cancelled') ORDER BY c.created_at DESC LIMIT 10")->fetchAll();

$pageTitle = 'Campaigns — 237Biz Creator';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.nav-pill{padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;color:rgba(255,255,255,0.6);border:1px solid rgba(255,255,255,0.1);}
.nav-pill:hover,.nav-pill.active{background:rgba(0,168,120,0.15);color:#00A878;border-color:rgba(0,168,120,0.35);}
.camp-card{background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:20px 22px;margin-bottom:12px;}
</style>

<div style="height:65px;"></div>
<div class="page-header"><div class="container">
  <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.4rem,3vw,1.9rem);">📣 Campaigns</h1>
</div></div>

<section class="page-section" style="padding-top:1.25rem;"><div class="container">

  <div style="display:flex;gap:8px;margin-bottom:1.5rem;flex-wrap:wrap;">
    <a href="<?= SITE_URL ?>/creator/dashboard"   class="nav-pill">🏠 Dashboard</a>
    <a href="<?= SITE_URL ?>/creator/profile"     class="nav-pill">👤 My Profile</a>
    <a href="<?= SITE_URL ?>/creator/referrals"   class="nav-pill">🔗 Referrals</a>
    <a href="<?= SITE_URL ?>/creator/campaigns"   class="nav-pill active">📣 Campaigns</a>
    <a href="<?= SITE_URL ?>/creator/submissions" class="nav-pill">📤 Submissions</a>
  </div>

  <h2 style="font-size:1rem;font-weight:700;margin-bottom:14px;">🟢 Active Campaigns</h2>
  <?php if (empty($active)): ?>
  <p style="color:var(--muted);">No active campaigns right now — check back soon.</p>
  <?php else: ?>
  <?php foreach ($active as $c):
    $joined = in_array($c['id'], $myIds);
  ?>
  <div class="camp-card" style="<?= $joined?'border-color:rgba(0,168,120,0.3);':'' ?>">
    <div style="display:flex;align-items:flex-start;gap:14px;flex-wrap:wrap;">
      <div style="flex:1;min-width:220px;">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px;">
          <strong style="font-size:15px;"><?= e($c['title']) ?></strong>
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
