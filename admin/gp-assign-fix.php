<?php
require_once __DIR__ . '/../includes/config.php';
requireAdmin();
$pdo = db();
try {
    $r = $pdo->query("SELECT id, business_name, organiser_id FROM listings LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($r);
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
