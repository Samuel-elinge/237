<?php
/**
 * manage-reviews.php — Listing Owner Review Management
 * Lets business owners read and reply to their reviews.
 */

require_once __DIR__ . '/includes/config.php';
requireLogin();

$u   = currentUser();
$msg = flash();

// ── Select which listing to manage ───────────────────────────────────────────
$myListings = [];
try {
    $lq = db()->prepare("SELECT id, title, slug FROM listings WHERE user_id=? AND status='approved' ORDER BY title");
    $lq->execute([$u['id']]);
    $myListings = $lq->fetchAll();
} catch (Exception $e) {}

if (!$myListings) {
    // No approved listings
    $pageTitle = t('My Reviews', 'Mes Avis');
    include __DIR__ . '/includes/header.php';
    echo '<div class="container" style="padding:3rem 0;">';
    echo '<p style="color:var(--muted);">' . t('You have no approved listings yet.','Vous n\'avez pas encore d\'annonce approuvée.') . '</p>';
    echo '</div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

$lid = (int)($_GET['lid'] ?? $myListings[0]['id']);
// Ensure the chosen listing belongs to this user
$activeListing = null;
foreach ($myListings as $ml) {
    if ($ml['id'] === $lid) { $activeListing = $ml; break; }
}
if (!$activeListing) { $lid = $myListings[0]['id']; $activeListing = $myListings[0]; }

// ── Fetch reviews with vote tallies ──────────────────────────────────────────
$reviews = [];
try {
    $rq = db()->prepare("
        SELECT
            r.*,
            COALESCE(v.helpful,0)     AS helpful,
            COALESCE(v.not_helpful,0) AS not_helpful
        FROM reviews r
        LEFT JOIN (
            SELECT review_id,
                   SUM(vote= 1) AS helpful,
                   SUM(vote=-1) AS not_helpful
            FROM review_votes GROUP BY review_id
        ) v ON v.review_id = r.id
        WHERE r.listing_id = ?
        ORDER BY FIELD(r.status,'pending','approved','rejected'), r.created_at DESC
    ");
    $rq->execute([$lid]);
    $reviews = $rq->fetchAll();
} catch (Exception $e) {}

// ── Counts ────────────────────────────────────────────────────────────────────
$pending  = count(array_filter($reviews, fn($r) => $r['status']==='pending'));
$approved = count(array_filter($reviews, fn($r) => $r['status']==='approved'));

$pageTitle = t('My Reviews', 'Mes Avis') . ' — ' . e($activeListing['title']);
include __DIR__ . '/includes/header.php';
?>
<div class="container" style="padding:2rem 0 3rem;">

  <!-- Page header -->
  <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:1.5rem;">
    <a href="<?= SITE_URL ?>/dashboard" style="font-size:0.82rem;color:var(--green);">← <?= t('Dashboard','Tableau de bord') ?></a>
    <h1 style="margin:0;font-size:1.4rem;">⭐ <?= t('Manage Reviews','Gérer les avis') ?></h1>
  </div>

  <?php if ($msg): ?>
    <div class="alert alert-<?= $msg['type'] ?>" style="margin-bottom:1rem;"><?= e($msg['text']) ?></div>
  <?php endif; ?>

  <!-- Listing selector (if >1 listing) -->
  <?php if (count($myListings) > 1): ?>
  <div style="margin-bottom:1.5rem;">
    <label style="font-size:0.82rem;color:var(--muted);margin-right:0.5rem;"><?= t('Listing:','Annonce :') ?></label>
    <?php foreach ($myListings as $ml): ?>
      <a href="?lid=<?= $ml['id'] ?>"
         style="display:inline-block;margin-right:0.5rem;margin-bottom:0.4rem;padding:0.3rem 0.8rem;border-radius:20px;font-size:0.8rem;
                border:1px solid <?= $ml['id']===$lid ? 'var(--green)' : 'var(--border)' ?>;
                color:<?= $ml['id']===$lid ? 'var(--green)' : 'var(--muted)' ?>;text-decoration:none;">
        <?= e($ml['title']) ?>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Summary bar -->
  <div style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:1.5rem;">
    <div style="background:var(--card);border:1px solid var(--border);border-radius:10px;padding:0.85rem 1.25rem;min-width:110px;">
      <div style="font-size:1.6rem;font-weight:700;color:var(--green);"><?= $approved ?></div>
      <div style="font-size:0.75rem;color:var(--muted);"><?= t('Approved','Approuvés') ?></div>
    </div>
    <?php if ($pending): ?>
    <div style="background:var(--card);border:1px solid rgba(251,191,36,0.4);border-radius:10px;padding:0.85rem 1.25rem;min-width:110px;">
      <div style="font-size:1.6rem;font-weight:700;color:#fbbf24;"><?= $pending ?></div>
      <div style="font-size:0.75rem;color:var(--muted);"><?= t('Pending','En attente') ?></div>
    </div>
    <?php endif; ?>
    <?php
    $withReply = count(array_filter($reviews, fn($r)=>!empty($r['owner_reply'])));
    $noReply   = count(array_filter($reviews, fn($r)=>$r['status']==='approved' && empty($r['owner_reply'])));
    ?>
    <?php if ($noReply): ?>
    <div style="background:var(--card);border:1px solid rgba(239,68,68,0.3);border-radius:10px;padding:0.85rem 1.25rem;min-width:110px;">
      <div style="font-size:1.6rem;font-weight:700;color:#ef4444;"><?= $noReply ?></div>
      <div style="font-size:0.75rem;color:var(--muted);"><?= t('Awaiting reply','Sans réponse') ?></div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Review list -->
  <?php if (!$reviews): ?>
    <p style="color:var(--muted);"><?= t('No reviews yet for this listing.','Aucun avis pour cette annonce.') ?></p>
  <?php else: ?>
    <div id="review-list" style="display:flex;flex-direction:column;gap:1rem;">
      <?php foreach ($reviews as $r): ?>
        <?php
          $statusColor = match($r['status']) {
              'approved' => '#00a878',
              'pending'  => '#fbbf24',
              default    => '#ef4444',
          };
          $statusLabel = match($r['status']) {
              'approved' => t('Approved','Approuvé'),
              'pending'  => t('Pending','En attente'),
              default    => t('Rejected','Rejeté'),
          };
        ?>
        <div id="rv-<?= $r['id'] ?>" style="background:var(--card);border:1px solid var(--border);border-radius:12px;padding:1.2rem;">

          <!-- Header row -->
          <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:0.5rem;margin-bottom:0.75rem;">
            <div style="display:flex;align-items:center;gap:0.75rem;">
              <div style="width:36px;height:36px;border-radius:50%;background:var(--green);display:flex;align-items:center;justify-content:center;font-weight:600;font-size:0.9rem;color:#fff;flex-shrink:0;">
                <?= strtoupper(mb_substr($r['name'] ?? 'A', 0, 1)) ?>
              </div>
              <div>
                <strong style="font-size:0.9rem;color:var(--white);"><?= e($r['name'] ?? t('Anonymous','Anonyme')) ?></strong>
                <div style="font-size:0.72rem;color:var(--muted-2);"><?= timeAgo($r['created_at']) ?></div>
              </div>
            </div>
            <div style="display:flex;align-items:center;gap:0.75rem;">
              <span style="color:var(--yellow);font-size:0.9rem;"><?= str_repeat('⭐', (int)$r['rating']) ?></span>
              <span style="font-size:0.7rem;padding:0.2rem 0.6rem;border-radius:20px;background:<?= $statusColor ?>22;color:<?= $statusColor ?>;border:1px solid <?= $statusColor ?>55;">
                <?= $statusLabel ?>
              </span>
              <?php if ($r['flagged']): ?>
                <span style="font-size:0.7rem;padding:0.2rem 0.6rem;border-radius:20px;background:rgba(239,68,68,0.1);color:#ef4444;border:1px solid rgba(239,68,68,0.3);">
                  🚩 <?= t('Flagged','Signalé') ?>
                </span>
              <?php endif; ?>
            </div>
          </div>

          <!-- Comment -->
          <p style="color:var(--muted);font-size:0.875rem;margin:0 0 0.6rem;"><?= e($r['comment'] ?? '') ?></p>

          <!-- Helpfulness tally -->
          <?php if ($r['status'] === 'approved' && ((int)$r['helpful'] > 0 || (int)$r['not_helpful'] > 0)): ?>
          <p style="font-size:0.72rem;color:var(--muted-2);margin:0 0 0.85rem;">
            👍 <?= (int)$r['helpful'] ?> · 👎 <?= (int)$r['not_helpful'] ?>
          </p>
          <?php endif; ?>

          <!-- Owner reply display -->
          <?php if (!empty($r['owner_reply'])): ?>
          <div id="reply-display-<?= $r['id'] ?>" style="background:rgba(0,168,120,0.07);border-left:3px solid var(--green);border-radius:0 8px 8px 0;padding:0.75rem 1rem;margin-bottom:0.75rem;">
            <div style="font-size:0.7rem;color:var(--green);font-weight:600;margin-bottom:0.3rem;">
              🏢 <?= t('Owner reply','Réponse du propriétaire') ?> — <?= timeAgo($r['owner_reply_at'] ?? $r['created_at']) ?>
            </div>
            <p id="reply-text-<?= $r['id'] ?>" style="color:var(--muted);font-size:0.85rem;margin:0;"><?= e($r['owner_reply']) ?></p>
          </div>
          <?php else: ?>
          <div id="reply-display-<?= $r['id'] ?>" style="display:none;background:rgba(0,168,120,0.07);border-left:3px solid var(--green);border-radius:0 8px 8px 0;padding:0.75rem 1rem;margin-bottom:0.75rem;">
            <div style="font-size:0.7rem;color:var(--green);font-weight:600;margin-bottom:0.3rem;">🏢 <?= t('Owner reply','Réponse du propriétaire') ?></div>
            <p id="reply-text-<?= $r['id'] ?>" style="color:var(--muted);font-size:0.85rem;margin:0;"></p>
          </div>
          <?php endif; ?>

          <!-- Reply form (only for approved reviews) -->
          <?php if ($r['status'] === 'approved'): ?>
          <div id="reply-form-<?= $r['id'] ?>" style="<?= !empty($r['owner_reply']) ? 'display:none;' : '' ?>">
            <textarea id="reply-textarea-<?= $r['id'] ?>"
                      rows="3"
                      maxlength="2000"
                      placeholder="<?= t('Write your reply to this review…','Écrivez votre réponse à cet avis…') ?>"
                      style="width:100%;box-sizing:border-box;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:8px;padding:0.65rem 0.85rem;color:var(--white);font-size:0.85rem;resize:vertical;outline:none;"
                      onfocus="this.style.borderColor='var(--green)'" onblur="this.style.borderColor='var(--border)'"
            ><?= e($r['owner_reply'] ?? '') ?></textarea>
            <div style="display:flex;gap:0.5rem;margin-top:0.5rem;">
              <button onclick="submitReply(<?= $r['id'] ?>)"
                      style="padding:0.4rem 1rem;background:var(--green);color:#fff;border:none;border-radius:7px;font-size:0.82rem;cursor:pointer;">
                <?= t('Post Reply','Répondre') ?>
              </button>
              <?php if (!empty($r['owner_reply'])): ?>
              <button onclick="clearReply(<?= $r['id'] ?>)"
                      style="padding:0.4rem 0.9rem;background:transparent;color:var(--muted);border:1px solid var(--border);border-radius:7px;font-size:0.82rem;cursor:pointer;">
                <?= t('Delete Reply','Supprimer') ?>
              </button>
              <?php endif; ?>
            </div>
          </div>

          <!-- Edit reply toggle (shown when reply exists) -->
          <?php if (!empty($r['owner_reply'])): ?>
          <button id="edit-btn-<?= $r['id'] ?>"
                  onclick="toggleEditReply(<?= $r['id'] ?>)"
                  style="font-size:0.78rem;color:var(--green);background:transparent;border:none;cursor:pointer;padding:0;margin-top:0.1rem;">
            ✏️ <?= t('Edit reply','Modifier la réponse') ?>
          </button>
          <?php else: ?>
          <!-- no edit button needed — form is already visible -->
          <?php endif; ?>
          <?php endif; /* approved */ ?>

        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div><!-- /container -->

<script>
const CSRF   = '<?= csrf() ?>';
const API    = '<?= SITE_URL ?>/api/review-actions.php';

function submitReply(rid) {
    const ta  = document.getElementById('reply-textarea-' + rid);
    const reply = ta.value.trim();
    const btn = ta.nextElementSibling.querySelector('button');
    btn.disabled = true;
    btn.textContent = '…';

    const fd = new FormData();
    fd.append('csrf',      CSRF);
    fd.append('action',    'reply');
    fd.append('review_id', rid);
    fd.append('reply',     reply);

    fetch(API, { method:'POST', body:fd })
        .then(r => r.json())
        .then(data => {
            if (!data.ok) { alert(data.error); btn.disabled = false; btn.textContent = '<?= t('Post Reply','Répondre') ?>'; return; }
            const display = document.getElementById('reply-display-' + rid);
            const text    = document.getElementById('reply-text-'    + rid);
            if (data.cleared) {
                display.style.display = 'none';
            } else {
                text.textContent = reply;
                display.style.display = 'block';
                document.getElementById('reply-form-' + rid).style.display = 'none';
                // Show edit button
                const editBtn = document.getElementById('edit-btn-' + rid);
                if (!editBtn) {
                    const eb = document.createElement('button');
                    eb.id = 'edit-btn-' + rid;
                    eb.onclick = () => toggleEditReply(rid);
                    eb.style = 'font-size:0.78rem;color:var(--green);background:transparent;border:none;cursor:pointer;padding:0;margin-top:0.1rem;';
                    eb.textContent = '✏️ <?= t('Edit reply','Modifier la réponse') ?>';
                    document.getElementById('reply-form-' + rid).after(eb);
                }
            }
        })
        .catch(() => { btn.disabled = false; btn.textContent = '<?= t('Post Reply','Répondre') ?>'; });
}

function clearReply(rid) {
    if (!confirm('<?= t('Delete this reply?','Supprimer cette réponse ?') ?>')) return;
    document.getElementById('reply-textarea-' + rid).value = '';
    submitReply(rid);
}

function toggleEditReply(rid) {
    const form    = document.getElementById('reply-form-' + rid);
    const display = document.getElementById('reply-display-' + rid);
    const editBtn = document.getElementById('edit-btn-' + rid);
    const open = (form.style.display === 'none' || form.style.display === '');
    form.style.display    = open ? 'block'    : 'none';
    if (display) display.style.display = open ? 'none' : 'block';
    if (editBtn) editBtn.textContent    = open
        ? '✕ <?= t('Cancel','Annuler') ?>'
        : '✏️ <?= t('Edit reply','Modifier la réponse') ?>';
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
