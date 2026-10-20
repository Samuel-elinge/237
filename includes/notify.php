<?php
/**
 * Notification dispatch helper — 237Biz
 *
 * Usage:
 *   require_once __DIR__ . '/notify.php';
 *   dispatch_notification($userId, 'bell',  'Title EN', 'Titre FR', 'Body EN', 'Corps FR', '/listing/slug', 'listing', $listingId);
 *   dispatch_notification($userId, 'email', 'Subject EN', 'Objet FR', '<p>Body EN</p>', '<p>Corps FR</p>', '/listing/slug', 'listing', $listingId, 'follow');
 *
 * Respects the user's notification_prefs row:
 *   - bell_*  columns gate whether the bell row is inserted
 *   - email_* columns gate whether the email is sent
 *
 * $channel values: 'bell'  (in-app bell icon)
 *                  'email' (transactional email via sendMail())
 *
 * $pref_key values: 'booking' | 'event' | 'follow' | 'promo'
 *   Pass null to skip the pref check and always dispatch.
 */

if (!function_exists('dispatch_notification')) {

    /**
     * Insert a bell (and/or email) notification row, subject to user prefs.
     *
     * @param int         $userId      Recipient user ID
     * @param string      $channel     'bell' or 'email'
     * @param string      $titleEn     Notification title in English
     * @param string      $titleFr     Notification title in French
     * @param string      $bodyEn      Notification body in English (optional)
     * @param string      $bodyFr      Notification body in French (optional)
     * @param string|null $link        Relative URL to navigate to (e.g. '/listing/my-biz')
     * @param string|null $entityType  e.g. 'listing', 'booking', 'review'
     * @param int|null    $entityId    Primary key of the entity
     * @param string|null $prefKey     'booking'|'event'|'follow'|'promo' or null to skip pref check
     * @return bool  true if the row was inserted
     */
    function dispatch_notification(
        int     $userId,
        string  $channel,
        string  $titleEn,
        string  $titleFr,
        string  $bodyEn     = '',
        string  $bodyFr     = '',
        ?string $link        = null,
        ?string $entityType  = null,
        ?int    $entityId    = null,
        ?string $prefKey     = null
    ): bool {
        if ($userId < 1) return false;
        $channel = ($channel === 'email') ? 'email' : 'bell';

        // ── Check user prefs ──────────────────────────────────────────────────
        if ($prefKey !== null) {
            $allowedPrefKeys = ['booking', 'event', 'follow', 'promo'];
            if (in_array($prefKey, $allowedPrefKeys, true)) {
                $col = $channel . '_' . $prefKey; // e.g. 'bell_follow'
                try {
                    $ps = db()->prepare("SELECT `{$col}` FROM notification_prefs WHERE user_id = ? LIMIT 1");
                    $ps->execute([$userId]);
                    $row = $ps->fetch();
                    if ($row !== false) {
                        // If the pref row exists and the column is explicitly 0, skip
                        if ((int)($row[$col] ?? 1) === 0) {
                            return false;
                        }
                    }
                    // No row = use default (1 = on)
                } catch (Exception $e) {
                    // notification_prefs table may not exist; proceed anyway
                }
            }
        }

        // ── Insert bell notification ──────────────────────────────────────────
        if ($channel === 'bell') {
            try {
                $ins = db()->prepare("
                    INSERT INTO notifications
                        (user_id, channel, title_en, title_fr, body_en, body_fr, link, entity_type, entity_id, is_read, created_at)
                    VALUES
                        (?, 'bell', ?, ?, ?, ?, ?, ?, ?, 0, NOW())
                ");
                $ins->execute([
                    $userId,
                    mb_substr($titleEn, 0, 160),
                    mb_substr($titleFr, 0, 160),
                    mb_substr($bodyEn,  0, 500),
                    mb_substr($bodyFr,  0, 500),
                    $link       ? mb_substr($link, 0, 255) : null,
                    $entityType ? mb_substr($entityType, 0, 50) : null,
                    $entityId   ?? null,
                ]);
                return true;
            } catch (Exception $e) {
                // Silently fail — notifications are non-critical
                return false;
            }
        }

        // ── Send email notification ───────────────────────────────────────
        if ($channel === 'email') {
            if (!function_exists('sendMail')) return false; // sendMail lives in config.php

            // Fetch recipient email + language preference
            try {
                $ur = db()->prepare("SELECT email, name FROM users WHERE id = ? LIMIT 1");
                $ur->execute([$userId]);
                $recipient = $ur->fetch();
                if (!$recipient || !$recipient['email']) return false;

                // Detect language preference via user_tags (tag name 'french')
                $langSt = db()->prepare(
                    "SELECT 1 FROM user_tags ut
                     JOIN automation_tags at ON at.id = ut.tag_id
                     WHERE ut.user_id = ? AND at.name = 'french' LIMIT 1"
                );
                $langSt->execute([$userId]);
                $isFrUser = (bool)$langSt->fetchColumn();
            } catch (Exception $e) {
                $recipient = null;
                $isFrUser  = false;
                if (!$recipient) return false;
            }

            $subject = $isFrUser ? $titleFr  : $titleEn;
            $body    = $isFrUser ? $bodyFr   : $bodyEn;
            $name    = $recipient['name'] ?? '';

            try {
                $html = emailWrap($subject, $name, $body, $link);
                sendMail($recipient['email'], $subject, $html);
                return true;
            } catch (Exception $e) {
                return false;
            }
        }

        return false;
    }

}

// ─────────────────────────────────────────────────────────────────────────────
// emailWrap() — branded HTML wrapper for transactional notification emails
// ─────────────────────────────────────────────────────────────────────────────
if (!function_exists('emailWrap')) {

    /**
     * Wraps a notification body in a simple branded HTML email template.
     *
     * @param string      $subject  Email subject / pre-header text
     * @param string      $name     Recipient display name (for personalised greeting)
     * @param string      $body     Notification body text (plain text or simple HTML)
     * @param string|null $link     Optional CTA URL (absolute or relative)
     * @return string  Complete HTML email string
     */
    function emailWrap(string $subject, string $name, string $body, ?string $link = null): string {
        $siteUrl   = defined('SITE_URL')  ? SITE_URL  : 'https://237biz.net';
        $siteName  = '237Biz';
        $accentColor = '#00A878';
        $bgColor     = '#f4f7f6';
        $cardBg      = '#ffffff';
        $textColor   = '#1a2e29';
        $mutedColor  = '#6b8c83';
        $year        = date('Y');

        // Convert relative link to absolute
        if ($link && !str_starts_with($link, 'http')) {
            $link = $siteUrl . $link;
        }

        // Build CTA button (if link provided)
        $ctaHtml = '';
        if ($link) {
            $ctaLabel = 'View Details';
            $ctaHtml  = '<p style="text-align:center;margin:28px 0 0;">'
                      . '<a href="' . htmlspecialchars($link, ENT_QUOTES) . '" '
                      . 'style="background:' . $accentColor . ';color:#fff;text-decoration:none;'
                      . 'padding:12px 28px;border-radius:6px;font-size:14px;font-weight:600;'
                      . 'display:inline-block;">' . $ctaLabel . '</a>'
                      . '</p>';
        }

        // Greeting
        $greeting = $name ? 'Hi ' . htmlspecialchars($name, ENT_QUOTES) . ',' : 'Hello,';

        // Body: plain text gets paragraph wrapped; HTML passed through
        $bodyHtml = strip_tags($body) === $body
            ? '<p style="margin:0 0 12px;line-height:1.6;">' . nl2br(htmlspecialchars($body, ENT_QUOTES)) . '</p>'
            : $body;

        // Unsubscribe placeholder
        $unsubUrl = $siteUrl . '/customer-dashboard.php?tab=preferences';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$subject}</title>
</head>
<body style="margin:0;padding:0;background:{$bgColor};font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:{$bgColor};padding:32px 16px;">
  <tr><td align="center">
    <table width="100%" style="max-width:560px;background:{$cardBg};border-radius:10px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.07);">
      <!-- Header -->
      <tr>
        <td style="background:{$accentColor};padding:22px 32px;text-align:center;">
          <a href="{$siteUrl}" style="color:#fff;text-decoration:none;font-size:22px;font-weight:700;letter-spacing:-0.5px;">
            {$siteName}
          </a>
        </td>
      </tr>
      <!-- Body -->
      <tr>
        <td style="padding:32px 32px 24px;color:{$textColor};font-size:15px;">
          <p style="margin:0 0 18px;font-size:15px;">{$greeting}</p>
          {$bodyHtml}
          {$ctaHtml}
        </td>
      </tr>
      <!-- Footer -->
      <tr>
        <td style="padding:16px 32px 28px;text-align:center;font-size:12px;color:{$mutedColor};border-top:1px solid #eef0ef;">
          &copy; {$year} {$siteName} &mdash; Cameroon&#39;s Business Directory<br>
          <a href="{$unsubUrl}" style="color:{$mutedColor};text-decoration:underline;">Manage notification preferences</a>
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
    }

}
