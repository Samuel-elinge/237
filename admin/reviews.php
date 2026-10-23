<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/ops-admin-helpers.php';
requireAnyAdmin();

// ── POST handler ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    requirePermission('review.approve'); // ops admin must have review.approve to act here
    $action = $_POST['action'] ?? '';

    // ── Bulk action ────────────────────────────────────────────────────────────
    if ($action === 'bulk' && !empty($_POST['ids'])) {
        $ids       = array_map('intval', (array)$_POST['ids']);
        $ids       = array_filter($ids);
        $bulkAct   = $_POST['bulk_action'] ?? '';
        if ($ids && in_array($bulkAct, ['approve','reject','flag'])) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            if ($bulkAct === 'approve') {
                db()->prepare("UPDATE reviews SET status='approved' WHERE id IN ($ph)")->execute($ids);
                // Fire notifications for each approved review
                foreach ($ids as $rid) {
                    try {
                        $rv = db()->prepare("SELECT r.*, l.title AS listing_title, l.slug, u.email AS owner_email, u.name AS owner_name FROM reviews r JOIN listings l ON l.id=r.listing_id LEFT JOIN users u ON u.id=l.user_id WHERE r.id=?");
                        $rv->execute([$rid]);
                        $rv = $rv->fetch();
                        if ($rv && $rv['owner_email']) {
                            sendMail($rv['owner_email'],
                                'New review on your listing — 237Biz',
                                '<h2 style="color:#fff;">⭐ New Review Approved</h2>
                                 <p style="color:rgba(255,255,255,0.8);">A customer left a review on <strong>' . e($rv['listing_title']) . '</strong>.</p>
                                 <div style="background:rgba(245,200,66,0.08);border:1px solid rgba(245,200,66,0.2);border-radius:10px;padding:1rem;margin:1rem 0;">
                                   <div style="color:#F5C842;margin-bottom:0.4rem;">' . str_repeat('⭐', (int)($rv['rating'] ?? 5)) . '</div>
                                   <p style="color:rgba(255,255,255,0.8);margin:0;">' . e($rv['comment'] ?? '') . '</p>
                                 </div>
                                 <a href="' . SITE_URL . '/listing/' . e($rv['slug']) . '" style="display:inline-block;margin-top:1rem;background:#00A878;color:#fff;padding:0.75rem 1.5rem;border-radius:6px;text-decoration:none;">View Listing →</a>'
                            );
                        }
                    } catch (Exception $e) {}
                }
            } elseif ($bulkAct === 'reject') {
                requirePermission('review.reject');
                db()->prepare("DELETE FROM reviews WHERE id IN ($ph)")->execute($ids);
                opsAuditLog('review.bulk_reject', 'review', 0, 'Bulk rejected ' . count($ids) . ' review(s)');
            } elseif ($bulkAct === 'flag') {
                db()->prepare("UPDATE reviews SET flagged=1 WHERE id IN ($ph)")->execute($ids);
                opsAuditLog('review.bulk_flag', 'review', 0, 'Bulk flagged ' . count($ids) . ' review(s)');
            }
            if ($bulkAct === 'approve') opsAuditLog('review.bulk_approve', 'review', 0, 'Bulk approved ' . count($ids) . ' review(s)');
            flash('success', count($ids) . ' review(s) updated.');
        }
        redirect(SITE_URL . '/admin/reviews.php' . (isset($_GET['status']) ? '?status=' . urlencode($_GET['status']) : ''));
    }

    // ── Single action ─────────────────────────────────────────────────────────
    $reviewId = (int)($_POST['review_id'] ?? 0);
    if ($reviewId && in_array($action, ['approve','reject','flag','unflag'])) {
        if ($action === 'approve') {
            opsAuditLog('review.approve', 'review', $reviewId);
            db()->prepare("UPDATE reviews SET status='approved', flagged=0 WHERE id=?")->execute([$reviewId]);
            try {
                $rv = db()->prepare("SELECT r.*, l.title AS listing_title, l.slug, u.email AS owner_email, u.name AS owner_name FROM reviews r JOIN listings l ON l.id=r.listing_id LEFT JOIN users u ON u.id=l.user_id WHERE r.id=?");
                $rv->execute([$reviewId]);
                $rv = $rv->fetch();
                if ($rv && $rv['owner_email']) {
                    sendMail($rv['owner_email'],
                        t('New review on your listing — 237Biz', 'Nouvel avis sur votre annonce — 237Biz'),
                        '<h2 style="color:#fff;font-family:Georgia,serif;">⭐ ' . t('New Review Approved', 'Nouvel avis approuvé') . '</h2>
                         <p style="color:rgba(255,255,255,0.8);">' . t('Hi', 'Bonjour') . ' ' . e($rv['owner_name']) . ',</p>
                         <p style="color:rgba(255,255,255,0.7);">' . t('A customer left a review on your listing', 'Un client a laissé un avis sur votre annonce') . ' <strong style="color:#fff;">' . e($rv['listing_title']) . '</strong>.</p>
                         <div style="background:rgba(245,200,66,0.08);border:1px solid rgba(245,200,66,0.2);border-radius:10px;padding:1rem;margin:1rem 0;">
                           <div style="color:#F5C842;margin-bottom:0.4rem;">' . str_repeat('⭐', (int)($rv['rating'] ?? 5)) . '</div>
                           <p style="color:rgba(255,255,255,0.8);margin:0;">' . e($rv['comment'] ?? '') . '</p>
                         </div>
                         <a href="' . SITE_URL . '/listing/' . e($rv['slug']) . '" style="display:inline-block;margin-top:1rem;background:#00A878;color:#fff;padding:0.75rem 1.5rem;border-radius:6px;text-decoration:none;">' . t('View Your Listing →', 'Voir votre annonce →') . '</a>'
                    );
                }
            } catch (Exception $e) {}
        } elseif ($action === 'reject') {
            requirePermission('review.reject');
            opsAuditLog('review.reject', 'review', $reviewId);
            db()->prepare("DELETE FROM reviews WHERE id=?")->execute([$reviewId]);
        } elseif ($action === 'flag') {
            opsAuditLog('review.flag', 'review', $reviewId);
            db()->prepare("UPDATE reviews SET flagged=1 WHERE id=?")->execute([$reviewId]);
        } elseif ($action === 'unflag') {
            opsAuditLog('review.unflag', 'review', $reviewId);
            db()->prepare("UPDATE reviews SET flagged=0 WHERE id=?")->execute([$reviewId]);
        }
        flash('success', 'Review updated.');
    }
    redirect(SITE_URL . '/admin/reviews.php' . (isset($_GET['status']) ? '?status=' . urlencode($_GET['status']) : ''));
}

// ── Filters & pagination ──────────────────────────────────────────────────────
$statusFilter = in_array($_GET['status'] ?? '', ['pending','approved','flagged']) ? $_GET['status'] : '';
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 50;
$offset       = ($page - 1) * $perPage;

$where  = [];
$params = [];
if ($statusFilter === 'flagged') {
    $where[] = 'r.flagged = 1';
} elseif ($statusFilter) {
    $where[] = 'r.status = ?';
    $params[] = $statusFilter;
}
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int)db()->prepare("SELECT COUNT(*) FROM reviews r $whereSQL")->execute($params) ? (function() use ($where, $params, $whereSQL) {
    $c = db()->prepare("SELECT COUNT(*) FROM reviews r $whereSQL");
    $c->execute($params);
    return (int)$c->fetchColumn();
})() : 0;

$pages = max(1, (int)ceil($total / $perPage));

$rq = db()->prepare("
    SELECT r.*, l.title AS listing_title, l.slug AS listing_slug,
           u.name AS user_name,
           COALESCE(vt.helpful,0)     AS helpful,
           COALESCE(vt.not_helpful,0) AS not_helpful
    FROM reviews r
    JOIN listings l ON l.id = r.listing_id
    LEFT JOIN users u ON u.id = r.user_id
    LEFT JOIN (
        SELECT review_id,
               SUM(vote= 1) AS helpful,
               SUM(vote=-1) AS not_helpful
        FROM review_votes GROUP BY review_id
    ) vt ON vt.review_id = r.id
    $whereSQL
    ORDER BY
        CASE WHEN r.flagged=1 THEN 0 ELSE 1 END,
        FIELD(r.status,'pending','approved'),
        r.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$rq->execute($params);
$reviews = $rq->fetchAll();

// Summary counts
$counts = db()->query("
    SELECT
        SUM(status='pending')  AS pending,
        SUM(status='approved') AS approved,
        SUM(flagged=1)         AS flagged
    FROM reviews
")->fetch();

$pageTitle = 'Reviews — Admin';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/includes/nav.php';
adminNav('reviews'); adminNavAssets();
?>
<div class="page-header"><div class="container">
  <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:2rem;">⭐ Review Moderation</h1>
</div></div>
<section class="page-section"><div class="container">

<?php $msg = flash(); if ($msg): ?>
<div class="alert alert-<?= $msg['type'] ?>" style="margin-bottom:1rem;"><?= e($msg['text']) ?></div>
<?php endif; ?>

<!-- Summary bar -->
<div style="display:flex;gap:0.75rem;flex-wrap:wrap;margin-bottom:1.5rem;">
  <?php
  $filters = [
    ''         => ['label'=>'All',      'count'=>$counts['pending']+$counts['approved'], 'color'=>'var(--green)'],
    'pending'  => ['label'=>'Pending',  'count'=>$counts['pending'],                     'color'=>'#fbbf24'],
    'approved' => ['label'=>'Approved', 'count'=>$counts['approved'],                    'color'=>'#00a878'],
    'flagged'  => ['label'=>'Flagged',  'count'=>$counts['flagged'],                     'color'=>'#ef4444'],
  ];
  foreach ($filters as $k => $f):
    $active = $statusFilter === $k;
  ?>
  <a href="?status=<?= $k ?>"
     style="display:flex;flex-direction:column;align-items:center;padding:0.6rem 1.1rem;border-radius:10px;border:1px solid <?= $active ? $f['color'] : 'var(--border)' ?>;background:<?= $active ? $f['color'].'22' : 'var(--card)' ?>;text-decoration:none;min-width:80px;">
    <span style="font-size:1.3rem;font-weight:700;color:<?= $f['color'] ?>;"><?= (int)$f['count'] ?></span>
    <span style="font-size:0.7rem;color:var(--muted);"><?= $f['label'] ?></span>
  </a>
  <?php endforeach; ?>
</div>

<!-- Bulk action form -->
<form id="bulk-form" method="POST">
  <input type="hidden" name="csrf"    value="<?= csrf() ?>">
  <input type="hidden" name="action"  value="bulk">

  <!-- Toolbar -->
  <div style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;margin-bottom:0.75rem;">
    <label style="font-size:0.78rem;color:var(--muted);display:flex;align-items:center;gap:0.4rem;">
      <input type="checkbox" id="select-all" onchange="toggleAll(this)">
      <span>Select all</span>
    </label>
    <select name="bulk_action" style="background:var(--card);border:1px solid var(--border);border-radius:7px;padding:0.35rem 0.75rem;font-size:0.82rem;color:var(--white);">
      <option value="">— Bulk action —</option>
      <option value="approve">✓ Approve</option>
      <option value="reject">✗ Delete</option>
      <option value="flag">🚩 Flag as spam</option>
    </select>
    <button type="submit" onclick="return confirmBulk()"
            style="padding:0.35rem 0.9rem;background:var(--green);color:#fff;border:none;border-radius:7px;font-size:0.82rem;cursor:pointer;">
      Apply
    </button>
    <span style="font-size:0.78rem;color:var(--muted-2);margin-left:auto;">
      <?= $total ?> review<?= $total !== 1 ? 's' : '' ?> · Page <?= $page ?>/<?= $pages ?>
    </span>
  </div>

  <div class="listing-widget" style="padding:0;overflow:hidden;">
  <div style="overflow-x:auto;">
    <table class="data-table">
      <thead>
        <tr>
          <th style="width:32px;"></th>
          <th>Listing</th>
          <th>Reviewer</th>
          <th>Rating</th>
          <th>Comment / Reply</th>
          <th>Votes</th>
          <th>Status</th>
          <th>Date</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($reviews as $r): ?>
          <tr style="<?= $r['flagged'] ? 'background:rgba(239,68,68,0.05);' : '' ?>">
            <td><input type="checkbox" name="ids[]" value="<?= $r['id'] ?>" class="row-cb"></td>
            <td><a href="<?= SITE_URL ?>/listing/<?= e($r['listing_slug']) ?>" target="_blank" style="color:var(--yellow);font-size:0.8rem;"><?= e(mb_substr($r['listing_title'],0,28)) ?></a></td>
            <td style="font-size:0.8rem;color:var(--white);"><?= e($r['name'] ?? $r['user_name'] ?? 'Anonymous') ?></td>
            <td style="color:var(--yellow);white-space:nowrap;"><?= str_repeat('⭐', (int)$r['rating']) ?></td>
            <td style="max-width:220px;">
              <p style="font-size:0.78rem;color:var(--muted);margin:0 0 0.25rem;"><?= e(mb_substr($r['comment']??'',0,90)) ?></p>
              <?php if (!empty($r['owner_reply'])): ?>
                <div style="font-size:0.7rem;color:var(--green);border-left:2px solid var(--green);padding-left:0.4rem;">
                  🏢 <?= e(mb_substr($r['owner_reply'],0,60)) ?>
                </div>
              <?php endif; ?>
              <?php if ($r['flagged']): ?><span style="font-size:0.65rem;color:#ef4444;">🚩 Flagged</span><?php endif; ?>
            </td>
            <td style="font-size:0.75rem;color:var(--muted-2);white-space:nowrap;">
              👍 <?= (int)$r['helpful'] ?> &nbsp;👎 <?= (int)$r['not_helpful'] ?>
            </td>
            <td>
              <span style="font-size:0.7rem;padding:0.2rem 0.55rem;border-radius:20px;
                           background:<?= $r['status']==='approved' ? 'rgba(0,168,120,0.15)' : 'rgba(251,191,36,0.15)' ?>;
                           color:<?= $r['status']==='approved' ? '#00a878' : '#fbbf24' ?>;">
                <?= ucfirst($r['status']) ?>
              </span>
            </td>
            <td style="font-size:0.75rem;color:var(--muted-2);white-space:nowrap;"><?= date('d M Y', strtotime($r['created_at'])) ?></td>
            <td>
              <div style="display:flex;gap:0.25rem;flex-wrap:nowrap;">
                <!-- Single-record actions (outside bulk form to avoid name collision) -->
                <?php if ($r['status']==='pending'): ?>
                <form method="POST" style="display:inline;">
                  <input type="hidden" name="csrf"      value="<?= csrf() ?>">
                  <input type="hidden" name="review_id" value="<?= $r['id'] ?>">
                  <button name="action" value="approve" class="btn btn-primary btn-sm" title="Approve">✓</button>
                </form>
                <?php endif; ?>
                <?php if (!$r['flagged']): ?>
                <form method="POST" style="display:inline;">
                  <input type="hidden" name="csrf"      value="<?= csrf() ?>">
                  <input type="hidden" name="review_id" value="<?= $r['id'] ?>">
                  <button name="action" value="flag" class="btn btn-outline btn-sm" title="Flag" style="border-color:rgba(251,191,36,0.4);color:#fbbf24;">🚩</button>
                </form>
                <?php else: ?>
                <form method="POST" style="display:inline;">
                  <input type="hidden" name="csrf"      value="<?= csrf() ?>">
                  <input type="hidden" name="review_id" value="<?= $r['id'] ?>">
                  <button name="action" value="unflag" class="btn btn-outline btn-sm" title="Unflag" style="border-color:rgba(0,168,120,0.3);color:var(--green);">✓🚩</button>
                </form>
                <?php endif; ?>
                <form method="POST" style="display:inline;">
                  <input type="hidden" name="csrf"      value="<?= csrf() ?>">
                  <input type="hidden" name="review_id" value="<?= $r['id'] ?>">
                  <button name="action" value="reject" class="btn btn-outline btn-sm" title="Delete"
                          style="border-color:rgba(230,50,50,0.3);color:#ff6b6b;"
                          onclick="return confirm('Delete this review?')">✗</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$reviews): ?>
          <tr><td colspan="9" style="text-align:center;color:var(--muted);padding:2rem;">No reviews found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  </div>
</form>

<!-- Pagination -->
<?php if ($pages > 1): ?>
<div style="display:flex;gap:0.5rem;flex-wrap:wrap;justify-content:center;margin-top:1.25rem;">
  <?php for ($p = 1; $p <= $pages; $p++): ?>
    <a href="?status=<?= urlencode($statusFilter) ?>&page=<?= $p ?>"
       style="padding:0.3rem 0.75rem;border-radius:7px;font-size:0.82rem;text-decoration:none;
              background:<?= $p===$page ? 'var(--green)' : 'var(--card)' ?>;
              color:<?= $p===$page ? '#fff' : 'var(--muted)' ?>;
              border:1px solid <?= $p===$page ? 'var(--green)' : 'var(--border)' ?>;">
      <?= $p ?>
    </a>
  <?php endfor; ?>
</div>
<?php endif; ?>

</div></section>

<script>
function toggleAll(cb) {
    document.querySelectorAll('.row-cb').forEach(function(c) { c.checked = cb.checked; });
}
function confirmBulk() {
    const checked = document.querySelectorAll('.row-cb:checked');
    if (!checked.length) { alert('Select at least one review.'); return false; }
    const act = document.querySelector('[name=bulk_action]').value;
    if (!act) { alert('Choose a bulk action.'); return false; }
    return confirm(checked.length + ' review(s) will be ' + act + 'd. Continue?');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
