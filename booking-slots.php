<?php
/**
 * booking-slots.php — 237Biz
 * AJAX endpoint: returns available time slots for a listing on a given date.
 * Called directly as /booking-slots.php?listing_id=X&date=2026-09-01
 * No .htaccess rewrite needed — direct PHP file access.
 */
require_once __DIR__ . '/includes/config.php';

header('Content-Type: application/json; charset=utf-8');

function jsonOut(array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$listingId = (int)($_GET['listing_id'] ?? 0);
$date      = trim($_GET['date'] ?? '');

// Basic validation
if (!$listingId || !$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    jsonOut(['slots' => [], 'message' => 'invalid_request']);
}

$ts = strtotime($date);
if (!$ts || $ts < strtotime('today')) {
    jsonOut(['slots' => [], 'message' => 'past_date']);
}

// Load booking settings from listing
try {
    $st = db()->prepare("
        SELECT booking_enabled, booking_slot_duration, hours_json
        FROM listings WHERE id=? AND featured=1
    ");
    $st->execute([$listingId]);
    $l = $st->fetch();
} catch (Exception $e) {
    jsonOut(['slots' => [], 'message' => 'db_error']);
}

if (!$l || empty($l['booking_enabled'])) {
    jsonOut(['slots' => [], 'message' => 'booking_not_enabled']);
}

$slotMins = (int)($l['booking_slot_duration'] ?? 30);
if (!in_array($slotMins, [15, 30, 45, 60])) $slotMins = 30;

// Check business hours for this day
$hours     = json_decode($l['hours_json'] ?? '{}', true) ?: [];
$dayOfWeek = date('l', $ts); // e.g. "Monday"

if (empty($hours[$dayOfWeek]['open'])) {
    jsonOut(['slots' => [], 'message' => 'closed', 'day' => $dayOfWeek]);
}

$fromTime = $hours[$dayOfWeek]['from'] ?? '08:00';
$toTime   = $hours[$dayOfWeek]['to']   ?? '17:00';

// Generate all possible slots for the day
$slotStart = strtotime($date . ' ' . $fromTime);
$slotEnd   = strtotime($date . ' ' . $toTime);
$nowTs     = time();
$allSlots  = [];

for ($t = $slotStart; $t < $slotEnd; $t += $slotMins * 60) {
    if ($t > $nowTs) { // skip past slots if today
        $allSlots[] = date('H:i', $t);
    }
}

if (empty($allSlots)) {
    jsonOut(['slots' => [], 'message' => 'no_slots_today']);
}

// ── Remove slots with existing bookings (pending or confirmed) ──────────
try {
    $bk = db()->prepare("
        SELECT preferred_time FROM listing_bookings
        WHERE listing_id=? AND preferred_date=?
          AND status IN ('pending','confirmed')
          AND preferred_time IS NOT NULL
    ");
    $bk->execute([$listingId, $date]);
    $bookedTimes = array_column($bk->fetchAll(), 'preferred_time');
} catch (Exception $e) {
    $bookedTimes = [];
}

// ── Remove blocked times ────────────────────────────────────────────────
$blockedTimes = [];
try {
    // Check if whole day is blocked
    $wd = db()->prepare("
        SELECT COUNT(*) FROM listing_blocked_slots
        WHERE listing_id=? AND blocked_date=? AND blocked_time IS NULL
    ");
    $wd->execute([$listingId, $date]);
    if ((int)$wd->fetchColumn() > 0) {
        jsonOut(['slots' => [], 'message' => 'day_blocked', 'day' => $dayOfWeek]);
    }

    // Individual blocked times
    $bt = db()->prepare("
        SELECT blocked_time FROM listing_blocked_slots
        WHERE listing_id=? AND blocked_date=? AND blocked_time IS NOT NULL
    ");
    $bt->execute([$listingId, $date]);
    $blockedTimes = array_column($bt->fetchAll(), 'blocked_time');
} catch (Exception $e) {
    $blockedTimes = [];
}

// ── Filter to available slots ────────────────────────────────────────────
$available = array_values(array_filter($allSlots, function($slot) use ($bookedTimes, $blockedTimes) {
    return !in_array($slot, $bookedTimes) && !in_array($slot, $blockedTimes);
}));

jsonOut([
    'slots'    => $available,
    'duration' => $slotMins,
    'total'    => count($allSlots),
    'taken'    => count($bookedTimes),
    'blocked'  => count($blockedTimes),
    'day'      => $dayOfWeek,
    'hours'    => $fromTime . ' – ' . $toTime,
]);
