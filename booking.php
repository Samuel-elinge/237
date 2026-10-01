<?php
/**
 * booking.php — 237Biz Smart Appointment Booking
 * URL: /booking?slug=maroon-hosting
 * Shows live available time slots based on business hours, existing bookings,
 * and manually blocked times.
 */
require_once __DIR__ . '/includes/config.php';

$slug = trim($_GET['slug'] ?? '');
if (!$slug) redirect(SITE_URL . '/listings');

// Load listing
$listing = null;
try {
    $st = db()->prepare("
        SELECT l.*, c.name_en AS cat_en, c.name_fr AS cat_fr, c.icon AS cat_icon,
               loc.name_en AS loc_en
        FROM listings l
        JOIN categories c   ON c.id  = l.category_id
        JOIN locations  loc ON loc.id = l.location_id
        WHERE l.slug = ? AND l.status = 'approved' AND l.featured = 1
    ");
    $st->execute([$slug]);
    $listing = $st->fetch();
} catch (Exception $e) {}

if (!$listing) {
    $pageTitle = t('Not Found','Introuvable');
    require_once __DIR__ . '/includes/header.php';
    echo '<div style="max-width:500px;margin:60px auto;text-align:center;padding:20px;">';
    echo '<div style="font-size:2.5rem;margin-bottom:16px;">📅</div>';
    echo '<h2 style="font-family:\'Fraunces\',serif;">' . t('Listing Not Found','Annonce introuvable') . '</h2>';
    echo '<a href="' . SITE_URL . '/listings" class="btn btn-outline" style="margin-top:16px;">' . t('Browse Listings','Parcourir') . '</a>';
    echo '</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Load booking settings
$bookingEnabled  = false;
$bookingServices = [];
$slotDuration    = 30;
$advanceDays     = 30;
try {
    $bc = db()->prepare("SELECT booking_enabled, booking_services, booking_slot_duration, booking_advance FROM listings WHERE id=?");
    $bc->execute([$listing['id']]);
    $bRow = $bc->fetch();
    $bookingEnabled  = !empty($bRow['booking_enabled']);
    $bookingServices = json_decode($bRow['booking_services'] ?? '[]', true) ?: [];
    $slotDuration    = (int)($bRow['booking_slot_duration'] ?? 30);
    $advanceDays     = max(1, min(365, (int)($bRow['booking_advance'] ?? 30)));
    if (!in_array($slotDuration, [15, 30, 45, 60])) $slotDuration = 30;
} catch (Exception $e) {}

if (!$bookingEnabled) {
    $pageTitle = t('Booking Not Available','Réservation non disponible');
    require_once __DIR__ . '/includes/header.php';
    echo '<div style="max-width:500px;margin:60px auto;text-align:center;padding:20px;">';
    echo '<div style="font-size:2.5rem;margin-bottom:16px;">📅</div>';
    echo '<h2 style="font-family:\'Fraunces\',serif;margin-bottom:10px;">' . t('Booking Not Enabled','Réservation non activée') . '</h2>';
    echo '<p style="color:var(--muted);margin-bottom:6px;">' . t('This business has not enabled online booking yet.','Cette entreprise n\'a pas encore activé la réservation en ligne.') . '</p>';
    if (!empty($listing['whatsapp'])) {
        $waPhone = preg_replace('/\D/', '', $listing['whatsapp']);
        echo '<a href="https://wa.me/' . $waPhone . '" style="display:inline-flex;align-items:center;gap:8px;background:#25D366;color:#fff;font-weight:700;padding:10px 22px;border-radius:99px;text-decoration:none;margin-top:16px;">💬 WhatsApp</a>';
    }
    echo '<br><a href="' . SITE_URL . '/listing/' . e($slug) . '" class="btn btn-outline" style="margin-top:12px;">' . t('← Back to listing','← Retour') . '</a>';
    echo '</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$hours = json_decode($listing['hours_json'] ?? '{}', true) ?: [];
$days  = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];

// Open days as JS weekday numbers (0=Sun, 1=Mon...)
$openDays = [];
foreach ($days as $i => $day) {
    if (!empty($hours[$day]['open'])) {
        $openDays[] = ($i === 6) ? 0 : $i + 1;
    }
}

$success = false;
$booking = null;
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $name    = trim($_POST['customer_name']  ?? '');
    $phone   = trim($_POST['customer_phone'] ?? '');
    $email   = trim($_POST['customer_email'] ?? '');
    $service = trim($_POST['service_name']   ?? '');
    $date    = trim($_POST['preferred_date'] ?? '');
    $time    = trim($_POST['preferred_time'] ?? '');
    $message = trim($_POST['message']        ?? '');

    if (!$name)  $errors[] = t('Your name is required.',  'Votre nom est requis.');
    if (!$phone) $errors[] = t('Your phone number is required.','Votre numéro de téléphone est requis.');
    if (!$date)  $errors[] = t('Please select a date.','Veuillez choisir une date.');
    if (!$time)  $errors[] = t('Please select a time slot.','Veuillez choisir un créneau horaire.');

    // Verify slot is still available (prevent race condition)
    if ($date && $time && !$errors) {
        try {
            $conflict = db()->prepare("
                SELECT COUNT(*) FROM listing_bookings
                WHERE listing_id=? AND preferred_date=? AND preferred_time=?
                  AND status IN ('pending','confirmed')
            ");
            $conflict->execute([$listing['id'], $date, $time]);
            if ((int)$conflict->fetchColumn() > 0) {
                $errors[] = t('That time slot was just taken. Please choose another.','Ce créneau vient d\'être pris. Veuillez en choisir un autre.');
            }
        } catch (Exception $e) {}
    }

    if (!$errors) {
        // Generate unique reference
        $ref = '';
        do {
            $ref = 'BK-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
            $chk = db()->prepare("SELECT COUNT(*) FROM listing_bookings WHERE reference=?");
            $chk->execute([$ref]);
        } while ((int)$chk->fetchColumn() > 0);

        db()->prepare("
            INSERT INTO listing_bookings
              (listing_id, reference, customer_name, customer_phone, customer_email,
               service_name, preferred_date, preferred_time, message)
            VALUES (?,?,?,?,?,?,?,?,?)
        ")->execute([
            $listing['id'], $ref, $name, $phone, $email ?: null,
            $service ?: null, $date, $time, $message ?: null
        ]);

        // Email to business
        if ($listing['email']) {
            $sub  = "New Booking [{$ref}] — {$listing['title']}";
            $body = "You have a new appointment request on 237Biz.\n\n";
            $body .= "Reference: {$ref}\nCustomer: {$name}\nPhone: {$phone}\n";
            if ($email)   $body .= "Email: {$email}\n";
            if ($service) $body .= "Service: {$service}\n";
            $body .= "Date: {$date} at {$time}\n";
            if ($message) $body .= "Note: {$message}\n";
            $body .= "\n";
            $body .= "Manage this booking:\n";
            $body .= SITE_URL . "/manage-bookings\n\n";
            $body .= "Help — Confirming or declining bookings:\n";
            $body .= SITE_URL . "/help/confirm-decline-booking\n\n";
            $body .= "— 237Biz.net\n" . SITE_URL . "/help";
            @mail($listing['email'], $sub, $body, "From: 237Biz <noreply@237biz.net>\r\n");
        }

        // Email to customer
        if ($email) {
            $sub  = "Booking Received [{$ref}] — {$listing['title']}";
            $body = "Thank you! Your appointment request has been received.\n\n";
            $body .= "Reference: {$ref}\nBusiness: {$listing['title']}\n";
            if ($service) $body .= "Service: {$service}\n";
            $body .= "Date: {$date} at {$time}\n";
            $body .= "Status: Pending confirmation — the business will confirm your appointment.\n\n";
            $body .= "View the business listing:\n";
            $body .= SITE_URL . "/listing/{$slug}\n\n";
            $body .= "Need help?\n";
            $body .= SITE_URL . "/help/customer-book-appointment\n\n";
            $body .= "— 237Biz.net\n" . SITE_URL . "/help";
            @mail($email, $sub, $body, "From: 237Biz <noreply@237biz.net>\r\n");
        }

        // WhatsApp notification link
        $waMsg  = "📅 New Booking [{$ref}] via 237Biz\nCustomer: {$name} ({$phone})\n";
        if ($service) $waMsg .= "Service: {$service}\n";
        $waMsg .= "Date: {$date} at {$time}\n";
        if ($message) $waMsg .= "Note: {$message}\n";
        $waMsg .= "Manage: " . SITE_URL . "/manage-bookings";
        $waPhone = preg_replace('/\D/', '', $listing['whatsapp'] ?? '');
        $waLink  = $waPhone ? "https://wa.me/{$waPhone}?text=" . rawurlencode($waMsg) : null;

        $booking = ['ref'=>$ref, 'date'=>$date, 'time'=>$time, 'wa_link'=>$waLink, 'service'=>$service];
        $success = true;
    }
}

$pageTitle = t('Book an Appointment','Prendre rendez-vous') . ' — ' . e($listing['title']);
require_once __DIR__ . '/includes/header.php';
?>

<style>
.booking-wrap   { max-width:620px; margin:0 auto; padding:24px 16px 60px; }
.booking-card   { background:rgba(255,255,255,0.02); border:1px solid var(--border); border-radius:16px; overflow:hidden; }
.booking-hero   { background:linear-gradient(135deg,#08472F,#0e6645); padding:24px 28px; }
.booking-hero h1{ font-family:'Fraunces',serif; font-size:1.5rem; font-weight:900; margin:0 0 4px; }
.booking-hero p { font-size:13px; color:rgba(255,255,255,0.7); margin:0; }
.booking-body   { padding:24px 28px; }
.bk-section-lbl { font-size:11px; font-weight:700; color:rgba(255,255,255,0.4); text-transform:uppercase; letter-spacing:.08em; margin:18px 0 10px; }
.bk-group       { margin-bottom:14px; }
.bk-group label { display:block; font-size:13px; font-weight:600; color:rgba(255,255,255,0.8); margin-bottom:5px; }
.bk-group input, .bk-group select, .bk-group textarea {
  width:100%; box-sizing:border-box; padding:10px 13px;
  background:rgba(255,255,255,0.04); border:1.5px solid rgba(255,255,255,0.1);
  border-radius:8px; color:var(--white); font-size:14px; font-family:inherit;
}
.bk-group input:focus, .bk-group select:focus, .bk-group textarea:focus {
  outline:none; border-color:rgba(0,168,120,0.6);
}
.bk-group select option { background:#0d1f12; }
.bk-row  { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.bk-hint { font-size:11.5px; color:var(--muted); margin-top:4px; }
.bk-req  { color:#e63946; }

/* Slot grid */
.slot-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(90px,1fr)); gap:8px; margin-top:6px; }
.slot-btn  {
  padding:9px 6px; border:1.5px solid rgba(255,255,255,0.12); border-radius:8px;
  background:rgba(255,255,255,0.04); color:rgba(255,255,255,0.8); font-size:13px;
  font-weight:600; cursor:pointer; font-family:inherit; text-align:center;
  transition:all .15s;
}
.slot-btn:hover  { border-color:#00A878; background:rgba(0,168,120,0.15); color:#00A878; }
.slot-btn.selected { border-color:#00A878; background:rgba(0,168,120,0.25); color:#00A878; }
.slot-loading { font-size:13px; color:var(--muted); padding:12px 0; }
.slot-none    { font-size:13px; color:#e63946; padding:10px 12px; background:rgba(206,17,38,0.08); border:1px solid rgba(206,17,38,0.2); border-radius:8px; }
.slot-closed  { font-size:13px; color:var(--muted); padding:10px 12px; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.08); border-radius:8px; }

.bk-submit {
  width:100%; padding:13px; background:var(--yellow); color:#0A1A0F;
  font-weight:800; font-size:15px; border:none; border-radius:10px;
  cursor:pointer; font-family:inherit; margin-top:8px; transition:opacity .15s;
}
.bk-submit:hover   { opacity:.88; }
.bk-submit:disabled{ opacity:.4; cursor:not-allowed; }

/* Open days indicator */
.open-days { display:flex; gap:5px; flex-wrap:wrap; margin-bottom:6px; }
.day-chip  { font-size:11px; padding:3px 9px; border-radius:99px; border:1px solid; }
.day-open  { background:rgba(0,168,120,0.1); color:#00A878; border-color:rgba(0,168,120,0.3); }
.day-closed{ background:rgba(255,255,255,0.03); color:rgba(255,255,255,0.3); border-color:rgba(255,255,255,0.08); }

/* Success */
.bk-ref { display:inline-block; background:rgba(0,168,120,0.15); border:1px solid rgba(0,168,120,0.4); color:#00A878; border-radius:8px; padding:10px 18px; font-size:1.1rem; font-weight:700; letter-spacing:2px; margin:12px 0; }
</style>

<div class="booking-wrap">

<?php if ($success && $booking): ?>
<!-- ── Success ──────────────────────────────────────────────────────── -->
<div class="booking-card">
  <div class="booking-hero" style="background:linear-gradient(135deg,#064a1e,#007A5E);">
    <h1>✅ <?= t('Booking Received!','Réservation reçue !') ?></h1>
    <p><?= e($listing['title']) ?></p>
  </div>
  <div class="booking-body" style="text-align:center;">
    <p style="color:var(--muted);font-size:14px;margin-bottom:6px;"><?= t('Your reference:','Votre référence :') ?></p>
    <div class="bk-ref"><?= e($booking['ref']) ?></div>
    <div style="background:rgba(255,255,255,0.03);border-radius:10px;padding:14px 18px;text-align:left;margin:16px 0;">
      <div style="font-size:14px;line-height:2;">
        <div>📅 <strong><?= e($booking['date']) ?></strong> <?= t('at','à') ?> <strong><?= e($booking['time']) ?></strong></div>
        <?php if ($booking['service']): ?><div>🛠️ <?= e($booking['service']) ?></div><?php endif; ?>
        <div>🏢 <?= e($listing['title']) ?></div>
        <div>📍 <?= e($listing['loc_en']) ?></div>
      </div>
    </div>
    <p style="font-size:13px;color:var(--muted);margin-bottom:18px;">
      <?= t('The business will confirm your appointment shortly.','L\'entreprise confirmera votre rendez-vous prochainement.') ?>
    </p>
    <?php if ($booking['wa_link']): ?>
    <a href="<?= e($booking['wa_link']) ?>" target="_blank"
       style="display:inline-flex;align-items:center;gap:8px;background:#25D366;color:#fff;font-weight:700;padding:10px 20px;border-radius:8px;text-decoration:none;font-size:14px;margin-bottom:14px;">
      💬 <?= t('Notify business on WhatsApp','Notifier l\'entreprise sur WhatsApp') ?>
    </a>
    <?php endif; ?>
    <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
      <a href="<?= SITE_URL ?>/listing/<?= e($slug) ?>" class="btn btn-outline">← <?= t('Back to listing','Retour à l\'annonce') ?></a>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ── Booking Form ──────────────────────────────────────────────── -->

<?php if ($errors): ?>
<div style="background:rgba(206,17,38,0.1);border:1px solid rgba(206,17,38,0.3);border-radius:10px;padding:14px 18px;margin-bottom:14px;">
  <?php foreach ($errors as $err): ?>
  <p style="font-size:13px;color:#ff6b7a;margin:0 0 3px;">⚠️ <?= e($err) ?></p>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="booking-card">
  <!-- Business bar -->
  <div style="display:flex;align-items:center;gap:12px;padding:14px 20px;background:rgba(255,255,255,0.015);border-bottom:1px solid var(--border);">
    <div style="width:40px;height:40px;border-radius:8px;overflow:hidden;background:rgba(255,255,255,0.05);display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0;">
      <?php if ($listing['logo']): ?>
      <img src="<?= e(UPLOAD_URL . $listing['logo']) ?>" style="width:100%;height:100%;object-fit:cover;">
      <?php else: echo $listing['cat_icon']; endif; ?>
    </div>
    <div>
      <div style="font-weight:700;font-size:15px;"><?= e($listing['title']) ?></div>
      <div style="font-size:12px;color:var(--muted);">📍 <?= e($listing['loc_en']) ?></div>
    </div>
    <a href="<?= SITE_URL ?>/listing/<?= e($slug) ?>" style="margin-left:auto;font-size:12px;color:var(--green);">← <?= t('Back','Retour') ?></a>
  </div>

  <!-- Hero -->
  <div class="booking-hero">
    <h1>📅 <?= t('Book an Appointment','Prendre rendez-vous') ?></h1>
    <p><?= t('Slots are','Créneaux de') ?> <?= $slotDuration ?> <?= t('minutes each. Available times load automatically when you pick a date.','minutes chacun. Les créneaux disponibles se chargent automatiquement quand vous choisissez une date.') ?></p>
  </div>

  <div class="booking-body">
    <form method="POST" id="bookingForm">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="preferred_time" id="selectedTime" value="">

      <!-- Your details -->
      <div class="bk-section-lbl"><?= t('Your Details','Vos coordonnées') ?></div>
      <div class="bk-row">
        <div class="bk-group">
          <label><?= t('Full Name','Nom complet') ?> <span class="bk-req">*</span></label>
          <input type="text" name="customer_name" value="<?= e($_POST['customer_name'] ?? '') ?>" required
                 placeholder="<?= e(t('Your name','Votre nom')) ?>">
        </div>
        <div class="bk-group">
          <label><?= t('Phone / WhatsApp','Téléphone / WhatsApp') ?> <span class="bk-req">*</span></label>
          <input type="tel" name="customer_phone" value="<?= e($_POST['customer_phone'] ?? '') ?>" required
                 placeholder="6XX XXX XXX">
        </div>
      </div>
      <div class="bk-group">
        <label>Email <?= t('(optional)','(optionnel)') ?></label>
        <input type="email" name="customer_email" value="<?= e($_POST['customer_email'] ?? '') ?>"
               placeholder="you@example.com">
      </div>

      <!-- Service -->
      <?php if (!empty($bookingServices)): ?>
      <div class="bk-section-lbl"><?= t('Service','Service') ?></div>
      <div class="bk-group">
        <label><?= t('What would you like to book?','Que souhaitez-vous réserver ?') ?></label>
        <select name="service_name">
          <option value=""><?= e(t('— Select a service —','— Choisissez un service —')) ?></option>
          <?php foreach ($bookingServices as $svc): ?>
          <option value="<?= e($svc) ?>" <?= ($_POST['service_name'] ?? '') === $svc ? 'selected' : '' ?>><?= e($svc) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php else: ?>
      <input type="hidden" name="service_name" value="">
      <?php endif; ?>

      <!-- Date & Time -->
      <div class="bk-section-lbl"><?= t('Date & Time','Date et heure') ?></div>

      <!-- Open days quick reference -->
      <?php if (!empty($hours)): ?>
      <div class="open-days" style="margin-bottom:10px;">
        <?php $dayShort = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
        foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as $i => $day): ?>
        <span class="day-chip <?= !empty($hours[$day]['open']) ? 'day-open' : 'day-closed' ?>">
          <?= $dayShort[$i] ?>
          <?php if (!empty($hours[$day]['open'])): ?>
          <span style="font-size:10px;opacity:.7;"> <?= $hours[$day]['from'] ?>–<?= $hours[$day]['to'] ?></span>
          <?php endif; ?>
        </span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div class="bk-group">
        <label><?= t('Pick a date','Choisissez une date') ?> <span class="bk-req">*</span></label>
        <input type="date" name="preferred_date" id="bookingDate"
               value="<?= e($_POST['preferred_date'] ?? '') ?>"
               min="<?= date('Y-m-d', strtotime('+1 day')) ?>"
               max="<?= date('Y-m-d', strtotime("+{$advanceDays} days")) ?>"
               required>
        <p class="bk-hint" id="dateHint"><?= t('Select a date to see available time slots.','Sélectionnez une date pour voir les créneaux disponibles.') ?></p>
      </div>

      <!-- Slot picker — populated via AJAX -->
      <div class="bk-group" id="slotPickerWrap" style="display:none;">
        <label><?= t('Available Time Slots','Créneaux disponibles') ?> <span class="bk-req">*</span></label>
        <div id="slotGrid" class="slot-grid"></div>
        <p class="bk-hint" id="slotHint"></p>
      </div>

      <!-- Selected time display -->
      <div id="selectedDisplay" style="display:none;margin-bottom:12px;padding:10px 14px;background:rgba(0,168,120,0.1);border:1px solid rgba(0,168,120,0.3);border-radius:8px;font-size:14px;font-weight:600;color:#00A878;"></div>

      <!-- Message -->
      <div class="bk-group">
        <label><?= t('Message / Notes','Message / Notes') ?> <?= t('(optional)','(optionnel)') ?></label>
        <textarea name="message" rows="2"
                  placeholder="<?= e(t('Any specific requests...','Toute demande spécifique...')) ?>"><?= e($_POST['message'] ?? '') ?></textarea>
      </div>

      <button type="submit" class="bk-submit" id="submitBtn" disabled>
        📅 <?= t('Request Appointment','Demander un rendez-vous') ?>
      </button>
      <p style="font-size:11.5px;color:var(--muted);text-align:center;margin-top:8px;">
        🔒 <?= t('Your details are only shared with this business.','Vos coordonnées ne sont partagées qu\'avec cette entreprise.') ?>
      </p>
    </form>
  </div>
</div>
<?php endif; ?>
</div>

<script>
(function() {
  'use strict';

  var dateInput      = document.getElementById('bookingDate');
  var slotWrap       = document.getElementById('slotPickerWrap');
  var slotGrid       = document.getElementById('slotGrid');
  var slotHint       = document.getElementById('slotHint');
  var selectedTime   = document.getElementById('selectedTime');
  var selectedDisplay = document.getElementById('selectedDisplay');
  var submitBtn      = document.getElementById('submitBtn');
  var dateHint       = document.getElementById('dateHint');

  var listingId   = <?= (int)$listing['id'] ?>;
  var slotMins    = <?= (int)$slotDuration ?>;
  var openDaysJS  = <?= json_encode($openDays) ?>;
  var currentSlot = '';

  // ── Disable closed days in the date picker (visual hint via label) ────
  // Native date inputs don't support disabling specific days,
  // so we validate on selection instead.
  function isDayOpen(dateStr) {
    if (!dateStr) return true;
    var d = new Date(dateStr + 'T12:00:00');
    var dow = d.getDay(); // 0=Sun
    return openDaysJS.length === 0 || openDaysJS.includes(dow);
  }

  function formatTime12(t) {
    var parts = t.split(':');
    var h = parseInt(parts[0]);
    var m = parts[1];
    var ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    return h + ':' + m + ' ' + ampm;
  }

  function clearSlots() {
    slotGrid.innerHTML = '';
    selectedTime.value = '';
    currentSlot = '';
    if (selectedDisplay) { selectedDisplay.style.display = 'none'; selectedDisplay.textContent = ''; }
    if (submitBtn) submitBtn.disabled = true;
  }

  function selectSlot(time) {
    currentSlot = time;
    selectedTime.value = time;
    slotGrid.querySelectorAll('.slot-btn').forEach(function(b) {
      b.classList.toggle('selected', b.getAttribute('data-time') === time);
    });
    if (selectedDisplay) {
      selectedDisplay.textContent = '✓ ' + formatTime12(time) + ' ' + '<?= e(t('selected','sélectionné')) ?>';
      selectedDisplay.style.display = 'block';
    }
    if (submitBtn) submitBtn.disabled = false;
  }

  function loadSlots(date) {
    if (!date) return;

    // Check if day is open
    if (!isDayOpen(date)) {
      clearSlots();
      slotWrap.style.display = 'block';
      slotGrid.innerHTML = '<p class="slot-closed">🔴 <?= e(t('This business is closed on this day. Please choose another date.','Cette entreprise est fermée ce jour. Veuillez choisir une autre date.')) ?></p>';
      if (dateHint) dateHint.textContent = '';
      return;
    }

    clearSlots();
    slotWrap.style.display = 'block';
    slotGrid.innerHTML = '<p class="slot-loading">⏳ <?= e(t('Loading available slots...','Chargement des créneaux...')) ?></p>';

    fetch('/booking-slots.php?listing_id=' + listingId + '&date=' + encodeURIComponent(date))
      .then(function(r) { return r.json(); })
      .then(function(data) {
        slotGrid.innerHTML = '';

        if (!data.slots || data.slots.length === 0) {
          var msg = '';
          if (data.message === 'closed' || data.message === 'day_blocked') {
            msg = '🔴 <?= e(t('Not available on this day. Please choose another date.','Non disponible ce jour. Veuillez choisir une autre date.')) ?>';
          } else {
            msg = '🔴 <?= e(t('No slots available on this date. Please try another day.','Aucun créneau disponible ce jour. Essayez un autre jour.')) ?>';
          }
          slotGrid.innerHTML = '<p class="slot-none">' + msg + '</p>';
          if (dateHint) dateHint.textContent = '';
          return;
        }

        // Build slot buttons
        data.slots.forEach(function(slot) {
          var btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'slot-btn';
          btn.textContent = formatTime12(slot);
          btn.setAttribute('data-time', slot);
          btn.addEventListener('click', function() { selectSlot(slot); });
          slotGrid.appendChild(btn);
        });

        var taken   = data.total - data.slots.length;
        var hint    = data.slots.length + ' <?= e(t('slots available','créneaux disponibles')) ?>';
        if (taken > 0) hint += ' · ' + taken + ' <?= e(t('taken','pris')) ?>';
        if (slotHint) slotHint.textContent = hint;
        if (dateHint) dateHint.textContent = '';

        // Auto-select if coming back after error
        var prevTime = '<?= e($_POST['preferred_time'] ?? '') ?>';
        if (prevTime && data.slots.includes(prevTime)) {
          selectSlot(prevTime);
        }
      })
      .catch(function() {
        slotGrid.innerHTML = '<p class="slot-none">⚠️ <?= e(t('Could not load slots. Please try again.','Impossible de charger les créneaux. Veuillez réessayer.')) ?></p>';
      });
  }

  if (dateInput) {
    dateInput.addEventListener('change', function() { loadSlots(this.value); });
    // Auto-load if date already set (error repopulation)
    if (dateInput.value) loadSlots(dateInput.value);
  }

  // Prevent submit without a slot selected
  var form = document.getElementById('bookingForm');
  if (form) {
    form.addEventListener('submit', function(e) {
      if (!selectedTime.value) {
        e.preventDefault();
        alert('<?= e(t('Please select a time slot before submitting.','Veuillez sélectionner un créneau horaire avant de soumettre.')) ?>');
      }
    });
  }

})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
