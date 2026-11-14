<?php
/**
 * qr.php — QR code proxy with 237biz.net label
 * Usage: /qr?data=<encoded_url>&size=<px>
 * Fetches from api.qrserver.com, draws label underneath, outputs PNG.
 */
require_once __DIR__ . '/includes/config.php';

$data = trim($_GET['data'] ?? '');
$size = max(100, min(600, (int)($_GET['size'] ?? 150)));

if (!$data) { http_response_code(400); exit; }

// ── Fetch raw QR PNG from qrserver ────────────────────────────────────────
$apiUrl = 'https://api.qrserver.com/v1/create-qr-code/?'
        . http_build_query([
            'size'    => "{$size}x{$size}",
            'data'    => $data,
            'color'   => '08472F',
            'bgcolor' => 'ffffff',
            'format'  => 'png',
            'margin'  => '1',
          ]);

$ctx = stream_context_create(['http' => ['timeout' => 8, 'user_agent' => 'Mozilla/5.0']]);
$pngData = @file_get_contents($apiUrl, false, $ctx);

if (!$pngData) {
    // Fallback: redirect directly to qrserver without label
    header('Location: ' . $apiUrl);
    exit;
}

// ── Compose image with label ──────────────────────────────────────────────
$qr = imagecreatefromstring($pngData);
if (!$qr) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    echo $pngData;
    exit;
}

$labelText  = '237biz.net';
$fontSize   = max(8, (int)($size * 0.09));   // ~9% of QR size
$paddingBot = $fontSize + 6;                   // space below QR for label
$totalH     = $size + $paddingBot;

$out = imagecreatetruecolor($size, $totalH);

// Background: white
$white = imagecolorallocate($out, 255, 255, 255);
$green = imagecolorallocate($out, 8,  71,  47);   // #08472F
imagefill($out, 0, 0, $white);

// Copy QR onto canvas
imagecopy($out, $qr, 0, 0, 0, 0, $size, $size);
imagedestroy($qr);

// Draw label text — use GD built-in font (no freetype needed)
// GD font 3 is ~7px wide × 13px tall; scale up with imagestring
$fontId = 3; // built-in GD font
if ($fontSize >= 14) $fontId = 4;
if ($fontSize >= 18) $fontId = 5;

$charW  = imagefontwidth($fontId);
$charH  = imagefontheight($fontId);
$txtW   = strlen($labelText) * $charW;
$x      = (int)(($size - $txtW) / 2);
$y      = $size + (int)(($paddingBot - $charH) / 2);

imagestring($out, $fontId, $x, $y, $labelText, $green);

// ── Output ────────────────────────────────────────────────────────────────
header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
imagepng($out);
imagedestroy($out);
exit;
