<?php
/**
 * Admin — BHC Questions Manager
 * View and manage the 21 Business Health Check assessment questions.
 */
define('IN_237BIZ', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/bhc-config.php';
requireAdmin();

$pdo = db();

// ── Auto-migrate: ensure bhc_categories + bhc_questions tables exist ──────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS bhc_categories (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        slug        VARCHAR(60)  NOT NULL UNIQUE,
        label_en    VARCHAR(120) NOT NULL,
        label_fr    VARCHAR(120) DEFAULT NULL,
        weight      TINYINT UNSIGNED NOT NULL DEFAULT 15,
        sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
        is_active   TINYINT(1)   NOT NULL DEFAULT 1,
        created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_sort (sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS bhc_questions (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        category_id     INT UNSIGNED NOT NULL,
        question_en     VARCHAR(500) NOT NULL,
        question_fr     VARCHAR(500) DEFAULT NULL,
        description_en  TEXT         DEFAULT NULL,
        description_fr  TEXT         DEFAULT NULL,
        sort_order      TINYINT UNSIGNED NOT NULL DEFAULT 0,
        is_active       TINYINT(1)   NOT NULL DEFAULT 1,
        created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_cat_sort (category_id, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Seed categories if empty
    $catCount = (int) $pdo->query("SELECT COUNT(*) FROM bhc_categories")->fetchColumn();
    if ($catCount === 0) {
        $catStmt = $pdo->prepare(
            "INSERT IGNORE INTO bhc_categories (slug, label_en, label_fr, weight, sort_order)
             VALUES (:slug, :label_en, :label_fr, :weight, :sort_order)"
        );
        $defaultCats = bhc_load_categories();
        foreach ($defaultCats as $slug => $cat) {
            $catStmt->execute([
                ':slug'       => $slug,
                ':label_en'   => $cat['label_en'],
                ':label_fr'   => $cat['label_fr'],
                ':weight'     => $cat['weight'],
                ':sort_order' => $cat['sort_order'],
            ]);
        }
    }

    // Seed questions if empty
    $qCount = (int) $pdo->query("SELECT COUNT(*) FROM bhc_questions")->fetchColumn();
    if ($qCount === 0) {
        $allQuestions = bhc_default_questions();
        $qStmt = $pdo->prepare(
            "INSERT IGNORE INTO bhc_questions
                (category_id, question_en, question_fr, description_en, description_fr, sort_order)
             VALUES (:cat_id, :q_en, :q_fr, :d_en, :d_fr, :sort_order)"
        );
        foreach ($allQuestions as $slug => $questions) {
            $catRow = $pdo->prepare("SELECT id FROM bhc_categories WHERE slug = ?");
            $catRow->execute([$slug]);
            $catId = (int) $catRow->fetchColumn();
            if (!$catId) continue;
            foreach ($questions as $q) {
                $qStmt->execute([
                    ':cat_id'     => $catId,
                    ':q_en'       => $q['question_en'],
                    ':q_fr'       => $q['question_fr'] ?? null,
                    ':d_en'       => $q['description_en'] ?? null,
                    ':d_fr'       => $q['description_fr'] ?? null,
                    ':sort_order' => $q['sort_order'],
                ]);
            }
        }
    }
} catch (Exception $e) {
    // Tables may already exist — carry on
}

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_question') {
        $qid = (int)($_POST['qid'] ?? 0);
        if ($qid) {
            $pdo->prepare("UPDATE bhc_questions SET is_active = 1 - is_active WHERE id = ?")->execute([$qid]);
        }
        header('Location: ' . SITE_URL . '/admin/bhc-questions?flash=success:Question+updated');
        exit;
    }

    if ($action === 'save_question') {
        $qid    = (int)($_POST['qid'] ?? 0);
        $q_en   = trim($_POST['question_en'] ?? '');
        $q_fr   = trim($_POST['question_fr'] ?? '');
        $d_en   = trim($_POST['description_en'] ?? '');
        $d_fr   = trim($_POST['description_fr'] ?? '');
        $sort   = (int)($_POST['sort_order'] ?? 0);
        $cat_id = (int)($_POST['category_id'] ?? 0);

        if (!$q_en || !$cat_id) {
            header('Location: ' . SITE_URL . '/admin/bhc-questions?flash=error:English+question+and+category+required');
            exit;
        }

        if ($qid) {
            $pdo->prepare(
                "UPDATE bhc_questions
                 SET category_id=?, question_en=?, question_fr=?, description_en=?, description_fr=?, sort_order=?
                 WHERE id=?"
            )->execute([$cat_id, $q_en, $q_fr ?: null, $d_en ?: null, $d_fr ?: null, $sort, $qid]);
            header('Location: ' . SITE_URL . '/admin/bhc-questions?flash=success:Question+saved');
        } else {
            $pdo->prepare(
                "INSERT INTO bhc_questions (category_id, question_en, question_fr, description_en, description_fr, sort_order)
                 VALUES (?,?,?,?,?,?)"
            )->execute([$cat_id, $q_en, $q_fr ?: null, $d_en ?: null, $d_fr ?: null, $sort]);
            header('Location: ' . SITE_URL . '/admin/bhc-questions?flash=success:Question+added');
        }
        exit;
    }

    if ($action === 'save_category') {
        $cid      = (int)($_POST['cid'] ?? 0);
        $label_en = trim($_POST['label_en'] ?? '');
        $label_fr = trim($_POST['label_fr'] ?? '');
        $weight   = max(1, min(100, (int)($_POST['weight'] ?? 10)));
        $sort     = (int)($_POST['sort_order'] ?? 0);

        if (!$label_en) {
            header('Location: ' . SITE_URL . '/admin/bhc-questions?flash=error:Category+label+required');
            exit;
        }
        if ($cid) {
            $pdo->prepare(
                "UPDATE bhc_categories SET label_en=?, label_fr=?, weight=?, sort_order=? WHERE id=?"
            )->execute([$label_en, $label_fr ?: null, $weight, $sort, $cid]);
        }
        header('Location: ' . SITE_URL . '/admin/bhc-questions?flash=success:Category+saved');
        exit;
    }

    header('Location: ' . SITE_URL . '/admin/bhc-questions');
    exit;
}

// ── Determine edit mode ───────────────────────────────────────────────────────
$editQid  = isset($_GET['edit_q'])   ? (int)$_GET['edit_q']   : 0;
$editCid  = isset($_GET['edit_cat']) ? (int)$_GET['edit_cat'] : 0;
$addNew   = isset($_GET['add']);

// Flash message
$flash = $_GET['flash'] ?? '';
$flashType = $flashMsg = '';
if ($flash) {
    [$flashType, $flashMsg] = explode(':', $flash, 2) + ['', ''];
    $flashMsg = urldecode($flashMsg);
}

// ── Load data ─────────────────────────────────────────────────────────────────
try {
    $categories = $pdo->query(
        "SELECT * FROM bhc_categories ORDER BY sort_order, id"
    )->fetchAll(PDO::FETCH_ASSOC);

    $questions = $pdo->query(
        "SELECT q.*, c.slug AS cat_slug, c.label_en AS cat_label, c.sort_order AS cat_sort
         FROM bhc_questions q
         JOIN bhc_categories c ON c.id = q.category_id
         ORDER BY c.sort_order, q.sort_order, q.id"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fallback: use hardcoded config data
    $categories = array_values(bhc_load_categories());
    $questions  = [];
    foreach (bhc_default_questions() as $slug => $qs) {
        foreach ($qs as $q) {
            $questions[] = array_merge($q, ['cat_slug' => $slug, 'cat_label' => $slug, 'cat_sort' => 0, 'is_active' => 1]);
        }
    }
}

// Group questions by category
$byCategory = [];
foreach ($questions as $q) {
    $byCategory[$q['cat_slug']][] = $q;
}

// Find edit targets
$editQ = $editQid ? (function() use ($questions, $editQid) {
    foreach ($questions as $q) { if ((int)$q['id'] === $editQid) return $q; } return null;
})() : null;

$editCat = $editCid ? (function() use ($categories, $editCid) {
    foreach ($categories as $c) { if ((int)$c['id'] === $editCid) return $c; } return null;
})() : null;

// Summary stats
$totalQ    = count($questions);
$activeQ   = count(array_filter($questions, fn($q) => (int)($q['is_active'] ?? 1)));
$totalCats = count($categories);
$totalWeight = array_sum(array_column($categories, 'weight'));

$pageTitle = 'BHC Questions';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/includes/nav.php';
adminNav('bhc-questions');
adminNavAssets();
?>
<style>
/* ── Page layout ───────────────────────────────────────── */
.bhcq-wrap { max-width:1200px; margin:0 auto; padding:1.5rem 1rem 3rem; }
.bhcq-header { display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; margin-bottom:1.5rem; }
.bhcq-header h1 { font-size:1.4rem; font-weight:700; color:#fff; margin:0; }
.bhcq-header-actions { display:flex; gap:.5rem; flex-wrap:wrap; }

/* stat cards */
.bhcq-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:.75rem; margin-bottom:1.75rem; }
.bhcq-stat { background:var(--card); border:1px solid var(--border); border-radius:10px; padding:.9rem 1rem; }
.bhcq-stat .val { font-size:1.6rem; font-weight:700; color:#00A878; line-height:1; }
.bhcq-stat .lbl { font-size:.78rem; color:var(--muted); margin-top:.2rem; }
.bhcq-stat.warn .val { color:#dd6b20; }

/* flash */
.flash { padding:.75rem 1rem; border-radius:8px; margin-bottom:1.25rem; font-size:.875rem; font-weight:500; }
.flash.success { background:rgba(56,161,105,.15); border:1px solid rgba(56,161,105,.35); color:#68d391; }
.flash.error   { background:rgba(229,62,62,.12); border:1px solid rgba(229,62,62,.3); color:#fc8181; }

/* category accordion */
.cat-section { background:var(--card); border:1px solid var(--border); border-radius:12px; margin-bottom:1rem; overflow:hidden; }
.cat-header { display:flex; align-items:center; gap:.75rem; padding:1rem 1.25rem; cursor:pointer; user-select:none; }
.cat-header:hover { background:rgba(0,168,120,.06); }
.cat-title { font-size:1rem; font-weight:600; color:#fff; flex:1; }
.cat-meta { font-size:.78rem; color:var(--muted); display:flex; gap:.75rem; align-items:center; flex-wrap:wrap; }
.weight-badge { background:rgba(0,168,120,.15); color:#00A878; border-radius:999px; padding:.15rem .55rem; font-size:.74rem; font-weight:700; }
.cat-toggle { font-size:.8rem; color:var(--muted); transition:transform .2s; }
.cat-section.open .cat-toggle { transform:rotate(180deg); }
.cat-body { display:none; border-top:1px solid var(--border); }
.cat-section.open .cat-body { display:block; }

/* question table */
.q-table { width:100%; border-collapse:collapse; font-size:.85rem; }
.q-table th { background:rgba(0,168,120,.07); color:var(--muted); font-size:.74rem; font-weight:600; text-transform:uppercase; letter-spacing:.04em; padding:.5rem 1rem; text-align:left; }
.q-table td { padding:.7rem 1rem; border-top:1px solid rgba(0,168,120,.08); vertical-align:top; color:var(--text); }
.q-table tr:hover td { background:rgba(0,168,120,.04); }
.q-num { width:36px; color:var(--muted); font-size:.8rem; }
.q-en { color:#e2e8f0; line-height:1.45; max-width:420px; }
.q-fr { color:var(--muted); font-size:.8rem; line-height:1.4; max-width:360px; }
.q-desc { color:var(--muted); font-size:.78rem; font-style:italic; margin-top:.3rem; line-height:1.4; }
.q-status { width:70px; }
.status-pill { display:inline-block; border-radius:999px; padding:.18rem .6rem; font-size:.72rem; font-weight:600; }
.status-pill.active   { background:rgba(56,161,105,.18); color:#68d391; }
.status-pill.inactive { background:rgba(113,128,150,.15); color:#718096; }
.q-actions { width:90px; white-space:nowrap; }
.btn-sm { display:inline-flex; align-items:center; gap:.3rem; padding:.3rem .7rem; border-radius:6px; font-size:.78rem; font-weight:600; cursor:pointer; border:1px solid transparent; text-decoration:none; transition:all .15s; }
.btn-edit   { background:rgba(0,168,120,.12); color:#00A878; border-color:rgba(0,168,120,.25); }
.btn-edit:hover { background:rgba(0,168,120,.22); }
.btn-toggle { background:rgba(113,128,150,.12); color:#a0aec0; border-color:rgba(113,128,150,.2); }
.btn-toggle:hover { background:rgba(113,128,150,.22); }
.btn-toggle.deactivate { background:rgba(229,62,62,.1); color:#fc8181; border-color:rgba(229,62,62,.2); }
.btn-toggle.deactivate:hover { background:rgba(229,62,62,.2); }

/* cat edit row */
.cat-edit-row { background:rgba(0,168,120,.05); padding:.75rem 1.25rem; border-top:1px solid rgba(0,168,120,.12); display:flex; align-items:center; gap:1rem; flex-wrap:wrap; }
.cat-edit-row .label { font-size:.78rem; color:var(--muted); font-weight:600; text-transform:uppercase; letter-spacing:.04em; }

/* edit panel */
.edit-panel { background:rgba(0,10,6,.4); border:1px solid rgba(0,168,120,.25); border-radius:12px; padding:1.5rem; margin-bottom:1.5rem; }
.edit-panel h2 { font-size:1.05rem; font-weight:700; color:#fff; margin:0 0 1.25rem; }
.form-row { display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1rem; }
.form-row.single { grid-template-columns:1fr; }
.form-row.triple { grid-template-columns:1fr 1fr 80px; }
@media(max-width:600px) { .form-row,.form-row.triple { grid-template-columns:1fr; } }
.form-group label { display:block; font-size:.78rem; color:var(--muted); font-weight:600; text-transform:uppercase; letter-spacing:.04em; margin-bottom:.4rem; }
.form-group input,
.form-group textarea,
.form-group select { width:100%; background:rgba(0,0,0,.35); border:1px solid rgba(0,168,120,.25); border-radius:7px; padding:.55rem .75rem; color:#e2e8f0; font-size:.875rem; font-family:inherit; box-sizing:border-box; resize:vertical; }
.form-group input:focus,
.form-group textarea:focus,
.form-group select:focus { outline:none; border-color:#00A878; }
.form-group textarea { min-height:70px; }
.form-actions { display:flex; gap:.5rem; margin-top:1.25rem; }
.btn-primary { background:#00A878; color:#fff; border:none; border-radius:7px; padding:.55rem 1.25rem; font-size:.875rem; font-weight:600; cursor:pointer; transition:background .15s; }
.btn-primary:hover { background:#00c48f; }
.btn-cancel  { background:rgba(255,255,255,.07); color:#a0aec0; border:1px solid rgba(255,255,255,.12); border-radius:7px; padding:.55rem 1.25rem; font-size:.875rem; cursor:pointer; text-decoration:none; transition:background .15s; }
.btn-cancel:hover { background:rgba(255,255,255,.13); }
.btn-add { background:rgba(0,168,120,.12); color:#00A878; border:1px solid rgba(0,168,120,.3); border-radius:7px; padding:.5rem 1.1rem; font-size:.82rem; font-weight:600; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:.35rem; transition:all .15s; }
.btn-add:hover { background:rgba(0,168,120,.22); }
</style>

<div class="bhcq-wrap">

  <?php if ($flashMsg): ?>
    <div class="flash <?= e($flashType) ?>"><?= e($flashMsg) ?></div>
  <?php endif; ?>

  <div class="bhcq-header">
    <h1>&#x2753; BHC Questions</h1>
    <div class="bhcq-header-actions">
      <a href="<?= SITE_URL ?>/admin/bhc-questions?add" class="btn-add">&#x2B; Add Question</a>
      <a href="<?= SITE_URL ?>/admin/bhc-overview" class="btn-cancel">&#x2190; Overview</a>
    </div>
  </div>

  <!-- Stats -->
  <div class="bhcq-stats">
    <div class="bhcq-stat">
      <div class="val"><?= $totalQ ?></div>
      <div class="lbl">Total Questions</div>
    </div>
    <div class="bhcq-stat">
      <div class="val"><?= $activeQ ?></div>
      <div class="lbl">Active</div>
    </div>
    <div class="bhcq-stat">
      <div class="val"><?= $totalCats ?></div>
      <div class="lbl">Categories</div>
    </div>
    <div class="bhcq-stat <?= $totalWeight !== 100 ? 'warn' : '' ?>">
      <div class="val"><?= $totalWeight ?>%</div>
      <div class="lbl">Total Weight<?= $totalWeight !== 100 ? ' ⚠' : ' ✓' ?></div>
    </div>
  </div>

  <!-- Edit / Add Question panel -->
  <?php if ($editQ || $addNew): ?>
  <div class="edit-panel">
    <h2><?= $editQ ? 'Edit Question #' . (int)$editQ['id'] : 'Add New Question' ?></h2>
    <form method="post" action="<?= SITE_URL ?>/admin/bhc-questions">
      <input type="hidden" name="action" value="save_question">
      <?php if ($editQ): ?>
        <input type="hidden" name="qid" value="<?= (int)$editQ['id'] ?>">
      <?php endif; ?>

      <div class="form-row triple">
        <div class="form-group">
          <label>Category *</label>
          <select name="category_id" required>
            <?php foreach ($categories as $cat): ?>
              <option value="<?= (int)$cat['id'] ?>"
                <?= (int)($editQ['category_id'] ?? 0) === (int)$cat['id'] ? 'selected' : '' ?>>
                <?= e($cat['label_en']) ?> (<?= (int)$cat['weight'] ?>%)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Sort Order</label>
          <input type="number" name="sort_order" min="0" max="99"
                 value="<?= (int)($editQ['sort_order'] ?? 0) ?>">
        </div>
      </div>

      <div class="form-row single">
        <div class="form-group">
          <label>Question (English) *</label>
          <textarea name="question_en" required><?= e($editQ['question_en'] ?? '') ?></textarea>
        </div>
      </div>
      <div class="form-row single">
        <div class="form-group">
          <label>Question (French)</label>
          <textarea name="question_fr"><?= e($editQ['question_fr'] ?? '') ?></textarea>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Description / Hint (English)</label>
          <textarea name="description_en"><?= e($editQ['description_en'] ?? '') ?></textarea>
        </div>
        <div class="form-group">
          <label>Description / Hint (French)</label>
          <textarea name="description_fr"><?= e($editQ['description_fr'] ?? '') ?></textarea>
        </div>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn-primary">&#x1F4BE; Save Question</button>
        <a href="<?= SITE_URL ?>/admin/bhc-questions" class="btn-cancel">Cancel</a>
      </div>
    </form>
  </div>
  <?php endif; ?>

  <!-- Edit Category panel -->
  <?php if ($editCat): ?>
  <div class="edit-panel">
    <h2>Edit Category — <?= e($editCat['label_en']) ?></h2>
    <form method="post" action="<?= SITE_URL ?>/admin/bhc-questions">
      <input type="hidden" name="action" value="save_category">
      <input type="hidden" name="cid" value="<?= (int)$editCat['id'] ?>">

      <div class="form-row triple">
        <div class="form-group">
          <label>Label (English) *</label>
          <input type="text" name="label_en" value="<?= e($editCat['label_en']) ?>" required>
        </div>
        <div class="form-group">
          <label>Label (French)</label>
          <input type="text" name="label_fr" value="<?= e($editCat['label_fr'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Weight %</label>
          <input type="number" name="weight" min="1" max="100" value="<?= (int)$editCat['weight'] ?>">
        </div>
      </div>
      <div class="form-row" style="grid-template-columns:120px 1fr">
        <div class="form-group">
          <label>Sort Order</label>
          <input type="number" name="sort_order" min="0" max="99" value="<?= (int)$editCat['sort_order'] ?>">
        </div>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn-primary">&#x1F4BE; Save Category</button>
        <a href="<?= SITE_URL ?>/admin/bhc-questions" class="btn-cancel">Cancel</a>
      </div>
    </form>
  </div>
  <?php endif; ?>

  <!-- Category accordions -->
  <?php foreach ($categories as $cat):
    $slug   = $cat['slug'];
    $catQs  = $byCategory[$slug] ?? [];
    $catId  = (int)$cat['id'];
    $isOpen = ($editQid && $editQ && $editQ['cat_slug'] === $slug) ? ' open' : '';
  ?>
  <div class="cat-section<?= $isOpen ?>" id="cat-<?= e($slug) ?>">
    <div class="cat-header" onclick="toggleCat(this)">
      <div class="cat-title"><?= e($cat['label_en']) ?></div>
      <div class="cat-meta">
        <span class="weight-badge"><?= (int)$cat['weight'] ?>%</span>
        <span><?= count($catQs) ?> question<?= count($catQs) !== 1 ? 's' : '' ?></span>
        <?php if ($cat['label_fr']): ?>
          <span style="color:#718096;font-style:italic"><?= e($cat['label_fr']) ?></span>
        <?php endif; ?>
      </div>
      <a href="<?= SITE_URL ?>/admin/bhc-questions?edit_cat=<?= $catId ?>" class="btn-sm btn-edit"
         onclick="event.stopPropagation()" title="Edit category">&#x270F;</a>
      <span class="cat-toggle">&#9662;</span>
    </div>

    <div class="cat-body">
      <?php if ($catQs): ?>
      <table class="q-table">
        <thead>
          <tr>
            <th class="q-num">#</th>
            <th>Question (EN)</th>
            <th style="width:260px">Question (FR)</th>
            <th class="q-status">Status</th>
            <th class="q-actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($catQs as $i => $q): ?>
          <tr>
            <td class="q-num"><?= (int)$q['sort_order'] ?: ($i + 1) ?></td>
            <td class="q-en">
              <?= e($q['question_en']) ?>
              <?php if ($q['description_en'] ?? ''): ?>
                <div class="q-desc">&#x1F4A1; <?= e($q['description_en']) ?></div>
              <?php endif; ?>
            </td>
            <td class="q-fr">
              <?= e($q['question_fr'] ?? '—') ?>
              <?php if ($q['description_fr'] ?? ''): ?>
                <div class="q-desc"><?= e($q['description_fr']) ?></div>
              <?php endif; ?>
            </td>
            <td class="q-status">
              <span class="status-pill <?= (int)($q['is_active'] ?? 1) ? 'active' : 'inactive' ?>">
                <?= (int)($q['is_active'] ?? 1) ? 'Active' : 'Off' ?>
              </span>
            </td>
            <td class="q-actions">
              <a href="<?= SITE_URL ?>/admin/bhc-questions?edit_q=<?= (int)$q['id'] ?>#cat-<?= e($slug) ?>"
                 class="btn-sm btn-edit">Edit</a>
              <?php if (isset($q['id'])): ?>
              <form method="post" action="<?= SITE_URL ?>/admin/bhc-questions" style="display:inline">
                <input type="hidden" name="action" value="toggle_question">
                <input type="hidden" name="qid" value="<?= (int)$q['id'] ?>">
                <button type="submit" class="btn-sm btn-toggle <?= (int)($q['is_active'] ?? 1) ? 'deactivate' : '' ?>">
                  <?= (int)($q['is_active'] ?? 1) ? 'Disable' : 'Enable' ?>
                </button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
        <div style="padding:1.25rem;color:var(--muted);font-size:.875rem;text-align:center;">
          No questions yet — <a href="<?= SITE_URL ?>/admin/bhc-questions?add" style="color:#00A878">add one</a>.
        </div>
      <?php endif; ?>

      <div class="cat-edit-row">
        <span class="label">Slug:</span>
        <code style="color:#00A878;font-size:.8rem"><?= e($slug) ?></code>
        <span class="label" style="margin-left:1rem">ID:</span>
        <code style="color:#a0aec0;font-size:.8rem"><?= $catId ?></code>
        <a href="<?= SITE_URL ?>/admin/bhc-questions?add&cat=<?= $catId ?>" class="btn-add" style="margin-left:auto">
          &#x2B; Add Question Here
        </a>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

</div>

<script>
function toggleCat(header) {
  var section = header.closest('.cat-section');
  section.classList.toggle('open');
}
// Auto-open category of an edit target
document.addEventListener('DOMContentLoaded', function() {
  var hash = window.location.hash;
  if (hash) {
    var el = document.querySelector(hash);
    if (el) { el.classList.add('open'); el.scrollIntoView({block:'start',behavior:'smooth'}); }
  }
  // Auto-open first cat if adding new with ?cat= param
  var params = new URLSearchParams(window.location.search);
  if (params.has('add')) {
    var catId = params.get('cat');
    if (catId) {
      document.querySelectorAll('.cat-section').forEach(function(s) {
        var addLink = s.querySelector('.btn-add');
        if (addLink && addLink.href.includes('cat='+catId)) s.classList.add('open');
      });
    } else {
      var first = document.querySelector('.cat-section');
      if (first) first.classList.add('open');
    }
  }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
