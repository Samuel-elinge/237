<?php
/**
 * CSRF debug — DELETE after use
 * Visit this page, note the token, then POST to it and see if it matches.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';
requireAdmin();

$result = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted  = $_POST['csrf_token'] ?? '(none)';
    $stored  = $_SESSION['csrf_token'] ?? '(none)';
    $match   = ($posted === $stored) ? 'YES ✓' : 'NO ✗';
    $result  = "<p><b>Posted token:</b> " . htmlspecialchars($posted) . "</p>"
             . "<p><b>Session token:</b> " . htmlspecialchars($stored) . "</p>"
             . "<p><b>Match:</b> $match</p>";
}
?>
<!doctype html><html><body>
<h2>CSRF Debug</h2>
<?= $result ?>
<form method="post">
<?= csrfField() ?>
<button type="submit">Test POST</button>
</form>
<p>Session token at GET time: <?= htmlspecialchars($_SESSION['csrf_token'] ?? '(none)') ?></p>
</body></html>
