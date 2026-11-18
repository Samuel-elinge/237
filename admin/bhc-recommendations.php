<?php
/**
 * admin/bhc-recommendations.php — CRUD editor for bhc_recommendations table
 * Clean URL: /admin/bhc-recommendations
 * Also creates the table if it doesn't exist (migration guard).
 */
define('IN_237BIZ', true);
require_once __DIR__ . '/../includes/config.php';
requireAdmin();
require_once __DIR__ . '/../includes/bhc-config.php';
$pdo = db();

// ── Ensure table exists (idempotent migration guard) ─────────────────────────
$pdo->exec("
CREATE TABLE IF NOT EXISTS bhc_recommendations (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL COMMENT 'FK → bhc_categories.id',
    score_band  ENUM('needs_attention','developing','good_progress','strong_foundations') NOT NULL,
    priority    TINYINT UNSIGNED NOT NULL DEFAULT 5,
    title_en    VARCHAR(200) NOT NULL,
    body_en     TEXT NOT NULL,
    title_fr    VARCHAR(200) DEFAULT NULL,
    body_fr     TEXT DEFAULT NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_band_cat (score_band, category_id),
    INDEX idx_priority (priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Also ensure bhc_categories exists for FK lookups (non-fatal if not)
$catRows = [];
try {
    $catRows = $pdo->query("SELECT id, slug, label_en FROM bhc_categories ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // bhc_categories not yet created — work with config-based categories
    foreach (bhc_load_categories() as $slug => $cat) {
        $catRows[] = ['id' => null, 'slug' => $slug, 'label_en' => $cat['label_en'] ?? ucwords(str_replace('_',' ',$slug))];
    }
}

// ── CSRF token ───────────────────────────────────────────────────────────────
if (empty($_SESSION['bhc_rec_csrf'])) {
    $_SESSION['bhc_rec_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['bhc_rec_csrf'];

$flash = '';

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['_csrf'] ?? '') !== $csrf) {
        $flash = 'error:CSRF mismatch. Please try again.';
    } else {
        $action = $_POST['_action'] ?? '';

        if ($action === 'save') {
            // Create or update
            $id        = (int)($_POST['id'] ?? 0);
            $catId     = (int)($_POST['category_id'] ?? 0);
            $band      = $_POST['score_band'] ?? '';
            $priority  = max(1, min(99, (int)($_POST['priority'] ?? 5)));
            $titleEn   = trim($_POST['title_en'] ?? '');
            $bodyEn    = trim($_POST['body_en'] ?? '');
            $titleFr   = trim($_POST['title_fr'] ?? '') ?: null;
            $bodyFr    = trim($_POST['body_fr'] ?? '') ?: null;
            $isActive  = isset($_POST['is_active']) ? 1 : 0;

            $validBands = ['needs_attention','developing','good_progress','strong_foundations'];
            if (!$titleEn || !$bodyEn || !in_array($band, $validBands)) {
                $flash = 'error:Title (EN), Body (EN), and Score Band are required.';
            } else {
                try {
                    if ($id) {
                        $st = $pdo->prepare("UPDATE bhc_recommendations SET category_id=?,score_band=?,priority=?,title_en=?,body_en=?,title_fr=?,body_fr=?,is_active=? WHERE id=?");
                        $st->execute([$catId, $band, $priority, $titleEn, $bodyEn, $titleFr, $bodyFr, $isActive, $id]);
                        $flash = 'success:Recommendation updated.';
                    } else {
                        $st = $pdo->prepare("INSERT INTO bhc_recommendations (category_id,score_band,priority,title_en,body_en,title_fr,body_fr,is_active) VALUES (?,?,?,?,?,?,?,?)");
                        $st->execute([$catId, $band, $priority, $titleEn, $bodyEn, $titleFr, $bodyFr, $isActive]);
                        $flash = 'success:Recommendation created.';
                    }
                } catch (Exception $e) {
                    $flash = 'error:Database error: ' . htmlspecialchars($e->getMessage());
                }
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                $pdo->prepare("DELETE FROM bhc_recommendations WHERE id=?")->execute([$id]);
                $flash = 'success:Recommendation deleted.';
            }
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                $pdo->prepare("UPDATE bhc_recommendations SET is_active = 1 - is_active WHERE id=?")->execute([$id]);
                $flash = 'success:Status toggled.';
            }
        }
    }
    // PRG pattern
    header('Location: ' . SITE_URL . '/admin/bhc-recommendations' . ($flash ? '?flash=' . urlencode($flash) : ''));
    exit;
}

// Flash from redirect
if (empty($flash) && !empty($_GET['flash'])) {
    $flash = $_GET['flash'];
}

// ── Filters ───────────────────────────────────────────────────────────────────
$filterBand = $_GET['band'] ?? '';
$filterCat  = (int)($_GET['cat'] ?? 0);
$editId     = (int)($_GET['edit'] ?? 0);

$where  = [];
$params = [];
if ($filterBand) { $where[] = 'score_band = ?'; $params[] = $filterBand; }
if ($filterCat)  { $where[] = 'category_id = ?'; $params[] = $filterCat; }
$wc = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$recs = [];
try {
    $st = $pdo->prepare("SELECT r.*, c.slug as cat_slug, c.label_en as cat_label FROM bhc_recommendations r LEFT JOIN bhc_categories c ON c.id = r.category_id {$wc} ORDER BY r.score_band, r.priority, r.id");
    $st->execute($params);
    $recs = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // bhc_categories join might fail — fall back
    try {
        $st = $pdo->prepare("SELECT * FROM bhc_recommendations {$wc} ORDER BY score_band, priority, id");
        $st->execute($params);
        $recs = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e2) {}
}

$editRec = null;
if ($editId) {
    foreach ($recs as $r) { if ((int)$r['id'] === $editId) { $editRec = $r; break; } }
    if (!$editRec) {
        try {
            $st = $pdo->prepare("SELECT * FROM bhc_recommendations WHERE id=?");
            $st->execute([$editId]);
            $editRec = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $e) {}
    }
}

// Band stats
$bandCounts = [];
try {
    $st = $pdo->query("SELECT score_band, COUNT(*) AS n, SUM(is_active) AS active FROM bhc_recommendations GROUP BY score_band");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $bandCounts[$row['score_band']] = $row;
    }
} catch (Exception $e) {}

$allBands = [
    'needs_attention'    => 'Needs Attention',
    'developing'         => 'Developing',
    'good_progress'      => 'Good Progress',
    'strong_foundations' => 'Strong Foundations',
];

$pageTitle = 'BHC Recommendations — Admin — 237Biz';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/includes/nav.php';
adminNav('bhc-recommendations'); adminNavAssets();
?>

<style>
.stat-card { background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:16px 20px; }
.stat-card .val { font-size:1.6rem;font-weight:900;font-family:'Fraunces',serif;line-height:1; }
.stat-card .lbl { font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px; }
.band-pill { display:inline-block;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700; }
.bhc-table { width:100%;border-collapse:collapse;font-size:13px; }
.bhc-table th { text-align:left;padding:8px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);border-bottom:1px solid rgba(255,255,255,0.08); }
.bhc-table td { padding:10px 12px;border-bottom:1px solid rgba(255,255,255,0.05);vertical-align:top; }
.bhc-table tr:last-child td { border-bottom:none; }
.bhc-table tr:hover td { background:rgba(255,255,255,0.02); }
.form-label { display:block;font-size:12px;color:var(--muted);margin-bottom:5px;text-transform:uppercase;letter-spacing:.04em; }
.form-input { width:100%;padding:9px 12px;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);border-radius:8px;color:var(--text);font-size:13px;box-sizing:border-box; }
.form-input:focus { outline:none;border-color:rgba(0,168,120,0.5);background:rgba(255,255,255,0.08); }
textarea.form-input { min-height:90px;resize:vertical; }
.btn-primary { padding:9px 20px;background:#00A878;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer; }
.btn-secondary { padding:7px 14px;background:rgba(255,255,255,0.06);color:var(--muted);border:1px solid rgba(255,255,255,0.1);border-radius:7px;font-size:12.5px;font-weight:600;cursor:pointer;text-decoration:none; }
.flash-ok  { background:rgba(0,168,120,0.1);border:1px solid rgba(0,168,120,0.3);color:#00A878;padding:10px 16px;border-radius:8px;margin-bottom:1rem;font-size:13px; }
.flash-err { background:rgba(229,62,62,0.1);border:1px solid rgba(229,62,62,0.3);color:#e53e3e;padding:10px 16px;border-radius:8px;margin-bottom:1rem;font-size:13px; }
</style>

<div class="page-header">
  <div class="container">
    <nav class="breadcrumb"><a href="<?= SITE_URL ?>/admin/">Admin</a> › <a href="<?= SITE_URL ?>/admin/bhc-overview">BHC</a> › <span>Recommendations</span></nav>
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-top:4px;">
      <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.5rem,3vw,2rem);margin:0;">&#x1F4A1; BHC Recommendations</h1>
      <a href="?edit=new" style="padding:9px 18px;background:#00A878;color:#fff;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none;">+ Add Recommendation</a>
    </div>
  </div>
</div>

<section class="page-section" style="padding-top:1rem;">
<div class="container">

<?php
[$ftype, $fmsg] = $flash ? explode(':', $flash, 2) : ['',''];
if ($ftype === 'success'): ?>
<div class="flash-ok">&#x2713; <?= htmlspecialchars($fmsg) ?></div>
<?php elseif ($ftype === 'error'): ?>
<div class="flash-err">&#x26A0; <?= htmlspecialchars($fmsg) ?></div>
<?php endif; ?>

  <!-- Band overview cards -->
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:12px;margin-bottom:1.5rem;">
    <?php foreach ($allBands as $slug => $label):
      $col  = bhc_band_color($slug);
      $cnt  = $bandCounts[$slug] ?? [];
      $n    = (int)($cnt['n'] ?? 0);
      $act  = (int)($cnt['active'] ?? 0);
    ?>
    <div class="stat-card" style="border-color:<?= $col ?>33;">
      <div class="lbl" style="color:<?= $col ?>;"><?= $label ?></div>
      <div class="val" style="color:<?= $col ?>;"><?= $n ?></div>
      <div style="font-size:11px;color:var(--muted);margin-top:4px;"><?= $act ?> active</div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Edit / Add form -->
  <?php if ($editId === 0 && isset($_GET['edit']) && $_GET['edit'] === 'new' || $editRec): ?>
  <div style="background:rgba(255,255,255,0.03);border:1px solid rgba(0,168,120,0.3);border-radius:12px;padding:20px;margin-bottom:1.5rem;">
    <h2 style="font-size:1rem;font-weight:700;margin:0 0 1.25rem;"><?= $editRec ? 'Edit Recommendation #' . (int)$editRec['id'] : 'New Recommendation' ?></h2>
    <form method="POST" action="">
      <input type="hidden" name="_csrf" value="<?= $csrf ?>">
      <input type="hidden" name="_action" value="save">
      <?php if ($editRec): ?><input type="hidden" name="id" value="<?= (int)$editRec['id'] ?>"><?php endif; ?>

      <div style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:12px;margin-bottom:12px;align-items:end;flex-wrap:wrap;">
        <div>
          <label class="form-label">Category</label>
          <select name="category_id" class="form-input">
            <option value="0">— Unassigned —</option>
            <?php foreach ($catRows as $cr): ?>
            <option value="<?= (int)($cr['id'] ?? 0) ?>" <?= (int)($editRec['category_id'] ?? 0) === (int)($cr['id'] ?? 0) ? 'selected' : '' ?>><?= htmlspecialchars($cr['label_en'] ?? $cr['slug']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label">Score Band *</label>
          <select name="score_band" class="form-input" required>
            <option value="">— Select —</option>
            <?php foreach ($allBands as $slug => $label): ?>
            <option value="<?= $slug ?>" <?= ($editRec['score_band'] ?? '') === $slug ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label">Priority (1=highest)</label>
          <input type="number" name="priority" class="form-input" min="1" max="99" value="<?= (int)($editRec['priority'] ?? 5) ?>">
        </div>
        <div>
          <label class="form-label">Active</label>
          <div style="display:flex;align-items:center;gap:8px;padding-top:8px;">
            <input type="checkbox" name="is_active" id="is_active" value="1" <?= ($editRec['is_active'] ?? 1) ? 'checked' : '' ?> style="width:16px;height:16px;">
            <label for="is_active" style="font-size:13px;">Active</label>
          </div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
        <div>
          <label class="form-label">Title (English) *</label>
          <input type="text" name="title_en" class="form-input" required value="<?= htmlspecialchars($editRec['title_en'] ?? '') ?>">
        </div>
        <div>
          <label class="form-label">Title (French)</label>
          <input type="text" name="title_fr" class="form-input" value="<?= htmlspecialchars($editRec['title_fr'] ?? '') ?>">
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
        <div>
          <label class="form-label">Body (English) *</label>
          <textarea name="body_en" class="form-input" required><?= htmlspecialchars($editRec['body_en'] ?? '') ?></textarea>
        </div>
        <div>
          <label class="form-label">Body (French)</label>
          <textarea name="body_fr" class="form-input"><?= htmlspecialchars($editRec['body_fr'] ?? '') ?></textarea>
        </div>
      </div>

      <div style="display:flex;gap:10px;align-items:center;">
        <button type="submit" class="btn-primary">&#x1F4BE; <?= $editRec ? 'Update' : 'Create' ?> Recommendation</button>
        <a href="<?= SITE_URL ?>/admin/bhc-recommendations" class="btn-secondary">Cancel</a>
      </div>
    </form>
  </div>
  <?php endif; ?>

  <!-- Filters -->
  <form method="GET" action="" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:1rem;align-items:center;">
    <select name="band" style="padding:8px 12px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:8px;color:var(--text);font-size:13px;">
      <option value="">All Bands</option>
      <?php foreach ($allBands as $slug => $label): ?>
      <option value="<?= $slug ?>" <?= $filterBand===$slug?'selected':'' ?>><?= $label ?></option>
      <?php endforeach; ?>
    </select>
    <select name="cat" style="padding:8px 12px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:8px;color:var(--text);font-size:13px;">
      <option value="">All Categories</option>
      <?php foreach ($catRows as $cr): ?>
      <option value="<?= (int)($cr['id'] ?? 0) ?>" <?= $filterCat===(int)($cr['id']??0)?'selected':'' ?>><?= htmlspecialchars($cr['label_en'] ?? $cr['slug']) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" style="padding:8px 18px;background:#00A878;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">Filter</button>
    <?php if ($filterBand||$filterCat): ?>
    <a href="<?= SITE_URL ?>/admin/bhc-recommendations" style="padding:8px 14px;border:1px solid rgba(255,255,255,0.1);border-radius:8px;font-size:13px;color:var(--muted);text-decoration:none;">Clear</a>
    <?php endif; ?>
  </form>

  <!-- Recommendations table -->
  <div style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.08);border-radius:12px;overflow:hidden;margin-bottom:2rem;">
    <table class="bhc-table">
      <thead>
        <tr>
          <th style="width:28px;">P</th>
          <th>Title</th>
          <th>Category</th>
          <th>Band</th>
          <th>Status</th>
          <th style="width:120px;"></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$recs): ?>
        <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:40px;">
          <?php if ($filterBand||$filterCat): ?>
          No recommendations match these filters. <a href="<?= SITE_URL ?>/admin/bhc-recommendations" style="color:#00A878;">Clear filters</a>
          <?php else: ?>
          No recommendations yet. <a href="?edit=new" style="color:#00A878;">Add the first one →</a>
          <?php endif; ?>
        </td></tr>
        <?php endif; ?>
        <?php foreach ($recs as $rec):
          $col = bhc_band_color($rec['score_band'] ?? 'developing');
          $catLabel = $rec['cat_label'] ?? ($rec['cat_slug'] ? ucwords(str_replace('_',' ',$rec['cat_slug'])) : '—');
        ?>
        <tr>
          <td style="color:var(--muted);font-size:12px;text-align:center;"><?= (int)$rec['priority'] ?></td>
          <td>
            <div style="font-weight:600;font-size:13px;margin-bottom:3px;"><?= htmlspecialchars($rec['title_en']) ?></div>
            <div style="font-size:11px;color:var(--muted);max-width:380px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars(mb_substr($rec['body_en'], 0, 100)) ?>…</div>
            <?php if ($rec['title_fr']): ?>
            <div style="font-size:11px;color:var(--muted);margin-top:2px;">&#x1F1EB;&#x1F1F7; <?= htmlspecialchars($rec['title_fr']) ?></div>
            <?php endif; ?>
          </td>
          <td style="font-size:12px;color:var(--muted);"><?= htmlspecialchars($catLabel) ?></td>
          <td><span class="band-pill" style="background:<?= $col ?>22;color:<?= $col ?>;border:1px solid <?= $col ?>44;"><?= $allBands[$rec['score_band']] ?? $rec['score_band'] ?></span></td>
          <td>
            <?php if ($rec['is_active']): ?>
            <span style="font-size:11px;color:#00A878;">&#x25CF; Active</span>
            <?php else: ?>
            <span style="font-size:11px;color:var(--muted);">&#x25CB; Inactive</span>
            <?php endif; ?>
          </td>
          <td style="white-space:nowrap;">
            <a href="?edit=<?= (int)$rec['id'] ?>" style="font-size:12px;color:#3182ce;margin-right:10px;">Edit</a>
            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this recommendation?')">
              <input type="hidden" name="_csrf" value="<?= $csrf ?>">
              <input type="hidden" name="_action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$rec['id'] ?>">
              <button type="submit" style="background:none;border:none;cursor:pointer;font-size:12px;color:#e53e3e;padding:0;">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Help note -->
  <div style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.06);border-radius:10px;padding:14px 16px;font-size:12.5px;color:var(--muted);margin-bottom:2rem;">
    <strong style="color:var(--text);">How it works:</strong> When a lead completes their assessment, the system looks up the top 4 recommendations for their score band (ordered by priority), matched by category. These appear in the email report and PDF. Add at least 4 recommendations per band for best results.
  </div>

</div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
