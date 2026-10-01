<?php
require_once __DIR__ . '/../../includes/config.php';
requireAdmin();

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $name      = trim($_POST['name'] ?? '');
    $subjectEn = trim($_POST['subject_en'] ?? '');
    $subjectFr = trim($_POST['subject_fr'] ?? '');
    $bodyEn    = trim($_POST['body_en'] ?? '');
    $bodyFr    = trim($_POST['body_fr'] ?? '');

    if (!$name)      $errors[] = 'Template name is required.';
    if (!$subjectEn) $errors[] = 'English subject is required.';
    if (!$bodyEn)    $errors[] = 'English body is required.';

    if (!$errors) {
        $pid = (int)($_POST['template_id'] ?? 0);
        if ($pid) {
            db()->prepare("UPDATE automation_templates SET name=?,subject_en=?,subject_fr=?,body_en=?,body_fr=?,updated_at=NOW() WHERE id=?")
                 ->execute([$name,$subjectEn,$subjectFr,$bodyEn,$bodyFr,$pid]);
            flash('success', 'Template updated.');
        } else {
            db()->prepare("INSERT INTO automation_templates (name,subject_en,subject_fr,body_en,body_fr) VALUES (?,?,?,?,?)")
                 ->execute([$name,$subjectEn,$subjectFr,$bodyEn,$bodyFr]);
            flash('success', 'Template created.');
        }
        redirect(SITE_URL . '/admin/automation/templates.php');
    }
    $action = (int)($_POST['template_id'] ?? 0) ? 'edit' : 'new';
    $id     = (int)($_POST['template_id'] ?? 0);
}

if ($action === 'delete' && $id) {
    $inUse = db()->prepare("SELECT COUNT(*) FROM automation_steps WHERE template_id=?");
    $inUse->execute([$id]);
    if ($inUse->fetchColumn() > 0) {
        flash('error', 'Cannot delete — template is used in a sequence.');
    } else {
        db()->prepare("DELETE FROM automation_templates WHERE id=?")->execute([$id]);
        flash('success', 'Template deleted.');
    }
    redirect(SITE_URL . '/admin/automation/templates.php');
}

if ($action === 'duplicate' && $id) {
    $s = db()->prepare("SELECT * FROM automation_templates WHERE id=?");
    $s->execute([$id]);
    $s = $s->fetch();
    if ($s) {
        db()->prepare("INSERT INTO automation_templates (name,subject_en,subject_fr,body_en,body_fr) VALUES (?,?,?,?,?)")
             ->execute([$s['name'].' (copy)',$s['subject_en'],$s['subject_fr'],$s['body_en'],$s['body_fr']]);
        flash('success', 'Template duplicated.');
    }
    redirect(SITE_URL . '/admin/automation/templates.php');
}

if ($action === 'test' && $id) {
    $s = db()->prepare("SELECT * FROM automation_templates WHERE id=?");
    $s->execute([$id]);
    $t = $s->fetch();
    if ($t) {
        $cu   = currentUser();
        $body = str_replace(
            ['{{name}}','{{business_name}}','{{site_url}}','{{listing_url}}','{{unsubscribe_url}}'],
            [$cu['name'],'Your Business',SITE_URL,SITE_URL.'/dashboard',SITE_URL.'/unsubscribe'],
            $t['body_en']
        );
        $sent = sendMail($cu['email'], '[TEST] '.$t['subject_en'], $body);
        flash($sent ? 'success' : 'error', $sent ? 'Test sent to '.$cu['email'] : 'Send failed — check SMTP settings.');
    }
    redirect(SITE_URL . '/admin/automation/templates.php');
}

$tpl = null;
if (in_array($action, ['edit','preview']) && $id) {
    $s = db()->prepare("SELECT * FROM automation_templates WHERE id=?");
    $s->execute([$id]);
    $tpl = $s->fetch();
    if (!$tpl) redirect(SITE_URL . '/admin/automation/templates.php');
}

$templates = db()->query("
    SELECT t.*,
           (SELECT COUNT(*) FROM automation_steps s WHERE s.template_id=t.id) AS seq_count,
           (SELECT COUNT(*) FROM automation_log l WHERE l.template_id=t.id AND l.status='sent') AS sent_count
    FROM automation_templates t ORDER BY t.updated_at DESC
")->fetchAll();

$MERGE_TAGS = ['{{name}}','{{business_name}}','{{listing_url}}','{{site_url}}','{{unsubscribe_url}}'];

$NAV = [
    ['href'=>SITE_URL.'/admin/','label'=>'📋 Listings'],
    ['href'=>SITE_URL.'/admin/users.php','label'=>'👤 Users'],
    ['href'=>SITE_URL.'/admin/orders.php','label'=>'📦 Orders'],
    ['href'=>SITE_URL.'/admin/emails.php','label'=>'✉️ Emails'],
    ['href'=>SITE_URL.'/admin/automation/','label'=>'🤖 Automation'],
    ['href'=>SITE_URL.'/admin/automation/templates.php','label'=>'📝 Templates'],
    ['href'=>SITE_URL.'/admin/automation/sequences.php','label'=>'🔗 Sequences'],
    ['href'=>SITE_URL.'/admin/automation/segments.php','label'=>'🏷️ Segments'],
    ['href'=>SITE_URL.'/admin/automation/log.php','label'=>'📊 Log'],
    ['href'=>SITE_URL.'/dashboard','label'=>'← Dashboard'],
];

$pageTitle = 'Email Templates — Automation';
require_once __DIR__ . '/../../includes/header.php';
?>

<style>
.tpl-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:1.25rem;transition:border-color .2s;}
.tpl-card:hover{border-color:rgba(0,168,120,.3);}
.tab-btn{padding:.5rem 1.1rem;border-radius:6px;border:1px solid var(--border);background:transparent;color:var(--muted);cursor:pointer;font-size:.82rem;transition:all .15s;}
.tab-btn.active{background:var(--green);color:#fff;border-color:var(--green);}
.merge-pill{display:inline-flex;align-items:center;gap:.3rem;background:rgba(245,200,66,.08);border:1px solid rgba(245,200,66,.2);border-radius:20px;padding:.2rem .65rem;font-size:.72rem;color:var(--yellow);cursor:pointer;margin:.2rem;}
.merge-pill:hover{background:rgba(245,200,66,.18);}
.lang-panel{display:none;}
.lang-panel.active{display:block;}
</style>

<div class="page-header"><div class="container">
  <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:2rem;">📝 Email Templates</h1>
</div></div>

<section class="page-section"><div class="container">

  <div class="filter-tabs" style="margin-bottom:2rem;flex-wrap:wrap;">
    <?php foreach ($NAV as $n): ?>
      <a href="<?= $n['href'] ?>" class="filter-tab <?= ($n['href']===SITE_URL.'/admin/automation/templates.php')?'active':'' ?>"><?= $n['label'] ?></a>
    <?php endforeach; ?>
  </div>

  <?php $fs=flash('success');$fe=flash('error'); ?>
  <?php if($fs): ?><div class="flash flash-success" style="margin-bottom:1rem;">✅ <?= e($fs) ?></div><?php endif; ?>
  <?php if($fe): ?><div class="flash flash-error"   style="margin-bottom:1rem;">❌ <?= e($fe) ?></div><?php endif; ?>

<?php if($action==='preview' && $tpl): ?>

  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:.5rem;">
    <h4 style="margin:0;">Preview: <?= e($tpl['name']) ?></h4>
    <div style="display:flex;gap:.5rem;">
      <a href="?action=test&id=<?= $tpl['id'] ?>" class="btn btn-outline btn-sm">📧 Send test</a>
      <a href="?action=edit&id=<?= $tpl['id'] ?>" class="btn btn-primary btn-sm">✏️ Edit</a>
      <a href="?" class="btn btn-outline btn-sm">← Back</a>
    </div>
  </div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
    <?php foreach(['en'=>'English','fr'=>'French'] as $l=>$lab): ?>
      <div>
        <div style="font-size:.72rem;color:var(--muted-2);text-transform:uppercase;letter-spacing:.08em;font-weight:600;margin-bottom:.5rem;"><?= $lab ?></div>
        <div class="listing-widget" style="padding:0;overflow:hidden;">
          <div style="background:#0D2E1A;padding:.75rem 1rem;border-bottom:1px solid rgba(255,255,255,.06);">
            <span style="font-family:Georgia,serif;font-size:1.1rem;font-weight:900;color:#F5C842;">237</span>
            <span style="font-family:Georgia,serif;font-weight:700;color:#fff;font-size:.9rem;">Biz</span>
          </div>
          <div style="padding:.5rem .75rem;background:rgba(0,0,0,.2);border-bottom:1px solid rgba(255,255,255,.04);font-size:.75rem;">
            <span style="color:var(--muted-2);">Subject:</span>
            <span style="color:var(--white);margin-left:.4rem;"><?= e($tpl['subject_'.$l]?:'No '.$lab.' subject') ?></span>
          </div>
          <div style="padding:1.25rem;font-size:.83rem;line-height:1.8;color:rgba(255,255,255,.8);">
            <?= $tpl['body_'.$l] ?: '<em style="color:var(--muted-2)">No '.$lab.' version yet</em>' ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

<?php elseif(in_array($action,['new','edit'])): ?>

  <!-- No external editor dependency — uses browser's built-in execCommand -->

  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:.5rem;">
    <div>
      <h4 style="margin:0;"><?= $tpl ? 'Edit: '.e($tpl['name']) : 'New Template' ?></h4>
      <p style="color:var(--muted-2);font-size:.78rem;margin:.25rem 0 0;">Use the toolbar to format. Switch to HTML tab for full code control.</p>
    </div>
    <a href="?" class="btn btn-outline btn-sm">← Back</a>
  </div>

  <?php foreach($errors as $err): ?>
    <div class="flash flash-error" style="margin-bottom:.75rem;">❌ <?= e($err) ?></div>
  <?php endforeach; ?>

  <!-- Merge tags -->
  <div style="background:rgba(245,200,66,.05);border:1px solid rgba(245,200,66,.15);border-radius:10px;padding:.85rem 1rem;margin-bottom:1.5rem;">
    <div style="font-size:.74rem;color:var(--yellow);font-weight:600;margin-bottom:.4rem;">PERSONALISATION TAGS — click to copy, then paste into subject or body</div>
    <?php foreach($MERGE_TAGS as $mt): ?>
      <span class="merge-pill" onclick="copyTag('<?= $mt ?>')"><?= e($mt) ?> 📋</span>
    <?php endforeach; ?>
    <span id="copy-ok" style="display:none;font-size:.72rem;color:var(--green);margin-left:.5rem;">✓ Copied!</span>
  </div>

  <form method="POST" id="tpl-form">
    <input type="hidden" name="csrf" value="<?= csrf() ?>">
    <input type="hidden" name="template_id" value="<?= $tpl['id'] ?? 0 ?>">

    <div class="form-group">
      <label>Template Name <span style="font-size:.72rem;color:var(--muted-2);">(internal — not shown to users)</span></label>
      <input type="text" name="name" required value="<?= e($tpl['name'] ?? '') ?>" placeholder="e.g. welcome, upsell-premium">
    </div>

    <div class="form-row" style="margin-bottom:1.25rem;">
      <div class="form-group">
        <label>Subject — English *</label>
        <input type="text" name="subject_en" id="subject_en" required value="<?= e($tpl['subject_en'] ?? '') ?>" placeholder="Welcome to 237Biz, {{name}}!">
      </div>
      <div class="form-group">
        <label>Subject — French</label>
        <input type="text" name="subject_fr" id="subject_fr" value="<?= e($tpl['subject_fr'] ?? '') ?>" placeholder="Bienvenue sur 237Biz, {{name}} !">
      </div>
    </div>

    <!-- Body tabs -->
    <div style="display:flex;gap:.5rem;margin-bottom:0;align-items:center;flex-wrap:wrap;">
      <button type="button" class="tab-btn active" id="tab-en" onclick="showLang('en')">🇬🇧 English Body *</button>
      <button type="button" class="tab-btn"        id="tab-fr" onclick="showLang('fr')">🇫🇷 French Body</button>
      <div style="margin-left:auto;display:flex;gap:.4rem;">
        <button type="button" class="tab-btn active" id="mode-rich" onclick="showMode('rich')">🖊 Rich Text</button>
        <button type="button" class="tab-btn"        id="mode-html" onclick="showMode('html')">⌨️ HTML</button>
      </div>
    </div>

    <!-- Hidden real textareas for form submission -->
    <textarea id="body_en" name="body_en" style="display:none;"><?= e($tpl['body_en'] ?? '') ?></textarea>
    <textarea id="body_fr" name="body_fr" style="display:none;"><?= e($tpl['body_fr'] ?? '') ?></textarea>

    <!-- Rich text panels (contenteditable) -->
    <div id="rich-mode" style="margin-top:.5rem;">
      <!-- Toolbar -->
      <div id="editor-toolbar" style="background:#1a3a2a;border:1px solid rgba(255,255,255,.1);border-radius:8px 8px 0 0;padding:.5rem .75rem;display:flex;gap:.25rem;flex-wrap:wrap;align-items:center;">
        <button type="button" onclick="fmt('bold')"           title="Bold"           style="font-weight:700;">B</button>
        <button type="button" onclick="fmt('italic')"         title="Italic"         style="font-style:italic;">I</button>
        <button type="button" onclick="fmt('underline')"      title="Underline"      style="text-decoration:underline;">U</button>
        <span style="color:rgba(255,255,255,.2);margin:0 .25rem;">|</span>
        <button type="button" onclick="fmtBlock('h2')"        title="Heading 2">H2</button>
        <button type="button" onclick="fmtBlock('h3')"        title="Heading 3">H3</button>
        <button type="button" onclick="fmtBlock('p')"         title="Paragraph">¶</button>
        <span style="color:rgba(255,255,255,.2);margin:0 .25rem;">|</span>
        <button type="button" onclick="fmt('insertUnorderedList')" title="Bullet list">• List</button>
        <button type="button" onclick="fmt('insertOrderedList')"   title="Numbered list">1. List</button>
        <span style="color:rgba(255,255,255,.2);margin:0 .25rem;">|</span>
        <button type="button" onclick="insertLink()"          title="Insert link">🔗 Link</button>
        <span style="color:rgba(255,255,255,.2);margin:0 .25rem;">|</span>
        <label title="Text colour" style="display:flex;align-items:center;gap:.25rem;cursor:pointer;">
          <span>A</span><input type="color" id="txt-color" value="#ffffff" onchange="fmt('foreColor', this.value)" style="width:24px;height:20px;padding:0;border:none;background:none;cursor:pointer;">
        </label>
      </div>
      <!-- EN editor -->
      <div id="panel-en" class="lang-panel active">
        <div id="editor-en"
             contenteditable="true"
             oninput="syncEditor('en')"
             style="min-height:280px;background:#0f2018;border:1px solid rgba(255,255,255,.1);border-top:none;border-radius:0 0 8px 8px;padding:1rem;color:#e0e0e0;font-family:'DM Sans',Arial,sans-serif;font-size:.9rem;line-height:1.75;outline:none;"></div>
      </div>
      <!-- FR editor -->
      <div id="panel-fr" class="lang-panel">
        <div id="editor-fr"
             contenteditable="true"
             oninput="syncEditor('fr')"
             style="min-height:280px;background:#0f2018;border:1px solid rgba(255,255,255,.1);border-top:none;border-radius:0 0 8px 8px;padding:1rem;color:#e0e0e0;font-family:'DM Sans',Arial,sans-serif;font-size:.9rem;line-height:1.75;outline:none;"></div>
      </div>
    </div>

    <!-- HTML panels -->
    <div id="html-mode" style="display:none;margin-top:.5rem;">
      <div id="html-panel-en" class="lang-panel active">
        <textarea id="raw_en" rows="16" style="width:100%;background:#0f2018;border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#e0e0e0;padding:.75rem;font-family:monospace;font-size:.78rem;resize:vertical;box-sizing:border-box;"><?= e($tpl['body_en'] ?? '') ?></textarea>
      </div>
      <div id="html-panel-fr" class="lang-panel">
        <textarea id="raw_fr" rows="16" style="width:100%;background:#0f2018;border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#e0e0e0;padding:.75rem;font-family:monospace;font-size:.78rem;resize:vertical;box-sizing:border-box;"><?= e($tpl['body_fr'] ?? '') ?></textarea>
      </div>
    </div>

    <div style="display:flex;gap:.75rem;margin-top:1.25rem;flex-wrap:wrap;">
      <button type="submit" class="btn btn-primary">💾 <?= $tpl ? 'Update Template' : 'Create Template' ?></button>
      <?php if($tpl): ?>
        <a href="?action=preview&id=<?= $tpl['id'] ?>" class="btn btn-outline">👁️ Preview</a>
        <a href="?action=test&id=<?= $tpl['id'] ?>" class="btn btn-outline">📧 Send Test</a>
        <a href="?action=duplicate&id=<?= $tpl['id'] ?>" class="btn btn-outline">📋 Duplicate</a>
      <?php endif; ?>
      <a href="?" class="btn btn-outline">Cancel</a>
    </div>
  </form>

  <style>
  #editor-toolbar button {
    background: rgba(255,255,255,.08);
    border: 1px solid rgba(255,255,255,.12);
    border-radius: 4px;
    color: #ddd;
    cursor: pointer;
    padding: .2rem .55rem;
    font-size: .82rem;
    line-height: 1.4;
    transition: background .15s;
  }
  #editor-toolbar button:hover { background: rgba(255,255,255,.18); }
  #editor-en a, #editor-fr a { color: #00A878; }
  #editor-en h2, #editor-fr h2 { color: #fff; margin: .75rem 0 .5rem; }
  #editor-en h3, #editor-fr h3 { color: #fff; margin: .6rem 0 .4rem; }
  #editor-en:focus, #editor-fr:focus { border-color: rgba(0,168,120,.4) !important; }
  </style>
  <script>
  var currentMode = 'rich';
  var currentLang = 'en';

  // ── Load initial content into editors ─────────────────────
  window.addEventListener('DOMContentLoaded', function() {
    var enContent = document.getElementById('body_en').value;
    var frContent = document.getElementById('body_fr').value;
    document.getElementById('editor-en').innerHTML = enContent;
    document.getElementById('editor-fr').innerHTML = frContent;
    document.getElementById('raw_en').value = enContent;
    document.getElementById('raw_fr').value = frContent;
  });

  // ── Sync editor → hidden textarea ─────────────────────────
  function syncEditor(lang) {
    var html = document.getElementById('editor-'+lang).innerHTML;
    document.getElementById('body_'+lang).value = html;
    document.getElementById('raw_'+lang).value  = html;
  }

  // ── Formatting commands ───────────────────────────────────
  function fmt(cmd, val) {
    var ed = document.getElementById('editor-'+currentLang);
    ed.focus();
    document.execCommand(cmd, false, val || null);
    syncEditor(currentLang);
  }

  function fmtBlock(tag) {
    var ed = document.getElementById('editor-'+currentLang);
    ed.focus();
    document.execCommand('formatBlock', false, tag);
    syncEditor(currentLang);
  }

  function insertLink() {
    var url = prompt('Enter URL:', 'https://');
    if (url) fmt('createLink', url);
  }

  // ── Language tabs ─────────────────────────────────────────
  function showLang(lang) {
    currentLang = lang;
    ['en','fr'].forEach(function(l) {
      var isActive = l === lang;
      // Rich panels
      var rp = document.getElementById('panel-'+l);
      if (rp) rp.className = 'lang-panel' + (isActive ? ' active' : '');
      // HTML panels
      var hp = document.getElementById('html-panel-'+l);
      if (hp) hp.className = 'lang-panel' + (isActive ? ' active' : '');
      // Tabs
      document.getElementById('tab-'+l).classList.toggle('active', isActive);
    });
  }

  // ── Mode toggle ───────────────────────────────────────────
  function showMode(mode) {
    currentMode = mode;
    if (mode === 'rich') {
      // HTML → editor
      ['en','fr'].forEach(function(l) {
        document.getElementById('editor-'+l).innerHTML = document.getElementById('raw_'+l).value;
        syncEditor(l);
      });
      document.getElementById('rich-mode').style.display = 'block';
      document.getElementById('html-mode').style.display = 'none';
    } else {
      // editor → HTML textarea
      ['en','fr'].forEach(function(l) { syncEditor(l); });
      document.getElementById('rich-mode').style.display = 'none';
      document.getElementById('html-mode').style.display = 'block';
    }
    document.getElementById('mode-rich').classList.toggle('active', mode==='rich');
    document.getElementById('mode-html').classList.toggle('active', mode==='html');
  }

  // ── Submit: ensure hidden textareas are up to date ────────
  document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('tpl-form').addEventListener('submit', function() {
      if (currentMode === 'rich') {
        syncEditor('en');
        syncEditor('fr');
      } else {
        document.getElementById('body_en').value = document.getElementById('raw_en').value;
        document.getElementById('body_fr').value = document.getElementById('raw_fr').value;
      }
    });
  });

  // ── Merge tag copy ────────────────────────────────────────
  function copyTag(tag) {
    navigator.clipboard.writeText(tag).then(function() {
      var el = document.getElementById('copy-ok');
      el.style.display = 'inline';
      setTimeout(function(){ el.style.display = 'none'; }, 2000);
    });
  }
  </script>

<?php else: ?>
  <!-- ══ LIST ══ -->
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;">
    <p style="color:var(--muted);font-size:.875rem;margin:0;"><?= count($templates) ?> templates</p>
    <a href="?action=new" class="btn btn-primary btn-sm">+ New Template</a>
  </div>

  <?php
  $existing = array_column($templates, 'name');
  $recommended = ['listing-approved'=>'Listing approved','enquiry-received'=>'New enquiry','monthly-newsletter'=>'Monthly newsletter','upsell-email'=>'Professional email upsell','upsell-crm'=>'CRM upsell','success-story'=>'Success story','special-offer'=>'Special offer'];
  $missing = array_filter($recommended, fn($k) => !in_array($k, $existing), ARRAY_FILTER_USE_KEY);
  if ($missing): ?>
    <div style="background:rgba(0,168,120,.06);border:1px solid rgba(0,168,120,.2);border-radius:12px;padding:1.25rem;margin-bottom:1.5rem;">
      <div style="font-weight:500;color:var(--green);margin-bottom:.5rem;">💡 Recommended templates to add</div>
      <div style="display:flex;flex-wrap:wrap;gap:.5rem;">
        <?php foreach($missing as $slug=>$desc): ?>
          <a href="?action=new" class="btn btn-outline btn-sm" style="font-size:.75rem;color:var(--green);border-color:rgba(0,168,120,.3);">+ <?= e($desc) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <div style="display:flex;flex-direction:column;gap:.75rem;">
    <?php foreach($templates as $t): ?>
      <div class="tpl-card">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
          <div style="flex:1;min-width:200px;">
            <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;margin-bottom:.35rem;">
              <span style="font-weight:600;color:var(--white);font-size:.9rem;"><?= e($t['name']) ?></span>
              <?php if($t['seq_count']>0): ?>
                <span style="font-size:.68rem;background:rgba(0,168,120,.12);color:var(--green);padding:.15rem .5rem;border-radius:20px;">In <?= $t['seq_count'] ?> sequence<?= $t['seq_count']!=1?'s':'' ?></span>
              <?php endif; ?>
              <?php if($t['sent_count']>0): ?>
                <span style="font-size:.68rem;background:rgba(255,255,255,.06);color:var(--muted-2);padding:.15rem .5rem;border-radius:20px;"><?= number_format($t['sent_count']) ?> sent</span>
              <?php endif; ?>
            </div>
            <div style="font-size:.78rem;color:var(--muted);">🇬🇧 <?= e(mb_substr($t['subject_en'],0,70)) ?></div>
            <?php if($t['subject_fr']): ?>
              <div style="font-size:.78rem;color:var(--muted);margin-top:.1rem;">🇫🇷 <?= e(mb_substr($t['subject_fr'],0,70)) ?></div>
            <?php else: ?>
              <div style="font-size:.7rem;color:rgba(245,200,66,.6);margin-top:.2rem;">⚠️ No French version</div>
            <?php endif; ?>
            <div style="font-size:.7rem;color:var(--muted-2);margin-top:.3rem;">Updated <?= timeAgo($t['updated_at']) ?></div>
          </div>
          <div style="display:flex;gap:.35rem;flex-wrap:wrap;flex-shrink:0;">
            <a href="?action=preview&id=<?= $t['id'] ?>"   class="btn btn-outline btn-sm" title="Preview">👁️</a>
            <a href="?action=test&id=<?= $t['id'] ?>"      class="btn btn-outline btn-sm" title="Send test">📧</a>
            <a href="?action=duplicate&id=<?= $t['id'] ?>" class="btn btn-outline btn-sm" title="Duplicate">📋</a>
            <a href="?action=edit&id=<?= $t['id'] ?>"      class="btn btn-primary btn-sm">Edit</a>
            <?php if($t['seq_count']==0): ?>
              <a href="?action=delete&id=<?= $t['id'] ?>"
                 onclick="return confirm('Delete \'<?= e(addslashes($t['name'])) ?>\'?')"
                 class="btn btn-sm" style="background:rgba(230,50,50,.1);color:#ff6b6b;border:1px solid rgba(230,50,50,.3);">✗</a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

</div></section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
