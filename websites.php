<?php
require_once __DIR__ . '/includes/config.php';

$isFr     = lang() === 'fr';
$pageTitle = t('Websites Built by 237Biz','Sites web créés par 237Biz');
$pageDesc  = t(
    'We design and manage professional websites for Cameroonian businesses. See our work.',
    'Nous concevons et gérons des sites web professionnels pour les entreprises camerounaises. Découvrez nos réalisations.'
);

$sites = [
  [
    'name'     => 'The Tahirih Effect',
    'url'      => 'https://thetahiriheffect.com',
    'tagline'  => t('Cameroon\'s Business Visibility Agency','Agence de visibilité des entreprises au Cameroun'),
    'desc'     => t(
        'A bold brand and marketing agency website built to position The Tahirih Effect as Cameroon\'s go-to business visibility partner. Features service packages, portfolio showcase, and a strong call to action.',
        'Un site d\'agence de marque et de marketing conçu pour positionner The Tahirih Effect comme le partenaire de visibilité incontournable au Cameroun. Comprend les forfaits de services, un portfolio et un appel à l\'action percutant.'
    ),
    'features' => [
        t('Service packages & pricing','Forfaits et tarifs'),
        t('Portfolio showcase','Vitrine portfolio'),
        t('Lead capture & contact','Capture de prospects'),
        t('Bilingual EN/FR','Bilingue EN/FR'),
    ],
    'category' => t('Marketing Agency','Agence Marketing'),
    'icon'     => '🎯',
    'color'    => '#e85d04',
    'badge'    => t('First Site Built','Premier site construit'),
    'screenshot_bg' => '#1a1f3a',
    'screenshot'    => 'screenshot-thetahiriheffect.jpg',
  ],
  [
    'name'     => 'Sabi Pot Restaurant & Grill',
    'url'      => 'https://sabi-pot.com',
    'tagline'  => t('Rich, slow-cooked African flavour','Les saveurs africaines mijotées avec soin'),
    'desc'     => t(
        'A full restaurant website for Sabi Pot, a Cameroonian kitchen bringing authentic African flavours to the table. Features an online menu, photo gallery, table booking, and ordering integration.',
        'Un site complet pour Sabi Pot, une cuisine camerounaise authentique. Comprend un menu en ligne, une galerie photo, la réservation de table et l\'intégration de commandes.'
    ),
    'features' => [
        t('Online menu','Menu en ligne'),
        t('Table booking','Réservation de table'),
        t('Photo gallery','Galerie photo'),
        t('Order / Book CTA','CTA Commande/Réservation'),
    ],
    'category' => t('Restaurant & Food','Restaurant & Alimentation'),
    'icon'     => '🍲',
    'color'    => '#c1440e',
    'badge'    => t('Latest Build','Dernière réalisation'),
    'screenshot_bg' => '#1a0a00',
    'screenshot'    => 'screenshot-sabi-pot.jpg',
  ],
];

require_once __DIR__ . '/includes/header.php';
?>

<style>
/* ── Page layout ───────────────────────────────────────────── */
.ws-hero {
  background: linear-gradient(135deg, var(--primary) 0%, #0d3b2e 100%);
  color: #fff;
  padding: 4rem 1.5rem 3rem;
  text-align: center;
}
.ws-hero-label {
  display: inline-block;
  font-size: 0.75rem;
  font-weight: 700;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  background: rgba(255,255,255,0.15);
  border-radius: 20px;
  padding: 0.3rem 1rem;
  margin-bottom: 1rem;
}
.ws-hero h1 {
  font-family: 'Fraunces', serif;
  font-size: clamp(2rem, 5vw, 3.2rem);
  font-weight: 900;
  margin: 0 0 1rem;
  line-height: 1.1;
}
.ws-hero p {
  font-size: 1.1rem;
  opacity: 0.88;
  max-width: 540px;
  margin: 0 auto 2rem;
}
.ws-hero-cta {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  background: #fff;
  color: var(--primary);
  font-weight: 700;
  font-size: 0.95rem;
  padding: 0.75rem 1.75rem;
  border-radius: 10px;
  text-decoration: none;
  transition: transform .15s, box-shadow .15s;
}
.ws-hero-cta:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,0.2); }

/* ── Stats bar ─────────────────────────────────────────────── */
.ws-stats {
  background: var(--card);
  border-bottom: 1px solid var(--border);
  padding: 1.25rem 1.5rem;
}
.ws-stats-inner {
  max-width: 900px;
  margin: 0 auto;
  display: flex;
  gap: 2rem;
  justify-content: center;
  flex-wrap: wrap;
}
.ws-stat { text-align: center; }
.ws-stat-num { font-size: 1.8rem; font-weight: 900; color: var(--primary); line-height: 1; }
.ws-stat-label { font-size: 0.75rem; color: var(--muted); margin-top: 0.2rem; }

/* ── Cards ─────────────────────────────────────────────────── */
.ws-wrap {
  max-width: 1000px;
  margin: 0 auto;
  padding: 3rem 1.5rem;
  display: flex;
  flex-direction: column;
  gap: 3rem;
}
.ws-card {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: 18px;
  overflow: hidden;
  display: grid;
  grid-template-columns: 1fr 1fr;
  min-height: 360px;
  transition: box-shadow .2s;
}
.ws-card:hover { box-shadow: 0 8px 32px rgba(0,0,0,0.12); }
.ws-card:nth-child(even) { direction: rtl; }
.ws-card:nth-child(even) > * { direction: ltr; }

.ws-card-screen {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  min-height: 280px;
}
.ws-browser {
  width: 88%;
  border-radius: 10px;
  overflow: hidden;
  box-shadow: 0 12px 40px rgba(0,0,0,0.4);
  border: 1px solid rgba(255,255,255,0.1);
}
.ws-browser-bar {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.5rem 0.8rem;
  background: #2a2a2a;
}
.ws-browser-dot {
  width: 10px; height: 10px;
  border-radius: 50%;
}
.ws-browser-url {
  flex: 1;
  background: #3a3a3a;
  border-radius: 4px;
  padding: 0.2rem 0.6rem;
  font-size: 0.65rem;
  color: #aaa;
  font-family: monospace;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.ws-browser-content {
  width: 100%;
  aspect-ratio: 16/9;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 3rem;
}

.ws-card-body {
  padding: 2rem 2rem 2rem;
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 1rem;
}
.ws-card-category {
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: var(--muted);
}
.ws-badge {
  display: inline-block;
  font-size: 0.7rem;
  font-weight: 700;
  padding: 0.2rem 0.65rem;
  border-radius: 20px;
  color: #fff;
  margin-left: 0.5rem;
}
.ws-card h2 {
  font-family: 'Fraunces', serif;
  font-size: 1.6rem;
  font-weight: 900;
  margin: 0;
  line-height: 1.2;
}
.ws-card-tagline {
  font-style: italic;
  color: var(--muted);
  font-size: 0.9rem;
  margin-top: -0.3rem;
}
.ws-card-desc {
  font-size: 0.9rem;
  color: var(--text);
  line-height: 1.6;
  margin: 0;
}
.ws-features {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  margin: 0;
  padding: 0;
  list-style: none;
}
.ws-features li {
  font-size: 0.75rem;
  font-weight: 600;
  background: var(--bg);
  border: 1px solid var(--border);
  border-radius: 6px;
  padding: 0.25rem 0.65rem;
  color: var(--text);
}
.ws-card-actions {
  display: flex;
  gap: 0.75rem;
  flex-wrap: wrap;
  margin-top: 0.25rem;
}
.ws-btn-primary {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.6rem 1.25rem;
  border-radius: 9px;
  font-size: 0.85rem;
  font-weight: 700;
  text-decoration: none;
  color: #fff;
  transition: opacity .15s, transform .15s;
}
.ws-btn-primary:hover { opacity: 0.88; transform: translateY(-1px); }
.ws-btn-ghost {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.6rem 1.25rem;
  border-radius: 9px;
  font-size: 0.85rem;
  font-weight: 700;
  text-decoration: none;
  color: var(--text);
  border: 1px solid var(--border);
  background: var(--bg);
  transition: border-color .15s, transform .15s;
}
.ws-btn-ghost:hover { border-color: var(--primary); color: var(--primary); transform: translateY(-1px); }

/* ── CTA section ───────────────────────────────────────────── */
.ws-cta {
  background: linear-gradient(135deg, #0d3b2e 0%, var(--primary) 100%);
  color: #fff;
  text-align: center;
  padding: 4rem 1.5rem;
}
.ws-cta h2 {
  font-family: 'Fraunces', serif;
  font-size: clamp(1.6rem, 4vw, 2.4rem);
  font-weight: 900;
  margin: 0 0 0.75rem;
}
.ws-cta p { opacity: 0.88; max-width: 480px; margin: 0 auto 2rem; font-size: 1rem; }
.ws-cta-btns { display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap; }
.ws-cta-btn-white {
  background: #fff;
  color: var(--primary);
  font-weight: 700;
  padding: 0.8rem 2rem;
  border-radius: 10px;
  text-decoration: none;
  font-size: 0.95rem;
  transition: transform .15s;
}
.ws-cta-btn-white:hover { transform: translateY(-2px); }
.ws-cta-btn-outline {
  background: transparent;
  color: #fff;
  font-weight: 700;
  padding: 0.8rem 2rem;
  border-radius: 10px;
  text-decoration: none;
  font-size: 0.95rem;
  border: 2px solid rgba(255,255,255,0.5);
  transition: border-color .15s, transform .15s;
}
.ws-cta-btn-outline:hover { border-color: #fff; transform: translateY(-2px); }

/* ── Responsive ────────────────────────────────────────────── */
@media (max-width: 700px) {
  .ws-card { grid-template-columns: 1fr; direction: ltr !important; }
  .ws-card:nth-child(even) { direction: ltr; }
  .ws-card-screen { min-height: 200px; padding: 1.5rem; }
  .ws-card-body { padding: 1.5rem; }
  .ws-card h2 { font-size: 1.3rem; }
}
</style>

<!-- Hero -->
<section class="ws-hero">
  <div class="ws-hero-label">🌐 <?= t('Our Web Work','Nos réalisations web') ?></div>
  <h1><?= t('Websites Built &amp; Managed<br>by 237Biz','Sites web créés &amp; gérés<br>par 237Biz') ?></h1>
  <p><?= t('We don\'t just list businesses — we build their online presence. Here\'s what we\'ve delivered.','Nous ne listons pas seulement des entreprises — nous construisons leur présence en ligne. Voici nos réalisations.') ?></p>
  <a href="<?= SITE_URL ?>/contact?type=website" class="ws-hero-cta">
    💬 <?= t('Get a Website for Your Business','Obtenir un site web pour votre entreprise') ?>
  </a>
</section>

<!-- Stats bar -->
<div class="ws-stats">
  <div class="ws-stats-inner">
    <div class="ws-stat">
      <div class="ws-stat-num">2</div>
      <div class="ws-stat-label"><?= t('Sites Built','Sites construits') ?></div>
    </div>
    <div class="ws-stat">
      <div class="ws-stat-num">100%</div>
      <div class="ws-stat-label"><?= t('Managed & Hosted','Gérés & hébergés') ?></div>
    </div>
    <div class="ws-stat">
      <div class="ws-stat-num">🇨🇲</div>
      <div class="ws-stat-label"><?= t('Cameroon-focused','Axé Cameroun') ?></div>
    </div>
  </div>
</div>

<!-- Cards -->
<div class="ws-wrap">
  <?php foreach ($sites as $i => $s): ?>
  <div class="ws-card">

    <!-- Browser mockup -->
    <div class="ws-card-screen" style="background:<?= $s['screenshot_bg'] ?>;">
      <div class="ws-browser">
        <div class="ws-browser-bar">
          <div class="ws-browser-dot" style="background:#ff5f57;"></div>
          <div class="ws-browser-dot" style="background:#febc2e;"></div>
          <div class="ws-browser-dot" style="background:#28c840;"></div>
          <div class="ws-browser-url"><?= htmlspecialchars(str_replace('https://','',$s['url'])) ?></div>
        </div>
        <div class="ws-browser-content" style="background:<?= $s['screenshot_bg'] ?>;padding:0;overflow:hidden;">
          <?php if (!empty($s['screenshot'])): ?>
          <img src="<?= SITE_URL ?>/assets/images/<?= $s['screenshot'] ?>" alt="<?= htmlspecialchars($s['name']) ?> website screenshot"
               style="width:100%;height:100%;object-fit:cover;object-position:top center;display:block;border-radius:0 0 4px 4px;">
          <?php else: ?>
          <span style="font-size:3.5rem;"><?= $s['icon'] ?></span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Content -->
    <div class="ws-card-body">
      <div class="ws-card-category">
        <?= htmlspecialchars($s['category']) ?>
        <span class="ws-badge" style="background:<?= $s['color'] ?>;"><?= htmlspecialchars($s['badge']) ?></span>
      </div>
      <h2><?= htmlspecialchars($s['name']) ?></h2>
      <p class="ws-card-tagline">"<?= htmlspecialchars($s['tagline']) ?>"</p>
      <p class="ws-card-desc"><?= htmlspecialchars($s['desc']) ?></p>
      <ul class="ws-features">
        <?php foreach ($s['features'] as $f): ?>
        <li>✓ <?= htmlspecialchars($f) ?></li>
        <?php endforeach; ?>
      </ul>
      <div class="ws-card-actions">
        <a href="<?= htmlspecialchars($s['url']) ?>" target="_blank" rel="noopener" class="ws-btn-primary" style="background:<?= $s['color'] ?>;">
          🌐 <?= t('View Live Site','Voir le site') ?>
        </a>
        <a href="<?= SITE_URL ?>/contact?type=website" class="ws-btn-ghost">
          💬 <?= t('Build Mine','Créer le mien') ?>
        </a>
      </div>
    </div>

  </div>
  <?php endforeach; ?>
</div>

<!-- CTA -->
<section class="ws-cta">
  <h2><?= t('Want a Website Like This?','Vous voulez un site comme celui-ci ?') ?></h2>
  <p><?= t('We design, build and manage professional websites for Cameroonian businesses — from restaurants to agencies.','Nous concevons, construisons et gérons des sites web professionnels pour les entreprises camerounaises — des restaurants aux agences.') ?></p>
  <div class="ws-cta-btns">
    <a href="<?= SITE_URL ?>/contact?type=website" class="ws-cta-btn-white">
      🚀 <?= t('Get Started','Commencer') ?>
    </a>
    <a href="<?= SITE_URL ?>/join" class="ws-cta-btn-outline">
      📋 <?= t('List Your Business','Référencer votre entreprise') ?>
    </a>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
