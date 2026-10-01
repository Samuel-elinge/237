<?php
/**
 * creator/profile.php — 237Biz Creator Profile
 */
require_once __DIR__ . '/../includes/config.php';
$u = currentUser();
if (!$u || !in_array($u['role'], ['creator','admin','user'])) redirect(SITE_URL . '/login');
$uid   = (int)$u['id'];
$pdo   = db();
$setup = isset($_GET['setup']);

// Load existing profile
$prof  = $pdo->prepare("SELECT * FROM creator_profiles WHERE user_id=?");
$prof->execute([$uid]);
$prof  = $prof->fetch();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $niche   = trim($_POST['niche']          ?? '');
    $bio     = trim($_POST['bio']            ?? '');
    $city    = trim($_POST['city']           ?? '');
    $audience= trim($_POST['audience_size']  ?? '');
    $tiktok  = trim($_POST['tiktok_url']     ?? '');
    $ig      = trim($_POST['instagram_url']  ?? '');
    $yt      = trim($_POST['youtube_url']    ?? '');
    $fb      = trim($_POST['facebook_url']   ?? '');

    if (!$niche)  $errors[] = 'Please select your content niche.';
    if (!$bio || mb_strlen($bio) < 30) $errors[] = 'Bio must be at least 30 characters.';
    if (!$tiktok && !$ig && !$yt && !$fb) $errors[] = 'Please add at least one social media link.';

    if (!$errors) {
        if ($prof) {
            $pdo->prepare("UPDATE creator_profiles SET niche=?,bio=?,city=?,audience_size=?,tiktok_url=?,instagram_url=?,youtube_url=?,facebook_url=?,updated_at=NOW() WHERE user_id=?")
                ->execute([$niche,$bio,$city,$audience,$tiktok,$ig,$yt,$fb,$uid]);
        } else {
            // First-time: create profile + set role to creator
            $pdo->prepare("INSERT INTO creator_profiles (user_id,niche,bio,city,audience_size,tiktok_url,instagram_url,youtube_url,facebook_url) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$uid,$niche,$bio,$city,$audience,$tiktok,$ig,$yt,$fb]);
            $pdo->prepare("UPDATE users SET role='creator' WHERE id=? AND role='user'")->execute([$uid]);
        }
        flash('success', $setup ? 'Profile submitted for review. We\'ll be in touch soon!' : 'Profile updated.');
        redirect(SITE_URL . '/creator/dashboard');
    }
}

$pageTitle = ($setup ? 'Apply as Creator' : 'My Creator Profile') . ' — 237Biz';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.nav-pill{padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;color:rgba(255,255,255,0.6);border:1px solid rgba(255,255,255,0.1);}
.nav-pill:hover,.nav-pill.active{background:rgba(0,168,120,0.15);color:#00A878;border-color:rgba(0,168,120,0.35);}
</style>

<div style="height:65px;"></div>
<div class="page-header">
  <div class="container">
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.4rem,3vw,1.9rem);">
      <?= $setup ? '🎬 Apply as a Creator' : '👤 My Creator Profile' ?>
    </h1>
    <?php if ($setup): ?>
    <p style="color:rgba(255,255,255,0.6);margin:6px 0 0;">Tell us about yourself — we'll review your application and activate your referral link within 24 hours.</p>
    <?php endif; ?>
  </div>
</div>

<section class="page-section" style="padding-top:1.25rem;">
<div class="container">

  <?php if (!$setup): ?>
  <div style="display:flex;gap:8px;margin-bottom:1.5rem;flex-wrap:wrap;">
    <a href="<?= SITE_URL ?>/creator/dashboard"   class="nav-pill">🏠 Dashboard</a>
    <a href="<?= SITE_URL ?>/creator/profile"     class="nav-pill active">👤 My Profile</a>
    <a href="<?= SITE_URL ?>/creator/referrals"   class="nav-pill">🔗 Referrals</a>
    <a href="<?= SITE_URL ?>/creator/campaigns"   class="nav-pill">📣 Campaigns</a>
    <a href="<?= SITE_URL ?>/creator/submissions" class="nav-pill">📤 Submissions</a>
  </div>
  <?php endif; ?>

  <?php if (!empty($errors)): ?>
  <div style="background:rgba(206,17,38,0.1);border:1px solid rgba(206,17,38,0.3);border-radius:8px;padding:12px 16px;margin-bottom:16px;">
    <?php foreach($errors as $e): ?><div style="font-size:13.5px;color:#ff6b7a;"><?= e($e) ?></div><?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div style="max-width:580px;">
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">

      <div class="form-group">
        <label>Content niche *</label>
        <select name="niche" required>
          <option value="">— Select your niche —</option>
          <?php foreach (['Food & Restaurants','Fashion & Beauty','Technology','Business & Finance','Health & Wellness','Education','Entertainment','Travel & Tourism','Real Estate','General / Lifestyle'] as $n): ?>
          <option value="<?= $n ?>" <?= ($prof['niche']??'')===$n?'selected':'' ?>><?= $n ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label>Bio * <span style="font-size:11px;color:var(--muted);">(tell us about yourself and your audience — min 30 characters)</span></label>
        <textarea name="bio" rows="4" required><?= e($prof['bio'] ?? '') ?></textarea>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group">
          <label>Your city</label>
          <input type="text" name="city" value="<?= e($prof['city'] ?? '') ?>" placeholder="e.g. Limbe, Douala">
        </div>
        <div class="form-group">
          <label>Audience size (estimate)</label>
          <select name="audience_size">
            <option value="">— Select —</option>
            <?php foreach (['Under 1k','1k–5k','5k–10k','10k–50k','50k–100k','100k+'] as $a): ?>
            <option value="<?= $a ?>" <?= ($prof['audience_size']??'')===$a?'selected':'' ?>><?= $a ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <h3 style="font-size:14px;font-weight:700;margin-top:8px;margin-bottom:12px;">📱 Social Media Links <span style="font-size:11px;color:var(--muted);font-weight:400;">(at least one required)</span></h3>

      <?php
      $socials = [
        ['tiktok_url','🎵 TikTok URL','https://www.tiktok.com/@yourhandle'],
        ['instagram_url','📸 Instagram URL','https://www.instagram.com/yourhandle'],
        ['youtube_url','▶️ YouTube URL','https://www.youtube.com/@yourchannel'],
        ['facebook_url','📘 Facebook URL','https://www.facebook.com/yourpage'],
      ];
      foreach ($socials as [$name,$label,$placeholder]):
      ?>
      <div class="form-group">
        <label><?= $label ?></label>
        <input type="url" name="<?= $name ?>" value="<?= e($prof[$name] ?? '') ?>" placeholder="<?= $placeholder ?>">
      </div>
      <?php endforeach; ?>

      <div style="display:flex;gap:10px;margin-top:8px;">
        <button type="submit" class="btn btn-primary"><?= $setup ? '🎬 Submit Application' : '💾 Save Profile' ?></button>
        <?php if (!$setup): ?><a href="<?= SITE_URL ?>/creator/dashboard" class="btn btn-outline">Cancel</a><?php endif; ?>
      </div>
    </form>
  </div>

</div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
