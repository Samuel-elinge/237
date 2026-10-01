<?php
/**
 * automation/helper.php
 * Include this to access queueEmail() and enrollUser() from any PHP page.
 * Requires config.php to already be loaded.
 *
 * queueEmail() now sends immediately via sendMail() instead of waiting for
 * a cron job. The automation_log row is written first (status=queued), then
 * updated to status=sent on success, or left as queued on failure so the
 * cron can retry it later. Delayed sequence steps (delay_days > 0) still
 * rely on the cron to advance enrollments at the right time.
 */

/**
 * Send a single automation email immediately (and log it).
 */
function queueEmail(int $userId, int $templateId, ?int $sequenceId = null, ?int $stepId = null): bool {
    try {
        // ── Fetch user ────────────────────────────────────────────────────
        $u = db()->prepare("SELECT email, name FROM users WHERE id=?");
        $u->execute([$userId]);
        $user = $u->fetch();
        if (!$user) return false;

        // ── Fetch template (subject + body) ───────────────────────────────
        $t = db()->prepare("SELECT subject_en, subject_fr, body_en, body_fr FROM automation_templates WHERE id=?");
        $t->execute([$templateId]);
        $tpl = $t->fetch();
        if (!$tpl) return false;

        // ── Determine language ────────────────────────────────────────────
        $tagSt = db()->prepare("SELECT at.name FROM user_tags ut JOIN automation_tags at ON at.id=ut.tag_id WHERE ut.user_id=? AND at.name='french'");
        $tagSt->execute([$userId]);
        $isFr = (bool)$tagSt->fetchColumn();
        $lang  = $isFr ? 'fr' : 'en';

        // ── Resolve merge tags ────────────────────────────────────────────
        $uDetailed = db()->prepare("
            SELECT u.name, u.email,
                   l.title AS business_name, l.slug AS listing_slug
            FROM users u
            LEFT JOIN listings l ON l.user_id=u.id AND l.status='approved'
            WHERE u.id=? LIMIT 1
        ");
        $uDetailed->execute([$userId]);
        $uData = $uDetailed->fetch();

        $mergeFind = ['{{name}}','{{first_name}}','{{email}}','{{business_name}}','{{listing_url}}','{{site_url}}','{{dashboard_url}}'];
        $firstName = explode(' ', $uData['name'] ?? '')[0];
        $mergeReplace = [
            $uData['name']   ?? '',
            $firstName,
            $uData['email']  ?? '',
            $uData['business_name'] ?? ($uData['name'] ?? ''),
            isset($uData['listing_slug']) ? SITE_URL . '/listing/' . $uData['listing_slug'] : SITE_URL,
            SITE_URL,
            SITE_URL . '/dashboard',
        ];

        $subject = str_replace($mergeFind, $mergeReplace, $lang === 'fr' ? $tpl['subject_fr'] : $tpl['subject_en']);
        $body    = str_replace($mergeFind, $mergeReplace, $lang === 'fr' ? ($tpl['body_fr'] ?? $tpl['body_en']) : $tpl['body_en']);

        // ── Duplicate check ───────────────────────────────────────────────
        // Skip if same template+sequence already sent or queued in last 24h
        $dup = db()->prepare("
            SELECT id FROM automation_log
            WHERE user_id=? AND template_id=? AND sequence_id<=>?
              AND status IN ('queued','sent')
              AND queued_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            LIMIT 1
        ");
        $dup->execute([$userId, $templateId, $sequenceId]);
        if ($dup->fetchColumn()) return false;

        // ── Write log row (queued) ────────────────────────────────────────
        $ins = db()->prepare("
            INSERT INTO automation_log
                (user_id, template_id, sequence_id, step_id, to_email, subject, lang, status)
            VALUES (?,?,?,?,?,?,?,'queued')
        ");
        $ins->execute([$userId, $templateId, $sequenceId, $stepId, $user['email'], $subject, $lang]);
        $logId = (int)db()->lastInsertId();

        // ── Send immediately ──────────────────────────────────────────────
        $sent = false;
        try {
            sendMail($user['email'], $subject, $body);
            $sent = true;
        } catch (Exception $mailEx) {
            error_log('automation sendMail failed for log#' . $logId . ': ' . $mailEx->getMessage());
        }

        // ── Update log to sent / leave as queued for cron retry ───────────
        if ($sent) {
            db()->prepare("UPDATE automation_log SET status='sent', sent_at=NOW() WHERE id=?")
                 ->execute([$logId]);
        }
        // If not sent, row stays as 'queued' — cron.php will retry it

        return $sent;

    } catch (Exception $e) {
        error_log('queueEmail error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Enroll a user in a sequence (by sequence name or ID).
 * Skips if already enrolled, or if exclude_tag is present.
 */
function enrollUser(int $userId, string $sequenceName): bool {
    try {
        $seq = db()->prepare("SELECT * FROM automation_sequences WHERE name=? AND active=1");
        $seq->execute([$sequenceName]);
        $s = $seq->fetch();
        if (!$s) return false;

        // Check exclude tag
        if ($s['exclude_tag']) {
            $hasTag = db()->prepare("SELECT 1 FROM user_tags ut JOIN automation_tags at ON at.id=ut.tag_id WHERE ut.user_id=? AND at.name=?");
            $hasTag->execute([$userId, $s['exclude_tag']]);
            if ($hasTag->fetchColumn()) return false;
        }

        // Check target tag (if set, user must have it)
        if ($s['target_tag']) {
            $hasTag = db()->prepare("SELECT 1 FROM user_tags ut JOIN automation_tags at ON at.id=ut.tag_id WHERE ut.user_id=? AND at.name=?");
            $hasTag->execute([$userId, $s['target_tag']]);
            if (!$hasTag->fetchColumn()) return false;
        }

        // Get first step
        $firstStep = db()->prepare("SELECT * FROM automation_steps WHERE sequence_id=? ORDER BY sort_order LIMIT 1");
        $firstStep->execute([$s['id']]);
        $fs = $firstStep->fetch();
        if (!$fs) return false;

        $nextSend = date('Y-m-d H:i:s', strtotime('+' . ($fs['delay_days'] ?? 0) . ' days'));

        // Enroll (INSERT IGNORE — silently skips if already enrolled)
        $ins = db()->prepare("INSERT IGNORE INTO automation_enrollments (user_id,sequence_id,current_step,next_send_at) VALUES (?,?,1,?)");
        $ins->execute([$userId, $s['id'], $nextSend]);
        $newlyEnrolled = $ins->rowCount() > 0;

        // Only send step 1 email if fresh enrollment AND no delay
        if ($newlyEnrolled && ($fs['delay_days'] ?? 0) == 0) {
            queueEmail($userId, $fs['template_id'], $s['id'], $fs['id']);
        }
        return true;
    } catch (Exception $e) {
        error_log('enrollUser error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Assign a tag to a user by tag name.
 */
function addUserTag(int $userId, string $tagName): void {
    try {
        $tag = db()->prepare("SELECT id FROM automation_tags WHERE name=?");
        $tag->execute([$tagName]);
        $tagId = $tag->fetchColumn();
        if ($tagId) {
            db()->prepare("INSERT IGNORE INTO user_tags (user_id,tag_id) VALUES (?,?)")->execute([$userId,$tagId]);
        }
    } catch (Exception $e) {
        error_log('addUserTag error: ' . $e->getMessage());
    }
}

/**
 * Remove a tag from a user.
 */
function removeUserTag(int $userId, string $tagName): void {
    try {
        db()->prepare("DELETE ut FROM user_tags ut JOIN automation_tags at ON at.id=ut.tag_id WHERE ut.user_id=? AND at.name=?")
             ->execute([$userId,$tagName]);
    } catch (Exception $e) {}
}
