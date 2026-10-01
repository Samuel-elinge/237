<?php
require_once __DIR__ . '/includes/config.php';

$slug = trim($_GET['slug'] ?? '');
if (!$slug) redirect(SITE_URL . '/listings');

$st = db()->prepare("
    SELECT l.*, c.name_en AS cat_en, c.name_fr AS cat_fr, c.icon AS cat_icon, c.slug AS cat_slug,
           loc.name_en AS loc_en, loc.slug AS loc_slug
    FROM listings l
    JOIN categories c ON c.id = l.category_id
    JOIN locations loc ON loc.id = l.location_id
    WHERE l.slug = ? AND l.status = 'approved'
");
$st->execute([$slug]);
$l = $st->fetch();
if (!$l) { http_response_code(404); die('Listing not found.'); }

// Increment views
db()->prepare("UPDATE listings SET views = views + 1 WHERE id = ?")->execute([$l['id']]);

// Detailed view tracking
try {
    $ipHash   = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
    $referrer = mb_substr($_SERVER['HTTP_REFERER'] ?? '', 0, 255);
    $ipHash = hash('sha256', ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '') . date('Y-m-d'));
    $rv = db()->prepare("SELECT id FROM listing_views WHERE listing_id=? AND ip_hash=? AND viewed_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $rv->execute([$l['id'], $ipHash]);
    if (!$rv->fetch()) {
        db()->prepare("INSERT INTO listing_views (listing_id, ip_hash, referrer) VALUES (?,?,?)")
             ->execute([$l['id'], $ipHash, $referrer ?: null]);
    }
} catch (Exception $e) { /* table may not exist yet */ }

// Images
$images = db()->prepare("SELECT * FROM listing_images WHERE listing_id = ? ORDER BY sort_order");
$images->execute([$l['id']]);
$images = $images->fetchAll();

// Reviews
$reviews = db()->prepare("SELECT * FROM reviews WHERE listing_id = ? AND status = 'approved' ORDER BY created_at DESC");
$reviews->execute([$l['id']]);
$reviews = $reviews->fetchAll();

// Products (featured listings only)
$products = [];
if ($l['featured']) {
    try {
        $pst = db()->prepare("SELECT * FROM listing_products WHERE listing_id=? ORDER BY sort_order, created_at DESC");
        $pst->execute([$l['id']]);
        $products = $pst->fetchAll();
    } catch (Exception $e) { /* table may not exist yet */ }
}

// FAQs (featured listings only)
$faqs = [];
if ($l['featured']) {
    try {
        $fq = db()->prepare("SELECT * FROM listing_faqs WHERE listing_id = ? ORDER BY sort_order LIMIT 4");
        $fq->execute([$l['id']]);
        $faqs = $fq->fetchAll();
    } catch (Exception $e) { /* table may not exist yet */ }
}
$avgRating = $reviews ? round(array_sum(array_column($reviews, 'rating')) / count($reviews), 1) : 0;

// Similar listings
$similar = db()->prepare("
    SELECT l.*, c.name_en AS cat_en, c.icon AS cat_icon
    FROM listings l JOIN categories c ON c.id = l.category_id
    WHERE l.category_id = ? AND l.id != ? AND l.status = 'approved'
    LIMIT 3
");
$similar->execute([$l['category_id'], $l['id']]);
$similar = $similar->fetchAll();

// Enquiry table check
$enquiryTableExists = false;
try {
    db()->query('SELECT 1 FROM listing_enquiries LIMIT 1');
    $enquiryTableExists = true;
} catch (Exception $e) {}

// Current user
$cu = currentUser();

// Cheapest active paid package, for the "Upgrade from X XAF" upsell text —
// pulled live so it never goes stale when prices change in admin/packages.php
$cheapestPaidPkg = db()->query("
    SELECT * FROM listing_packages WHERE active=1 AND price_xaf > 0 ORDER BY price_xaf ASC LIMIT 1
")->fetch();

// Word limit helper
function wordLimit(string $text, int $limit): array {
    $words = preg_split('/\s+/', trim($text));
    $truncated = count($words) > $limit;
    return [
        'text'      => implode(' ', array_slice($words, 0, $limit)),
        'truncated' => $truncated,
        'total'     => count($words),
    ];
}

$pageTitle = e($l['title']) . ' — 237Biz';
$pageDesc  = e(mb_substr($l['description'] ?? '', 0, 155));

// Structured data
$extraHead = '';
if (file_exists(__DIR__ . '/includes/schema.php')) {
    require_once __DIR__ . '/includes/schema.php';
    $catName = lang() === 'fr' ? $l['cat_fr'] : $l['cat_en'];

    $extraHead .= '<script type="application/ld+json">' . schemaLocalBusiness($l, $catName, $l['loc_en'], $avgRating, count($reviews)) . '</script>' . "\n";
    $extraHead .= '<script type="application/ld+json">' . schemaBreadcrumb([
        ['name' => 'Home', 'url' => SITE_URL . '/'],
        ['name' => $catName, 'url' => SITE_URL . '/listings?category=' . $l['cat_slug']],
        ['name' => $l['title']],
    ]) . '</script>' . "\n";
    if ($faqs) {
        $faqSchema = schemaFaq(array_map(fn($f) => ['question' => $f['question'], 'answer' => $f['answer']], $faqs));
        if ($faqSchema) $extraHead .= '<script type="application/ld+json">' . $faqSchema . '</script>' . "\n";
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div class="container">
    <nav class="breadcrumb">
      <a href="<?= SITE_URL ?>/"><?= t('Home','Accueil') ?></a> ›
      <a href="<?= SITE_URL ?>/listings?category=<?= e($l['cat_slug']) ?>"><?= e(lang()==='fr' ? $l['cat_fr'] : $l['cat_en']) ?></a> ›
      <span><?= e($l['title']) ?></span>
    </nav>
  </div>
</div>

<section class="page-section">
  <div class="container">
    <div class="listing-grid">

      <!-- ── MAIN CONTENT ── -->
      <div>

        <!-- Header card -->
        <div class="listing-widget">
          <div style="display:flex;gap:1.5rem;align-items:flex-start;">
            <div class="listing-hero-logo">
              <?php if ($l['logo']): ?>
                <img src="<?= e(UPLOAD_URL . $l['logo']) ?>" alt="<?= e($l['title']) ?>">
              <?php else: ?>
                <?= $l['cat_icon'] ?>
              <?php endif; ?>
            </div>
            <div style="flex:1;min-width:0;">
              <div style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;margin-bottom:0.35rem;">
                <span style="font-size:0.72rem;color:var(--green);font-weight:500;text-transform:uppercase;letter-spacing:0.08em;">
                  <?= e(lang()==='fr' ? $l['cat_fr'] : $l['cat_en']) ?>
                </span>
                <?php if ($l['featured']): ?>
                  <span class="badge badge-approved">⭐ <?= t('Featured','En vedette') ?></span>
                <?php endif; ?>
                <?php if ($l['verified']): ?>
                  <span class="listing-verified">✓ <?= t('Verified','Vérifié') ?></span>
                <?php endif; ?>
              </div>
              <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.6rem,3vw,2.2rem);margin-bottom:0.4rem;"><?= e($l['title']) ?></h1>
              <p style="color:var(--muted);font-size:0.875rem;">
                📍 <?= e($l['loc_en']) ?>
                <?php if ($avgRating > 0): ?>
                  &nbsp;·&nbsp; ⭐ <?= $avgRating ?>/5 (<?= count($reviews) ?> <?= t('reviews','avis') ?>)
                <?php endif; ?>
                &nbsp;·&nbsp; 👁 <?= number_format($l['views']) ?> <?= t('views','vues') ?>
              </p>
            </div>
          </div>
        </div>

        <!-- Cover image -->
        <?php if ($l['cover_image']): ?>
          <div style="border-radius:14px;overflow:hidden;margin-bottom:1.25rem;max-height:320px;">
            <img src="<?= e(UPLOAD_URL . $l['cover_image']) ?>" alt="<?= e($l['title']) ?>" style="width:100%;height:320px;object-fit:cover;">
          </div>
        <?php endif; ?>

        <!-- Gallery -->
        <?php if ($images): ?>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:0.75rem;margin-bottom:1.25rem;">
            <?php foreach ($images as $img): ?>
              <div style="border-radius:8px;overflow:hidden;aspect-ratio:1;border:1px solid var(--border);">
                <img src="<?= e(UPLOAD_URL . $img['path']) ?>" alt="<?= e($l['title']) ?>" style="width:100%;height:100%;object-fit:cover;">
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <!-- Description -->
        <div class="listing-widget">
          <h4><?= t('Business Overview','Aperçu de l\'Entreprise') ?></h4>
          <?php
          $desc = $l['description'] ?? '';
          if ($l['featured']): ?>
            <p style="color:var(--muted);font-size:0.9rem;line-height:1.8;"><?= nl2br(e($desc)) ?></p>
          <?php else:
              $wl = wordLimit($desc, 30); ?>
            <p style="color:var(--muted);font-size:0.9rem;line-height:1.8;"><?= nl2br(e($wl['text'])) ?><?= $wl['truncated'] ? '...' : '' ?></p>
            <?php if ($wl['truncated']): ?>
              <div style="background:linear-gradient(135deg,#0D2E1A,#1A3A26);border:1px solid rgba(245,200,66,0.25);border-radius:10px;padding:1rem 1.25rem;margin-top:1rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
                <div>
                  <div style="font-size:0.78rem;color:var(--yellow);font-weight:500;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:0.2rem;">⭐ <?= t('Featured Listing','Annonce Vedette') ?></div>
                  <div style="font-size:0.83rem;color:var(--muted);"><?= t('Upgrade to show your full description.','Passez à la vedette pour afficher votre description complète.') ?></div>
                </div>
                <?php if ($cu && $l['user_id'] && $l['user_id'] == $cu['id']): ?>
                  <a href="<?= SITE_URL ?>/upgrade-listing?listing_id=<?= $l['id'] ?>" class="btn btn-sm" style="background:var(--yellow);color:var(--dark);white-space:nowrap;flex-shrink:0;">
                    ⭐ <?php if ($cheapestPaidPkg): ?>
                      <?= t('Upgrade from','Améliorer à partir de') ?> <?= number_format($cheapestPaidPkg['price_xaf']) ?> XAF
                    <?php else: ?>
                      <?= t('Upgrade','Améliorer') ?>
                    <?php endif; ?>
                  </a>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>

        <!-- FAQ -->
        <?php if ($faqs): ?>
        <div class="listing-widget">
          <h4>❓ <?= t('Frequently Asked Questions','Questions Fréquentes') ?></h4>
          <div style="display:flex;flex-direction:column;gap:0.25rem;margin-top:0.5rem;">
            <?php foreach ($faqs as $idx => $faq): ?>
              <details style="border-bottom:1px solid var(--border);padding:0.85rem 0;<?= $idx === count($faqs)-1 ? 'border-bottom:none;' : '' ?>">
                <summary style="cursor:pointer;font-size:0.9rem;color:var(--white);font-weight:500;list-style:none;display:flex;align-items:center;justify-content:space-between;">
                  <?= e($faq['question']) ?>
                  <span style="color:var(--green);font-size:0.8rem;">▾</span>
                </summary>
                <p style="font-size:0.85rem;color:var(--muted);line-height:1.7;margin-top:0.6rem;"><?= nl2br(e($faq['answer'])) ?></p>
              </details>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- Reviews -->
        <div class="listing-widget">
          <h4><?= t('Reviews','Avis') ?> (<?= count($reviews) ?>)</h4>
          <?php if ($reviews): ?>
            <?php foreach ($reviews as $r): ?>
              <div style="border-bottom:1px solid var(--border);padding:1rem 0;">
                <div style="display:flex;justify-content:space-between;margin-bottom:0.35rem;">
                  <strong style="font-size:0.875rem;color:var(--white);"><?= e($r['name'] ?? t('Anonymous','Anonyme')) ?></strong>
                  <span style="color:var(--yellow);font-size:0.85rem;"><?= str_repeat('⭐', (int)$r['rating']) ?></span>
                </div>
                <p style="color:var(--muted);font-size:0.83rem;"><?= e($r['comment'] ?? '') ?></p>
                <span style="font-size:0.72rem;color:var(--muted-2);"><?= timeAgo($r['created_at']) ?></span>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <p style="color:var(--muted);font-size:0.875rem;"><?= t('No reviews yet. Be the first!','Pas encore d\'avis. Soyez le premier !') ?></p>
          <?php endif; ?>
          <?php if (isLoggedIn()): ?>
            <form action="<?= SITE_URL ?>/submit-review.php" method="POST" style="margin-top:1.5rem;">
              <input type="hidden" name="csrf" value="<?= csrf() ?>">
              <input type="hidden" name="listing_id" value="<?= $l['id'] ?>">
              <div class="form-group">
                <label><?= t('Your rating','Votre note') ?></label>
                <select name="rating" required>
                  <option value="5">⭐⭐⭐⭐⭐ Excellent</option>
                  <option value="4">⭐⭐⭐⭐ <?= t('Good','Bien') ?></option>
                  <option value="3">⭐⭐⭐ <?= t('Average','Moyen') ?></option>
                  <option value="2">⭐⭐ <?= t('Poor','Mauvais') ?></option>
                  <option value="1">⭐ <?= t('Terrible','Terrible') ?></option>
                </select>
              </div>
              <div class="form-group">
                <label><?= t('Your review','Votre avis') ?></label>
                <textarea name="comment" placeholder="<?= t('Share your experience...','Partagez votre expérience...') ?>"></textarea>
              </div>
              <button type="submit" class="btn btn-primary"><?= t('Submit Review','Soumettre l\'avis') ?></button>
            </form>
          <?php else: ?>
            <p style="margin-top:1rem;font-size:0.83rem;color:var(--muted);">
              <a href="<?= SITE_URL ?>/login"><?= t('Sign in','Connectez-vous') ?></a> <?= t('to leave a review.','pour laisser un avis.') ?>
            </p>
          <?php endif; ?>
        </div>

        <!-- Products & Services -->
        <?php if ($products): ?>
        <div class="listing-widget" id="products">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:0.5rem;">
            <h4 style="margin:0;">🛍️ <?= t('Products & Services','Produits & Services') ?> (<?= count($products) ?>)</h4>
            <a href="<?= SITE_URL ?>/products" style="font-size:0.78rem;color:var(--green);"><?= t('Browse all →','Voir tout →') ?></a>
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:1rem;">
            <?php foreach ($products as $prod): ?>
              <?php if (!$prod['in_stock']) continue; ?>
              <a href="<?= SITE_URL ?>/product/<?= $prod['id'] ?>"
                 style="text-decoration:none;color:inherit;background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:10px;overflow:hidden;display:flex;flex-direction:column;transition:border-color 0.2s;"
                 onmouseover="this.style.borderColor='rgba(0,168,120,0.3)'" onmouseout="this.style.borderColor='var(--border)'">
                <!-- Image -->
                <div style="height:120px;background:rgba(255,255,255,0.04);position:relative;overflow:hidden;flex-shrink:0;">
                  <?php if ($prod['image']): ?>
                    <img src="<?= e(UPLOAD_URL . $prod['image']) ?>" alt="<?= e($prod['name']) ?>" style="width:100%;height:100%;object-fit:cover;">
                  <?php else: ?>
                    <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:2rem;color:var(--muted-2);">🛍️</div>
                  <?php endif; ?>
                  <?php if ($prod['sale_price']): ?>
                    <span style="position:absolute;top:6px;left:6px;background:#e63946;color:#fff;font-size:0.6rem;font-weight:600;padding:0.15rem 0.45rem;border-radius:20px;">SALE</span>
                  <?php endif; ?>
                  <?php if ($prod['category_tag']): ?>
                    <span style="position:absolute;top:6px;right:6px;background:rgba(0,0,0,0.55);color:rgba(255,255,255,0.8);font-size:0.6rem;padding:0.15rem 0.45rem;border-radius:20px;"><?= e($prod['category_tag']) ?></span>
                  <?php endif; ?>
                </div>
                <!-- Details -->
                <div style="padding:0.75rem;flex:1;display:flex;flex-direction:column;">
                  <div style="font-size:0.82rem;font-weight:500;color:var(--white);margin-bottom:0.3rem;line-height:1.35;"><?= e(mb_substr($prod['name'], 0, 60)) ?></div>
                  <?php if ($prod['description']): ?>
                    <p style="font-size:0.73rem;color:var(--muted-2);line-height:1.5;flex:1;margin-bottom:0.5rem;"><?= e(mb_substr($prod['description'], 0, 80)) ?><?= mb_strlen($prod['description']) > 80 ? '…' : '' ?></p>
                  <?php endif; ?>
                  <div style="display:flex;align-items:baseline;gap:0.35rem;flex-wrap:wrap;">
                    <?php if ($prod['sale_price']): ?>
                      <span style="font-size:0.88rem;font-weight:700;color:#00A878;"><?= number_format($prod['sale_price']) ?> XAF</span>
                      <span style="font-size:0.7rem;color:var(--muted-2);text-decoration:line-through;"><?= number_format($prod['price']) ?></span>
                    <?php else: ?>
                      <span style="font-size:0.88rem;font-weight:700;color:var(--yellow);"><?= number_format($prod['price']) ?> XAF</span>
                    <?php endif; ?>
                  </div>
                  <div style="font-size:0.68rem;color:var(--green);margin-top:0.4rem;"><?= t('View details →','Voir les détails →') ?></div>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
          <!-- Owner: link to manage -->
          <?php if ($cu && ($cu['id'] == $l['user_id'] || isAdmin())): ?>
            <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border);">
              <a href="<?= SITE_URL ?>/manage-products?listing_id=<?= $l['id'] ?>" class="btn btn-outline btn-sm">
                ✏️ <?= t('Manage Products/Services','Gérer les Produits/Services') ?>
              </a>
            </div>
          <?php endif; ?>
        </div>
        <?php elseif ($l['featured'] && $cu && ($cu['id'] == $l['user_id'] || isAdmin())): ?>
        <div class="listing-widget" style="border-color:rgba(0,168,120,0.2);background:rgba(0,168,120,0.02);">
          <h4 style="margin-bottom:0.5rem;">🛍️ <?= t('Products & Services','Produits & Services') ?></h4>
          <p style="font-size:0.83rem;color:var(--muted);margin-bottom:0.75rem;"><?= t('Showcase what you sell directly on your listing.','Présentez ce que vous vendez directement sur votre annonce.') ?></p>
          <a href="<?= SITE_URL ?>/manage-products?listing_id=<?= $l['id'] ?>" class="btn btn-primary btn-sm">+ <?= t('Add Products/Services','Ajouter des Produits/Services') ?></a>
        </div>
        <?php endif; ?>

        <!-- Enquiry form -->
        <?php if ($enquiryTableExists && ($l['email'] || $l['user_id'])): ?>
        <div class="listing-widget" id="enquiry-form">
          <h4>📬 <?= t('Send an Enquiry','Envoyer une Demande') ?></h4>
          <p style="font-size:0.83rem;color:var(--muted-2);margin-bottom:1.25rem;">
            <?= t('Your message goes directly to this business. Your email is never shown publicly.','Votre message va directement à cette entreprise. Votre email n\'est jamais affiché publiquement.') ?>
          </p>
          <form method="POST" action="<?= SITE_URL ?>/enquiry.php">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="listing_id" value="<?= $l['id'] ?>">
            <div style="position:absolute;left:-9999px;opacity:0;height:0;" aria-hidden="true">
              <input type="text" name="website_url" tabindex="-1" autocomplete="off" value="">
            </div>
            <div class="form-row">
              <div class="form-group">
                <label><?= t('Your Name *','Votre Nom *') ?></label>
                <input type="text" name="sender_name" id="enquiry-name" required
                       value="<?= e($cu ? $cu['name'] : '') ?>"
                       placeholder="<?= t('Full name','Nom complet') ?>">
              </div>
              <div class="form-group">
                <label><?= t('Your Email *','Votre Email *') ?></label>
                <input type="email" name="sender_email" required
                       value="<?= e($cu ? $cu['email'] : '') ?>"
                       placeholder="you@example.com">
              </div>
            </div>
            <div class="form-group">
              <label><?= t('Phone (optional)','Téléphone (optionnel)') ?></label>
              <input type="tel" name="sender_phone"
                     value="<?= e($cu ? ($cu['phone'] ?? '') : '') ?>"
                     placeholder="+237 6XX XXX XXX">
            </div>
            <div class="form-group">
              <label><?= t('Message *','Message *') ?></label>
              <textarea name="message" rows="4" required
                        placeholder="<?= t('Hi, I found your business on 237Biz and would like to enquire about...','Bonjour, j\'ai trouvé votre entreprise sur 237Biz et je voudrais me renseigner sur...') ?>"></textarea>
            </div>
            <button type="submit" class="btn btn-primary btn-full">
              📬 <?= t('Send Enquiry','Envoyer la Demande') ?>
            </button>
            <p style="font-size:0.72rem;color:var(--muted-2);margin-top:0.6rem;text-align:center;">
              🔒 <?= t('Your email is never shown publicly.','Votre email n\'est jamais affiché publiquement.') ?>
            </p>
          </form>
        </div>
        <?php endif; ?>

        <!-- Share this listing -->
        <div class="listing-widget">
          <div style="font-size:0.78rem;color:var(--muted-2);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.75rem;"><?= t('Share this listing','Partager cette annonce') ?></div>
          <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
            <a href="https://wa.me/?text=<?= urlencode($l['title'] . ' on 237Biz — ' . SITE_URL . '/listing/' . $l['slug']) ?>"
               target="_blank" rel="noopener"
               class="btn btn-sm" style="background:rgba(37,211,102,0.12);color:#25D366;border:1px solid rgba(37,211,102,0.25);flex:1;text-align:center;">
              💬 WhatsApp
            </a>
            <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode(SITE_URL . '/listing/' . $l['slug']) ?>"
               target="_blank" rel="noopener"
               class="btn btn-sm" style="background:rgba(24,119,242,0.12);color:#1877f2;border:1px solid rgba(24,119,242,0.25);flex:1;text-align:center;">
              📘 Facebook
            </a>
            <button onclick="navigator.clipboard.writeText('<?= SITE_URL . '/listing/' . e($l['slug']) ?>').then(function(){var b=this;b.textContent='✓ Copied!';setTimeout(function(){b.textContent='🔗 Copy link'},2000)}.bind(this))"
                    class="btn btn-sm btn-outline" style="flex:1;">🔗 Copy link</button>
          </div>
        </div>

      </div><!-- end main content -->

      <!-- ── SIDEBAR ── -->
      <aside>

        <!-- Contact -->
        <div class="listing-widget">
          <h4>📞 <?= t('Contact','Contact') ?></h4>
          <?php if ($l['phone']): ?>
            <div class="contact-item">
              <span class="contact-icon">📞</span>
              <a href="tel:<?= e($l['phone']) ?>" style="color:var(--muted);"><?= e($l['phone']) ?></a>
            </div>
          <?php endif; ?>
          <?php if ($l['whatsapp']): ?>
            <div class="contact-item">
              <span class="contact-icon">💬</span>
              <a href="https://wa.me/<?= e(preg_replace('/\D/','',$l['whatsapp'])) ?>" target="_blank" style="color:#25D366;">WhatsApp</a>
            </div>
          <?php endif; ?>
          <?php if ($l['email']): ?>
            <div class="contact-item">
              <span class="contact-icon">✉️</span>
              <a href="#enquiry-form"
                 onclick="document.getElementById('enquiry-form')?.scrollIntoView({behavior:'smooth'});document.getElementById('enquiry-name')?.focus();"
                 style="color:var(--green);">
                <?= t('Send a Message →','Envoyer un Message →') ?>
              </a>
            </div>
          <?php endif; ?>
          <?php if ($l['website']): ?>
            <div class="contact-item">
              <span class="contact-icon">🌐</span>
              <a href="<?= e($l['website']) ?>" target="_blank" rel="noopener" style="color:var(--green);">
                <?= e(parse_url($l['website'], PHP_URL_HOST) ?? $l['website']) ?>
              </a>
            </div>
          <?php endif; ?>
          <?php if ($l['facebook']): ?>
            <div class="contact-item">
              <span class="contact-icon">📘</span>
              <a href="<?= e($l['facebook']) ?>" target="_blank" style="color:var(--muted);">Facebook</a>
            </div>
          <?php endif; ?>
          <?php if (!empty($l['tiktok'])): ?>
            <div class="contact-item">
              <span class="contact-icon">🎵</span>
              <a href="<?= e($l['tiktok']) ?>" target="_blank" style="color:var(--muted);">TikTok</a>
            </div>
          <?php endif; ?>
          <?php if (!empty($l['instagram'])): ?>
            <div class="contact-item">
              <span class="contact-icon">📷</span>
              <a href="<?= e($l['instagram']) ?>" target="_blank" style="color:var(--muted);">Instagram</a>
            </div>
          <?php endif; ?>
          <?php if ($l['address']): ?>
            <div class="contact-item">
              <span class="contact-icon">📍</span>
              <span style="color:var(--muted);"><?= e($l['address']) ?></span>
            </div>
          <?php endif; ?>
        </div>

        <!-- Location -->
        <div class="listing-widget">
          <h4>📍 <?= t('Location','Localisation') ?></h4>
          <p style="color:var(--muted);font-size:0.875rem;"><?= e($l['loc_en']) ?>, Cameroon</p>
          <?php if ($l['lat'] && $l['lng']): ?>
            <div style="border-radius:10px;overflow:hidden;margin-top:0.75rem;">
              <iframe src="https://www.google.com/maps?q=<?= $l['lat'] ?>,<?= $l['lng'] ?>&output=embed"
                      width="100%" height="180" style="border:none;display:block;" loading="lazy"></iframe>
            </div>
          <?php elseif ($l['address']): ?>
            <div style="border-radius:10px;overflow:hidden;margin-top:0.75rem;">
              <iframe src="https://www.google.com/maps?q=<?= urlencode($l['address'] . ', ' . $l['loc_en'] . ', Cameroon') ?>&output=embed"
                      width="100%" height="180" style="border:none;display:block;" loading="lazy"></iframe>
            </div>
          <?php endif; ?>
          <a href="<?= SITE_URL ?>/location/<?= e($l['loc_slug']) ?>" class="btn btn-outline btn-sm" style="margin-top:0.75rem;display:inline-flex;">
            <?= t('More in','Plus à') ?> <?= e($l['loc_en']) ?> →
          </a>
        </div>

        <!-- Share -->
        <div class="listing-widget">
          <h4>🔗 <?= t('Share this Listing','Partager cette Annonce') ?></h4>
          <div style="display:flex;flex-direction:column;gap:0.5rem;">
            <button onclick="shareTikTok('<?= e(addslashes($l['title'])) ?>', window.location.href)"
                    class="btn btn-sm" style="background:rgba(0,0,0,0.4);color:#fff;border:1px solid rgba(255,255,255,0.15);display:flex;align-items:center;gap:0.6rem;justify-content:center;">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="white"><path d="M19.59 6.69a4.83 4.83 0 01-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 01-2.88 2.5 2.89 2.89 0 01-2.89-2.89 2.89 2.89 0 012.89-2.89c.28 0 .54.04.79.1V9.01a6.33 6.33 0 00-.79-.05 6.34 6.34 0 00-6.34 6.34 6.34 6.34 0 006.34 6.34 6.34 6.34 0 006.33-6.34V8.69a8.18 8.18 0 004.78 1.52V6.74a4.85 4.85 0 01-1.01-.05z"/></svg>
              TikTok
            </button>
            <button onclick="shareInstagram('<?= e(addslashes($l['title'])) ?>', window.location.href)"
                    class="btn btn-sm" style="background:linear-gradient(135deg,rgba(131,58,180,0.25),rgba(253,29,29,0.25),rgba(252,176,69,0.25));color:#fff;border:1px solid rgba(255,255,255,0.15);display:flex;align-items:center;gap:0.6rem;justify-content:center;">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="white"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>
              Instagram
            </button>
            <button onclick="shareFacebook(window.location.href)"
                    class="btn btn-sm" style="background:rgba(24,119,242,0.15);color:#1877f2;border:1px solid rgba(24,119,242,0.25);display:flex;align-items:center;gap:0.6rem;justify-content:center;">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="#1877f2"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
              Facebook
            </button>
            <button id="copy-link-btn" onclick="copyLink(window.location.href)"
                    class="btn btn-outline btn-sm" style="display:flex;align-items:center;gap:0.6rem;justify-content:center;">
              📋 <?= t('Copy Link','Copier le lien') ?>
            </button>
          </div>
        </div>

        <!-- Claim -->
        <?php if (!$l['user_id'] || !$cu || $l['user_id'] != $cu['id']): ?>
        <div class="listing-widget" style="background:rgba(245,200,66,0.03);border-color:rgba(245,200,66,0.15);">
          <h4 style="font-size:0.9rem;"><?= t('Is this your business?','Est-ce votre entreprise ?') ?></h4>
          <p style="font-size:0.8rem;color:var(--muted-2);margin-bottom:0.75rem;line-height:1.5;">
            <?= t('Claim ownership to manage your listing.','Revendiquez la propriété pour gérer votre annonce.') ?>
          </p>
          <a href="<?= SITE_URL ?>/claim-listing?slug=<?= e($l['slug']) ?>" class="btn btn-outline btn-sm btn-full">
            🏢 <?= t('Claim this Listing','Revendiquer cette Annonce') ?>
          </a>
        </div>
        <?php endif; ?>

        <!-- Owner actions -->
        <?php if ($cu && ($cu['id'] == $l['user_id'] || isAdmin())): ?>
        <div class="listing-widget">
          <h4><?= t('Manage Listing','Gérer l\'annonce') ?></h4>
          <a href="<?= SITE_URL ?>/edit-listing?id=<?= $l['id'] ?>" class="btn btn-primary btn-full" style="margin-bottom:0.5rem;">
            <?= t('Edit Listing','Modifier l\'annonce') ?>
          </a>
          <?php if (!$l['featured']): ?>
            <a href="<?= SITE_URL ?>/upgrade-listing?listing_id=<?= $l['id'] ?>" class="btn btn-full" style="background:rgba(245,200,66,0.15);color:var(--yellow);border:1px solid rgba(245,200,66,0.3);">
              ⭐ <?= t('Upgrade to Featured','Passer à la Vedette') ?>
            </a>
          <?php endif; ?>
        </div>
        <?php endif; ?>

      </aside>

    </div><!-- end listing-grid -->

    <!-- Similar listings -->
    <?php if ($similar): ?>
      <div style="margin-top:4rem;">
        <h3 style="font-family:'Fraunces',serif;font-size:1.5rem;margin-bottom:1.5rem;"><?= t('Similar Businesses','Entreprises similaires') ?></h3>
        <div class="listings-grid">
          <?php foreach ($similar as $s): ?>
            <a href="<?= SITE_URL ?>/listing/<?= e($s['slug']) ?>" class="listing-card" style="text-decoration:none;color:inherit;">
              <div class="listing-top">
                <div class="listing-logo"><?= $s['cat_icon'] ?></div>
                <div>
                  <div class="listing-name"><?= e($s['title']) ?></div>
                  <div class="listing-category"><?= e($s['cat_en']) ?></div>
                </div>
              </div>
              <p class="listing-desc"><?= e(mb_substr($s['description'] ?? '', 0, 100)) ?>...</p>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

  </div>
</section>

<!-- TikTok share modal -->
<div id="tiktok-share-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.8);z-index:600;align-items:center;justify-content:center;padding:1rem;">
  <div style="background:#122B1C;border:1px solid var(--border);border-radius:16px;padding:2rem;width:100%;max-width:420px;">
    <h3 style="font-family:'Fraunces',serif;font-weight:700;margin-bottom:1rem;">TikTok</h3>
    <ol style="list-style:decimal;padding-left:1.25rem;display:flex;flex-direction:column;gap:0.5rem;margin-bottom:1.25rem;">
      <li style="font-size:0.85rem;color:var(--muted);"><?= t('Caption copied to clipboard','Légende copiée dans le presse-papiers') ?></li>
      <li style="font-size:0.85rem;color:var(--muted);"><?= t('Open TikTok and record a video','Ouvrez TikTok et enregistrez une vidéo') ?></li>
      <li style="font-size:0.85rem;color:var(--muted);"><?= t('Paste the caption and post','Collez la légende et publiez') ?></li>
    </ol>
    <textarea id="tiktok-share-text" rows="3" readonly style="width:100%;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:6px;color:rgba(255,255,255,0.7);font-size:0.8rem;padding:0.75rem;resize:none;margin-bottom:1rem;"></textarea>
    <div style="display:flex;gap:0.75rem;">
      <a href="https://www.tiktok.com/" target="_blank" rel="noopener" style="flex:1;display:flex;align-items:center;justify-content:center;background:#000;color:#fff;padding:0.75rem;border-radius:6px;text-decoration:none;font-size:0.875rem;font-weight:500;">Open TikTok</a>
      <button onclick="document.getElementById('tiktok-share-modal').style.display='none'" class="btn btn-outline" style="flex:1;"><?= t('Close','Fermer') ?></button>
    </div>
  </div>
</div>

<!-- Instagram share modal -->
<div id="instagram-share-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.8);z-index:600;align-items:center;justify-content:center;padding:1rem;">
  <div style="background:#122B1C;border:1px solid var(--border);border-radius:16px;padding:2rem;width:100%;max-width:420px;">
    <h3 style="font-family:'Fraunces',serif;font-weight:700;margin-bottom:1rem;">Instagram</h3>
    <p style="font-size:0.85rem;color:var(--muted);margin-bottom:1rem;"><?= t('Link copied! Here\'s how to share on Instagram:','Lien copié ! Voici comment partager sur Instagram :') ?></p>
    <ol style="list-style:decimal;padding-left:1.25rem;display:flex;flex-direction:column;gap:0.5rem;margin-bottom:1.5rem;">
      <li style="font-size:0.85rem;color:var(--muted);"><?= t('Open Instagram on your phone','Ouvrez Instagram sur votre téléphone') ?></li>
      <li style="font-size:0.85rem;color:var(--muted);"><?= t('Add to your bio link or Stories link sticker','Ajoutez à votre lien bio ou autocollant Stories') ?></li>
    </ol>
    <div style="display:flex;gap:0.75rem;">
      <a href="https://www.instagram.com/" target="_blank" rel="noopener" style="flex:1;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#833ab4,#fd1d1d,#fcb045);color:#fff;padding:0.75rem;border-radius:6px;text-decoration:none;font-size:0.875rem;font-weight:500;">Open Instagram</a>
      <button onclick="document.getElementById('instagram-share-modal').style.display='none'" class="btn btn-outline" style="flex:1;"><?= t('Close','Fermer') ?></button>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
