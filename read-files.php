<?php
if (($_GET['key'] ?? '') !== 'read237') die('No access.');
$file  = '/home/biz97h/public_html/listing.php';
$lines = file($file);
echo '<pre style="background:#0a1a0f;color:#ccc;padding:24px;font-size:12px;line-height:1.6;">';

// Show 6 lines around first wa.me occurrence
foreach ($lines as $i => $line) {
    if (strpos($line, 'wa.me/') !== false) {
        echo "=== wa.me context (lines " . ($i-2) . "-" . ($i+6) . ") ===\n";
        for ($j=max(0,$i-2); $j<=min(count($lines)-1,$i+6); $j++) {
            echo ($j===$i?'>>>':'   ') . ' ' . ($j+1) . ': ' . htmlspecialchars(rtrim($lines[$j])) . "\n";
        }
        echo "\n";
        break;
    }
}

// Show last 8 lines of file (footer area)
$total = count($lines);
echo "=== last 8 lines ===\n";
for ($j = max(0, $total-8); $j < $total; $j++) {
    echo '   ' . ($j+1) . ': ' . htmlspecialchars(rtrim($lines[$j])) . "\n";
}
echo '</pre>';
