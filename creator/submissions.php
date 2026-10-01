<?php
/**
 * creator/submissions.php — Submit content for campaigns
 */
require_once __DIR__ . '/../includes/config.php';
$u = currentUser();
if (!$u || !in_array($u['role'],['creator','admin'])) redirect(SITE_URL . '/login');
$uid = (int)$u['id'];
$pdo = db();

$campaignFilter = (int)($_GET['campaign'] ?? 0);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'submit_content') {
    verifyCsrf();
    $cid      = (int)($_POST['campaign_id']    ?? 0);
    $platform = $_POST['platform']             ?? '';
    $url      = trim($_POST['content_url']     ?? '');
    $caption  = trim($_POST['caption']         ?? '');
    $views    = (int)($_POST['views_reported'] ?? 0);

    if (!$cid)      $errors[] = 'Please select a campaign.';
    if (!$platform) $errors[] = 'Please select a platform.';
    if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) $errors[] = 'Please enter a valid content URL.';

    if (!$errors) {
        // Verify joined
        $check = $pdo->prepare("SELECT id FROM campaign_participants WHERE campaign_id=? AND user_id=?");
        $check->execute([$cid, $uid]);
        if (!$check->fetch()) $errors[] = 'You must join this campaign before submitting.';
    }

    if (!$errors) {
        $pdo->prepare("INSERT INTO content_submissions (campaign_id,user_id,platform,content_url,caption,views_reported) VALUES (?,?,?,?,?,?)")
            ->execute([$cid, $uid, $platform, $url, $caption, $views]);
        $pdo->prepare("UPDATE campaign_participants SET status='submitted' WHERE campaign_id=? AND user_id=? AND status='joined'")
            ->execute([$cid, $uid]);
        flash('success', 'Submission received! The 237Biz team will review it within 48 hours.');
        redirect(SITE_URL . '/creator/submissions');
    }
}

// My joined campaigns
$joined = $pdo->prepare("SELECT c.id, c.title, c.commission_xaf FROM campaign_participants cp JOIN campaigns c ON c.id=cp.campaign_id WHERE cp.user_id=? AND c.status='active'");
$joined->execute([$uid]);
$joined = $joined->fetchAll();

// My submissions
$submissions = $pdo->prepare("
    SELECT cs.*, c.title AS campaign_title
    FROM content_submissions cs
    JOIN campaigns c ON c.id=cs.campaign_id
    WHERE cs.user_id=? ORDER BY cs.submitted_at DESC
");
$submissions->execute([$uid]);
$submissions = $submissions->fetchAll();

$pageTitle = 'Submissions — 237Biz Creator';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.nav-pill{padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;color:rgba(255,255,255,0.6);border:1px solid rgba(255,255,255,0.1);}
.nav-pill:hover,.nav-pill.active{background:rgba(0,168,120,0.15);color:#00A878;border-color:rgba(0,168,120,0.35);}
.sub-row{padding:12px 0;border-bottom:1px solid rgba(255,255,255,0.05);display:flex;align-items:flex-start;gap:14px;flex-wrap:wrap;}
</style>

<div style="height:65px;"></div>
<div class="page-header"><div class="container">
  <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.4rem,3vw,1.9rem);">📤 Content Submissions</h1>
</div></div>

<section class="page-section" style="padding-top:1.25rem;"><div class="container">

  <div style="display:flex;gap:8px;margin-bottom:1.5rem;flex-wrap:wrap;">
    <a href="<?= SITE_URL ?>/creator/dashboard"   class="nav-pill">🏠 Dashboard</a>
    <a href="<?= SITE_URL ?>/creator/profile"     class="nav-pill">👤 My Profile</a>
    <a href="<?= SITE_URL ?>/creator/referrals"   class="nav-pill">🔗 Referrals</a>
    <a href="<?= SITE_URL ?>/creator/campaigns"   class="nav-pill">📣 Campaigns</a>
    <a href="<?= SITE_URL ?>/creator/submissions" class="nav-pill active">📤 Submissions</a>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;align-items:start;" class="sub-layout">

    <!-- Submit form -->
    <div style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:20px 22px;">
      <h2 style="margin-top:0;font-size:1rem;font-weight:700;">+ Submit Content</h2>

      <?php if (empty($joined)): ?>
      <p style="color:var(--muted);font-size:13.5px;">You haven't joined any active campaigns yet. <a href="<?= SITE_URL ?>/creator/campaigns" style="color:var(--green);">Browse campaigns →</a></p>
      <?php else: ?>

      <?php if (!empty($errors)): ?>
      <div style="background:rgba(206,17,38,0.1);border:1px solid rgba(206,17,38,0.3);border-radius:8px;padding:12px;margin-bottom:14px;">
        <?php foreach($errors as $e): ?><div style="font-size:13.5px;color:#ff6b7a;"><?= e($e) ?></div><?php endforeach; ?>
      </div>
      <?php endif; ?>

      <form method="POST">
        <input type="hidden" name="csrf" value="<?= csrf() ?>">
        <input type="hidden" name="action" value="submit_content">

        <div class="form-group">
          <label>Campaign *</label>
          <select name="campaign_id" required>
            <option value="">— Select campaign —</option>
            <?php foreach ($joined as $j): ?>
            <option value="<?= $j['id'] ?>" <?= $campaignFilter===$j['id']?'selected':'' ?>><?= e($j['title']) ?> (<?= formatXaf((int)$j['commission_xaf']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label>Platform *</label>
          <select name="platform" required>
            <option value="">— Select platform —</option>
            <?php foreach (['tiktok'=>'🎵 TikTok','instagram'=>'📸 Instagram','youtube'=>'▶️ YouTube','facebook'=>'📘 Facebook','twitter'=>'🐦 Twitter/X','other'=>'🌐 Other'] as $v=>$l): ?>
            <option value="<?= $v ?>"><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label>Content URL * <span style="font-size:11px;color:var(--muted);">(direct link to your post)</span></label>
          <input type="url" name="content_url" required placeholder="https://www.tiktok.com/@you/video/...">
        </div>

        <div class="form-group">
          <label>Caption / post text</label>
          <textarea name="caption" rows="3" placeholder="Paste the caption you used in the post…"></textarea>
        </div>

        <div class="form-group">
          <label>Views at time of submission</label>
          <input type="number" name="views_reported" min="0" placeholder="0">
          <p style="font-size:11.5px;color:var(--muted);margin:4px 0 0;">Approximate view count on your post — for our records.</p>
        </div>

        <button type="submit" class="btn btn-primary">📤 Submit for Review</button>
      </form>
      <?php endif; ?>
    </div>

    <!-- Submission history -->
    <div>
      <h2 style="font-size:1rem;font-weight:700;margin-bottom:12px;">My Submissions</h2>
      <?php if (empty($submissions)): ?>
      <p style="color:var(--muted);font-size:13.5px;">No submissions yet.</p>
      <?php else: ?>
      <?php foreach ($submissions as $sub):
        $statusColor = ['pending'=>'#fcd116','approved'=>'#00A878','rejected'=>'#ff6b7a'][$sub['status']] ?? 'var(--muted)';
      ?>
      <div class="sub-row">
        <div style="flex:1;min-width:180px;">
          <div style="font-weight:600;font-size:13.5px;"><?= e($sub['campaign_title']) ?></div>
          <div style="font-size:12px;color:var(--muted);"><?= ucfirst($sub['platform']) ?> · <?= date('d M Y',strtotime($sub['submitted_at'])) ?></div>
          <a href="<?= e($sub['content_url']) ?>" target="_blank" style="font-size:12px;color:var(--green);display:block;margin-top:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:220px;"><?= e($sub['content_url']) ?></a>
          <?php if ($sub['admin_notes'] && $sub['status']==='rejected'): ?>
          <div style="font-size:12px;color:#ff6b7a;margin-top:4px;">💬 <?= e($sub['admin_notes']) ?></div>
          <?php endif; ?>
          <?php if ($sub['quality_score']): ?>
          <div style="font-size:12px;color:#fcd116;margin-top:3px;"><?= str_repeat('★',$sub['quality_score']) ?><?= str_repeat('☆',5-$sub['quality_score']) ?></div>
          <?php endif; ?>
        </div>
        <div style="text-align:right;">
          <span style="font-size:11.5px;font-weight:700;padding:3px 10px;border-radius:99px;background:rgba(255,255,255,0.05);color:<?= $statusColor ?>;"><?= ucfirst($sub['status']) ?></span>
          <?php if ($sub['commission_xaf'] > 0): ?>
          <div style="font-size:13px;font-weight:700;color:#00A878;margin-top:4px;">+<?= formatXaf((int)$sub['commission_xaf']) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>

  </div>
</div></section>

<style>@media(max-width:700px){.sub-layout{grid-template-columns:1fr!important;}}</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
