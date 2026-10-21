<?php
/**
 * admin/notify-campaigns.php
 * Notification campaign management — create, edit, test, send.
 * Aligns with notification_campaigns schema (v1.0 migration).
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/notify-helpers.php';
requireAdmin();
$pdo = db();
$u   = currentUser();

// ── Helpers ───────────────────────────────────────────────────────────────
function campaignStatusBadge(string $s): string {
    $map = ['draft'=>'pending','ready'=>'pending','scheduled'=>'info','sending'=>'info',
            'completed'=>'approved','paused'=>'info','cancelled'=>'rejected','failed'=>'rejected'];
    return '<span class="badge badge-' . ($map[$s] ?? 'info') . '">' . ucfirst($s) . '</span>';
}

// ── POST handler ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // ── Create / Update ───────────────────────────────────────────────────
    if (in_array($action, ['create', 'update'])) {
        $cid         = (int)($_POST['campaign_id'] ?? 0);
        $name        = trim($_POST['name']    ?? '');
        $type        = in_array($_POST['type'] ?? '', ['special_offers','events','general']) ? $_POST['type'] : 'general';
        $lang        = in_array($_POST['language'] ?? '', ['en','fr']) ? $_POST['language'] : 'en';
        $subject     = trim($_POST['subject']  ?? '');
        $previewText = trim($_POST['preview_text'] ?? '') ?: null;
        $bodyHtml    = trim($_POST['body_html'] ?? '');
        $scheduledAt = trim($_POST['scheduled_at'] ?? '') ?: null;
        $errors      = [];

        if (!$name)    $errors[] = 'Campaign name required.';
        if (!$subject) $errors[] = 'Subject line required.';
        if (!$bodyHtml) $errors[] = 'Email body required.';

        if (!$errors) {
            try {
                if ($action === 'create') {
                    $pdo->prepare("
                        INSERT INTO notification_campaigns
                            (name, type, language, subject, preview_text, body_html, status, scheduled_at, created_by)
                        VALUES (?,?,?,?,?,?,?,?,?)
                    ")->execute([$name, $type, $lang, $subject, $previewText, $bodyHtml, 'draft', $scheduledAt, (int)$u['id']]);
                    $newId = (int)$pdo->lastInsertId();

                    // Segment row (type maps to interests)
                    $filterInterests = $type === 'general' ? null : $type;
                    $pdo->prepare("
                        INSERT INTO notification_campaign_segments (campaign_id, filter_interests, filter_language)
                        VALUES (?,?,?)
                    ")->execute([$newId, $filterInterests, null]);

                    notifyAuditLog('admin', 'create_campaign', 'campaign', $newId, $name, (int)$u['id']);
                    flash('success', 'Campaign created.');
                    redirect(SITE_URL . '/admin/notify-campaigns.php?edit=' . $newId);
                } else {
                    $pdo->prepare("
                        UPDATE notification_campaigns
                        SET name=?, type=?, language=?, subject=?, preview_text=?, body_html=?, scheduled_at=?, updated_at=NOW()
                        WHERE id=? AND status IN ('draft','ready','scheduled')
                    ")->execute([$name, $type, $lang, $subject, $previewText, $bodyHtml, $scheduledAt, $cid]);

                    // Update segment
                    $filterInterests = $type === 'general' ? null : $type;
                    $pdo->prepare("DELETE FROM notification_campaign_segments WHERE campaign_id=?")->execute([$cid]);
                    $pdo->prepare("INSERT INTO notification_campaign_segments (campaign_id, filter_interests, filter_language) VALUES (?,?,?)")
                        ->execute([$cid, $filterInterests, null]);

                    notifyAuditLog('admin', 'update_campaign', 'campaign', $cid, $name, (int)$u['id']);
                    flash('success', 'Campaign saved.');
                    redirect(SITE_URL . '/admin/notify-campaigns.php?edit=' . $cid);
                }
            } catch (Exception $ex) {
                $errors[] = 'DB error: ' . $ex->getMessage();
            }
        }
        $tab = 'form';
    }

    // ── Cancel ────────────────────────────────────────────────────────────
    if ($action === 'cancel') {
        $cid = (int)($_POST['campaign_id'] ?? 0);
        $pdo->prepare("UPDATE notification_campaigns SET status='cancelled', updated_at=NOW() WHERE id=? AND status IN ('draft','ready','scheduled')")
            ->execute([$cid]);
        notifyAuditLog('admin', 'cancel_campaign', 'campaign', $cid, null, (int)$u['id']);
        flash('success', 'Campaign cancelled.');
        redirect(SITE_URL . '/admin/notify-campaigns.php');
    }

    // ── Send test ─────────────────────────────────────────────────────────
    if ($action === 'send_test') {
        $cid      = (int)($_POST['campaign_id'] ?? 0);
        $testAddr = trim($_POST['test_email'] ?? '');
        if ($cid && filter_var($testAddr, FILTER_VALIDATE_EMAIL)) {
            $r = $pdo->prepare("SELECT * FROM notification_campaigns WHERE id=?");
            $r->execute([$cid]);
            $camp = $r->fetch();
            if ($camp) {
                $testBanner = '<div style="background:#ff6b6b;color:#fff;padding:8px 16px;font-size:12px;text-align:center;margin-bottom:16px">⚠️ TEST — Not sent to real subscribers</div>';
                sendMail($testAddr, '[TEST] ' . $camp['subject'], $testBanner . $camp['body_html']);
                $pdo->prepare("UPDATE notification_campaigns SET test_sent=1, updated_at=NOW() WHERE id=?")->execute([$cid]);
                notifyAuditLog('admin', 'send_test_campaign', 'campaign', $cid, $testAddr, (int)$u['id']);
                flash('success', 'Test email sent to ' . $testAddr . '.');
            }
        } else {
            flash('error', 'Valid email required for test.');
        }
        redirect(SITE_URL . '/admin/notify-campaigns.php?edit=' . $cid);
    }

    // ── Send now ──────────────────────────────────────────────────────────
    if ($action === 'send_now') {
        $cid = (int)($_POST['campaign_id'] ?? 0);
        $r = $pdo->prepare("SELECT * FROM notification_campaigns WHERE id=? AND status IN ('draft','ready','scheduled')");
        $r->execute([$cid]);
        $camp = $r->fetch();
        if (!$camp) {
            flash('error', 'Campaign not found or already sent.');
            redirect(SITE_URL . '/admin/notify-campaigns.php');
        }

        // Build audience query based on type
        $subSQL = "
            SELECT ns.id, ns.email_display, ns.language, ns.email_normalised
            FROM notification_subscribers ns
            JOIN notification_preferences np ON np.subscriber_id = ns.id
            WHERE ns.status='active'";
        if ($camp['type'] === 'special_offers') {
            $subSQL .= " AND np.special_offers_enabled=1";
        } elseif ($camp['type'] === 'events') {
            $subSQL .= " AND np.events_enabled=1";
        }
        $subRows = $pdo->query($subSQL)->fetchAll();

        $pdo->prepare("UPDATE notification_campaigns SET status='sending', sent_at=NOW(), recipient_count=?, updated_at=NOW() WHERE id=?")
            ->execute([count($subRows), $cid]);

        $sent = 0; $failed = 0;
        foreach ($subRows as $sub) {
            // Skip duplicates
            $ex = $pdo->prepare("SELECT id FROM notification_campaign_recipients WHERE campaign_id=? AND subscriber_id=?");
            $ex->execute([$cid, $sub['id']]);
            if ($ex->fetch()) continue;

            // Personalise: use subscriber language to pick subject/body (single lang campaign)
            $subject = $camp['subject'];
            $body    = $camp['body_html'];

            // Add unsubscribe footer
            $manageToken = notifyGetManageToken((int)$sub['id']);
            $lang        = $camp['language'];
            $unsubUrl    = SITE_URL . '/notify-unsubscribe.php?token=' . urlencode($manageToken);
            $prefUrl     = SITE_URL . '/notify-preferences.php?token='  . urlencode($manageToken);
            $footer = '<div style="text-align:center;margin-top:2rem;padding-top:1rem;border-top:1px solid #333;font-size:11px;color:#888">'
                . ($lang === 'fr'
                    ? '<a href="' . $prefUrl  . '" style="color:#888">Préférences</a> · <a href="' . $unsubUrl . '" style="color:#888">Se désabonner</a>'
                    : '<a href="' . $prefUrl  . '" style="color:#888">Preferences</a> · <a href="' . $unsubUrl . '" style="color:#888">Unsubscribe</a>'
                ) . '</div>';

            try {
                $ok = sendMail($sub['email_display'], $subject, $body . $footer);
                $pdo->prepare("INSERT INTO notification_campaign_recipients (campaign_id, subscriber_id, email, status, created_at) VALUES (?,?,?,?,NOW())")
                    ->execute([$cid, $sub['id'], $sub['email_display'], $ok ? 'sent' : 'failed']);
                $ok ? $sent++ : $failed++;
            } catch (Exception $e) {
                $pdo->prepare("INSERT INTO notification_campaign_recipients (campaign_id, subscriber_id, email, status, created_at) VALUES (?,?,?,'failed',NOW())")
                    ->execute([$cid, $sub['id'], $sub['email_display']]);
                $failed++;
            }
        }

        $newStatus = $failed === count($subRows) && count($subRows) > 0 ? 'failed' : 'completed';
        $pdo->prepare("UPDATE notification_campaigns SET status=?, updated_at=NOW() WHERE id=?")
            ->execute([$newStatus, $cid]);
        notifyAuditLog('admin', 'send_campaign', 'campaign', $cid, "sent=$sent failed=$failed", (int)$u['id']);
        flash('success', "Campaign sent — ✓ $sent delivered" . ($failed ? ", ✗ $failed failed" : '') . '.');
        redirect(SITE_URL . '/admin/notify-campaigns.php?edit=' . $cid);
    }

    redirect(SITE_URL . '/admin/notify-campaigns.php');
}

// ── Data ──────────────────────────────────────────────────────────────────
$editId   = (int)($_GET['edit'] ?? 0);
$editCamp = null;
$tab      = $_GET['tab'] ?? ($editId ? 'form' : 'list');

if ($editId) {
    $r = $pdo->prepare("SELECT * FROM notification_campaigns WHERE id=?");
    $r->execute([$editId]);
    $editCamp = $r->fetch();
    if ($editCamp) $tab = 'form';
}

$campaigns = $pdo->query("
    SELECT c.*,
        (SELECT COUNT(*) FROM notification_campaign_recipients ncr WHERE ncr.campaign_id=c.id AND ncr.status='sent') AS delivered_count,
        (SELECT COUNT(*) FROM notification_campaign_recipients ncr WHERE ncr.campaign_id=c.id AND ncr.status='failed') AS failed_count
    FROM notification_campaigns c
    ORDER BY c.created_at DESC
    LIMIT 100
")->fetchAll();

// Audience counts
$activeCount  = (int)$pdo->query("SELECT COUNT(*) FROM notification_subscribers WHERE status='active'")->fetchColumn();
$offersSubs   = (int)$pdo->query("SELECT COUNT(*) FROM notification_subscribers ns JOIN notification_preferences np ON np.subscriber_id=ns.id WHERE ns.status='active' AND np.special_offers_enabled=1")->fetchColumn();
$eventsSubs   = (int)$pdo->query("SELECT COUNT(*) FROM notification_subscribers ns JOIN notification_preferences np ON np.subscriber_id=ns.id WHERE ns.status='active' AND np.events_enabled=1")->fetchColumn();

$pageTitle = 'Notification Campaigns — Admin';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/includes/nav.php';
adminNav('notify-campaigns'); adminNavAssets();

$errors  = $errors  ?? [];
$success = getFlash('success');
$errMsg  = getFlash('error');
?>
<style>
.nc-tabs{display:flex;gap:0;border-bottom:2px solid var(--border);margin-bottom:2rem}
.nc-tab{padding:0.65rem 1.25rem;cursor:pointer;color:var(--muted);font-size:0.9rem;border:none;background:none;font-weight:500;transition:color .15s;text-decoration:none}
.nc-tab.active{color:var(--white);border-bottom:2px solid #00A878;margin-bottom:-2px}
.nc-tab:hover{color:var(--white)}
.body-editor{
  width:100%;min-height:220px;background:var(--bg);border:1px solid var(--border);border-radius:8px;
  color:var(--white);padding:0.75rem 1rem;font-family:monospace;font-size:0.88rem;
  line-height:1.6;box-sizing:border-box;resize:vertical;transition:border-color .15s
}
.body-editor:focus{outline:none;border-color:#00A878}
.type-option{
  display:flex;align-items:flex-start;gap:0.75rem;padding:0.75rem 1rem;
  border:1px solid var(--border);border-radius:8px;cursor:pointer;margin-bottom:0.5rem;
  transition:border-color .15s,background .15s
}
.type-option:has(input:checked){border-color:#00A878;background:rgba(0,168,120,0.06)}
.type-option input{accent-color:#00A878;width:16px;height:16px;margin-top:2px;flex-shrink:0}
</style>

<div class="page-header"><div class="container">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem">
    <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:2rem;margin:0">📣 Notification Campaigns</h1>
    <div style="display:flex;gap:0.75rem">
      <a href="<?= SITE_URL ?>/admin/notify-subscribers.php" class="btn btn-sm" style="background:var(--card);border:1px solid var(--border)">👥 Subscribers</a>
      <?php if ($tab !== 'form'): ?>
      <a href="?tab=form" class="btn btn-primary btn-sm">+ New Campaign</a>
      <?php endif; ?>
    </div>
  </div>
</div></div>

<section class="page-section"><div class="container">

  <?php if ($success): ?><div class="alert alert-success" style="margin-bottom:1.5rem"><?= e($success) ?></div><?php endif; ?>
  <?php if ($errMsg):  ?><div class="alert alert-error"   style="margin-bottom:1.5rem"><?= e($errMsg)  ?></div><?php endif; ?>
  <?php if ($errors):  ?>
  <div class="alert alert-error" style="margin-bottom:1.5rem"><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
  <?php endif; ?>

  <div class="nc-tabs">
    <a href="?tab=list" class="nc-tab <?= $tab === 'list' ? 'active' : '' ?>">All Campaigns</a>
    <a href="?tab=form<?= $editCamp ? '&edit=' . $editId : '' ?>" class="nc-tab <?= $tab === 'form' ? 'active' : '' ?>">
      <?= $editCamp ? ('Edit: ' . e(mb_substr($editCamp['name'], 0, 28))) : 'New Campaign' ?>
    </a>
  </div>

<?php if ($tab === 'list'): ?>
  <!-- ═══ LIST ═══════════════════════════════════════════════════════════ -->
  <div class="listing-widget"><div style="overflow-x:auto">
    <table class="data-table">
      <thead>
        <tr>
          <th>Name</th><th>Type</th><th>Lang</th><th>Status</th>
          <th>Scheduled</th><th>Sent</th><th>Delivered</th><th>Failed</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($campaigns)): ?>
        <tr><td colspan="9" style="text-align:center;color:var(--muted);padding:2rem">No campaigns yet. <a href="?tab=form" style="color:#00A878">Create one →</a></td></tr>
        <?php endif; ?>
        <?php foreach ($campaigns as $c): ?>
        <tr>
          <td style="font-weight:600;color:var(--white)"><?= e($c['name']) ?></td>
          <td style="font-size:0.82rem;color:var(--muted)"><?= e(str_replace('_',' ', $c['type'])) ?></td>
          <td style="font-size:0.82rem;color:var(--muted)"><?= strtoupper($c['language']) ?></td>
          <td><?= campaignStatusBadge($c['status']) ?></td>
          <td style="font-size:0.78rem;color:var(--muted-2)"><?= $c['scheduled_at'] ? date('d M H:i', strtotime($c['scheduled_at'])) : '—' ?></td>
          <td style="font-size:0.78rem;color:var(--muted-2)"><?= $c['sent_at'] ? date('d M H:i', strtotime($c['sent_at'])) : '—' ?></td>
          <td style="font-size:0.83rem;color:#00A878"><?= $c['delivered_count'] > 0 ? number_format($c['delivered_count']) : '—' ?></td>
          <td style="font-size:0.83rem;color:<?= $c['failed_count'] > 0 ? '#ff6b6b' : 'var(--muted)' ?>"><?= $c['failed_count'] > 0 ? number_format($c['failed_count']) : '—' ?></td>
          <td style="display:flex;gap:0.4rem;flex-wrap:wrap">
            <a href="?edit=<?= $c['id'] ?>" class="btn btn-sm" style="background:var(--card);border:1px solid var(--border);font-size:0.78rem">
              <?= in_array($c['status'], ['draft','ready','scheduled']) ? '✏️ Edit' : '👁 View' ?>
            </a>
            <?php if (in_array($c['status'], ['completed','sending','failed'])): ?>
            <a href="<?= SITE_URL ?>/admin/notify-report.php?id=<?= $c['id'] ?>" class="btn btn-sm" style="background:rgba(0,168,120,0.1);border:1px solid rgba(0,168,120,0.25);color:#00A878;font-size:0.78rem">
              📊
            </a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>

<?php else: ?>
  <!-- ═══ FORM ═══════════════════════════════════════════════════════════ -->
  <?php
  $isSent     = $editCamp && !in_array($editCamp['status'], ['draft','ready','scheduled']);
  $isEditable = !$editCamp || in_array($editCamp['status'], ['draft','ready','scheduled']);
  ?>

  <?php if ($editCamp && $isSent): ?>
  <!-- Read-only view -->
  <div class="listing-widget" style="padding:1.5rem;margin-bottom:1.5rem">
    <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1.25rem">
      <div>
        <h2 style="font-family:'Fraunces',serif;font-weight:900;margin:0 0 0.4rem"><?= e($editCamp['name']) ?></h2>
        <div style="display:flex;gap:0.75rem;align-items:center">
          <?= campaignStatusBadge($editCamp['status']) ?>
          <span style="font-size:0.83rem;color:var(--muted)"><?= ucfirst($editCamp['type']) ?> · <?= strtoupper($editCamp['language']) ?></span>
          <?php if ($editCamp['sent_at']): ?>
          <span style="font-size:0.83rem;color:var(--muted)">Sent <?= date('d M Y H:i', strtotime($editCamp['sent_at'])) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <?php if (in_array($editCamp['status'], ['completed','sending'])): ?>
      <?php
      $rc = $pdo->prepare("SELECT status, COUNT(*) AS cnt FROM notification_campaign_recipients WHERE campaign_id=? GROUP BY status");
      $rc->execute([$editId]);
      $rcStats = $rc->fetchAll(PDO::FETCH_KEY_PAIR);
      ?>
      <div class="dash-stats-grid" style="grid-template-columns:repeat(3,1fr);gap:0.75rem">
        <div class="dash-stat"><strong><?= number_format($editCamp['recipient_count'] ?? 0) ?></strong><span>Recipients</span></div>
        <div class="dash-stat"><strong style="color:#00A878"><?= number_format($rcStats['sent'] ?? 0) ?></strong><span>Delivered</span></div>
        <div class="dash-stat"><strong style="color:<?= ($rcStats['failed'] ?? 0) > 0 ? '#ff6b6b' : 'var(--muted)' ?>"><?= number_format($rcStats['failed'] ?? 0) ?></strong><span>Failed</span></div>
      </div>
      <?php endif; ?>
    </div>
    <div><strong style="color:var(--muted);font-size:0.8rem;text-transform:uppercase;letter-spacing:0.03em">Subject</strong>
      <p style="margin:0.3rem 0 1rem"><?= e($editCamp['subject']) ?></p>
    </div>
    <div><strong style="color:var(--muted);font-size:0.8rem;text-transform:uppercase;letter-spacing:0.03em">Body preview</strong>
      <div style="background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:1rem;margin-top:0.5rem;font-size:0.88rem;line-height:1.65;color:var(--muted);max-height:300px;overflow-y:auto">
        <?= $editCamp['body_html'] ?>
      </div>
    </div>
  </div>
  <?php else: ?>
  <!-- Editable form -->
  <form method="POST">
    <?= csrf() ?>
    <input type="hidden" name="action"      value="<?= $editCamp ? 'update' : 'create' ?>">
    <input type="hidden" name="campaign_id" value="<?= $editId ?>">

    <div class="listing-widget" style="padding:1.5rem;margin-bottom:1.5rem">
      <h2 style="font-family:'Fraunces',serif;font-weight:900;margin:0 0 1.5rem">
        <?= $editCamp ? 'Edit Campaign' : 'New Campaign' ?>
      </h2>

      <!-- Name -->
      <div style="margin-bottom:1.25rem">
        <label class="form-label">Campaign name <span style="color:#ff6b6b">*</span></label>
        <input type="text" name="name" class="form-input" required maxlength="200"
               value="<?= e($editCamp['name'] ?? '') ?>" placeholder="e.g. October Offers 2026">
        <div style="color:var(--muted-2);font-size:0.75rem;margin-top:0.25rem">Internal label — never shown to subscribers.</div>
      </div>

      <!-- Type + Language -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.25rem">
        <div>
          <label class="form-label">Audience type <span style="color:#ff6b6b">*</span></label>
          <?php $curType = $editCamp['type'] ?? 'general'; ?>
          <label class="type-option">
            <input type="radio" name="type" value="general" <?= $curType === 'general' ? 'checked' : '' ?>>
            <div><strong>📣 All active subscribers</strong>
              <div style="color:var(--muted);font-size:0.82rem"><?= number_format($activeCount) ?> subs</div></div>
          </label>
          <label class="type-option">
            <input type="radio" name="type" value="special_offers" <?= $curType === 'special_offers' ? 'checked' : '' ?>>
            <div><strong>🏷️ Special offers</strong>
              <div style="color:var(--muted);font-size:0.82rem"><?= number_format($offersSubs) ?> subs</div></div>
          </label>
          <label class="type-option">
            <input type="radio" name="type" value="events" <?= $curType === 'events' ? 'checked' : '' ?>>
            <div><strong>📅 Events</strong>
              <div style="color:var(--muted);font-size:0.82rem"><?= number_format($eventsSubs) ?> subs</div></div>
          </label>
        </div>
        <div>
          <label class="form-label">Email language <span style="color:#ff6b6b">*</span></label>
          <select name="language" class="form-input">
            <option value="en" <?= ($editCamp['language'] ?? 'en') === 'en' ? 'selected' : '' ?>>🇬🇧 English</option>
            <option value="fr" <?= ($editCamp['language'] ?? '') === 'fr' ? 'selected' : '' ?>>🇫🇷 Français</option>
          </select>
          <div style="color:var(--muted-2);font-size:0.75rem;margin-top:0.4rem">Tip: create two campaigns to send both languages.</div>

          <label class="form-label" style="margin-top:1.25rem">Schedule send (optional)</label>
          <input type="datetime-local" name="scheduled_at" class="form-input"
                 value="<?= e($editCamp['scheduled_at'] ? date('Y-m-d\TH:i', strtotime($editCamp['scheduled_at'])) : '') ?>">
          <div style="color:var(--muted-2);font-size:0.75rem;margin-top:0.25rem">Requires a cron job to trigger delivery. Leave blank to send manually.</div>
        </div>
      </div>

      <!-- Subject + Preview text -->
      <div style="margin-bottom:1.25rem">
        <label class="form-label">Subject line <span style="color:#ff6b6b">*</span></label>
        <input type="text" name="subject" class="form-input" required maxlength="200"
               value="<?= e($editCamp['subject'] ?? '') ?>" placeholder="🏷️ Exclusive deals just for you">
      </div>
      <div style="margin-bottom:1.25rem">
        <label class="form-label">Preview text <span style="color:var(--muted);font-weight:400">(optional)</span></label>
        <input type="text" name="preview_text" class="form-input" maxlength="200"
               value="<?= e($editCamp['preview_text'] ?? '') ?>" placeholder="Shown in inbox preview…">
      </div>

      <!-- Body -->
      <div style="margin-bottom:1.5rem">
        <label class="form-label">Email body <span style="color:#ff6b6b">*</span></label>
        <textarea name="body_html" class="body-editor" required
                  placeholder="HTML or plain text. Unsubscribe and preferences links are appended automatically."><?= e($editCamp['body_html'] ?? '') ?></textarea>
        <div style="color:var(--muted-2);font-size:0.75rem;margin-top:0.3rem">HTML is supported. Unsubscribe / preferences links are added automatically to the bottom of every email.</div>
      </div>

      <div style="display:flex;gap:0.75rem;flex-wrap:wrap">
        <button type="submit" class="btn btn-primary">
          <?= $editCamp ? '💾 Save changes' : '✓ Create campaign' ?>
        </button>
        <a href="?tab=list" class="btn" style="background:var(--card);border:1px solid var(--border)">Cancel</a>
      </div>
    </div>
  </form>
  <?php endif; ?>

  <!-- Actions panel -->
  <?php if ($editCamp): ?>
  <div class="listing-widget" style="padding:1.5rem">
    <h3 style="font-family:'Fraunces',serif;font-weight:900;margin:0 0 1.25rem">Campaign Actions</h3>
    <?php if (in_array($editCamp['status'], ['completed','sending','failed'])): ?>
    <a href="<?= SITE_URL ?>/admin/notify-report.php?id=<?= $editId ?>"
       class="btn btn-sm" style="background:rgba(0,168,120,0.12);border:1px solid rgba(0,168,120,0.3);color:#00A878;margin-bottom:1rem;display:inline-block">
      📊 View delivery report
    </a>
    <?php endif; ?>
    <?php if ($editCamp['test_sent']): ?>
    <div style="background:rgba(0,168,120,0.08);border:1px solid rgba(0,168,120,0.2);border-radius:8px;padding:0.6rem 1rem;margin-bottom:1rem;font-size:0.82rem;color:#00A878">
      ✓ Test email has been sent for this campaign.
    </div>
    <?php endif; ?>
    <div style="display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-start">

      <!-- Test send -->
      <form method="POST" style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap">
        <?= csrf() ?>
        <input type="hidden" name="action"      value="send_test">
        <input type="hidden" name="campaign_id" value="<?= $editId ?>">
        <input type="email" name="test_email" class="form-input" style="width:220px"
               placeholder="test@example.com" required value="<?= e($u['email'] ?? '') ?>">
        <button type="submit" class="btn btn-sm" style="background:var(--card);border:1px solid var(--border)">📧 Send test</button>
      </form>

      <?php if ($isEditable): ?>
      <!-- Send now -->
      <form method="POST"
            onsubmit="return confirm('Send to all matched active subscribers NOW? This cannot be undone.')">
        <?= csrf() ?>
        <input type="hidden" name="action"      value="send_now">
        <input type="hidden" name="campaign_id" value="<?= $editId ?>">
        <button type="submit" class="btn btn-primary btn-sm">📤 Send now</button>
      </form>

      <!-- Cancel -->
      <form method="POST" onsubmit="return confirm('Cancel this campaign?')">
        <?= csrf() ?>
        <input type="hidden" name="action"      value="cancel">
        <input type="hidden" name="campaign_id" value="<?= $editId ?>">
        <button type="submit" class="btn btn-sm" style="background:none;border:1px solid #ff6b6b;color:#ff6b6b">✗ Cancel</button>
      </form>
      <?php endif; ?>

    </div>
  </div>
  <?php endif; ?>

<?php endif; ?>

</div></section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
