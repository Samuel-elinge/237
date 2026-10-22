<?php
/**
 * admin/offers.php — Admin Moderation & Management for Marketplace Offers
 * v2: adds scheduled, paused, archived, suspended_admin statuses and actions
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/ops-admin-helpers.php';
require_once __DIR__ . '/../includes/notify.php';
requireAnyAdmin();

$isFr = lang() === 'fr';
$pdo  = db();

// ── Handle POST actions ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action']  ?? '';
    $oid    = (int)($_POST['offer_id'] ?? 0);
    $note   = trim($_POST['admin_notes'] ?? '');

    // Fetch offer + business owner for notifications before any status change
    $offerForNotif = null;
    if ($oid && in_array($action, ['approve','reject','suspend','unsuspend'])) {
        try {
            $nSt = $pdo->prepare("
                SELECT o.title, o.title_fr, o.slug, l.user_id AS owner_id
                FROM offers o JOIN listings l ON l.id = o.listing_id
                WHERE o.id = ?
            ");
            $nSt->execute([$oid]);
            $offerForNotif = $nSt->fetch();
        } catch (Exception $e) {}
    }

    if ($oid) {
        switch ($action) {

            case 'approve':
                // Pending → active (or scheduled if starts_at is future)
                $pdo->prepare("
                    UPDATE offers
                    SET status = CASE
                            WHEN starts_at IS NOT NULL AND starts_at > NOW() THEN 'scheduled'
                            ELSE 'active'
                        END,
                        admin_notes = ?, updated_at = NOW()
                    WHERE id = ? AND status = 'pending'
                ")->execute([$note ?: null, $oid]);
                flash('success', t('Offer approved.', 'Offre approuvée.'));
                // Notify business owner
                if ($offerForNotif && $offerForNotif['owner_id']) {
                    $oTitle = $offerForNotif['title'];
                    $oTitleFr = $offerForNotif['title_fr'] ?: $oTitle;
                    $oLink  = SITE_URL . '/partner/offers';
                    dispatch_notification(
                        (int)$offerForNotif['owner_id'], 'bell',
                        '✅ Offer Approved', 'Offre approuvée',
                        "Your offer \"{$oTitle}\" has been approved and is now live.",
                        "Votre offre \"{$oTitleFr}\" a été approuvée et est maintenant en ligne.",
                        $oLink, 'offer', $oid, null
                    );
                    dispatch_notification(
                        (int)$offerForNotif['owner_id'], 'email',
                        "✅ Your Offer Has Been Approved — {$oTitle}",
                        "✅ Votre Offre a Été Approuvée — {$oTitleFr}",
                        "Great news! Your offer <strong>\"{$oTitle}\"</strong> has been reviewed and approved. It is now visible to customers on 237Biz.",
                        "Bonne nouvelle ! Votre offre <strong>\"{$oTitleFr}\"</strong> a été examinée et approuvée. Elle est maintenant visible par les clients sur 237Biz.",
                        $oLink, 'offer', $oid, null
                    );
                }
                break;

            case 'reject':
                if (!$note) { flash('error', t('Please add a rejection reason.', 'Veuillez ajouter un motif de rejet.')); break; }
                $pdo->prepare("
                    UPDATE offers SET status='rejected', admin_notes=?, updated_at=NOW()
                    WHERE id=? AND status='pending'
                ")->execute([$note, $oid]);
                flash('success', t('Offer rejected.', 'Offre rejetée.'));
                // Notify business owner with rejection reason
                if ($offerForNotif && $offerForNotif['owner_id']) {
                    $oTitle = $offerForNotif['title'];
                    $oTitleFr = $offerForNotif['title_fr'] ?: $oTitle;
                    $oLink  = SITE_URL . '/partner/offers';
                    dispatch_notification(
                        (int)$offerForNotif['owner_id'], 'bell',
                        '❌ Offer Not Approved', 'Offre non approuvée',
                        "Your offer \"{$oTitle}\" was not approved. Reason: {$note}",
                        "Votre offre \"{$oTitleFr}\" n'a pas été approuvée. Motif : {$note}",
                        $oLink, 'offer', $oid, null
                    );
                    dispatch_notification(
                        (int)$offerForNotif['owner_id'], 'email',
                        "❌ Offer Update — {$oTitle}",
                        "❌ Mise à Jour Offre — {$oTitleFr}",
                        "Unfortunately, your offer <strong>\"{$oTitle}\"</strong> was not approved for the following reason:<br><br><em>{$note}</em><br><br>Please review your offer and resubmit if appropriate.",
                        "Malheureusement, votre offre <strong>\"{$oTitleFr}\"</strong> n'a pas été approuvée pour la raison suivante :<br><br><em>{$note}</em><br><br>Veuillez réviser votre offre et la resoumettre si nécessaire.",
                        $oLink, 'offer', $oid, null
                    );
                }
                break;

            case 'suspend':
                $pdo->prepare("
                    UPDATE offers SET status='suspended_admin', admin_notes=?, updated_at=NOW()
                    WHERE id=? AND status NOT IN ('archived','rejected')
                ")->execute([$note ?: null, $oid]);
                flash('success', t('Offer suspended.', 'Offre suspendue.'));
                // Notify business owner
                if ($offerForNotif && $offerForNotif['owner_id'] && $note) {
                    $oTitle = $offerForNotif['title'];
                    $oTitleFr = $offerForNotif['title_fr'] ?: $oTitle;
                    $oLink  = SITE_URL . '/partner/offers';
                    dispatch_notification(
                        (int)$offerForNotif['owner_id'], 'bell',
                        '🚫 Offer Suspended', 'Offre suspendue',
                        "Your offer \"{$oTitle}\" has been suspended. Reason: {$note}",
                        "Votre offre \"{$oTitleFr}\" a été suspendue. Motif : {$note}",
                        $oLink, 'offer', $oid, null
                    );
                }
                break;

            case 'unsuspend':
                // Restore to active (or scheduled if future-dated)
                $pdo->prepare("
                    UPDATE offers
                    SET status = CASE
                            WHEN starts_at IS NOT NULL AND starts_at > NOW() THEN 'scheduled'
                            ELSE 'active'
                        END,
                        admin_notes = NULL, updated_at = NOW()
                    WHERE id=? AND status='suspended_admin'
                ")->execute([$oid]);
                flash('success', t('Offer reinstated.', 'Offre rétablie.'));
                break;

            case 'expire':
                $pdo->prepare("
                    UPDATE offers SET status='expired', updated_at=NOW()
                    WHERE id=? AND status IN ('active','scheduled','paused')
                ")->execute([$oid]);
                flash('success', t('Offer expired.', 'Offre expirée.'));
                break;

            case 'reactivate':
                $pdo->prepare("
                    UPDATE offers SET status='active', updated_at=NOW()
                    WHERE id=? AND status='expired'
                ")->execute([$oid]);
                flash('success', t('Offer reactivated.', 'Offre réactivée.'));
                break;

            case 'archive':
                $pdo->prepare("
                    UPDATE offers SET status='archived', updated_at=NOW()
                    WHERE id=? AND status NOT IN ('pending','archived')
                ")->execute([$oid]);
                flash('success', t('Offer archived.', 'Offre archivée.'));
                break;

            case 'delete':
                $pdo->prepare("DELETE FROM offers WHERE id=?")->execute([$oid]);
                flash('success', t('Offer deleted.', 'Offre supprimée.'));
                break;
        }

        // Audit log
        try {
            $pdo->prepare("
                INSERT INTO offer_audit_logs
                    (actor_id, actor_role, action, entity_type, entity_id, notes, created_at)
                VALUES (?, 'admin', ?, 'offer', ?, ?, NOW())
            ")->execute([$_SESSION['user_id'] ?? null, "admin.$action", $oid, $note ?: null]);
        } catch (Exception $e) { /* table may not exist on first run */ }
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

$allStatuses = ['pending','scheduled','active','paused','suspended_admin','rejected','expired','archived','all'];
if (!in_array($filterStatus, $allStatuses)) $filterStatus = 'pending';

// ── Build query ───────────────────────────────────────────────────────────
$where  = [];
$params = [];

if ($filterStatus !== 'all') {
    $where[]  = 'o.status = ?';
    $params[] = $filterStatus;
}
if ($search) {
    $where[]  = '(o.title LIKE ? OR o.title_fr LIKE ? OR l.title LIKE ?)';
    $s = '%' . $search . '%';
    array_push($params, $s, $s, $s);
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

// Fetch — join discount_codes to show shared code
$offersSt = $pdo->prepare("
    SELECT o.*,
           l.title AS biz_name, l.slug AS biz_slug,
           u.name AS owner_name, u.email AS owner_email,
           c.name_en AS cat_en, loc.name_en AS loc_en,
           (SELECT dc.code FROM discount_codes dc
            WHERE dc.offer_id = o.id AND dc.code_type = 'shared' LIMIT 1) AS shared_code
    FROM offers o
    JOIN listings  l   ON l.id  = o.listing_id
    JOIN users     u   ON u.id  = l.user_id
    JOIN categories c  ON c.id  = l.category_id
    JOIN locations  loc ON loc.id = l.location_id
    $whereClause
    ORDER BY
        FIELD(o.status,'pending','suspended_admin','active','scheduled','paused','rejected','expired','archived'),
        o.created_at DESC
    LIMIT $perPage OFFSET " . (($page - 1) * $perPage)
);
$offersSt->execute($params);
$offers = $offersSt->fetchAll();

// Tab counts
$tabStatuses = ['pending','active','scheduled','paused','suspended_admin','rejected','expired','archived','all'];
$tabs = [];
foreach ($tabStatuses as $s) {
    $cSt = $pdo->prepare(
        "SELECT COUNT(*) FROM offers o JOIN listings l ON l.id=o.listing_id" .
        ($s !== 'all' ? " WHERE o.status=?" : "")
    );
    $cSt->execute($s !== 'all' ? [$s] : []);
    $tabs[$s] = (int)$cSt->fetchColumn();
}

// ── Display helpers ───────────────────────────────────────────────────────
$statusColors = [
    'pending'         => '#f59e0b',
    'scheduled'       => '#60a5fa',
    'active'          => '#4ade80',
    'paused'          => '#fbbf24',
    'suspended_admin' => '#dc2626',
    'rejected'        => '#f87171',
    'expired'         => '#6b7280',
    'archived'        => '#4b5563',
    'draft'           => '#8db8a8',
];
$statusLabels = [
    'pending'         => t('Pending',   'En attente'),
    'scheduled'       => t('Scheduled', 'Planifiée'),
    'active'          => t('Active',    'Actif'),
    'paused'          => t('Paused',    'En pause'),
    'suspended_admin' => t('Suspended', 'Suspendue'),
    'rejected'        => t('Rejected',  'Rejeté'),
    'expired'         => t('Expired',   'Expiré'),
    'archived'        => t('Archived',  'Archivée'),
    'draft'           => t('Draft',     'Brouillon'),
];
$typeLabels = [
    'percent_off' => t('% Off',      '% Rabais'),
    'fixed_off'   => t('XAF Off',    'XAF Rabais'),
    'fixed_price' => t('Fixed Price','Prix fixe'),
    'bogo'        => 'BOGO',
    'free_item'   => t('Free Item',  'Article gratuit'),
    'bundle'      => t('Bundle',     'Pack'),
];

// Tabs shown in UI (only if count > 0 or it's the selected one, except always show pending+active+all)
$tabNames = [
    'pending'         => t('Pending',    'En attente'),
    'active'          => t('Active',     'Actif'),
    'scheduled'       => t('Scheduled',  'Planifiées'),
    'paused'          => t('Paused',     'En pause'),
    'suspended_admin' => t('Suspended',  'Suspendues'),
    'rejected'        => t('Rejected',   'Rejetées'),
    'expired'         => t('Expired',    'Expirées'),
    'archived'        => t('Archived',   'Archivées'),
    'all'             => t('All',        'Tout'),
];

$pageTitle = t('Offer Management', 'Gestion des Offres') . ' — Admin';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/includes/nav.php';
adminNav(''); adminNavAssets();
?>
<style>
:root { --ao-accent:#00A878; --ao-muted:#5a8878; --ao-border:rgba(0,168,120,0.15); --ao-card:rgba(0,0,0,0.25); }

.ao-wrap { max-width:1160px; margin:0 auto; padding:1.5rem 1rem 3rem; }
.ao-page-title { font-family:'Fraunces',serif; font-weight:900; font-size:1.7rem; color:#f0faf5; margin-bottom:1.25rem; }

/* Tabs */
.ao-tabs { display:flex; gap:0.3rem; flex-wrap:wrap; margin-bottom:1.25rem; }
.ao-tab  { padding:0.4rem 0.9rem; border-radius:8px; font-size:0.78rem; font-weight:700;
           text-decoration:none; color:#8db8a8; background:rgba(255,255,255,0.03);
           border:1px solid var(--ao-border); transition:all 0.15s; }
.ao-tab:hover  { border-color:rgba(0,168,120,0.35); color:#e8f5ee; }
.ao-tab.active { background:var(--ao-accent); color:#fff; border-color:var(--ao-accent); }
.ao-badge { background:rgba(0,0,0,0.25); padding:0 0.4rem; border-radius:99px; font-size:0.68rem; margin-left:0.25rem; }
.ao-tab.active .ao-badge { background:rgba(255,255,255,0.2); }
.ao-tab.has-alert { border-color:rgba(220,38,38,0.4); }

/* Search */
.ao-toolbar { display:flex; gap:0.75rem; margin-bottom:1rem; flex-wrap:wrap; }
.ao-search { flex:1; min-width:180px; max-width:360px; background:var(--ao-card);
             border:1px solid rgba(0,168,120,0.2); color:#e8f5ee; border-radius:8px;
             padding:0.5rem 0.75rem; font-size:0.88rem; }
.ao-search:focus { outline:none; border-color:rgba(0,168,120,0.45); }
.ao-sbtn { background:var(--ao-accent); color:#fff; border:none; border-radius:8px;
           padding:0.5rem 1rem; font-weight:700; font-size:0.85rem; cursor:pointer; }

/* Table */
.ao-table-wrap { overflow-x:auto; }
.ao-table { width:100%; border-collapse:collapse; font-size:0.81rem; }
.ao-table th { text-align:left; color:var(--ao-muted); font-weight:700; letter-spacing:0.04em;
               font-size:0.7rem; border-bottom:1px solid var(--ao-border); padding:0.5rem 0.65rem; }
.ao-table td { padding:0.7rem 0.65rem; border-bottom:1px solid rgba(0,168,120,0.06); vertical-align:top; }
.ao-table tr:hover td { background:rgba(0,168,120,0.025); }
.ao-pill  { display:inline-block; padding:0.15rem 0.5rem; border-radius:99px;
            font-size:0.68rem; font-weight:700; color:#fff; white-space:nowrap; }
.ao-type-pill { background:rgba(0,168,120,0.15); color:#00A878; font-size:0.68rem;
                padding:0.15rem 0.45rem; border-radius:4px; font-weight:700; }
.ao-biz-link { color:#00A878; text-decoration:none; font-weight:700; }
.ao-biz-link:hover { text-decoration:underline; }
.ao-note { font-size:0.7rem; color:#fbbf24; margin-top:0.2rem; }
.ao-susp-note { font-size:0.7rem; color:#f87171; margin-top:0.2rem; }
.ao-code { font-family:monospace; color:#00A878; font-size:0.74rem; margin-top:0.15rem; }

/* Action area */
.ao-actions { display:flex; gap:0.35rem; flex-wrap:wrap; align-items:flex-start; }
.ao-btn { padding:0.22rem 0.6rem; border-radius:5px; font-size:0.73rem; font-weight:700;
          cursor:pointer; border:1px solid transparent; text-decoration:none;
          display:inline-flex; align-items:center; gap:0.2rem; background:none; }
.ao-btn-approve  { background:#4ade80; color:#052e16; border-color:#4ade80; }
.ao-btn-reject   { border-color:rgba(248,113,113,0.4); color:#f87171; }
.ao-btn-suspend  { border-color:rgba(220,38,38,0.35); color:#f87171; }
.ao-btn-unsuspend{ background:rgba(96,165,250,0.15); border-color:rgba(96,165,250,0.4); color:#93c5fd; }
.ao-btn-expire   { border-color:rgba(107,114,128,0.4); color:#9ca3af; }
.ao-btn-archive  { border-color:rgba(75,85,99,0.5); color:#9ca3af; }
.ao-btn-reactive { background:rgba(74,222,128,0.1); border-color:rgba(74,222,128,0.35); color:#4ade80; }
.ao-btn-view     { background:rgba(0,168,120,0.08); color:#00A878; border-color:rgba(0,168,120,0.2); }
.ao-btn-danger   { border-color:rgba(248,113,113,0.2); color:#f87171; }

/* Mod row */
.ao-mod-row td { padding:0.5rem 0.65rem; background:rgba(251,191,36,0.03); }
.ao-mod-inner  { display:flex; gap:0.65rem; align-items:flex-start; flex-wrap:wrap; }
.ao-mod-textarea { flex:1; min-width:200px; background:rgba(0,0,0,0.3);
                   border:1px solid rgba(0,168,120,0.2); color:#e8f5ee;
                   border-radius:6px; padding:0.45rem; font-size:0.8rem;
                   resize:vertical; min-height:55px; }
.ao-suspend-row td { background:rgba(220,38,38,0.04); }

/* Empty / pagination */
.ao-empty { text-align:center; padding:2.5rem 1rem; color:var(--ao-muted); }
.ao-pages { display:flex; gap:0.35rem; justify-content:center; margin-top:1.25rem; flex-wrap:wrap; }
.ao-pages a, .ao-pages span {
  padding:0.3rem 0.65rem; border-radius:6px; font-size:0.8rem; text-decoration:none;
  background:var(--ao-card); border:1px solid var(--ao-border); color:#8db8a8;
}
.ao-pages a:hover { border-color:rgba(0,168,120,0.4); color:#e8f5ee; }
.ao-pages .cur    { background:var(--ao-accent); color:#fff; border-color:var(--ao-accent); }
</style>

<?php flash('display'); ?>

<div class="ao-wrap">
  <h1 class="ao-page-title">🏷️ <?= t('Offer Management', 'Gestion des Offres') ?></h1>

  <!-- Status tabs -->
  <div class="ao-tabs">
    <?php foreach ($tabNames as $ts => $tn):
      // Always show pending, active, suspended_admin, all; others only if count > 0 or selected
      if (!in_array($ts, ['pending','active','suspended_admin','all']) && $tabs[$ts] === 0 && $filterStatus !== $ts) continue;
      $href = '?status='.$ts.($search ? '&q='.urlencode($search) : '');
      $cls  = 'ao-tab' . ($filterStatus === $ts ? ' active' : '') . ($ts === 'suspended_admin' && $tabs[$ts] > 0 ? ' has-alert' : '');
    ?>
      <a href="<?= $href ?>" class="<?= $cls ?>">
        <?= $tn ?><span class="ao-badge"><?= $tabs[$ts] ?></span>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- Search -->
  <form method="GET" class="ao-toolbar">
    <input type="hidden" name="status" value="<?= e($filterStatus) ?>">
    <input type="text" name="q" value="<?= e($search) ?>" class="ao-search"
           placeholder="<?= t('Search title or business…', 'Rechercher titre ou entreprise…') ?>">
    <button type="submit" class="ao-sbtn"><?= t('Search', 'Chercher') ?></button>
    <?php if ($search): ?>
      <a href="?status=<?= $filterStatus ?>" class="ao-sbtn" style="background:transparent;border:1px solid var(--ao-border);color:#8db8a8;">×</a>
    <?php endif; ?>
  </form>

  <p style="font-size:0.76rem;color:var(--ao-muted);margin-bottom:0.75rem;">
    <?= $total ?> <?= t('offers', 'offres') ?>
    <?php if ($pages > 1): ?> — <?= t('page','page') ?> <?= $page ?>/<?= $pages ?><?php endif; ?>
  </p>

  <!-- Table -->
  <div class="ao-table-wrap">
    <table class="ao-table">
      <thead>
        <tr>
          <th><?= t('OFFER', 'OFFRE') ?></th>
          <th><?= t('BUSINESS', 'ENTREPRISE') ?></th>
          <th><?= t('TYPE / VALUE', 'TYPE / VALEUR') ?></th>
          <th><?= t('DATES', 'DATES') ?></th>
          <th><?= t('STATUS', 'STATUT') ?></th>
          <th><?= t('ACTIONS', 'ACTIONS') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$offers): ?>
          <tr><td colspan="6" class="ao-empty"><?= t('No offers match the current filter.', 'Aucune offre ne correspond au filtre actuel.') ?></td></tr>
        <?php endif; ?>

        <?php foreach ($offers as $o):
          $otitle    = $isFr && $o['title_fr'] ? $o['title_fr'] : $o['title'];
          $color     = $statusColors[$o['status']] ?? '#8db8a8';
          $lbl       = $statusLabels[$o['status']]  ?? $o['status'];
          $typeKey   = $o['discount_type'] ?? $o['offer_type'] ?? '';
          $tLbl      = $typeLabels[$typeKey] ?? $typeKey;
          $isSusp    = $o['status'] === 'suspended_admin';
        ?>
          <tr>
            <!-- Offer -->
            <td>
              <strong style="color:#e8f5ee;"><?= e($otitle) ?></strong>
              <?php if ($o['shared_code'] ?? null): ?>
                <div class="ao-code">🏷 <?= e($o['shared_code']) ?></div>
              <?php elseif ($o['promo_code'] ?? null): ?>
                <div class="ao-code">🏷 <?= e($o['promo_code']) ?></div>
              <?php endif; ?>
              <?php if ($o['admin_notes'] && $o['status'] === 'rejected'): ?>
                <div class="ao-note">⚠️ <?= e($o['admin_notes']) ?></div>
              <?php endif; ?>
              <?php if ($isSusp && $o['admin_notes']): ?>
                <div class="ao-susp-note">🚫 <?= e($o['admin_notes']) ?></div>
              <?php endif; ?>
              <?php if ($o['code_type'] ?? null): ?>
                <div style="font-size:0.67rem;color:#5a8878;margin-top:0.15rem;">
                  <?= $o['code_type'] === 'shared' ? '🔗 Shared code' : '🎫 Unique codes' ?>
                </div>
              <?php endif; ?>
              <div style="font-size:0.67rem;color:#5a8878;margin-top:0.1rem;">ID #<?= $o['id'] ?></div>
            </td>
            <!-- Business -->
            <td>
              <a href="<?= SITE_URL ?>/listing/<?= e($o['biz_slug']) ?>" target="_blank" class="ao-biz-link">
                <?= e($o['biz_name']) ?>
              </a>
              <div style="font-size:0.7rem;color:#5a8878;margin-top:0.15rem;">
                <?= e($o['owner_name']) ?><br>
                <span style="opacity:0.7;"><?= e($o['cat_en']) ?> · <?= e($o['loc_en']) ?></span>
              </div>
            </td>
            <!-- Type / value -->
            <td>
              <?php if ($tLbl): ?><span class="ao-type-pill"><?= e($tLbl) ?></span><?php endif; ?>
              <?php if ($o['discount_value']): ?>
                <div style="font-size:0.74rem;color:#e8f5ee;margin-top:0.2rem;">
                  <?= $typeKey === 'percent_off'
                      ? e('-' . (int)$o['discount_value'] . '%')
                      : e(number_format($o['discount_value']) . ' XAF') ?>
                </div>
              <?php endif; ?>
              <?php if ($o['min_spend'] ?? null): ?>
                <div style="font-size:0.67rem;color:#5a8878;">Min: <?= e(number_format($o['min_spend'])) ?> XAF</div>
              <?php endif; ?>
              <?php if ($o['redemption_count'] ?? null): ?>
                <div style="font-size:0.67rem;color:#5a8878;margin-top:0.1rem;">🎫 <?= (int)$o['redemption_count'] ?> <?= t('used','utilisé') ?></div>
              <?php endif; ?>
            </td>
            <!-- Dates -->
            <td style="font-size:0.73rem;color:#8db8a8;white-space:nowrap;">
              <?= t('Start:','Début :') ?> <?= $o['starts_at'] ? date('d M Y', strtotime($o['starts_at'])) : '—' ?><br>
              <?= $o['expires_at']
                  ? t('Exp:','Exp :').' '.date('d M Y', strtotime($o['expires_at']))
                  : '<span style="opacity:0.5;">'.t('No expiry','Sans expiration').'</span>' ?>
            </td>
            <!-- Status -->
            <td>
              <span class="ao-pill" style="background:<?= $color ?>;"><?= $lbl ?></span>
            </td>
            <!-- Actions -->
            <td>
              <div class="ao-actions">

                <?php if (in_array($o['status'], ['active','scheduled'])): ?>
                  <a href="<?= SITE_URL ?>/offer/<?= e($o['slug']) ?>" target="_blank" class="ao-btn ao-btn-view">👁</a>
                <?php endif; ?>

                <?php if ($o['status'] === 'pending'): ?>
                  <form method="POST" style="display:inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action"   value="approve">
                    <input type="hidden" name="offer_id" value="<?= $o['id'] ?>">
                    <button class="ao-btn ao-btn-approve">✓ <?= t('Approve','Approuver') ?></button>
                  </form>
                  <button class="ao-btn ao-btn-reject" onclick="toggleRow('mod',<?= $o['id'] ?>)">
                    ✗ <?= t('Reject…','Rejeter…') ?>
                  </button>
                <?php endif; ?>

                <?php if ($o['status'] === 'suspended_admin'): ?>
                  <form method="POST" style="display:inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action"   value="unsuspend">
                    <input type="hidden" name="offer_id" value="<?= $o['id'] ?>">
                    <button class="ao-btn ao-btn-unsuspend">↑ <?= t('Reinstate','Rétablir') ?></button>
                  </form>
                <?php elseif (!in_array($o['status'], ['rejected','archived','expired'])): ?>
                  <button class="ao-btn ao-btn-suspend" onclick="toggleRow('susp',<?= $o['id'] ?>)">
                    🚫 <?= t('Suspend…','Suspendre…') ?>
                  </button>
                <?php endif; ?>

                <?php if (in_array($o['status'], ['active','scheduled','paused'])): ?>
                  <form method="POST" style="display:inline" onsubmit="return confirm('<?= t('Expire this offer?','Expirer cette offre ?') ?>')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action"   value="expire">
                    <input type="hidden" name="offer_id" value="<?= $o['id'] ?>">
                    <button class="ao-btn ao-btn-expire"><?= t('Expire','Expirer') ?></button>
                  </form>
                <?php endif; ?>

                <?php if ($o['status'] === 'expired'): ?>
                  <form method="POST" style="display:inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action"   value="reactivate">
                    <input type="hidden" name="offer_id" value="<?= $o['id'] ?>">
                    <button class="ao-btn ao-btn-reactive"><?= t('Reactivate','Réactiver') ?></button>
                  </form>
                <?php endif; ?>

                <?php if (!in_array($o['status'], ['pending','archived'])): ?>
                  <form method="POST" style="display:inline" onsubmit="return confirm('<?= t('Archive this offer?','Archiver cette offre ?') ?>')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action"   value="archive">
                    <input type="hidden" name="offer_id" value="<?= $o['id'] ?>">
                    <button class="ao-btn ao-btn-archive">📦</button>
                  </form>
                <?php endif; ?>

                <form method="POST" style="display:inline" onsubmit="return confirm('<?= t('Permanently delete?','Supprimer définitivement ?') ?>')">
                  <?= csrfField() ?>
                  <input type="hidden" name="action"   value="delete">
                  <input type="hidden" name="offer_id" value="<?= $o['id'] ?>">
                  <button class="ao-btn ao-btn-danger">🗑</button>
                </form>

              </div>
            </td>
          </tr>

          <!-- Reject form row -->
          <?php if ($o['status'] === 'pending'): ?>
            <tr id="row-mod-<?= $o['id'] ?>" style="display:none">
              <td colspan="6">
                <form method="POST" class="ao-mod-inner">
                  <?= csrfField() ?>
                  <input type="hidden" name="action"   value="reject">
                  <input type="hidden" name="offer_id" value="<?= $o['id'] ?>">
                  <textarea name="admin_notes" class="ao-mod-textarea" required
                            placeholder="<?= t('Rejection reason (shown to owner)…','Motif du rejet (visible par le propriétaire)…') ?>"></textarea>
                  <div style="display:flex;flex-direction:column;gap:0.35rem;">
                    <button type="submit" class="ao-btn ao-btn-reject"><?= t('Confirm Rejection','Confirmer Rejet') ?></button>
                    <button type="button" class="ao-btn" style="color:#8db8a8;border-color:rgba(141,184,168,0.2);"
                            onclick="toggleRow('mod',<?= $o['id'] ?>)"><?= t('Cancel','Annuler') ?></button>
                  </div>
                </form>
              </td>
            </tr>
          <?php endif; ?>

          <!-- Suspend form row -->
          <?php if (!in_array($o['status'], ['rejected','archived','expired','suspended_admin'])): ?>
            <tr id="row-susp-<?= $o['id'] ?>" class="ao-suspend-row" style="display:none">
              <td colspan="6">
                <form method="POST" class="ao-mod-inner">
                  <?= csrfField() ?>
                  <input type="hidden" name="action"   value="suspend">
                  <input type="hidden" name="offer_id" value="<?= $o['id'] ?>">
                  <textarea name="admin_notes" class="ao-mod-textarea"
                            placeholder="<?= t('Suspension reason (optional, shown to business)…','Motif de suspension (optionnel, visible par l\'entreprise)…') ?>"></textarea>
                  <div style="display:flex;flex-direction:column;gap:0.35rem;">
                    <button type="submit" class="ao-btn ao-btn-suspend"><?= t('Confirm Suspension','Confirmer Suspension') ?></button>
                    <button type="button" class="ao-btn" style="color:#8db8a8;border-color:rgba(141,184,168,0.2);"
                            onclick="toggleRow('susp',<?= $o['id'] ?>)"><?= t('Cancel','Annuler') ?></button>
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
    <div class="ao-pages">
      <?php for ($p = 1; $p <= $pages; $p++):
        $href = '?status='.urlencode($filterStatus).'&page='.$p.($search ? '&q='.urlencode($search) : '');
        if ($p === $page): ?><span class="cur"><?= $p ?></span>
        <?php else: ?><a href="<?= $href ?>"><?= $p ?></a><?php endif; ?>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>

<script>
function toggleRow(type, id) {
    const row = document.getElementById('row-' + type + '-' + id);
    if (!row) return;
    // close other open rows of same type first
    document.querySelectorAll('[id^="row-' + type + '-"]').forEach(function(r) {
        if (r !== row) r.style.display = 'none';
    });
    row.style.display = row.style.display === 'none' ? '' : 'none';
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
