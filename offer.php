<?php
/**
 * offer.php — 237Biz Single Offer Detail
 * URL: /offer/{slug}  (via .htaccess rewrite)
 */
require_once __DIR__ . '/includes/config.php';

$isFr = lang() === 'fr';

// ── Resolve slug ──────────────────────────────────────────────────────────
$slug = trim($_GET['slug'] ?? basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)));
$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($slug));

if (!$slug) { redirect(SITE_URL . '/offers'); }

// ── Load offer ────────────────────────────────────────────────────────────
$offer = null;
try {
    $st = db()->prepare("
        SELECT o.*, l.title AS biz_name, l.slug AS biz_slug, l.logo, l.phone, l.whatsapp,
               l.address, l.id AS biz_id,
               c.name_en AS cat_en, c.name_fr AS cat_fr, c.icon AS cat_icon,
               loc.name_en AS loc_en, loc.name_fr AS loc_fr
        FROM offers o
        JOIN listings  l   ON l.id  = o.listing_id
        JOIN categories c  ON c.id  = l.category_id
        JOIN locations  loc ON loc.id = l.location_id
        WHERE o.slug = ?
    ");
    $st->execute([$slug]);
    $offer = $st->fetch();
} catch (Exception $e) {}

if (!$offer) { http_response_code(404); redirect(SITE_URL . '/offers'); }

// Only show active offers to public (admins can preview any)
$isExpired = $offer['expires_at'] && strtotime($offer['expires_at']) < time();
$isActive  = $offer['status'] === 'active' && !$isExpired;
if (!$isActive && !isAdmin()) {
    redirect(SITE_URL . '/offers');
}

// ── Resolved fields ───────────────────────────────────────────────────────
$title   = $isFr && $offer['title_fr']       ? $offer['title_fr']       : $offer['title'];
$desc    = $isFr && $offer['description_fr'] ? $offer['description_fr'] : ($offer['description'] ?? '');
$catName = $isFr ? $offer['cat_fr'] : $offer['cat_en'];
$locName = $isFr ? $offer['loc_fr'] : $offer['loc_en'];

$badgeLabel = ''; $badgeClass = '';
switch ($offer['offer_type']) {
    case 'percent_off': $badgeLabel = '-'.(int)$offer['discount_value'].'%'; break;
    case 'fixed_off':   $badgeLabel = number_format($offer['discount_value']).' XAF '.t('off','de rabais'); break;
    case 'bogo':        $badgeLabel = 'Buy 1 Get 1'; $badgeClass = 'bogo'; break;
    case 'free_item':   $badgeLabel = t('Free Item Included','Article Gratuit Inclus'); break;
    case 'bundle':      $badgeLabel = 'Bundle Deal'; $badgeClass = 'bundle'; break;
}

// ── More offers from same business ───────────────────────────────────────
$moreOffers = [];
try {
    $mo = db()->prepare("
        SELECT o.slug, o.title, o.title_fr, o.offer_type, o.discount_value, o.expires_at
        FROM offers o
        WHERE o.listing_id = ?
          AND o.id != ?
          AND o.status = 'active'
          AND (o.expires_at IS NULL OR o.expires_at > NOW())
        LIMIT 3
    ");
    $mo->execute([$offer['biz_id'], $offer['id']]);
    $moreOffers = $mo->fetchAll();
} catch (Exception $e) {}

// ── Page meta ─────────────────────────────────────────────────────────────
$pageTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ' — 237Biz Marketplace';
$pageDesc  = $desc ? mb_substr(strip_tags($desc), 0, 160) : t('Exclusive deal from '.$offer['biz_name'].' on 237Biz Marketplace.', 'Offre exclusive de '.$offer['biz_name'].' sur le Marketplace 237Biz.');

require_once __DIR__ . '/includes/header.php';
?>
<style>
.offer-detail { max-width: 860px; margin: 2rem auto; padding: 0 1rem; }
.offer-detail-back { font-size:0.82rem; color:#5a8878; text-decoration:none; display:inline-flex;
  align-items:center; gap:0.3rem; margin-bottom:1rem; }
.offer-detail-back:hover { color:#00A878; }

.offer-hero-img {
  width:100%; aspect-ratio:16/6; object-fit:cover;
  border-radius:12px; margin-bottom:1.5rem;
  background:linear-gradient(135deg,rgba(0,168,120,0.1),rgba(0,168,120,0.03));
}
.offer-hero-placeholder {
  width:100%; aspect-ratio:16/6; border-radius:12px; margin-bottom:1.5rem;
  background:linear-gradient(135deg,rgba(0,168,120,0.1),rgba(0,168,120,0.03));
  display:flex; align-items:center; justify-content:center; font-size:4rem;
}

.offer-main { display:grid; grid-template-columns:1fr 300px; gap:1.5rem; }
@media(max-width:680px) { .offer-main { grid-template-columns:1fr; } }

.offer-detail-badge {
  display:inline-flex; align-items:center;
  background:#00A878; color:#fff;
  font-size:0.8rem; font-weight:800; letter-spacing:0.04em;
  padding:0.3rem 0.9rem; border-radius:99px; margin-bottom:0.75rem;
}
.offer-detail-badge.bogo   { background:#7c3aed; }
.offer-detail-badge.bundle { background:#0369a1; }

.offer-detail-title {
  font-family:'Fraunces',serif; font-weight:900;
  font-size:clamp(1.5rem,3.5vw,2.1rem); color:#f0faf5;
  line-height:1.15; margin-bottom:0.5rem;
}
.offer-detail-biz {
  display:flex; align-items:center; gap:0.6rem; margin-bottom:1rem;
}
.offer-detail-biz img {
  width:36px; height:36px; border-radius:8px; object-fit:cover;
  background:rgba(0,168,120,0.1);
}
.offer-detail-biz a { font-weight:700; color:#00A878; text-decoration:none; font-size:0.92rem; }
.offer-detail-biz a:hover { text-decoration:underline; }
.offer-detail-biz span { font-size:0.78rem; color:#5a8878; }

.offer-detail-desc {
  color:#8db8a8; line-height:1.7; font-size:0.95rem; margin-bottom:1.5rem;
}
.offer-terms-box {
  background:rgba(0,0,0,0.2); border:1px solid rgba(0,168,120,0.1);
  border-radius:8px; padding:0.9rem 1rem; font-size:0.8rem; color:#5a8878;
}
.offer-terms-box strong { color:#8db8a8; }

/* Sidebar */
.offer-sidebar {
  background:rgba(255,255,255,0.03);
  border:1px solid rgba(0,168,120,0.18); border-radius:12px;
  padding:1.25rem; display:flex; flex-direction:column; gap:0.85rem;
  height:fit-content;
}
.offer-sidebar-row { display:flex; justify-content:space-between; align-items:baseline; font-size:0.85rem; }
.offer-sidebar-row .lbl { color:#5a8878; }
.offer-sidebar-row .val { color:#e8f5ee; font-weight:600; }
.offer-sidebar-divider { border:none; border-top:1px solid rgba(0,168,120,0.12); }

.offer-action-btn {
  display:block; text-align:center; padding:0.75rem;
  border-radius:10px; font-weight:700; font-size:0.92rem;
  text-decoration:none; transition:all 0.15s;
}
.offer-action-btn.primary { background:#00A878; color:#fff; }
.offer-action-btn.primary:hover { background:#00c48f; }
.offer-action-btn.whatsapp { background:#25d366; color:#fff; }
.offer-action-btn.whatsapp:hover { background:#1db954; }
.offer-action-btn.outline {
  border:1px solid rgba(0,168,120,0.3); color:#00A878;
}
.offer-action-btn.outline:hover { background:rgba(0,168,120,0.08); }

.promo-code-box {
  background:rgba(0,168,120,0.08); border:1px dashed rgba(0,168,120,0.4);
  border-radius:8px; padding:0.7rem 1rem; text-align:center;
}
.promo-code-box .code {
  font-family:monospace; font-size:1.15rem; font-weight:800;
  letter-spacing:0.12em; color:#00A878;
}
.promo-code-box small { font-size:0.72rem; color:#5a8878; display:block; }

.more-offers { margin-top:2.5rem; }
.more-offers h3 {
  font-family:'Fraunces',serif; font-weight:800; font-size:1.1rem; color:#e8f5ee;
  margin-bottom:0.75rem;
}
.more-offer-link {
  display:flex; align-items:center; justify-content:space-between;
  padding:0.65rem 0.9rem; border-radius:8px;
  background:rgba(255,255,255,0.02); border:1px solid rgba(0,168,120,0.1);
  text-decoration:none; margin-bottom:0.5rem; transition:border-color 0.15s;
}
.more-offer-link:hover { border-color:rgba(0,168,120,0.35); }
.more-offer-link span { color:#e8f5ee; font-size:0.88rem; }
.more-offer-link small { color:#5a8878; font-size:0.75rem; }
</style>

<div class="offer-detail">
  <a href="<?= SITE_URL ?>/offers" class="offer-detail-back">← <?= t('Back to Deals','Retour aux Offres') ?></a>

  <!-- Hero image -->
  <?php if ($offer['image']): ?>
    <img src="<?= UPLOAD_URL . htmlspecialchars($offer['image'], ENT_QUOTES, 'UTF-8') ?>"
         alt="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>"
         class="offer-hero-img" loading="lazy">
  <?php else: ?>
    <div class="offer-hero-placeholder">🏷️</div>
  <?php endif; ?>

  <div class="offer-main">
    <!-- Left col: main content -->
    <div>
      <?php if ($badgeLabel): ?>
        <div class="offer-detail-badge <?= $badgeClass ?>"><?= htmlspecialchars($badgeLabel, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <h1 class="offer-detail-title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>

      <!-- Business link -->
      <div class="offer-detail-biz">
        <?php if ($offer['logo']): ?>
          <img src="<?= UPLOAD_URL . htmlspecialchars($offer['logo'], ENT_QUOTES, 'UTF-8') ?>"
               alt="<?= htmlspecialchars($offer['biz_name'], ENT_QUOTES, 'UTF-8') ?>">
        <?php endif; ?>
        <div>
          <a href="<?= SITE_URL ?>/listing/<?= htmlspecialchars($offer['biz_slug'], ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($offer['biz_name'], ENT_QUOTES, 'UTF-8') ?>
          </a><br>
          <span><?= htmlspecialchars($offer['cat_icon'] . ' ' . $catName . ' · 📍 ' . $locName, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
      </div>

      <?php if ($desc): ?>
        <div class="offer-detail-desc"><?= nl2br(htmlspecialchars($desc, ENT_QUOTES, 'UTF-8')) ?></div>
      <?php endif; ?>

      <?php if ($offer['terms']): ?>
        <div class="offer-terms-box">
          <strong><?= t('Terms & Conditions','Termes et Conditions') ?>:</strong><br>
          <?= nl2br(htmlspecialchars($offer['terms'], ENT_QUOTES, 'UTF-8')) ?>
        </div>
      <?php endif; ?>

      <!-- More offers from this business -->
      <?php if ($moreOffers): ?>
        <div class="more-offers">
          <h3><?= t('More from','Autres offres de') ?> <?= htmlspecialchars($offer['biz_name'], ENT_QUOTES, 'UTF-8') ?></h3>
          <?php foreach ($moreOffers as $mo):
            $moTitle = $isFr && $mo['title_fr'] ? $mo['title_fr'] : $mo['title'];
          ?>
            <a href="<?= SITE_URL ?>/offer/<?= htmlspecialchars($mo['slug'], ENT_QUOTES, 'UTF-8') ?>" class="more-offer-link">
              <span><?= htmlspecialchars($moTitle, ENT_QUOTES, 'UTF-8') ?></span>
              <small>
                <?php if ($mo['expires_at']): ?>
                  ⏰ <?= date('d M', strtotime($mo['expires_at'])) ?>
                <?php endif; ?>
              </small>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Right sidebar -->
    <div class="offer-sidebar">
      <!-- Promo code -->
      <?php if ($offer['promo_code']): ?>
        <div class="promo-code-box">
          <small><?= t('Use code at checkout:','Code à utiliser lors du paiement :') ?></small>
          <div class="code"><?= htmlspecialchars($offer['promo_code'], ENT_QUOTES, 'UTF-8') ?></div>
        </div>
      <?php endif; ?>

      <!-- Key details -->
      <?php if ($offer['original_price']): ?>
        <div class="offer-sidebar-row">
          <span class="lbl"><?= t('Original Price','Prix Original') ?></span>
          <span class="val" style="text-decoration:line-through;color:#5a8878;"><?= number_format($offer['original_price']) ?> XAF</span>
        </div>
      <?php endif; ?>
      <?php if ($offer['offer_type'] === 'percent_off' && $offer['original_price']): ?>
        <div class="offer-sidebar-row">
          <span class="lbl"><?= t('You Save','Vous économisez') ?></span>
          <span class="val" style="color:#4ade80;">
            <?= number_format($offer['original_price'] * $offer['discount_value'] / 100) ?> XAF
          </span>
        </div>
      <?php endif; ?>

      <div class="offer-sidebar-row">
        <span class="lbl"><?= t('Valid From','Valide à partir du') ?></span>
        <span class="val"><?= date('d M Y', strtotime($offer['starts_at'])) ?></span>
      </div>
      <div class="offer-sidebar-row">
        <span class="lbl"><?= t('Expires','Expire') ?></span>
        <span class="val <?= $isExpired ? 'style="color:#f87171;"' : '' ?>">
          <?= $offer['expires_at'] ? date('d M Y', strtotime($offer['expires_at'])) : t('No expiry','Sans expiration') ?>
        </span>
      </div>
      <?php if ($offer['max_redemptions']): ?>
        <div class="offer-sidebar-row">
          <span class="lbl"><?= t('Remaining','Restantes') ?></span>
          <span class="val"><?= max(0, $offer['max_redemptions'] - $offer['redemption_count']) ?></span>
        </div>
      <?php endif; ?>

      <hr class="offer-sidebar-divider">

      <!-- Action buttons -->
      <?php if ($offer['whatsapp'] || $offer['phone']): ?>
        <?php $waNum = preg_replace('/\D/','',$offer['whatsapp'] ?: $offer['phone']); ?>
        <?php $waMsg = urlencode(t("Hi, I saw your deal on 237Biz: \"$title\"","Bonjour, j'ai vu votre offre sur 237Biz : \"$title\"")); ?>
        <a href="https://wa.me/<?= $waNum ?>?text=<?= $waMsg ?>" target="_blank" rel="noopener"
           class="offer-action-btn whatsapp">
          💬 <?= t('Claim via WhatsApp','Obtenir via WhatsApp') ?>
        </a>
      <?php endif; ?>
      <a href="<?= SITE_URL ?>/listing/<?= htmlspecialchars($offer['biz_slug'], ENT_QUOTES, 'UTF-8') ?>"
         class="offer-action-btn outline">
        🏢 <?= t('View Business','Voir l\'Entreprise') ?>
      </a>
      <?php if ($offer['phone']): ?>
        <a href="tel:<?= htmlspecialchars($offer['phone'], ENT_QUOTES, 'UTF-8') ?>"
           class="offer-action-btn outline">
          📞 <?= htmlspecialchars($offer['phone'], ENT_QUOTES, 'UTF-8') ?>
        </a>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
