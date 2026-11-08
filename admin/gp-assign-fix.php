<?php
require_once __DIR__ . '/../includes/config.php';
requireAdmin();
$pdo = db();

// Sam uid=19 pp_id=5, Tahirih uid=2 pp_id=6
// Find their listings
$r = $pdo->query("SELECT id,business_name,organiser_id FROM listings WHERE organiser_id IN (2,19) ORDER BY organiser_id,id")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($r);
