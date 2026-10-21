<?php
/**
 * diag3.php — step-by-step render test for my-enquiries section
 * DELETE AFTER USE
 */
require_once __DIR__ . '/../includes/config.php';
requireLogin();
if (!isAdmin()) { http_response_code(403); exit('Admin only'); }

error_reporting(E_ALL);
ini_set('display_errors', 1);

$u = currentUser();

echo "STEP 1: user loaded OK\n";

$myListings = db()->query("SELECT id,title,slug FROM listings WHERE status='approved' ORDER BY title ASC")->fetchAll();
$listingIds = array_column($myListings, 'id');
echo "STEP 2: listings loaded: " . count($listingIds) . "\n";

$filterLid    = 121;
$filterStatus = 'all';

$inClause = implode(',', array_fill(0, count($listingIds), '?'));
$params   = $listingIds;
$params[] = $filterLid;

$eq = db()->prepare("
    SELECT e.*,
           l.title AS listing_title, l.slug AS listing_slug,
           COALESCE(e.crm_status, IF(e.status='unread','new','contacted')) AS eff_status
    FROM listing_enquiries e
    JOIN listings l ON l.id = e.listing_id
    WHERE e.listing_id IN ($inClause) AND e.listing_id = ?
    ORDER BY e.created_at DESC LIMIT 150
");
$eq->execute($params);
$enquiries = $eq->fetchAll();
echo "STEP 3: enquiries loaded: " . count($enquiries) . "\n";

// Replicate the status counts
$statusCounts = ['all'=>count($enquiries),'new'=>0,'contacted'=>0,'quoted'=>0,'won'=>0,'lost'=>0];
foreach ($enquiries as $enq) {
    $s = $enq['eff_status'] ?? 'new';
    if (isset($statusCounts[$s])) $statusCounts[$s]++;
}
echo "STEP 4: status counts: " . json_encode($statusCounts) . "\n";

// Test flash() return value
echo "STEP 5: testing flash()...\n";
$flashOut = flash();
echo "STEP 5: flash() returned: " . var_export($flashOut, true) . "\n";

// Test tab labels with t()
echo "STEP 6: testing t()...\n";
$tabLabels = [
    'all'       => t('All','Tout'),
    'new'       => t('New','Nouveau'),
    'contacted' => t('Contacted','Contacté'),
    'quoted'    => t('Quoted','Devis'),
    'won'       => t('Won','Gagné'),
    'lost'      => t('Lost','Perdu'),
];
echo "STEP 6: t() OK: " . json_encode($tabLabels) . "\n";

// Replicate the foreach
echo "STEP 7: testing foreach render...\n";
foreach ($enquiries as $i => $enq) {
    echo "  enq[$i] id={$enq['id']}\n";

    $eid     = (int)$enq['id'];
    $eStatus = $enq['eff_status'] ?? 'new';
    echo "  eid=$eid eStatus=$eStatus\n";

    $statusLabels = [
        'new'       => ['label'=>t('New','Nouveau'),       'class'=>'badge-new'],
        'contacted' => ['label'=>t('Contacted','Contacté'),'class'=>'badge-contacted'],
        'quoted'    => ['label'=>t('Quoted','Devis'),      'class'=>'badge-quoted'],
        'won'       => ['label'=>t('Won','Gagné'),         'class'=>'badge-won'],
        'lost'      => ['label'=>t('Lost','Perdu'),        'class'=>'badge-lost'],
    ];
    $sl = $statusLabels[$eStatus] ?? $statusLabels['new'];
    echo "  statusLabel: " . json_encode($sl) . "\n";

    // Test e()
    echo "  e(sender_name): " . e($enq['sender_name'] ?? t('Anonymous','Anonyme')) . "\n";
    echo "  e(ref): " . (empty($enq['ref']) ? 'empty' : e($enq['ref'])) . "\n";

    // Test date on created_at
    echo "  date(created_at): " . date('d M Y H:i', strtotime($enq['created_at'])) . "\n";

    // Test sender_email / phone
    echo "  sender_email empty: " . (empty($enq['sender_email']) ? 'yes' : 'no') . "\n";
    echo "  sender_phone empty: " . (empty($enq['sender_phone']) ? 'yes' : 'no') . "\n";

    // Test message truncation
    $msg = $enq['message'] ?? '';
    $short = mb_strlen($msg) > 200;
    echo "  message short: " . ($short ? 'yes' : 'no') . "\n";

    // Test follow_up_at
    echo "  follow_up_at empty: " . (empty($enq['follow_up_at']) ? 'yes' : 'no') . "\n";

    // Test source_type
    echo "  source_type empty: " . (empty($enq['source_type']) ? 'yes' : "no: " . $enq['source_type']) . "\n";

    // Test won_at
    echo "  won_at empty: " . (empty($enq['won_at']) ? 'yes' : 'no') . "\n";

    // Test lost_reason
    echo "  lost_reason empty: " . (empty($enq['lost_reason']) ? 'yes' : 'no') . "\n";

    // Test csrf()
    echo "  csrf(): " . (function_exists('csrf') ? 'function exists' : 'MISSING') . "\n";
    $csrfOut = csrf();
    echo "  csrf() output length: " . strlen($csrfOut) . "\n";

    // Test notes
    echo "  e(notes): " . e($enq['notes'] ?? '') . "\n";

    // Test lost_at / follow_up_at for date
    if (!empty($enq['follow_up_at'])) {
        echo "  follow_up_at date: " . date('Y-m-d', strtotime($enq['follow_up_at'])) . "\n";
        echo "  strtotime < time: " . (strtotime($enq['follow_up_at']) < time() ? 'yes' : 'no') . "\n";
    }

    echo "  enq[$i] COMPLETE OK\n";
}

echo "STEP 7: foreach DONE\n";
echo "\nALL STEPS PASSED - render should work\n";
