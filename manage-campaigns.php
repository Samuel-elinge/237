<?php
/**
 * admin/manage-campaigns.php — 237Biz
 * Create and manage creator campaigns.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/referral-helpers.php';
requireAdmin();
$pdo = db();

$tab = $_GET['tab'] ?? 'campaigns';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    $u = currentUser();

    if (in_array($action, ['create_campaign','update_campaign'])) {
        $title       = trim($_POST['title']       ?? '');
        $description = trim($_POST['description'] ?? '');
        $brief       = trim($_POST['brief']       ?? '');
        $city        = trim($_POST['target_city'] ?? '');
        $category    = trim($_POST['target_category'] ?? '');
        $commission  = (int)($_POST['commission_xaf'] ?? 0);
        $bonus       = (int)($_POST['bonus_xaf']      ?? 0);
        $maxP        = (int)($_POST['max_participants'] ?? 0) ?: null;
        $starts      = trim($_POST['starts_at'] ?? '') ?: null;
        $ends        = trim($_POST['ends_at']   ?? '') ?: null;
        $status      = in_array($_POST['status']??'',['draft','active','completed','cancelled']) ? $_POST['status'] : 'draft';

        if (!$title) $errors[] = 'Title required.';

        if (!$errors) {
            if ($action === 'create_campaign') {
                $pdo->prepare("INSERT INTO campaigns (title,description,brief,target_city,target_category,commission_xaf,bonus_xaf,max_participants,starts_at,ends_at,status,created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$title,$description,$brief,$city,$category,$commission,$bonus,$maxP,$starts,$ends,$status,$u['id']]);
                flash('success', 'Campaign created.');
            } else {
                $cid = (int)($_POST['campaign_id'] ?? 0);
                $pdo->prepare("UPDATE campaigns SET title=?,description=?,brief=?,target_city=?,target_category=?,commission_xaf=?,bonus_xaf=?,max_participants=?,starts_at=?,ends_at=?,status=?,updated_at=NOW() WHERE id=?")
                    ->execute([$title,$description,$brief,$city,$category,$commission,$bonus,$maxP,$starts,$ends,$status,$cid]);
                flash('success', 'Campaign updated.');
            }
            redirect(SITE_URL . '/admin/manage-campaigns?tab=campaigns');
        }
    }
}

// ── Data ──────────────────────────────────────────────────────────────────
$campaigns = $pdo->query("
    SELECT c.*,
        (SELECT COUNT(*) FROM campaign_participants cp WHERE cp.campaign_id=c.id) AS participant_count,
        (SELECT COUNT(*) FROM content_submissions cs WHERE cs.campaign_id=c.id AND cs.status='approved') AS approved_submissions,
        (SELECT COUNT(*) FROM content_submissions cs WHERE cs.campaign_id=c.id AND cs.status='pending') AS pending_submissions
    FROM campaigns c ORDER BY c.created_at DESC
")->fetchAll();

$editId = (int)($_GET['edit'] ?? 0);
$editCampaign = null;
if ($editId) {
    $es = $pdo->prepare("SELECT * FROM campaigns WHERE id=?");
    $es->execute([$editId]);
    $editCampaign = $es->fetch();
    $tab = 'form';
}

$pageTitle = 'Manage Campaigns — Admin — 237Biz';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.campaign-card { background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:18px 20px;margin-bottom:10px; }
.campaign-status { font-size:11.5px;font-weight:700;padding:3px 10px;border-radius:99px; }
.cs-draft     { background:rgba(255,255,255,0.07);color:rgba(255,255,255,0.5); }
.cs-active    { background:rgba(0,168,120,0.15);color:#00A878; }
.cs-completed { background:rgba(138,180,248,0.12);color:#8ab4f8; }
.cs-cancelled { background:rgba(206,17,38,0.1);color:#ff6b7a; }
</style>

<div class="page-header">
  <div class="container">
    <nav class="breadcrumb"><a href="<?= SITE_URL ?>/admin/">Admin</a> › <span>Campaigns</span></nav>
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.5rem,3vw,2rem);">📣 Manage Campaigns</h1>
  </div>
</div>

<section class="page-section" style="padding-top:1.25rem;">
<div class="container">

  <div class="filter-tabs" style="margin-bottom:1.5rem;flex-wrap:wrap;">
    <a href="?tab=campaigns" class="filter-tab <?= $tab==='campaigns'?'active':'' ?>">📣 Campaigns (<?= count($campaigns) ?>)</a>
    <a href="?tab=form"      class="filter-tab <?= $tab==='form'     ?'active':'' ?>"><?= $editCampaign?'✏️ Edit':'+ New Campaign' ?></a>
  </div>

  <?php if (!empty($errors)): ?>
  <div style="background:rgba(206,17,38,0.1);border:1px solid rgba(206,17,38,0.3);border-radius:8px;padding:12px 16px;margin-bottom:16px;">
    <?php foreach($errors as $e): ?><div style="font-size:13.5px;color:#ff6b7a;"><?= e($e) ?></div><?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($tab === 'form'): ?>
  <!-- Campaign form -->
  <div style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.08);border-radius:14px;padding:24px;max-width:680px;">
    <h3 style="margin-top:0;"><?= $editCampaign ? 'Edit Campaign' : 'New Campaign' ?></h3>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="action" value="<?= $editCampaign ? 'update_campaign' : 'create_campaign' ?>">
      <?php if ($editCampaign): ?><input type="hidden" name="campaign_id" value="<?= $editCampaign['id'] ?>"><?php endif; ?>

      <div class="form-group"><label>Campaign Title *</label>
        <input type="text" name="title" required value="<?= e($editCampaign['title'] ?? '') ?>"></div>

      <div class="form-group"><label>Description <span style="font-size:11px;color:var(--muted);">(shown to creators on the campaigns page)</span></label>
        <textarea name="description" rows="3"><?= e($editCampaign['description'] ?? '') ?></textarea></div>

      <div class="form-group"><label>Content Brief <span style="font-size:11px;color:var(--muted);">(what content is required — hashtags, tone, requirements)</span></label>
        <textarea name="brief" rows="4"><?= e($editCampaign['brief'] ?? '') ?></textarea></div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group"><label>Target City</label>
          <input type="text" name="target_city" value="<?= e($editCampaign['target_city'] ?? '') ?>" placeholder="e.g. Limbe, Buea, All"></div>
        <div class="form-group"><label>Target Category</label>
          <input type="text" name="target_category" value="<?= e($editCampaign['target_category'] ?? '') ?>" placeholder="e.g. Food, Tech"></div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
        <div class="form-group"><label>Commission per submission (XAF)</label>
          <input type="number" name="commission_xaf" value="<?= (int)($editCampaign['commission_xaf'] ?? 0) ?>" min="0" step="500"></div>
        <div class="form-group"><label>Top performer bonus (XAF)</label>
          <input type="number" name="bonus_xaf" value="<?= (int)($editCampaign['bonus_xaf'] ?? 0) ?>" min="0" step="500"></div>
        <div class="form-group"><label>Max participants <span style="font-size:10px;color:var(--muted);">(blank = unlimited)</span></label>
          <input type="number" name="max_participants" value="<?= $editCampaign['max_participants'] ?? '' ?>" min="1" placeholder="Unlimited"></div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group"><label>Start date</label>
          <input type="datetime-local" name="starts_at" value="<?= $editCampaign ? date('Y-m-d\TH:i',strtotime($editCampaign['starts_at']??'')) : '' ?>"></div>
        <div class="form-group"><label>End date</label>
          <input type="datetime-local" name="ends_at" value="<?= $editCampaign ? date('Y-m-d\TH:i',strtotime($editCampaign['ends_at']??'')) : '' ?>"></div>
      </div>

      <div class="form-group"><label>Status</label>
        <select name="status">
          <?php foreach (['draft','active','completed','cancelled'] as $s): ?>
          <option value="<?= $s ?>" <?= ($editCampaign['status']??'draft')===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select></div>

      <div style="display:flex;gap:10px;margin-top:6px;">
        <button type="submit" class="btn btn-primary"><?= $editCampaign ? '💾 Update Campaign' : '+ Create Campaign' ?></button>
        <a href="?tab=campaigns" class="btn btn-outline">Cancel</a>
      </div>
    </form>
  </div>

  <?php else: ?>
  <!-- Campaigns list -->
  <?php if (empty($campaigns)): ?>
  <div style="text-align:center;padding:40px;color:var(--muted);">
    <div style="font-size:2.5rem;margin-bottom:12px;">📣</div>
    <p>No campaigns yet. <a href="?tab=form" style="color:var(--green);">Create your first campaign →</a></p>
  </div>
  <?php else: ?>
  <?php foreach ($campaigns as $c): ?>
  <div class="campaign-card">
    <div style="display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap;">
      <div style="flex:1;min-width:220px;">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px;">
          <strong style="font-size:15px;"><?= e($c['title']) ?></strong>
          <span class="campaign-status cs-<?= $c['status'] ?>"><?= ucfirst($c['status']) ?></span>
        </div>
        <?php if ($c['description']): ?>
        <p style="font-size:13px;color:var(--muted);margin:0 0 8px;"><?= e(mb_substr($c['description'],0,100)) ?>…</p>
        <?php endif; ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <?php if ($c['target_city']): ?><span style="font-size:12px;color:var(--muted);">📍 <?= e($c['target_city']) ?></span><?php endif; ?>
          <?php if ($c['starts_at']): ?><span style="font-size:12px;color:var(--muted);">🗓 <?= date('d M',strtotime($c['starts_at'])) ?> – <?= $c['ends_at']?date('d M Y',strtotime($c['ends_at'])):'?' ?></span><?php endif; ?>
        </div>
      </div>

      <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center;">
        <?php foreach ([['Participants',$c['participant_count'],'#fff'],['Approved',$c['approved_submissions'],'#00A878'],['Pending',$c['pending_submissions'],'#fcd116'],['Commission/sub',formatXaf((int)$c['commission_xaf']),'rgba(255,255,255,0.7)']] as [$lbl,$val,$col]): ?>
        <div style="text-align:center;">
          <div style="font-size:1.05rem;font-weight:900;font-family:'Fraunces',serif;color:<?= $col ?>;"><?= $val ?></div>
          <div style="font-size:11px;color:var(--muted);"><?= $lbl ?></div>
        </div>
        <?php endforeach; ?>
        <a href="?edit=<?= $c['id'] ?>" class="btn btn-outline" style="font-size:12px;padding:5px 14px;">✏️ Edit</a>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
  <?php endif; ?>

</div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
