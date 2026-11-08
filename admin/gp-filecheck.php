<?php
// Check what version of manage-growth-partners.php is deployed
$f = __DIR__ . '/manage-growth-partners.php';
echo "File exists: " . (file_exists($f) ? 'YES' : 'NO') . "\n";
echo "Mod time: " . date('Y-m-d H:i:s', filemtime($f)) . "\n";
echo "File size: " . filesize($f) . " bytes\n";
// Show first 5 lines
$lines = file($f);
echo "First 5 lines:\n";
for ($i = 0; $i < min(5, count($lines)); $i++) {
    echo "  " . ($i+1) . ": " . $lines[$i];
}
// Show line 94-105 (the query)
echo "\nLines 94-105 (partners query):\n";
for ($i = 93; $i < min(105, count($lines)); $i++) {
    echo "  " . ($i+1) . ": " . $lines[$i];
}
