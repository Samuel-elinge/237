<?php
/**
 * partner/earnings.php — Phase 3 Commission Earnings
 *
 * Shows a partner their commissions earned from subscription
 * and promotion purchases attributed to their referral links.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';
if (file_exists(__DIR__ . '/../includes/partner-lang.php')) {
    require_once __DIR__ . '/../includes/partner-lang.php';
}

$partnerProfile = requireGrowthPartner();
$partnerId      = (int)$partnerProfile['id'];

// Overall commission stats
$stats = db()->prepare("SELECT
    COUNT(*) AS total_orders,
    SUM(commission_amount_xaf) AS total_earned,
    SUM(CASE WHEN status='paid' THEN commission_amount_xaf ELSE 0 END) AS total_paid,
    SUM(CASE WHEN status='pending' THEN commission_amount_xaf ELSE 0 END) AS pending,
    SUM(CASE WHEN order_type='subscription' THEN commission_amount_xaf ELSE 0 END) AS from_subs,
    SUM(CASE WHEN order_type='promotion' THEN commission_amount_xaf ELSE 0 END) AS from_promos
    FROM partner_commissions
    WHERE user_id=? AND reversed_at IS NULL");
$stats->execute([$partnerId]);
$stats = $stats->fetch();

// Commission rows with order details
$filter = $_GET['filter'] ?? 'all';
$page   = max(1, (int)($_GET['p'] ?? 1));
$perPage = 30;
$offset  = ($page - 1) * $perPage;

$whereFilter = "pc.user_id=$partnerId AND pc.reversed_at IS NULL";
if ($filter === 'subscription') $whereFilter .= " AND pc.order_type='subscription'";
if ($filter === 'promotion')    $whereFilter .= " AND pc.order_type='promotion'";
if ($filter === 'pending')      $whereFilter .= " AND pc.status='pending'";
if ($filter === 'paid')         $whereFilter .= " AND pc.status='paid'";

$total = db()->query("SELECT COUNT(*) FROM partner_commissions pc WHERE $whereFilter")->fetchColumn();

$commissions = db()->query("SELECT pc.*,
    l.title AS listing_title,
    mo.ref AS order_ref, mo.created_at AS order_date,
    CASE WHEN pc.order_type='subscription'
         THEN sp.name_en
         ELSE pp.name_en
    END AS product_name,
    u.name AS business_name
    FROM partner_commissions pc
    LEFT JOIN monetisation_orders mo ON mo.id = pc.order_id
    LEFT JOIN listings l ON l.id = pc.listing_id
    LEFT JOIN business_subscriptions bs ON bs.id = mo.linked_id AND mo.product_type='subscription'
    LEFT JOIN subscription_plans sp ON sp.id = bs.plan_id
    LEFT JOIN promotion_orders promo ON promo.id = mo.linked_id AND mo.product_type='promotion'
    LEFT JOIN promotion_products pp ON pp.id = promo.product_id
    LEFT JOIN users u ON u.id = mo.user_id
    WHERE $whereFilter
    ORDER BY pc.created_at DESC
    LIMIT $perPage OFFSET $offset")->fetchAll();

$pageTitle = t('Earnings — Partner Portal', 'Revenus — Portail Partenaire');
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.stat-tile{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:1.1rem;text-align:center;}
.commission-badge{display:inline-block;padding:.2rem .6rem;border-radius:6px;font-size:.72rem;font-weight:600;}
</style>

<div class="page-header">
  <div class="container">
    <nav class="breadcrumb">
      <a href="<?= SITE_URL ?>/partner"><?= t('Partner Portal','Portail Partenaire') ?></a> ›
      <span><?= t('Earnings','Revenus') ?></span>
    </nav>
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.6rem,2.5vw,2.2rem);">
      💰 <?= t('Commission Earnings', 'Revenus de commission') ?>
    </h1>
    <p style="color:var(--muted);"><?= t('Commissions from subscription and promotion referrals', 'Commissions sur les abonnements et promotions référés') ?></p>
  </div>
</div>

<section class="page-section">
<div class="container" style="max-width:950px;">

<!-- Stats -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:1rem;margin-bottom:2rem;">
  <?php $tiles = [
    [t('Total Earned','Total gagné'), number_format($stats['total_earned'] ?: 0) . ' XAF', 'var(--accent)'],
    [t('Paid Out','Payé'), number_format($stats['total_paid'] ?: 0) . ' XAF', '#00A878'],
    [t('Pending','En attente'), number_format($stats['pending'] ?: 0) . ' XAF', '#f59e0b'],
    [t('From Subscriptions','Abonnements'), number_format($stats['from_subs'] ?: 0) . ' XAF', '#3b82f6'],
    [t('From Promotions','Promotions'), number_format($stats['from_promos'] ?: 0) . ' XAF', '#7c3aed'],
    [t('Total Orders','Commandes'), $stats['total_orders'] ?: 0, 'var(--muted)'],
  ];
  foreach ($tiles as [$label, $val, $color]): ?>
  <div class="stat-tile">
    <div style="font-size:1.3rem;font-weight:900;font-family:'Fraunces',serif;color:<?= $color ?>;"><?= $val ?></div>
    <div style="font-size:.75rem;color:var(--muted);margin-top:.2rem;"><?= $label ?></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Your rate -->
<div class="listing-widget" style="margin-bottom:1.5rem;background:rgba(0,168,120,.05);border-color:rgba(0,168,120,.2);">
  <p style="margin:0;font-size:.875rem;color:var(--muted);">
    <?= t('Your commission rate:', 'Votre taux de commission :') ?>
    <strong style="color:var(--accent);font-size:1rem;"><?= number_format($partnerProfile['commission_rate'] ?? 25, 1) ?>%</strong>
    &nbsp;·&nbsp;<?= t('Tier:', 'Niveau :') ?> <strong><?= e($partnerProfile['tier_name'] ?? 'Solo') ?></strong>
  </p>
</div>

<!-- Filter -->
<div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.25rem;">
  <?php foreach ([
    'all'          => t('All','Tous'),
    'subscription' => t('Subscriptions','Abonnements'),
    'promotion'    => t('Promotions','Promotions'),
    'pending'      => t('Pending','En attente'),
    'paid'         => t('Paid','Payé'),
  ] as $f => $label): ?>
  <a href="?filter=<?= $f ?>" class="btn btn-sm <?= $filter===$f ? 'btn-primary' : 'btn-secondary' ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>

<!-- Commission list -->
<?php if (!$commissions): ?>
<div style="text-align:center;padding:3rem;background:var(--card);border:1px solid var(--border);border-radius:12px;">
  <div style="font-size:2rem;margin-bottom:.5rem;">💰</div>
  <p style="color:var(--muted);"><?= t('No commissions yet. Share your referral link to earn!', 'Aucune commission encore. Partagez votre lien de parrainage pour gagner !') ?></p>
  <a href="<?= SITE_URL ?>/partner/referrals" class="btn btn-primary" style="margin-top:1rem;"><?= t('My Referral Links', 'Mes liens de parrainage') ?></a>
</div>
<?php else: ?>
<div class="listing-widget" style="padding:0;overflow:hidden;">
  <table style="width:100%;border-collapse:collapse;font-size:.83rem;">
    <thead>
      <tr style="background:rgba(255,255,255,.03);">
        <th style="padding:.75rem 1rem;text-align:left;color:var(--muted);font-weight:600;"><?= t('Order Ref','Réf') ?></th>
        <th style="padding:.75rem 1rem;text-align:left;color:var(--muted);font-weight:600;"><?= t('Type','Type') ?></th>
        <th style="padding:.75rem 1rem;text-align:left;color:var(--muted);font-weight:600;"><?= t('Product','Produit') ?></th>
        <th style="padding:.75rem 1rem;text-align:left;color:var(--muted);font-weight:600;"><?= t('Business','Entreprise') ?></th>
        <th style="padding:.75rem 1rem;text-align:right;color:var(--muted);font-weight:600;"><?= t('Order','Commande') ?></th>
        <th style="padding:.75rem 1rem;text-align:right;color:var(--muted);font-weight:600;"><?= t('Commission','Commission') ?></th>
        <th style="padding:.75rem 1rem;text-align:left;color:var(--muted);font-weight:600;"><?= t('Status','Statut') ?></th>
        <th style="padding:.75rem 1rem;text-align:left;color:var(--muted);font-weight:600;"><?= t('Date','Date') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($commissions as $c):
      $typeColors = ['subscription'=>['bg'=>'rgba(59,130,246,.12)','c'=>'#3b82f6'],'promotion'=>['bg'=>'rgba(109,40,217,.12)','c'=>'#7c3aed']];
      $tc = $typeColors[$c['order_type']] ?? ['bg'=>'rgba(107,114,128,.12)','c'=>'#6b7280'];
      $statusColors = ['pending'=>['bg'=>'rgba(245,158,11,.12)','c'=>'#f59e0b'],'approved'=>['bg'=>'rgba(0,168,120,.12)','c'=>'#00A878'],'paid'=>['bg'=>'rgba(0,168,120,.12)','c'=>'#00A878'],'cancelled'=>['bg'=>'rgba(239,68,68,.12)','c'=>'#ef4444']];
      $sc = $statusColors[$c['status']] ?? ['bg'=>'rgba(107,114,128,.12)','c'=>'#6b7280'];
    ?>
    <tr style="border-top:1px solid var(--border);">
      <td style="padding:.65rem 1rem;"><code style="font-size:.77rem;"><?= e($c['order_ref'] ?? '—') ?></code></td>
      <td style="padding:.65rem 1rem;">
        <span class="commission-badge" style="background:<?= $tc['bg'] ?>;color:<?= $tc['c'] ?>;"><?= ucfirst($c['order_type']) ?></span>
      </td>
      <td style="padding:.65rem 1rem;max-width:130px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= e($c['product_name'] ?? '—') ?></td>
      <td style="padding:.65rem 1rem;max-width:120px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= e($c['business_name'] ?? '—') ?></td>
      <td style="padding:.65rem 1rem;text-align:right;white-space:nowrap;"><?= number_format($c['gross_amount_xaf']) ?> XAF</td>
      <td style="padding:.65rem 1rem;text-align:right;white-space:nowrap;font-weight:700;color:var(--accent);"><?= number_format($c['commission_amount_xaf']) ?> XAF</td>
      <td style="padding:.65rem 1rem;">
        <span class="commission-badge" style="background:<?= $sc['bg'] ?>;color:<?= $sc['c'] ?>;"><?= ucfirst($c['status']) ?></span>
      </td>
      <td style="padding:.65rem 1rem;color:var(--muted);white-space:nowrap;"><?= date('d M Y', strtotime($c['created_at'])) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php if ($total > $perPage): ?>
<div style="display:flex;justify-content:center;gap:.5rem;margin-top:1rem;">
  <?php $pages = ceil($total / $perPage);
  for ($i = 1; $i <= $pages; $i++): ?>
  <a href="?filter=<?= $filter ?>&p=<?= $i ?>" class="btn btn-sm <?= $i===$page ? 'btn-primary' : 'btn-secondary' ?>"><?= $i ?></a>
  <?php endfor; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<div style="display:flex;flex-wrap:wrap;gap:.75rem;margin-top:2rem;">
  <a href="<?= SITE_URL ?>/partner/payouts" class="btn btn-secondary btn-sm">💸 <?= t('My Payouts','Mes Versements') ?></a>
  <a href="<?= SITE_URL ?>/partner/referrals" class="btn btn-secondary btn-sm">🔗 <?= t('Referral Links','Liens de parrainage') ?></a>
  <a href="<?= SITE_URL ?>/partner" class="btn btn-secondary btn-sm">🏠 <?= t('Partner Portal','Portail Partenaire') ?></a>
</div>

</div><!-- /container -->
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
