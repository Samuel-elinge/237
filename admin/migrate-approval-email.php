<?php
/**
 * One-time migration: add QR code + digital card section to listing-approved email template.
 * Run once via browser: /admin/migrate-approval-email.php
 * DELETE this file after running.
 */
require_once __DIR__ . '/../includes/config.php';
requireAdmin();

$tpl = db()->query("SELECT id, subject_en, subject_fr, body_en, body_fr FROM automation_templates WHERE name='listing-approved' LIMIT 1")->fetch();

if (!$tpl) {
    echo "<p style='color:red'>Template 'listing-approved' not found. Nothing changed.</p>";
    echo "<p><a href='/admin'>← Back to Admin</a></p>";
    exit;
}

// New QR block to append before closing </div> or just append to body
$qrBlockEn = '

<div style="margin:28px 0;padding:20px;background:#f4f9f6;border-radius:10px;border:1px solid #d0e9db;text-align:center;">
  <p style="font-size:15px;font-weight:700;color:#08472F;margin:0 0 6px 0;">📱 Your Digital Business Card</p>
  <p style="font-size:13px;color:#555;margin:0 0 14px 0;">Share your card link or QR code with customers — they can tap to call, WhatsApp, or visit your website instantly.</p>
  <a href="{{card_url}}" style="display:inline-block;padding:10px 22px;background:#08472F;color:#fff;text-decoration:none;border-radius:6px;font-size:14px;font-weight:600;margin-bottom:16px;">
    View Your Digital Card →
  </a>
  <br>
  <img src="{{qr_code_url}}" alt="QR Code" width="150" height="150" style="border:4px solid #fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.12);" />
  <p style="font-size:11.5px;color:#888;margin:10px 0 0 0;">
    <strong>How to use:</strong> Download the QR image, save to your phone gallery, then share on WhatsApp, print on your flyer or business card.
  </p>
</div>';

$qrBlockFr = '

<div style="margin:28px 0;padding:20px;background:#f4f9f6;border-radius:10px;border:1px solid #d0e9db;text-align:center;">
  <p style="font-size:15px;font-weight:700;color:#08472F;margin:0 0 6px 0;">📱 Votre Carte de Visite Digitale</p>
  <p style="font-size:13px;color:#555;margin:0 0 14px 0;">Partagez votre lien ou code QR avec vos clients — ils peuvent appeler, envoyer un WhatsApp ou visiter votre site en un tap.</p>
  <a href="{{card_url}}" style="display:inline-block;padding:10px 22px;background:#08472F;color:#fff;text-decoration:none;border-radius:6px;font-size:14px;font-weight:600;margin-bottom:16px;">
    Voir Votre Carte Digitale →
  </a>
  <br>
  <img src="{{qr_code_url}}" alt="Code QR" width="150" height="150" style="border:4px solid #fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.12);" />
  <p style="font-size:11.5px;color:#888;margin:10px 0 0 0;">
    <strong>Comment utiliser :</strong> Téléchargez le QR, enregistrez-le dans votre galerie, puis partagez sur WhatsApp ou imprimez-le sur votre flyer ou carte de visite.
  </p>
</div>';

// Check if already migrated
if (str_contains($tpl['body_en'], '{{card_url}}')) {
    echo "<p style='color:orange'>Template already contains {{card_url}} — already migrated. Nothing changed.</p>";
    echo "<p><a href='/admin'>← Back to Admin</a></p>";
    exit;
}

$newBodyEn = $tpl['body_en'] . $qrBlockEn;
$newBodyFr = ($tpl['body_fr'] ?? $tpl['body_en']) . $qrBlockFr;

$st = db()->prepare("UPDATE automation_templates SET body_en=?, body_fr=? WHERE id=?");
$st->execute([$newBodyEn, $newBodyFr, $tpl['id']]);

echo "<p style='color:green;font-size:16px;'>✅ Template updated successfully (ID: {$tpl['id']})</p>";
echo "<p>The 'listing-approved' email now includes the digital card link, QR code image and usage guide.</p>";
echo "<p style='color:red;font-weight:bold;'>⚠️ Please delete this file from the server: <code>admin/migrate-approval-email.php</code></p>";
echo "<p><a href='/admin'>← Back to Admin</a></p>";
