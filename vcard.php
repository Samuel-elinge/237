<?php
/**
 * vcard.php — 237Biz Digital Business Card vCard Download
 * URL: /vcard/{slug}
 * Generates a vCard 3.0 .vcf file for download.
 */
require_once __DIR__ . '/includes/config.php';

$slug = trim($_GET['slug'] ?? '');
if (!$slug) { http_response_code(400); exit; }

$st = db()->prepare("
    SELECT l.id, l.title, l.slug, l.phone, l.email, l.website,
           l.address, l.description, l.facebook, l.instagram, l.tiktok,
           l.logo, l.status,
           c.name_en AS cat_en
    FROM listings l
    JOIN categories c ON c.id = l.category_id
    WHERE l.slug = ? AND l.status = 'approved'
");
$st->execute([$slug]);
$l = $st->fetch();

if (!$l) {
    http_response_code(404);
    exit;
}

// Track contact download
try {
    $ip   = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $ua   = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $hash = hash('sha256', $ip . '|' . $ua);
    $src  = trim($_GET['source'] ?? 'card');

    $dup = db()->prepare("
        SELECT COUNT(*) FROM marketplace_impressions
        WHERE entity_type = 'listing' AND entity_id = ? AND visitor_hash = ?
          AND event_subtype = 'card_contact_download'
          AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
    ");
    $dup->execute([$l['id'], $hash]);
    if (!(int)$dup->fetchColumn()) {
        db()->prepare("
            INSERT INTO marketplace_impressions
                (entity_type, entity_id, page_context, visitor_hash, event_subtype, created_at)
            VALUES ('listing', ?, ?, ?, 'card_contact_download', NOW())
        ")->execute([$l['id'], 'card_' . $src, $hash]);
    }
} catch (Exception $e) { /* tracking is best-effort */ }

// ── Build vCard 3.0 ───────────────────────────────────────────────────────
function vcf_escape(string $s): string {
    // Escape backslashes first, then newlines and semicolons
    // Commas are NOT escaped — vCard 3.0 parsers on iOS show \, literally in NOTE/ADR
    return str_replace(["\r\n", "\r", "\n", ';', '\\'], ['\\n', '\\n', '\\n', '\\;', '\\\\'], $s);
}

function vcf_fold(string $line): string {
    // RFC 2425: fold long lines at 75 octets
    $out = '';
    while (mb_strlen($line, '8bit') > 75) {
        $chunk = mb_substr($line, 0, 75);
        $out  .= $chunk . "\r\n ";
        $line  = mb_substr($line, 75);
    }
    return $out . $line;
}

$name  = $l['title'];
$phone = $l['phone'] ?? '';

// Format phone: remove spaces/dashes, ensure it starts with +
$phoneFormatted = '';
if ($phone) {
    $cleaned = preg_replace('/[^0-9+]/', '', $phone);
    // Cameroon: 2376xx/2377xx → keep as-is; local 6xx → prefix +237
    if (str_starts_with($cleaned, '+')) {
        $phoneFormatted = $cleaned;
    } elseif (str_starts_with($cleaned, '00')) {
        $phoneFormatted = '+' . substr($cleaned, 2);
    } elseif (strlen($cleaned) === 9 && str_starts_with($cleaned, '6')) {
        $phoneFormatted = '+237' . $cleaned;
    } else {
        $phoneFormatted = $cleaned;
    }
}

// Embed logo as base64 PHOTO if available
$photoLine = '';
if (!empty($l['logo'])) {
    $logoPath = __DIR__ . '/uploads/' . ltrim($l['logo'], '/');
    if (file_exists($logoPath)) {
        $ext  = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
        $mime = match($ext) { 'png' => 'PNG', 'gif' => 'GIF', default => 'JPEG' };
        $b64  = base64_encode(file_get_contents($logoPath));
        // vCard 3.0 inline photo — fold the base64 data
        $photoLine = "PHOTO;ENCODING=b;TYPE={$mime}:" . $b64;
    }
}

$lines = [];
$lines[] = 'BEGIN:VCARD';
$lines[] = 'VERSION:3.0';
$lines[] = vcf_fold('FN:' . vcf_escape($name));
// N: field required by iOS: Family;Given;Middle;Prefix;Suffix
// For a business name we put the full name as "family" so it sorts correctly
$lines[] = vcf_fold('N:' . vcf_escape($name) . ';;;;');
$lines[] = vcf_fold('ORG:' . vcf_escape($name));
$lines[] = vcf_fold('TITLE:' . vcf_escape($l['cat_en'] ?? ''));
if ($phoneFormatted) {
    $lines[] = vcf_fold('TEL;TYPE=WORK,VOICE:' . $phoneFormatted);
}
if (!empty($l['email'])) {
    $lines[] = vcf_fold('EMAIL;TYPE=WORK,INTERNET:' . vcf_escape($l['email']));
}
if (!empty($l['website'])) {
    $lines[] = vcf_fold('URL;TYPE=WORK:' . vcf_escape($l['website']));
}
if (!empty($l['address'])) {
    // ADR: PO Box;Extended;Street;Locality;Region;Postal;Country
    $lines[] = vcf_fold('ADR;TYPE=WORK:;;' . vcf_escape($l['address']) . ';;;;');
}
if (!empty($l['description'])) {
    $desc = strip_tags($l['description']);
    $desc = mb_substr($desc, 0, 200);
    $lines[] = vcf_fold('NOTE:' . vcf_escape($desc));
}
// Social profiles as X-SOCIALPROFILE or URL
if (!empty($l['facebook'])) {
    $lines[] = vcf_fold('X-SOCIALPROFILE;TYPE=facebook:' . vcf_escape($l['facebook']));
}
if (!empty($l['instagram'])) {
    $lines[] = vcf_fold('X-SOCIALPROFILE;TYPE=instagram:' . vcf_escape($l['instagram']));
}
// WhatsApp via phone
if ($phoneFormatted) {
    $lines[] = vcf_fold('X-WHATSAPP:' . $phoneFormatted);
}
// Source URL
$cardUrl = SITE_URL . '/card/' . $l['slug'];
$lines[] = vcf_fold('URL;TYPE=HOME:' . $cardUrl);
$lines[] = 'X-237BIZ:Powered by 237biz.net';
// Photo (must come last before END to avoid folding issues on some parsers)
if ($photoLine) {
    // Manually fold photo line: prefix + base64 data, 75-char lines after first
    $header = substr($photoLine, 0, strpos($photoLine, ':') + 1);
    $data   = substr($photoLine, strlen($header));
    $folded = $header;
    while (strlen($data) > 0) {
        $chunk  = substr($data, 0, 74);
        $data   = substr($data, 74);
        $folded .= $chunk . "\r\n ";
    }
    $lines[] = rtrim($folded);
}
$lines[] = 'END:VCARD';

$vcf = implode("\r\n", $lines) . "\r\n";

$filename = preg_replace('/[^a-z0-9-]/', '-', strtolower($name)) . '.vcf';

header('Content-Type: text/vcard; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($vcf));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
echo $vcf;
exit;
