<?php
require_once __DIR__ . '/../includes/config.php';
requireLogin();
if (!isAdmin()) { http_response_code(403); exit('Admin only'); }

header('Content-Type: text/plain');
error_reporting(E_ALL);
ini_set('display_errors', 1);

$u = currentUser();

// Same query as my-enquiries.php
$myListings = db()->query("SELECT id,title,slug FROM listings WHERE status='approved' ORDER BY title ASC")->fetchAll();
$listingIds = array_column($myListings, 'id');
echo "Listings count: " . count($listingIds) . "\n";

$inClause = implode(',', array_fill(0, count($listingIds), '?'));
$params = $listingIds;
$filterLid = 121;
$params[] = $filterLid;

try {
    $eq = db()->prepare("
        SELECT e.*,
               l.title AS listing_title, l.slug AS listing_slug,
               COALESCE(e.crm_status, IF(e.status='unread','new','contacted')) AS eff_status
        FROM listing_enquiries e
        JOIN listings l ON l.id = e.listing_id
        WHERE e.listing_id IN ($inClause) AND e.listing_id = ?
        ORDER BY e.created_at DESC
        LIMIT 150
    ");
    $eq->execute($params);
    $enquiries = $eq->fetchAll();
    echo "Enquiries count: " . count($enquiries) . "\n";
    if ($enquiries) {
        $enq = $enquiries[0];
        echo "Keys: " . implode(', ', array_keys($enq)) . "\n\n";
        echo "sender_name: " . ($enq['sender_name'] ?? 'NULL') . "\n";
        echo "sender_email: " . ($enq['sender_email'] ?? 'NULL') . "\n";
        echo "sender_phone: " . ($enq['sender_phone'] ?? 'NULL') . "\n";
        echo "eff_status: " . ($enq['eff_status'] ?? 'NULL') . "\n";
        echo "crm_status: " . ($enq['crm_status'] ?? 'NULL') . "\n";
        echo "created_at: " . ($enq['created_at'] ?? 'NULL') . "\n";
        echo "message: " . substr($enq['message'] ?? '', 0, 100) . "\n";

        // Test the strtotime that could crash
        echo "\nTesting date('d M Y H:i', strtotime(created_at)):\n";
        echo date('d M Y H:i', strtotime($enq['created_at'])) . "\n";

        // Test mb_strlen
        $msg = $enq['message'] ?? '';
        echo "mb_strlen(message): " . mb_strlen($msg) . "\n";
        echo "mb_substr: " . mb_substr($msg, 0, 200) . "\n";
    }
} catch (Exception $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
