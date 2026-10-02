<?php
/**
 * partner/templates.php — Communication Templates Manager (Phase 3B)
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';

$partnerProfile = requireGrowthPartner();
$partnerId      = (int)$partnerProfile['id'];
$userId         = currentUser()['id'];
$pdo            = db();

// ── POST handler ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // CREATE / EDIT template
    if ($action === 'save_template') {
        $templateId   = (int)($_POST['template_id'] ?? 0);
        $name         = trim($_POST['name'] ?? '');
        $type         = $_POST['template_type'] ?? 'custom';
        $channel      = $_POST['channel'] ?? 'email';
        $subject      = trim($_POST['subject'] ?? '');
        $body         = trim($_POST['body'] ?? '');

        $validTypes   = ['welcome','lead_followup','appointment_reminder','review_request','promotion','re_engagement','thank_you','event_reminder','customer_followup','custom'];
        $validChannels= ['email','whatsapp','sms','internal'];

        if ($name && $body && in_array($type, $validTypes) && in_array($channel, $validChannels)) {
            // Extract variables like {name}, {business}, etc.
            preg_match_all('/\{([a-zA-Z_]+)\}/', $body . ' ' . $subject, $varMatches);
            $variables = array_values(array_unique($varMatches[1]));

            if ($templateId) {
                // Edit — verify ownership
                $chk = $pdo->prepare("SELECT id FROM communication_templates WHERE id=? AND partner_id=?");
                $chk->execute([$templateId, $partnerId]);
                if ($chk->fetch()) {
                    $pdo->prepare("UPDATE communication_templates
                        SET name=?, template_type=?, channel=?, subject=?, body=?, variables=?, updated_at=NOW()
                        WHERE id=? AND partner_id=?")
                        ->execute([$name, $type, $channel, $subject ?: null, $body, json_encode($variables), $templateId, $partnerId]);
                    partnerAuditLog($partnerId, $userId, null, 'template_updated', "Template: {$name}");
                    setFlash('success', 'Template updated successfully.');
                }
            } else {
                // Create
                $pdo->prepare("INSERT INTO communication_templates
                    (partner_id, name, template_type, channel, subject, body, variables, status)
                    VALUES (?,?,?,?,?,?,?,'active')")
                    ->execute([$partnerId, $name, $type, $channel, $subject ?: null, $body, json_encode($variables)]);
                partnerAuditLog($partnerId, $userId, null, 'template_created', "Template: {$name}");
                setFlash('success', "Template \"{$name}\" created.");
            }
        }
        redirect(SITE_URL . '/partner/templates');
    }

    // TOGGLE STATUS
    if ($action === 'toggle_status') {
        $templateId = (int)($_POST['template_id'] ?? 0);
        if ($templateId) {
            $pdo->prepare("UPDATE communication_templates
                SET status = IF(status='active','inactive','active')
                WHERE id=? AND partner_id=?")
                ->execute([$templateId, $partnerId]);
            partnerAuditLog($partnerId, $userId, null, 'template_toggled', "Template ID: {$templateId}");
        }
        redirect(SITE_URL . '/partner/templates');
    }

    // DELETE template
    if ($action === 'delete_template') {
        $templateId = (int)($_POST['template_id'] ?? 0);
        if ($templateId) {
            $chk = $pdo->prepare("SELECT id, name FROM communication_templates WHERE id=? AND partner_id=?");
            $chk->execute([$templateId, $partnerId]);
            $tpl = $chk->fetch();
            if ($tpl) {
                $pdo->prepare("DELETE FROM communication_templates WHERE id=? AND partner_id=?")->execute([$templateId, $partnerId]);
                partnerAuditLog($partnerId, $userId, null, 'template_deleted', "Template: {$tpl['name']}");
                setFlash('success', "Template deleted.");
            }
        }
        redirect(SITE_URL . '/partner/templates');
    }

    // USE template (increment use_count — called from other pages via AJAX-style form)
    if ($action === 'use_template') {
        $templateId = (int)($_POST['template_id'] ?? 0);
        if ($templateId) {
            $pdo->prepare("UPDATE communication_templates SET use_count = use_count + 1 WHERE id=? AND partner_id=?")
                ->execute([$templateId, $partnerId]);
        }
        redirect(SITE_URL . '/partner/templates');
    }

    redirect(SITE_URL . '/partner/templates');
}

// ── Data ────────────────────────────────────────────────────────
$filterChannel = $_GET['channel'] ?? '';
$filterType    = $_GET['type'] ?? '';
$filterStatus  = $_GET['status'] ?? 'active';
$editId        = (int)($_GET['edit'] ?? 0);

$validStatuses = ['active','inactive','all'];
if (!in_array($filterStatus, $validStatuses)) $filterStatus = 'active';

// Load templates
$where   = ['partner_id = ?'];
$params  = [$partnerId];
if ($filterChannel) { $where[] = 'channel = ?'; $params[] = $filterChannel; }
if ($filterType)    { $where[] = 'template_type = ?'; $params[] = $filterType; }
if ($filterStatus !== 'all') { $where[] = 'status = ?'; $params[] = $filterStatus; }

$sql = "SELECT * FROM communication_templates WHERE " . implode(' AND ', $where) . " ORDER BY use_count DESC, updated_at DESC";
$st  = $pdo->prepare($sql);
$st->execute($params);
$templates = $st->fetchAll();

// Template to edit
$editTemplate = null;
if ($editId) {
    $est = $pdo->prepare("SELECT * FROM communication_templates WHERE id=? AND partner_id=?");
    $est->execute([$editId, $partnerId]);
    $editTemplate = $est->fetch() ?: null;
}

// Summary counts
$cntSt = $pdo->prepare("SELECT status, COUNT(*) n FROM communication_templates WHERE partner_id=? GROUP BY status");
$cntSt->execute([$partnerId]);
$counts = ['active' => 0, 'inactive' => 0];
foreach ($cntSt->fetchAll() as $r) $counts[$r['status']] = (int)$r['n'];
$totalCount = array_sum($counts);

// Flash
$successMsg = getFlash('success');

// ── Helpers ─────────────────────────────────────────────────────
function channelBadge(string $ch): string {
    $map = [
        'email'    => ['📧','#3b82f6','#eff6ff'],
        'whatsapp' => ['💬','#059669','#d1fae5'],
        'sms'      => ['📱','#7c3aed','#ede9fe'],
        'internal' => ['📋','#6b7280','#f3f4f6'],
    ];
    [$icon, $col, $bg] = $map[$ch] ?? ['📄','#6b7280','#f3f4f6'];
    $label = ucfirst($ch);
    return "<span style=\"display:inline-flex;align-items:center;gap:.25rem;background:{$bg};color:{$col};border-radius:20px;padding:.2rem .65rem;font-size:.75rem;font-weight:600;\">{$icon} {$label}</span>";
}

function typeBadge(string $type): string {
    $labels = [
        'welcome'              => 'Welcome',
        'lead_followup'        => 'Lead Follow-Up',
        'appointment_reminder' => 'Appt Reminder',
        'review_request'       => 'Review Request',
        'promotion'            => 'Promotion',
        're_engagement'        => 'Re-Engagement',
        'thank_you'            => 'Thank You',
        'event_reminder'       => 'Event Reminder',
        'customer_followup'    => 'Customer Follow-Up',
        'custom'               => 'Custom',
    ];
    return '<span style="font-size:.75rem;color:var(--muted);">' . e($labels[$type] ?? $type) . '</span>';
}

// Default starter templates (shown when zero templates exist)
function starterTemplates(): array {
    return [
        [
            'name'          => 'New Lead Welcome',
            'template_type' => 'welcome',
            'channel'       => 'email',
            'subject'       => 'Thanks for your enquiry — {business}',
            'body'          => "Hi {name},\n\nThank you for reaching out to {business}! We've received your enquiry and will be in touch shortly.\n\nIn the meantime, if you have any questions please don't hesitate to reply to this message.\n\nWarm regards,\nThe {business} Team",
        ],
        [
            'name'          => 'Lead Follow-Up (Day 3)',
            'template_type' => 'lead_followup',
            'channel'       => 'whatsapp',
            'subject'       => '',
            'body'          => "Hi {name} 👋\n\nJust checking in — did you get a chance to look into {business}?\n\nWe'd love to help. Let us know if you have any questions or if there's a good time to chat.\n\n{business} Team",
        ],
        [
            'name'          => 'Review Request',
            'template_type' => 'review_request',
            'channel'       => 'email',
            'subject'       => 'How did we do? — {business}',
            'body'          => "Hi {name},\n\nThank you for choosing {business}! We hope everything went smoothly.\n\nIf you have a moment, we'd really appreciate it if you could leave us a quick review — it helps other customers find us and helps us keep improving.\n\nThank you so much!\n\nThe {business} Team",
        ],
        [
            'name'          => 'Thank You Message',
            'template_type' => 'thank_you',
            'channel'       => 'whatsapp',
            'subject'       => '',
            'body'          => "Hi {name} 😊\n\nThank you for using {business} — we really appreciate your support!\n\nIf you ever need us again or know someone who could benefit from our services, we'd love to hear from you.\n\nTake care,\n{business}",
        ],
        [
            'name'          => 'Re-Engagement (Inactive Customer)',
            'template_type' => 're_engagement',
            'channel'       => 'email',
            'subject'       => "We miss you — {business}",
            'body'          => "Hi {name},\n\nIt's been a while since we last heard from you, and we just wanted to check in.\n\nIf there's anything we can help with, or if you have any feedback about your past experience, we'd love to hear from you.\n\nWarm regards,\nThe {business} Team",
        ],
    ];
}

$pageTitle = 'Communication Templates';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.tpl-card {
  background: var(--card-bg, #fff);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 1.1rem 1.3rem;
  display: flex;
  flex-direction: column;
  gap: .5rem;
  transition: box-shadow .15s;
}
.tpl-card:hover { box-shadow: 0 2px 10px rgba(0,0,0,.06); }
.tpl-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 1rem;
}
.tpl-form { background: var(--card-bg, #fff); border: 1px solid var(--border); border-radius: 10px; padding: 1.4rem; }
.tpl-form label { font-size: .82rem; font-weight: 600; display: block; margin-bottom: .35rem; }
.tpl-form input, .tpl-form select, .tpl-form textarea {
  width: 100%; padding: .55rem .7rem; border: 1px solid var(--border);
  border-radius: 6px; background: var(--bg); color: var(--text);
  font-family: inherit; font-size: .88rem; box-sizing: border-box;
}
.tpl-form textarea { resize: vertical; }
.tpl-form .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: .9rem; }
.tpl-body-preview {
  font-size: .8rem; color: var(--muted); white-space: pre-wrap; word-break: break-word;
  background: var(--bg); border: 1px solid var(--border); border-radius: 6px;
  padding: .6rem .8rem; max-height: 140px; overflow: hidden; position: relative;
}
.tpl-body-preview::after {
  content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 40px;
  background: linear-gradient(transparent, var(--bg));
}
.var-chip {
  display: inline-block; background: #eff6ff; color: #1d4ed8;
  border-radius: 4px; padding: .1rem .4rem; font-size: .73rem; font-family: monospace;
  margin: .1rem;
}
.filter-bar { display: flex; gap: .6rem; flex-wrap: wrap; margin-bottom: 1.2rem; align-items: center; }
.filter-bar select { padding: .45rem .7rem; border: 1px solid var(--border); border-radius: 6px; background: var(--bg); color: var(--text); font-size: .83rem; }
</style>

<div class="partner-wrap" style="max-width:1100px; margin:0 auto; padding:1.5rem 1rem;">

  <!-- Header -->
  <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:1rem; margin-bottom:1.5rem;">
    <div>
      <h1 style="margin:0 0 .2rem; font-size:1.4rem;">📨 Communication Templates</h1>
      <p style="margin:0; font-size:.85rem; color:var(--muted);">
        Reusable message templates for email, WhatsApp and SMS.
        Use <code style="background:var(--bg); border:1px solid var(--border); border-radius:3px; padding:.1rem .3rem;">{name}</code>,
        <code style="background:var(--bg); border:1px solid var(--border); border-radius:3px; padding:.1rem .3rem;">{business}</code>
        and other placeholders to personalise messages.
      </p>
    </div>
    <a href="?new=1" style="background:var(--primary); color:#fff; text-decoration:none; border-radius:7px; padding:.55rem 1.2rem; font-weight:600; font-size:.9rem; white-space:nowrap;">+ New Template</a>
  </div>

  <?php if ($successMsg): ?>
  <div style="background:#d1fae5; border:1px solid #6ee7b7; border-radius:8px; padding:.8rem 1.1rem; margin-bottom:1.2rem; color:#065f46; font-size:.88rem;">
    ✅ <?= e($successMsg) ?>
  </div>
  <?php endif; ?>

  <!-- Stat strip -->
  <div style="display:flex; gap:.8rem; flex-wrap:wrap; margin-bottom:1.4rem;">
    <?php foreach ([
      ['Total',    $totalCount,         '#3b82f6'],
      ['Active',   $counts['active'],   '#059669'],
      ['Inactive', $counts['inactive'], '#9ca3af'],
    ] as [$label, $val, $col]): ?>
    <div style="background:var(--card-bg,#fff); border:1px solid var(--border); border-radius:8px; padding:.6rem 1.1rem; min-width:90px; text-align:center;">
      <div style="font-size:1.3rem; font-weight:700; color:<?= $col ?>;"><?= $val ?></div>
      <div style="font-size:.75rem; color:var(--muted);"><?= $label ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- CREATE / EDIT FORM -->
  <?php if (isset($_GET['new']) || $editTemplate): ?>
  <div class="tpl-form" style="margin-bottom:1.5rem;">
    <h3 style="margin:0 0 1rem;"><?= $editTemplate ? 'Edit Template' : 'New Template' ?></h3>
    <form method="POST" action="<?= SITE_URL ?>/partner/templates">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_template">
      <?php if ($editTemplate): ?>
      <input type="hidden" name="template_id" value="<?= $editTemplate['id'] ?>">
      <?php endif; ?>

      <div class="form-row">
        <div>
          <label>Template Name *</label>
          <input type="text" name="name" required maxlength="200"
            value="<?= e($editTemplate['name'] ?? '') ?>"
            placeholder="e.g. New Lead Welcome Message">
        </div>
        <div>
          <label>Channel *</label>
          <select name="channel">
            <?php foreach (['email' => '📧 Email', 'whatsapp' => '💬 WhatsApp', 'sms' => '📱 SMS', 'internal' => '📋 Internal Note'] as $v => $l): ?>
            <option value="<?= $v ?>" <?= ($editTemplate['channel'] ?? 'email') === $v ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form-row">
        <div>
          <label>Template Type</label>
          <select name="template_type">
            <?php foreach ([
              'welcome'              => 'Welcome',
              'lead_followup'        => 'Lead Follow-Up',
              'appointment_reminder' => 'Appointment Reminder',
              'review_request'       => 'Review Request',
              'promotion'            => 'Promotion',
              're_engagement'        => 'Re-Engagement',
              'thank_you'            => 'Thank You',
              'event_reminder'       => 'Event Reminder',
              'customer_followup'    => 'Customer Follow-Up',
              'custom'               => 'Custom',
            ] as $v => $l): ?>
            <option value="<?= $v ?>" <?= ($editTemplate['template_type'] ?? 'custom') === $v ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div id="subjectWrap">
          <label>Subject Line <span style="font-weight:400; color:var(--muted);">(email only)</span></label>
          <input type="text" name="subject" maxlength="300"
            value="<?= e($editTemplate['subject'] ?? '') ?>"
            placeholder="e.g. Thanks for your enquiry — {business}">
        </div>
      </div>

      <div style="margin-bottom:.9rem;">
        <label>Message Body *
          <span style="font-weight:400; color:var(--muted);">— use <code>{name}</code>, <code>{business}</code>, <code>{date}</code>, <code>{service}</code> as placeholders</span>
        </label>
        <textarea name="body" rows="8" required
          placeholder="Hi {name},&#10;&#10;Thank you for reaching out to {business}..."><?= e($editTemplate['body'] ?? '') ?></textarea>
      </div>

      <div style="display:flex; gap:.7rem; flex-wrap:wrap;">
        <button type="submit" style="background:var(--primary); color:#fff; border:none; border-radius:6px; padding:.55rem 1.4rem; cursor:pointer; font-weight:600;">
          <?= $editTemplate ? '💾 Save Changes' : '+ Create Template' ?>
        </button>
        <a href="<?= SITE_URL ?>/partner/templates" style="display:inline-block; background:var(--bg); border:1px solid var(--border); border-radius:6px; padding:.55rem 1.1rem; font-size:.88rem; color:var(--muted); text-decoration:none;">Cancel</a>
      </div>
    </form>
  </div>
  <?php endif; ?>

  <!-- STARTER TEMPLATES (only if zero templates exist and not creating) -->
  <?php if ($totalCount === 0 && !isset($_GET['new']) && !$editTemplate): ?>
  <div style="background:var(--card-bg,#fff); border:1px solid var(--border); border-radius:10px; padding:1.4rem; margin-bottom:1.5rem;">
    <h3 style="margin:0 0 .5rem;">🚀 Get started with starter templates</h3>
    <p style="font-size:.85rem; color:var(--muted); margin:0 0 1rem;">You don't have any templates yet. Click below to add any of these ready-made templates to your library.</p>
    <div class="tpl-grid">
      <?php foreach (starterTemplates() as $idx => $st): ?>
      <div class="tpl-card" style="border-top:3px solid var(--primary);">
        <div style="font-weight:600; font-size:.95rem;"><?= e($st['name']) ?></div>
        <div style="display:flex; gap:.4rem; flex-wrap:wrap;">
          <?= channelBadge($st['channel']) ?>
          <?= typeBadge($st['template_type']) ?>
        </div>
        <div class="tpl-body-preview"><?= e(mb_substr($st['body'], 0, 180)) ?></div>
        <form method="POST" action="<?= SITE_URL ?>/partner/templates" style="margin-top:.3rem;">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="save_template">
          <input type="hidden" name="name" value="<?= e($st['name']) ?>">
          <input type="hidden" name="template_type" value="<?= e($st['template_type']) ?>">
          <input type="hidden" name="channel" value="<?= e($st['channel']) ?>">
          <input type="hidden" name="subject" value="<?= e($st['subject']) ?>">
          <input type="hidden" name="body" value="<?= e($st['body']) ?>">
          <button type="submit" style="background:var(--primary); color:#fff; border:none; border-radius:6px; padding:.4rem 1rem; cursor:pointer; font-size:.83rem; font-weight:600; width:100%;">
            + Add This Template
          </button>
        </form>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- FILTER BAR -->
  <form method="GET" class="filter-bar">
    <span style="font-size:.83rem; color:var(--muted); font-weight:500;">Filter:</span>
    <select name="channel" onchange="this.form.submit()">
      <option value="">All Channels</option>
      <?php foreach (['email' => 'Email', 'whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'internal' => 'Internal'] as $v => $l): ?>
      <option value="<?= $v ?>" <?= $filterChannel === $v ? 'selected' : '' ?>><?= $l ?></option>
      <?php endforeach; ?>
    </select>
    <select name="type" onchange="this.form.submit()">
      <option value="">All Types</option>
      <?php foreach ([
        'welcome','lead_followup','appointment_reminder','review_request','promotion',
        're_engagement','thank_you','event_reminder','customer_followup','custom'
      ] as $v): ?>
      <option value="<?= $v ?>" <?= $filterType === $v ? 'selected' : '' ?>><?= ucwords(str_replace('_',' ',$v)) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="status" onchange="this.form.submit()">
      <option value="active" <?= $filterStatus==='active' ? 'selected' : '' ?>>Active</option>
      <option value="inactive" <?= $filterStatus==='inactive' ? 'selected' : '' ?>>Inactive</option>
      <option value="all" <?= $filterStatus==='all' ? 'selected' : '' ?>>All</option>
    </select>
    <?php if ($filterChannel || $filterType || $filterStatus !== 'active'): ?>
    <a href="<?= SITE_URL ?>/partner/templates" style="font-size:.8rem; color:var(--muted);">Clear</a>
    <?php endif; ?>
  </form>

  <!-- TEMPLATE LIST -->
  <?php if (empty($templates)): ?>
  <div style="background:var(--card-bg,#fff); border:1px solid var(--border); border-radius:10px; padding:2.5rem; text-align:center; color:var(--muted);">
    <div style="font-size:2rem; margin-bottom:.6rem;">📨</div>
    <p style="margin:0 0 .8rem;">No templates found<?= $filterChannel || $filterType ? ' matching your filter' : '' ?>.</p>
    <a href="?new=1" style="color:var(--primary); font-weight:600; text-decoration:none;">+ Create your first template</a>
  </div>
  <?php else: ?>
  <div class="tpl-grid">
    <?php foreach ($templates as $t):
      $vars = json_decode($t['variables'] ?? '[]', true) ?: [];
      $statusOpacity = $t['status'] === 'inactive' ? 'opacity:.65;' : '';
    ?>
    <div class="tpl-card" style="<?= $statusOpacity ?> border-top:3px solid <?= $t['status']==='active' ? 'var(--primary)' : '#d1d5db' ?>;">
      <!-- Header row -->
      <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:.5rem;">
        <div style="font-weight:600; font-size:.95rem; flex:1;"><?= e($t['name']) ?></div>
        <?php if ($t['status'] === 'inactive'): ?>
        <span style="font-size:.72rem; background:#f3f4f6; color:#6b7280; border-radius:20px; padding:.15rem .6rem; white-space:nowrap;">Inactive</span>
        <?php endif; ?>
      </div>

      <!-- Badges -->
      <div style="display:flex; gap:.35rem; flex-wrap:wrap; align-items:center;">
        <?= channelBadge($t['channel']) ?>
        <?= typeBadge($t['template_type']) ?>
        <?php if ($t['use_count'] > 0): ?>
        <span style="font-size:.73rem; color:var(--muted);">Used <?= (int)$t['use_count'] ?>×</span>
        <?php endif; ?>
      </div>

      <!-- Subject (email only) -->
      <?php if ($t['subject']): ?>
      <div style="font-size:.8rem; color:var(--muted);">📧 <?= e($t['subject']) ?></div>
      <?php endif; ?>

      <!-- Body preview -->
      <div class="tpl-body-preview"><?= e(mb_substr($t['body'], 0, 200)) ?></div>

      <!-- Variables -->
      <?php if (!empty($vars)): ?>
      <div style="margin-top:.2rem;">
        <?php foreach ($vars as $var): ?>
        <span class="var-chip">{<?= e($var) ?>}</span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Action row -->
      <div style="display:flex; gap:.5rem; flex-wrap:wrap; margin-top:.4rem; padding-top:.6rem; border-top:1px solid var(--border);">
        <a href="?edit=<?= $t['id'] ?>" style="font-size:.8rem; color:var(--primary); font-weight:600; text-decoration:none;">✏️ Edit</a>

        <!-- Copy body to clipboard -->
        <button onclick="copyTemplate(<?= $t['id'] ?>)" style="background:none; border:none; font-size:.8rem; color:var(--muted); cursor:pointer; padding:0; font-family:inherit;">📋 Copy</button>
        <textarea id="tpl-body-<?= $t['id'] ?>" style="position:absolute;left:-9999px;"><?= e($t['body']) ?></textarea>

        <!-- Toggle status -->
        <form method="POST" action="<?= SITE_URL ?>/partner/templates" style="display:inline;">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="toggle_status">
          <input type="hidden" name="template_id" value="<?= $t['id'] ?>">
          <button type="submit" style="background:none; border:none; font-size:.8rem; color:var(--muted); cursor:pointer; padding:0; font-family:inherit;">
            <?= $t['status'] === 'active' ? '⏸ Disable' : '▶ Enable' ?>
          </button>
        </form>

        <!-- Delete -->
        <form method="POST" action="<?= SITE_URL ?>/partner/templates" style="display:inline;"
              onsubmit="return confirm('Delete this template? This cannot be undone.');">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="delete_template">
          <input type="hidden" name="template_id" value="<?= $t['id'] ?>">
          <button type="submit" style="background:none; border:none; font-size:.8rem; color:#e63946; cursor:pointer; padding:0; font-family:inherit;">🗑 Delete</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Variable reference -->
  <div style="background:var(--card-bg,#fff); border:1px solid var(--border); border-radius:10px; padding:1.1rem 1.3rem; margin-top:1.5rem;">
    <h4 style="margin:0 0 .7rem; font-size:.9rem;">📌 Available Placeholders</h4>
    <div style="display:flex; flex-wrap:wrap; gap:.4rem; font-size:.8rem;">
      <?php foreach ([
        '{name}'     => 'Customer / contact name',
        '{business}' => 'Business name',
        '{date}'     => 'Today\'s date',
        '{service}'  => 'Service name',
        '{phone}'    => 'Business phone',
        '{email}'    => 'Business email',
        '{website}'  => 'Business website',
        '{city}'     => 'Business city',
        '{partner}'  => 'Your (partner) name',
      ] as $ph => $desc): ?>
      <div style="background:var(--bg); border:1px solid var(--border); border-radius:6px; padding:.35rem .7rem;">
        <code style="color:#1d4ed8;"><?= $ph ?></code>
        <span style="color:var(--muted); margin-left:.3rem;"><?= $desc ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

</div>

<script>
function copyTemplate(id) {
  const ta = document.getElementById('tpl-body-' + id);
  ta.select();
  document.execCommand('copy');
  // Brief visual feedback
  const btn = event.target;
  const orig = btn.textContent;
  btn.textContent = '✅ Copied!';
  setTimeout(() => btn.textContent = orig, 1800);
}

// Hide subject field for non-email channels
document.addEventListener('DOMContentLoaded', function() {
  const channelSelect = document.querySelector('select[name="channel"]');
  const subjectWrap   = document.getElementById('subjectWrap');
  if (!channelSelect || !subjectWrap) return;
  function updateSubject() {
    subjectWrap.style.display = channelSelect.value === 'email' ? '' : 'none';
  }
  channelSelect.addEventListener('change', updateSubject);
  updateSubject();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
