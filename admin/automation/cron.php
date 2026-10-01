<?php
/**
 * automation/cron.php
 *
 * Run daily via cPanel Cron Jobs:
 *   php /home/biz97h/public_html/automation/cron.php
 *
 * Or set up in cPanel → Cron Jobs → "Once a day" → command above.
 *
 * What this does:
 *  1. Advance sequence enrollments — queue emails that are now due
 *  2. Detect users inactive for 30 days → tag + enroll re-engagement
 *  3. Clean up old sent log entries (>90 days)
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/helper.php';

$log = [];
$now = date('Y-m-d H:i:s');

// ── 1. Advance active enrollments ────────────────────────
$due = db()->query("
    SELECT e.*, u.id AS uid, u.email, u.name AS user_name
    FROM automation_enrollments e
    JOIN users u ON u.id = e.user_id
    WHERE e.status = 'active'
      AND e.next_send_at IS NOT NULL
      AND e.next_send_at <= NOW()
")->fetchAll();

foreach ($due as $enrollment) {
    // Find which step to send
    $step = db()->prepare("
        SELECT s.* FROM automation_steps s
        WHERE s.sequence_id = ?
        ORDER BY s.sort_order
        LIMIT 1 OFFSET ?
    ");
    $step->execute([$enrollment['sequence_id'], $enrollment['current_step'] - 1]);
    $currentStep = $step->fetch();

    if (!$currentStep) {
        // No more steps — sequence complete
        db()->prepare("UPDATE automation_enrollments SET status='completed' WHERE id=?")
             ->execute([$enrollment['id']]);
        $log[] = "Completed enrollment #{$enrollment['id']} for user {$enrollment['uid']}";
        continue;
    }

    // Queue the email
    $queued = queueEmail(
        $enrollment['uid'],
        $currentStep['template_id'],
        $enrollment['sequence_id'],
        $currentStep['id']
    );

    if ($queued) {
        $log[] = "Queued template #{$currentStep['template_id']} for user {$enrollment['uid']} (step {$enrollment['current_step']})";
    }

    // Find next step
    $nextStep = db()->prepare("
        SELECT s.* FROM automation_steps s
        WHERE s.sequence_id = ?
        ORDER BY s.sort_order
        LIMIT 1 OFFSET ?
    ");
    $nextStep->execute([$enrollment['sequence_id'], $enrollment['current_step']]);
    $ns = $nextStep->fetch();

    if ($ns) {
        $nextSendAt = date('Y-m-d H:i:s', strtotime('+' . $ns['delay_days'] . ' days'));
        db()->prepare("UPDATE automation_enrollments SET current_step=current_step+1, next_send_at=? WHERE id=?")
             ->execute([$nextSendAt, $enrollment['id']]);
    } else {
        // This was the last step
        db()->prepare("UPDATE automation_enrollments SET status='completed', next_send_at=NULL WHERE id=?")
             ->execute([$enrollment['id']]);
        $log[] = "Final step sent — enrollment #{$enrollment['id']} complete";
    }
}

// ── 2. Detect inactive-30d users ─────────────────────────
$inactive = db()->query("
    SELECT u.id, u.name, u.email
    FROM users u
    WHERE u.last_login_at IS NOT NULL
      AND u.last_login_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
      AND u.role != 'admin'
      AND NOT EXISTS (
          SELECT 1 FROM user_tags ut
          JOIN automation_tags at ON at.id = ut.tag_id
          WHERE ut.user_id = u.id AND at.name = 'inactive-30d'
      )
")->fetchAll();

foreach ($inactive as $u) {
    addUserTag($u['id'], 'inactive-30d');
    enrollUser($u['id'], 'Re-engagement');
    $log[] = "Tagged inactive-30d + enrolled re-engagement: {$u['email']}";
}

// Remove inactive-30d tag from users who have logged in again recently
$reactivated = db()->query("
    SELECT u.id
    FROM users u
    JOIN user_tags ut ON ut.user_id = u.id
    JOIN automation_tags at ON at.id = ut.tag_id
    WHERE at.name = 'inactive-30d'
      AND u.last_login_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
")->fetchAll();

foreach ($reactivated as $u) {
    removeUserTag($u['id'], 'inactive-30d');
    // Cancel re-engagement if still active
    db()->prepare("
        UPDATE automation_enrollments SET status='cancelled'
        WHERE user_id=? AND status='active'
          AND sequence_id=(SELECT id FROM automation_sequences WHERE name='Re-engagement' LIMIT 1)
    ")->execute([$u['id']]);
    $log[] = "Removed inactive-30d tag from reactivated user #{$u['id']}";
}

// ── 3. Clean up old log entries (>90 days) ────────────────
$deleted = db()->exec("DELETE FROM automation_log WHERE status IN ('sent','failed','skipped') AND queued_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
if ($deleted) $log[] = "Cleaned up $deleted old log entries";

// ── Output ────────────────────────────────────────────────
$summary = [
    'ran_at'         => $now,
    'enrollments_due'=> count($due),
    'inactive_tagged'=> count($inactive),
    'reactivated'    => count($reactivated),
    'log'            => $log,
];

// If called from CLI, print plain text; if HTTP, return JSON
if (php_sapi_name() === 'cli') {
    echo "237Biz Automation Cron — $now\n";
    foreach ($log as $entry) echo "  · $entry\n";
    echo "Done.\n";
} else {
    // Protect from public access
    $token = $_GET['token'] ?? '';
    if (!defined('AUTOMATION_WEBHOOK_TOKEN') || !hash_equals(AUTOMATION_WEBHOOK_TOKEN, $token)) {
        http_response_code(403);
        die('Forbidden');
    }
    header('Content-Type: application/json');
    echo json_encode($summary, JSON_PRETTY_PRINT);
}
