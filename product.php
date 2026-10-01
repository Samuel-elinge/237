<?php
require_once __DIR__ . '/includes/config.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(SITE_URL . '/products');

$st = db()->prepare("
    SELECT p.*,
           l.title AS biz_name, l.slug AS biz_slug, l.featured AS biz_featured,
           l.phone AS biz_phone, l.whatsapp AS biz_whatsapp, l.user_id AS biz_user_id,
           loc.name_en AS loc_name, loc.slug AS loc_slug,
           c.name_en AS cat_name, c.icon AS cat_icon, c.slug AS cat_slug
    FROM listing_products p
    JOIN listings l   ON l.id = p.listing_id
    JOIN locations loc ON loc.id = l.location_id
    JOIN categories c  ON c.id = l.category_id
    WHERE p.id = ? AND l.status = 'approved' AND l.featured = 1
");
$st->execute([$id]);
$prod = $st->fetch();
if (!$prod) { http_response_code(404); redirect(SITE_URL . '/products'); }

// Other products from same business
$others = db()->prepare("
    SELECT * FROM listing_products
    WHERE listing_id = ? AND id != ? AND in_stock = 1
    ORDER BY sort_order, created_at DESC
    LIMIT 6
");
$others->execute([$prod['listing_id'] ?? 0, $id]);
$others = $others->fetchAll();

// Discount %
$discountPct = ($prod['sale_price'] && $prod['price'] > 0)
    ? round((1 - $prod['sale_price'] / $prod['price']) * 100)
    : 0;

$cu = currentUser();

$pageTitle = e($prod['name']) . ' — ' . e($prod['biz_name']) . ' — 237Biz';
$pageDesc  = e(mb_substr($prod['description'] ?? $prod['name'], 0, 155));

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div class="container">
    <nav class="breadcrumb">
      <a href="<?= SITE_URL ?>/"><?= t('Home','Accueil') ?></a> ›
      <a href="<?= SITE_URL ?>/products"><?= t('Products & Services','Produits & Services') ?></a> ›
      <?php if ($prod['category_tag']): ?>
        <a href="<?= SITE_URL ?>/products?tag=<?= urlencode($prod['category_tag']) ?>"><?= e($prod['category_tag']) ?></a> ›
      <?php endif; ?>
      <span><?= e($prod['name']) ?></span>
    </nav>
  </div>
</div>

<section class="page-section">
  <div class="container">
    <div class="listing-grid">

      <!-- ── MAIN CONTENT ── -->
      <div>

        <!-- Product card -->
        <div class="listing-widget" style="padding:0;overflow:hidden;margin-bottom:1.25rem;">

          <!-- Image -->
          <div style="position:relative;background:rgba(255,255,255,0.04);<?= $prod['image'] ? '' : 'height:200px;display:flex;align-items:center;justify-content:center;' ?>">
            <?php if ($prod['image']): ?>
              <img src="<?= e(UPLOAD_URL . $prod['image']) ?>" alt="<?= e($prod['name']) ?>"
                   style="width:100%;max-height:420px;object-fit:cover;display:block;">
            <?php else: ?>
              <div style="font-size:4rem;color:var(--muted-2);">🛍️</div>
            <?php endif; ?>

            <!-- Badges -->
            <div style="position:absolute;top:14px;left:14px;display:flex;gap:0.5rem;flex-wrap:wrap;">
              <?php if ($prod['sale_price']): ?>
                <span style="background:#e63946;color:#fff;font-size:0.75rem;font-weight:600;padding:0.3rem 0.75rem;border-radius:20px;">
                  -<?= $discountPct ?>% SALE
                </span>
              <?php endif; ?>
              <?php if (!$prod['in_stock']): ?>
                <span style="background:rgba(0,0,0,0.6);color:rgba(255,255,255,0.6);font-size:0.75rem;padding:0.3rem 0.75rem;border-radius:20px;">
                  <?= t('Out of stock','Rupture de stock') ?>
                </span>
              <?php endif; ?>
            </div>
          </div>

          <!-- Details -->
          <div style="padding:1.5rem;">
            <?php if ($prod['category_tag']): ?>
              <div style="font-size:0.72rem;color:var(--green);text-transform:uppercase;letter-spacing:0.08em;font-weight:500;margin-bottom:0.5rem;">
                <?= e($prod['category_tag']) ?>
              </div>
            <?php endif; ?>

            <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.6rem,3vw,2.2rem);margin-bottom:1rem;line-height:1.15;">
              <?= e($prod['name']) ?>
            </h1>

            <!-- Pricing block -->
            <div style="display:flex;align-items:baseline;gap:1rem;margin-bottom:1.5rem;flex-wrap:wrap;">
              <?php if ($prod['sale_price']): ?>
                <span style="font-family:'Fraunces',serif;font-weight:900;font-size:2rem;color:#00A878;">
                  <?= number_format($prod['sale_price']) ?> XAF
                </span>
                <span style="font-size:1.1rem;color:var(--muted-2);text-decoration:line-through;">
                  <?= number_format($prod['price']) ?> XAF
                </span>
                <span style="background:rgba(230,57,70,0.15);color:#e63946;font-size:0.8rem;font-weight:600;padding:0.25rem 0.75rem;border-radius:20px;">
                  <?= t('Save','Économisez') ?> <?= number_format($prod['price'] - $prod['sale_price']) ?> XAF
                </span>
              <?php else: ?>
                <span style="font-family:'Fraunces',serif;font-weight:900;font-size:2rem;color:var(--yellow);">
                  <?= number_format($prod['price']) ?> XAF
                </span>
              <?php endif; ?>
            </div>

            <!-- Description -->
            <?php if ($prod['description']): ?>
              <div style="color:var(--muted);font-size:0.95rem;line-height:1.85;white-space:pre-wrap;margin-bottom:1.5rem;">
                <?= nl2br(e($prod['description'])) ?>
              </div>
            <?php endif; ?>

            <!-- Stock status -->
            <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:1.5rem;font-size:0.875rem;">
              <span style="width:8px;height:8px;border-radius:50%;background:<?= $prod['in_stock'] ? 'var(--green)' : '#e63946' ?>;flex-shrink:0;"></span>
              <span style="color:var(--muted);">
                <?= $prod['in_stock'] ? t('In stock — contact the business to order','En stock — contactez l\'entreprise pour commander') : t('Currently out of stock','Actuellement en rupture de stock') ?>
              </span>
            </div>

            <!-- CTAs -->
            <?php if ($prod['in_stock']): ?>
              <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
                <?php if ($prod['biz_whatsapp']): ?>
                  <a href="https://wa.me/<?= e(preg_replace('/\D/','',$prod['biz_whatsapp'])) ?>?text=<?= urlencode(t('Hi! I\'m interested in: ','Bonjour ! Je suis intéressé(e) par : ') . $prod['name'] . ' (' . number_format($prod['sale_price'] ?: $prod['price']) . ' XAF)') ?>"
                     target="_blank" class="btn" style="background:rgba(37,211,102,0.15);color:#25D366;border:1px solid rgba(37,211,102,0.3);display:inline-flex;align-items:center;gap:0.5rem;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="#25D366"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                    <?= t('Order via WhatsApp','Commander via WhatsApp') ?>
                  </a>
                <?php endif; ?>
                <a href="<?= SITE_URL ?>/listing/<?= e($prod['biz_slug']) ?>#enquiry-form" class="btn btn-primary">
                  📬 <?= t('Contact Business','Contacter l\'Entreprise') ?>
                </a>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Other products from same business -->
        <?php if ($others): ?>
          <div class="listing-widget">
            <h4 style="margin-bottom:1.25rem;"><?= t('More from','Plus de') ?> <?= e($prod['biz_name']) ?></h4>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:1rem;">
              <?php foreach ($others as $o): ?>
                <a href="<?= SITE_URL ?>/product/<?= $o['id'] ?>"
                   style="text-decoration:none;color:inherit;background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:10px;overflow:hidden;transition:border-color 0.2s;"
                   onmouseover="this.style.borderColor='rgba(0,168,120,0.3)'" onmouseout="this.style.borderColor='var(--border)'">
                  <div style="height:90px;background:rgba(255,255,255,0.04);overflow:hidden;">
                    <?php if ($o['image']): ?>
                      <img src="<?= e(UPLOAD_URL . $o['image']) ?>" alt="<?= e($o['name']) ?>" style="width:100%;height:100%;object-fit:cover;">
                    <?php else: ?>
                      <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:1.75rem;color:var(--muted-2);">🛍️</div>
                    <?php endif; ?>
                  </div>
                  <div style="padding:0.65rem;">
                    <div style="font-size:0.78rem;font-weight:500;color:var(--white);line-height:1.3;margin-bottom:0.25rem;"><?= e(mb_substr($o['name'],0,45)) ?></div>
                    <div style="font-size:0.8rem;font-weight:700;color:<?= $o['sale_price'] ? '#00A878' : 'var(--yellow)' ?>;">
                      <?= number_format($o['sale_price'] ?: $o['price']) ?> XAF
                      <?php if ($o['sale_price']): ?>
                        <span style="font-size:0.68rem;color:var(--muted-2);text-decoration:line-through;font-weight:400;"><?= number_format($o['price']) ?></span>
                      <?php endif; ?>
                    </div>
                  </div>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

      </div>

      <!-- ── SIDEBAR ── -->
      <aside>

        <!-- Business card -->
        <div class="listing-widget">
          <h4 style="margin-bottom:1rem;"><?= t('Sold by','Vendu par') ?></h4>
          <a href="<?= SITE_URL ?>/listing/<?= e($prod['biz_slug']) ?>"
             style="display:flex;align-items:center;gap:0.75rem;text-decoration:none;margin-bottom:1rem;">
            <div style="width:42px;height:42px;border-radius:10px;background:rgba(0,168,120,0.1);border:1px solid rgba(0,168,120,0.2);display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0;">
              <?= $prod['cat_icon'] ?>
            </div>
            <div>
              <div style="font-weight:500;color:var(--white);font-size:0.9rem;"><?= e($prod['biz_name']) ?></div>
              <div style="font-size:0.75rem;color:var(--muted-2);">📍 <?= e($prod['loc_name']) ?> · <?= e($prod['cat_name']) ?></div>
            </div>
          </a>
          <?php if ($prod['biz_phone']): ?>
            <div class="contact-item">
              <span class="contact-icon">📞</span>
              <a href="tel:<?= e($prod['biz_phone']) ?>" style="color:var(--muted);"><?= e($prod['biz_phone']) ?></a>
            </div>
          <?php endif; ?>
          <?php if ($prod['biz_whatsapp']): ?>
            <div class="contact-item">
              <span class="contact-icon">💬</span>
              <a href="https://wa.me/<?= e(preg_replace('/\D/','',$prod['biz_whatsapp'])) ?>" target="_blank" style="color:#25D366;">WhatsApp</a>
            </div>
          <?php endif; ?>
          <a href="<?= SITE_URL ?>/listing/<?= e($prod['biz_slug']) ?>" class="btn btn-outline btn-sm btn-full" style="margin-top:0.75rem;">
            <?= t('View Full Listing →','Voir l\'Annonce Complète →') ?>
          </a>
        </div>

        <!-- Browse products from this business -->
        <div class="listing-widget">
          <h4 style="margin-bottom:0.75rem;font-size:0.9rem;"><?= t('More Products','Plus de Produits') ?></h4>
          <a href="<?= SITE_URL ?>/listing/<?= e($prod['biz_slug']) ?>#products" class="btn btn-outline btn-sm btn-full">
            <?= t('See All Products from this Business','Voir Tous les Produits de cette Entreprise') ?>
          </a>
          <?php if ($prod['category_tag']): ?>
            <a href="<?= SITE_URL ?>/products?tag=<?= urlencode($prod['category_tag']) ?>" class="btn btn-outline btn-sm btn-full" style="margin-top:0.5rem;">
              <?= t('Browse','Parcourir') ?> "<?= e($prod['category_tag']) ?>"
            </a>
          <?php endif; ?>
        </div>

        <!-- Admin / owner actions -->
        <?php if ($cu && ($cu['id'] == $prod['biz_user_id'] || isAdmin())): ?>
          <div class="listing-widget">
            <h4 style="margin-bottom:0.75rem;font-size:0.9rem;"><?= t('Manage','Gérer') ?></h4>
            <a href="<?= SITE_URL ?>/manage-products?listing_id=<?= $prod['listing_id'] ?>&edit=<?= $prod['id'] ?>" class="btn btn-primary btn-sm btn-full">
              ✏️ <?= t('Edit this Product','Modifier ce Produit') ?>
            </a>
          </div>
        <?php endif; ?>

      </aside>
    </div>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
