<?php
/**
 * includes/notify-helpers.php
 * Notification subscription helpers — loaded by all notify-* pages.
 * Requires includes/config.php already loaded.
 */

// ── Constants ────────────────────────────────────────────────────────────────
define('NOTIFY_CONSENT_VERSION', '1.0');
define('NOTIFY_CONSENT_TEXT_EN',
    'I agree to receive the 237biz updates I have selected by email. ' .
    'I understand that I can unsubscribe at any time.');
define('NOTIFY_CONSENT_TEXT_FR',
    'J\'accepte de recevoir par e-mail les mises à jour 237biz que j\'ai sélectionnées. ' .
    'Je comprends que je peux me désabonner à tout moment.');
define('NOTIFY_TOKEN_EXPIRY_HOURS', 24);
define('NOTIFY_RATE_LIMIT_WINDOW', 3600); // 1 hour in seconds
define('NOTIFY_RATE_LIMIT_MAX',    5);    // max subscribe attempts per hour per IP

// ── Email normalisation ──────────────────────────────────────────────────────
function notifyNormaliseEmail(string $email): string {
    $email = strtolower(trim($email));
    // Strip Gmail dots/plus aliases for normalisation
    if (preg_match('/^([^+@]+)(?:\+[^@]*)(@gmail\.com)$/i', $email, $m)) {
        $email = str_replace('.', '', $m[1]) . $m[2];
    }
    return $email;
}

// ── Suppression check ────────────────────────────────────────────────────────
function notifyIsSuppressed(string $emailNormalised): bool {
    $st = db()->prepare(
        "SELECT id FROM notification_suppressions WHERE email_normalised=? AND active=1 LIMIT 1"
    );
    $st->execute([$emailNormalised]);
    return (bool)$st->fetchColumn();
}

// ── Rate limiting (by IP hash) ───────────────────────────────────────────────
function notifyCheckRateLimit(): bool {
    // Returns true if OK, false if limit exceeded
    $ipHash  = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $window  = date('Y-m-d H:i:s', time() - NOTIFY_RATE_LIMIT_WINDOW);
    try {
        $count = db()->prepare(
            "SELECT COUNT(*) FROM notification_consent_events
             WHERE request_ip_hash=? AND event_type='consent_given' AND occurred_at > ?"
        );
        $count->execute([$ipHash, $window]);
        return (int)$count->fetchColumn() < NOTIFY_RATE_LIMIT_MAX;
    } catch (Exception $e) {
        return true; // fail open — don't block on DB error
    }
}

// ── Create or update subscriber (on new subscribe/resubscribe) ───────────────
/**
 * @param string $emailDisplay  original email
 * @param array  $interests     e.g. ['special_offers','events']
 * @param string $lang          'en'|'fr'
 * @param string $source        'homepage'|'offers'|'events'|...
 * @param int|null $accountUserId
 * @return array ['ok'=>bool, 'subscriber_id'=>int, 'is_new'=>bool, 'reason'=>string]
 */
function notifySubscribe(
    string $emailDisplay,
    array  $interests,
    string $lang,
    string $source = 'homepage',
    ?int   $accountUserId = null
): array {
    $emailNorm = notifyNormaliseEmail($emailDisplay);

    // Suppression check
    if (notifyIsSuppressed($emailNorm)) {
        // Don't reveal suppression to caller — return same "check inbox" response
        return ['ok' => true, 'subscriber_id' => 0, 'is_new' => false, 'reason' => 'suppressed_silent'];
    }

    $db = db();
    $db->beginTransaction();

    try {
        // Fetch existing subscriber
        $st = $db->prepare("SELECT * FROM notification_subscribers WHERE email_normalised=?");
        $st->execute([$emailNorm]);
        $existing = $st->fetch();

        $isNew = false;

        if ($existing) {
            $subId = (int)$existing['id'];
            // If suppressed, silently return ok
            if ($existing['status'] === 'suppressed') {
                $db->rollBack();
                return ['ok' => true, 'subscriber_id' => 0, 'is_new' => false, 'reason' => 'suppressed_silent'];
            }
            // Reset to pending (re-subscribe journey); preserve history
            $db->prepare(
                "UPDATE notification_subscribers
                 SET status='pending', language=?, email_display=?, account_user_id=?,
                     email_verified_at=NULL, updated_at=NOW()
                 WHERE id=?"
            )->execute([$lang, $emailDisplay, $accountUserId, $subId]);

        } else {
            // Insert new
            $db->prepare(
                "INSERT INTO notification_subscribers
                 (email_normalised, email_display, account_user_id, language, status)
                 VALUES (?,?,?,'pending')"
            )->execute([$emailNorm, $emailDisplay, $accountUserId]);
            // Re-fetch so we don't mis-match after duplicate key
            $db->prepare(
                "UPDATE notification_subscribers SET language=? WHERE email_normalised=?"
            )->execute([$lang, $emailNorm]);
            $subId = (int)$db->lastInsertId();
            $isNew = true;
        }

        // Upsert preferences
        $offersOn = in_array('special_offers', $interests) ? 1 : 0;
        $eventsOn = in_array('events', $interests)         ? 1 : 0;
        $db->prepare(
            "INSERT INTO notification_preferences (subscriber_id, special_offers_enabled, events_enabled)
             VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE special_offers_enabled=VALUES(special_offers_enabled),
                                     events_enabled=VALUES(events_enabled), updated_at=NOW()"
        )->execute([$subId, $offersOn, $eventsOn]);

        // Consent event
        $ipHash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $interestStr = implode(',', $interests);
        $consentText = $lang === 'fr' ? NOTIFY_CONSENT_TEXT_FR : NOTIFY_CONSENT_TEXT_EN;
        $evType = $isNew ? 'consent_given' : 'resubscribed';
        $db->prepare(
            "INSERT INTO notification_consent_events
             (subscriber_id, event_type, consent_version, consent_text_snapshot,
              selected_interests, language, source, request_ip_hash)
             VALUES (?,?,?,?,?,?,?,?)"
        )->execute([
            $subId, $evType, NOTIFY_CONSENT_VERSION, $consentText,
            $interestStr, $lang, $source, $ipHash
        ]);

        // Invalidate old tokens
        $db->prepare(
            "UPDATE notification_verification_tokens SET used_at=NOW() WHERE subscriber_id=? AND used_at IS NULL"
        )->execute([$subId]);

        // Create new verification token
        $rawToken  = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', time() + NOTIFY_TOKEN_EXPIRY_HOURS * 3600);
        $db->prepare(
            "INSERT INTO notification_verification_tokens (subscriber_id, token_hash, expires_at)
             VALUES (?,?,?)"
        )->execute([$subId, $tokenHash, $expiresAt]);

        $db->commit();

        return [
            'ok'            => true,
            'subscriber_id' => $subId,
            'raw_token'     => $rawToken,
            'is_new'        => $isNew,
            'reason'        => 'ok',
        ];

    } catch (Exception $e) {
        $db->rollBack();
        error_log('notifySubscribe error: ' . $e->getMessage());
        return ['ok' => false, 'subscriber_id' => 0, 'is_new' => false, 'reason' => 'db_error'];
    }
}

// ── Verify a token ───────────────────────────────────────────────────────────
/**
 * Returns ['ok'=>bool, 'subscriber_id'=>int, 'reason'=>string]
 */
function notifyVerifyToken(string $rawToken): array {
    $tokenHash = hash('sha256', $rawToken);
    $db = db();

    $st = $db->prepare(
        "SELECT t.*, s.status AS sub_status
         FROM notification_verification_tokens t
         JOIN notification_subscribers s ON s.id=t.subscriber_id
         WHERE t.token_hash=? AND t.used_at IS NULL
         LIMIT 1"
    );
    $st->execute([$tokenHash]);
    $token = $st->fetch();

    if (!$token) return ['ok' => false, 'subscriber_id' => 0, 'reason' => 'invalid_or_used'];
    if (strtotime($token['expires_at']) < time()) {
        return ['ok' => false, 'subscriber_id' => 0, 'reason' => 'expired'];
    }

    $subId = (int)$token['subscriber_id'];

    $db->beginTransaction();
    try {
        // Mark token used
        $db->prepare("UPDATE notification_verification_tokens SET used_at=NOW() WHERE id=?")
           ->execute([$token['id']]);

        // Activate subscriber
        $db->prepare(
            "UPDATE notification_subscribers
             SET status='active', email_verified_at=NOW(), last_activity_at=NOW(), updated_at=NOW()
             WHERE id=? AND status='pending'"
        )->execute([$subId]);

        // Consent event
        $db->prepare(
            "INSERT INTO notification_consent_events (subscriber_id, event_type, consent_version, verification_method)
             VALUES (?, 'verified', ?, 'email_token')"
        )->execute([$subId, NOTIFY_CONSENT_VERSION]);

        // Audit
        notifyAuditLog('system', 'subscriber.verified', 'notification_subscribers', $subId,
                        "Email verified via token");

        $db->commit();
        return ['ok' => true, 'subscriber_id' => $subId, 'reason' => 'ok'];

    } catch (Exception $e) {
        $db->rollBack();
        error_log('notifyVerifyToken error: ' . $e->getMessage());
        return ['ok' => false, 'subscriber_id' => 0, 'reason' => 'db_error'];
    }
}

// ── Get subscriber by manage token ──────────────────────────────────────────
function notifyGetSubscriberByToken(string $rawToken): ?array {
    // Manage tokens are stored in notification_verification_tokens with
    // expires_at far in future (1 year) and never marked used, unless
    // we generate a new one. For preference management we allow reuse
    // of the most recent active token.
    $tokenHash = hash('sha256', $rawToken);
    $st = db()->prepare(
        "SELECT s.*, t.id AS token_id
         FROM notification_verification_tokens t
         JOIN notification_subscribers s ON s.id=t.subscriber_id
         WHERE t.token_hash=? AND t.used_at IS NULL AND t.expires_at > NOW()
         LIMIT 1"
    );
    $st->execute([$tokenHash]);
    $row = $st->fetch();
    return $row ?: null;
}

// ── Update preferences ───────────────────────────────────────────────────────
function notifyUpdatePreferences(int $subId, bool $offersOn, bool $eventsOn, string $lang): bool {
    $db = db();
    $db->beginTransaction();
    try {
        // At least one interest required — enforce unsubscribe if both off
        if (!$offersOn && !$eventsOn) {
            notifyUnsubscribe($subId, 'preference_all_off');
            $db->commit();
            return true;
        }

        $db->prepare(
            "UPDATE notification_preferences
             SET special_offers_enabled=?, events_enabled=?, updated_at=NOW()
             WHERE subscriber_id=?"
        )->execute([$offersOn ? 1 : 0, $eventsOn ? 1 : 0, $subId]);

        $db->prepare(
            "UPDATE notification_subscribers SET language=?, updated_at=NOW() WHERE id=?"
        )->execute([$lang, $subId]);

        $db->prepare(
            "INSERT INTO notification_consent_events
             (subscriber_id, event_type, consent_version, selected_interests, language)
             VALUES (?, 'preference_changed', ?, ?, ?)"
        )->execute([
            $subId, NOTIFY_CONSENT_VERSION,
            implode(',', array_filter(['special_offers' => $offersOn, 'events' => $eventsOn],
                fn($v) => $v, ARRAY_FILTER_USE_BOTH) ? implode(',', array_keys(array_filter(
                    ['special_offers' => $offersOn, 'events' => $eventsOn]))) : ''),
            $lang
        ]);

        $db->commit();
        return true;
    } catch (Exception $e) {
        $db->rollBack();
        error_log('notifyUpdatePreferences error: ' . $e->getMessage());
        return false;
    }
}

// ── Unsubscribe ──────────────────────────────────────────────────────────────
function notifyUnsubscribe(int $subId, string $source = 'email_link'): bool {
    $db = db();
    $db->beginTransaction();
    try {
        // Get email for suppression
        $st = $db->prepare("SELECT email_normalised FROM notification_subscribers WHERE id=?");
        $st->execute([$subId]);
        $emailNorm = $st->fetchColumn();

        if (!$emailNorm) { $db->rollBack(); return false; }

        // Update subscriber status
        $db->prepare(
            "UPDATE notification_subscribers SET status='unsubscribed', updated_at=NOW() WHERE id=?"
        )->execute([$subId]);

        // Ensure suppression record
        $db->prepare(
            "INSERT IGNORE INTO notification_suppressions (email_normalised, reason, source)
             VALUES (?, 'unsubscribe', ?)"
        )->execute([$emailNorm, $source]);

        // Consent event
        $db->prepare(
            "INSERT INTO notification_consent_events (subscriber_id, event_type, consent_version, source)
             VALUES (?, 'unsubscribed', ?, ?)"
        )->execute([$subId, NOTIFY_CONSENT_VERSION, $source]);

        // Skip any queued campaign recipients
        $db->prepare(
            "UPDATE notification_campaign_recipients SET status='unsubscribed'
             WHERE subscriber_id=? AND status='queued'"
        )->execute([$subId]);

        notifyAuditLog('system', 'subscriber.unsubscribed', 'notification_subscribers', $subId,
                        "Source: $source");

        $db->commit();
        return true;
    } catch (Exception $e) {
        $db->rollBack();
        error_log('notifyUnsubscribe error: ' . $e->getMessage());
        return false;
    }
}

// ── Send verification email ──────────────────────────────────────────────────
function notifySendVerificationEmail(string $emailDisplay, string $lang, string $rawToken): bool {
    $verifyUrl = SITE_URL . '/notify-verify.php?token=' . urlencode($rawToken);
    if ($lang === 'fr') {
        $subject = 'Confirmez votre abonnement e-mail 237biz';
        $body = '
        <h2 style="color:#fff;font-family:Georgia,serif;">Confirmez votre abonnement</h2>
        <p style="color:rgba(255,255,255,0.7);">Merci de votre intérêt pour les actualités 237biz.</p>
        <p style="color:rgba(255,255,255,0.7);">Cliquez sur le bouton ci-dessous pour confirmer votre adresse e-mail et activer votre abonnement.</p>
        <div style="text-align:center;margin:2rem 0;">
          <a href="' . $verifyUrl . '" style="display:inline-block;background:#00A878;color:#fff;padding:0.85rem 2rem;border-radius:6px;text-decoration:none;font-weight:600;font-size:1rem;">
            ✓ Confirmer mon abonnement
          </a>
        </div>
        <p style="color:rgba(255,255,255,0.5);font-size:0.8rem;">Ce lien expire dans 24 heures. Si vous n\'avez pas demandé cet abonnement, ignorez cet e-mail.</p>
        <p style="color:rgba(255,255,255,0.4);font-size:0.75rem;">Ou copiez ce lien : ' . $verifyUrl . '</p>';
    } else {
        $subject = 'Confirm your 237biz email subscription';
        $body = '
        <h2 style="color:#fff;font-family:Georgia,serif;">Confirm your subscription</h2>
        <p style="color:rgba(255,255,255,0.7);">Thank you for your interest in 237biz updates.</p>
        <p style="color:rgba(255,255,255,0.7);">Click the button below to verify your email address and activate your subscription to the updates you selected.</p>
        <div style="text-align:center;margin:2rem 0;">
          <a href="' . $verifyUrl . '" style="display:inline-block;background:#00A878;color:#fff;padding:0.85rem 2rem;border-radius:6px;text-decoration:none;font-weight:600;font-size:1rem;">
            ✓ Confirm my subscription
          </a>
        </div>
        <p style="color:rgba(255,255,255,0.5);font-size:0.8rem;">This link expires in 24 hours. If you did not request these updates, you can ignore this email.</p>
        <p style="color:rgba(255,255,255,0.4);font-size:0.75rem;">Or copy this link: ' . $verifyUrl . '</p>';
    }
    return (bool)sendMail($emailDisplay, $subject, $body);
}

// ── Send confirmation email (after verification) ─────────────────────────────
function notifySendConfirmationEmail(
    string $emailDisplay,
    string $lang,
    bool   $offersOn,
    bool   $eventsOn,
    int    $subId
): bool {
    $manageToken = notifyGetManageToken($subId);
    $manageUrl = SITE_URL . '/notify-preferences.php?token=' . urlencode($manageToken);
    $unsubUrl  = SITE_URL . '/notify-unsubscribe.php?token=' . urlencode($manageToken);

    $interests = array_filter([
        $offersOn ? ($lang === 'fr' ? 'Offres spéciales' : 'Special offers') : null,
        $eventsOn ? ($lang === 'fr' ? 'Événements'       : 'Events')         : null,
    ]);
    $interestList = implode(', ', $interests);

    if ($lang === 'fr') {
        $subject = '🎉 Vous êtes abonné à 237biz !';
        $body = '
        <h2 style="color:#fff;font-family:Georgia,serif;">Vous êtes abonné !</h2>
        <p style="color:rgba(255,255,255,0.7);">Vous recevrez des mises à jour par e-mail sur : <strong style="color:#F5C842;">' . $interestList . '</strong>.</p>
        <p style="color:rgba(255,255,255,0.7);">Vous pouvez modifier vos préférences ou vous désabonner à tout moment.</p>
        <div style="text-align:center;margin:2rem 0;">
          <a href="' . $manageUrl . '" style="display:inline-block;background:#00A878;color:#fff;padding:0.75rem 1.75rem;border-radius:6px;text-decoration:none;font-weight:600;">
            ⚙️ Gérer mes préférences
          </a>
        </div>
        <p style="font-size:0.75rem;color:rgba(255,255,255,0.3);text-align:center;margin-top:1.5rem;">
          <a href="' . $unsubUrl . '" style="color:rgba(255,255,255,0.3);">Se désabonner</a>
        </p>';
    } else {
        $subject = '🎉 You\'re subscribed to 237biz!';
        $body = '
        <h2 style="color:#fff;font-family:Georgia,serif;">You\'re subscribed!</h2>
        <p style="color:rgba(255,255,255,0.7);">You\'ll receive email updates about: <strong style="color:#F5C842;">' . $interestList . '</strong>.</p>
        <p style="color:rgba(255,255,255,0.7);">You can update your preferences or unsubscribe at any time.</p>
        <div style="text-align:center;margin:2rem 0;">
          <a href="' . $manageUrl . '" style="display:inline-block;background:#00A878;color:#fff;padding:0.75rem 1.75rem;border-radius:6px;text-decoration:none;font-weight:600;">
            ⚙️ Manage my preferences
          </a>
        </div>
        <p style="font-size:0.75rem;color:rgba(255,255,255,0.3);text-align:center;margin-top:1.5rem;">
          <a href="' . $unsubUrl . '" style="color:rgba(255,255,255,0.3);">Unsubscribe</a>
        </p>';
    }
    return (bool)sendMail($emailDisplay, $subject, $body);
}

// ── Get or create a long-lived manage token ───────────────────────────────────
function notifyGetManageToken(int $subId): string {
    // Reuse most recent token if still valid and not used
    $st = db()->prepare(
        "SELECT token_hash FROM notification_verification_tokens
         WHERE subscriber_id=? AND used_at IS NULL AND expires_at > NOW()
         ORDER BY id DESC LIMIT 1"
    );
    $st->execute([$subId]);
    $existing = $st->fetchColumn();
    if ($existing) {
        // We can't retrieve raw token from hash — create a new one
    }
    // Always create a fresh long-lived manage token (1 year)
    $rawToken  = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+1 year'));
    db()->prepare(
        "INSERT INTO notification_verification_tokens (subscriber_id, token_hash, expires_at)
         VALUES (?,?,?)"
    )->execute([$subId, $tokenHash, $expiresAt]);
    return $rawToken;
}

// ── Audit log helper ─────────────────────────────────────────────────────────
function notifyAuditLog(
    string  $actorType,
    string  $action,
    ?string $objectType = null,
    ?int    $objectId   = null,
    ?string $detail     = null,
    ?int    $actorId    = null
): void {
    try {
        db()->prepare(
            "INSERT INTO notification_audit_logs (actor_id, actor_type, action, object_type, object_id, detail)
             VALUES (?,?,?,?,?,?)"
        )->execute([$actorId, $actorType, $action, $objectType, $objectId, $detail]);
    } catch (Exception $e) {
        error_log('notifyAuditLog error: ' . $e->getMessage());
    }
}

// ── Get subscriber preferences ───────────────────────────────────────────────
function notifyGetPreferences(int $subId): ?array {
    $st = db()->prepare(
        "SELECT s.*, p.special_offers_enabled, p.events_enabled
         FROM notification_subscribers s
         LEFT JOIN notification_preferences p ON p.subscriber_id=s.id
         WHERE s.id=?"
    );
    $st->execute([$subId]);
    return $st->fetch() ?: null;
}
