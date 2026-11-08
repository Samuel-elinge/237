<?php
/**
 * One-time script: activate marketplace categories 1, 2, 3
 * DELETE THIS FILE after running.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';
requireAdmin();

$pdo = db();

$results = [];
foreach ([1, 2, 3] as $catId) {
    $pdo->prepare("UPDATE mp_service_categories SET active=1, orderable=1 WHERE id=?")->execute([$catId]);
    $cat = $pdo->prepare("SELECT id, name_en, active, orderable FROM mp_service_categories WHERE id=?")->execute([$catId])
        ? $pdo->query("SELECT id, name_en, active, orderable FROM mp_service_categories WHERE id=$catId")->fetch(PDO::FETCH_ASSOC)
        : null;
    $results[] = $cat;
}

header('Content-Type: text/plain');
echo "Done.\n\n";
foreach ($results as $r) {
    echo "ID={$r['id']} name={$r['name_en']} active={$r['active']} orderable={$r['orderable']}\n";
}
