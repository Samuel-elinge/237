<?php
/**
 * partner/onboarding.php — Multi-step Growth Partner Application
 * Route: /partner/onboarding
 * Requires login. Redirects if already an approved partner.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';
require_once __DIR__ . '/../includes/partner-lang.php';

if (!isLoggedIn()) {
    redirect(SITE_URL . '/login?next=' . urlencode(SITE_URL . '/partner/onboarding'));
}

$pdo  = db();
$user = currentUser();
$uid  = (int)$user['id'];

// Already an active partner? Go to dashboard.
$existing = $pdo->prepare("SELECT * FROM partner_profiles WHERE user_id = ?");
$existing->execute([$uid]);
$profile = $existing->fetch();

if ($profile && in_array($profile['status'], ['approved','active'])) {
    redirect(SITE_URL . '/partner/dashboard');
}
if ($profile && in_array($profile['status'], ['applicant','pending'])) {
    redirect(SITE_URL . '/partner/pending');
}

// ── Step management ───────────────────────────────────────────────
$step = max(1, min(4, (int)($_GET['step'] ?? 1)));

// Session storage for form data across steps
if (!isset($_SESSION['partner_app'])) {
    $_SESSION['partner_app'] = [];
}

// ── POST handler ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $postStep = (int)($_POST['step'] ?? 1);

    if ($postStep === 1) {
        $_SESSION['partner_app']['display_name'] = trim($_POST['display_name'] ?? '');
        $_SESSION['partner_app']['organisation']  = trim($_POST['organisation'] ?? '');
        $_SESSION['partner_app']['phone']         = trim($_POST['phone'] ?? '');
        $_SESSION['partner_app']['tagline']       = trim($_POST['tagline'] ?? '');
        $_SESSION['partner_app']['bio']           = trim($_POST['bio'] ?? '');
        redirect(SITE_URL . '/partner/onboarding?step=2');
    }

    if ($postStep === 2) {
        $specialisms = array_filter(array_map('trim', explode(',', $_POST['specialisms'] ?? '')));
        $services    = array_filter(array_map('trim', explode(',', $_POST['services'] ?? '')));
        $regions     = array_filter(array_map('trim', $_POST['regions'] ?? []));
        $cities      = array_filter(array_map('trim', $_POST['cities'] ?? []));
        $primary_region = trim($_POST['primary_region'] ?? '');

        $_SESSION['partner_app']['specialisms']    = $specialisms;
        $_SESSION['partner_app']['services']       = $services;
        $_SESSION['partner_app']['regions']        = $regions;
        $_SESSION['partner_app']['cities']         = $cities;
        $_SESSION['partner_app']['primary_region'] = $primary_region;
        redirect(SITE_URL . '/partner/onboarding?step=3');
    }

    if ($postStep === 3) {
        $_SESSION['partner_app']['max_businesses']  = max(1, min(50, (int)($_POST['max_businesses'] ?? 5)));
        $_SESSION['partner_app']['experience']      = trim($_POST['experience'] ?? '');
        $_SESSION['partner_app']['why_join']        = trim($_POST['why_join'] ?? '');
        $_SESSION['partner_app']['areas_covered']   = trim($_POST['areas_covered'] ?? '');
        redirect(SITE_URL . '/partner/onboarding?step=4');
    }

    if ($postStep === 4) {
        // Final submission
        $app = $_SESSION['partner_app'];

        // Generate referral code
        do {
            $code = strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', base64_encode(random_bytes(6))), 0, 8));
            $exists = $pdo->prepare("SELECT id FROM partner_profiles WHERE referral_code = ?");
            $exists->execute([$code]);
        } while ($exists->fetchColumn());

        $pdo->beginTransaction();
        try {
            // Upsert partner profile
            if ($profile) {
                // Update existing inactive/terminated record
                $pdo->prepare("UPDATE partner_profiles SET
                    status='applicant', display_name=?, organisation=?, phone=?, tagline=?, bio=?,
                    specialisms=?, services_offered=?, experience=?, why_join=?, areas_covered=?,
                    max_businesses=?, referral_code=?, updated_at=NOW()
                    WHERE user_id=?")
                    ->execute([
                        $app['display_name'] ?: $user['name'],
                        $app['organisation'],
                        $app['phone'],
                        $app['tagline'],
                        $app['bio'],
                        json_encode(array_values($app['specialisms'] ?? [])),
                        json_encode(array_values($app['services'] ?? [])),
                        $app['experience'],
                        $app['why_join'],
                        $app['areas_covered'],
                        $app['max_businesses'],
                        $code,
                        $uid,
                    ]);
                $pid = (int)$profile['id'];
            } else {
                $pdo->prepare("INSERT INTO partner_profiles
                    (user_id, status, display_name, organisation, phone, tagline, bio,
                     specialisms, services_offered, experience, why_join, areas_covered,
                     max_businesses, referral_code, created_at)
                    VALUES (?,  'applicant',?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
                    ->execute([
                        $uid,
                        $app['display_name'] ?: $user['name'],
                        $app['organisation'],
                        $app['phone'],
                        $app['tagline'],
                        $app['bio'],
                        json_encode(array_values($app['specialisms'] ?? [])),
                        json_encode(array_values($app['services'] ?? [])),
                        $app['experience'],
                        $app['why_join'],
                        $app['areas_covered'],
                        $app['max_businesses'],
                        $code,
                    ]);
                $pid = (int)$pdo->lastInsertId();
            }

            // Insert locations
            $pdo->prepare("DELETE FROM partner_locations WHERE partner_id = ?")->execute([$pid]);
            $regions = $app['regions'] ?? [];
            $cities  = $app['cities'] ?? [];
            $primaryR = $app['primary_region'] ?? ($regions[0] ?? '');
            foreach ($regions as $i => $region) {
                if (!$region) continue;
                $pdo->prepare("INSERT INTO partner_locations (partner_id, region, city, is_primary) VALUES (?,?,?,?)")
                    ->execute([$pid, $region, $cities[$i] ?? null, $region === $primaryR ? 1 : 0]);
            }

            // Create verification placeholder
            $pdo->prepare("INSERT IGNORE INTO partner_verifications (partner_id) VALUES (?)")->execute([$pid]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            setFlash('error', 'Something went wrong saving your application. Please try again.');
            redirect(SITE_URL . '/partner/onboarding?step=4');
        }

        // Notify admins
        $adminIds = $pdo->query("SELECT id FROM users WHERE role='admin'")->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($adminIds as $adminId) {
            pushNotification(
                (int)$adminId,
                'partner_application',
                'New Partner Application',
                ($app['display_name'] ?: $user['name']) . ' has applied to become a Growth Partner.',
                SITE_URL . '/admin/manage-growth-partners?tab=applications'
            );
        }

        // Clear session data
        unset($_SESSION['partner_app']);

        setFlash('success', 'Application submitted! We\'ll review it and get back to you within 1–3 business days.');
        redirect(SITE_URL . '/partner/pending');
    }
}

// ── Load session data for display ─────────────────────────────────
$app = $_SESSION['partner_app'];

// Cameroon regions for the location picker
$cameroonRegions = [
    'Adamawa','Centre','East','Far North','Littoral',
    'North','North West','South','South West','West'
];

$pageTitle = pt('Become a Growth Partner') . ' — 237Biz';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.ob-wrap {
  max-width: 720px;
  margin: 88px auto 60px;
  padding: 0 20px;
}

/* Progress bar */
.ob-progress {
  display: flex;
  gap: 0;
  margin-bottom: 36px;
  border-radius: 12px;
  overflow: hidden;
  border: 1px solid var(--border, #e5e7eb);
}
.ob-step {
  flex: 1;
  padding: 12px 8px;
  text-align: center;
  font-size: 0.8rem;
  font-weight: 600;
  background: var(--card-bg, #fff);
  color: var(--muted, #9ca3af);
  position: relative;
  transition: background 0.2s;
}
.ob-step.done    { background: #ecfdf5; color: #059669; }
.ob-step.active  { background: #059669; color: #fff; }
.ob-step .sn     { font-size: 0.7rem; opacity: 0.7; display: block; }

/* Card */
.ob-card {
  background: var(--card-bg, #fff);
  border: 1px solid var(--border, #e5e7eb);
  border-radius: 16px;
  padding: 36px;
}
.ob-card h2 {
  font-family: 'Fraunces', serif;
  font-size: 1.6rem;
  margin: 0 0 6px;
  color: var(--heading, #0a1628);
}
.ob-card .ob-sub {
  color: var(--muted, #6b7280);
  margin: 0 0 28px;
  font-size: 0.93rem;
}

/* Form fields */
.form-group { margin-bottom: 20px; }
.form-group label {
  display: block;
  font-size: 0.85rem;
  font-weight: 600;
  color: var(--heading, #0a1628);
  margin-bottom: 6px;
}
.form-group label .opt { font-weight: 400; color: var(--muted, #9ca3af); }
.form-group input[type=text],
.form-group input[type=tel],
.form-group textarea,
.form-group select {
  width: 100%;
  padding: 10px 14px;
  border: 1px solid var(--border, #d1d5db);
  border-radius: 8px;
  font-size: 0.93rem;
  background: var(--input-bg, #fff);
  color: var(--text, #111827);
  box-sizing: border-box;
  transition: border-color 0.15s;
}
.form-group input:focus,
.form-group textarea:focus,
.form-group select:focus {
  outline: none;
  border-color: #059669;
  box-shadow: 0 0 0 3px rgba(5,150,105,0.12);
}
.form-group textarea { min-height: 100px; resize: vertical; }
.form-hint {
  font-size: 0.78rem;
  color: var(--muted, #9ca3af);
  margin-top: 4px;
}
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
@media (max-width:600px) { .form-row { grid-template-columns: 1fr; } }

/* Tag input simulation */
.tag-input-wrap {
  border: 1px solid var(--border, #d1d5db);
  border-radius: 8px;
  padding: 8px 12px;
  display: flex; flex-wrap: wrap; gap: 6px;
  cursor: text;
  min-height: 44px;
  background: var(--input-bg, #fff);
  transition: border-color 0.15s;
}
.tag-input-wrap:focus-within {
  border-color: #059669;
  box-shadow: 0 0 0 3px rgba(5,150,105,0.12);
}
.tag-pill {
  display: inline-flex; align-items: center; gap: 4px;
  background: #ecfdf5; color: #065f46;
  border: 1px solid #6ee7b7;
  border-radius: 20px; padding: 3px 10px;
  font-size: 0.78rem; font-weight: 600;
}
.tag-pill button {
  background: none; border: none; cursor: pointer;
  color: #059669; padding: 0; font-size: 0.9rem; line-height: 1;
}
.tag-input-field {
  border: none; outline: none; font-size: 0.88rem;
  flex: 1; min-width: 120px; padding: 2px;
  background: transparent;
  color: var(--text, #111827);
}

/* Location row */
.loc-row {
  display: grid; grid-template-columns: 1fr 1fr auto; gap: 10px;
  align-items: end; margin-bottom: 10px;
}
.loc-row select, .loc-row input { margin: 0; }
.btn-remove-loc {
  background: #fee2e2; color: #dc2626; border: none;
  border-radius: 6px; width: 36px; height: 36px;
  cursor: pointer; font-size: 1rem;
  display: flex; align-items: center; justify-content: center;
}
.btn-add-loc {
  background: none; border: 1px dashed var(--border, #d1d5db);
  border-radius: 8px; padding: 8px 16px;
  color: var(--muted, #6b7280); cursor: pointer;
  font-size: 0.85rem; width: 100%; margin-top: 6px;
  transition: all 0.15s;
}
.btn-add-loc:hover { border-color: #059669; color: #059669; }

/* Capacity slider */
.capacity-wrap {
  display: flex; align-items: center; gap: 16px;
}
.capacity-slider { flex: 1; accent-color: #059669; }
.capacity-val {
  font-size: 1.4rem; font-weight: 700;
  color: #059669; min-width: 40px; text-align: center;
}

/* Review section */
.review-section {
  background: var(--hover, #f9fafb);
  border: 1px solid var(--border, #e5e7eb);
  border-radius: 10px;
  padding: 16px 20px;
  margin-bottom: 16px;
}
.review-section h4 {
  font-size: 0.85rem; font-weight: 700;
  color: var(--heading, #0a1628);
  margin: 0 0 10px;
  text-transform: uppercase; letter-spacing: 0.05em;
}
.review-row { display: flex; gap: 10px; margin-bottom: 6px; font-size: 0.88rem; }
.review-row .rl { color: var(--muted, #6b7280); min-width: 140px; }
.review-row .rv { color: var(--text, #374151); font-weight: 500; }

/* Buttons */
.ob-actions {
  display: flex; gap: 12px; align-items: center; margin-top: 28px;
}
.btn-ob-next {
  background: #059669; color: #fff;
  padding: 12px 28px; border-radius: 10px;
  border: none; font-size: 0.95rem; font-weight: 600;
  cursor: pointer; transition: background 0.2s;
}
.btn-ob-next:hover { background: #047857; }
.btn-ob-back {
  color: var(--muted, #6b7280); text-decoration: none;
  font-size: 0.9rem;
}

/* Agreement */
.agreement-box {
  background: var(--hover, #f9fafb);
  border: 1px solid var(--border, #e5e7eb);
  border-radius: 10px;
  padding: 16px 20px;
  font-size: 0.82rem;
  color: var(--text, #374151);
  line-height: 1.6;
  max-height: 180px;
  overflow-y: auto;
  margin-bottom: 14px;
}
</style>

<div class="ob-wrap">
  <h1 style="font-family:'Fraunces',serif;font-size:2rem;margin:0 0 6px;text-align:center">Become a Growth Partner</h1>
  <p style="text-align:center;color:var(--muted);margin:0 0 24px">Help Cameroonian businesses grow and earn commissions for every success.</p>

  <!-- Progress -->
  <div class="ob-progress">
    <?php
    $steps = ['Your Profile','Skills & Areas','Capacity','Review'];
    foreach ($steps as $i => $label):
      $n = $i + 1;
      $cls = $n < $step ? 'done' : ($n === $step ? 'active' : '');
    ?>
    <div class="ob-step <?= $cls ?>">
      <?= $n < $step ? '✓' : $n ?>
      <span class="sn"><?= $label ?></span>
    </div>
    <?php endforeach; ?>
  </div>

  <?php displayFlash(); ?>

  <div class="ob-card">
  <form method="POST" action="<?= SITE_URL ?>/partner/onboarding?step=<?= $step ?>">
    <?= csrfField() ?>
    <input type="hidden" name="step" value="<?= $step ?>">

    <!-- ── Step 1: Profile ─────────────────────────────────── -->
    <?php if ($step === 1): ?>
      <h2>Your Profile</h2>
      <p class="ob-sub">Tell us about yourself. This will appear on your public partner profile.</p>

      <div class="form-row">
        <div class="form-group">
          <label>Display Name</label>
          <input type="text" name="display_name" value="<?= e($app['display_name'] ?? $user['name']) ?>" placeholder="Your name or brand name" required>
        </div>
        <div class="form-group">
          <label>Phone Number</label>
          <input type="tel" name="phone" value="<?= e($app['phone'] ?? '') ?>" placeholder="+237 6XX XXX XXX">
        </div>
      </div>

      <div class="form-group">
        <label>Organisation <span class="opt">(optional)</span></label>
        <input type="text" name="organisation" value="<?= e($app['organisation'] ?? '') ?>" placeholder="Company or agency name (if applicable)">
      </div>

      <div class="form-group">
        <label>Tagline <span class="opt">(optional)</span></label>
        <input type="text" name="tagline" value="<?= e($app['tagline'] ?? '') ?>" placeholder="e.g. Helping Limbe businesses grow digitally" maxlength="255">
        <div class="form-hint">One line that describes what you do — shown on your public profile.</div>
      </div>

      <div class="form-group">
        <label>About You</label>
        <textarea name="bio" placeholder="Describe your background, skills and why you want to help businesses grow..."><?= e($app['bio'] ?? '') ?></textarea>
      </div>

      <div class="ob-actions">
        <button type="submit" class="btn-ob-next">Continue → Skills & Areas</button>
        <a href="<?= SITE_URL ?>/dashboard" class="btn-ob-back">Cancel</a>
      </div>

    <!-- ── Step 2: Skills & Areas ──────────────────────────── -->
    <?php elseif ($step === 2): ?>
      <h2>Skills & Coverage Areas</h2>
      <p class="ob-sub">What are you good at, and which areas of Cameroon can you serve?</p>

      <div class="form-group">
        <label>Specialisms</label>
        <div class="tag-input-wrap" id="spec-wrap">
          <?php foreach ($app['specialisms'] ?? [] as $s): ?>
            <span class="tag-pill"><?= e($s) ?><button type="button" onclick="removeTag(this)">×</button></span>
          <?php endforeach; ?>
          <input class="tag-input-field" id="spec-input" placeholder="Type a specialism and press Enter…">
        </div>
        <input type="hidden" name="specialisms" id="spec-hidden" value="<?= e(implode(', ', $app['specialisms'] ?? [])) ?>">
        <div class="form-hint">e.g. Digital Marketing, Social Media, Lead Generation, Business Development, Photography</div>
      </div>

      <div class="form-group">
        <label>Services Offered <span class="opt">(optional)</span></label>
        <div class="tag-input-wrap" id="svc-wrap">
          <?php foreach ($app['services'] ?? [] as $s): ?>
            <span class="tag-pill"><?= e($s) ?><button type="button" onclick="removeTag(this)">×</button></span>
          <?php endforeach; ?>
          <input class="tag-input-field" id="svc-input" placeholder="Type a service and press Enter…">
        </div>
        <input type="hidden" name="services" id="svc-hidden" value="<?= e(implode(', ', $app['services'] ?? [])) ?>">
        <div class="form-hint">e.g. Profile Setup, Photography, Facebook Ads, WhatsApp Marketing, Review Management</div>
      </div>

      <div class="form-group">
        <label>Coverage Areas</label>
        <div class="form-hint" style="margin-bottom:10px">Add the regions and cities you'll actively serve. Mark your primary area.</div>

        <div id="loc-container">
          <?php
          $locRegions = $app['regions'] ?? [''];
          $locCities  = $app['cities'] ?? [''];
          $primaryR   = $app['primary_region'] ?? ($locRegions[0] ?? '');
          foreach ($locRegions as $i => $region):
            if ($i > 0 && !$region) continue;
          ?>
          <div class="loc-row">
            <select name="regions[]">
              <option value="">— Select region —</option>
              <?php foreach ($cameroonRegions as $r): ?>
                <option value="<?= e($r) ?>" <?= $region === $r ? 'selected' : '' ?>><?= e($r) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="text" name="cities[]" value="<?= e($locCities[$i] ?? '') ?>" placeholder="City (optional)">
            <?php if ($i === 0): ?>
              <span style="font-size:0.75rem;color:#059669;font-weight:600;white-space:nowrap">Primary</span>
              <input type="hidden" name="primary_region" id="primary-region-input" value="<?= e($primaryR) ?>">
            <?php else: ?>
              <button type="button" class="btn-remove-loc" onclick="this.closest('.loc-row').remove()">×</button>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>

        <button type="button" class="btn-add-loc" id="add-loc-btn">+ Add another area</button>
      </div>

      <div class="ob-actions">
        <button type="submit" class="btn-ob-next">Continue → Capacity</button>
        <a href="<?= SITE_URL ?>/partner/onboarding?step=1" class="btn-ob-back">← Back</a>
      </div>

    <!-- ── Step 3: Capacity ────────────────────────────────── -->
    <?php elseif ($step === 3): ?>
      <h2>Capacity & Motivation</h2>
      <p class="ob-sub">How many businesses can you realistically manage, and why do you want to join?</p>

      <div class="form-group">
        <label>Maximum Businesses You Can Manage</label>
        <div class="capacity-wrap">
          <input type="range" class="capacity-slider" name="max_businesses"
            min="1" max="20" step="1"
            value="<?= (int)($app['max_businesses'] ?? 5) ?>"
            oninput="document.getElementById('cap-val').textContent=this.value">
          <div class="capacity-val" id="cap-val"><?= (int)($app['max_businesses'] ?? 5) ?></div>
        </div>
        <div class="form-hint">Start conservatively — you can increase this later. Most solo partners manage 3–8 businesses.</div>
      </div>

      <div class="form-group">
        <label>Your Experience</label>
        <textarea name="experience" placeholder="Describe your relevant experience — marketing, sales, business development, digital tools, etc."><?= e($app['experience'] ?? '') ?></textarea>
      </div>

      <div class="form-group">
        <label>Why Do You Want to Join?</label>
        <textarea name="why_join" placeholder="Tell us what motivates you to become a 237Biz Growth Partner..." required><?= e($app['why_join'] ?? '') ?></textarea>
      </div>

      <div class="form-group">
        <label>Areas Covered <span class="opt">(optional detail)</span></label>
        <textarea name="areas_covered" placeholder="Any additional detail about the areas you cover or specific communities you serve..."><?= e($app['areas_covered'] ?? '') ?></textarea>
      </div>

      <div class="ob-actions">
        <button type="submit" class="btn-ob-next">Continue → Review</button>
        <a href="<?= SITE_URL ?>/partner/onboarding?step=2" class="btn-ob-back">← Back</a>
      </div>

    <!-- ── Step 4: Review & Submit ─────────────────────────── -->
    <?php elseif ($step === 4): ?>
      <h2>Review & Submit</h2>
      <p class="ob-sub">Check your application before submitting. You can go back to make changes.</p>

      <div class="review-section">
        <h4>Your Profile</h4>
        <div class="review-row"><span class="rl">Name:</span><span class="rv"><?= e($app['display_name'] ?? $user['name']) ?></span></div>
        <?php if ($app['organisation'] ?? ''): ?>
        <div class="review-row"><span class="rl">Organisation:</span><span class="rv"><?= e($app['organisation']) ?></span></div>
        <?php endif; ?>
        <?php if ($app['phone'] ?? ''): ?>
        <div class="review-row"><span class="rl">Phone:</span><span class="rv"><?= e($app['phone']) ?></span></div>
        <?php endif; ?>
        <?php if ($app['tagline'] ?? ''): ?>
        <div class="review-row"><span class="rl">Tagline:</span><span class="rv"><?= e($app['tagline']) ?></span></div>
        <?php endif; ?>
      </div>

      <?php if ($app['specialisms'] ?? []): ?>
      <div class="review-section">
        <h4>Specialisms</h4>
        <div style="display:flex;flex-wrap:wrap;gap:6px">
          <?php foreach ($app['specialisms'] as $s): ?>
            <span style="background:#ecfdf5;color:#065f46;border:1px solid #6ee7b7;border-radius:20px;padding:3px 10px;font-size:0.78rem;font-weight:600"><?= e($s) ?></span>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($app['regions'] ?? []): ?>
      <div class="review-section">
        <h4>Coverage Areas</h4>
        <?php
        $regions = $app['regions'] ?? [];
        $cities  = $app['cities'] ?? [];
        foreach ($regions as $i => $r):
          if (!$r) continue;
        ?>
          <div class="review-row">
            <span class="rl"><?= $i === 0 ? 'Primary:' : 'Also covers:' ?></span>
            <span class="rv"><?= e($r) ?><?= ($cities[$i] ?? '') ? ', ' . e($cities[$i]) : '' ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div class="review-section">
        <h4>Capacity</h4>
        <div class="review-row"><span class="rl">Max businesses:</span><span class="rv"><?= (int)($app['max_businesses'] ?? 5) ?></span></div>
      </div>

      <div class="form-group" style="margin-top:20px">
        <div class="agreement-box">
          <strong>237Biz Growth Partner Agreement</strong><br><br>
          By submitting this application you agree to:<br><br>
          1. Act professionally and in the best interests of the businesses you are assigned.<br>
          2. Maintain confidentiality of all business and customer data.<br>
          3. Comply with 237Biz platform policies and guidelines.<br>
          4. Complete required training and certification within 30 days of approval.<br>
          5. Accurately report activity and not misrepresent 237Biz services.<br>
          6. Commission rates are as per your assigned tier and are subject to change with 30 days' notice.<br>
          7. 237Biz reserves the right to suspend or terminate partner status for policy violations.<br>
          8. You confirm the information in this application is accurate and truthful.
        </div>
        <label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer">
          <input type="checkbox" name="agree" value="1" required style="margin-top:2px;width:16px;height:16px;accent-color:#059669">
          <span style="font-size:0.88rem">I have read and agree to the 237Biz Growth Partner Agreement</span>
        </label>
      </div>

      <div class="ob-actions">
        <button type="submit" class="btn-ob-next">🚀 Submit Application</button>
        <a href="<?= SITE_URL ?>/partner/onboarding?step=3" class="btn-ob-back">← Back</a>
      </div>

    <?php endif; ?>
  </form>
  </div>
</div>

<script>
// ── Tag input (steps 2) ────────────────────────────────────────
function makeTagSystem(inputId, hiddenId, wrapId) {
  const input  = document.getElementById(inputId);
  const hidden = document.getElementById(hiddenId);
  const wrap   = document.getElementById(wrapId);
  if (!input) return;

  function getTags() {
    return Array.from(wrap.querySelectorAll('.tag-pill'))
      .map(p => p.childNodes[0].textContent.trim()).filter(Boolean);
  }
  function updateHidden() { hidden.value = getTags().join(', '); }

  function addTag(val) {
    val = val.trim();
    if (!val || getTags().includes(val)) return;
    const pill = document.createElement('span');
    pill.className = 'tag-pill';
    pill.innerHTML = val + '<button type="button" onclick="removeTag(this)">×</button>';
    wrap.insertBefore(pill, input);
    updateHidden();
  }

  input.addEventListener('keydown', e => {
    if (['Enter','Comma',','].includes(e.key) || e.key === ',') {
      e.preventDefault();
      addTag(input.value);
      input.value = '';
    }
    if (e.key === 'Backspace' && !input.value) {
      const pills = wrap.querySelectorAll('.tag-pill');
      if (pills.length) { pills[pills.length-1].remove(); updateHidden(); }
    }
  });
  input.addEventListener('blur', () => { if (input.value) { addTag(input.value); input.value=''; } });
  wrap.addEventListener('click', () => input.focus());
}

window.removeTag = function(btn) {
  const pill = btn.closest('.tag-pill');
  const wrap = pill.closest('.tag-input-wrap');
  const hiddenId = wrap.id.replace('-wrap', '-hidden');
  pill.remove();
  const hidden = document.getElementById(hiddenId);
  if (hidden) {
    hidden.value = Array.from(wrap.querySelectorAll('.tag-pill'))
      .map(p => p.childNodes[0].textContent.trim()).join(', ');
  }
};

makeTagSystem('spec-input','spec-hidden','spec-wrap');
makeTagSystem('svc-input','svc-hidden','svc-wrap');

// ── Location rows ──────────────────────────────────────────────
const addLocBtn = document.getElementById('add-loc-btn');
const locContainer = document.getElementById('loc-container');
const regions = <?= json_encode($cameroonRegions) ?>;

if (addLocBtn) {
  addLocBtn.addEventListener('click', () => {
    const row = document.createElement('div');
    row.className = 'loc-row';
    const opts = regions.map(r => `<option value="${r}">${r}</option>`).join('');
    row.innerHTML = `
      <select name="regions[]"><option value="">— Select region —</option>${opts}</select>
      <input type="text" name="cities[]" placeholder="City (optional)">
      <button type="button" class="btn-remove-loc" onclick="this.closest('.loc-row').remove()">×</button>`;
    locContainer.appendChild(row);
  });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
