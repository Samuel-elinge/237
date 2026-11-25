<?php
/**
 * creator/referrals.php — Link Manager
 * Create & track multiple named referral links (A/B testing, per-platform).
 * Account-level stats (tier progress, total earnings) aggregate across all links.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/referral-helpers.php';
$u = currentUser();
if (!$u || !in_array($u['role'],['creator','admin'])) redirect(SITE_URL . '/login');
$uid = (int)$u['id'];
$pdo = db();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_link') {
    verifyCsrf();
    $label = trim($_POST['label'] ?? '');
    if (!$label) {
        $errors[] = 'Please enter a name for this link.';
    } elseif (mb_strlen($label) > 100) {
        $errors[] = 'Link name is too long.';
    } else {
        $newLink = createCreatorLink($uid, $label);
        flash('success', 'Link created: ' . SITE_URL . '/r/' . $newLink['code']);
        redirect(SITE_URL . '/creator/referrals');
    }
}

// Ensure the general campaign + link exist (idempotent)
ensureGeneralCampaignAndLink($uid);

$links = getCreatorLinks($uid);
$commSummary = getUserCommissionSummary($uid);
$tierProgress = getCreatorTierProgress($uid);

// Account-level totals (across every link)
$totalClicks = 0; $totalConversions = 0; $totalRevenue = 0; $totalCommission = 0;
foreach ($links as $l) {
    $totalClicks      += (int)$l['clicks'];
    $totalConversions += (int)$l['conversions'];
    $totalRevenue     += (int)$l['revenue_xaf'];
    $totalCommission  += (int)$l['commission_xaf'];
}

$pageTitle = 'My Referrals — 237Biz Creator';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>
<?php creatorNavAssets(); ?>
<?php creatorNav('referrals'); ?>

<style>
.stat-card{background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:14px 18px;}
.stat-card .val{font-size:1.7rem;font-weight:900;font-family:'Fraunces',serif;}
.stat-card .lbl{font-size:11.5px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;}
.row-item{padding:10px 0;border-bottom:1px solid rgba(255,255,255,0.05);display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
.link-table{width:100%;border-collapse:collapse;}
.link-table th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);padding:8px 10px;border-bottom:1px solid rgba(255,255,255,0.1);}
.link-table td{padding:10px;border-bottom:1px solid rgba(255,255,255,0.05);font-size:13px;vertical-align:middle;}
.link-table tr:last-child td{border-bottom:none;}
.link-badge{display:inline-block;font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:99px;background:rgba(0,168,120,0.15);color:#00A878;margin-left:6px;}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:500;align-items:center;justify-content:center;padding:16px;}
.modal-overlay.open{display:flex;}
.modal-box{background:#0b1f17;border:1px solid rgba(0,168,120,0.3);border-radius:14px;padding:24px;max-width:420px;width:100%;}
</style>

<div class="page-header"><div class="container">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.4rem,3vw,1.9rem);margin:0;">🔗 My Referrals</h1>
    <button type="button" onclick="document.getElementById('createLinkModal').classList.add('open')" class="btn btn-primary" style="font-size:13px;padding:8px 16px;">+ Create New Link</button>
  </div>
</div></div>

<section class="page-section" style="padding-top:1.25rem;"><div class="container">

  <?php if (!empty($errors)): ?>
  <div style="background:rgba(206,17,38,0.1);border:1px solid rgba(206,17,38,0.3);border-radius:8px;padding:12px 16px;margin-bottom:16px;">
    <?php foreach($errors as $err): ?><div style="font-size:13.5px;color:#ff6b7a;"><?= e($err) ?></div><?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Account-level stats -->
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;margin-bottom:1.5rem;">
    <div class="stat-card"><div class="lbl">Total clicks</div><div class="val"><?= $totalClicks ?></div></div>
    <div class="stat-card"><div class="lbl">Conversions</div><div class="val" style="color:#00A878;"><?= $totalConversions ?></div></div>
    <div class="stat-card"><div class="lbl">Revenue driven</div><div class="val" style="font-size:1.2rem;"><?= formatXaf($totalRevenue) ?></div></div>
    <div class="stat-card"><div class="lbl">Commission earned</div><div class="val" style="font-size:1.2rem;color:#fcd116;"><?= formatXaf($totalCommission) ?></div></div>
    <div class="stat-card"><div class="lbl">Current tier</div><div class="val" style="color:#00A878;"><?= (int)$tierProgress['tier'] ?>%</div></div>
    <div class="stat-card"><div class="lbl">Paid out</div><div class="val" style="font-size:1.2rem;color:#8ab4f8;"><?= formatXaf($commSummary['paid_xaf']) ?></div></div>
  </div>

  <p style="font-size:12.5px;color:var(--muted);margin-bottom:1rem;">Tier progress and payouts are calculated at the account level — across all of your links combined, not per link.</p>

  <!-- Link table -->
  <div style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:6px 10px 10px;overflow-x:auto;">
    <?php if (empty($links)): ?>
    <p style="color:var(--muted);font-size:13.5px;padding:20px;">No links yet. Create your first one above.</p>
    <?php else: ?>
    <table class="link-table">
      <thead>
        <tr>
          <th>Link Name</th>
          <th>Clicks</th>
          <th>Conversions</th>
          <th>Revenue</th>
          <th>Commission</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($links as $l): ?>
        <tr>
          <td>
            <div style="font-weight:700;">
              <?= e($l['label'] ?: $l['code']) ?>
              <?php if ($l['link_type'] === 'general'): ?><span class="link-badge">General</span><?php endif; ?>
              <?php if ($l['campaign_title'] && $l['link_type'] !== 'general'): ?><span class="link-badge" style="background:rgba(138,180,248,0.12);color:#8ab4f8;">📣 <?= e($l['campaign_title']) ?></span><?php endif; ?>
            </div>
            <div style="font-family:monospace;font-size:11.5px;color:#00A878;margin-top:2px;"><?= SITE_URL ?>/r/<?= e($l['code']) ?></div>
          </td>
          <td><?= (int)$l['clicks'] ?> <span style="color:var(--muted);font-size:11px;">(<?= (int)$l['unique_clicks'] ?> unique)</span></td>
          <td><?= (int)$l['conversions'] ?></td>
          <td><?= formatXaf((int)$l['revenue_xaf']) ?></td>
          <td style="color:#00A878;font-weight:700;"><?= formatXaf((int)$l['commission_xaf']) ?></td>
          <td style="white-space:nowrap;">
            <button onclick="navigator.clipboard.writeText('<?= SITE_URL ?>/r/<?= e($l['code']) ?>').then(()=>{this.textContent='✅';setTimeout(()=>this.textContent='📋',1500)})"
                    style="background:rgba(0,168,120,0.15);border:1px solid rgba(0,168,120,0.3);color:#00A878;border-radius:6px;padding:5px 10px;font-size:12px;cursor:pointer;font-family:inherit;">📋</button>
            <a href="https://wa.me/?text=<?= urlencode('Find local Cameroon businesses at 237Biz.net — ' . SITE_URL . '/r/' . $l['code']) ?>" target="_blank"
               style="background:rgba(37,211,102,0.12);border:1px solid rgba(37,211,102,0.3);color:#25d366;border-radius:6px;padding:5px 10px;font-size:12px;text-decoration:none;display:inline-block;">💬</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-top:1.5rem;" class="ref-layout">

    <!-- Conversions -->
    <div>
      <h2 style="font-size:1rem;font-weight:700;margin-bottom:12px;">📈 Businesses Referred</h2>
      <?php
      $linkIds = array_column($links, 'id');
      $conversions = [];
      if ($linkIds) {
          $in = implode(',', array_fill(0, count($linkIds), '?'));
          $cv = $pdo->prepare("
              SELECT rc.*, l.title AS listing_title, l.slug AS listing_slug
              FROM referral_conversions rc
              LEFT JOIN listings l ON l.id=rc.listing_id
              WHERE rc.link_id IN ({$in}) ORDER BY rc.created_at DESC LIMIT 20
          ");
          $cv->execute($linkIds);
          $conversions = $cv->fetchAll();
      }
      ?>
      <?php if (empty($conversions)): ?>
      <p style="color:var(--muted);font-size:13.5px;">No conversions yet. Share your links to start earning.</p>
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
          <span style="font-size:11.5px;font-weight:700;padding:2px 9px;border-radius:99px;background:rgba(255,255,255,0.05);color:<?= ['pending'=>'#fcd116','approved'=>'#00A878','paid'=>'#8ab4f8','rejected'=>'#ff6b7a'][$cv['status']] ?? 'var(--muted)' ?>;"><?= ucfirst($cv['status'] ?? 'pending') ?></span>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- All commissions -->
    <div>
      <h2 style="font-size:1rem;font-weight:700;margin-bottom:12px;">💰 All Earnings</h2>
      <?php
      $commissions = $pdo->prepare("SELECT * FROM commissions WHERE user_id=? ORDER BY created_at DESC LIMIT 20");
      $commissions->execute([$uid]);
      $commissions = $commissions->fetchAll();
      ?>
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

<!-- Create link modal -->
<div class="modal-overlay" id="createLinkModal">
  <div class="modal-box">
    <h3 style="margin-top:0;font-size:1.05rem;">+ Create New Link</h3>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="action" value="create_link">
      <div class="form-group">
        <label>Link name</label>
        <input type="text" name="label" required maxlength="100" placeholder="e.g. TikTok - Restaurant Promo" autofocus>
        <p style="font-size:11.5px;color:var(--muted);margin-top:4px;">Use this to tell apart different platforms or campaigns — e.g. "TikTok Bio", "IG Story - March".</p>
      </div>
      <div style="display:flex;gap:10px;margin-top:10px;">
        <button type="submit" class="btn btn-primary">Create Link</button>
        <button type="button" class="btn btn-outline" onclick="document.getElementById('createLinkModal').classList.remove('open')">Cancel</button>
      </div>
    </form>
  </div>
</div>
<script>
document.getElementById('createLinkModal').addEventListener('click', function(e){
  if (e.target === this) this.classList.remove('open');
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
