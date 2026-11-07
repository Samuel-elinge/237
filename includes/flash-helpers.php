<?php
/**
 * Flash message helpers — safe fallback definitions.
 *
 * config.php may define flash() and showFlash() already; these only
 * define them when they are missing (older config.php versions).
 */

if (!function_exists('flash')) {
    function flash(string $type, string $msg): void {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION['_flash'][] = ['type' => $type, 'msg' => $msg];
    }
}

if (!function_exists('e')) {
    function e(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('showFlash')) {
    function showFlash(): void {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $msgs = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        foreach ($msgs as $f) {
            $cls = match($f['type']) {
                'success' => 'alert-success',
                'error'   => 'alert-danger',
                'warning' => 'alert-warning',
                default   => 'alert-info',
            };
            echo '<div class="alert ' . $cls . ' mb-3">' . htmlspecialchars($f['msg'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
        }
    }
}
