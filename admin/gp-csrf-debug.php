<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
/**
 * CSRF debug — DELETE after use
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';
requireAdmin();

// Test that gp functions exist
echo "<p>gpCsrfField exists: " . (function_exists('gpCsrfField') ? 'YES' : 'NO') . "</p>";
echo "<p>gpVerifyCsrf exists: " . (function_exists('gpVerifyCsrf') ? 'YES' : 'NO') . "</p>";

// Simulate what manage-growth-partners does on POST
$result = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    gpVerifyCsrf();
    $result = "<p style='color:green'><b>gpVerifyCsrf passed!</b></p>";
}
?>
<!doctype html><html><body>
<h2>CSRF Debug</h2>
<?= $result ?>
<form method="post">
<?= gpCsrfField() ?>
<button type="submit">Test gpVerifyCsrf POST</button>
</form>
<p>_gp_csrf: <?= htmlspecialchars($_SESSION['_gp_csrf'] ?? '(none)') ?></p>
<p>csrf_token (config.php): <?= htmlspecialchars($_SESSION['csrf_token'] ?? '(none)') ?></p>
</body></html>
