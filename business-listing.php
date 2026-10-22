<?php
require_once __DIR__ . '/includes/config.php';

// ── Plans from subscription_plans (replaces legacy listing_packages) ──────
$plans = db()->query(
    "SELECT * FROM subscription_plans WHERE active=1 ORDER BY sort_order ASC"
)->fetchAll();

$freePlan    = null;
$growthPlan  = null;
$premiumPlan = null;
foreach ($plans as $p) {
    if ($p['slug'] === 'free')    $freePlan    = $p;
    if ($p['slug'] === 'growth')  $growthPlan  = $p;
    if ($p['slug'] === 'premium') $premiumPlan = $p;
}

// ── Site settings (MoMo numbers etc.) ────────────────────────────────────
$settings = [];
try {
    $rows = db()->query("SELECT `key`, `value` FROM site_settings")->fetchAll();
    foreach ($rows as $r) $settings[$r['key']] = $r['value'];
} catch (Exception $e) {}
$mtnMomo   = $settings['mtn_momo_number']    ?? '674073852';
$orangeNum = $settings['orange_money_number'] ?? '';
$payName   = $settings['payment_name']        ?? '237Biz / MS IT Solutions';

$mtnFormatted = preg_replace('/(\d{3})(\d{3})(\d{3})/', '$1 $2 $3', $mtnMomo);

// ── Helper: duration label ────────────────────────────────────────────────
function durationLabel(int $days): string {
    if ($days <= 0)   return t('Unlimited','Illimité');
    if ($days >= 330) return round($days / 30) . ' ' . t('months','mois');
    if ($days >= 25)  return round($days / 30) . ' ' . t('month','mois');
    return $days . ' ' . t('days','jours');
}

// ── Helpers ───────────────────────────────────────────────────────────────
function planName($p): string {
    global $isFr;
    return $isFr ? ($p['name_fr'] ?? $p['name_en']) : ($p['name_en'] ?? '');
}
function planPrice($p): string {
    $xaf = (int)($p['price_xaf'] ?? 0);
    return $xaf > 0 ? number_format($xaf) . ' XAF' : t('Free','Gratuit');
}
function planOffers($p): string {
    $v = (int)($p['total_offers_display'] ?? $p['max_offers'] ?? 0);
    // addon bump shown on page by toggling — keep simple
    return $v <= 0 ? '—' : (string)$v;
}

$pageTitle = t('Subscription Plans — 237Biz','Forfaits d\'Abonnement — 237Biz');
$pageDesc  = t('Choose a subscription plan for your 237Biz business listing. Free, Growth and Premium tiers available.','Choisissez un forfait pour votre annonce sur 237Biz. Gratuit, Croissance et Premium.');

require_once __DIR__ . '/includes/header.php';
?>

<!-- HERO ─────────────────────────────────────────────────────────────────── -->
<section style="padding:7rem 5vw 4rem;background:radial-gradient(ellipse at top,rgba(245,200,66,0.08) 0%,transparent 60%);border-bottom:1px solid var(--border);">
  <div class="container" style="text-align:center;max-width:760px;margin:0 auto;">
    <span class="section-label">⭐ <?= t('Subscription Plans','Forfaits d\'Abonnement') ?></span>
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(2rem,4.5vw,3.2rem);line-height:1.1;margin-bottom:1.25rem;">
      <?= t('Get found first —','Soyez trouvé en premier —') ?>
      <em style="font-style:italic;color:var(--yellow);"><?= t('not just found','pas juste trouvé') ?></em>
    </h1>
    <p style="font-size:1.05rem;color:var(--muted);line-height:1.8;font-weight:300;">
      <?= t('Every business can list for free on 237Biz. Upgrade to Growth or Premium to unlock more offers, events, products, higher search placement, and a golden star badge.',
            'Chaque entreprise peut s\'inscrire gratuitement sur 237Biz. Passez à Croissance ou Premium pour débloquer plus d\'offres, d\'événements, de produits, un meilleur placement et un badge étoile dorée.') ?>
    </p>
    <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-top:28px;">
      <a href="<?= SITE_URL ?>/add-listing" style="padding:13px 28px;background:#fcd116;color:#0A1A0F;border-radius:10px;font-size:15px;font-weight:700;text-decoration:none;">⭐ <?= t('Get Premium','Obtenir Premium') ?></a>
      <a href="<?= SITE_URL ?>/add-listing" style="padding:12px 24px;border:2px solid rgba(255,255,255,0.2);color:rgba(255,255,255,0.8);border-radius:10px;font-size:15px;font-weight:600;text-decoration:none;"><?= t('List Free','S\'inscrire Gratuitement') ?></a>
    </div>
  </div>
</section>

<!-- COMPARISON TABLE ────────────────────────────────────────────────────── -->
<section class="page-section">
  <div class="container" style="max-width:980px;">
    <div style="text-align:center;margin-bottom:32px;">
      <h2 style="font-family:'Fraunces',serif;font-weight:900;font-size:1.8rem;"><?= t('What\'s included','Ce qui est inclus') ?></h2>
    </div>

    <?php
    // Build column plan array — only render columns for plans that exist in DB
    $cols = [];
    if ($freePlan)    $cols[] = ['plan' => $freePlan,    'highlight' => false];
    if ($growthPlan)  $cols[] = ['plan' => $growthPlan,  'highlight' => false];
    if ($premiumPlan) $cols[] = ['plan' => $premiumPlan, 'highlight' => true];

    $colCount = count($cols) + 1; // +1 for label column
    $gridCols = '1.6fr' . str_repeat(' 1fr', $colCount - 1);

    // Build feature rows dynamically from plan data
    // Val can be: true/false (checkmark/dash), or a string
    $tblRows = [];

    // 1. Directory listing
    $r = [t('Appears in directory search','Apparaît dans la recherche annuaire')];
    foreach ($cols as $c) $r[] = true;
    $tblRows[] = $r;

    // 2. Offers
    $r = [t('Offers / deals','Offres / promotions')];
    foreach ($cols as $c) {
        $max = (int)($c['plan']['max_offers'] ?? 0);
        $r[] = $max <= 0 ? '—' : (string)$max;
    }
    $tblRows[] = $r;

    // 3. Events
    $r = [t('Events','Événements')];
    foreach ($cols as $c) {
        $max = (int)($c['plan']['max_events'] ?? 0);
        $r[] = $max <= 0 ? '—' : (string)$max;
    }
    $tblRows[] = $r;

    // 4. Products
    $r = [t('Products & Services catalogue','Catalogue Produits & Services')];
    foreach ($cols as $c) {
        $max = (int)($c['plan']['max_products'] ?? 0);
        $r[] = $max <= 0 ? '—' : (string)$max;
    }
    $tblRows[] = $r;

    // 5. Add-on packs
    $r = [t('Capacity add-on packs','Packs d\'extension de capacité')];
    foreach ($cols as $c) $r[] = (bool)($c['plan']['addon_eligible'] ?? 0);
    $tblRows[] = $r;

    // 6. Golden star badge
    $r = [t('⭐ Premium star badge','⭐ Badge étoile Premium')];
    foreach ($cols as $c) $r[] = (bool)($c['plan']['premium_star'] ?? 0);
    $tblRows[] = $r;

    // 7. Search priority
    $r = [t('Search placement priority','Priorité de placement en recherche')];
    foreach ($cols as $c) {
        $sp = (int)($c['plan']['search_priority'] ?? 0);
        if ($sp === 0)      $r[] = t('Standard','Standard');
        elseif ($sp === 1)  $r[] = t('High','Élevée');
        else                $r[] = t('Highest','Maximale');
    }
    $tblRows[] = $r;

    // 8. Analytics
    $r = [t('Analytics dashboard','Tableau de bord analytique')];
    foreach ($cols as $c) $r[] = true;
    $tblRows[] = $r;

    // 9. Contact form
    $r = [t('Business contact form','Formulaire de contact')];
    foreach ($cols as $c) $r[] = true;
    $tblRows[] = $r;

    // 10. WhatsApp
    $r = [t('WhatsApp click-to-chat','WhatsApp cliquable')];
    foreach ($cols as $c) $r[] = true;
    $tblRows[] = $r;

    // 11. Photo gallery
    $r = [t('Photo gallery (up to 6 images)','Galerie photo (jusqu\'à 6 images)')];
    foreach ($cols as $c) $r[] = true;
    $tblRows[] = $r;

    // 12. QR code
    $r = [t('QR Code download','Téléchargement Code QR')];
    foreach ($cols as $c) $r[] = true;
    $tblRows[] = $r;
    ?>

    <div style="display:grid;grid-template-columns:<?= $gridCols ?>;border:1px solid var(--border);border-radius:16px;overflow:hidden;">

      <!-- Header row -->
      <div style="padding:20px 22px;background:rgba(255,255,255,0.02);border-bottom:1px solid var(--border);"></div>

      <?php foreach ($cols as $c):
        $plan    = $c['plan'];
        $hl      = $c['highlight'];
        $xaf     = (int)($plan['price_xaf'] ?? 0);
        $days    = (int)($plan['duration_days'] ?? 0);
        $badgeEn = $plan['badge_label_en'] ?? null;
        $badgeFr = $plan['badge_label_fr'] ?? null;
        $badge   = $isFr ? ($badgeFr ?? $badgeEn) : $badgeEn;
        $hlColor = $plan['highlight_color'] ?? '#fcd116';
      ?>
      <div style="padding:20px;text-align:center;background:<?= $hl?'rgba(245,200,66,0.06)':'rgba(255,255,255,0.02)' ?>;border-bottom:1px solid <?= $hl?'rgba(245,200,66,0.25)':'var(--border)' ?>;border-left:1px solid var(--border);position:relative;">
        <?php if ($badge): ?>
          <span style="position:absolute;top:-1px;left:50%;transform:translateX(-50%);background:<?= e($hlColor) ?>;color:#0A1A0F;font-size:0.6rem;font-weight:700;padding:3px 10px;border-radius:0 0 8px 8px;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap;">
            <?= e($badge) ?>
          </span>
        <?php endif; ?>
        <div style="font-family:'Fraunces',serif;font-weight:700;font-size:1rem;margin:<?= $badge?'10px':'0' ?> 0 6px;"><?= e(planName($plan)) ?></div>
        <div style="font-family:'Fraunces',serif;font-weight:900;font-size:1.5rem;color:<?= $xaf>0?e($hlColor):'#00A878' ?>;">
          <?= planPrice($plan) ?>
        </div>
        <div style="font-size:11px;color:rgba(255,255,255,0.4);margin-top:2px;">
          <?= $days>0 ? '/ '.durationLabel($days) : t('Forever','Pour toujours') ?>
        </div>
      </div>
      <?php endforeach; ?>

      <?php foreach ($tblRows as $ri => $row):
        $label  = $row[0];
        $vals   = array_slice($row, 1);
        $bg     = $ri % 2 === 0 ? 'rgba(255,255,255,0.015)' : 'transparent';
      ?>
      <div style="padding:13px 22px;font-size:13.5px;color:rgba(255,255,255,0.7);background:<?= $bg ?>;border-bottom:1px solid rgba(255,255,255,0.05);display:flex;align-items:center;"><?= $label ?></div>
      <?php foreach ($vals as $vi => $val):
        $hl  = $cols[$vi]['highlight'] ?? false;
        $cbg = $hl ? ($ri%2===0?'rgba(245,200,66,0.04)':'rgba(245,200,66,0.02)') : $bg;
      ?>
      <div style="padding:13px 16px;text-align:center;background:<?= $cbg ?>;border-bottom:1px solid rgba(255,255,255,0.05);border-left:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:13px;color:<?= $hl?'#fff':'rgba(255,255,255,0.6)' ?>;font-weight:<?= $hl?'500':'400' ?>;">
        <?php if (is_bool($val)): ?>
          <?= $val
              ? '<span style="color:'.($hl?'#fcd116':'#00A878').';font-size:1.1rem;font-weight:700;">✓</span>'
              : '<span style="color:rgba(255,255,255,0.2);">—</span>' ?>
        <?php else: echo e($val); endif; ?>
      </div>
      <?php endforeach; ?>
      <?php endforeach; ?>

      <!-- CTA row -->
      <div style="padding:20px;background:rgba(255,255,255,0.02);"></div>
      <?php foreach ($cols as $c):
        $hl  = $c['highlight'];
        $xaf = (int)($c['plan']['price_xaf'] ?? 0);
        $hlColor = $c['plan']['highlight_color'] ?? '#fcd116';
      ?>
      <div style="padding:20px;text-align:center;background:<?= $hl?'rgba(245,200,66,0.06)':'rgba(255,255,255,0.02)' ?>;border-left:1px solid var(--border);">
        <?php if ($xaf > 0): ?>
          <a href="<?= SITE_URL ?>/add-listing" class="btn btn-sm btn-full" style="background:<?= e($hlColor) ?>;color:#0A1A0F;font-weight:700;"><?= t('Get Started →','Commencer →') ?></a>
        <?php else: ?>
          <a href="<?= SITE_URL ?>/add-listing" class="btn btn-outline btn-sm btn-full"><?= t('List Free','S\'inscrire') ?></a>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>

    </div><!-- /grid -->
  </div>
</section>

<!-- PRICING CARDS ────────────────────────────────────────────────────────── -->
<section class="page-section" style="background:rgba(255,255,255,0.01);border-top:1px solid var(--border);">
  <div class="container">
    <div style="text-align:center;max-width:600px;margin:0 auto 36px;">
      <span class="section-label"><?= t('Pricing','Tarifs') ?></span>
      <h2 style="font-family:'Fraunces',serif;font-weight:900;font-size:1.8rem;margin-bottom:10px;"><?= t('Choose your plan','Choisissez votre forfait') ?></h2>
      <p style="color:rgba(255,255,255,0.55);font-size:14.5px;"><?= t('Pay once via MTN MoMo or Orange Money — no monthly fees, no credit card.','Payez une fois via MTN MoMo ou Orange Money — pas de frais mensuels, pas de carte bancaire.') ?></p>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;max-width:980px;margin:0 auto;">
      <?php foreach ($plans as $plan):
        $xaf      = (int)($plan['price_xaf'] ?? 0);
        $days     = (int)($plan['duration_days'] ?? 0);
        $isPaid   = $xaf > 0;
        $isTop    = $plan['slug'] === 'premium';
        $hlColor  = $plan['highlight_color'] ?? '#fcd116';
        $badgeEn  = $plan['badge_label_en'] ?? null;
        $badgeFr  = $plan['badge_label_fr'] ?? null;
        $badge    = $isFr ? ($badgeFr ?? $badgeEn) : $badgeEn;
        $descKey  = $isFr ? 'description_fr' : 'description_en';
        $desc     = $plan[$descKey] ?? ($isFr ? $plan['description_en'] : '');
        $durLabel = $days > 0 ? durationLabel($days) : t('Forever','Pour toujours');
      ?>
      <div style="background:<?= $isTop?'rgba(245,200,66,0.05)':'rgba(255,255,255,0.02)' ?>;border:2px solid <?= $isPaid?'rgba(245,200,66,0.3)':'rgba(255,255,255,0.1)' ?>;border-radius:18px;padding:28px;text-align:center;position:relative;">
        <?php if ($badge): ?>
        <span style="position:absolute;top:-11px;left:50%;transform:translateX(-50%);background:<?= e($hlColor) ?>;color:#0A1A0F;font-size:11px;font-weight:800;padding:3px 14px;border-radius:99px;white-space:nowrap;">
          <?= e($badge) ?>
        </span>
        <?php endif; ?>
        <div style="font-family:'Fraunces',serif;font-weight:900;font-size:1.1rem;color:#fff;margin-bottom:8px;margin-top:<?= $badge?'8px':'0' ?>;">
          <?= e(planName($plan)) ?>
        </div>
        <div style="font-family:'Fraunces',serif;font-weight:900;font-size:2rem;color:<?= $isPaid?e($hlColor):'#00A878' ?>;line-height:1;margin-bottom:4px;">
          <?= planPrice($plan) ?>
        </div>
        <div style="font-size:12.5px;color:rgba(255,255,255,0.45);margin-bottom:<?= $desc?'12px':'20px' ?>;">
          / <?= $durLabel ?>
        </div>
        <?php if ($desc): ?>
        <div style="font-size:12.5px;color:rgba(255,255,255,0.5);line-height:1.6;margin-bottom:20px;"><?= e($desc) ?></div>
        <?php endif; ?>
        <a href="<?= SITE_URL ?>/add-listing" style="display:block;padding:12px;background:<?= $isPaid?e($hlColor):'rgba(255,255,255,0.08)' ?>;color:<?= $isPaid?'#0A1A0F':'rgba(255,255,255,0.8)' ?>;border-radius:10px;font-size:14px;font-weight:700;text-decoration:none;border:<?= $isPaid?'none':'1px solid rgba(255,255,255,0.15)' ?>;">
          <?= $isPaid ? t('Get Started →','Commencer →') : t('List Free →','S\'inscrire →') ?>
        </a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- HOW TO PAY ───────────────────────────────────────────────────────────── -->
<section class="page-section" style="border-top:1px solid var(--border);">
  <div class="container" style="max-width:680px;">
    <div style="text-align:center;margin-bottom:32px;">
      <span class="section-label">💳 <?= t('Payment','Paiement') ?></span>
      <h2 style="font-family:'Fraunces',serif;font-weight:900;font-size:1.8rem;"><?= t('How to pay','Comment payer') ?></h2>
      <p style="color:rgba(255,255,255,0.55);font-size:14.5px;"><?= t('No online payment system needed. Send via Mobile Money and upload your screenshot.','Pas de système de paiement en ligne requis. Envoyez via Mobile Money et téléchargez votre capture d\'écran.') ?></p>
    </div>

    <div style="background:rgba(252,209,22,0.05);border:1px solid rgba(252,209,22,0.2);border-radius:16px;padding:28px;">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px;">
        <div style="background:rgba(255,255,255,0.04);border-radius:12px;padding:18px;">
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:rgba(255,255,255,0.4);margin-bottom:8px;">📱 MTN MoMo</div>
          <div style="font-size:1.2rem;font-weight:800;color:#fcd116;letter-spacing:.05em;"><?= e($mtnFormatted) ?></div>
          <div style="font-size:12px;color:rgba(255,255,255,0.45);margin-top:4px;"><?= e($payName) ?></div>
        </div>
        <?php if ($orangeNum): ?>
        <div style="background:rgba(255,255,255,0.04);border-radius:12px;padding:18px;">
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:rgba(255,255,255,0.4);margin-bottom:8px;">📱 Orange Money</div>
          <div style="font-size:1.2rem;font-weight:800;color:#ffa500;letter-spacing:.05em;"><?= e($orangeNum) ?></div>
          <div style="font-size:12px;color:rgba(255,255,255,0.45);margin-top:4px;"><?= e($payName) ?></div>
        </div>
        <?php else: ?>
        <div style="background:rgba(255,255,255,0.04);border-radius:12px;padding:18px;display:flex;align-items:center;justify-content:center;">
          <div style="text-align:center;color:rgba(255,255,255,0.3);font-size:13px;"><?= t('Orange Money<br>coming soon','Orange Money<br>bientôt disponible') ?></div>
        </div>
        <?php endif; ?>
      </div>

      <ol style="padding-left:18px;display:flex;flex-direction:column;gap:10px;margin:0;">
        <?php foreach ([
          t('Choose your plan above (Growth or Premium)','Choisissez votre forfait ci-dessus (Croissance ou Premium)'),
          t('Send the exact amount to the MTN MoMo number above','Envoyez le montant exact au numéro MTN MoMo ci-dessus'),
          t('Take a screenshot of your payment confirmation','Prenez une capture d\'écran de votre confirmation de paiement'),
          t('Submit your listing and upload the screenshot','Soumettez votre annonce et téléchargez la capture d\'écran'),
          t('Our team verifies and activates your listing within 24 hours','Notre équipe vérifie et active votre annonce dans les 24 heures'),
        ] as $step): ?>
        <li style="font-size:13.5px;color:rgba(255,255,255,0.7);line-height:1.6;"><?= $step ?></li>
        <?php endforeach; ?>
      </ol>
    </div>

    <div style="text-align:center;margin-top:28px;">
      <a href="<?= SITE_URL ?>/add-listing" style="padding:14px 36px;background:#fcd116;color:#0A1A0F;border-radius:10px;font-size:15px;font-weight:700;text-decoration:none;display:inline-block;">
        ⭐ <?= t('Get Your Business Listing','Obtenir votre Annonce Commerciale') ?>
      </a>
    </div>
  </div>
</section>

<!-- WHY UPGRADE ──────────────────────────────────────────────────────────── -->
<section class="page-section" style="background:rgba(255,255,255,0.01);border-top:1px solid var(--border);">
  <div class="container">
    <div style="text-align:center;max-width:680px;margin:0 auto 36px;">
      <span class="section-label"><?= t('Why upgrade','Pourquoi évoluer') ?></span>
      <h2 style="font-family:'Fraunces',serif;font-weight:900;font-size:1.8rem;"><?= t('More tools to win more customers','Plus d\'outils pour attirer plus de clients') ?></h2>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;">
      <?php
      $growthOffers  = (int)(($growthPlan['max_offers']   ?? 0));
      $premiumOffers = (int)(($premiumPlan['max_offers']  ?? 0));
      $growthEvents  = (int)(($growthPlan['max_events']   ?? 0));
      $premiumProds  = (int)(($premiumPlan['max_products'] ?? 0));
      $features = [
        ['📢', t('Publish more offers','Publiez plus d\'offres'),
          sprintf(t('Growth gets %d offers, Premium gets %d — showcase your deals prominently.',
                    'Croissance obtient %d offres, Premium en obtient %d — mettez vos promotions en avant.'),
                  $growthOffers, $premiumOffers)],
        ['🗓️', t('List your events','Listez vos événements'),
          sprintf(t('Growth plan includes up to %d events to keep customers in the loop.',
                    'Le forfait Croissance inclut jusqu\'à %d événements pour informer vos clients.'),
                  $growthEvents)],
        ['🛍️', t('Products & Services catalogue','Catalogue Produits & Services'),
          sprintf(t('Premium lists up to %d products — turn your listing into a full storefront.',
                    'Premium liste jusqu\'à %d produits — transformez votre annonce en vitrine complète.'),
                  $premiumProds)],
        ['⭐', t('Golden star badge','Badge étoile dorée'),
          t('Premium listings display a golden ⭐ star badge on every card and directory page.',
            'Les annonces Premium affichent un badge étoile ⭐ dorée sur chaque carte et page annuaire.')],
        ['🔝', t('Priority search placement','Placement prioritaire en recherche'),
          t('Premium and Growth listings always appear above Free listings in every search and category page.',
            'Les annonces Premium et Croissance apparaissent toujours avant les annonces gratuites.')],
        ['📦', t('Capacity add-on packs','Packs d\'extension de capacité'),
          t('Need more slots? Buy add-on packs from your dashboard to expand your offers, events or products.',
            'Besoin de plus de créneaux ? Achetez des packs d\'extension depuis votre tableau de bord.')],
      ];
      foreach ($features as [$icon,$title,$desc]): ?>
      <div style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.08);border-radius:14px;padding:22px 20px;">
        <div style="font-size:1.6rem;margin-bottom:10px;"><?= $icon ?></div>
        <h4 style="font-size:14.5px;font-weight:700;margin-bottom:6px;"><?= $title ?></h4>
        <p style="font-size:13px;color:rgba(255,255,255,0.55);line-height:1.65;margin:0;"><?= $desc ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
