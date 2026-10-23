<?php
/**
 * Temporary admin tool: bulk assign Premium (plan_id=3) to listings 1,2,3,4
 * DELETE after use.
 */
require_once __DIR__ . '/../includes/config.php';
requireLogin();
if (!isAdmin()) { http_response_code(403); exit('Forbidden'); }

$listing_ids = [1, 2, 3, 4];
$plan_id     = 3; // Premium
$months      = 12;
$admin_note  = 'Founding partner — complimentary year';

$results = [];

foreach ($listing_ids as $lid) {
    // Fetch listing
    $st = db()->prepare("SELECT id, user_id, title AS business_name FROM listings WHERE id = ?");
    $st->execute([$lid]);
    $listing = $st->fetch();

    if (!$listing) {
        $results[] = "Listing #$lid: NOT FOUND";
        continue;
    }

    // Fetch plan
    $pl = db()->prepare("SELECT * FROM subscription_plans WHERE id = ? AND active = 1");
    $pl->execute([$plan_id]);
    $plan = $pl->fetch();

    if (!$plan) {
        $results[] = "Listing #$lid ({$listing['business_name']}): plan not found";
        continue;
    }

    $startsAt = date('Y-m-d H:i:s');
    $endsAt   = date('Y-m-d H:i:s', strtotime("+{$months} months"));

    // Cancel existing active subscriptions
    db()->prepare("
        UPDATE business_subscriptions
        SET status = 'cancelled', cancelled_at = NOW(),
            cancellation_reason = 'Superseded by admin manual assignment'
        WHERE listing_id = ? AND status IN ('active','pending_payment','past_due')
    ")->execute([$lid]);

    // Insert new active subscription
    $ins = db()->prepare("
        INSERT INTO business_subscriptions
            (listing_id, user_id, plan_id, status, starts_at, ends_at, admin_note, created_at, updated_at)
        VALUES (?, ?, ?, 'active', ?, ?, ?, NOW(), NOW())
    ");
    $ins->execute([$listing['id'], $listing['user_id'], $plan_id, $startsAt, $endsAt, $admin_note]);

    $results[] = "✅ Listing #$lid ({$listing['business_name']}): Premium assigned, expires " . date('d M Y', strtotime($endsAt));
}

// Verify
$verify = db()->query("
    SELECT l.id, l.title, sp.name_en AS plan, bs.ends_at
    FROM listings l
    LEFT JOIN business_subscriptions bs ON bs.listing_id = l.id AND bs.status = 'active'
    LEFT JOIN subscription_plans sp ON sp.id = bs.plan_id
    WHERE l.id IN (1,2,3,4)
    ORDER BY l.id
")->fetchAll();

echo '<pre>';
echo "=== ASSIGNMENT RESULTS ===\n\n";
foreach ($results as $r) echo $r . "\n";
echo "\n=== VERIFICATION ===\n\n";
foreach ($verify as $row) {
    echo "#{$row['id']} {$row['title']}: {$row['plan']} (expires: {$row['ends_at']})\n";
}
echo '</pre>';
echo '<p><a href="assign-subscription.php">← Back to Assign Subscription</a></p>';
echo '<p><strong>Remember to delete this file after verifying!</strong></p>';
