<?php
/**
 * booking.php — 237Biz Unified Booking
 * URL: /booking?slug=maroon-hosting
 *
 * Supports four booking types:
 *   appointment — date + time slot (original flow)
 *   table       — date + time slot + party size
 *   service     — date + time slot + service selection
 *   experience  — date + time slot + party size + notes
 */
require_once __DIR__ . '/includes/config.php';

$slug = trim($_GET['slug'] ?? '');
if (!$slug) redirect(SITE_URL . '/listings');

// ── Load listing ──────────────────────────────────────────────────────
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

// ── Load booking settings ─────────────────────────────────────────────
$bookingEnabled    = false;
$bookingServices   = [];
$bookingTypes      = ['appointment']; // enabled booking types for this listing
$slotDuration      = 30;
$advanceDays       = 30;

try {
    $bc = db()->prepare("
        SELECT booking_enabled, booking_services, booking_slot_duration,
               booking_advance, booking_types
        FROM listings WHERE id=?
    ");
    $bc->execute([$listing['id']]);
    $bRow = $bc->fetch();
    $bookingEnabled  = !empty($bRow['booking_enabled']);
    $bookingServices = json_decode($bRow['booking_services'] ?? '[]', true) ?: [];
    $slotDuration    = (int)($bRow['booking_slot_duration'] ?? 30);
    $advanceDays     = max(1, min(365, (int)($bRow['booking_advance'] ?? 30)));
    if (!in_array($slotDuration, [15, 30, 45, 60])) $slotDuration = 30;

    // Decode booking_types JSON column (new in Phase 1 migration)
    $enabledTypes = json_decode($bRow['booking_types'] ?? 'null', true);
    if (is_array($enabledTypes) && !empty($enabledTypes)) {
        $allowedTypes = ['appointment','table','service','experience'];
        $bookingTypes = array_values(array_intersect($allowedTypes, $enabledTypes));
    }
    if (empty($bookingTypes)) $bookingTypes = ['appointment'];
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

// Determine active booking type (from GET ?type= or first enabled)
$requestedType = $_GET['type'] ?? ($_POST['booking_type'] ?? '');
$activeType = in_array($requestedType, $bookingTypes) ? $requestedType : $bookingTypes[0];

// Type meta
$typeLabel = [
    'appointment' => t('Appointment','Rendez-vous'),
    'table'       => t('Table Reservation','Réservation de table'),
    'service'     => t('Book a Service','Réserver un service'),
    'experience'  => t('Book an Experience','Réserver une expérience'),
];
$typeIcon = [
    'appointment' => '📅',
    'table'       => '🍽️',
    'service'     => '🛠️',
    'experience'  => '🎟️',
];
$typeHero = [
    'appointment' => t('Schedule your appointment in just a few clicks.','Prenez rendez-vous en quelques clics.'),
    'table'       => t('Reserve your table — select a date, time and party size.','Réservez votre table — choisissez date, heure et nombre de convives.'),
    'service'     => t('Book a service — pick what you need and a convenient time.','Réservez un service — choisissez ce dont vous avez besoin et un créneau.'),
    'experience'  => t('Book your experience — slots are limited, book early!','Réservez votre expérience — les places sont limitées, réservez tôt !'),
];

$hours    = json_decode($listing['hours_json'] ?? '{}', true) ?: [];
$days     = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
$openDays = [];
foreach ($days as $i => $day) {
    if (!empty($hours[$day]['open'])) {
        $openDays[] = ($i === 6) ? 0 : $i + 1;
    }
}

// ── POST handler ──────────────────────────────────────────────────────
$success = false;
$booking = null;
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $bType   = $_POST['booking_type']    ?? 'appointment';
    if (!in_array($bType, $bookingTypes)) $bType = $bookingTypes[0];

    $name        = trim($_POST['customer_name']    ?? '');
    $phone       = trim($_POST['customer_phone']   ?? '');
    $email       = trim($_POST['customer_email']   ?? '');
    $service     = trim($_POST['service_name']     ?? '');
    $date        = trim($_POST['preferred_date']   ?? '');
    $time        = trim($_POST['preferred_time']   ?? '');
    $message     = trim($_POST['message']          ?? '');
    $partySize   = (int)($_POST['party_size']      ?? 0);
    $specialReqs = trim($_POST['special_requests'] ?? '');

    if (!$name)  $errors[] = t('Your name is required.',  'Votre nom est requis.');
    if (!$phone) $errors[] = t('Your phone number is required.','Votre numéro de téléphone est requis.');
    if (!$date)  $errors[] = t('Please select a date.','Veuillez choisir une date.');
    if (!$time)  $errors[] = t('Please select a time slot.','Veuillez choisir un créneau horaire.');

    // Party size required for table & experience
    if (in_array($bType, ['table','experience']) && $partySize < 1) {
        $errors[] = t('Please enter the number of guests.','Veuillez indiquer le nombre de convives.');
    }

    // Verify slot still available
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

        // Build special_requests from dedicated field or message fallback
        $finalSpecial = $specialReqs ?: ($message ?: null);

        // Insert — handle new columns gracefully
        try {
            db()->prepare("
                INSERT INTO listing_bookings
                  (listing_id, reference, customer_name, customer_phone, customer_email,
                   service_name, preferred_date, preferred_time, message,
                   booking_type, party_size, special_requests)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $listing['id'], $ref, $name, $phone, $email ?: null,
                $service ?: null, $date, $time, $message ?: null,
                $bType,
                ($partySize > 0 ? $partySize : null),
                ($specialReqs ?: null),
            ]);
        } catch (Exception $e) {
            // Fallback: insert without new columns if migration not yet run
            db()->prepare("
                INSERT INTO listing_bookings
                  (listing_id, reference, customer_name, customer_phone, customer_email,
                   service_name, preferred_date, preferred_time, message)
                VALUES (?,?,?,?,?,?,?,?,?)
            ")->execute([
                $listing['id'], $ref, $name, $phone, $email ?: null,
                $service ?: null, $date, $time, $finalSpecial,
            ]);
        }

        // ── Email to business ─────────────────────────────────────────
        if ($listing['email']) {
            $sub  = "New " . ucfirst($bType) . " [{$ref}] — {$listing['title']}";
            $body = "You have a new booking on 237Biz.\n\n";
            $body .= "Type: " . ucfirst($bType) . "\n";
            $body .= "Reference: {$ref}\nCustomer: {$name}\nPhone: {$phone}\n";
            if ($email)      $body .= "Email: {$email}\n";
            if ($service)    $body .= "Service: {$service}\n";
            if ($partySize)  $body .= "Guests: {$partySize}\n";
            $body .= "Date: {$date} at {$time}\n";
            if ($specialReqs) $body .= "Special requests: {$specialReqs}\n";
            elseif ($message) $body .= "Note: {$message}\n";
            $body .= "\nManage this booking:\n" . SITE_URL . "/partner/bookings\n\n";
            $body .= "— 237Biz.net\n" . SITE_URL . "/help";
            @mail($listing['email'], $sub, $body, "From: 237Biz <noreply@237biz.net>\r\n");
        }

        // ── Email to customer ─────────────────────────────────────────
        if ($email) {
            $sub  = ucfirst($bType) . " Received [{$ref}] — {$listing['title']}";
            $body = "Thank you! Your booking request has been received.\n\n";
            $body .= "Reference: {$ref}\nBusiness: {$listing['title']}\n";
            if ($service)   $body .= "Service: {$service}\n";
            if ($partySize) $body .= "Guests: {$partySize}\n";
            $body .= "Date: {$date} at {$time}\n";
            $body .= "Status: Pending confirmation — the business will confirm shortly.\n\n";
            $body .= SITE_URL . "/listing/{$slug}\n\n";
            $body .= "— 237Biz.net\n" . SITE_URL . "/help";
            @mail($email, $sub, $body, "From: 237Biz <noreply@237biz.net>\r\n");
        }

        // ── WhatsApp notification ─────────────────────────────────────
        $waMsg  = ($typeIcon[$bType] ?? '📅') . " New " . ucfirst($bType) . " [{$ref}] via 237Biz\n";
        $waMsg .= "Customer: {$name} ({$phone})\n";
        if ($service)    $waMsg .= "Service: {$service}\n";
        if ($partySize)  $waMsg .= "Guests: {$partySize}\n";
        $waMsg .= "Date: {$date} at {$time}\n";
        if ($specialReqs) $waMsg .= "Note: {$specialReqs}\n";
        elseif ($message) $waMsg .= "Note: {$message}\n";
        $waMsg .= "Manage: " . SITE_URL . "/partner/bookings";

        $waPhone = preg_replace('/\D/', '', $listing['whatsapp'] ?? '');
        $waLink  = $waPhone ? "https://wa.me/{$waPhone}?text=" . rawurlencode($waMsg) : null;

        $booking = [
            'ref'       => $ref,
            'type'      => $bType,
            'date'      => $date,
            'time'      => $time,
            'service'   => $service,
            'party'     => $partySize,
            'wa_link'   => $waLink,
        ];
        $success = true;
    }
}

$pageTitle = ($typeLabel[$activeType] ?? t('Book','Réserver')) . ' — ' . e($listing['title']);
require_once __DIR__ . '/includes/header.php';
?>

<style>
.booking-wrap   { max-width:640px; margin:0 auto; padding:24px 16px 60px; }
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
@media(max-width:480px){ .bk-row { grid-template-columns:1fr; } }
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

/* Type tabs */
.type-tabs { display:flex; gap:6px; flex-wrap:wrap; padding:14px 20px 0; }
.type-tab  {
  display:inline-flex; align-items:center; gap:5px;
  padding:7px 14px; border-radius:99px; font-size:13px; font-weight:600;
  border:1.5px solid rgba(255,255,255,0.1); color:rgba(255,255,255,0.55);
  text-decoration:none; transition:all .15s;
}
.type-tab:hover  { border-color:rgba(0,168,120,0.5); color:#00A878; }
.type-tab.active { background:rgba(0,168,120,0.15); border-color:#00A878; color:#00A878; }

/* Open days */
.open-days { display:flex; gap:5px; flex-wrap:wrap; margin-bottom:6px; }
.day-chip  { font-size:11px; padding:3px 9px; border-radius:99px; border:1px solid; }
.day-open  { background:rgba(0,168,120,0.1); color:#00A878; border-color:rgba(0,168,120,0.3); }
.day-closed{ background:rgba(255,255,255,0.03); color:rgba(255,255,255,0.3); border-color:rgba(255,255,255,0.08); }

/* Success */
.bk-ref { display:inline-block; background:rgba(0,168,120,0.15); border:1px solid rgba(0,168,120,0.4); color:#00A878; border-radius:8px; padding:10px 18px; font-size:1.1rem; font-weight:700; letter-spacing:2px; margin:12px 0; }

/* Party size stepper */
.party-stepper { display:flex; align-items:center; gap:10px; }
.party-stepper button { width:32px; height:32px; border-radius:8px; border:1.5px solid rgba(255,255,255,0.15); background:rgba(255,255,255,0.05); color:#fff; font-size:1.2rem; cursor:pointer; font-family:inherit; display:flex; align-items:center; justify-content:center; transition:all .15s; }
.party-stepper button:hover { border-color:#00A878; background:rgba(0,168,120,0.15); color:#00A878; }
.party-stepper .party-val { font-size:1.1rem; font-weight:700; min-width:28px; text-align:center; }
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
        <div><?= $typeIcon[$booking['type']] ?? '📅' ?> <strong><?= $typeLabel[$booking['type']] ?? 'Booking' ?></strong></div>
        <div>📅 <strong><?= e($booking['date']) ?></strong> <?= t('at','à') ?> <strong><?= e($booking['time']) ?></strong></div>
        <?php if ($booking['service']): ?><div>🛠️ <?= e($booking['service']) ?></div><?php endif; ?>
        <?php if ($booking['party'] > 0): ?><div>👥 <?= (int)$booking['party'] ?> <?= t('guests','convives') ?></div><?php endif; ?>
        <div>🏢 <?= e($listing['title']) ?></div>
        <div>📍 <?= e($listing['loc_en']) ?></div>
      </div>
    </div>
    <p style="font-size:13px;color:var(--muted);margin-bottom:18px;">
      <?= t('The business will confirm your booking shortly.','L\'entreprise confirmera votre réservation prochainement.') ?>
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

  <!-- Type tabs (only shown if >1 type enabled) -->
  <?php if (count($bookingTypes) > 1): ?>
  <div class="type-tabs">
    <?php foreach ($bookingTypes as $bt): ?>
    <a href="?slug=<?= e($slug) ?>&type=<?= $bt ?>"
       class="type-tab <?= $bt === $activeType ? 'active' : '' ?>">
      <?= $typeIcon[$bt] ?? '' ?> <?= $typeLabel[$bt] ?? $bt ?>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Hero -->
  <div class="booking-hero" <?= count($bookingTypes) > 1 ? 'style="margin-top:12px;"' : '' ?>>
    <h1><?= ($typeIcon[$activeType] ?? '📅') . ' ' . ($typeLabel[$activeType] ?? t('Book','Réserver')) ?></h1>
    <p><?= $typeHero[$activeType] ?? '' ?></p>
  </div>

  <div class="booking-body">
    <form method="POST" id="bookingForm">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="booking_type" value="<?= e($activeType) ?>">
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

      <?php /* ── Service selection (appointment + service types) ── */ ?>
      <?php if (!empty($bookingServices) && in_array($activeType, ['appointment','service'])): ?>
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

      <?php /* ── Party size (table + experience types) ── */ ?>
      <?php if (in_array($activeType, ['table','experience'])): ?>
      <div class="bk-section-lbl"><?= $activeType === 'table' ? t('Reservation Details','Détails de la réservation') : t('Experience Details','Détails de l\'expérience') ?></div>
      <div class="bk-group">
        <label><?= t('Number of Guests','Nombre de convives') ?> <span class="bk-req">*</span></label>
        <div class="party-stepper">
          <button type="button" onclick="adjustParty(-1)">−</button>
          <span class="party-val" id="partyDisplay">1</span>
          <button type="button" onclick="adjustParty(1)">+</button>
          <span style="font-size:13px;color:var(--muted);margin-left:4px;"><?= t('people','personnes') ?></span>
        </div>
        <input type="hidden" name="party_size" id="partySize" value="<?= (int)($_POST['party_size'] ?? 1) ?: 1 ?>">
      </div>
      <?php else: ?>
      <input type="hidden" name="party_size" value="0">
      <?php endif; ?>

      <!-- Date & Time -->
      <div class="bk-section-lbl"><?= t('Date & Time','Date et heure') ?></div>

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

      <div id="selectedDisplay" style="display:none;margin-bottom:12px;padding:10px 14px;background:rgba(0,168,120,0.1);border:1px solid rgba(0,168,120,0.3);border-radius:8px;font-size:14px;font-weight:600;color:#00A878;"></div>

      <?php /* ── Special requests (experience + table types) ── */ ?>
      <?php if (in_array($activeType, ['table','experience'])): ?>
      <div class="bk-group">
        <label><?= $activeType === 'table'
          ? t('Special Requests','Demandes spéciales')
          : t('Notes / Special Requests','Notes / Demandes spéciales') ?>
          <?= t('(optional)','(optionnel)') ?></label>
        <textarea name="special_requests" rows="2"
                  placeholder="<?= e(t('Dietary requirements, accessibility needs, celebrations…','Régime alimentaire, accessibilité, célébrations…')) ?>"><?= e($_POST['special_requests'] ?? '') ?></textarea>
      </div>
      <input type="hidden" name="message" value="">
      <?php else: ?>
      <input type="hidden" name="special_requests" value="">
      <div class="bk-group">
        <label><?= t('Message / Notes','Message / Notes') ?> <?= t('(optional)','(optionnel)') ?></label>
        <textarea name="message" rows="2"
                  placeholder="<?= e(t('Any specific requests...','Toute demande spécifique...')) ?>"><?= e($_POST['message'] ?? '') ?></textarea>
      </div>
      <?php endif; ?>

      <button type="submit" class="bk-submit" id="submitBtn" disabled>
        <?= ($typeIcon[$activeType] ?? '📅') . ' ' . ($typeLabel[$activeType] ?? t('Book','Réserver')) ?>
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

  var dateInput       = document.getElementById('bookingDate');
  var slotWrap        = document.getElementById('slotPickerWrap');
  var slotGrid        = document.getElementById('slotGrid');
  var slotHint        = document.getElementById('slotHint');
  var selectedTime    = document.getElementById('selectedTime');
  var selectedDisplay = document.getElementById('selectedDisplay');
  var submitBtn       = document.getElementById('submitBtn');
  var dateHint        = document.getElementById('dateHint');

  var listingId  = <?= (int)$listing['id'] ?>;
  var slotMins   = <?= (int)$slotDuration ?>;
  var openDaysJS = <?= json_encode($openDays) ?>;
  var currentSlot = '';

  // ── Party size stepper ────────────────────────────────────────────
  var partyInput   = document.getElementById('partySize');
  var partyDisplay = document.getElementById('partyDisplay');
  if (partyDisplay && partyInput) {
    partyDisplay.textContent = partyInput.value || '1';
  }
  window.adjustParty = function(delta) {
    if (!partyInput) return;
    var val = parseInt(partyInput.value) || 1;
    val = Math.max(1, Math.min(20, val + delta));
    partyInput.value = val;
    if (partyDisplay) partyDisplay.textContent = val;
  };

  // ── Slot helpers ──────────────────────────────────────────────────
  function isDayOpen(dateStr) {
    if (!dateStr) return true;
    var d   = new Date(dateStr + 'T12:00:00');
    var dow = d.getDay();
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
      selectedDisplay.textContent = '✓ ' + formatTime12(time) + ' <?= e(t('selected','sélectionné')) ?>';
      selectedDisplay.style.display = 'block';
    }
    if (submitBtn) submitBtn.disabled = false;
  }

  function loadSlots(date) {
    if (!date) return;
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
          var msg = (data.message === 'closed' || data.message === 'day_blocked')
            ? '🔴 <?= e(t('Not available on this day. Please choose another date.','Non disponible ce jour. Veuillez choisir une autre date.')) ?>'
            : '🔴 <?= e(t('No slots available on this date. Please try another day.','Aucun créneau disponible ce jour. Essayez un autre jour.')) ?>';
          slotGrid.innerHTML = '<p class="slot-none">' + msg + '</p>';
          if (dateHint) dateHint.textContent = '';
          return;
        }
        data.slots.forEach(function(slot) {
          var btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'slot-btn';
          btn.textContent = formatTime12(slot);
          btn.setAttribute('data-time', slot);
          btn.addEventListener('click', function() { selectSlot(slot); });
          slotGrid.appendChild(btn);
        });
        var taken = data.total - data.slots.length;
        var hint  = data.slots.length + ' <?= e(t('slots available','créneaux disponibles')) ?>';
        if (taken > 0) hint += ' · ' + taken + ' <?= e(t('taken','pris')) ?>';
        if (slotHint) slotHint.textContent = hint;
        if (dateHint) dateHint.textContent = '';
        // Auto-select on error repopulation
        var prevTime = '<?= e($_POST['preferred_time'] ?? '') ?>';
        if (prevTime && data.slots.includes(prevTime)) selectSlot(prevTime);
      })
      .catch(function() {
        slotGrid.innerHTML = '<p class="slot-none">⚠️ <?= e(t('Could not load slots. Please try again.','Impossible de charger les créneaux. Veuillez réessayer.')) ?></p>';
      });
  }

  if (dateInput) {
    dateInput.addEventListener('change', function() { loadSlots(this.value); });
    if (dateInput.value) loadSlots(dateInput.value);
  }

  // Prevent submit without slot
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
