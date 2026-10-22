<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/entitlement-helpers.php';
requireLogin();

$u = currentUser();

// Get all user's listings with their current subscription
$listings = db()->prepare("SELECT l.id, l.title,
    bs.id AS sub_id, bs.status AS sub_status, bs.starts_at, bs.ends_at, bs.cancelled_at,
    sp.id AS plan_id, sp.name_en, sp.name_fr, sp.slug AS plan_slug, sp.price_xaf,
    sp.billing_interval, sp.duration_days,
    sp.max_offers, sp.max_events, sp.analytics_level, sp.featured_eligible,
    sp.promotions_eligible, sp.verified_badge, sp.priority_support, sp.is_free,
    sp.highlight_color, sp.badge_label_en, sp.badge_label_fr
    FROM listings l
    LEFT JOIN business_subscriptions bs ON bs.listing_id = l.id AND bs.status='active'
    LEFT JOIN subscription_plans sp ON sp.id = bs.plan_id
    WHERE l.user_id=? AND l.status='active'
    ORDER BY l.title");
$listings->execute([$u['id']]);
$listings = $listings->fetchAll();

// Entitlements per listing (usage indicators)
$listingEnt = [];
foreach ($listings as $_l) {
    try { $listingEnt[$_l['id']] = getListingEntitlements($_l['id']); } catch (Exception $e) {}
}
unset($_l);

// Active add-on entitlements per listing
$activeAddons = [];
try {
    $aeSt = db()->prepare("
        SELECT ae.*, pa.name_en, pa.name_fr, pa.addon_type,
               ao.ref AS order_ref, ao.quantity AS units_bought
        FROM addon_entitlements ae
        JOIN addon_orders ao ON ao.id = ae.addon_order_id
        JOIN plan_addons pa ON pa.id = ao.addon_id
        WHERE ae.listing_id IN (SELECT id FROM listings WHERE user_id=?)
          AND (ae.expires_at IS NULL OR ae.expires_at > NOW())
        ORDER BY ae.addon_type, ae.activated_at DESC
    ");
    $aeSt->execute([$u['id']]);
    foreach ($aeSt->fetchAll() as $ae) {
        $activeAddons[$ae['listing_id']][] = $ae;
    }
} catch (Exception $e) {}

// Subscription history (all orders)
$history = db()->prepare("SELECT mo.ref, mo.amount_xaf, mo.payment_status, mo.created_at,
    mo.payment_confirmed_at,
    sp.name_en AS plan_name_en, sp.name_fr AS plan_name_fr,
    bs.status AS sub_status, bs.starts_at, bs.ends_at,
    l.title AS listing_title
    FROM monetisation_orders mo
    JOIN business_subscriptions bs ON bs.id = mo.linked_id AND mo.product_type='subscription'
    JOIN subscription_plans sp ON sp.id = bs.plan_id
    LEFT JOIN listings l ON l.id = mo.listing_id
    WHERE mo.user_id=?
    ORDER BY mo.created_at DESC LIMIT 30");
$history->execute([$u['id']]);
$history = $history->fetchAll();

// Available plans for upgrade prompt
$plans = db()->query("SELECT * FROM subscription_plans WHERE active=1 ORDER BY sort_order, price_xaf")->fetchAll();

$pageTitle = t('My Subscriptions — 237Biz', 'Mes Abonnements — 237Biz');
require_once __DIR__ . '/includes/header.php';
?>
<style>
.sub-listing-card{background:var(--card);border:2px solid var(--border);border-radius:14px;padding:1.4rem;transition:border-color .18s;}
.sub-listing-card.has-paid{border-color:var(--accent);}
.feature-check{color:var(--accent);font-size:.85rem;}
.feature-x{color:var(--muted);font-size:.85rem;}
</style>

<div class="page-header">
  <div class="container">
    <nav class="breadcrumb">
      <a href="<?= SITE_URL ?>/dashboard"><?= t('Dashboard', 'Tableau de bord') ?></a> ›
      <span><?= t('Subscriptions', 'Abonnements') ?></span>
    </nav>
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.6rem,2.5vw,2.2rem);">
      ⭐ <?= t('My Subscriptions', 'Mes Abonnements') ?>
    </h1>
  </div>
</div>

<section class="page-section">
<div class="container" style="max-width:900px;">

<?php if (!$listings): ?>
<div style="text-align:center;padding:3rem;background:var(--card);border:1px solid var(--border);border-radius:12px;">
  <p style="color:var(--muted);"><?= t('You have no active listings yet.', 'Vous n\'avez pas encore d\'annonces actives.') ?></p>
  <a href="<?= SITE_URL ?>/add-listing" class="btn btn-primary" style="margin-top:1rem;"><?= t('Create Listing', 'Créer une annonce') ?></a>
</div>
<?php else: ?>

<div style="display:flex;flex-direction:column;gap:1.25rem;margin-bottom:2.5rem;">
<?php foreach ($listings as $l):
  $hasPaid = $l['sub_id'] && !$l['is_free'];
  $planName = lang()==='fr' ? ($l['name_fr'] ?? 'Free') : ($l['name_en'] ?? 'Free');
  $daysLeft = null;
  if ($l['ends_at']) {
    $daysLeft = max(0, (int)ceil((strtotime($l['ends_at']) - time()) / 86400));
  }
?>
<div class="sub-listing-card <?= $hasPaid ? 'has-paid' : '' ?>">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
    <div>
      <h3 style="font-family:'Fraunces',serif;font-weight:700;margin-bottom:.25rem;"><?= e($l['title']) ?></h3>
      <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
        <?php if ($l['plan_slug']): ?>
        <span style="background:<?= e($l['highlight_color'] ?? 'var(--accent)') ?>22;color:<?= e($l['highlight_color'] ?? 'var(--accent)') ?>;font-weight:700;font-size:.8rem;padding:.2rem .65rem;border-radius:6px;">
          <?= e($l['badge_label_en'] ?? strtoupper($l['plan_slug'])) ?>
        </span>
        <?php else: ?>
        <span style="background:rgba(107,114,128,.12);color:#6b7280;font-size:.8rem;padding:.2rem .65rem;border-radius:6px;">FREE</span>
        <?php endif; ?>
        <?php if ($daysLeft !== null): ?>
        <span style="font-size:.78rem;color:<?= $daysLeft <= 7 ? '#ef4444' : 'var(--muted)' ?>;">
          <?= $daysLeft ?> <?= t('days remaining', 'jours restants') ?>
          (→ <?= date('d M Y', strtotime($l['ends_at'])) ?>)
        </span>
        <?php endif; ?>
      </div>
    </div>
    <a href="<?= SITE_URL ?>/subscribe?listing_id=<?= $l['id'] ?>" class="btn btn-primary btn-sm">
      <?= $l['sub_id'] ? t('Upgrade / Renew', 'Améliorer / Renouveler') : t('Subscribe', 'S\'abonner') ?>
    </a>
  </div>

  <?php if ($l['plan_slug'] && $l['plan_slug'] !== 'free'): ?>
  <!-- Plan features summary -->
  <div style="display:flex;gap:1.5rem;flex-wrap:wrap;margin-top:1rem;font-size:.8rem;">
    <?php $features = [
      $l['max_offers'] ? t("Up to {$l['max_offers']} offers", "Jusqu'à {$l['max_offers']} offres") : t('Unlimited offers', 'Offres illimitées'),
      $l['analytics_level'] !== 'basic' ? t(ucfirst($l['analytics_level']) . ' analytics', ucfirst($l['analytics_level']) . ' analytics') : null,
      $l['featured_eligible'] ? t('Featured eligible', 'Éligible vedette') : null,
      $l['verified_badge'] ? t('Verified badge', 'Badge vérifié') : null,
      $l['promotions_eligible'] ? t('Promotions unlocked', 'Promotions débloquées') : null,
    ];
    foreach (array_filter($features) as $feat): ?>
    <span style="color:var(--muted);">✅ <?= e($feat) ?></span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if (isset($listingEnt[$l['id']])): $ent = $listingEnt[$l['id']]; ?>
  <!-- Usage indicators -->
  <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border);">
    <p style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin:0 0 .6rem;"><?= t('Current usage','Utilisation actuelle') ?></p>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.75rem;">
      <?php
      $usageDefs = [
          ['key' => 'offers',   'label' => t('Offers','Offres'),    'icon' => '🏷️', 'link' => SITE_URL.'/offers?listing_id='.$l['id']],
          ['key' => 'events',   'label' => t('Events','Événements'),'icon' => '📅', 'link' => SITE_URL.'/events?listing_id='.$l['id']],
          ['key' => 'products', 'label' => t('Products','Produits'),'icon' => '🛍️', 'link' => SITE_URL.'/manage-products?listing_id='.$l['id']],
      ];
      foreach ($usageDefs as $ud):
          $used  = $ent['used_'  . $ud['key']];
          $total = $ent['total_' . $ud['key']];
          $pct   = ($total > 0) ? min(100, (int)round($used / $total * 100)) : 0;
          $full  = ($total > 0 && $used >= $total);
          $addonCap = $ent['addon_' . $ud['key']];
      ?>
      <a href="<?= $ud['link'] ?>" style="text-decoration:none;background:rgba(255,255,255,.02);border:1px solid <?= $full ? 'rgba(239,68,68,.3)' : 'var(--border)' ?>;border-radius:8px;padding:.6rem .75rem;display:block;">
        <div style="font-size:.73rem;color:var(--muted);margin-bottom:.2rem;"><?= $ud['icon'] ?> <?= $ud['label'] ?></div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.3rem;">
          <span style="font-size:.9rem;font-weight:700;color:<?= $full ? '#ef4444' : 'var(--fg)' ?>;"><?= $used ?></span>
          <span style="font-size:.72rem;color:var(--muted);">/ <?= $total ?: '∞' ?></span>
        </div>
        <?php if ($total > 0): ?>
        <div style="height:4px;border-radius:99px;background:rgba(255,255,255,.08);">
          <div style="width:<?= $pct ?>%;height:100%;border-radius:99px;background:<?= $full ? '#ef4444' : 'var(--accent)' ?>;transition:width .3s;"></div>
        </div>
        <?php endif; ?>
        <?php if ($addonCap > 0): ?>
        <div style="font-size:.65rem;color:var(--accent);margin-top:.2rem;">+<?= $addonCap ?> <?= t('add-on','ext.') ?></div>
        <?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>

    <?php if ($ent['addon_eligible']): ?>
    <div style="margin-top:.6rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
      <span style="font-size:.73rem;color:var(--muted);"><?= t('Need more capacity?','Besoin de plus de capacité ?') ?></span>
      <a href="<?= SITE_URL ?>/partner/buy-addon?listing_id=<?= $l['id'] ?>" class="btn btn-sm btn-outline" style="font-size:.75rem;">
        🛒 <?= t('Buy Add-ons','Acheter des extensions') ?>
      </a>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($activeAddons[$l['id']])): ?>
  <!-- Active add-ons -->
  <div style="margin-top:.75rem;padding-top:.75rem;border-top:1px solid var(--border);">
    <p style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin:0 0 .5rem;"><?= t('Active add-ons','Extensions actives') ?></p>
    <div style="display:flex;flex-direction:column;gap:.35rem;">
      <?php foreach ($activeAddons[$l['id']] as $ae):
          $addonName = $isFr ? ($ae['name_fr'] ?: $ae['name_en']) : $ae['name_en'];
          $expiresStr = $ae['expires_at']
              ? t('Expires', 'Expire le') . ' ' . date('d M Y', strtotime($ae['expires_at']))
              : t('Never expires', 'N\'expire jamais');
          $daysLeft2 = $ae['expires_at'] ? max(0,(int)ceil((strtotime($ae['expires_at'])-time())/86400)) : null;
      ?>
      <div style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;font-size:.78rem;background:rgba(0,168,120,.04);border:1px solid rgba(0,168,120,.12);border-radius:6px;padding:.35rem .65rem;">
        <span>✅ <strong><?= e($addonName) ?></strong> <span style="color:var(--muted);">(+<?= (int)$ae['capacity'] ?> <?= t($ae['addon_type'],$ae['addon_type']) ?>)</span></span>
        <span style="color:<?= ($daysLeft2 !== null && $daysLeft2 <= 7) ? '#ef4444' : 'var(--muted)' ?>;white-space:nowrap;">
          <?= $expiresStr ?>
          <?php if ($daysLeft2 !== null): ?> · <?= $daysLeft2 ?> <?= t('days','j') ?><?php endif; ?>
        </span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endforeach; ?>
</div>

<!-- Plans comparison -->
<h3 style="font-family:'Fraunces',serif;margin-bottom:1rem;"><?= t('Available Plans', 'Forfaits disponibles') ?></h3>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem;margin-bottom:2.5rem;">
  <?php foreach ($plans as $plan):
    $planName = lang()==='fr' ? $plan['name_fr'] : $plan['name_en'];
    $planDesc = lang()==='fr' ? $plan['description_fr'] : $plan['description_en'];
  ?>
  <div style="background:var(--card);border:2px solid <?= $plan['highlight_color'] ? e($plan['highlight_color']) . '40' : 'var(--border)' ?>;border-radius:12px;padding:1.2rem;position:relative;">
    <?php if ($plan['badge_label_en']): ?>
    <div style="position:absolute;top:-10px;left:50%;transform:translateX(-50%);background:<?= e($plan['highlight_color'] ?? 'var(--accent)') ?>;color:#fff;font-size:.7rem;font-weight:700;padding:.2rem .75rem;border-radius:99px;white-space:nowrap;">
      <?= e(lang()==='fr' ? ($plan['badge_label_fr'] ?: $plan['badge_label_en']) : $plan['badge_label_en']) ?>
    </div>
    <?php endif; ?>
    <div style="font-family:'Fraunces',serif;font-weight:700;font-size:1rem;margin-bottom:.3rem;"><?= e($planName) ?></div>
    <div style="font-size:1.6rem;font-weight:900;color:<?= e($plan['highlight_color'] ?? 'var(--accent)') ?>;">
      <?= $plan['is_free'] ? t('Free', 'Gratuit') : number_format($plan['price_xaf']) . ' XAF' ?>
    </div>
    <div style="font-size:.75rem;color:var(--muted);margin-bottom:.75rem;">
      <?= $plan['is_free'] ? '' : ('/' . t($plan['billing_interval'], $plan['billing_interval'])) ?>
    </div>
    <?php if ($planDesc): ?>
    <p style="font-size:.78rem;color:var(--muted);margin-bottom:.75rem;line-height:1.5;"><?= e($planDesc) ?></p>
    <?php endif; ?>
    <?php if ($listings): ?>
    <a href="<?= SITE_URL ?>/subscribe?listing_id=<?= $listings[0]['id'] ?>" class="btn btn-sm <?= $plan['highlight_color'] ? 'btn-primary' : 'btn-secondary' ?>" style="width:100%;text-align:center;">
      <?= t('Select', 'Choisir') ?>
    </a>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<?php endif; ?>

<!-- Subscription history -->
<?php if ($history): ?>
<h3 style="font-family:'Fraunces',serif;margin-bottom:1rem;"><?= t('Subscription History', 'Historique des abonnements') ?></h3>
<div class="listing-widget" style="padding:0;overflow:hidden;">
  <table style="width:100%;border-collapse:collapse;font-size:.83rem;">
    <thead>
      <tr style="background:rgba(255,255,255,.03);">
        <th style="padding:.75rem 1rem;text-align:left;color:var(--muted);font-weight:600;"><?= t('Ref','Réf') ?></th>
        <th style="padding:.75rem 1rem;text-align:left;color:var(--muted);font-weight:600;"><?= t('Listing','Annonce') ?></th>
        <th style="padding:.75rem 1rem;text-align:left;color:var(--muted);font-weight:600;"><?= t('Plan','Forfait') ?></th>
        <th style="padding:.75rem 1rem;text-align:left;color:var(--muted);font-weight:600;"><?= t('Amount','Montant') ?></th>
        <th style="padding:.75rem 1rem;text-align:left;color:var(--muted);font-weight:600;"><?= t('Status','Statut') ?></th>
        <th style="padding:.75rem 1rem;text-align:left;color:var(--muted);font-weight:600;"><?= t('Date','Date') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($history as $h):
      $statusColors = ['pending'=>'#f59e0b','paid'=>'#00A878','failed'=>'#ef4444'];
      $sc = $statusColors[$h['payment_status']] ?? '#6b7280';
      $planName = lang()==='fr' ? ($h['plan_name_fr'] ?: $h['plan_name_en']) : $h['plan_name_en'];
    ?>
    <tr style="border-top:1px solid var(--border);">
      <td style="padding:.7rem 1rem;"><code style="font-size:.77rem;"><?= e($h['ref']) ?></code></td>
      <td style="padding:.7rem 1rem;max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= e($h['listing_title']) ?></td>
      <td style="padding:.7rem 1rem;"><?= e($planName) ?></td>
      <td style="padding:.7rem 1rem;white-space:nowrap;"><?= number_format($h['amount_xaf']) ?> XAF</td>
      <td style="padding:.7rem 1rem;">
        <span style="font-size:.75rem;font-weight:600;color:<?= $sc ?>;"><?= ucfirst($h['payment_status']) ?></span>
      </td>
      <td style="padding:.7rem 1rem;color:var(--muted);white-space:nowrap;"><?= date('d M Y', strtotime($h['created_at'])) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

</div><!-- /container -->
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
