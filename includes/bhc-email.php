<?php
/**
 * Business Health Check — Email helper
 *
 * Sends the branded report notification email to the lead.
 *
 * Since sendMail() accepts (email, subject, htmlBody) with no attachment
 * support, we deliver a rich HTML email containing:
 *   - The online report link (/bhc-results?token=...)
 *   - A "Download PDF" link (/bhc-report-pdf?token=...)
 *
 * Usage:
 *   bhc_send_report_email(array $assessment, string $rawToken, string $lang): bool
 */

defined('IN_237BIZ') or die('Direct access denied');

require_once __DIR__ . '/bhc-config.php';
require_once __DIR__ . '/bhc-pdf.php';

/**
 * Send the BHC completion email to the lead.
 *
 * @param array  $assessment  Row from bhc_assessments (must have contact_email, business_name, etc.)
 * @param string $rawToken    The raw report access token (64-char hex)
 * @param string $lang        'en' | 'fr'
 * @return bool
 */
function bhc_send_report_email(array $assessment, string $rawToken, string $lang = 'en'): bool
{
    $email = trim($assessment['contact_email'] ?? $assessment['email'] ?? '');
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;  // No valid email — silent skip
    }

    $isFr       = $lang === 'fr';
    $bname      = htmlspecialchars($assessment['business_name'] ?? '', ENT_QUOTES, 'UTF-8');
    $contact    = htmlspecialchars($assessment['contact_name']  ?? '', ENT_QUOTES, 'UTF-8');
    $ref        = htmlspecialchars($assessment['reference']     ?? '', ENT_QUOTES, 'UTF-8');
    $total      = round((float)($assessment['total_score'] ?? 0));
    $band       = $assessment['score_band'] ?? bhc_score_band((float)$total);
    $bandLabel  = htmlspecialchars(bhc_band_label($band, $lang), ENT_QUOTES, 'UTF-8');
    $bandColor  = bhc_band_color($band);

    $reportUrl  = rtrim(SITE_URL, '/') . '/bhc-results?token=' . urlencode($rawToken);
    $pdfUrl     = rtrim(SITE_URL, '/') . '/bhc-report-pdf?token=' . urlencode($rawToken);

    // ── Subject ───────────────────────────────────────────────────────────────
    if ($isFr) {
        $subject = "Votre Bilan de Sante 237biz est pret — $bname";
    } else {
        $subject = "Your 237biz Business Health Check is ready — $bname";
    }

    // ── Greeting + intro ──────────────────────────────────────────────────────
    if ($isFr) {
        $greeting   = $contact ? "Bonjour $contact," : 'Bonjour,';
        $intro      = "Votre bilan de santé numérique pour <strong>$bname</strong> est prêt.";
        $scoreLabel = 'Votre score global';
        $bandHead   = 'Niveau';
        $refLabel   = 'Référence';
        $viewBtn    = 'Voir mon rapport complet';
        $pdfBtn     = 'Télécharger le PDF';
        $nextHdr    = 'Prochaines étapes recommandées';
        $nextBody   = $band === 'strong_foundations'
            ? 'Excellente performance ! Continuez sur cette lancée et affinez votre stratégie de croissance avec un expert 237biz.'
            : ($band === 'good_progress'
                ? 'Bonne base ! Quelques améliorations ciblées peuvent propulser votre entreprise au niveau supérieur.'
                : ($band === 'developing'
                    ? 'Vous progressez. Des actions concrètes dans les domaines prioritaires vous permettront d\'accélérer votre croissance.'
                    : 'Une attention immédiate dans les domaines clés peut rapidement transformer vos résultats.'));
        $ctaText    = 'Parler à un expert 237biz';
        $ctaUrl     = rtrim(SITE_URL, '/') . '/contact';
        $footerNote = 'Vous recevez cet email car vous avez complété un bilan de santé sur 237biz.net.';
        $privacyLbl = 'Politique de confidentialité';
        $tagline    = 'La plateforme numérique des entreprises camerounaises';
    } else {
        $greeting   = $contact ? "Hello $contact," : 'Hello,';
        $intro      = "Your digital health check for <strong>$bname</strong> is ready.";
        $scoreLabel = 'Your Overall Score';
        $bandHead   = 'Level';
        $refLabel   = 'Reference';
        $viewBtn    = 'View My Full Report';
        $pdfBtn     = 'Download PDF';
        $nextHdr    = 'Recommended Next Steps';
        $nextBody   = $band === 'strong_foundations'
            ? 'Outstanding performance! Keep the momentum going and refine your growth strategy with a 237biz expert.'
            : ($band === 'good_progress'
                ? 'Good foundation! A few targeted improvements can take your business to the next level.'
                : ($band === 'developing'
                    ? 'You\'re making progress. Concrete actions in priority areas will help you accelerate growth.'
                    : 'Immediate attention in several key areas can quickly transform your results.'));
        $ctaText    = 'Talk to a 237biz Expert';
        $ctaUrl     = rtrim(SITE_URL, '/') . '/contact';
        $footerNote = 'You received this email because you completed a health check at 237biz.net.';
        $privacyLbl = 'Privacy Policy';
        $tagline    = 'The digital platform for Cameroonian businesses';
    }

    $privacyUrl = rtrim(SITE_URL, '/') . '/privacy-policy';

    // ── HTML Body ──────────────────────────────────────────────────────────────
    $body = <<<HTML
<!DOCTYPE html>
<html lang="{$lang}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$subject}</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:Arial,Helvetica,sans-serif;">

<!-- Wrapper -->
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;padding:32px 16px;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 16px rgba(0,0,0,.08);">

  <!-- Header -->
  <tr>
    <td style="background:#0d1117;padding:28px 32px 20px;">
      <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
          <td>
            <span style="font-size:22px;font-weight:800;color:#ffffff;letter-spacing:-0.5px;">237biz</span><br>
            <span style="font-size:11px;color:rgba(255,255,255,0.5);">{$tagline}</span>
          </td>
          <td align="right">
            <span style="font-size:10px;color:rgba(255,255,255,0.4);">{$refLabel}: {$ref}</span>
          </td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- Green accent bar -->
  <tr><td style="background:#00A878;height:4px;"></td></tr>

  <!-- Score hero -->
  <tr>
    <td style="background:linear-gradient(135deg,#1a1f36 0%,#0d1117 100%);padding:32px;text-align:center;">
      <p style="margin:0 0 8px;font-size:12px;color:rgba(255,255,255,0.6);text-transform:uppercase;letter-spacing:1px;">{$scoreLabel}</p>
      <div style="display:inline-block;width:120px;height:120px;border-radius:50%;background:{$bandColor}22;border:8px solid {$bandColor};line-height:104px;text-align:center;vertical-align:middle;">
        <span style="font-size:34px;font-weight:800;color:{$bandColor};line-height:1;">{$total}</span>
      </div>
      <div style="margin-top:12px;">
        <span style="display:inline-block;padding:5px 18px;border-radius:20px;background:{$bandColor}22;color:{$bandColor};font-weight:700;font-size:13px;border:1px solid {$bandColor}66;">{$bandLabel}</span>
      </div>
    </td>
  </tr>

  <!-- Main content -->
  <tr>
    <td style="padding:32px;">
      <p style="margin:0 0 20px;font-size:15px;color:#1a202c;">{$greeting}</p>
      <p style="margin:0 0 24px;font-size:15px;color:#4a5568;line-height:1.6;">{$intro}</p>

      <!-- CTA Buttons -->
      <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
          <td style="padding-right:8px;">
            <a href="{$reportUrl}"
               style="display:block;background:#00A878;color:#fff;text-align:center;padding:14px 20px;border-radius:8px;font-weight:700;font-size:14px;text-decoration:none;">
              {$viewBtn}
            </a>
          </td>
          <td style="padding-left:8px;">
            <a href="{$pdfUrl}"
               style="display:block;background:#fff;color:#00A878;text-align:center;padding:13px 20px;border-radius:8px;font-weight:700;font-size:14px;text-decoration:none;border:1.5px solid #00A878;">
              ↓ {$pdfBtn}
            </a>
          </td>
        </tr>
      </table>

      <!-- Divider -->
      <hr style="border:none;border-top:1px solid #e8ecf0;margin:28px 0;">

      <!-- Next steps -->
      <p style="margin:0 0 10px;font-size:14px;font-weight:700;color:#1a202c;">{$nextHdr}</p>
      <p style="margin:0 0 20px;font-size:14px;color:#4a5568;line-height:1.6;">{$nextBody}</p>

      <a href="{$ctaUrl}"
         style="display:inline-block;background:#F5C842;color:#1a202c;padding:12px 24px;border-radius:8px;font-weight:700;font-size:14px;text-decoration:none;">
        {$ctaText}
      </a>
    </td>
  </tr>

  <!-- Footer -->
  <tr>
    <td style="background:#f8f9fa;padding:20px 32px;border-top:1px solid #e8ecf0;">
      <p style="margin:0 0 6px;font-size:11px;color:#9aa5b1;line-height:1.5;">{$footerNote}</p>
      <p style="margin:0;font-size:11px;color:#9aa5b1;">
        &copy; HTML_YEAR 237biz / MS IT Solutions &nbsp;·&nbsp;
        <a href="{$privacyUrl}" style="color:#9aa5b1;">{$privacyLbl}</a> &nbsp;·&nbsp;
        <a href="https://237biz.net" style="color:#9aa5b1;">237biz.net</a>
      </p>
    </td>
  </tr>

</table>
</td></tr>
</table>

</body>
</html>
HTML;

    // Replace placeholder year
    $body = str_replace('HTML_YEAR', date('Y'), $body);

    // ── Attach PDF via PHPMailer directly ─────────────────────────────────────
    $phpmailerPath = __DIR__ . '/../vendor/phpmailer/phpmailer/src/';
    if (file_exists($phpmailerPath . 'PHPMailer.php')) {
        require_once $phpmailerPath . 'Exception.php';
        require_once $phpmailerPath . 'PHPMailer.php';
        require_once $phpmailerPath . 'SMTP.php';

        try {
            // Generate PDF bytes
            $pdo      = db();
            $pdfBytes = bhc_generate_pdf_for_assessment($assessment, $pdo);
            $filename = 'BHC-Report-' . preg_replace('/[^A-Z0-9\-]/i', '', $assessment['reference'] ?? 'report') . '.pdf';

            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USER;
            $mail->Password   = SMTP_PASS;
            $mail->SMTPSecure = defined('SMTP_SECURE') ? SMTP_SECURE : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = defined('SMTP_PORT') ? SMTP_PORT : 587;

            $mail->setFrom(SMTP_USER, defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : '237biz');
            $mail->addAddress($email);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = strip_tags($body);

            // Attach PDF from memory (no temp file needed)
            $mail->addStringAttachment($pdfBytes, $filename, \PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64, 'application/pdf');

            $mail->send();
            return true;
        } catch (\Exception $e) {
            error_log('BHC PDF email error: ' . $e->getMessage());
            // Fall through to plain sendMail without attachment
        }
    }

    // Fallback: send without attachment if PHPMailer unavailable or errored
    return (bool) sendMail($email, $subject, $body);
}

/**
 * Look up the most recent completed assessment for an email address,
 * find or create its report token, and re-send the report email.
 *
 * Returns: 'sent' | 'not_found' | 'error'
 */
function bhc_resend_report_by_email(string $email): string
{
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'not_found';
    }

    $pdo = db();

    // Find most recent completed assessment for this email
    $stmt = $pdo->prepare(
        "SELECT * FROM bhc_assessments
         WHERE email = :email
           AND status IN ('submitted','completed')
         ORDER BY completed_at DESC, submitted_at DESC
         LIMIT 1"
    );
    $stmt->execute([':email' => $email]);
    $assessment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$assessment) {
        return 'not_found';
    }

    // Find existing permanent report token
    $tStmt = $pdo->prepare(
        "SELECT token_hash FROM bhc_access_tokens
         WHERE assessment_id = :aid
           AND token_type = 'report'
           AND (expires_at IS NULL OR expires_at > NOW())
           AND revoked = 0
         ORDER BY created_at DESC
         LIMIT 1"
    );
    $tStmt->execute([':aid' => $assessment['id']]);
    $tokenRow = $tStmt->fetch(PDO::FETCH_ASSOC);

    // We can't reverse-hash to get the raw token, so create a new one
    // (the old one remains valid — users just get a fresh link)
    $rawToken = bhc_create_access_token((int)$assessment['id'], 'report');

    $lang = $assessment['lang'] ?? 'en';
    $ok   = bhc_send_report_email($assessment, $rawToken, $lang);

    return $ok ? 'sent' : 'error';
}
