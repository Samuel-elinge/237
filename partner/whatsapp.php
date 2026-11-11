<?php
/**
 * partner/whatsapp.php — WhatsApp Sales Toolkit (Phase 5A)
 * - Quick WA link generator per listing
 * - WA message template library (channel=whatsapp)
 * - Message composer with variable substitution
 * - Click tracking via api/track
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';
require_once __DIR__ . '/../includes/partner-lang.php';

$partnerProfile = requireGrowthPartner();
$partnerId      = (int)$partnerProfile['id'];
$userId         = currentUser()['id'];
$pdo            = db();

// ── POST handler ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // Save WA template
    if ($action === 'save_wa_template') {
        $name       = trim($_POST['name'] ?? '');
        $type       = $_POST['template_type'] ?? 'lead_followup';
        $waCat      = in_array($_POST['wa_category'] ?? '', ['utility','marketing','authentication']) ? $_POST['wa_category'] : 'utility';
        $header     = trim($_POST['wa_header'] ?? '');
        $body       = trim($_POST['body'] ?? '');
        $footer     = trim($_POST['wa_footer'] ?? '');
        $templateId = (int)($_POST['template_id'] ?? 0);

        $validTypes = ['welcome','lead_followup','appointment_reminder','review_request','promotion','re_engagement','thank_you','custom'];

        if ($name && $body && in_array($type, $validTypes)) {
            preg_match_all('/\{([a-zA-Z_]+)\}/', $body . ' ' . $header, $varMatches);
            $variables = array_values(array_unique($varMatches[1]));

            if ($templateId) {
                $chk = $pdo->prepare("SELECT id FROM communication_templates WHERE id=? AND partner_id=? AND channel='whatsapp'");
                $chk->execute([$templateId, $partnerId]);
                if ($chk->fetch()) {
                    $pdo->prepare("UPDATE communication_templates
                        SET name=?, template_type=?, wa_category=?, wa_header=?, body=?, wa_footer=?, variables=?, updated_at=NOW()
                        WHERE id=? AND partner_id=?")
                        ->execute([$name, $type, $waCat, $header ?: null, $body, $footer ?: null, json_encode($variables), $templateId, $partnerId]);
                    setFlash('success', 'WhatsApp template updated.');
                    partnerAuditLog($partnerId, $userId, null, 'wa_template_updated', "Template: {$name}");
                }
            } else {
                $pdo->prepare("INSERT INTO communication_templates
                    (partner_id, name, template_type, channel, wa_category, wa_header, body, wa_footer, variables, status)
                    VALUES (?,?,?,'whatsapp',?,?,?,?,?,'active')")
                    ->execute([$partnerId, $name, $type, $waCat, $header ?: null, $body, $footer ?: null, json_encode($variables)]);
                setFlash('success', "Template \"{$name}\" created.");
                partnerAuditLog($partnerId, $userId, null, 'wa_template_created', "Template: {$name}");
            }
        }
        redirect(SITE_URL . '/partner/whatsapp');
    }

    // Delete WA template
    if ($action === 'delete_wa_template') {
        $templateId = (int)($_POST['template_id'] ?? 0);
        if ($templateId) {
            $chk = $pdo->prepare("SELECT name FROM communication_templates WHERE id=? AND partner_id=? AND channel='whatsapp'");
            $chk->execute([$templateId, $partnerId]);
            $tpl = $chk->fetch();
            if ($tpl) {
                $pdo->prepare("DELETE FROM communication_templates WHERE id=? AND partner_id=?")->execute([$templateId, $partnerId]);
                setFlash('success', "Template deleted.");
                partnerAuditLog($partnerId, $userId, null, 'wa_template_deleted', "Template: {$tpl['name']}");
            }
        }
        redirect(SITE_URL . '/partner/whatsapp');
    }

    redirect(SITE_URL . '/partner/whatsapp');
}

// ── Data ─────────────────────────────────────────────────────────
$listings = fetchPartnerListings($partnerId);

// WA templates
$waTplSt = $pdo->prepare("SELECT * FROM communication_templates WHERE partner_id=? AND channel='whatsapp' AND status='active' ORDER BY use_count DESC, updated_at DESC");
$waTplSt->execute([$partnerId]);
$waTemplates = $waTplSt->fetchAll();

// Edit template
$editId  = (int)($_GET['edit'] ?? 0);
$editTpl = null;
if ($editId) {
    $est = $pdo->prepare("SELECT * FROM communication_templates WHERE id=? AND partner_id=? AND channel='whatsapp'");
    $est->execute([$editId, $partnerId]);
    $editTpl = $est->fetch() ?: null;
}

$flash = getFlash();

partnerNavBadges($partnerId, $pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>WhatsApp Sales Toolkit — 237Biz Partner</title>
<?php partnerNavAssets(); ?>
<style>
:root {
  --bg: #0a1a14;
  --card: #0f2318;
  --border: rgba(0,168,120,0.18);
  --accent: #00A878;
  --accent2: #25D366;
  --muted: #8db8a8;
  --text: #d4ede5;
  --danger: #ef4444;
}
body { background: var(--bg); color: var(--text); font-family: system-ui, sans-serif; margin: 0; }
.wa-wrap { max-width: 1100px; margin: 0 auto; padding: 1.5rem 1rem 4rem; }
.wa-header { margin-bottom: 1.5rem; }
.wa-header h1 { font-size: 1.4rem; color: #fff; margin: 0 0 0.25rem; display: flex; align-items: center; gap: 0.5rem; }
.wa-header p  { color: var(--muted); margin: 0; font-size: 0.9rem; }

/* Tabs */
.wa-tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--border); margin-bottom: 1.5rem; }
.wa-tab  { padding: 0.6rem 1.1rem; font-size: 0.85rem; font-weight: 600; color: var(--muted);
           cursor: pointer; border-bottom: 2px solid transparent; background: none; border-top: none; border-left: none; border-right: none; }
.wa-tab.active, .wa-tab:hover { color: var(--accent); }
.wa-tab.active { border-bottom-color: var(--accent); }
.wa-panel { display: none; }
.wa-panel.active { display: block; }

/* Cards */
.card { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 1.25rem; margin-bottom: 1rem; }
.card-title { font-size: 1rem; font-weight: 700; color: #fff; margin: 0 0 1rem; }

/* Business selector */
.biz-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 0.75rem; }
.biz-card { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 1rem;
            cursor: pointer; transition: border-color 0.15s, transform 0.1s; }
.biz-card:hover { border-color: var(--accent); transform: translateY(-1px); }
.biz-card.selected { border-color: var(--accent2); background: rgba(37,211,102,0.08); }
.biz-name { font-weight: 700; color: #fff; margin-bottom: 0.3rem; }
.biz-wa   { font-size: 0.82rem; color: var(--accent2); display: flex; align-items: center; gap: 0.3rem; }
.biz-nowa { font-size: 0.82rem; color: var(--muted); font-style: italic; }

/* WA composer */
.composer-wrap { display: grid; grid-template-columns: 1fr 320px; gap: 1rem; }
@media(max-width: 700px) { .composer-wrap { grid-template-columns: 1fr; } }
.composer-left { }
.phone-preview { background: #0a2515; border: 1px solid var(--border); border-radius: 18px; padding: 1.25rem;
                  min-height: 200px; position: relative; overflow: hidden; }
.phone-preview::before { content: '📱 WhatsApp Preview'; position: absolute; top: 0; left: 0; right: 0;
  background: var(--accent2); color: #fff; font-size: 0.72rem; font-weight: 700; padding: 0.4rem 1rem; }
.preview-bubble { background: #1a3d25; border-radius: 0 10px 10px 10px; padding: 0.75rem 1rem;
  margin-top: 2rem; white-space: pre-wrap; font-size: 0.88rem; line-height: 1.5; color: #d4ede5;
  max-width: 90%; border-left: 3px solid var(--accent2); }
.preview-header { font-weight: 700; color: #fff; margin-bottom: 0.4rem; font-size: 0.9rem; }
.preview-footer { color: var(--muted); font-size: 0.75rem; margin-top: 0.5rem; }
.wa-send-btn { display: inline-flex; align-items: center; gap: 0.5rem; background: var(--accent2);
  color: #fff; padding: 0.7rem 1.4rem; border-radius: 8px; font-weight: 700; font-size: 0.9rem;
  text-decoration: none; border: none; cursor: pointer; margin-top: 0.75rem; width: 100%; justify-content: center; }
.wa-send-btn:hover { background: #1db954; }

/* Template grid */
.tpl-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 0.75rem; }
.tpl-card { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 1rem; }
.tpl-name  { font-weight: 700; color: #fff; margin-bottom: 0.3rem; }
.tpl-type  { font-size: 0.75rem; background: rgba(0,168,120,0.15); color: var(--accent);
  padding: 0.15rem 0.5rem; border-radius: 20px; display: inline-block; margin-bottom: 0.5rem; }
.tpl-wa-cat { font-size: 0.72rem; background: rgba(37,211,102,0.12); color: var(--accent2);
  padding: 0.15rem 0.5rem; border-radius: 20px; display: inline-block; margin-left: 0.3rem; }
.tpl-body  { font-size: 0.83rem; color: var(--muted); white-space: pre-wrap; max-height: 80px; overflow: hidden;
  position: relative; }
.tpl-body::after { content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 24px;
  background: linear-gradient(transparent, var(--card)); }
.tpl-actions { margin-top: 0.75rem; display: flex; gap: 0.5rem; flex-wrap: wrap; }
.btn-sm { padding: 0.3rem 0.7rem; border-radius: 6px; font-size: 0.78rem; font-weight: 600; cursor: pointer; border: none; }
.btn-use  { background: rgba(37,211,102,0.15); color: var(--accent2); }
.btn-edit { background: rgba(0,168,120,0.15); color: var(--accent); }
.btn-del  { background: rgba(239,68,68,0.12); color: var(--danger); }
.btn-use:hover { background: rgba(37,211,102,0.3); }
.btn-edit:hover { background: rgba(0,168,120,0.3); }
.btn-del:hover  { background: rgba(239,68,68,0.25); }
.use-count { font-size: 0.72rem; color: var(--muted); margin-top: 0.4rem; }

/* Form */
.form-group { margin-bottom: 1rem; }
.form-label { display: block; font-size: 0.82rem; color: var(--muted); margin-bottom: 0.4rem; font-weight: 600; }
.form-input, .form-select, .form-textarea {
  width: 100%; background: rgba(255,255,255,0.05); border: 1px solid var(--border);
  border-radius: 7px; color: #fff; font-size: 0.88rem; padding: 0.6rem 0.8rem; box-sizing: border-box; }
.form-textarea { resize: vertical; min-height: 100px; font-family: inherit; line-height: 1.5; }
.form-input:focus, .form-select:focus, .form-textarea:focus { outline: none; border-color: var(--accent); }
.form-select option { background: #0f2318; }
.form-hint { font-size: 0.75rem; color: var(--muted); margin-top: 0.3rem; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
@media(max-width: 600px) { .form-row { grid-template-columns: 1fr; } }
.btn-primary { background: var(--accent); color: #fff; border: none; border-radius: 8px;
  padding: 0.65rem 1.4rem; font-weight: 700; cursor: pointer; font-size: 0.9rem; }
.btn-primary:hover { background: #00c490; }
.btn-secondary { background: rgba(255,255,255,0.08); color: var(--muted); border: 1px solid var(--border);
  border-radius: 8px; padding: 0.65rem 1rem; font-weight: 600; cursor: pointer; font-size: 0.88rem;
  text-decoration: none; display: inline-block; }

/* Vars helper */
.vars-hint { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-top: 0.5rem; }
.var-chip { background: rgba(0,168,120,0.1); border: 1px solid rgba(0,168,120,0.2); color: var(--accent);
  padding: 0.15rem 0.6rem; border-radius: 20px; font-size: 0.75rem; cursor: pointer; font-family: monospace; }
.var-chip:hover { background: rgba(0,168,120,0.25); }

/* Flash */
.flash { padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.88rem; font-weight: 600; }
.flash-success { background: rgba(0,168,120,0.15); border: 1px solid rgba(0,168,120,0.3); color: #6ee7c8; }
.flash-error   { background: rgba(239,68,68,0.12); border: 1px solid rgba(239,68,68,0.3); color: #fca5a5; }

/* Empty state */
.empty { text-align: center; padding: 2.5rem 1rem; color: var(--muted); }
.empty-icon { font-size: 2.5rem; margin-bottom: 0.5rem; }

/* Quick link box */
.link-box { display: flex; gap: 0.5rem; align-items: center; margin-top: 0.5rem; }
.link-url  { flex: 1; background: rgba(255,255,255,0.05); border: 1px solid var(--border); border-radius: 7px;
  padding: 0.5rem 0.8rem; color: var(--accent2); font-size: 0.82rem; word-break: break-all; }
.btn-copy  { background: rgba(255,255,255,0.08); border: 1px solid var(--border); border-radius: 7px;
  padding: 0.5rem 0.8rem; cursor: pointer; font-size: 0.8rem; color: var(--muted); white-space: nowrap; }
.btn-copy:hover { color: #fff; }
</style>
</head>
<body>
<?php partnerNav('whatsapp'); ?>

<div class="wa-wrap">
  <div class="wa-header">
    <h1>💬 WhatsApp Sales Toolkit</h1>
    <p>Send messages to your clients, build your template library, and track engagement.</p>
  </div>

  <?php if ($flash): ?>
  <div class="flash flash-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['message']) ?></div>
  <?php endif; ?>

  <div class="wa-tabs">
    <button class="wa-tab active" onclick="showTab('send')">📤 Quick Send</button>
    <button class="wa-tab" onclick="showTab('templates')">📋 Templates</button>
    <button class="wa-tab <?= $editTpl ? 'active' : '' ?>" onclick="showTab('build')" id="build-tab">✏️ <?= $editTpl ? 'Edit Template' : 'Build Template' ?></button>
  </div>

  <!-- TAB: Quick Send -->
  <div class="wa-panel active" id="tab-send">
    <?php if (empty($listings)): ?>
      <div class="empty"><div class="empty-icon">📋</div><p>No businesses assigned to your portfolio yet.</p></div>
    <?php else: ?>
      <div class="card">
        <div class="card-title">1. Select a Business</div>
        <div class="biz-grid">
          <?php foreach ($listings as $biz):
            $wa = preg_replace('/\D/', '', $biz['whatsapp'] ?? '');
            if ($wa && strlen($wa) >= 8 && $wa[0] !== '2') $wa = '237' . $wa;
          ?>
          <div class="biz-card" id="biz-<?= $biz['id'] ?>" onclick="selectBiz(<?= $biz['id'] ?>, <?= htmlspecialchars(json_encode($biz['name']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($wa), ENT_QUOTES) ?>)">
            <div class="biz-name"><?= htmlspecialchars($biz['name']) ?></div>
            <?php if ($wa): ?>
              <div class="biz-wa">📱 +<?= htmlspecialchars($wa) ?></div>
            <?php else: ?>
              <div class="biz-nowa">No WhatsApp number on file</div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="card" id="composer-card" style="display:none">
        <div class="card-title">2. Compose Your Message</div>
        <div class="composer-wrap">
          <div class="composer-left">
            <?php if (!empty($waTemplates)): ?>
            <div class="form-group">
              <label class="form-label">Load a Template (optional)</label>
              <select class="form-select" id="tpl-select" onchange="loadTemplate(this.value)">
                <option value="">— Start from scratch —</option>
                <?php foreach ($waTemplates as $t): ?>
                <option value="<?= $t['id'] ?>"
                  data-body="<?= htmlspecialchars($t['body'], ENT_QUOTES) ?>"
                  data-header="<?= htmlspecialchars($t['wa_header'] ?? '', ENT_QUOTES) ?>"
                  data-footer="<?= htmlspecialchars($t['wa_footer'] ?? '', ENT_QUOTES) ?>">
                  <?= htmlspecialchars($t['name']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php endif; ?>

            <div class="form-group">
              <label class="form-label">Message Header (optional — bold)</label>
              <input type="text" class="form-input" id="msg-header" placeholder="e.g. Special Offer for You!" oninput="updatePreview()">
            </div>
            <div class="form-group">
              <label class="form-label">Message Body</label>
              <textarea class="form-textarea" id="msg-body" rows="6" placeholder="Hello {name}, I wanted to follow up about..." oninput="updatePreview()"></textarea>
              <div class="form-hint">Use {name}, {business}, {date}, {offer} as placeholders</div>
              <div class="vars-hint">
                <span class="var-chip" onclick="insertVar('{name}')">{name}</span>
                <span class="var-chip" onclick="insertVar('{business}')">{business}</span>
                <span class="var-chip" onclick="insertVar('{date}')">{date}</span>
                <span class="var-chip" onclick="insertVar('{offer}')">{offer}</span>
                <span class="var-chip" onclick="insertVar('{phone}')">{phone}</span>
              </div>
            </div>
            <div class="form-group">
              <label class="form-label">Footer (optional — small text)</label>
              <input type="text" class="form-input" id="msg-footer" placeholder="e.g. Reply STOP to unsubscribe" oninput="updatePreview()">
            </div>

            <div class="form-group">
              <label class="form-label">Client Name (to personalise)</label>
              <input type="text" class="form-input" id="client-name" placeholder="e.g. Jean-Pierre" oninput="updatePreview()">
            </div>
          </div>

          <div>
            <div class="phone-preview">
              <div class="preview-bubble" id="preview-bubble">
                <div class="preview-header" id="preview-header" style="display:none"></div>
                <span id="preview-body">Your message will appear here...</span>
                <div class="preview-footer" id="preview-footer" style="display:none"></div>
              </div>
            </div>
            <a href="#" class="wa-send-btn" id="wa-send-link" target="_blank" onclick="trackWaSend()">
              💬 Open in WhatsApp
            </a>
            <div style="margin-top:0.75rem">
              <div class="form-hint" style="text-align:center">Opens WhatsApp with your message pre-filled</div>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <!-- TAB: Templates -->
  <div class="wa-panel" id="tab-templates">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
      <div style="color:var(--muted); font-size:0.88rem;"><?= count($waTemplates) ?> template<?= count($waTemplates) !== 1 ? 's' : '' ?></div>
      <button class="btn-primary" onclick="showTab('build')">+ New Template</button>
    </div>

    <?php if (empty($waTemplates)): ?>
      <div class="empty">
        <div class="empty-icon">📝</div>
        <p>No WhatsApp templates yet.</p>
        <button class="btn-primary" onclick="showTab('build')" style="margin-top:0.5rem">Create your first template</button>
      </div>
    <?php else: ?>
      <div class="tpl-grid">
        <?php foreach ($waTemplates as $t):
          $vars = json_decode($t['variables'] ?? '[]', true) ?: [];
        ?>
        <div class="tpl-card" id="tplcard-<?= $t['id'] ?>">
          <div class="tpl-name"><?= htmlspecialchars($t['name']) ?></div>
          <div>
            <span class="tpl-type"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $t['template_type']))) ?></span>
            <?php if ($t['wa_category']): ?>
            <span class="tpl-wa-cat"><?= htmlspecialchars($t['wa_category']) ?></span>
            <?php endif; ?>
          </div>
          <?php if ($t['wa_header']): ?>
          <div style="font-weight:700; color:#fff; font-size:0.82rem; margin:0.4rem 0 0.2rem;">📌 <?= htmlspecialchars($t['wa_header']) ?></div>
          <?php endif; ?>
          <div class="tpl-body"><?= htmlspecialchars($t['body']) ?></div>
          <?php if (!empty($vars)): ?>
          <div style="display:flex; flex-wrap:wrap; gap:0.3rem; margin-top:0.4rem;">
            <?php foreach ($vars as $v): ?>
            <span style="font-size:0.7rem; background:rgba(0,168,120,0.1); color:var(--accent); padding:0.1rem 0.4rem; border-radius:10px; font-family:monospace;">{<?= htmlspecialchars($v) ?>}</span>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <div class="use-count">Used <?= (int)$t['use_count'] ?> time<?= $t['use_count'] !== 1 ? 's' : '' ?></div>
          <div class="tpl-actions">
            <button class="btn-sm btn-use" onclick="useTemplate(<?= $t['id'] ?>)">📤 Use</button>
            <button class="btn-sm btn-edit" onclick="editTemplate(<?= $t['id'] ?>)">✏️ Edit</button>
            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this template?')">
              <?= gpCsrfField() ?>
              <input type="hidden" name="action" value="delete_wa_template">
              <input type="hidden" name="template_id" value="<?= $t['id'] ?>">
              <button type="submit" class="btn-sm btn-del">🗑</button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- TAB: Build / Edit Template -->
  <div class="wa-panel <?= $editTpl ? 'active' : '' ?>" id="tab-build">
    <div class="card">
      <div class="card-title"><?= $editTpl ? '✏️ Edit WhatsApp Template' : '➕ New WhatsApp Template' ?></div>
      <form method="POST">
        <?= gpCsrfField() ?>
        <input type="hidden" name="action" value="save_wa_template">
        <?php if ($editTpl): ?>
        <input type="hidden" name="template_id" value="<?= $editTpl['id'] ?>">
        <?php endif; ?>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Template Name *</label>
            <input type="text" class="form-input" name="name" required placeholder="e.g. Follow-up After Audit"
              value="<?= htmlspecialchars($editTpl['name'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Type</label>
            <select class="form-select" name="template_type">
              <?php $types = ['welcome'=>'Welcome','lead_followup'=>'Lead Follow-up','appointment_reminder'=>'Appointment Reminder',
                'review_request'=>'Review Request','promotion'=>'Promotion','re_engagement'=>'Re-engagement',
                'thank_you'=>'Thank You','custom'=>'Custom'];
              foreach ($types as $k => $v): ?>
              <option value="<?= $k ?>" <?= ($editTpl['template_type'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">WhatsApp Category</label>
          <select class="form-select" name="wa_category">
            <option value="utility"     <?= ($editTpl['wa_category'] ?? '') === 'utility'      ? 'selected' : '' ?>>Utility (transactional)</option>
            <option value="marketing"   <?= ($editTpl['wa_category'] ?? '') === 'marketing'    ? 'selected' : '' ?>>Marketing (promotions)</option>
            <option value="authentication" <?= ($editTpl['wa_category'] ?? '') === 'authentication' ? 'selected' : '' ?>>Authentication (OTP)</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Header (bold — optional)</label>
          <input type="text" class="form-input" name="wa_header" placeholder="Short bold headline..."
            value="<?= htmlspecialchars($editTpl['wa_header'] ?? '') ?>" oninput="updateBuildPreview()">
        </div>

        <div class="form-group">
          <label class="form-label">Message Body *</label>
          <textarea class="form-textarea" name="body" rows="7" required oninput="updateBuildPreview()"
            placeholder="Hello {name}, this is {business}..."><?= htmlspecialchars($editTpl['body'] ?? '') ?></textarea>
          <div class="form-hint">Variables: {name} {business} {date} {offer} {phone} — click to insert:</div>
          <div class="vars-hint">
            <span class="var-chip" onclick="insertBuildVar('{name}')">{name}</span>
            <span class="var-chip" onclick="insertBuildVar('{business}')">{business}</span>
            <span class="var-chip" onclick="insertBuildVar('{date}')">{date}</span>
            <span class="var-chip" onclick="insertBuildVar('{offer}')">{offer}</span>
            <span class="var-chip" onclick="insertBuildVar('{phone}')">{phone}</span>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Footer (small text — optional)</label>
          <input type="text" class="form-input" name="wa_footer" placeholder="e.g. 237Biz Growth Partner · Reply STOP to opt out"
            value="<?= htmlspecialchars($editTpl['wa_footer'] ?? '') ?>" oninput="updateBuildPreview()">
        </div>

        <!-- Live preview -->
        <div class="phone-preview" style="margin-bottom:1rem">
          <div class="preview-bubble" id="build-preview-bubble">
            <div class="preview-header" id="build-preview-header" style="display:none"></div>
            <span id="build-preview-body"><?= htmlspecialchars($editTpl['body'] ?? 'Your template will preview here...') ?></span>
            <div class="preview-footer" id="build-preview-footer" style="display:none"></div>
          </div>
        </div>

        <div style="display:flex; gap:0.75rem; align-items:center;">
          <button type="submit" class="btn-primary"><?= $editTpl ? '💾 Save Changes' : '✅ Create Template' ?></button>
          <?php if ($editTpl): ?>
          <a href="<?= SITE_URL ?>/partner/whatsapp" class="btn-secondary">Cancel</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// ── Tab switching ──────────────────────────────────────────────
function showTab(tab) {
  document.querySelectorAll('.wa-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.wa-tab').forEach(t => t.classList.remove('active'));
  document.getElementById('tab-' + tab).classList.add('active');
  document.querySelectorAll('.wa-tab').forEach(t => {
    if (t.textContent.toLowerCase().includes(tab === 'send' ? 'quick' : tab === 'templates' ? 'template' : 'build')) {
      t.classList.add('active');
    }
  });
}
<?php if ($editTpl): ?>
document.addEventListener('DOMContentLoaded', () => showTab('build'));
<?php endif; ?>

// ── Business selector ─────────────────────────────────────────
let selectedWa = '';
let selectedBizName = '';

function selectBiz(id, name, wa) {
  document.querySelectorAll('.biz-card').forEach(c => c.classList.remove('selected'));
  document.getElementById('biz-' + id).classList.add('selected');
  selectedWa = wa;
  selectedBizName = name;
  document.getElementById('composer-card').style.display = wa ? 'block' : 'none';
  if (!wa) alert('This business has no WhatsApp number on file. Please add one in the listings admin.');
  updatePreview();
}

// ── Preview (send tab) ────────────────────────────────────────
function updatePreview() {
  const header = document.getElementById('msg-header')?.value || '';
  const body   = document.getElementById('msg-body')?.value || '';
  const footer = document.getElementById('msg-footer')?.value || '';
  const name   = document.getElementById('client-name')?.value || '{name}';

  const filled = body
    .replace(/\{name\}/g, name || '{name}')
    .replace(/\{business\}/g, selectedBizName || '{business}')
    .replace(/\{date\}/g, new Date().toLocaleDateString('en-GB', {day:'numeric',month:'long',year:'numeric'}))
    .replace(/\{offer\}/g, '{offer}')
    .replace(/\{phone\}/g, selectedWa || '{phone}');

  const hEl = document.getElementById('preview-header');
  const bEl = document.getElementById('preview-body');
  const fEl = document.getElementById('preview-footer');
  if (hEl) { hEl.textContent = header; hEl.style.display = header ? 'block' : 'none'; }
  if (bEl) bEl.textContent = filled || 'Your message will appear here...';
  if (fEl) { fEl.textContent = footer; fEl.style.display = footer ? 'block' : 'none'; }

  // Update WA link
  const full = (header ? '*' + header + '*\n\n' : '') + filled + (footer ? '\n\n_' + footer + '_' : '');
  const link = document.getElementById('wa-send-link');
  if (link && selectedWa) {
    link.href = 'https://wa.me/' + selectedWa + '?text=' + encodeURIComponent(full);
  }
}

function insertVar(v) {
  const ta = document.getElementById('msg-body');
  if (!ta) return;
  const s = ta.selectionStart, e = ta.selectionEnd;
  ta.value = ta.value.slice(0, s) + v + ta.value.slice(e);
  ta.selectionStart = ta.selectionEnd = s + v.length;
  ta.focus();
  updatePreview();
}

function trackWaSend() {
  // Increment use_count if a template was loaded
  const sel = document.getElementById('tpl-select');
  if (sel && sel.value) {
    fetch('<?= SITE_URL ?>/partner/templates', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: 'action=use_template&template_id=' + sel.value + '&<?= gpCsrfToken() ?>=<?= gpCsrfValue() ?>'
    }).catch(() => {});
  }
}

function loadTemplate(id) {
  if (!id) return;
  const opt = document.querySelector('#tpl-select option[value="' + id + '"]');
  if (!opt) return;
  const h = document.getElementById('msg-header');
  const b = document.getElementById('msg-body');
  const f = document.getElementById('msg-footer');
  if (h) h.value = opt.dataset.header || '';
  if (b) b.value = opt.dataset.body || '';
  if (f) f.value = opt.dataset.footer || '';
  updatePreview();
}

// ── Build tab preview ─────────────────────────────────────────
function updateBuildPreview() {
  const headerEl = document.querySelector('[name="wa_header"]');
  const bodyEl   = document.querySelector('[name="body"]');
  const footerEl = document.querySelector('[name="wa_footer"]');
  const header = headerEl?.value || '';
  const body   = bodyEl?.value   || '';
  const footer = footerEl?.value || '';

  const hEl = document.getElementById('build-preview-header');
  const bEl = document.getElementById('build-preview-body');
  const fEl = document.getElementById('build-preview-footer');
  if (hEl) { hEl.textContent = header; hEl.style.display = header ? 'block' : 'none'; }
  if (bEl) bEl.textContent = body || 'Your template will preview here...';
  if (fEl) { fEl.textContent = footer; fEl.style.display = footer ? 'block' : 'none'; }
}

function insertBuildVar(v) {
  const ta = document.querySelector('[name="body"]');
  if (!ta) return;
  const s = ta.selectionStart, e = ta.selectionEnd;
  ta.value = ta.value.slice(0, s) + v + ta.value.slice(e);
  ta.selectionStart = ta.selectionEnd = s + v.length;
  ta.focus();
  updateBuildPreview();
}

function useTemplate(id) {
  document.getElementById('tpl-select') && (document.getElementById('tpl-select').value = id);
  loadTemplate(id);
  showTab('send');
}

function editTemplate(id) {
  window.location.href = '<?= SITE_URL ?>/partner/whatsapp?edit=' + id;
}

// Init build preview if editing
document.addEventListener('DOMContentLoaded', updateBuildPreview);
</script>
</body>
</html>
