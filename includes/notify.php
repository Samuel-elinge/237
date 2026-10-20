<?php
/**
 * Notification dispatch helper — 237Biz
 *
 * Usage:
 *   require_once __DIR__ . '/notify.php';
 *   dispatch_notification($userId, 'bell', 'Title EN', 'Titre FR', 'Body EN', 'Corps FR', '/listing/slug', 'listing', $listingId);
 *
 * Respects the user's notification_prefs row:
 *   - bell_* columns gate whether the bell row is inserted
 *   - email_* columns are checked but email sending is handled separately
 *
 * $channel values: 'bell'  (in-app bell icon)
 *                  'email' (future — placeholder, no SMTP send here)
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

        // email channel — not yet implemented, placeholder
        return false;
    }

}
