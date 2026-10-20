<?php
/**
 * admin/offers.php — Admin Moderation Queue for Marketplace Offers
 */
require_once __DIR__ . '/../includes/config.php';
requireAdmin();

$isFr = lang() === 'fr';
$pdo  = db();

// ── Handle actions ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action  = $_POST['action']  ?? '';
    $oid     = (int)($_POST['offer_id'] ?? 0);
    $note    = trim($_POST['admin_notes'] ?? '');

    if ($oid) {
        switch ($action) {
            case 'approve':
                $pdo->prepare("
                    UPDATE offers
                    SET status='active', admin_notes=?, updated_at=NOW()
                    WHERE id=? AND status='pending'
                ")->execute([$note ?: null, $oid]);
                flash('success', t('Offer approved and now live.','Offre approuvée et maintenant en ligne.'));
                break;

            case 'reject':
                if (!$note) { flash('error', t('Please add a rejection reason.','Veuillez ajouter un motif de rejet.')); break; }
                $pdo->prepare("
                    UPDATE offers
                    SET status='rejected', admin_notes=?, updated_at=NOW()
                    WHERE id=? AND status='pending'
                ")->execute([$note, $oid]);
                flash('success', t('Offer rejected.','Offre rejetée.'));
                break;

            case 'expire':
                $pdo->prepare("
                    UPDATE offers SET status='expired', updated_at=NOW() WHERE id=?
                ")->execute([$oid]);
                flash('success', t('Offer expired.','Offre expirée.'));
                break;

            case 'reactivate':
                $pdo->prepare("
                    UPDATE offers SET status='active', updated_at=NOW() WHERE id=? AND status='expired'
                ")->execute([$oid]);
                flash('success', t('Offer reactivated.','Offre réactivée.'));
                break;

            case 'delete':
                $pdo->prepare("DELETE FROM offers WHERE id=?")->execute([$oid]);
                flash('success', t('Offer deleted.','Offre supprimée.'));
                break;
        }
    }
    redirect(SITE_URL . '/admin/offers?' . http_build_query([
        'status' => $_GET['status'] ?? 'pending',
        'page'   => $_GET['page']   ?? 1,
    ]));
}

// ── Filters ───────────────────────────────────────────────────────────────
$filterStatus = $_GET['status'] ?? 'pending';
$search       = trim($_GET['q'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 20;

$validStatuses = ['pending','active','expired','rejected','all'];
if (!in_array($filterStatus, $validStatuses)) $filterStatus = 'pending';

// ── Build query ───────────────────────────────────────────────────────────
$where  = [];
$params = [];

if ($filterStatus !== 'all') {
    $where[]  = 'o.status = ?';
    $params[] = $filterStatus;
}
if ($search) {
    $where[]  = '(o.title LIKE ? OR o.title_fr LIKE ? OR l.title LIKE ? OR o.promo_code LIKE ?)';
    $s = '%' . $search . '%';
    array_push($params, $s, $s, $s, $s);
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Count
$countSt = $pdo->prepare("
    SELECT COUNT(*) FROM offers o
    JOIN listings l ON l.id = o.listing_id
    $whereClause
");
$countSt->execute($params);
$total = (int)$countSt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page  = min($page, $pages);

// Fetch
$offersSt = $pdo->prepare("
    SELECT o.*, l.title AS biz_name, l.slug AS biz_slug,
           u.name AS owner_name, u.email AS owner_email,
           c.name_en AS cat_en, loc.name_en AS loc_en
    FROM offers o
    JOIN listings  l   ON l.id  = o.listing_id
    JOIN users     u   ON u.id  = l.user_id
    JOIN categories c  ON c.id  = l.category_id
    JOIN locations  loc ON loc.id = l.location_id
    $whereClause
    ORDER BY
        FIELD(o.status,'pending','active','rejected','expired'),
        o.created_at DESC
    LIMIT $perPage OFFSET " . (($page - 1) * $perPage)
);
$offersSt->execute($params);
$offers = $offersSt->fetchAll();

// Count by status for tab badges
$tabs = [];
foreach (['pending','active','rejected','expired','all'] as $s) {
    $cSt = $pdo->prepare("SELECT COUNT(*) FROM offers o JOIN listings l ON l.id=o.listing_id" . ($s !== 'all' ? " WHERE o.status=?" : ""));
    $cSt->execute($s !== 'all' ? [$s] : []);
    $tabs[$s] = (int)$cSt->fetchColumn();
}

// ── Page ──────────────────────────────────────────────────────────────────
$pageTitle = t('Offer Moderation','Modération des Offres') . ' — Admin';
require_once __DIR__ . '/../includes/header.php';

$badgeColor = ['pending'=>'#fbbf24','active'=>'#4ade80','rejected'=>'#f87171','expired'=>'#6b7280'];
$badgeLabel = [
    'pending'  => t('Pending','En attente'),
    'active'   => t('Active','Actif'),
    'rejected' => t('Rejected','Rejeté'),
    'expired'  => t('Expired','Expiré'),
];
$offerTypeLabel = [
    'percent_off' => t('% Off','% Rabais'),
    'fixed_off'   => t('XAF Off','XAF Rabais'),
    'bogo'        => 'BOGO',
    'free_item'   => t('Free Item','Gratuit'),
    'bundle'      => t('Bundle','Pack'),
];
?>
<style>
.ao-wrap { max-width:1100px; margin:2rem auto; padding:0 1rem; }
.ao-page-title { font-family:'Fraunces',serif; font-weight:900; font-size:1.7rem; color:#f0faf5; margin-bottom:1.5rem; }

/* Tabs */
.ao-tabs  { display:flex; gap:0.3rem; flex-wrap:wrap; margin-bottom:1.5rem; }
.ao-tab   { padding:0.45rem 1rem; border-radius:8px; font-size:0.82rem; font-weight:700;
            text-decoration:none; color:#8db8a8; background:rgba(255,255,255,0.03);
            border:1px solid rgba(0,168,120,0.12); transition:all 0.15s; }
.ao-tab:hover  { border-color:rgba(0,168,120,0.35); color:#e8f5ee; }
.ao-tab.active { background:#00A878; color:#fff; border-color:#00A878; }
.ao-badge { background:rgba(0,0,0,0.25); padding:0 0.4rem; border-radius:99px; font-size:0.7rem; margin-left:0.3rem; }
.ao-tab.active .ao-badge { background:rgba(255,255,255,0.2); }

/* Search */
.ao-toolbar { display:flex; gap:0.75rem; margin-bottom:1.25rem; }
.ao-search { flex:1; max-width:340px; background:rgba(0,0,0,0.3); border:1px solid rgba(0,168,120,0.2);
             color:#e8f5ee; border-radius:8px; padding:0.5rem 0.75rem; font-size:0.88rem; }
.ao-search:focus { outline:none; border-color:rgba(0,168,120,0.45); }
.ao-search-btn { background:#00A878; color:#fff; border:none; border-radius:8px; padding:0.5rem 1rem;
                 font-weight:700; font-size:0.85rem; cursor:pointer; }

/* Table */
.ao-table-wrap { overflow-x:auto; }
.ao-table { width:100%; border-collapse:collapse; font-size:0.82rem; }
.ao-table th { text-align:left; color:#5a8878; font-weight:700; letter-spacing:0.04em; font-size:0.72rem;
               border-bottom:1px solid rgba(0,168,120,0.15); padding:0.5rem 0.75rem; }
.ao-table td { padding:0.75rem; border-bottom:1px solid rgba(0,168,120,0.07); vertical-align:top; }
.ao-table tr:hover td { background:rgba(0,168,120,0.03); }
.ao-pill { display:inline-block; padding:0.15rem 0.55rem; border-radius:99px;
           font-size:0.68rem; font-weight:700; color:#fff; }
.ao-type-pill { background:rgba(0,168,120,0.15); color:#00A878; font-size:0.68rem;
                padding:0.15rem 0.5rem; border-radius:4px; font-weight:700; }
.ao-biz-link { color:#00A878; text-decoration:none; font-weight:700; }
.ao-biz-link:hover { text-decoration:underline; }
.ao-note { font-size:0.72rem; color:#fbbf24; margin-top:0.2rem; }

/* Action forms */
.ao-actions { display:flex; gap:0.4rem; flex-wrap:wrap; }
.ao-btn { padding:0.25rem 0.65rem; border-radius:5px; font-size:0.75rem; font-weight:700;
          cursor:pointer; border:none; text-decoration:none; display:inline-block; }
.ao-btn-approve { background:#4ade80; color:#052e16; }
.ao-btn-reject  { background:transparent; border:1px solid rgba(248,113,113,0.4); color:#f87171; }
.ao-btn-expire  { background:transparent; border:1px solid rgba(107,114,128,0.4); color:#9ca3af; }
.ao-btn-view    { background:rgba(0,168,120,0.1); color:#00A878; border:1px solid rgba(0,168,120,0.2); }
.ao-btn-danger  { background:transparent; border:1px solid rgba(248,113,113,0.25); color:#f87171; }

/* Moderation modal */
.ao-mod-row { background:rgba(251,191,36,0.04); }
.ao-mod-row td { padding:0.5rem 0.75rem; }
.ao-mod-inner { display:flex; gap:0.75rem; align-items:flex-start; flex-wrap:wrap; }
.ao-mod-textarea { flex:1; min-width:200px; background:rgba(0,0,0,0.3);
                   border:1px solid rgba(0,168,120,0.2); color:#e8f5ee;
                   border-radius:6px; padding:0.5rem; font-size:0.8rem;
                   resize:vertical; min-height:60px; }

/* Empty / pagination */
.ao-empty { text-align:center; padding:3rem 1rem; color:#5a8878; }
.ao-pagination { display:flex; gap:0.4rem; justify-content:center; margin-top:1.5rem; flex-wrap:wrap; }
.ao-pagination a, .ao-pagination span {
  padding:0.3rem 0.7rem; border-radius:6px; font-size:0.82rem; text-decoration:none;
  background:rgba(255,255,255,0.03); border:1px solid rgba(0,168,120,0.12); color:#8db8a8;
}
.ao-pagination a:hover   { border-color:rgba(0,168,120,0.4); color:#e8f5ee; }
.ao-pagination .current  { background:#00A878; color:#fff; border-color:#00A878; }
</style>

<?php flash('display'); ?>

<div class="ao-wrap">
  <h1 class="ao-page-title">🏷️ <?= t('Offer Moderation','Modération des Offres') ?></h1>

  <!-- Status tabs -->
  <div class="ao-tabs">
    <?php
    $tabNames = [
        'pending'  => t('Pending','En attente'),
        'active'   => t('Active','Actif'),
        'rejected' => t('Rejected','Rejeté'),
        'expired'  => t('Expired','Expiré'),
        'all'      => t('All','Tout'),
    ];
    foreach ($tabNames as $ts => $tn):
      $href = '?status='.$ts.($search ? '&q='.urlencode($search) : '');
      $cls  = $filterStatus === $ts ? 'ao-tab active' : 'ao-tab';
    ?>
      <a href="<?= $href ?>" class="<?= $cls ?>">
        <?= $tn ?>
        <span class="ao-badge"><?= $tabs[$ts] ?></span>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- Search bar -->
  <form method="GET" action="" class="ao-toolbar">
    <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus, ENT_QUOTES, 'UTF-8') ?>">
    <input type="text" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
           class="ao-search" placeholder="<?= t('Search title, business, promo code…','Rechercher titre, entreprise, code…') ?>">
    <button type="submit" class="ao-search-btn"><?= t('Search','Chercher') ?></button>
    <?php if ($search): ?><a href="?status=<?= $filterStatus ?>" class="ao-search-btn" style="background:transparent;border:1px solid rgba(0,168,120,0.3);color:#8db8a8;">×</a><?php endif; ?>
  </form>

  <!-- Result count -->
  <p style="font-size:0.78rem;color:#5a8878;margin-bottom:0.75rem;">
    <?= $total ?> <?= t('offers found','offres trouvées') ?>
    <?php if ($pages > 1): ?> &mdash; <?= t('page','page') ?> <?= $page ?>/<?= $pages ?><?php endif; ?>
  </p>

  <!-- Table -->
  <div class="ao-table-wrap">
    <table class="ao-table">
      <thead>
        <tr>
          <th><?= t('OFFER','OFFRE') ?></th>
          <th><?= t('BUSINESS','ENTREPRISE') ?></th>
          <th><?= t('TYPE / VALUE','TYPE / VALEUR') ?></th>
          <th><?= t('DATES','DATES') ?></th>
          <th><?= t('STATUS','STATUT') ?></th>
          <th><?= t('ACTIONS','ACTIONS') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$offers): ?>
          <tr><td colspan="6" class="ao-empty"><?= t('No offers match the current filter.','Aucune offre ne correspond au filtre actuel.') ?></td></tr>
        <?php endif; ?>

        <?php foreach ($offers as $o):
          $otitle = $isFr && $o['title_fr'] ? $o['title_fr'] : $o['title'];
          $color  = $badgeColor[$o['status']] ?? '#8db8a8';
          $lbl    = $badgeLabel[$o['status']]  ?? $o['status'];
          $tLbl   = $offerTypeLabel[$o['offer_type']] ?? $o['offer_type'];
        ?>
          <tr>
            <!-- Offer -->
            <td>
              <strong style="color:#e8f5ee;"><?= htmlspecialchars($otitle, ENT_QUOTES, 'UTF-8') ?></strong>
              <?php if ($o['promo_code']): ?>
                <div style="font-family:monospace;color:#00A878;font-size:0.75rem;margin-top:0.15rem;"><?= e($o['promo_code']) ?></div>
              <?php endif; ?>
              <?php if ($o['admin_notes'] && $o['status'] === 'rejected'): ?>
                <div class="ao-note">⚠️ <?= htmlspecialchars($o['admin_notes'], ENT_QUOTES, 'UTF-8') ?></div>
              <?php endif; ?>
              <div style="font-size:0.7rem;color:#5a8878;margin-top:0.2rem;">ID #<?= $o['id'] ?></div>
            </td>
            <!-- Business -->
            <td>
              <a href="<?= SITE_URL ?>/listing/<?= htmlspecialchars($o['biz_slug'], ENT_QUOTES, 'UTF-8') ?>"
                 target="_blank" class="ao-biz-link">
                <?= htmlspecialchars($o['biz_name'], ENT_QUOTES, 'UTF-8') ?>
              </a>
              <div style="font-size:0.72rem;color:#5a8878;">
                <?= htmlspecialchars($o['owner_name'], ENT_QUOTES, 'UTF-8') ?><br>
                <?= htmlspecialchars($o['cat_en'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($o['loc_en'], ENT_QUOTES, 'UTF-8') ?>
              </div>
            </td>
            <!-- Type / value -->
            <td>
              <span class="ao-type-pill"><?= $tLbl ?></span>
              <?php if ($o['discount_value']): ?>
                <div style="font-size:0.75rem;color:#e8f5ee;margin-top:0.2rem;">
                  <?= $o['offer_type'] === 'percent_off'
                      ? e('-'.(int)$o['discount_value'].'%')
                      : e(number_format($o['discount_value']).' XAF') ?>
                </div>
              <?php endif; ?>
              <?php if ($o['redemption_count']): ?>
                <div style="font-size:0.68rem;color:#5a8878;">🎫 <?= $o['redemption_count'] ?> <?= t('used','utilisé') ?></div>
              <?php endif; ?>
            </td>
            <!-- Dates -->
            <td style="font-size:0.75rem;color:#8db8a8;">
              <?= t('Start:','Début :') ?> <?= date('d M Y', strtotime($o['starts_at'])) ?><br>
              <?= $o['expires_at']
                  ? t('Exp:','Exp :').' '.date('d M Y', strtotime($o['expires_at']))
                  : t('No expiry','Sans expiration') ?>
            </td>
            <!-- Status -->
            <td>
              <span class="ao-pill" style="background:<?= $color ?>;"><?= $lbl ?></span>
            </td>
            <!-- Actions -->
            <td>
              <div class="ao-actions">
                <?php if ($o['status'] === 'active'): ?>
                  <a href="<?= SITE_URL ?>/offer/<?= htmlspecialchars($o['slug'], ENT_QUOTES, 'UTF-8') ?>"
                     target="_blank" class="ao-btn ao-btn-view">👁</a>
                <?php endif; ?>

                <?php if ($o['status'] === 'pending'): ?>
                  <!-- Quick approve (no note needed) -->
                  <form method="POST" action="" style="display:inline">
                    <?= csrf() ?>
                    <input type="hidden" name="action"   value="approve">
                    <input type="hidden" name="offer_id" value="<?= $o['id'] ?>">
                    <button class="ao-btn ao-btn-approve">✓ <?= t('Approve','Approuver') ?></button>
                  </form>
                  <button class="ao-btn ao-btn-reject" onclick="toggleModRow(<?= $o['id'] ?>)">
                    ✗ <?= t('Reject…','Rejeter…') ?>
                  </button>
                <?php elseif ($o['status'] === 'active'): ?>
                  <form method="POST" action="" style="display:inline"
                        onsubmit="return confirm('Expire this offer?')">
                    <?= csrf() ?>
                    <input type="hidden" name="action"   value="expire">
                    <input type="hidden" name="offer_id" value="<?= $o['id'] ?>">
                    <button class="ao-btn ao-btn-expire"><?= t('Expire','Expirer') ?></button>
                  </form>
                <?php elseif ($o['status'] === 'expired'): ?>
                  <form method="POST" action="" style="display:inline">
                    <?= csrf() ?>
                    <input type="hidden" name="action"   value="reactivate">
                    <input type="hidden" name="offer_id" value="<?= $o['id'] ?>">
                    <button class="ao-btn ao-btn-approve"><?= t('Reactivate','Réactiver') ?></button>
                  </form>
                <?php endif; ?>

                <!-- Delete (all statuses) -->
                <form method="POST" action="" style="display:inline"
                      onsubmit="return confirm('<?= t('Permanently delete this offer?','Supprimer définitivement cette offre ?') ?>')">
                  <?= csrf() ?>
                  <input type="hidden" name="action"   value="delete">
                  <input type="hidden" name="offer_id" value="<?= $o['id'] ?>">
                  <button class="ao-btn ao-btn-danger">🗑</button>
                </form>
              </div>
            </td>
          </tr>

          <!-- Reject form (hidden, toggled inline) -->
          <?php if ($o['status'] === 'pending'): ?>
            <tr id="mod-row-<?= $o['id'] ?>" class="ao-mod-row" style="display:none">
              <td colspan="6">
                <form method="POST" action="" class="ao-mod-inner">
                  <?= csrf() ?>
                  <input type="hidden" name="action"   value="reject">
                  <input type="hidden" name="offer_id" value="<?= $o['id'] ?>">
                  <textarea name="admin_notes" class="ao-mod-textarea"
                            placeholder="<?= t('Rejection reason (shown to owner)…','Motif du rejet (visible par le propriétaire)…') ?>"></textarea>
                  <div style="display:flex;flex-direction:column;gap:0.4rem;">
                    <button type="submit" class="ao-btn ao-btn-reject"><?= t('Send Rejection','Envoyer Rejet') ?></button>
                    <button type="button" class="ao-btn" style="color:#8db8a8;border:1px solid rgba(141,184,168,0.2);"
                            onclick="toggleModRow(<?= $o['id'] ?>)"><?= t('Cancel','Annuler') ?></button>
                  </div>
                </form>
              </td>
            </tr>
          <?php endif; ?>

        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($pages > 1): ?>
    <div class="ao-pagination">
      <?php for ($p = 1; $p <= $pages; $p++):
        $href = '?status='.urlencode($filterStatus).'&page='.$p.($search?'&q='.urlencode($search):'');
        if ($p === $page):
      ?><span class="current"><?= $p ?></span>
      <?php else: ?><a href="<?= $href ?>"><?= $p ?></a><?php endif; ?>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>

<script>
function toggleModRow(id) {
    const row = document.getElementById('mod-row-' + id);
    row.style.display = row.style.display === 'none' ? '' : 'none';
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
