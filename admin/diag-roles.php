<?php
require_once __DIR__ . '/../includes/config.php';
requireAdmin();
header('Content-Type: text/plain');

// users table columns
$cols = db()->query("DESCRIBE users")->fetchAll(PDO::FETCH_ASSOC);
echo "=== users table columns ===\n";
foreach ($cols as $c) echo $c['Field'] . ' ' . $c['Type'] . ' ' . ($c['Null']==='YES'?'NULL':'NOT NULL') . ' default=' . $c['Default'] . "\n";

// existing roles
echo "\n=== role counts ===\n";
$rows = db()->query("SELECT role, COUNT(*) as n FROM users GROUP BY role ORDER BY n DESC")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) echo $r['role'] . ': ' . $r['n'] . "\n";

// any permissions table?
echo "\n=== permission-related tables ===\n";
$tables = db()->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) {
    if (stripos($t,'perm')!==false||stripos($t,'role')!==false||stripos($t,'audit')!==false||stripos($t,'request')!==false) echo $t."\n";
}
echo "\nAll tables:\n";
echo implode(', ', $tables) . "\n";
