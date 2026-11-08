<?php
// Clear OPcache for manage-growth-partners.php
$file = __DIR__ . '/manage-growth-partners.php';
echo "OPcache enabled: " . (function_exists('opcache_get_status') ? 'YES' : 'NO') . "\n";
if (function_exists('opcache_invalidate')) {
    $result = opcache_invalidate($file, true);
    echo "opcache_invalidate result: " . ($result ? 'TRUE' : 'FALSE') . "\n";
}
if (function_exists('opcache_reset')) {
    $result = opcache_reset();
    echo "opcache_reset result: " . ($result ? 'TRUE' : 'FALSE') . "\n";
}
// Also show current file content lines 94-105
$lines = file($file);
echo "\nLines 94-105 on disk:\n";
for ($i = 93; $i < min(105, count($lines)); $i++) {
    echo ($i+1) . ": " . $lines[$i];
}
// Show line count with pp_id
$content = file_get_contents($file);
echo "\n\nOccurrences of 'pp_id' in file: " . substr_count($content, 'pp_id') . "\n";
echo "Occurrences of 'GROUP BY' in file: " . substr_count($content, 'GROUP BY') . "\n";
