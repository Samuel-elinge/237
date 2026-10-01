<?php
require_once __DIR__ . '/includes/config.php';

// ── Packages from DB ──────────────────────────────────────────────────────
$packages = db()->query("SELECT * FROM listing_packages WHERE active=1 ORDER BY sort_order")->fetchAll();

$freePkg  = null;
$paidPkgs = [];
foreach ($packages as $p) {
    if ((int)$p['price_xaf'] === 0) { if (!$freePkg) $freePkg = $p; }
    else $paidPkgs[] = $p;
}

// ── Site settings (MoMo number etc) ──────────────────────────────────────
$settings = [];
try {
    $rows = db()->query("SELECT `key`, `value` FROM site_settings")->fetchAll();
    foreach ($rows as $r) $settings[$r['key']] = $r['value'];
} catch(Exception $e) {}
$mtnMomo   = $settings['mtn_momo_number']    ?? '674073852';
$orangeNum = $settings['orange_money_number'] ?? '';
$payName   = $settings['payment_name']        ?? '237Biz / MS IT Solutions';

// Format MoMo: 674073852 → 674 073 852
$mtnFormatted = preg_replace('/(\d{3})(\d{3})(\d{3})/', '$1 $2 $3', $mtnMomo);

// Helper: days → human label
function durationLabel(int $days): string {
    if ($days <= 0)   return t('Unlimited','Illimité');
    if ($days >= 330) return round($days/30) . ' ' . t('months','mois');
    if ($days >= 25)  return round($days/30) . ' ' . t('months','mois');
    return $days . ' ' . t('days','jours');
}

// Word limits from DB
$freeWordLimit = (int)($freePkg['description_word_limit'] ?? 200);
$paidWordLimit = (int)(($paidPkgs[0]['description_word_limit'] ?? 500));

$pageTitle = t('Business Listing — Free vs Business — 237Biz','Annonce Commerciale — Gratuit vs Business — 237Biz');
$pageDesc  = t('Compare free and business listings on 237Biz Cameroon. Get more visibility, booking system, FAQ, social links and top placement.','Comparez les annonces gratuites et commerciales sur 237Biz Cameroun.');

require_once __DIR__ . '/includes/header.php';
?>

<!-- HERO -->
<section style="padding:7rem 5vw 4rem;background:radial-gradient(ellipse at top,rgba(245,200,66,0.08) 0%,transparent 60%);border-bottom:1px solid var(--border);">
  <div class="container" style="text-align:center;max-width:720px;margin:0 auto;">
    <span class="section-label">⭐ <?= t('Business Listing','Annonce Commerciale') ?></span>
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(2rem,4.5vw,3.2rem);line-height:1.1;margin-bottom:1.25rem;">
      <?= t('Get found first —','Soyez trouvé en premier —') ?>
      <em style="font-style:italic;color:var(--yellow);"><?= t('not just found','pas juste trouvé') ?></em>
    </h1>
    <p style="font-size:1.05rem;color:var(--muted);line-height:1.8;font-weight:300;">
      <?= t('Every business can list for free on 237Biz. A Business Listing gives you more room to tell your story, accept bookings, answer customer questions, link your socials, and appear above every free listing in search.',
            'Chaque entreprise peut s\'inscrire gratuitement sur 237Biz. Une Annonce Commerciale vous donne plus d\'espace, des réservations en ligne, des FAQ et un placement prioritaire.') ?>
    </p>
    <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-top:28px;">
      <a href="<?= SITE_URL ?>/add-listing" style="padding:13px 28px;background:#fcd116;color:#0A1A0F;border-radius:10px;font-size:15px;font-weight:700;text-decoration:none;">⭐ <?= t('Get Business Listing','Obtenir une Annonce Commerciale') ?></a>
      <a href="<?= SITE_URL ?>/add-listing" style="padding:12px 24px;border:2px solid rgba(255,255,255,0.2);color:rgba(255,255,255,0.8);border-radius:10px;font-size:15px;font-weight:600;text-decoration:none;"><?= t('List Free','S\'inscrire Gratuitement') ?></a>
    </div>
  </div>
</section>

<!-- COMPARISON TABLE -->
<section class="page-section">
  <div class="container" style="max-width:860px;">
    <div style="text-align:center;margin-bottom:32px;">
      <h2 style="font-family:'Fraunces',serif;font-weight:900;font-size:1.8rem;"><?= t('What\'s included','Ce qui est inclus') ?></h2>
    </div>

    <div style="display:grid;grid-template-columns:1.4fr 1fr 1fr;border:1px solid var(--border);border-radius:16px;overflow:hidden;">

      <!-- Column headers -->
      <div style="padding:20px 22px;background:rgba(255,255,255,0.02);border-bottom:1px solid var(--border);"></div>

      <div style="padding:20px;text-align:center;background:rgba(255,255,255,0.02);border-bottom:1px solid var(--border);border-left:1px solid var(--border);">
        <div style="font-family:'Fraunces',serif;font-weight:700;font-size:1rem;margin-bottom:6px;"><?= t('Free Listing','Annonce Gratuite') ?></div>
        <div style="font-family:'Fraunces',serif;font-weight:900;font-size:1.5rem;color:#00A878;"><?= t('Free','Gratuit') ?></div>
        <div style="font-size:11px;color:rgba(255,255,255,0.4);margin-top:2px;"><?= t('Forever','Pour toujours') ?></div>
      </div>

      <div style="padding:20px;text-align:center;background:rgba(245,200,66,0.06);border-bottom:1px solid rgba(245,200,66,0.25);border-left:1px solid var(--border);position:relative;">
        <span style="position:absolute;top:-1px;left:50%;transform:translateX(-50%);background:#fcd116;color:#0A1A0F;font-size:0.6rem;font-weight:700;padding:3px 10px;border-radius:0 0 8px 8px;text-transform:uppercase;letter-spacing:.04em;">
          ⭐ <?= t('Recommended','Recommandé') ?>
        </span>
        <div style="font-family:'Fraunces',serif;font-weight:700;font-size:1rem;margin:10px 0 6px;"><?= t('Business Listing','Annonce Commerciale') ?></div>
        <div style="font-family:'Fraunces',serif;font-weight:900;font-size:1.5rem;color:#fcd116;">
          <?= $paidPkgs ? t('From','À partir de') . ' ' . number_format($paidPkgs[0]['price_xaf']) . ' XAF' : t('Contact us','Contactez-nous') ?>
        </div>
        <div style="font-size:11px;color:rgba(255,255,255,0.4);margin-top:2px;">
          <?= $paidPkgs ? t('6 or 12 month plans','Forfaits 6 ou 12 mois') : '' ?>
        </div>
      </div>

      <?php
      $rows = [
        [t('Appears in directory search','Apparaît dans la recherche'),           true,  true],
        [t('Business contact form','Formulaire de contact'),                       true,  true],
        [t('WhatsApp click-to-chat','WhatsApp cliquable'),                         true,  true],
        [t('Photo gallery (up to 6 images)','Galerie photo (jusqu\'à 6 images)'), true,  true],
        [t('QR Code download','Téléchargement Code QR'),                           true,  true],
        [t('Analytics dashboard','Tableau de bord analytique'),                    true,  true],
        [t('Business overview','Aperçu de l\'entreprise'),
            $freeWordLimit . ' ' . t('words','mots'),
            $paidWordLimit . ' ' . t('words','mots')],
        [t('Appointment booking system','Système de réservation'),                 false, true],
        [t('FAQ section (up to 4 questions)','Section FAQ (jusqu\'à 4 questions)'),false, true],
        [t('TikTok, Instagram & Facebook links','Liens TikTok, Instagram & Facebook'), false, true],
        [t('Products & Services catalogue','Catalogue Produits & Services'),       false, t('Unlimited','Illimité')],
        [t('Verified badge','Badge vérifié'),                                      false, true],
        [t('Featured badge','Badge vedette'),                                      false, true],
        [t('Search placement','Placement recherche'),
            t('Standard','Standard'),
            t('Above all free listings','Au-dessus des annonces gratuites')],
      ];
      foreach ($rows as $i => [$label, $free, $paid]):
        $bg = $i % 2 === 0 ? 'rgba(255,255,255,0.015)' : 'transparent';
      ?>
      <div style="padding:13px 22px;font-size:13.5px;color:rgba(255,255,255,0.7);background:<?= $bg ?>;border-bottom:1px solid rgba(255,255,255,0.05);display:flex;align-items:center;"><?= $label ?></div>
      <div style="padding:13px 16px;text-align:center;background:<?= $bg ?>;border-bottom:1px solid rgba(255,255,255,0.05);border-left:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:13px;color:rgba(255,255,255,0.6);">
        <?php if (is_bool($free)): ?>
          <?= $free ? '<span style="color:#00A878;font-size:1.1rem;font-weight:700;">✓</span>' : '<span style="color:rgba(255,255,255,0.2);">—</span>' ?>
        <?php else: echo $free; endif; ?>
      </div>
      <div style="padding:13px 16px;text-align:center;background:<?= $i%2===0?'rgba(245,200,66,0.04)':'rgba(245,200,66,0.02)' ?>;border-bottom:1px solid rgba(255,255,255,0.05);border-left:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:13px;color:#fff;font-weight:500;">
        <?php if (is_bool($paid)): ?>
          <?= $paid ? '<span style="color:#fcd116;font-size:1.1rem;font-weight:700;">✓</span>' : '<span style="color:rgba(255,255,255,0.2);">—</span>' ?>
        <?php else: echo $paid; endif; ?>
      </div>
      <?php endforeach; ?>

      <!-- CTA row -->
      <div style="padding:20px;background:rgba(255,255,255,0.02);"></div>
      <div style="padding:20px;text-align:center;background:rgba(255,255,255,0.02);border-left:1px solid var(--border);">
        <a href="<?= SITE_URL ?>/add-listing" class="btn btn-outline btn-sm btn-full"><?= t('List Free','S\'inscrire') ?></a>
      </div>
      <div style="padding:20px;text-align:center;background:rgba(245,200,66,0.06);border-left:1px solid var(--border);">
        <a href="<?= SITE_URL ?>/add-listing" class="btn btn-sm btn-full" style="background:#fcd116;color:#0A1A0F;font-weight:700;"><?= t('Get Business Listing →','Obtenir l\'Annonce →') ?></a>
      </div>
    </div>
  </div>
</section>

<!-- PRICING CARDS -->
<section class="page-section" style="background:rgba(255,255,255,0.01);border-top:1px solid var(--border);">
  <div class="container">
    <div style="text-align:center;max-width:600px;margin:0 auto 36px;">
      <span class="section-label"><?= t('Pricing','Tarifs') ?></span>
      <h2 style="font-family:'Fraunces',serif;font-weight:900;font-size:1.8rem;margin-bottom:10px;"><?= t('Choose your plan','Choisissez votre forfait') ?></h2>
      <p style="color:rgba(255,255,255,0.55);font-size:14.5px;"><?= t('Pay once via MTN MoMo or Orange Money — no monthly fees, no credit card.','Payez une fois via MTN MoMo ou Orange Money — pas de frais mensuels, pas de carte bancaire.') ?></p>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;max-width:860px;margin:0 auto;">
      <?php foreach ($packages as $pkg):
        $isPaid    = (int)$pkg['price_xaf'] > 0;
        $durLabel  = (int)$pkg['duration_days'] > 0 ? durationLabel((int)$pkg['duration_days']) : t('Forever','Pour toujours');
        $nameEn    = $pkg['name_en'] ?? '';
        $nameFr    = $pkg['name_fr'] ?? '';
        $dispName  = lang()==='fr' ? $nameFr : $nameEn;
        $isMostPop = $isPaid && isset($paidPkgs[1]) && (int)$pkg['price_xaf'] === (int)$paidPkgs[1]['price_xaf'];
      ?>
      <div style="background:rgba(255,255,255,0.02);border:2px solid <?= $isPaid?'rgba(245,200,66,0.3)':'rgba(255,255,255,0.1)' ?>;border-radius:18px;padding:28px;text-align:center;position:relative;<?= $isMostPop?'background:rgba(245,200,66,0.05);':'' ?>">
        <?php if ($isMostPop): ?>
        <span style="position:absolute;top:-11px;left:50%;transform:translateX(-50%);background:#fcd116;color:#0A1A0F;font-size:11px;font-weight:800;padding:3px 14px;border-radius:99px;white-space:nowrap;">
          🏆 <?= t('Best Value','Meilleur Rapport') ?>
        </span>
        <?php endif; ?>
        <div style="font-family:'Fraunces',serif;font-weight:900;font-size:1.1rem;color:#fff;margin-bottom:8px;margin-top:<?= $isMostPop?'8px':'0' ?>;">
          <?= e($dispName) ?>
        </div>
        <div style="font-family:'Fraunces',serif;font-weight:900;font-size:2rem;color:<?= $isPaid?'#fcd116':'#00A878' ?>;line-height:1;margin-bottom:4px;">
          <?= $isPaid ? number_format((int)$pkg['price_xaf']) . ' XAF' : t('Free','Gratuit') ?>
        </div>
        <div style="font-size:12.5px;color:rgba(255,255,255,0.45);margin-bottom:20px;">
          / <?= $durLabel ?>
          <?php if ($isPaid && (int)$pkg['duration_days'] >= 330): ?>
          <br><span style="color:#00A878;font-weight:600;font-size:11.5px;">
            <?= t('Save vs 6 months','Économisez vs 6 mois') ?>
            <?php
              $sixMonthPrice = 0;
              foreach ($paidPkgs as $pp) { if ((int)$pp['duration_days'] < 330) { $sixMonthPrice = (int)$pp['price_xaf']*2; break; } }
              if ($sixMonthPrice > 0 && $sixMonthPrice > (int)$pkg['price_xaf']):
                echo ' (' . number_format($sixMonthPrice - (int)$pkg['price_xaf']) . ' XAF)';
              endif;
            ?>
          </span>
          <?php endif; ?>
        </div>
        <a href="<?= SITE_URL ?>/add-listing" style="display:block;padding:12px;background:<?= $isPaid?'#fcd116':'rgba(255,255,255,0.08)' ?>;color:<?= $isPaid?'#0A1A0F':'rgba(255,255,255,0.8)' ?>;border-radius:10px;font-size:14px;font-weight:700;text-decoration:none;border:<?= $isPaid?'none':'1px solid rgba(255,255,255,0.15)' ?>;">
          <?= $isPaid ? t('Get Started →','Commencer →') : t('List Free →','S\'inscrire →') ?>
        </a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- HOW TO PAY -->
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
          t('Choose your plan above (6 or 12 months)','Choisissez votre forfait ci-dessus (6 ou 12 mois)'),
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

<!-- WHY UPGRADE -->
<section class="page-section" style="background:rgba(255,255,255,0.01);border-top:1px solid var(--border);">
  <div class="container">
    <div style="text-align:center;max-width:680px;margin:0 auto 36px;">
      <span class="section-label"><?= t('Why upgrade','Pourquoi passer à la version Business') ?></span>
      <h2 style="font-family:'Fraunces',serif;font-weight:900;font-size:1.8rem;"><?= t('More tools to win more customers','Plus d\'outils pour attirer plus de clients') ?></h2>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;">
      <?php foreach ([
        ['📝', t('Full business story','Histoire complète'),        sprintf(t('%d words instead of %d — enough room to really sell your business.',"%d mots au lieu de %d — assez d'espace pour vraiment vendre votre entreprise."), $paidWordLimit, $freeWordLimit)],
        ['📅', t('Online booking system','Système de réservation'), t('Customers book appointments directly from your listing. You approve from your dashboard.','Les clients réservent directement depuis votre annonce. Vous approuvez depuis votre tableau de bord.')],
        ['❓', t('FAQ section','Section FAQ'),                       t('Answer the 4 questions customers always ask — before they even have to message you.','Répondez aux 4 questions que les clients posent toujours — avant même qu\'ils n\'aient à vous écrire.')],
        ['📱', t('Full social links','Liens sociaux complets'),      t('TikTok, Instagram and Facebook links on your listing — turn visitors into followers.','Liens TikTok, Instagram et Facebook sur votre annonce — transformez les visiteurs en abonnés.')],
        ['🔝', t('Always shown first','Toujours affiché en premier'),t('Business Listings appear above all free listings in search and category pages.','Les Annonces Commerciales apparaissent au-dessus des annonces gratuites dans les résultats de recherche.')],
        ['📱', t('QR code','Code QR'),                              t('Print and share your listing QR code on receipts, packaging and business cards.','Imprimez et partagez le code QR de votre annonce sur les reçus, emballages et cartes de visite.')],
      ] as [$icon,$title,$desc]): ?>
      <div style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.08);border-radius:14px;padding:22px 20px;transition:all .2s;">
        <div style="font-size:1.6rem;margin-bottom:10px;"><?= $icon ?></div>
        <h4 style="font-size:14.5px;font-weight:700;margin-bottom:6px;"><?= $title ?></h4>
        <p style="font-size:13px;color:rgba(255,255,255,0.55);line-height:1.65;margin:0;"><?= $desc ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
