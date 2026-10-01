<?php
if (($_GET['key'] ?? '') !== 'restore237') die('No access.');

$file   = '/home/biz97h/public_html/listing.php';
$backup = $file . '.bak';

if (!file_exists($backup)) die('Backup file not found: ' . $backup);

$content = file_get_contents($backup);
if (file_put_contents($file, $content) !== false) {
    echo '<p style="font-family:monospace;background:#0a1a0f;color:#00A878;padding:24px;">
    ✅ listing.php restored from backup successfully.<br><br>
    Delete restore-listing.php now.
    </p>';
} else {
    echo '<p style="font-family:monospace;background:#0a1a0f;color:#ff6b7a;padding:24px;">
    ❌ Could not write file — check permissions.
    </p>';
}
