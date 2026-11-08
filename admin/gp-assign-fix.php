<?php
require_once __DIR__ . '/../includes/config.php';
requireAdmin();
$pdo = db();
$r = $pdo->query("SELECT id, title, user_id FROM listings WHERE user_id IN (2,19) ORDER BY user_id,id")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($r);
