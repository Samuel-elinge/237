<?php
/**
 * build-listing.php — run once, delete after
 * Reads listing.php, applies two targeted edits, saves as listing-updated.php
 * Visit: https://237biz.net/build-listing.php?key=build237
 */
if (($_GET['key'] ?? '') !== 'build237') die('No access.');

$src = '/home/biz97h/public_html/listing.php';
$out = '/home/biz97h/public_html/listing-updated.php';

if (!file_exists($src)) die('listing.php not found.');

$content = file_get_contents($src);
$applied = [];
$failed  = [];

// ── EDIT 1: Booking button after WhatsApp share button ────────────────────
$find1 = '              💬 WhatsApp
            </a>';

$replace1 = '              💬 WhatsApp
            </a>
            <?php if (!empty($l[\'featured\']) && !empty($l[\'whatsapp\'])): ?>
            <a href="<?= SITE_URL ?>/booking/<?= e($l[\'slug\']) ?>"
               class="btn btn-sm" style="background:rgba(138,180,248,0.12);color:#8ab4f8;border:1px solid rgba(138,180,248,0.25);flex:1;text-align:center;">
              &#128197; <?= t(\'Book Now\',\'R&eacute;server\') ?>
            </a>
            <?php endif; ?>';

if (strpos($content, $find1) !== false) {
    $content = str_replace($find1, $replace1, $content);
    $applied[] = 'Booking button added after WhatsApp share button';
} else {
    $failed[] = 'WhatsApp button text not found — booking not added';
}

// ── EDIT 2: QR code section before footer ────────────────────────────────
$find2 = '<?php require_once __DIR__ . \'/includes/footer.php\'; ?>';

$replace2 = '<!-- QR Code — shown on all listings -->
<?php if (!empty($l[\'slug\'])): ?>
<section style="padding:2rem 0;border-top:1px solid var(--border);">
  <div class="container">
    <div style="display:flex;align-items:center;gap:2rem;flex-wrap:wrap;">
      <div>
        <h3 style="font-family:\'Fraunces\',serif;font-weight:700;font-size:1.05rem;margin-bottom:6px;">
          &#128241; <?= t(\'Share or Print this Listing\',\'Partager ou Imprimer cette Annonce\') ?>
        </h3>
        <p style="font-size:13px;color:var(--muted);margin-bottom:12px;">
          <?= t(\'Scan the QR code to open this listing on your phone, or download it to print on business cards or receipts.\',\'Scannez le code QR pour ouvrir cette annonce ou t&eacute;l&eacute;chargez-le pour l\\\'imprimer.\') ?>
        </p>
        <a id="qr-dl" download="<?= e($l[\'slug\']) ?>-qr.png"
           style="display:inline-block;padding:8px 18px;background:rgba(0,168,120,0.12);border:1px solid rgba(0,168,120,0.3);color:#00A878;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none;cursor:pointer;">
          &#11015; <?= t(\'Download QR Code\',\'T&eacute;l&eacute;charger le Code QR\') ?>
        </a>
      </div>
      <canvas id="listing-qr" width="130" height="130"
              style="border-radius:10px;background:#fff;padding:8px;flex-shrink:0;"></canvas>
    </div>
  </div>
</section>
<script>
(function(){
  var url = "<?= SITE_URL ?>/listing/<?= e($l[\'slug\']) ?>";
  var img = new Image();
  img.crossOrigin = "anonymous";
  img.src = "https://chart.googleapis.com/chart?chs=130x130&cht=qr&chl=" + encodeURIComponent(url) + "&choe=UTF-8";
  img.onload = function() {
    var c = document.getElementById("listing-qr");
    if (!c) return;
    var ctx = c.getContext("2d");
    ctx.fillStyle = "#fff"; ctx.fillRect(0,0,130,130);
    ctx.drawImage(img,0,0,130,130);
    var dl = document.getElementById("qr-dl");
    if (dl) dl.href = c.toDataURL("image/png");
  };
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . \'/includes/footer.php\'; ?>';

if (strpos($content, $find2) !== false) {
    $content = str_replace($find2, $replace2, $content);
    $applied[] = 'QR code section added before footer';
} else {
    $failed[] = 'Footer require line not found — QR not added';
}

// ── Write output file ─────────────────────────────────────────────────────
$written = file_put_contents($out, $content) !== false;

// Check PHP syntax by looking for obvious issues
$hasSyntaxOk = substr_count($content, '<?php') > 0 && substr_count($content, '?>') > 0;
?>
<!DOCTYPE html>
<html>
<head><title>Build listing.php</title>
<style>
body { font-family: monospace; background: #0a1a0f; color: #ccc; padding: 32px; }
h1   { color: #fcd116; }
.ok  { color: #00A878; }
.err { color: #ff6b7a; }
.box { background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; padding: 12px 16px; margin: 8px 0; }
</style>
</head>
<body>
<h1>🔨 Build listing.php</h1>

<?php if ($written): ?>
<p class="ok">✅ listing-updated.php written successfully (<?= number_format(strlen($content)) ?> bytes)</p>
<p style="color:#fcd116;">Now in File Manager: rename <strong>listing.php → listing-old.php</strong>, then rename <strong>listing-updated.php → listing.php</strong></p>
<?php else: ?>
<p class="err">❌ Could not write listing-updated.php — check folder permissions.</p>
<?php endif; ?>

<h3>Applied (<?= count($applied) ?>)</h3>
<?php foreach ($applied as $a): ?><div class="box ok">✅ <?= htmlspecialchars($a) ?></div><?php endforeach; ?>

<h3>Failed (<?= count($failed) ?>)</h3>
<?php foreach ($failed as $f): ?><div class="box err">❌ <?= htmlspecialchars($f) ?></div><?php endforeach; ?>

<div class="box" style="border-color:rgba(252,209,22,0.3);color:#fcd116;margin-top:24px;">
  ⚠️ Delete <strong>build-listing.php</strong> from your server after use.
</div>
</body>
</html>
