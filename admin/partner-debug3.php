<?php
if (!isset($_GET['key']) || $_GET['key'] !== 'debug237c') {
    http_response_code(403); exit('Forbidden');
}
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';
$pdo = db();

// Set up session as approved partner
$row = $pdo->query("SELECT * FROM partner_profiles WHERE status='approved' LIMIT 1")->fetch();
$_SESSION['user_id'] = $row['user_id'];

// Capture any output/errors from the full dashboard include
ob_start();
try {
    include __DIR__ . '/../partner/dashboard.php';
    $out = ob_get_clean();
    // If we got here, it loaded — show first 500 chars of output
    echo "<pre>✅ dashboard.php loaded OK!\nFirst 200 chars of output:\n";
    echo htmlspecialchars(substr($out, 0, 200));
    echo "\n</pre>";
} catch (Throwable $e) {
    ob_end_clean();
    echo "<pre>";
    echo "❌ " . get_class($e) . ": " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n\n";
    // Show 5 lines around the error in the file
    $errFile = $e->getFile();
    $errLine = $e->getLine();
    if (file_exists($errFile)) {
        $lines = file($errFile);
        $start = max(0, $errLine - 4);
        $end   = min(count($lines), $errLine + 3);
        echo "=== Context ($errFile lines $start-$end) ===\n";
        for ($i = $start; $i < $end; $i++) {
            $marker = ($i + 1 === $errLine) ? '>>>' : '   ';
            echo $marker . ' ' . ($i+1) . ': ' . $lines[$i];
        }
    }
    echo "\nStack:\n" . $e->getTraceAsString();
    echo "</pre>";
}
