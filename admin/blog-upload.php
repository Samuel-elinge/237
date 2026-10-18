<?php
/**
 * admin/blog-upload.php — 237Biz
 * AJAX image upload for the blog (cover images + images inside articles).
 * Admin only, CSRF-protected, returns JSON: {ok:true,url:"..."} or {ok:false,error:"..."}
 */
require_once __DIR__ . '/../includes/config.php';
requireAdmin();

header('Content-Type: application/json; charset=utf-8');

function bu_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') bu_fail('POST only.', 405);

// CSRF (same 'csrf' field as the rest of the admin). A failed check exits with a plain-text
// message; the browser script reports that as 'session expired'.
verifyCsrf();

if (empty($_FILES['image'])) {
    bu_fail('No image received (it may be larger than the server allows). Try a smaller image.');
}
$f = $_FILES['image'];
if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $e = (int)$f['error'];
    bu_fail(($e === UPLOAD_ERR_INI_SIZE || $e === UPLOAD_ERR_FORM_SIZE)
        ? 'Image is too large for the server. Try a smaller image.'
        : 'Upload failed (code ' . $e . ').');
}
if (!is_uploaded_file($f['tmp_name'])) bu_fail('No image received.');
if ($f['size'] > 8 * 1024 * 1024) bu_fail('Image must be under 8 MB.');

// Check the real file type, not just the extension
$info = @getimagesize($f['tmp_name']);
$map  = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
if (!$info || !isset($map[$info[2]])) bu_fail('Image must be a JPG, PNG or WebP file.');
$type = $info[2];
$ext  = $map[$type];

$dir = rtrim(UPLOAD_DIR, '/') . '/blog/';
if (!is_dir($dir) && !@mkdir($dir, 0755, true)) bu_fail('Could not create the upload folder.', 500);

// Never allow scripts to run from this folder
$ht = $dir . '.htaccess';
if (!file_exists($ht)) {
    @file_put_contents($ht, "Options -Indexes -ExecCGI\n<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi)\$\">\n  Require all denied\n</FilesMatch>\n");
}

$name = 'blog_' . date('Ymd') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
$dest = $dir . $name;
$done = false;

// Resize very large photos and re-encode (this also strips EXIF/location data)
$maxW = 1600;
if (function_exists('imagecreatetruecolor') && $info[0] > 0 && $info[1] > 0) {
    $src = null;
    if ($type === IMAGETYPE_JPEG && function_exists('imagecreatefromjpeg')) $src = @imagecreatefromjpeg($f['tmp_name']);
    if ($type === IMAGETYPE_PNG  && function_exists('imagecreatefrompng'))  $src = @imagecreatefrompng($f['tmp_name']);
    if ($type === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) $src = @imagecreatefromwebp($f['tmp_name']);

    if ($src) {
        // Respect camera orientation for JPEGs
        if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($f['tmp_name']);
            $o = $exif['Orientation'] ?? 1;
            $rot = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
            if ($rot) { $r = imagerotate($src, $rot, 0); if ($r) { imagedestroy($src); $src = $r; } }
        }
        $w = imagesx($src); $h = imagesy($src);
        if ($w > $maxW) { $nh = (int)round($h * $maxW / $w); $nw = $maxW; }
        else            { $nw = $w; $nh = $h; }

        $out = imagecreatetruecolor($nw, $nh);
        if ($type !== IMAGETYPE_JPEG) {
            imagealphablending($out, false);
            imagesavealpha($out, true);
            imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        }
        imagecopyresampled($out, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        if ($type === IMAGETYPE_JPEG)      $done = imagejpeg($out, $dest, 82);
        elseif ($type === IMAGETYPE_PNG)   $done = imagepng($out, $dest, 7);
        elseif (function_exists('imagewebp')) $done = imagewebp($out, $dest, 82);

        imagedestroy($src); imagedestroy($out);
    }
}
// GD not available (or failed): store the original file as-is
if (!$done) {
    $done = move_uploaded_file($f['tmp_name'], $dest);
}
if (!$done || !file_exists($dest)) bu_fail('Could not save the image.', 500);
@chmod($dest, 0644);

$base = rtrim(UPLOAD_URL, '/') . '/blog/' . $name;
if (!preg_match('#^https?://#i', $base)) $base = rtrim(SITE_URL, '/') . '/' . ltrim($base, '/');

echo json_encode(['ok' => true, 'url' => $base, 'name' => $name]);
