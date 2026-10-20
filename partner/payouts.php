<?php
/**
 * partner/payouts.php — Phase 3 Partner Payout History
 *
 * Shows a partner their payout batches and which commissions
 * are included in each batch.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';
if (file_exists(__DIR__ . '/../includes/partner-lang.php')) {
    require_once __DIR__ . '/../includes/partner-lang.php';
}

$partnerProfile = requireGrowthPartner();
$partnerId      = (int)$partnerProfile['id'];

// Summary stats
$stats = db()->prepare("SELECT
    SUM(CASE WHEN status='paid' THEN total_xaf ELSE 0 END) AS total_received,
    SUM(CASE WHEN status='pending' THEN total_xaf ELSE 0 END) AS pending_batched,
    COUNT(*) AS total_batches,
    COUNT(CASE WHEN status='paid' THEN 1 END) AS paid_batches
    FROM partner_payout_batches WHERE partner_id=?");
$stats->execute([$partnerId]);
$stats = $stats->fetch();

// Unpaid/pending commission total (approved but not yet in a batch)
$unbatched = db()->prepare("SELECT SUM(commission_amount_xaf) AS total
    FROM partner_commissions
    WHERE user_id=? AND status='approved' AND payout_batch_id IS NULL AND reversed_at IS NULL");
$unbatched->execute([$partnerId]);
$unbatched = $unbatched->fetchColumn() ?: 0;

// Batches list
$batches = db()->query("SELECT ppb.*
    FROM partner_payout_batches ppb
    WHERE ppb.partner_id=$partnerId
    ORDER BY ppb.created_at DESC")->fetchAll();

// If a batch is selected, show its commissions
$viewBatch = (int)($_GET['batch'] ?? 0);
$batchDetail = null;
$batchCommissions = [];
if ($viewBatch) {
    $batchDetail = db()->prepare("SELECT * FROM partner_payout_batches WHERE id=? AND partner_id=?");
    $batchDetail->execute([$viewBatch, $partnerId]);
    $batchDetail = $batchDetail->fetch();

    if ($batchDetail) {
        $batchCommissions = db()->query("SELECT pc.*,
            mo.ref AS order_ref,
            l.title AS listing_title,
            CASE WHEN pc.order_type='subscription' THEN sp.name_en ELSE pp.name_en END AS product_name
            FROM partner_commissions pc
            LEFT JOIN monetisation_orders mo ON mo.id = pc.order_id
            LEFT JOIN listings l ON l.id = pc.listing_id
            LEFT JOIN business_subscriptions bs ON bs.id = mo.linked_id AND mo.product_type='subscription'
            LEFT JOIN subscription_plans sp ON sp.id = bs.plan_id
            LEFT JOIN promotion_orders promo ON promo.id = mo.linked_id AND mo.product_type='promotion'
            LEFT JOIN promotion_products pp ON pp.id = promo.product_id
            WHERE pc.payout_batch_id=$viewBatch
            ORDER BY pc.created_at DESC")->fetchAll();
    }
}

$pageTitle = t('Payouts — Partner Portal', 'Paiements — Portail Partenaire');
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.payout-stat{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:1.1rem;text-align:center;}
.batch-row{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:1rem 1.2rem;display:grid;grid-template-columns:1fr auto;gap:.75rem;align-items:center;transition:border-color .15s;}
.batch-row:hover{border-color:var(--accent);}
.badge{display:inline-block;padding:.2rem .6rem;border-radius:6px;font-size:.72rem;font-weight:600;}
</style>

<div class="page-header">
  <div class="container">
    <nav class="breadcrumb">
      <a href="<?= SITE_URL ?>/partner"><?= t('Partner Portal','Portail Partenaire') ?></a> ›
      <span><?= t('Payouts','Paiements') ?></span>
    </nav>
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.6rem,2.5vw,2.2rem);">
      💳 <?= t('Payout History', 'Historique des paiements') ?>
    </h1>
    <p style="color:var(--muted);"><?= t('Your commission batches and payment records', 'Vos lots de commissions et historique des paiements') ?></p>
  </div>
</div>

<section class="page-section">
<div class="container" style="max-width:900px;">

<?php if ($batchDetail): ?>
<!-- ── BATCH DETAIL VIEW ────────────────────────────── -->
<div style="margin-bottom:1.25rem;">
  <a href="<?= SITE_URL ?>/partner/payouts" class="btn btn-secondary btn-sm">← <?= t('Back to Payouts','Retour aux paiements') ?></a>
</div>

<div style="background:var(--card);border:1px solid var(--border);border-radius:12px;padding:1.4rem;margin-bottom:1.5rem;">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.75rem;">
    <div>
      <div style="font-size:.8rem;color:var(--muted);margin-bottom:.25rem;"><?= t('Batch Reference','Référence lot') ?></div>
      <code style="font-size:1rem;"><?= e($batchDetail['ref']) ?></code>
    </div>
    <?php
    $bsc = ['pending'=>['bg'=>'rgba(245,158,11,.12)','c'=>'#f59e0b'],'paid'=>['bg'=>'rgba(0,168,120,.12)','c'=>'#00A878']];
    $bc = $bsc[$batchDetail['status']] ?? $bsc['pending'];
    ?>
    <span class="badge" style="background:<?= $bc['bg'] ?>;color:<?= $bc['c'] ?>;font-size:.85rem;padding:.4rem 1rem;">
      <?= ucfirst($batchDetail['status']) ?>
    </span>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;margin-top:1.2rem;">
    <div>
      <div style="font-size:.75rem;color:var(--muted);"><?= t('Total Amount','Montant total') ?></div>
      <div style="font-size:1.5rem;font-weight:900;font-family:'Fraunces',serif;color:var(--accent);"><?= number_format($batchDetail['total_xaf']) ?> XAF</div>
    </div>
    <div>
      <div style="font-size:.75rem;color:var(--muted);"><?= t('Commissions','Commissions') ?></div>
      <div style="font-size:1.2rem;font-weight:700;"><?= $batchDetail['commission_count'] ?></div>
    </div>
    <div>
      <div style="font-size:.75rem;color:var(--muted);"><?= t('Created','Créé le') ?></div>
      <div style="font-size:.9rem;"><?= date('d M Y', strtotime($batchDetail['created_at'])) ?></div>
    </div>
    <?php if ($batchDetail['paid_at']): ?>
    <div>
      <div style="font-size:.75rem;color:var(--muted);"><?= t('Paid On','Payé le') ?></div>
      <div style="font-size:.9rem;color:#00A878;"><?= date('d M Y', strtotime($batchDetail['paid_at'])) ?></div>
    </div>
    <?php endif; ?>
    <?php if ($batchDetail['paid_ref']): ?>
    <div>
      <div style="font-size:.75rem;color:var(--muted);"><?= t('Transfer Ref','Réf virement') ?></div>
      <div style="font-size:.85rem;font-weight:600;"><?= e($batchDetail['paid_ref']) ?></div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($batchCommissions): ?>
<h3 style="font-family:'Fraunces',serif;margin-bottom:1rem;"><?= t('Included Commissions','Commissions incluses') ?></h3>
<div class="listing-widget" style="padding:0;overflow:hidden;">
  <table style="width:100%;border-collapse:collapse;font-size:.82rem;">
    <thead>
      <tr style="background:rgba(255,255,255,.03);">
        <th style="padding:.75rem 1rem;text-align:left;color:var(--muted);font-weight:600;"><?= t('Order Ref','Réf') ?></th>
        <th style="padding:.75rem 1rem;text-align:left;color:var(--muted);font-weight:600;"><?= t('Type','Type') ?></th>
        <th style="padding:.75rem 1rem;text-align:left;color:var(--muted);font-weight:600;"><?= t('Product','Produit') ?></th>
        <th style="padding:.75rem 1rem;text-align:left;color:var(--muted);font-weight:600;"><?= t('Listing','Annonce') ?></th>
        <th style="padding:.75rem 1rem;text-align:right;color:var(--muted);font-weight:600;"><?= t('Order Amt','Commande') ?></th>
        <th style="padding:.75rem 1rem;text-align:right;color:var(--muted);font-weight:600;"><?= t('Commission','Commission') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($batchCommissions as $c):
      $typeColors = ['subscription'=>['bg'=>'rgba(59,130,246,.12)','c'=>'#3b82f6'],'promotion'=>['bg'=>'rgba(109,40,217,.12)','c'=>'#7c3aed']];
      $tc = $typeColors[$c['order_type']] ?? ['bg'=>'rgba(107,114,128,.12)','c'=>'#6b7280'];
    ?>
    <tr style="border-top:1px solid var(--border);">
      <td style="padding:.65rem 1rem;"><code style="font-size:.75rem;"><?= e($c['order_ref'] ?? '—') ?></code></td>
      <td style="padding:.65rem 1rem;">
        <span class="badge" style="background:<?= $tc['bg'] ?>;color:<?= $tc['c'] ?>;"><?= ucfirst($c['order_type']) ?></span>
      </td>
      <td style="padding:.65rem 1rem;max-width:140px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= e($c['product_name'] ?? '—') ?></td>
      <td style="padding:.65rem 1rem;max-width:140px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= e($c['listing_title'] ?? '—') ?></td>
      <td style="padding:.65rem 1rem;text-align:right;white-space:nowrap;"><?= number_format($c['gross_amount_xaf']) ?> XAF</td>
      <td style="padding:.65rem 1rem;text-align:right;font-weight:700;color:var(--accent);white-space:nowrap;"><?= number_format($c['commission_amount_xaf']) ?> XAF</td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr style="border-top:2px solid var(--border);background:rgba(255,255,255,.02);">
        <td colspan="5" style="padding:.75rem 1rem;font-weight:700;text-align:right;"><?= t('Batch Total','Total lot') ?></td>
        <td style="padding:.75rem 1rem;text-align:right;font-weight:900;font-family:'Fraunces',serif;color:var(--accent);font-size:1.1rem;"><?= number_format($batchDetail['total_xaf']) ?> XAF</td>
      </tr>
    </tfoot>
  </table>
</div>
<?php endif; ?>

<?php else: ?>
<!-- ── BATCH LIST VIEW ─────────────────────────────── -->

<!-- Stats -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:1rem;margin-bottom:2rem;">
  <?php foreach ([
    [t('Total Received','Total reçu'), number_format($stats['total_received'] ?: 0) . ' XAF', '#00A878'],
    [t('Pending (batched)','En attente (en lot)'), number_format($stats['pending_batched'] ?: 0) . ' XAF', '#f59e0b'],
    [t('Approved (unbatched)','Approuvé (sans lot)'), number_format($unbatched) . ' XAF', '#3b82f6'],
    [t('Payout Batches','Lots'), $stats['total_batches'] . ' (' . $stats['paid_batches'] . ' ' . t('paid','payés') . ')', 'var(--muted)'],
  ] as [$label, $val, $color]): ?>
  <div class="payout-stat">
    <div style="font-size:1.25rem;font-weight:900;font-family:'Fraunces',serif;color:<?= $color ?>;"><?= $val ?></div>
    <div style="font-size:.75rem;color:var(--muted);margin-top:.2rem;"><?= $label ?></div>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($unbatched > 0): ?>
<!-- Approved-but-not-yet-batched notice -->
<div style="background:rgba(59,130,246,.06);border:1px solid rgba(59,130,246,.2);border-radius:10px;padding:1rem 1.2rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
  <div style="flex:1;min-width:200px;">
    <div style="font-weight:700;margin-bottom:.2rem;">ℹ <?= t('Approved Commissions Awaiting Batch', 'Commissions approuvées en attente de lot') ?></div>
    <div style="font-size:.83rem;color:var(--muted);"><?= t('You have','Vous avez') ?> <strong><?= number_format($unbatched) ?> XAF</strong> <?= t('in approved commissions not yet included in a payout batch.','de commissions approuvées pas encore incluses dans un lot de paiement.') ?></div>
  </div>
  <a href="<?= SITE_URL ?>/partner/earnings" class="btn btn-secondary btn-sm"><?= t('View Earnings','Voir les revenus') ?></a>
</div>
<?php endif; ?>

<?php if (!$batches): ?>
<div style="text-align:center;padding:3rem;background:var(--card);border:1px solid var(--border);border-radius:12px;">
  <div style="font-size:2.5rem;margin-bottom:.75rem;">💳</div>
  <h3 style="font-family:'Fraunces',serif;"><?= t('No payouts yet', 'Aucun paiement encore') ?></h3>
  <p style="color:var(--muted);margin-bottom:1rem;"><?= t('Earn commissions through referrals and your admin will create payout batches.', 'Gagnez des commissions via des parrainages et votre admin créera des lots de paiement.') ?></p>
  <a href="<?= SITE_URL ?>/partner/earnings" class="btn btn-primary"><?= t('View Earnings','Voir les revenus') ?></a>
</div>
<?php else: ?>
<h3 style="font-family:'Fraunces',serif;margin-bottom:1rem;"><?= t('Payout Batches','Lots de paiement') ?></h3>
<div style="display:flex;flex-direction:column;gap:.75rem;">
  <?php foreach ($batches as $b):
    $bsc = ['pending'=>['bg'=>'rgba(245,158,11,.12)','c'=>'#f59e0b','label'=>t('Pending','En attente')],'paid'=>['bg'=>'rgba(0,168,120,.12)','c'=>'#00A878','label'=>t('Paid','Payé')],'cancelled'=>['bg'=>'rgba(239,68,68,.12)','c'=>'#ef4444','label'=>t('Cancelled','Annulé')]];
    $bc = $bsc[$b['status']] ?? $bsc['pending'];
  ?>
  <div class="batch-row">
    <div>
      <div style="display:flex;align-items:center;gap:.65rem;flex-wrap:wrap;margin-bottom:.4rem;">
        <code style="font-size:.83rem;font-weight:600;"><?= e($b['ref']) ?></code>
        <span class="badge" style="background:<?= $bc['bg'] ?>;color:<?= $bc['c'] ?>;"><?= $bc['label'] ?></span>
        <?php if ($b['status'] === 'paid' && $b['paid_ref']): ?>
        <span style="font-size:.75rem;color:var(--muted);">Ref: <?= e($b['paid_ref']) ?></span>
        <?php endif; ?>
      </div>
      <div style="font-size:.82rem;color:var(--muted);display:flex;gap:1.25rem;flex-wrap:wrap;">
        <span>
          <strong style="color:var(--accent);font-family:'Fraunces',serif;"><?= number_format($b['total_xaf']) ?> XAF</strong>
        </span>
        <span><?= $b['commission_count'] ?> <?= t('commissions','commissions') ?></span>
        <span><?= t('Created','Créé') ?> <?= date('d M Y', strtotime($b['created_at'])) ?></span>
        <?php if ($b['paid_at']): ?>
        <span style="color:#00A878;">✓ <?= t('Paid','Payé') ?> <?= date('d M Y', strtotime($b['paid_at'])) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <a href="?batch=<?= $b['id'] ?>" class="btn btn-secondary btn-sm"><?= t('Details','Détails') ?></a>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; /* end list vs detail */ ?>

<!-- Nav links -->
<div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border);">
  <a href="<?= SITE_URL ?>/partner/earnings" class="btn btn-secondary btn-sm">💰 <?= t('View Earnings','Voir les revenus') ?></a>
  <a href="<?= SITE_URL ?>/partner/referrals" class="btn btn-secondary btn-sm">🔗 <?= t('Referral Links','Liens de parrainage') ?></a>
  <a href="<?= SITE_URL ?>/partner" class="btn btn-secondary btn-sm">🏠 <?= t('Partner Portal','Portail Partenaire') ?></a>
</div>

</div><!-- /container -->
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
