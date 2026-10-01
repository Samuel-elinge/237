<?php
/**
 * INTEGRATION GUIDE — booking-integration.php
 * ============================================
 * This file shows exactly what to add to:
 *   1. listing.php  — the "Book an Appointment" button + info panel
 *   2. edit-listing.php — the Booking Settings section
 *
 * Search for the PASTE HERE markers in your files.
 */

// ═══════════════════════════════════════════════════════════════════════════
// PART 1: listing.php
// ═══════════════════════════════════════════════════════════════════════════
//
// FIND this section in listing.php (around line 70, after $faqs is loaded):
//
//    // Products (featured listings only)
//    $products = [];
//    if ($l['featured']) { ...
//
// ADD AFTER the existing $faqs loading block:
//
//    // ── Booking settings ──────────────────────────────────────────────────
//    $bookingEnabled  = !empty($l['booking_enabled']) && $l['featured'];
//    $bookingServices = json_decode($l['booking_services'] ?? '[]', true) ?: [];
//
// ───────────────────────────────────────────────────────────────────────────
//
// FIND the CTA button section in the listing hero (inside the header widget):
//
//    <?php if (!empty($l['cta_url']) && $l['featured']): ?>
//    <div style="margin-top:1rem;">
//      <a href="...">...</a>
//    </div>
//    <?php endif; ?>
//
// ADD AFTER that block:
?>

<!-- PASTE 1A: Booking button in hero (after existing CTA button) -->
<?php if (!empty($bookingEnabled)): ?>
<div style="margin-top:<?= !empty($l['cta_url']) ? '10px' : '1rem' ?>;">
  <a href="<?= SITE_URL ?>/booking?slug=<?= e($l['slug']) ?>"
     style="display:inline-flex;align-items:center;gap:8px;background:rgba(0,168,120,0.2);color:#00A878;border:2px solid rgba(0,168,120,0.5);font-weight:700;font-size:14px;padding:10px 22px;border-radius:99px;text-decoration:none;transition:background .15s;"
     onmouseover="this.style.background='rgba(0,168,120,0.35)'"
     onmouseout="this.style.background='rgba(0,168,120,0.2)'">
    📅 <?= t('Book an Appointment','Prendre rendez-vous') ?>
  </a>
</div>
<?php endif; ?>
<!-- END PASTE 1A -->

<?php
// ───────────────────────────────────────────────────────────────────────────
//
// FIND the sidebar section in listing.php, just before the Location widget:
//
//    <!-- Location -->
//    <div class="listing-widget">
//      <h4>📍 Location</h4>
//
// PASTE 1B immediately BEFORE that Location widget:
?>

<!-- PASTE 1B: Booking sidebar card (paste before the Location widget in sidebar) -->
<?php if (!empty($bookingEnabled)): ?>
<div class="listing-widget" style="border-color:rgba(0,168,120,0.3);background:rgba(0,168,120,0.03);">
  <h4 style="margin-bottom:6px;">📅 <?= t('Appointments','Rendez-vous') ?></h4>
  <p style="font-size:13px;color:var(--muted);margin-bottom:12px;line-height:1.5;">
    <?= t('Book an appointment with this business directly through 237Biz.','Prenez rendez-vous directement avec cette entreprise via 237Biz.') ?>
  </p>
  <?php if (!empty($bookingServices)): ?>
  <div style="display:flex;flex-wrap:wrap;gap:5px;margin-bottom:12px;">
    <?php foreach (array_slice($bookingServices, 0, 4) as $svc): ?>
    <span style="font-size:11.5px;background:rgba(0,168,120,0.1);color:#00A878;border:1px solid rgba(0,168,120,0.25);border-radius:99px;padding:3px 10px;">
      <?= e($svc) ?>
    </span>
    <?php endforeach; ?>
    <?php if (count($bookingServices) > 4): ?>
    <span style="font-size:11.5px;color:var(--muted);padding:3px 0;">+<?= count($bookingServices)-4 ?> <?= t('more','autres') ?></span>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <a href="<?= SITE_URL ?>/booking?slug=<?= e($l['slug']) ?>"
     style="display:block;width:100%;text-align:center;padding:11px;background:rgba(0,168,120,0.2);color:#00A878;font-weight:700;font-size:14px;border:2px solid rgba(0,168,120,0.45);border-radius:10px;text-decoration:none;">
    📅 <?= t('Book Now','Réserver maintenant') ?>
  </a>
</div>
<?php endif; ?>
<!-- END PASTE 1B -->

<?php
// ═══════════════════════════════════════════════════════════════════════════
// PART 2: edit-listing.php
// ═══════════════════════════════════════════════════════════════════════════
//
// FIND the Logo & Media section div in edit-listing.php:
//
//    <!-- ══ 7. LOGO & MEDIA -->
//    <div class="esec ...
//
// PASTE 2 immediately BEFORE that Logo & Media section:
//
// Also add to the PHP top of edit-listing.php (after the $services/$hours decoding):
//   $bookingEnabled  = (bool)($l['booking_enabled'] ?? false);
//   $bookingServices = json_decode($l['booking_services'] ?? '[]', true) ?: [];
//   $bookingAdvance  = (int)($l['booking_advance'] ?? 30);
//
// And add to the POST handler (after hours_json is read):
//   $booking_enabled  = isset($_POST['booking_enabled']) ? 1 : 0;
//   $booking_services = $_POST['booking_services_json'] ?? '[]';
//   $booking_advance  = max(1, min(365, (int)($_POST['booking_advance'] ?? 30)));
//
// And add these to the UPDATE query SET clause:
//   booking_enabled=?, booking_services=?, booking_advance=?,
// And add to the execute array:
//   $booking_enabled, $booking_services, $booking_advance,
// ───────────────────────────────────────────────────────────────────────────
?>

<!-- PASTE 2: Booking Settings section for edit-listing.php (paste before Logo & Media section) -->
<?php if ($l['featured']): // booking is a paid feature ?>
<div class="esec <?= !empty($bookingEnabled) ? 'done' : '' ?>" id="sec-booking">
  <div class="esec-head" data-toggle="booking">
    <span class="esec-icon">📅</span>
    <div>
      <p class="esec-title"><?= t('Appointment Booking','Réservation de rendez-vous') ?></p>
      <p class="esec-sub"><?= t('Let customers book appointments directly from your listing','Permettez aux clients de réserver directement depuis votre annonce') ?></p>
    </div>
    <span class="esec-status"><?= !empty($bookingEnabled) ? '✓ '.t('Active','Actif') : '○ '.t('Disabled','Désactivé') ?></span>
    <span class="esec-chev" id="chev-booking">▾</span>
  </div>
  <div class="esec-body" id="body-booking">
    <div class="esec-inner">

      <!-- Enable toggle -->
      <div style="display:flex;align-items:center;gap:14px;padding:14px;background:rgba(0,168,120,0.05);border:1px solid rgba(0,168,120,0.2);border-radius:10px;margin-bottom:16px;">
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;flex:1;margin:0;">
          <input type="checkbox" name="booking_enabled" id="bookingToggle" value="1"
                 <?= !empty($bookingEnabled) ? 'checked' : '' ?>
                 style="accent-color:#00A878;width:18px;height:18px;cursor:pointer;">
          <div>
            <div style="font-weight:700;font-size:14px;"><?= t('Enable Appointment Booking','Activer la prise de rendez-vous') ?></div>
            <div style="font-size:12px;color:var(--muted);"><?= t('A "Book an Appointment" button will appear on your listing.','Un bouton "Prendre rendez-vous" apparaîtra sur votre annonce.') ?></div>
          </div>
        </label>
        <?php if (!empty($bookingEnabled) && !empty($l['slug'])): ?>
        <a href="<?= SITE_URL ?>/booking?slug=<?= e($l['slug']) ?>" target="_blank"
           style="font-size:12px;color:var(--green);white-space:nowrap;">
          <?= t('View booking page →','Voir la page →') ?>
        </a>
        <?php endif; ?>
      </div>

      <div id="bookingSettings" style="<?= empty($bookingEnabled) ? 'opacity:.4;pointer-events:none;' : '' ?>">

        <!-- Bookable services -->
        <div class="bk-group" style="margin-bottom:16px;">
          <label style="font-size:13px;font-weight:600;color:rgba(255,255,255,0.8);display:block;margin-bottom:6px;">
            <?= t('Services available for booking','Services disponibles à la réservation') ?>
            <span style="font-size:11px;font-weight:400;color:var(--muted);margin-left:5px;"><?= t('(leave empty to accept any booking)','(laissez vide pour accepter toute réservation)') ?></span>
          </label>
          <div class="tags-wrap" id="bkServicesWrap" style="min-height:42px;cursor:text;"
               onclick="document.getElementById('bkServiceInput').focus()">
            <input type="text" class="tag-input-field" id="bkServiceInput"
                   placeholder="<?= e(t('Type a service name, press Enter...','Tapez un nom de service, appuyez sur Entrée...')) ?>"
                   style="border:none;background:none;color:var(--white);outline:none;font-size:13px;min-width:160px;flex:1;font-family:inherit;">
          </div>
          <p style="font-size:11.5px;color:var(--muted);margin-top:4px;">
            <?= t('Customers will be able to select from these when booking.','Les clients pourront choisir parmi ces options lors de la réservation.') ?>
          </p>
          <input type="hidden" name="booking_services_json" id="bkServicesJson"
                 value="<?= e(json_encode($bookingServices)) ?>">
        </div>

        <!-- Advance booking days -->
        <div style="margin-bottom:16px;">
          <label style="font-size:13px;font-weight:600;color:rgba(255,255,255,0.8);display:block;margin-bottom:6px;">
            📆 <?= t('How far ahead can customers book?','Combien de jours à l\'avance les clients peuvent-ils réserver ?') ?>
          </label>
          <div style="display:flex;align-items:center;gap:10px;">
            <input type="number" name="booking_advance" id="bookingAdvance"
                   value="<?= (int)($bookingAdvance ?? 30) ?>" min="1" max="365"
                   style="width:90px;padding:8px 12px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:6px;color:var(--white);font-family:inherit;font-size:14px;">
            <span style="font-size:13px;color:var(--muted);"><?= t('days in advance','jours à l\'avance') ?></span>
          </div>
          <p style="font-size:11.5px;color:var(--muted);margin-top:4px;">
            <?= t('Recommended: 30 days. Set lower if you prefer last-minute bookings only.','Recommandé : 30 jours. Réduisez si vous préférez uniquement les réservations de dernière minute.') ?>
          </p>
        </div>

        <!-- Manage link -->
        <div style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.07);border-radius:8px;padding:12px 14px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
          <span style="font-size:13px;color:var(--muted);">
            📋 <?= t('View and manage all booking requests:','Consultez et gérez toutes les demandes de réservation :') ?>
          </span>
          <a href="<?= SITE_URL ?>/manage-bookings?listing_id=<?= $l['id'] ?>"
             style="font-size:13px;font-weight:600;color:#00A878;text-decoration:none;">
            <?= t('Open Booking Dashboard →','Ouvrir le tableau de bord →') ?>
          </a>
        </div>

      </div><!-- /bookingSettings -->
    </div>
  </div>
</div>

<script>
// Booking section toggle — show/hide settings based on checkbox
(function() {
  var toggle   = document.getElementById('bookingToggle');
  var settings = document.getElementById('bookingSettings');
  if (!toggle || !settings) return;

  toggle.addEventListener('change', function() {
    settings.style.opacity        = this.checked ? '1' : '.4';
    settings.style.pointerEvents  = this.checked ? '' : 'none';
  });

  // ── Booking services tag input ──────────────────────────────────────
  var bkWrap   = document.getElementById('bkServicesWrap');
  var bkInput  = document.getElementById('bkServiceInput');
  var bkJson   = document.getElementById('bkServicesJson');
  var bkTags   = [];

  try { bkTags = JSON.parse(bkJson.value) || []; } catch(e) {}

  function renderBkTags() {
    if (!bkWrap) return;
    bkWrap.querySelectorAll('.tag-chip').forEach(function(c) { c.remove(); });
    bkTags.forEach(function(tag, i) {
      var chip = document.createElement('span');
      chip.className = 'tag-chip';
      var txt = document.createTextNode(tag + ' ');
      var btn = document.createElement('button');
      btn.type = 'button'; btn.textContent = '×';
      btn.setAttribute('data-i', i);
      btn.addEventListener('click', function() {
        bkTags.splice(parseInt(this.getAttribute('data-i')), 1);
        renderBkTags();
        bkJson.value = JSON.stringify(bkTags);
      });
      chip.appendChild(txt); chip.appendChild(btn);
      bkWrap.insertBefore(chip, bkInput);
    });
  }

  if (bkInput) {
    bkInput.addEventListener('keydown', function(e) {
      if (e.key === 'Enter' && this.value.trim()) {
        e.preventDefault();
        var val = this.value.trim();
        if (!bkTags.includes(val)) bkTags.push(val);
        renderBkTags();
        bkJson.value = JSON.stringify(bkTags);
        this.value = '';
      }
    });
  }
  renderBkTags();
})();
</script>
<?php endif; ?>
<!-- END PASTE 2 -->
