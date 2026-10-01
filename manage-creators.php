<?php
/**
 * admin/manage-creators.php — 237Biz
 * Approve creators, view stats, manage payouts and campaigns.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/referral-helpers.php';
requireAdmin();
$pdo = db();

$tab = $_GET['tab'] ?? 'creators';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    $u = currentUser();

    // Approve creator
    if ($action === 'approve_creator') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid) {
            $pdo->prepare("UPDATE creator_profiles SET status='approved', approved_at=NOW(), approved_by=? WHERE user_id=?")
                ->execute([$u['id'], $uid]);

            // Create referral link if not exists
            $check = $pdo->prepare("SELECT id FROM referral_links WHERE user_id=?");
            $check->execute([$uid]);
            if (!$check->fetch()) {
                $nameRow = $pdo->prepare("SELECT name FROM users WHERE id=?");
                $nameRow->execute([$uid]);
                $name = $nameRow->fetchColumn() ?: 'creator';
                $code = generateReferralCode($name);
                $pdo->prepare("INSERT INTO referral_links (user_id,programme_id,code) VALUES (?,1,?)")
                    ->execute([$uid, $code]);
            }
            flash('success', 'Creator approved and referral link created.');
        }
        redirect(SITE_URL . '/admin/manage-creators?tab=creators');
    }

    // Suspend
    if ($action === 'suspend_creator') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid) {
            $pdo->prepare("UPDATE creator_profiles SET status='suspended' WHERE user_id=?")->execute([$uid]);
            flash('success', 'Creator suspended.');
        }
        redirect(SITE_URL . '/admin/manage-creators?tab=creators');
    }

    // Review content submission
    if ($action === 'review_submission') {
        $sid     = (int)($_POST['submission_id'] ?? 0);
        $status  = in_array($_POST['status']??'',['approved','rejected']) ? $_POST['status'] : 'rejected';
        $notes   = trim($_POST['admin_notes'] ?? '');
        $quality = min(5, max(1, (int)($_POST['quality_score'] ?? 3)));
        $commission = (int)($_POST['commission_xaf'] ?? 0);

        $pdo->prepare("UPDATE content_submissions SET status=?,admin_notes=?,quality_score=?,commission_xaf=?,reviewed_at=NOW(),reviewed_by=? WHERE id=?")
            ->execute([$status, $notes, $quality, $commission, $u['id'], $sid]);

        if ($status === 'approved' && $commission > 0) {
            $sub = $pdo->prepare("SELECT user_id, campaign_id FROM content_submissions WHERE id=?");
            $sub->execute([$sid]);
            $sub = $sub->fetch();
            if ($sub) {
                createCommission($sub['user_id'], 'campaign', $commission,
                    "Campaign content approved — Submission #{$sid}", $sid, 'submission');

                $pdo->prepare("UPDATE campaign_participants SET status='approved' WHERE user_id=? AND campaign_id=?")
                    ->execute([$sub['user_id'], $sub['campaign_id']]);
            }
        }
        flash('success', 'Submission reviewed.');
        redirect(SITE_URL . '/admin/manage-creators?tab=submissions');
    }
}

// ── Data ─────────────────────────────────────────────────────────────────
$statusFilter = $_GET['status'] ?? 'pending';

$creators = [];
try {
    $creators = $pdo->query("
        SELECT u.id, u.name, u.email, u.created_at,
            cp.niche, cp.city, cp.status AS creator_status, cp.audience_size,
            cp.tiktok_url, cp.instagram_url, cp.bio,
            rl.code AS ref_code, rl.clicks, rl.unique_clicks,
            (SELECT COUNT(*) FROM referral_conversions rc JOIN referral_links rl2 ON rl2.id=rc.link_id WHERE rl2.user_id=u.id) AS conversions,
            (SELECT SUM(c.amount_xaf) FROM commissions c WHERE c.user_id=u.id AND c.status='approved') AS approved_xaf
        FROM users u
        JOIN creator_profiles cp ON cp.user_id=u.id
        LEFT JOIN referral_links rl ON rl.user_id=u.id
        WHERE u.role='creator'
        ORDER BY cp.status='pending' DESC, u.created_at DESC
    ")->fetchAll();
} catch (Exception $e) { $creators = []; }

$submissions = [];
try {
    $submissions = $pdo->query("
        SELECT cs.*, u.name AS creator_name, cam.title AS campaign_title
        FROM content_submissions cs
        JOIN users u ON u.id=cs.user_id
        JOIN campaigns cam ON cam.id=cs.campaign_id
        WHERE cs.status='pending'
        ORDER BY cs.submitted_at DESC
        LIMIT 100
    ")->fetchAll();
} catch (Exception $e) { $submissions = []; }

$pageTitle = 'Manage Creators — Admin — 237Biz';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.creator-card { background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:18px 20px;margin-bottom:10px; }
.creator-card .top { display:flex;align-items:flex-start;gap:14px;flex-wrap:wrap; }
.creator-meta { display:flex;gap:8px;flex-wrap:wrap;margin-top:8px; }
.creator-tag { font-size:11.5px;padding:2px 10px;border-radius:99px;background:rgba(255,255,255,0.06);color:rgba(255,255,255,0.65); }
.sub-card { background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.08);border-radius:10px;padding:14px 18px;margin-bottom:10px; }
.star-rating { display:flex;gap:3px; }
.star-rating input { display:none; }
.star-rating label { font-size:20px;cursor:pointer;color:rgba(255,255,255,0.2); }
.star-rating input:checked ~ label,
.star-rating label:hover,
.star-rating label:hover ~ label { color:#fcd116; }
</style>

<div class="page-header">
  <div class="container">
    <nav class="breadcrumb"><a href="<?= SITE_URL ?>/admin/">Admin</a> › <span>Manage Creators</span></nav>
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.5rem,3vw,2rem);">🎬 Manage Creators</h1>
  </div>
</div>

<section class="page-section" style="padding-top:1.25rem;">
<div class="container">

  <div class="filter-tabs" style="margin-bottom:1.5rem;flex-wrap:wrap;">
    <a href="?tab=creators"    class="filter-tab <?= $tab==='creators'   ?'active':'' ?>">🎬 Creators (<?= count($creators) ?>)</a>
    <a href="?tab=submissions" class="filter-tab <?= $tab==='submissions'?'active':'' ?>">📤 Pending Submissions (<?= count($submissions) ?>)</a>
  </div>

  <?php if ($tab === 'submissions'): ?>
  <!-- Pending content submissions -->
  <?php if (empty($submissions)): ?>
  <p style="color:var(--muted);padding:40px 0;text-align:center;">No pending submissions. ✅</p>
  <?php else: ?>
  <?php foreach ($submissions as $sub): ?>
  <div class="sub-card">
    <div style="display:flex;align-items:flex-start;gap:14px;flex-wrap:wrap;">
      <div style="flex:1;min-width:200px;">
        <div style="font-weight:700;font-size:14px;"><?= e($sub['creator_name']) ?></div>
        <div style="font-size:12.5px;color:var(--muted);">Campaign: <?= e($sub['campaign_title']) ?></div>
        <div style="margin-top:6px;">
          <span style="background:rgba(138,180,248,0.1);color:#8ab4f8;border-radius:6px;padding:2px 8px;font-size:11.5px;font-weight:600;"><?= ucfirst($sub['platform']) ?></span>
        </div>
        <a href="<?= e($sub['content_url']) ?>" target="_blank" style="font-size:13px;color:#00A878;display:block;margin-top:6px;word-break:break-all;"><?= e($sub['content_url']) ?></a>
        <?php if ($sub['caption']): ?><p style="font-size:12.5px;color:var(--muted);margin:6px 0 0;"><?= e($sub['caption']) ?></p><?php endif; ?>
        <?php if ($sub['views_reported']): ?><div style="font-size:12px;color:var(--muted);margin-top:4px;">👁 <?= number_format($sub['views_reported']) ?> views reported</div><?php endif; ?>
        <div style="font-size:12px;color:var(--muted);margin-top:4px;">Submitted: <?= date('d M Y H:i',strtotime($sub['submitted_at'])) ?></div>
      </div>
      <!-- Review form -->
      <form method="POST" style="min-width:240px;">
        <input type="hidden" name="csrf" value="<?= csrf() ?>">
        <input type="hidden" name="action" value="review_submission">
        <input type="hidden" name="submission_id" value="<?= $sub['id'] ?>">

        <!-- Star rating -->
        <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:4px;">Quality (1–5)</label>
        <div class="star-rating" style="margin-bottom:10px;">
          <?php for ($i=5;$i>=1;$i--): ?>
          <input type="radio" name="quality_score" id="star<?= $sub['id'] ?>_<?= $i ?>" value="<?= $i ?>">
          <label for="star<?= $sub['id'] ?>_<?= $i ?>">★</label>
          <?php endfor; ?>
        </div>

        <div class="form-group" style="margin-bottom:8px;">
          <label style="font-size:12px;color:var(--muted);">Commission (XAF)</label>
          <input type="number" name="commission_xaf" value="0" min="0" step="500"
                 style="width:100%;padding:7px 10px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:6px;color:var(--white);font-family:inherit;font-size:13.5px;">
        </div>
        <div class="form-group" style="margin-bottom:8px;">
          <label style="font-size:12px;color:var(--muted);">Admin notes</label>
          <textarea name="admin_notes" rows="2" style="width:100%;padding:7px 10px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:6px;color:var(--white);font-family:inherit;font-size:13px;resize:vertical;"></textarea>
        </div>
        <div style="display:flex;gap:7px;">
          <button type="submit" name="status" value="approved"
                  style="flex:1;background:rgba(0,168,120,0.2);border:1px solid rgba(0,168,120,0.4);color:#00A878;border-radius:7px;padding:8px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;">✅ Approve</button>
          <button type="submit" name="status" value="rejected"
                  style="flex:1;background:rgba(206,17,38,0.1);border:1px solid rgba(206,17,38,0.3);color:#ff6b7a;border-radius:7px;padding:8px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;">✕ Reject</button>
        </div>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <?php else: ?>
  <!-- Creators list -->
  <?php if (empty($creators)): ?>
  <div style="text-align:center;padding:40px;color:var(--muted);">
    <div style="font-size:2.5rem;margin-bottom:12px;">🎬</div>
    <p>No creator applications yet.</p>
  </div>
  <?php else: ?>
  <?php foreach ($creators as $cr):
    $isPending  = $cr['creator_status'] === 'pending';
    $isApproved = $cr['creator_status'] === 'approved';
    $statusColor = ['pending'=>'#fcd116','approved'=>'#00A878','suspended'=>'#ff6b7a'][$cr['creator_status']] ?? 'var(--muted)';
  ?>
  <div class="creator-card" style="border-color:<?= $isPending?'rgba(252,209,22,0.3)':'rgba(255,255,255,0.08)' ?>;">
    <div class="top">
      <div style="flex:1;min-width:200px;">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
          <strong style="font-size:15px;"><?= e($cr['name']) ?></strong>
          <span style="font-size:11px;font-weight:700;color:<?= $statusColor ?>;background:rgba(255,255,255,0.05);border-radius:99px;padding:2px 10px;"><?= ucfirst($cr['creator_status']) ?></span>
          <?php if ($isPending): ?><span style="font-size:11px;color:var(--yellow);">⚠️ Needs approval</span><?php endif; ?>
        </div>
        <div style="font-size:12.5px;color:var(--muted);margin-top:2px;"><?= e($cr['email']) ?></div>
        <?php if ($cr['bio']): ?><p style="font-size:13px;color:rgba(255,255,255,0.7);margin:8px 0 4px;"><?= e(mb_substr($cr['bio'],0,120)) ?><?= mb_strlen($cr['bio'])>120?'…':'' ?></p><?php endif; ?>
        <div class="creator-meta">
          <?php if ($cr['niche']): ?><span class="creator-tag">🎯 <?= e($cr['niche']) ?></span><?php endif; ?>
          <?php if ($cr['city']): ?><span class="creator-tag">📍 <?= e($cr['city']) ?></span><?php endif; ?>
          <?php if ($cr['audience_size']): ?><span class="creator-tag">👥 <?= e($cr['audience_size']) ?></span><?php endif; ?>
          <?php if ($cr['tiktok_url']): ?><a href="<?= e($cr['tiktok_url']) ?>" target="_blank" class="creator-tag" style="color:#8ab4f8;text-decoration:none;">🎵 TikTok</a><?php endif; ?>
          <?php if ($cr['instagram_url']): ?><a href="<?= e($cr['instagram_url']) ?>" target="_blank" class="creator-tag" style="color:#e1306c;text-decoration:none;">📸 Instagram</a><?php endif; ?>
        </div>
      </div>

      <!-- Stats (approved creators) -->
      <?php if ($isApproved): ?>
      <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:center;">
        <?php foreach ([['Clicks',$cr['clicks'],'#fff'],['Unique',$cr['unique_clicks'],'rgba(255,255,255,0.6)'],['Conversions',$cr['conversions'],'#00A878'],['Approved',formatXaf((int)$cr['approved_xaf']),'#fcd116']] as [$lbl,$val,$col]): ?>
        <div style="text-align:center;">
          <div style="font-size:1.05rem;font-weight:900;font-family:'Fraunces',serif;color:<?= $col ?>;"><?= $val ?: 0 ?></div>
          <div style="font-size:11px;color:var(--muted);"><?= $lbl ?></div>
        </div>
        <?php endforeach; ?>
        <?php if ($cr['ref_code']): ?>
        <div style="background:rgba(0,168,120,0.1);color:#00A878;border:1px solid rgba(0,168,120,0.3);border-radius:7px;padding:4px 11px;font-family:monospace;font-size:13px;font-weight:700;">/r/<?= e($cr['ref_code']) ?></div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Actions -->
      <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-end;">
        <?php if ($isPending): ?>
        <form method="POST">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <input type="hidden" name="action" value="approve_creator">
          <input type="hidden" name="user_id" value="<?= $cr['id'] ?>">
          <button type="submit" class="btn btn-primary" style="font-size:13px;padding:7px 16px;">✅ Approve Creator</button>
        </form>
        <?php endif; ?>
        <?php if ($isApproved): ?>
        <form method="POST">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <input type="hidden" name="action" value="suspend_creator">
          <input type="hidden" name="user_id" value="<?= $cr['id'] ?>">
          <button type="submit" class="btn btn-outline" style="font-size:12px;padding:5px 14px;color:#ff6b7a;border-color:rgba(206,17,38,0.3);" onclick="return confirm('Suspend this creator?')">Suspend</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
  <?php endif; ?>

</div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
