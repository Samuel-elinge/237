<?php
/**
 * Admin — BHC Settings
 * Configure the Business Health Check tool: scoring, emails, feature toggles, etc.
 * Settings are stored in a bhc_settings key-value table.
 */
define('IN_237BIZ', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/bhc-config.php';
requireAdmin();

$pdo = db();

// ── Auto-migrate: ensure bhc_settings table exists ────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS bhc_settings (
        `key`        VARCHAR(80)  NOT NULL PRIMARY KEY,
        `value`      TEXT         DEFAULT NULL,
        `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `updated_by` INT UNSIGNED DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}

// ── Helpers ───────────────────────────────────────────────────────────────────
function bhcSetting(PDO $pdo, string $key, string $default = ''): string {
    static $cache = null;
    if ($cache === null) {
        try {
            $rows = $pdo->query("SELECT `key`, `value` FROM bhc_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
            $cache = $rows ?: [];
        } catch (Exception $e) { $cache = []; }
    }
    return $cache[$key] ?? $default;
}

function bhcSaveSetting(PDO $pdo, string $key, string $value): void {
    $uid = $_SESSION['user_id'] ?? null;
    $pdo->prepare(
        "INSERT INTO bhc_settings (`key`, `value`, `updated_by`)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), `updated_at`=NOW(), `updated_by`=VALUES(`updated_by`)"
    )->execute([$key, $value, $uid]);
}

// ── POST handler ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $section = $_POST['section'] ?? '';

    if ($section === 'general') {
        $keys = [
            'bhc_enabled', 'bhc_title_en', 'bhc_title_fr',
            'bhc_intro_en', 'bhc_intro_fr',
            'bhc_results_show_band', 'bhc_results_show_category',
            'bhc_results_show_recommendations', 'bhc_allow_pdf',
            'bhc_allow_retake',
        ];
        foreach ($keys as $k) {
            $v = $_POST[$k] ?? '';
            if (in_array($k, ['bhc_enabled','bhc_results_show_band','bhc_results_show_category',
                                'bhc_results_show_recommendations','bhc_allow_pdf','bhc_allow_retake'])) {
                $v = $v === '1' ? '1' : '0';
            }
            bhcSaveSetting($pdo, $k, trim((string)$v));
        }
        header('Location: ' . SITE_URL . '/admin/bhc-settings?flash=success:General+settings+saved&tab=general');
        exit;
    }

    if ($section === 'email') {
        $keys = [
            'bhc_email_enabled',
            'bhc_email_from_name',
            'bhc_email_from_address',
            'bhc_email_send_report',
            'bhc_email_notify_admin',
            'bhc_email_admin_address',
            'bhc_email_subject_en',
            'bhc_email_subject_fr',
        ];
        foreach ($keys as $k) {
            $v = $_POST[$k] ?? '';
            if (in_array($k, ['bhc_email_enabled','bhc_email_send_report','bhc_email_notify_admin'])) {
                $v = $v === '1' ? '1' : '0';
            }
            bhcSaveSetting($pdo, $k, trim((string)$v));
        }
        header('Location: ' . SITE_URL . '/admin/bhc-settings?flash=success:Email+settings+saved&tab=email');
        exit;
    }

    if ($section === 'scoring') {
        // Category weights — must sum to 100
        $cats = $pdo->query("SELECT id, slug FROM bhc_categories ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
        $totalW = 0;
        $updates = [];
        foreach ($cats as $cat) {
            $w = max(0, min(100, (int)($_POST['weight_' . $cat['id']] ?? 0)));
            $totalW += $w;
            $updates[$cat['id']] = $w;
        }
        if ($totalW !== 100) {
            header('Location: ' . SITE_URL . '/admin/bhc-settings?flash=error:Weights+must+sum+to+100+(got+' . $totalW . ')&tab=scoring');
            exit;
        }
        $wStmt = $pdo->prepare("UPDATE bhc_categories SET weight=? WHERE id=?");
        foreach ($updates as $catId => $w) {
            $wStmt->execute([$w, $catId]);
        }
        // Also save score band thresholds
        $threshKeys = ['bhc_threshold_developing','bhc_threshold_good','bhc_threshold_strong'];
        $threshVals = [];
        foreach ($threshKeys as $k) {
            $v = max(0, min(100, (int)($_POST[$k] ?? 0)));
            $threshVals[$k] = $v;
        }
        // Validate ascending order
        if (!($threshVals['bhc_threshold_developing'] < $threshVals['bhc_threshold_good'] &&
              $threshVals['bhc_threshold_good'] < $threshVals['bhc_threshold_strong'])) {
            header('Location: ' . SITE_URL . '/admin/bhc-settings?flash=error:Thresholds+must+be+ascending&tab=scoring');
            exit;
        }
        foreach ($threshVals as $k => $v) {
            bhcSaveSetting($pdo, $k, (string)$v);
        }
        header('Location: ' . SITE_URL . '/admin/bhc-settings?flash=success:Scoring+settings+saved&tab=scoring');
        exit;
    }

    header('Location: ' . SITE_URL . '/admin/bhc-settings');
    exit;
}

// ── Load current settings ─────────────────────────────────────────────────────
$s = function(string $k, string $d = '') use ($pdo): string {
    return bhcSetting($pdo, $k, $d);
};

// Active tab
$activeTab = in_array($_GET['tab'] ?? '', ['general','email','scoring']) ? $_GET['tab'] : 'general';

// Flash
$flash = $_GET['flash'] ?? '';
$flashType = $flashMsg = '';
if ($flash) {
    [$flashType, $flashMsg] = explode(':', $flash, 2) + ['',''];
    $flashMsg = urldecode($flashMsg);
}

// Load categories for scoring tab
try {
    $categories = $pdo->query(
        "SELECT id, slug, label_en, label_fr, weight FROM bhc_categories ORDER BY sort_order"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $categories = array_values(bhc_load_categories());
}

// Score band thresholds (defaults: 40, 60, 80)
$thrDev    = (int) $s('bhc_threshold_developing', '40');
$thrGood   = (int) $s('bhc_threshold_good',       '60');
$thrStrong = (int) $s('bhc_threshold_strong',     '80');

$pageTitle = 'BHC Settings';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/includes/nav.php';
adminNav('bhc-settings');
adminNavAssets();
?>
<style>
/* ── Layout ────────────────────────────────────────────── */
.bhcs-wrap { max-width:900px; margin:0 auto; padding:1.5rem 1rem 3rem; }
.bhcs-header { display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; margin-bottom:1.5rem; }
.bhcs-header h1 { font-size:1.4rem; font-weight:700; color:#fff; margin:0; }

/* flash */
.flash { padding:.75rem 1rem; border-radius:8px; margin-bottom:1.25rem; font-size:.875rem; font-weight:500; }
.flash.success { background:rgba(56,161,105,.15); border:1px solid rgba(56,161,105,.35); color:#68d391; }
.flash.error   { background:rgba(229,62,62,.12); border:1px solid rgba(229,62,62,.3); color:#fc8181; }

/* tabs */
.bhcs-tabs { display:flex; gap:0; border-bottom:1px solid var(--border); margin-bottom:1.75rem; }
.bhcs-tab { padding:.6rem 1.2rem; font-size:.875rem; font-weight:600; color:var(--muted); text-decoration:none; border-bottom:2px solid transparent; transition:all .15s; white-space:nowrap; }
.bhcs-tab:hover { color:#a0aec0; }
.bhcs-tab.active { color:#00A878; border-bottom-color:#00A878; }

/* form panels */
.settings-panel { background:var(--card); border:1px solid var(--border); border-radius:12px; overflow:hidden; }
.sp-section { padding:1.5rem; border-bottom:1px solid var(--border); }
.sp-section:last-child { border-bottom:none; }
.sp-section h2 { font-size:.95rem; font-weight:700; color:#e2e8f0; margin:0 0 1.1rem; display:flex; align-items:center; gap:.5rem; }
.form-row { display:grid; gap:1rem; margin-bottom:1rem; }
.form-row-2 { grid-template-columns:1fr 1fr; }
.form-row-3 { grid-template-columns:1fr 1fr 1fr; }
@media(max-width:620px) { .form-row-2,.form-row-3 { grid-template-columns:1fr; } }
.form-group label { display:block; font-size:.76rem; color:var(--muted); font-weight:600; text-transform:uppercase; letter-spacing:.04em; margin-bottom:.4rem; }
.form-group small  { display:block; font-size:.74rem; color:#718096; margin-top:.3rem; line-height:1.4; }
.form-group input,
.form-group textarea,
.form-group select { width:100%; background:rgba(0,0,0,.35); border:1px solid rgba(0,168,120,.22); border-radius:7px; padding:.5rem .75rem; color:#e2e8f0; font-size:.875rem; font-family:inherit; box-sizing:border-box; }
.form-group input:focus,
.form-group textarea:focus,
.form-group select:focus { outline:none; border-color:#00A878; }
.form-group textarea { min-height:70px; resize:vertical; }

/* toggle switch */
.toggle-row { display:flex; align-items:center; justify-content:space-between; gap:1rem; padding:.6rem 0; }
.toggle-row:not(:last-child) { border-bottom:1px solid rgba(0,168,120,.07); }
.toggle-label { font-size:.875rem; color:#e2e8f0; }
.toggle-label span { display:block; font-size:.77rem; color:var(--muted); margin-top:.15rem; }
.toggle-switch { position:relative; width:46px; height:26px; flex-shrink:0; }
.toggle-switch input { opacity:0; width:0; height:0; }
.toggle-slider { position:absolute; inset:0; background:#2d3748; border-radius:999px; cursor:pointer; transition:background .2s; }
.toggle-slider:before { content:''; position:absolute; height:20px; width:20px; left:3px; bottom:3px; background:#fff; border-radius:50%; transition:.2s; }
.toggle-switch input:checked + .toggle-slider { background:#00A878; }
.toggle-switch input:checked + .toggle-slider:before { transform:translateX(20px); }

/* scoring */
.weight-row { display:grid; grid-template-columns:1fr 100px 80px; gap:.75rem; align-items:center; padding:.55rem 0; border-bottom:1px solid rgba(0,168,120,.07); }
.weight-row:last-child { border-bottom:none; }
.weight-name { font-size:.875rem; color:#e2e8f0; }
.weight-fr   { font-size:.76rem; color:var(--muted); }
.weight-input { text-align:center; }
.weight-total { display:flex; align-items:center; justify-content:space-between; padding:.75rem .25rem 0; font-size:.875rem; font-weight:600; }
.weight-total .total-num { font-size:1.3rem; font-weight:700; }
.weight-total .ok   { color:#68d391; }
.weight-total .warn { color:#fc8181; }

/* band thresholds */
.band-preview { display:grid; grid-template-columns:repeat(4,1fr); gap:.5rem; margin-top:1rem; }
.band-swatch { border-radius:8px; padding:.6rem .5rem; text-align:center; }
.band-swatch .range { font-size:.78rem; font-weight:700; }
.band-swatch .name  { font-size:.7rem; margin-top:.2rem; opacity:.8; }

/* form actions */
.form-footer { background:rgba(0,168,120,.05); padding:1rem 1.5rem; border-top:1px solid var(--border); display:flex; align-items:center; gap:.75rem; }
.btn-save { background:#00A878; color:#fff; border:none; border-radius:8px; padding:.6rem 1.5rem; font-size:.9rem; font-weight:700; cursor:pointer; transition:background .15s; }
.btn-save:hover { background:#00c48f; }
.btn-cancel-link { color:var(--muted); font-size:.82rem; text-decoration:none; }
.btn-cancel-link:hover { color:#a0aec0; }
</style>

<div class="bhcs-wrap">

  <div class="bhcs-header">
    <h1>&#x2699;&#xFE0F; BHC Settings</h1>
    <a href="<?= SITE_URL ?>/admin/bhc-overview" style="color:var(--muted);font-size:.85rem;text-decoration:none">&#x2190; Overview</a>
  </div>

  <?php if ($flashMsg): ?>
    <div class="flash <?= e($flashType) ?>"><?= e($flashMsg) ?></div>
  <?php endif; ?>

  <!-- Tabs -->
  <div class="bhcs-tabs">
    <a href="?tab=general" class="bhcs-tab <?= $activeTab==='general' ? 'active':'' ?>">&#x1F4DD; General</a>
    <a href="?tab=email"   class="bhcs-tab <?= $activeTab==='email'   ? 'active':'' ?>">&#x2709;&#xFE0F; Email</a>
    <a href="?tab=scoring" class="bhcs-tab <?= $activeTab==='scoring' ? 'active':'' ?>">&#x1F4CA; Scoring</a>
  </div>

  <!-- ── GENERAL TAB ───────────────────────────────────────── -->
  <?php if ($activeTab === 'general'): ?>
  <form method="post" action="<?= SITE_URL ?>/admin/bhc-settings">
    <input type="hidden" name="section" value="general">

    <div class="settings-panel">
      <div class="sp-section">
        <h2>&#x1FA7A; Feature Status</h2>
        <div class="toggle-row">
          <div class="toggle-label">
            Enable Business Health Check
            <span>Makes the public /business-health-check page accessible</span>
          </div>
          <label class="toggle-switch">
            <input type="checkbox" name="bhc_enabled" value="1" <?= $s('bhc_enabled','1')==='1' ? 'checked':'' ?>>
            <span class="toggle-slider"></span>
          </label>
        </div>
        <div class="toggle-row">
          <div class="toggle-label">
            Allow PDF download
            <span>Users can download their BHC results as a PDF report</span>
          </div>
          <label class="toggle-switch">
            <input type="checkbox" name="bhc_allow_pdf" value="1" <?= $s('bhc_allow_pdf','1')==='1' ? 'checked':'' ?>>
            <span class="toggle-slider"></span>
          </label>
        </div>
        <div class="toggle-row">
          <div class="toggle-label">
            Allow retake
            <span>Users can retake the assessment (new token / fresh assessment)</span>
          </div>
          <label class="toggle-switch">
            <input type="checkbox" name="bhc_allow_retake" value="1" <?= $s('bhc_allow_retake','1')==='1' ? 'checked':'' ?>>
            <span class="toggle-slider"></span>
          </label>
        </div>
      </div>

      <div class="sp-section">
        <h2>&#x1F4AC; Titles</h2>
        <div class="form-row form-row-2">
          <div class="form-group">
            <label>Page Title (English)</label>
            <input type="text" name="bhc_title_en" value="<?= e($s('bhc_title_en','Business Health Check')) ?>">
          </div>
          <div class="form-group">
            <label>Page Title (French)</label>
            <input type="text" name="bhc_title_fr" value="<?= e($s('bhc_title_fr','Bilan de santé de l\'entreprise')) ?>">
          </div>
        </div>
        <div class="form-row form-row-2">
          <div class="form-group">
            <label>Intro Text (English)</label>
            <textarea name="bhc_intro_en"><?= e($s('bhc_intro_en')) ?></textarea>
            <small>Shown above the assessment form. Leave blank for default.</small>
          </div>
          <div class="form-group">
            <label>Intro Text (French)</label>
            <textarea name="bhc_intro_fr"><?= e($s('bhc_intro_fr')) ?></textarea>
          </div>
        </div>
      </div>

      <div class="sp-section">
        <h2>&#x1F4CA; Results Page</h2>
        <div class="toggle-row">
          <div class="toggle-label">
            Show score band label
            <span>Display Needs Attention / Developing / etc. on the results page</span>
          </div>
          <label class="toggle-switch">
            <input type="checkbox" name="bhc_results_show_band" value="1"
                   <?= $s('bhc_results_show_band','1')==='1' ? 'checked':'' ?>>
            <span class="toggle-slider"></span>
          </label>
        </div>
        <div class="toggle-row">
          <div class="toggle-label">
            Show category breakdown
            <span>Display per-category scores on the results page</span>
          </div>
          <label class="toggle-switch">
            <input type="checkbox" name="bhc_results_show_category" value="1"
                   <?= $s('bhc_results_show_category','1')==='1' ? 'checked':'' ?>>
            <span class="toggle-slider"></span>
          </label>
        </div>
        <div class="toggle-row">
          <div class="toggle-label">
            Show recommendations
            <span>Show tailored recommendations based on scores and band</span>
          </div>
          <label class="toggle-switch">
            <input type="checkbox" name="bhc_results_show_recommendations" value="1"
                   <?= $s('bhc_results_show_recommendations','1')==='1' ? 'checked':'' ?>>
            <span class="toggle-slider"></span>
          </label>
        </div>
      </div>

      <div class="form-footer">
        <button type="submit" class="btn-save">&#x1F4BE; Save General Settings</button>
        <a href="<?= SITE_URL ?>/business-health-check" target="_blank" class="btn-cancel-link">View public page &#x2197;</a>
      </div>
    </div>
  </form>

  <!-- ── EMAIL TAB ─────────────────────────────────────────── -->
  <?php elseif ($activeTab === 'email'): ?>
  <form method="post" action="<?= SITE_URL ?>/admin/bhc-settings">
    <input type="hidden" name="section" value="email">

    <div class="settings-panel">
      <div class="sp-section">
        <h2>&#x1F4E7; Email Delivery</h2>
        <div class="toggle-row">
          <div class="toggle-label">
            Enable email sending
            <span>When off, no BHC emails are sent regardless of other settings</span>
          </div>
          <label class="toggle-switch">
            <input type="checkbox" name="bhc_email_enabled" value="1"
                   <?= $s('bhc_email_enabled','1')==='1' ? 'checked':'' ?>>
            <span class="toggle-slider"></span>
          </label>
        </div>
        <div class="toggle-row">
          <div class="toggle-label">
            Send report to participant
            <span>Email the full BHC report (PDF + summary) to the contact email provided</span>
          </div>
          <label class="toggle-switch">
            <input type="checkbox" name="bhc_email_send_report" value="1"
                   <?= $s('bhc_email_send_report','1')==='1' ? 'checked':'' ?>>
            <span class="toggle-slider"></span>
          </label>
        </div>
        <div class="toggle-row">
          <div class="toggle-label">
            Notify admin on completion
            <span>Send a brief notification email to the admin address below each time an assessment is completed</span>
          </div>
          <label class="toggle-switch">
            <input type="checkbox" name="bhc_email_notify_admin" value="1"
                   <?= $s('bhc_email_notify_admin','0')==='1' ? 'checked':'' ?>>
            <span class="toggle-slider"></span>
          </label>
        </div>
      </div>

      <div class="sp-section">
        <h2>&#x1F4EC; Sender Details</h2>
        <div class="form-row form-row-2">
          <div class="form-group">
            <label>From Name</label>
            <input type="text" name="bhc_email_from_name" value="<?= e($s('bhc_email_from_name','237biz')) ?>">
          </div>
          <div class="form-group">
            <label>From Email Address</label>
            <input type="email" name="bhc_email_from_address" value="<?= e($s('bhc_email_from_address')) ?>">
            <small>Leave blank to use the system default SMTP address.</small>
          </div>
        </div>
        <div class="form-row form-row-2">
          <div class="form-group">
            <label>Admin Notification Address</label>
            <input type="email" name="bhc_email_admin_address" value="<?= e($s('bhc_email_admin_address')) ?>">
            <small>Where admin notifications are sent. Leave blank for system default.</small>
          </div>
        </div>
      </div>

      <div class="sp-section">
        <h2>&#x1F4DD; Subject Lines</h2>
        <div class="form-row form-row-2">
          <div class="form-group">
            <label>Subject (English)</label>
            <input type="text" name="bhc_email_subject_en"
                   value="<?= e($s('bhc_email_subject_en','Your Business Health Check Results')) ?>">
          </div>
          <div class="form-group">
            <label>Subject (French)</label>
            <input type="text" name="bhc_email_subject_fr"
                   value="<?= e($s('bhc_email_subject_fr','Vos résultats du Bilan de santé')) ?>">
          </div>
        </div>
      </div>

      <div class="form-footer">
        <button type="submit" class="btn-save">&#x1F4BE; Save Email Settings</button>
      </div>
    </div>
  </form>

  <!-- ── SCORING TAB ───────────────────────────────────────── -->
  <?php elseif ($activeTab === 'scoring'): ?>
  <form method="post" action="<?= SITE_URL ?>/admin/bhc-settings">
    <input type="hidden" name="section" value="scoring">

    <div class="settings-panel">
      <div class="sp-section">
        <h2>&#x2696;&#xFE0F; Category Weights <small style="font-weight:400;font-size:.8rem;color:var(--muted)">(must sum to 100)</small></h2>
        <?php
        $totalW = 0;
        foreach ($categories as $cat) $totalW += (int)$cat['weight'];
        ?>
        <?php foreach ($categories as $cat): ?>
        <div class="weight-row">
          <div>
            <div class="weight-name"><?= e($cat['label_en']) ?></div>
            <?php if ($cat['label_fr'] ?? ''): ?>
              <div class="weight-fr"><?= e($cat['label_fr']) ?></div>
            <?php endif; ?>
          </div>
          <div class="weight-input">
            <input type="number" name="weight_<?= (int)$cat['id'] ?>"
                   value="<?= (int)$cat['weight'] ?>"
                   min="0" max="100" style="text-align:center;width:70px"
                   oninput="updateTotal()">
          </div>
          <div style="color:var(--muted);font-size:.8rem">%</div>
        </div>
        <?php endforeach; ?>

        <div class="weight-total">
          <span>Total weight:</span>
          <span id="weight-total" class="total-num <?= $totalW === 100 ? 'ok' : 'warn' ?>">
            <?= $totalW ?>%
          </span>
        </div>
        <small id="weight-hint" style="color:<?= $totalW===100 ? '#68d391':'#fc8181' ?>;font-size:.78rem;display:block;margin-top:.4rem">
          <?= $totalW === 100 ? '✓ Weights sum correctly' : '⚠ Weights must sum to exactly 100' ?>
        </small>
      </div>

      <div class="sp-section">
        <h2>&#x1F3AF; Score Band Thresholds</h2>
        <p style="color:var(--muted);font-size:.82rem;margin:0 0 1rem">
          Define the score boundaries (0–100) for each band. Must be in ascending order.
        </p>
        <div class="form-row form-row-3">
          <div class="form-group">
            <label>Developing starts at</label>
            <input type="number" name="bhc_threshold_developing" min="1" max="98"
                   value="<?= $thrDev ?>">
            <small>Scores 0–<?= $thrDev-1 ?> = Needs Attention</small>
          </div>
          <div class="form-group">
            <label>Good Progress starts at</label>
            <input type="number" name="bhc_threshold_good" min="2" max="99"
                   value="<?= $thrGood ?>">
            <small>Scores <?= $thrDev ?>–<?= $thrGood-1 ?> = Developing</small>
          </div>
          <div class="form-group">
            <label>Strong Foundations starts at</label>
            <input type="number" name="bhc_threshold_strong" min="3" max="100"
                   value="<?= $thrStrong ?>">
            <small>Scores <?= $thrGood ?>–<?= $thrStrong-1 ?> = Good Progress</small>
          </div>
        </div>

        <!-- Visual band preview -->
        <div class="band-preview">
          <?php
          $bands = [
            ['Needs Attention', "#e53e3e", "0 – " . ($thrDev-1)],
            ['Developing',      "#dd6b20", $thrDev . " – " . ($thrGood-1)],
            ['Good Progress',   "#d69e2e", $thrGood . " – " . ($thrStrong-1)],
            ['Strong Foundations', "#38a169", $thrStrong . " – 100"],
          ];
          foreach ($bands as $b):
          ?>
          <div class="band-swatch" style="background:<?= $b[1] ?>20;border:1px solid <?= $b[1] ?>40">
            <div class="range" style="color:<?= $b[1] ?>"><?= $b[2] ?></div>
            <div class="name" style="color:<?= $b[1] ?>"><?= $b[0] ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="form-footer">
        <button type="submit" class="btn-save" id="save-scoring-btn">&#x1F4BE; Save Scoring Settings</button>
        <span id="save-hint" style="font-size:.78rem;color:#fc8181;display:none">⚠ Weights must sum to 100 before saving</span>
        <a href="<?= SITE_URL ?>/admin/bhc-questions" class="btn-cancel-link" style="margin-left:auto">
          Edit Questions &amp; Categories &#x2192;
        </a>
      </div>
    </div>
  </form>
  <?php endif; ?>

</div>

<script>
function updateTotal() {
  var inputs = document.querySelectorAll('input[name^="weight_"]');
  var total = 0;
  inputs.forEach(function(inp) { total += parseInt(inp.value,10)||0; });
  var el = document.getElementById('weight-total');
  var hint = document.getElementById('weight-hint');
  var btn  = document.getElementById('save-scoring-btn');
  var saveHint = document.getElementById('save-hint');
  if (el) {
    el.textContent = total + '%';
    el.className = 'total-num ' + (total===100?'ok':'warn');
  }
  if (hint) {
    hint.textContent = total===100 ? '✓ Weights sum correctly' : '⚠ Weights must sum to exactly 100';
    hint.style.color = total===100 ? '#68d391' : '#fc8181';
  }
  if (saveHint) saveHint.style.display = total!==100 ? 'inline':'none';
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
