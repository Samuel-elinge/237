<?php
/**
 * partner/leads.php — All Leads (across portfolio)
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';

$partnerProfile = requireGrowthPartner();
$partnerId      = (int)$partnerProfile['id'];
$pdo            = db();

$statusFilter  = $_GET['status']  ?? 'active';
$listingFilter = (int)($_GET['listing'] ?? 0);
$search        = trim($_GET['q'] ?? '');

// Handle POST: update lead status
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'update_lead') {
        $leadId = (int)$_POST['lead_id'];
        $st = $pdo->prepare("SELECT id, listing_id FROM partner_leads WHERE id=? AND partner_id=?");
        $st->execute([$leadId, $partnerId]);
        $lead = $st->fetch();
        if ($lead) {
            $newStatus  = in_array($_POST['new_status'], ['new','contacted','follow_up','qualified','converted','lost','closed']) ? $_POST['new_status'] : null;
            $nextAction = trim($_POST['next_action'] ?? '');
            $notes      = trim($_POST['notes'] ?? '');
            if ($newStatus) {
                $pdo->prepare("UPDATE partner_leads SET status=?, next_action=?, notes=?, last_activity=NOW() WHERE id=?")
                    ->execute([$newStatus, $nextAction ?: null, $notes ?: null, $leadId]);
                partnerAuditLog($partnerId, currentUser()['id'], $lead['listing_id'], 'lead_updated', "Lead #{$leadId} status → {$newStatus}");
            }
        }
    }
    header('Location: ?status=' . urlencode($statusFilter));
    exit;
}

// Build query
$where  = ["pl.partner_id = ?"];
$params = [$partnerId];

if ($statusFilter === 'active') {
    $where[] = "pl.status IN ('new','contacted','follow_up')";
} elseif ($statusFilter === 'pipeline') {
    $where[] = "pl.status IN ('new','contacted','follow_up','qualified')";
} elseif (in_array($statusFilter, ['new','contacted','follow_up','qualified','converted','lost','closed'])) {
    $where[] = "pl.status = ?";
    $params[] = $statusFilter;
}

if ($listingFilter) {
    $where[] = "pl.listing_id = ?";
    $params[] = $listingFilter;
}

if ($search) {
    $where[] = "(pl.customer_name LIKE ? OR pl.customer_email LIKE ? OR pl.customer_phone LIKE ? OR l.title LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}

$st = $pdo->prepare("
    SELECT pl.*, l.title AS listing_title, c.name_en AS cat_en, loc.name_en AS city
    FROM partner_leads pl
    JOIN listings l ON l.id = pl.listing_id
    JOIN categories c ON c.id = l.category_id
    JOIN locations loc ON loc.id = l.location_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY FIELD(pl.status,'new','follow_up','contacted','qualified','converted','lost','closed'),
             pl.last_activity DESC, pl.created_at DESC
");
$st->execute($params);
$leads = $st->fetchAll();

$myListings = fetchPartnerListings($partnerId);

$pageTitle = 'Leads — Partner Centre';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.partner-wrap { max-width:1280px; margin:0 auto; padding:2rem 1.5rem; }
.partner-nav { display:flex; gap:0.5rem; flex-wrap:wrap; margin-bottom:2rem; }
.partner-nav a { padding:0.45rem 1rem; border-radius:9px; font-size:0.875rem; font-weight:600;
  text-decoration:none; background:var(--card); border:1px solid var(--border); color:var(--text); transition:all .15s; }
.partner-nav a:hover, .partner-nav a.active { background:var(--primary); color:#fff; border-color:var(--primary); }

.status-tab { padding:0.35rem 0.9rem; border-radius:20px; border:1px solid var(--border);
  font-size:0.8rem; cursor:pointer; background:var(--card); color:var(--text); text-decoration:none; }
.status-tab.active { background:var(--primary); color:#fff; border-color:var(--primary); }

.lead-card { background:var(--card); border:1px solid var(--border); border-radius:12px; padding:1.1rem 1.25rem; margin-bottom:0.75rem; }
.lead-header { display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; flex-wrap:wrap; }
.lead-status { padding:3px 10px; border-radius:20px; font-size:0.72rem; font-weight:700; text-transform:uppercase; }
.ls-new        { background:rgba(252,209,22,.2); color:#b8960f; }
.ls-contacted  { background:rgba(0,168,120,.12); color:#00A878; }
.ls-follow_up  { background:rgba(255,159,28,.2); color:#d4780f; }
.ls-qualified  { background:rgba(46,196,182,.15); color:#1a8f88; }
.ls-converted  { background:rgba(0,168,120,.25); color:#006e50; }
.ls-lost       { background:rgba(230,57,70,.12); color:#e63946; }
.ls-closed     { background:rgba(150,150,150,.15); color:var(--muted); }
</style>

<div class="partner-wrap">
  <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1rem; margin-bottom:1.5rem;">
    <h1 style="font-family:'Fraunces',serif; font-size:2rem; font-weight:900; margin:0;">💬 Leads</h1>
    <span style="color:var(--muted);"><?= count($leads) ?> lead<?= count($leads) !== 1 ? 's' : '' ?></span>
  </div>

  <nav class="partner-nav">
    <a href="<?= SITE_URL ?>/partner/dashboard">🏠 Dashboard</a>
    <a href="<?= SITE_URL ?>/partner/portfolio">📋 Portfolio</a>
    <a href="<?= SITE_URL ?>/partner/tasks">✅ Tasks</a>
    <a href="<?= SITE_URL ?>/partner/leads" class="active">💬 Leads</a>
    <a href="<?= SITE_URL ?>/partner/commissions">💰 Commissions</a>
  </nav>

  <!-- Status tabs -->
  <div style="display:flex; gap:0.4rem; flex-wrap:wrap; margin-bottom:1rem;">
    <?php
    $tabs = ['active'=>'Active','pipeline'=>'Full Pipeline','new'=>'New','contacted'=>'Contacted','follow_up'=>'Follow Up','qualified'=>'Qualified','converted'=>'Converted','lost'=>'Lost'];
    foreach ($tabs as $tk => $tl): ?>
    <a href="?status=<?= $tk ?><?= $listingFilter ? '&listing='.$listingFilter : '' ?>" class="status-tab <?= $statusFilter===$tk?'active':'' ?>"><?= $tl ?></a>
    <?php endforeach; ?>
  </div>

  <!-- Filters -->
  <form method="GET" style="display:flex; gap:0.75rem; flex-wrap:wrap; align-items:center; margin-bottom:1.5rem;">
    <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
    <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search leads…"
      style="flex:1; min-width:180px; padding:0.45rem 0.75rem; border:1px solid var(--border); border-radius:8px; background:var(--card); color:var(--text); font-size:0.875rem;">
    <select name="listing" style="padding:0.45rem 0.75rem; border:1px solid var(--border); border-radius:8px; background:var(--card); color:var(--text); font-size:0.875rem;">
      <option value="">All Businesses</option>
      <?php foreach ($myListings as $ml): ?>
      <option value="<?= $ml['id'] ?>" <?= $listingFilter===$ml['id']?'selected':'' ?>><?= e($ml['title']) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary" style="white-space:nowrap;">Filter</button>
  </form>

  <?php if (!$leads): ?>
    <div style="text-align:center; padding:4rem; background:var(--card); border:1px solid var(--border); border-radius:14px;">
      <div style="font-size:3rem; margin-bottom:1rem;">📭</div>
      <h3 style="font-family:'Fraunces',serif;">No leads found</h3>
      <p style="color:var(--muted);">Leads are added from the business management page.</p>
    </div>
  <?php else: ?>
    <?php foreach ($leads as $lead): ?>
    <div class="lead-card">
      <div class="lead-header">
        <div style="flex:1; min-width:0;">
          <div style="display:flex; align-items:center; gap:0.6rem; flex-wrap:wrap; margin-bottom:0.3rem;">
            <strong><?= e($lead['customer_name'] ?: 'Unknown Customer') ?></strong>
            <span class="lead-status ls-<?= $lead['status'] ?>"><?= str_replace('_',' ', $lead['status']) ?></span>
            <?php if ($lead['source']): ?><span style="font-size:0.78rem; color:var(--muted);">via <?= e(ucfirst($lead['source'])) ?></span><?php endif; ?>
          </div>
          <div style="font-size:0.78rem; color:var(--muted); display:flex; gap:1rem; flex-wrap:wrap;">
            <?php if ($lead['customer_email']): ?><span>✉ <?= e($lead['customer_email']) ?></span><?php endif; ?>
            <?php if ($lead['customer_phone']): ?><span>📞 <?= e($lead['customer_phone']) ?></span><?php endif; ?>
            <span>📍 <a href="<?= SITE_URL ?>/partner/business?id=<?= $lead['listing_id'] ?>" style="color:var(--primary); text-decoration:none;"><?= e($lead['listing_title']) ?></a></span>
            <span><?= date('j M Y', strtotime($lead['created_at'])) ?></span>
          </div>
        </div>
        <a href="<?= SITE_URL ?>/partner/business?id=<?= $lead['listing_id'] ?>&tab=leads" style="font-size:0.8rem; color:var(--primary); text-decoration:none; flex-shrink:0;">View →</a>
      </div>

      <?php if ($lead['notes'] || $lead['next_action']): ?>
      <div style="margin-top:0.5rem; font-size:0.83rem; color:var(--muted);">
        <?php if ($lead['next_action']): ?><div>→ <strong>Next:</strong> <?= e($lead['next_action']) ?></div><?php endif; ?>
        <?php if ($lead['notes']): ?><div style="margin-top:0.2rem;"><?= e(mb_strimwidth($lead['notes'], 0, 140, '…')) ?></div><?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Quick status update -->
      <?php if (!in_array($lead['status'], ['converted','lost','closed'])): ?>
      <details style="margin-top:0.75rem;">
        <summary style="font-size:0.8rem; color:var(--primary); cursor:pointer; user-select:none;">Update status</summary>
        <form method="POST" style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:flex-end; margin-top:0.5rem;">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="update_lead">
          <input type="hidden" name="lead_id" value="<?= $lead['id'] ?>">
          <select name="new_status" style="padding:0.4rem; border:1px solid var(--border); border-radius:6px; background:var(--bg); color:var(--text); font-size:0.83rem;">
            <?php foreach (['new','contacted','follow_up','qualified','converted','lost','closed'] as $s): ?>
            <option value="<?= $s ?>" <?= $lead['status']===$s?'selected':'' ?>><?= ucwords(str_replace('_',' ',$s)) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" name="next_action" value="<?= e($lead['next_action'] ?? '') ?>" placeholder="Next action…"
            style="flex:1; min-width:140px; padding:0.4rem 0.6rem; border:1px solid var(--border); border-radius:6px; background:var(--bg); color:var(--text); font-size:0.83rem;">
          <button type="submit" class="btn btn-primary" style="font-size:0.83rem; padding:0.35rem 0.75rem;">Save</button>
        </form>
      </details>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
