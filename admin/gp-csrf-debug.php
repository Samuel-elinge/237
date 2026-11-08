<?php
/**
 * CSRF debug — DELETE after use
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';
requireAdmin();

$result = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted  = $_POST['csrf_token'] ?? '(none)';
    $stored  = $_SESSION['_gp_csrf'] ?? '(none)';
    $match   = ($posted === $stored) ? 'YES ✓' : 'NO ✗';
    $result  = "<p><b>Posted token:</b> " . htmlspecialchars($posted) . "</p>"
             . "<p><b>Session _gp_csrf:</b> " . htmlspecialchars($stored) . "</p>"
             . "<p><b>Match:</b> $match</p>";
}
?>
<!doctype html><html><body>
<h2>CSRF Debug</h2>
<?= $result ?>
<form method="post">
<?= gpCsrfField() ?>
<button type="submit">Test POST</button>
</form>
<p>_gp_csrf at GET time: <?= htmlspecialchars($_SESSION['_gp_csrf'] ?? '(none)') ?></p>
<p>csrf_token (config.php): <?= htmlspecialchars($_SESSION['csrf_token'] ?? '(none)') ?></p>
</body></html>
