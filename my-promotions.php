<?php
require_once __DIR__ . '/includes/config.php';
requireLogin();

$u = currentUser();

// Get user's listings
$listings = db()->prepare("SELECT id, title FROM listings WHERE user_id=? AND status='active' ORDER BY title");
$listings->execute([$u['id']]);
$listings = $listings->fetchAll();

$filterListing = (int)($_GET['listing_id'] ?? 0);
$filterStatus  = $_GET['status'] ?? 'all';

$whereUser = "po.user_id = {$u['id']}";
if ($filterListing) $whereUser .= " AND po.listing_id=$filterListing";
if ($filterStatus !== 'all') {
    $safe = ['active','pending_payment','pending_approval','completed','rejected','cancelled'];
    if (in_array($filterStatus, $safe)) {
        $whereUser .= " AND po.promo_status='$filterStatus'";
    }
}

$promotions = db()->query("SELECT po.*, pp.name_en, pp.name_fr, pp.promo_type, pp.duration_days,
    l.title AS listing_title
    FROM promotion_orders po
    JOIN promotion_products pp ON pp.id = po.product_id
    LEFT JOIN listings l ON l.id = po.listing_id
    WHERE $whereUser
    ORDER BY po.created_at DESC
    LIMIT 100")->fetchAll();

// Stats summary
$stats = db()->prepare("SELECT
    COUNT(*) AS total,
    SUM(promo_status='active') AS active_count,
    SUM(promo_status='pending_payment' OR promo_status='pending_approval') AS pending_count,
    SUM(amount_xaf) AS total_spent
    FROM promotion_orders WHERE user_id=?");
$stats->execute([$u['id']]);
$stats = $stats->fetch();

$pageTitle = t('My Promotions — 237Biz', 'Mes Promotions — 237Biz');
require_once __DIR__ . '/includes/header.php';
?>
<style>
.promo-status-badge{display:inline-flex;align-items:center;gap:.3rem;padding:.25rem .75rem;border-radius:99px;font-size:.75rem;font-weight:600;}
.promo-card-row{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:1rem 1.1rem;display:grid;grid-template-columns:1fr auto;gap:.75rem;align-items:start;}
.promo-card-row:hover{border-color:var(--accent);}
.promo-type-chip{display:inline-block;padding:.15rem .55rem;border-radius:5px;font-size:.7rem;font-weight:600;background:rgba(109,40,217,.1);color:#7c3aed;}
</style>

<div class="page-header">
  <div class="container">
    <nav class="breadcrumb">
      <a href="<?= SITE_URL ?>/dashboard"><?= t('Dashboard', 'Tableau de bord') ?></a> ›
      <span><?= t('My Promotions', 'Mes Promotions') ?></span>
    </nav>
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.6rem,2.5vw,2.2rem);">
      📣 <?= t('My Promotions', 'Mes Promotions') ?>
    </h1>
  </div>
</div>

<section class="page-section">
<div class="container" style="max-width:900px;">

<!-- Stats row -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:1rem;margin-bottom:2rem;">
  <?php $statCards = [
    [t('Total Orders','Total commandes'), $stats['total'], '📋'],
    [t('Active Now','Actives maintenant'), $stats['active_count'], '✅'],
    [t('Pending','En attente'), $stats['pending_count'], '⏳'],
    [t('Total Spent','Total dépensé'), number_format($stats['total_spent'] ?: 0) . ' XAF', '💰'],
  ];
  foreach ($statCards as [$label, $val, $icon]): ?>
  <div style="background:var(--card);border:1px solid var(--border);border-radius:10px;padding:1rem;text-align:center;">
    <div style="font-size:1.4rem;margin-bottom:.25rem;"><?= $icon ?></div>
    <div style="font-size:1.4rem;font-weight:900;font-family:'Fraunces',serif;"><?= $val ?></div>
    <div style="font-size:.75rem;color:var(--muted);"><?= $label ?></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Filters -->
<form method="get" style="display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:1.5rem;align-items:center;">
  <?php if (count($listings) > 1): ?>
  <select name="listing_id" class="form-control" style="width:auto;flex:1;min-width:180px;">
    <option value=""><?= t('All listings', 'Toutes les annonces') ?></option>
    <?php foreach ($listings as $lx): ?>
    <option value="<?= $lx['id'] ?>" <?= $filterListing==$lx['id']?'selected':'' ?>><?= e($lx['title']) ?></option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>
  <select name="status" class="form-control" style="width:auto;flex:1;min-width:150px;">
    <option value="all" <?= $filterStatus==='all'?'selected':'' ?>><?= t('All statuses','Tous les statuts') ?></option>
    <option value="active" <?= $filterStatus==='active'?'selected':'' ?>><?= t('Active','Active') ?></option>
    <option value="pending_payment" <?= $filterStatus==='pending_payment'?'selected':'' ?>><?= t('Pending payment','En attente paiement') ?></option>
    <option value="pending_approval" <?= $filterStatus==='pending_approval'?'selected':'' ?>><?= t('Pending approval','En attente approbation') ?></option>
    <option value="completed" <?= $filterStatus==='completed'?'selected':'' ?>><?= t('Completed','Terminée') ?></option>
    <option value="rejected" <?= $filterStatus==='rejected'?'selected':'' ?>><?= t('Rejected','Rejetée') ?></option>
  </select>
  <button type="submit" class="btn btn-secondary btn-sm"><?= t('Filter','Filtrer') ?></button>
</form>

<!-- CTA buttons -->
<div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:1.5rem;">
  <?php foreach ($listings as $lx): ?>
  <a href="<?= SITE_URL ?>/promote?listing_id=<?= $lx['id'] ?>" class="btn btn-primary btn-sm">
    📣 <?= t('Promote', 'Promouvoir') ?> — <?= e(mb_strimwidth($lx['title'], 0, 30, '…')) ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- Promotion list -->
<?php if (!$promotions): ?>
<div style="text-align:center;padding:3rem;background:var(--card);border:1px solid var(--border);border-radius:12px;">
  <div style="font-size:2.5rem;margin-bottom:.75rem;">📣</div>
  <h3 style="font-family:'Fraunces',serif;"><?= t('No promotions yet', 'Aucune promotion encore') ?></h3>
  <p style="color:var(--muted);"><?= t('Boost visibility by promoting your listing.', 'Augmentez la visibilité en promouvant votre annonce.') ?></p>
  <?php if ($listings): ?>
  <a href="<?= SITE_URL ?>/promote?listing_id=<?= $listings[0]['id'] ?>" class="btn btn-primary" style="margin-top:1rem;">
    <?= t('Launch First Promotion', 'Lancer la première promotion') ?>
  </a>
  <?php endif; ?>
</div>
<?php else: ?>
<div style="display:flex;flex-direction:column;gap:.75rem;">
  <?php foreach ($promotions as $p):
    $promoName = lang() === 'fr' ? $p['name_fr'] : $p['name_en'];
    $statusDefs = [
      'active'           => ['bg'=>'rgba(0,168,120,.12)','c'=>'#00A878','label'=>t('Active','Active')],
      'pending_payment'  => ['bg'=>'rgba(245,158,11,.12)','c'=>'#f59e0b','label'=>t('Pending Payment','Paiement en attente')],
      'pending_approval' => ['bg'=>'rgba(245,158,11,.12)','c'=>'#f59e0b','label'=>t('Pending Approval','Approbation en attente')],
      'completed'        => ['bg'=>'rgba(107,114,128,.12)','c'=>'#6b7280','label'=>t('Completed','Terminée')],
      'rejected'         => ['bg'=>'rgba(239,68,68,.12)','c'=>'#ef4444','label'=>t('Rejected','Rejetée')],
      'cancelled'        => ['bg'=>'rgba(107,114,128,.12)','c'=>'#6b7280','label'=>t('Cancelled','Annulée')],
      'scheduled'        => ['bg'=>'rgba(59,130,246,.12)','c'=>'#3b82f6','label'=>t('Scheduled','Planifiée')],
      'draft'            => ['bg'=>'rgba(107,114,128,.12)','c'=>'#6b7280','label'=>t('Draft','Brouillon')],
    ];
    $sd = $statusDefs[$p['promo_status']] ?? $statusDefs['draft'];
    $daysLeft = null;
    if ($p['promo_status'] === 'active' && $p['ends_at']) {
      $daysLeft = max(0, (int)ceil((strtotime($p['ends_at']) - time()) / 86400));
    }
  ?>
  <div class="promo-card-row">
    <div>
      <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:.4rem;">
        <span style="font-family:'Fraunces',serif;font-weight:700;"><?= e($promoName) ?></span>
        <span class="promo-type-chip"><?= e($p['promo_type']) ?></span>
        <span class="promo-status-badge" style="background:<?= $sd['bg'] ?>;color:<?= $sd['c'] ?>;"><?= $sd['label'] ?></span>
      </div>
      <div style="font-size:.83rem;color:var(--muted);margin-bottom:.3rem;"><?= e($p['listing_title']) ?></div>
      <div style="font-size:.8rem;color:var(--muted);display:flex;gap:1rem;flex-wrap:wrap;">
        <span>Ref: <code><?= e($p['ref']) ?></code></span>
        <span><?= number_format($p['amount_xaf']) ?> XAF</span>
        <?php if ($p['starts_at']): ?>
        <span><?= date('d M Y', strtotime($p['starts_at'])) ?> → <?= date('d M Y', strtotime($p['ends_at'])) ?></span>
        <?php endif; ?>
        <?php if ($daysLeft !== null): ?>
        <span style="color:<?= $daysLeft <= 3 ? '#ef4444' : 'var(--accent)' ?>;font-weight:600;">
          <?= $daysLeft ?> <?= t('days left', 'jours restants') ?>
        </span>
        <?php endif; ?>
      </div>
      <?php if ($p['rejection_reason']): ?>
      <div style="margin-top:.4rem;font-size:.8rem;color:#f59e0b;">⚠ <?= e($p['rejection_reason']) ?></div>
      <?php endif; ?>
    </div>
    <div style="text-align:right;flex-shrink:0;">
      <?php if (in_array($p['promo_status'], ['rejected','completed'])): ?>
      <a href="<?= SITE_URL ?>/promote?listing_id=<?= $p['listing_id'] ?>" class="btn btn-secondary btn-sm">
        <?= t('Reorder', 'Recommander') ?>
      </a>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

</div><!-- /container -->
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
