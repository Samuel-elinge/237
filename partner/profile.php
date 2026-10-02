<?php
/**
 * partner/profile.php — Public-facing Growth Partner profile
 * Route: /partner/profile?code=REF123
 * No login required — publicly visible (if partner has public_profile=1)
 */
require_once __DIR__ . '/../includes/config.php';

$pdo  = db();
$code = trim($_GET['code'] ?? '');

if (!$code) { redirect(SITE_URL . '/partners'); }

// Load partner by referral_code
$stmt = $pdo->prepare("
    SELECT pp.*, u.name AS user_name, u.email AS user_email,
           pt.name AS tier_name, pt.slug AS tier_slug
    FROM partner_profiles pp
    JOIN users u ON u.id = pp.user_id
    LEFT JOIN partner_tiers pt ON pt.id = pp.tier_id
    WHERE pp.referral_code = ? AND pp.public_profile = 1
      AND pp.status IN ('approved','active')
    LIMIT 1
");
$stmt->execute([$code]);
$partner = $stmt->fetch();

if (!$partner) {
    redirect(SITE_URL . '/partners');
}

$pid = $partner['id'];

// Certifications
$certs = $pdo->prepare("
    SELECT pc.name, pc.icon, pc.badge_color, pc.level, pca.awarded_at
    FROM partner_cert_awards pca
    JOIN partner_certifications pc ON pc.id = pca.cert_id
    WHERE pca.partner_id = ? AND pca.revoked = 0
    ORDER BY pc.sort_order
");
$certs->execute([$pid]);
$certList = $certs->fetchAll();

// Locations
$locs = $pdo->prepare("
    SELECT * FROM partner_locations WHERE partner_id = ? ORDER BY is_primary DESC, region
");
$locs->execute([$pid]);
$locations = $locs->fetchAll();

// Active businesses managed count
$bizCount = $pdo->prepare("
    SELECT COUNT(*) FROM partner_business_assignments WHERE partner_id = ? AND status = 'active'
");
$bizCount->execute([$pid]);
$managedCount = (int)$bizCount->fetchColumn();

// Recent feedback (published)
$fbStmt = $pdo->prepare("
    SELECT pf.rating, pf.comment, pf.created_at, l.title AS business_name
    FROM partner_feedback pf
    LEFT JOIN listings l ON l.id = pf.listing_id
    WHERE pf.partner_id = ? AND pf.published = 1
    ORDER BY pf.created_at DESC
    LIMIT 5
");
$fbStmt->execute([$pid]);
$feedback = $fbStmt->fetchAll();

// Specialisms (stored as comma-separated or JSON)
$specialisms = [];
if ($partner['specialisms']) {
    $dec = json_decode($partner['specialisms'], true);
    $specialisms = is_array($dec) ? $dec : array_filter(array_map('trim', explode(',', $partner['specialisms'])));
}

// Services offered
$services = [];
if ($partner['services_offered']) {
    $dec = json_decode($partner['services_offered'], true);
    $services = is_array($dec) ? $dec : array_filter(array_map('trim', explode(',', $partner['services_offered'])));
}

// Display name
$displayName = $partner['display_name'] ?: $partner['user_name'];
$orgName     = $partner['organisation'] ?? '';

// Tier badge colour
$tierColors = ['solo' => '#16a34a', 'agency' => '#2563eb', 'strategic' => '#7c3aed'];
$tierColor  = $tierColors[$partner['tier_slug'] ?? 'solo'] ?? '#16a34a';

// Rating
$rating      = $partner['rating'] ? number_format((float)$partner['rating'], 1) : null;
$ratingCount = (int)($partner['rating_count'] ?? 0);

// Track profile view (simple increment, no login required)
$pdo->prepare("UPDATE partner_profiles SET profile_views = profile_views + 1 WHERE id = ?")
    ->execute([$pid]);

$pageTitle = e($displayName) . ' — 237Biz Growth Partner';
$pageDesc  = $partner['tagline'] ?: 'Growth Partner helping Cameroonian businesses grow with 237Biz.';

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ── Partner Profile Page ───────────────────────────────── */
.pp-wrap {
  max-width: 960px;
  margin: 88px auto 60px;
  padding: 0 20px;
}

/* Hero card */
.pp-hero {
  background: var(--card-bg, #fff);
  border: 1px solid var(--border, #e5e7eb);
  border-radius: 16px;
  padding: 36px;
  display: flex;
  gap: 28px;
  align-items: flex-start;
  margin-bottom: 24px;
}
.pp-avatar {
  flex-shrink: 0;
  width: 96px; height: 96px;
  border-radius: 50%;
  background: linear-gradient(135deg, #059669 0%, #10b981 100%);
  display: flex; align-items: center; justify-content: center;
  font-size: 36px; font-weight: 700; color: #fff;
  overflow: hidden;
}
.pp-avatar img { width: 100%; height: 100%; object-fit: cover; }
.pp-meta { flex: 1; min-width: 0; }
.pp-name { font-size: 1.75rem; font-weight: 700; color: var(--heading, #0a1628); margin: 0 0 4px; }
.pp-org  { font-size: 0.95rem; color: var(--muted, #6b7280); margin: 0 0 10px; }
.pp-tagline { font-size: 1.05rem; color: var(--text, #374151); margin: 0 0 14px; }
.pp-badges  { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
.pp-badge {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 4px 10px; border-radius: 20px;
  font-size: 0.78rem; font-weight: 600;
  background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0;
}
.pp-badge.tier {
  background: #eff6ff; border-color: #bfdbfe;
}
.pp-badge.verified {
  background: #f0fdf4; color: #15803d; border-color: #86efac;
}
.pp-stats {
  display: flex; gap: 20px; flex-wrap: wrap;
  margin-top: 10px;
}
.pp-stat { text-align: center; }
.pp-stat .val { font-size: 1.4rem; font-weight: 700; color: var(--heading, #0a1628); display: block; }
.pp-stat .lbl { font-size: 0.72rem; color: var(--muted, #6b7280); text-transform: uppercase; letter-spacing: 0.05em; }
.pp-cta { flex-shrink: 0; }
.btn-request {
  display: inline-block;
  background: #059669; color: #fff;
  padding: 12px 24px; border-radius: 10px;
  font-weight: 600; font-size: 0.95rem;
  text-decoration: none;
  transition: background 0.2s;
}
.btn-request:hover { background: #047857; }

/* Content grid */
.pp-grid {
  display: grid;
  grid-template-columns: 2fr 1fr;
  gap: 20px;
}
.pp-card {
  background: var(--card-bg, #fff);
  border: 1px solid var(--border, #e5e7eb);
  border-radius: 12px;
  padding: 24px;
  margin-bottom: 20px;
}
.pp-card h3 {
  font-size: 1rem; font-weight: 700;
  color: var(--heading, #0a1628);
  margin: 0 0 16px;
  padding-bottom: 10px;
  border-bottom: 1px solid var(--border, #e5e7eb);
}
.pp-card p { color: var(--text, #374151); line-height: 1.7; margin: 0; }

/* Specialisms chips */
.chip-list { display: flex; flex-wrap: wrap; gap: 8px; }
.chip {
  background: #f3f4f6; color: #374151;
  padding: 5px 12px; border-radius: 20px;
  font-size: 0.82rem; font-weight: 500;
}

/* Certifications */
.cert-item {
  display: flex; align-items: center; gap: 12px;
  padding: 10px 0;
  border-bottom: 1px solid var(--border, #f3f4f6);
}
.cert-item:last-child { border-bottom: none; }
.cert-icon {
  width: 36px; height: 36px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 18px; flex-shrink: 0;
  background: #f0fdf4;
}
.cert-info .cn { font-weight: 600; font-size: 0.9rem; color: var(--heading, #0a1628); }
.cert-info .cl { font-size: 0.75rem; color: var(--muted, #6b7280); text-transform: capitalize; }

/* Locations */
.loc-item {
  display: flex; align-items: center; gap: 8px;
  padding: 6px 0;
  font-size: 0.9rem; color: var(--text, #374151);
}
.loc-primary-dot {
  width: 8px; height: 8px; border-radius: 50%;
  background: #059669; flex-shrink: 0;
}
.loc-dot {
  width: 8px; height: 8px; border-radius: 50%;
  background: #d1d5db; flex-shrink: 0;
}

/* Feedback */
.fb-item {
  padding: 14px 0;
  border-bottom: 1px solid var(--border, #f3f4f6);
}
.fb-item:last-child { border-bottom: none; }
.fb-stars { color: #f59e0b; font-size: 0.9rem; margin-bottom: 4px; }
.fb-comment { font-size: 0.88rem; color: var(--text, #374151); line-height: 1.6; margin: 4px 0; }
.fb-biz { font-size: 0.78rem; color: var(--muted, #6b7280); }

/* Rating stars display */
.rating-big {
  display: flex; align-items: center; gap: 10px;
  margin: 8px 0;
}
.stars-big { color: #f59e0b; font-size: 1.3rem; }
.stars-big-label { font-size: 1.2rem; font-weight: 700; color: var(--heading, #0a1628); }
.stars-big-count { font-size: 0.82rem; color: var(--muted, #6b7280); }

/* No content state */
.empty-state {
  text-align: center;
  padding: 20px 0;
  color: var(--muted, #9ca3af);
  font-size: 0.9rem;
}

/* Referral CTA box */
.pp-referral {
  background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
  border: 1px solid #6ee7b7;
  border-radius: 12px;
  padding: 20px;
  text-align: center;
  margin-bottom: 20px;
}
.pp-referral h4 { font-size: 1rem; font-weight: 700; color: #065f46; margin: 0 0 6px; }
.pp-referral p  { font-size: 0.85rem; color: #047857; margin: 0 0 12px; }
.ref-code-box {
  background: #fff;
  border: 1px solid #6ee7b7;
  border-radius: 8px;
  padding: 8px 14px;
  font-family: monospace;
  font-size: 1rem;
  font-weight: 700;
  color: #065f46;
  letter-spacing: 0.05em;
  display: inline-block;
  cursor: pointer;
  user-select: all;
}

/* Responsive */
@media (max-width: 720px) {
  .pp-hero { flex-direction: column; }
  .pp-cta  { width: 100%; }
  .btn-request { width: 100%; text-align: center; }
  .pp-grid { grid-template-columns: 1fr; }
  .pp-stats { gap: 14px; }
}
</style>

<div class="pp-wrap">

  <!-- ── Hero card ─────────────────────────────────────── -->
  <div class="pp-hero">
    <div class="pp-avatar">
      <?php if ($partner['avatar_url']): ?>
        <img src="<?= e($partner['avatar_url']) ?>" alt="<?= e($displayName) ?>">
      <?php else: ?>
        <?= mb_strtoupper(mb_substr($displayName, 0, 1)) ?>
      <?php endif; ?>
    </div>

    <div class="pp-meta">
      <div class="pp-name"><?= e($displayName) ?></div>
      <?php if ($orgName): ?>
        <div class="pp-org">🏢 <?= e($orgName) ?></div>
      <?php endif; ?>
      <?php if ($partner['tagline']): ?>
        <div class="pp-tagline"><?= e($partner['tagline']) ?></div>
      <?php endif; ?>

      <div class="pp-badges">
        <?php if ($partner['tier_name']): ?>
          <span class="pp-badge tier" style="color:<?= e($tierColor) ?>;border-color:<?= e($tierColor) ?>30;background:<?= e($tierColor) ?>12">
            <?= $partner['tier_slug'] === 'strategic' ? '🏛️' : ($partner['tier_slug'] === 'agency' ? '🏢' : '👤') ?>
            <?= e($partner['tier_name']) ?>
          </span>
        <?php endif; ?>
        <?php if ($partner['verified']): ?>
          <span class="pp-badge verified">✅ Verified Partner</span>
        <?php endif; ?>
        <span class="pp-badge" style="background:#fef9c3;color:#92400e;border-color:#fde68a">
          <?= $partner['capacity_status'] === 'accepting' ? '🟢 Accepting clients' : ($partner['capacity_status'] === 'limited' ? '🟡 Limited availability' : '🔴 Fully booked') ?>
        </span>
      </div>

      <div class="pp-stats">
        <div class="pp-stat">
          <span class="val"><?= $managedCount ?></span>
          <span class="lbl">Businesses</span>
        </div>
        <?php if ($rating): ?>
          <div class="pp-stat">
            <span class="val"><?= $rating ?> ⭐</span>
            <span class="lbl"><?= $ratingCount ?> review<?= $ratingCount !== 1 ? 's' : '' ?></span>
          </div>
        <?php endif; ?>
        <?php if ($certList): ?>
          <div class="pp-stat">
            <span class="val"><?= count($certList) ?></span>
            <span class="lbl">Certifications</span>
          </div>
        <?php endif; ?>
        <?php if ($partner['profile_views'] > 10): ?>
          <div class="pp-stat">
            <span class="val"><?= number_format((int)$partner['profile_views']) ?></span>
            <span class="lbl">Profile views</span>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($partner['capacity_status'] !== 'full'): ?>
    <div class="pp-cta">
      <a href="<?= SITE_URL ?>/partner-request?partner=<?= urlencode($code) ?>" class="btn-request">
        Request This Partner →
      </a>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── Two-column grid ────────────────────────────────── -->
  <div class="pp-grid">

    <!-- Left column: Bio, Specialisms, Services, Feedback -->
    <div>
      <?php if ($partner['bio']): ?>
      <div class="pp-card">
        <h3>About</h3>
        <p><?= nl2br(e($partner['bio'])) ?></p>
      </div>
      <?php endif; ?>

      <?php if ($specialisms): ?>
      <div class="pp-card">
        <h3>Specialisms</h3>
        <div class="chip-list">
          <?php foreach ($specialisms as $s): ?>
            <span class="chip"><?= e($s) ?></span>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($services): ?>
      <div class="pp-card">
        <h3>Services Offered</h3>
        <div class="chip-list">
          <?php foreach ($services as $s): ?>
            <span class="chip">✓ <?= e($s) ?></span>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($feedback): ?>
      <div class="pp-card">
        <h3>Business Reviews</h3>
        <?php if ($rating): ?>
          <div class="rating-big">
            <span class="stars-big">
              <?php for ($i = 1; $i <= 5; $i++): ?>
                <?= $i <= round((float)$rating) ? '★' : '☆' ?>
              <?php endfor; ?>
            </span>
            <span class="stars-big-label"><?= $rating ?></span>
            <span class="stars-big-count">(<?= $ratingCount ?> review<?= $ratingCount !== 1 ? 's' : '' ?>)</span>
          </div>
        <?php endif; ?>
        <?php foreach ($feedback as $fb): ?>
          <div class="fb-item">
            <div class="fb-stars">
              <?php for ($i = 1; $i <= 5; $i++) echo $i <= (int)$fb['rating'] ? '★' : '☆'; ?>
            </div>
            <?php if ($fb['comment']): ?>
              <div class="fb-comment">"<?= e($fb['comment']) ?>"</div>
            <?php endif; ?>
            <div class="fb-biz">
              <?= $fb['business_name'] ? '— ' . e($fb['business_name']) . ' · ' : '' ?>
              <?= date('M Y', strtotime($fb['created_at'])) ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Right column: Certifications, Locations, Referral -->
    <div>
      <?php if ($certList): ?>
      <div class="pp-card">
        <h3>Certifications</h3>
        <?php foreach ($certList as $cert): ?>
          <div class="cert-item">
            <div class="cert-icon" style="background:<?= e($cert['badge_color'] ?? '#7c3aed') ?>18">
              <?= e($cert['icon'] ?? '🏅') ?>
            </div>
            <div class="cert-info">
              <div class="cn"><?= e($cert['name']) ?></div>
              <div class="cl"><?= e($cert['level']) ?> · Awarded <?= date('M Y', strtotime($cert['awarded_at'])) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if ($locations): ?>
      <div class="pp-card">
        <h3>Coverage Areas</h3>
        <?php foreach ($locations as $loc): ?>
          <div class="loc-item">
            <div class="<?= $loc['is_primary'] ? 'loc-primary-dot' : 'loc-dot' ?>"></div>
            <span><?= e($loc['region']) ?><?= $loc['city'] ? ', ' . e($loc['city']) : '' ?></span>
            <?php if ($loc['is_primary']): ?>
              <span style="font-size:0.72rem;color:#059669;font-weight:600">Primary</span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if ($partner['experience']): ?>
      <div class="pp-card">
        <h3>Experience</h3>
        <p style="font-size:0.88rem"><?= nl2br(e($partner['experience'])) ?></p>
      </div>
      <?php endif; ?>

      <!-- Referral code -->
      <div class="pp-referral">
        <h4>Work with <?= e(explode(' ', $displayName)[0]) ?>?</h4>
        <p>Use this partner's referral code when listing your business to connect automatically.</p>
        <div class="ref-code-box" onclick="navigator.clipboard.writeText('<?= e($code) ?>');this.textContent='Copied!';setTimeout(()=>this.textContent='<?= e($code) ?>',1500)">
          <?= e($code) ?>
        </div>
      </div>

      <!-- Back to marketplace -->
      <div style="text-align:center;margin-top:10px">
        <a href="<?= SITE_URL ?>/partners" style="font-size:0.85rem;color:var(--muted,#6b7280);text-decoration:none">
          ← Browse all partners
        </a>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
