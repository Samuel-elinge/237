<?php
/**
 * TEMP diagnostic — DELETE after use
 * Shows what config.php sets/checks for CSRF
 */
require_once __DIR__ . '/../includes/config.php';
requireAdmin();

header('Content-Type: text/plain');

echo "=== CSRF DEBUG ===\n\n";
echo "REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD'] . "\n\n";

echo "SESSION keys: " . implode(', ', array_keys($_SESSION)) . "\n";
echo "SESSION['csrf']:       " . ($_SESSION['csrf'] ?? '(not set)') . "\n";
echo "SESSION['csrf_token']: " . ($_SESSION['csrf_token'] ?? '(not set)') . "\n\n";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "POST keys: " . implode(', ', array_keys($_POST)) . "\n";
    echo "POST['csrf']:       " . ($_POST['csrf'] ?? '(not set)') . "\n";
    echo "POST['csrf_token']: " . ($_POST['csrf_token'] ?? '(not set)') . "\n\n";
    echo "Match csrf vs SESSION['csrf']:       " . (($_POST['csrf'] ?? '') === ($_SESSION['csrf'] ?? '') ? 'YES' : 'NO') . "\n";
    echo "Match csrf vs SESSION['csrf_token']: " . (($_POST['csrf'] ?? '') === ($_SESSION['csrf_token'] ?? '') ? 'YES' : 'NO') . "\n";
    echo "Match csrf_token vs SESSION['csrf']:       " . (($_POST['csrf_token'] ?? '') === ($_SESSION['csrf'] ?? '') ? 'YES' : 'NO') . "\n";
    echo "Match csrf_token vs SESSION['csrf_token']: " . (($_POST['csrf_token'] ?? '') === ($_SESSION['csrf_token'] ?? '') ? 'YES' : 'NO') . "\n\n";

    echo "=== Testing verifyCsrf() ===\n";
    // Check if verifyCsrf is defined and where
    if (function_exists('verifyCsrf')) {
        $rf = new ReflectionFunction('verifyCsrf');
        echo "verifyCsrf defined in: " . $rf->getFileName() . " line " . $rf->getStartLine() . "\n";
    } else {
        echo "verifyCsrf NOT defined!\n";
    }
    if (function_exists('csrfCheck')) {
        $rf = new ReflectionFunction('csrfCheck');
        echo "csrfCheck defined in: " . $rf->getFileName() . " line " . $rf->getStartLine() . "\n";
    }
    if (function_exists('csrf')) {
        $rf = new ReflectionFunction('csrf');
        echo "csrf() defined in: " . $rf->getFileName() . " line " . $rf->getStartLine() . "\n";
    }
} else {
    echo "=== GET: showing token that would be embedded ===\n";
    if (function_exists('csrf')) {
        echo "csrf() returns: " . csrf() . "\n";
    }
    if (function_exists('csrfField')) {
        echo "csrfField() returns: " . csrfField() . "\n";
    }
    echo "\nReflection:\n";
    foreach (['verifyCsrf','csrfCheck','csrf','csrfField'] as $fn) {
        if (function_exists($fn)) {
            $rf = new ReflectionFunction($fn);
            echo "$fn() defined in: " . $rf->getFileName() . " line " . $rf->getStartLine() . "\n";
        } else {
            echo "$fn() NOT defined\n";
        }
    }
}

// Show source of verifyCsrf from config.php
echo "\n=== verifyCsrf source (from config.php) ===\n";
$lines = file('/home/biz97h/public_html/includes/config.php');
// print lines 80-100 (around line 86)
for ($i = 78; $i < min(105, count($lines)); $i++) {
    echo ($i+1) . ": " . $lines[$i];
}

// Also show a test form
?>
<hr>
<h3>Test POST with name="csrf"</h3>
<form method="POST">
  <input type="hidden" name="csrf" value="<?= function_exists('csrf') ? csrf() : 'NO_CSRF_FN' ?>">
  <button type="submit">POST with csrf</button>
</form>
<h3>Test POST with name="csrf_token"</h3>
<form method="POST">
  <input type="hidden" name="csrf_token" value="<?= function_exists('csrf') ? csrf() : 'NO_CSRF_FN' ?>">
  <button type="submit">POST with csrf_token</button>
</form>
