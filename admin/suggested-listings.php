<?php
/**
 * admin/suggested-listings.php — Queue for suggested / requested businesses.
 *
 * Actions:
 *   approve  — mark as approved (business will be contacted)
 *   reject   — mark as rejected
 *   convert  — create a placeholder draft listing from the suggestion
 *              so the owner can claim it via the existing claims flow
 *   delete   — hard-delete a row
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/ops-admin-helpers.php';
requireAnyAdmin();

$pdo = db();
$isFr = lang() === 'fr';

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    if ($id > 0) {
        switch ($action) {

            case 'approve':
                $pdo->prepare("UPDATE suggested_listings SET status='approved' WHERE id=?")->execute([$id]);
                setFlash('success', 'Suggestion marked as approved.');
                break;

            case 'reject':
                $notes = trim($_POST['admin_notes'] ?? '');
                $pdo->prepare("UPDATE suggested_listings SET status='rejected', admin_notes=? WHERE id=?")->execute([$notes ?: null, $id]);
                setFlash('success', 'Suggestion rejected.');
                break;

            case 'save_notes':
                $notes = trim($_POST['admin_notes'] ?? '');
                $pdo->prepare("UPDATE suggested_listings SET admin_notes=? WHERE id=?")->execute([$notes ?: null, $id]);
                setFlash('success', 'Notes saved.');
                break;

            case 'convert':
                // Pull suggestion row
                $sug = $pdo->prepare("SELECT * FROM suggested_listings WHERE id=?")->execute([$id])
                    ? $pdo->prepare("SELECT * FROM suggested_listings WHERE id=?")
                    : null;
                if ($sug === null) break;
                $sug->execute([$id]);
                $row = $sug->fetch();

                if ($row) {
                    // Build a draft listing slug
                    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($row['business_name']));
                    $slug = trim($slug, '-');
                    // Ensure uniqueness
                    $base = $slug; $n = 1;
                    while ($pdo->prepare("SELECT id FROM listings WHERE slug=?")->execute([$slug]) &&
                           $pdo->prepare("SELECT COUNT(*) FROM listings WHERE slug=?")->execute([$slug]) &&
                           (int)$pdo->query("SELECT COUNT(*) FROM listings WHERE slug='$slug'")->fetchColumn() > 0) {
                        $slug = $base . '-' . (++$n);
                    }

                    // Insert a draft/unclaimed placeholder
                    $ins = $pdo->prepare("
                        INSERT INTO listings
                            (title, slug, category_id, location_id, description, status, is_claimed, created_at)
                        VALUES (?, ?, ?, ?, ?, 'draft', 0, NOW())
                    ");
                    $ins->execute([
                        $row['business_name'],
                        $slug,
                        $row['category_id'] ?: null,
                        $row['location_id'] ?: null,
                        $row['description'] ?: null,
                    ]);
                    $listingId = (int)$pdo->lastInsertId();

                    // Mark suggestion converted
                    $pdo->prepare("UPDATE suggested_listings SET status='converted', converted_listing_id=? WHERE id=?")
                        ->execute([$listingId, $id]);

                    setFlash('success', "Draft listing #{$listingId} created from suggestion. Find it in Listings → Draft.");
                }
                break;

            case 'delete':
                $pdo->prepare("DELETE FROM suggested_listings WHERE id=?")->execute([$id]);
                setFlash('success', 'Suggestion deleted.');
                break;
        }
    }
    redirect(SITE_URL . '/admin/suggested-listings.php');
}

// ── Filter ────────────────────────────────────────────────────────────────────
$statusFilter = $_GET['status'] ?? 'pending';
$allowed = ['pending', 'approved', 'rejected', 'converted', 'all'];
if (!in_array($statusFilter, $allowed, true)) $statusFilter = 'pending';

$where = $statusFilter !== 'all' ? "WHERE s.status = " . $pdo->quote($statusFilter) : '';

$rows = $pdo->query("
    SELECT s.*,
           c.name_en AS cat_name,
           l.name_en AS loc_name
    FROM suggested_listings s
    LEFT JOIN categories c ON c.id = s.category_id
    LEFT JOIN locations  l ON l.id = s.location_id
    $where
    ORDER BY s.created_at DESC
    LIMIT 200
")->fetchAll();

// Counts per status
$counts = [];
foreach (['pending','approved','rejected','converted'] as $st) {
    $counts[$st] = (int)$pdo->query("SELECT COUNT(*) FROM suggested_listings WHERE status='$st'")->fetchColumn();
}
$counts['all'] = array_sum($counts);

$pageTitle = 'Suggested Listings — Admin';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/includes/nav.php';
adminNav('suggested-listings'); adminNavAssets();
?>

<style>
.sug-tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:1.5rem; }
.sug-tabs a { padding:6px 14px; border-radius:99px; font-size:0.82rem; font-weight:700; text-decoration:none; border:1px solid var(--border); color:var(--muted); background:transparent; transition:.15s; }
.sug-tabs a.active, .sug-tabs a:hover { background:rgba(0,168,120,0.12); border-color:rgba(0,168,120,0.3); color:var(--green); }
.sug-row { background:var(--card); border:1px solid var(--border); border-radius:14px; padding:1.25rem 1.5rem; display:flex; flex-direction:column; gap:0.75rem; }
.sug-row.status-pending  { border-color:rgba(252,209,22,0.25); background:rgba(252,209,22,0.02); }
.sug-row.status-approved { border-color:rgba(0,168,120,0.25); background:rgba(0,168,120,0.02); }
.sug-row.status-rejected { opacity:.6; }
.sug-row.status-converted { border-color:rgba(99,102,241,0.3); background:rgba(99,102,241,0.03); }
.sug-meta { font-size:0.8rem; color:var(--muted); display:flex; flex-wrap:wrap; gap:0.4rem 1rem; }
.sug-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.sug-notes-wrap textarea { width:100%; padding:0.5rem 0.75rem; border-radius:8px; border:1px solid var(--border); background:rgba(255,255,255,0.03); color:var(--white); font-size:0.82rem; font-family:inherit; resize:vertical; min-height:60px; box-sizing:border-box; }
</style>

<div class="page-header"><div class="container">
  <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:2rem;">
    💡 Suggested Listings
    <?php if ($counts['pending']): ?>
      <span class="badge badge-pending" style="font-size:0.9rem;vertical-align:middle;"><?= $counts['pending'] ?> pending</span>
    <?php endif; ?>
  </h1>
</div></div>

<section class="page-section"><div class="container">

  <div class="sug-tabs">
    <?php foreach (['pending'=>'⏳ Pending','approved'=>'✅ Approved','rejected'=>'✗ Rejected','converted'=>'🔄 Converted','all'=>'All'] as $st => $label): ?>
      <a href="?status=<?= $st ?>" class="<?= $statusFilter === $st ? 'active' : '' ?>">
        <?= $label ?> <span style="opacity:.6">(<?= $counts[$st] ?? 0 ?>)</span>
      </a>
    <?php endforeach; ?>
  </div>

  <div style="display:flex;flex-direction:column;gap:1rem;">
    <?php foreach ($rows as $r): ?>
      <div class="sug-row status-<?= e($r['status']) ?>">

        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
          <div>
            <span style="font-size:1.05rem;font-weight:700;color:var(--white);"><?= e($r['business_name']) ?></span>
            <?php if ($r['cat_name']): ?>
              <span class="badge" style="margin-left:6px;font-size:0.72rem;background:rgba(0,168,120,0.1);color:var(--green);border:1px solid rgba(0,168,120,0.2);">
                <?= e($r['cat_name']) ?>
              </span>
            <?php endif; ?>
            <?php if ($r['loc_name']): ?>
              <span style="font-size:0.8rem;color:var(--muted);margin-left:6px;">📍 <?= e($r['loc_name']) ?></span>
            <?php endif; ?>
          </div>
          <div style="font-size:0.78rem;color:var(--muted-2);text-align:right;flex-shrink:0;">
            <?= date('d M Y H:i', strtotime($r['created_at'])) ?>
            <span class="badge badge-<?= $r['status'] === 'pending' ? 'pending' : ($r['status'] === 'approved' ? 'active' : 'draft') ?>" style="margin-left:6px;">
              <?= ucfirst($r['status']) ?>
            </span>
          </div>
        </div>

        <div class="sug-meta">
          <?php if ($r['description']): ?>
            <span>📝 <?= e(mb_substr($r['description'], 0, 160)) ?><?= mb_strlen($r['description']) > 160 ? '…' : '' ?></span>
          <?php endif; ?>
          <?php if ($r['contact_info']): ?>
            <span>🔗 <?= e($r['contact_info']) ?></span>
          <?php endif; ?>
          <?php if ($r['submitter_name'] || $r['submitter_email']): ?>
            <span>👤 <?= e($r['submitter_name'] ?: '') ?><?= $r['submitter_email'] ? (' &lt;' . e($r['submitter_email']) . '&gt;') : '' ?></span>
          <?php endif; ?>
          <span>🌐 IP: <?= e($r['submitter_ip'] ?: '—') ?></span>
          <?php if ($r['converted_listing_id']): ?>
            <a href="<?= SITE_URL ?>/admin/listing-edit.php?id=<?= (int)$r['converted_listing_id'] ?>" style="color:var(--green);">
              🔄 Listing #<?= (int)$r['converted_listing_id'] ?>
            </a>
          <?php endif; ?>
        </div>

        <!-- Notes -->
        <form method="POST" class="sug-notes-wrap" style="display:flex;gap:8px;align-items:flex-end;">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <input type="hidden" name="action" value="save_notes">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <div style="flex:1;">
            <label style="font-size:0.75rem;color:var(--muted);display:block;margin-bottom:4px;">Admin notes</label>
            <textarea name="admin_notes" placeholder="Internal notes…"><?= e($r['admin_notes'] ?? '') ?></textarea>
          </div>
          <button type="submit" class="btn btn-sm btn-outline" style="flex-shrink:0;margin-bottom:2px;">Save</button>
        </form>

        <!-- Actions -->
        <div class="sug-actions">
          <?php if ($r['status'] === 'pending'): ?>
            <form method="POST" style="display:inline;">
              <input type="hidden" name="csrf" value="<?= csrf() ?>">
              <input type="hidden" name="action" value="approve">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button type="submit" class="btn btn-sm btn-primary">✅ Approve</button>
            </form>
            <form method="POST" style="display:inline;">
              <input type="hidden" name="csrf" value="<?= csrf() ?>">
              <input type="hidden" name="action" value="reject">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline" style="color:#f87171;border-color:rgba(248,113,113,0.3);"
                      onclick="return confirm('Reject this suggestion?')">✗ Reject</button>
            </form>
          <?php endif; ?>

          <?php if (in_array($r['status'], ['pending','approved'], true) && !$r['converted_listing_id']): ?>
            <form method="POST" style="display:inline;">
              <input type="hidden" name="csrf" value="<?= csrf() ?>">
              <input type="hidden" name="action" value="convert">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button type="submit" class="btn btn-sm"
                      style="background:rgba(99,102,241,0.12);color:#a5b4fc;border:1px solid rgba(99,102,241,0.3);"
                      onclick="return confirm('Create a draft listing from this suggestion?')">🔄 Convert to Draft Listing</button>
            </form>
          <?php endif; ?>

          <form method="POST" style="display:inline;margin-left:auto;">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline" style="opacity:.5;font-size:0.75rem;"
                    onclick="return confirm('Permanently delete this suggestion?')">🗑 Delete</button>
          </form>
        </div>

      </div>
    <?php endforeach; ?>

    <?php if (!$rows): ?>
      <div style="text-align:center;padding:3rem;background:var(--card);border:1px solid var(--border);border-radius:14px;">
        <div style="font-size:2.5rem;margin-bottom:0.75rem;">💡</div>
        <p style="color:var(--muted);">No <?= $statusFilter !== 'all' ? $statusFilter : '' ?> suggestions yet.</p>
        <p style="font-size:0.82rem;color:var(--muted-2);">
          Suggestions come from the
          <a href="<?= SITE_URL ?>/suggest-business" target="_blank" style="color:var(--green);">suggest-business page</a>
          and from empty search results.
        </p>
      </div>
    <?php endif; ?>
  </div>

</div></section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
