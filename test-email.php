<?php
// test-email.php — DELETE AFTER TESTING
require_once __DIR__ . '/includes/config.php';

$to = 'sam@msitsolutions.co.uk'; // ← change to your email

echo "<h2>Testing SMTP email from 237Biz</h2>";
echo "<p>Sending to: $to</p>";

$result = sendMail(
    $to,
    'Test Email — 237Biz SMTP',
    '<h2 style="color:#fff;">✅ SMTP is working!</h2>
     <p style="color:rgba(255,255,255,0.7);">If you received this, email notifications are correctly configured on 237Biz.</p>
     <p style="color:rgba(255,255,255,0.5);font-size:0.8rem;">Sent via PHPMailer · ' . date('d M Y H:i:s') . '</p>'
);

if ($result) {
    echo "<p style='color:green;font-weight:bold;'>✅ Email sent successfully! Check your inbox.</p>";
} else {
    echo "<p style='color:red;font-weight:bold;'>❌ Email failed. Check SMTP settings in includes/config.php</p>";
    echo "<p>Make sure SMTP_PASS is set correctly.</p>";
}
?>
