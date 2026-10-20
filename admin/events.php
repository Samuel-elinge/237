<?php
/**
 * admin/events.php — Admin: event moderation queue
 * URL: /admin/events
 */
require_once __DIR__ . '/../includes/config.php';
requireAdmin();
$isFr = lang() === 'fr';

// ── Handle actions ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    $eid    = (int)($_POST['event_id'] ?? 0);
    if (!$eid) { redirect(SITE_URL . '/admin/events'); }

    switch ($action) {
        case 'approve':
            db()->prepare("UPDATE events SET status='approved', rejection_note=NULL WHERE id=?")->execute([$eid]);
            flash('success', t('Event approved.','Événement approuvé.'));
            break;
        case 'reject':
            $note = trim($_POST['rejection_note'] ?? '');
            if (!$note) { flash('error', t('Please provide a rejection reason.','Veuillez indiquer un motif de refus.')); break; }
            db()->prepare("UPDATE events SET status='rejected', rejection_note=? WHERE id=?")->execute([$note, $eid]);
            flash('success', t('Event rejected.','Événement refusé.'));
            break;
        case 'cancel':
            db()->prepare("UPDATE events SET status='cancelled' WHERE id=?")->execute([$eid]);
            flash('success', t('Event cancelled.','Événement annulé.'));
            break;
        case 'feature':
            db()->prepare("UPDATE events SET featured=IF(featured,0,1) WHERE id=?")->execute([$eid]);
            flash('success', t('Featured status toggled.','Statut "à la une" modifié.'));
            break;
        case 'delete':
            db()->prepare("DELETE FROM events WHERE id=?")->execute([$eid]);
            flash('success', t('Event deleted.','Événement supprimé.'));
            break;
    }
    redirect(SITE_URL . '/admin/events' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
}

// ── Filters ───────────────────────────────────────────────────────────────
$tab    = in_array($_GET['tab'] ?? '', ['pending','approved','rejected','cancelled','all']) ? $_GET['tab'] : 'pending';
$search = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

// ── Status counts ─────────────────────────────────────────────────────────
try {
    $counts = db()->query("
        SELECT status, COUNT(*) AS n FROM events GROUP BY status
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) { $counts = []; }

// ── Build query ───────────────────────────────────────────────────────────
$where  = [];
$params = [];
if ($tab !== 'all') {
    $where[]  = 'e.status = ?';
    $params[] = $tab;
}
if ($search) {
    $where[]  = '(e.title LIKE ? OR e.title_fr LIKE ? OR l.title LIKE ?)';
    $s = '%' . $search . '%';
    array_push($params, $s, $s, $s);
}
$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

try {
    $cnt = db()->prepare("SELECT COUNT(*) FROM events e LEFT JOIN listings l ON l.id=e.listing_id $whereClause");
    $cnt->execute($params);
    $total = (int)$cnt->fetchColumn();
} catch (Exception $e) { $total = 0; }

$pages = max(1, (int)ceil($total / $perPage));
$page  = min($page, $pages);

try {
    $st = db()->prepare("
        SELECT e.*,
               l.title AS biz_name, l.slug AS biz_slug,
               COUNT(er.id) AS reg_count
        FROM events e
        LEFT JOIN listings l ON l.id = e.listing_id
        LEFT JOIN event_registrations er ON er.event_id = e.id
        $whereClause
        GROUP BY e.id
        ORDER BY FIELD(e.status,'pending','approved','rejected','cancelled') ASC, e.created_at DESC
        LIMIT $perPage OFFSET " . (($page - 1) * $perPage)
    );
    $st->execute($params);
    $events = $st->fetchAll();
} catch (Exception $e) { $events = []; }

$flash = flash();
$pageTitle = t('Events Moderation','Modération des Événements') . ' — Admin';
require_once __DIR__ . '/../includes/header.php';

function adminEvUrl(array $ov = []): string {
    $p = array_merge([
        'tab'  => $_GET['tab']  ?? 'pending',
        'q'    => $_GET['q']    ?? '',
        'page' => $_GET['page'] ?? '',
    ], $ov);
    $p = array_filter($p, fn($v) => $v !== '');
    return SITE_URL . '/admin/events' . ($p ? '?' . http_build_query($p) : '');
}

$typeEmoji = [
    'workshop'=>'🛠','networking'=>'🤝','exhibition'=>'🖼',
    'conference'=>'🎤','social'=>'🎉','other'=>'📌',
];
$statusColour = [
    'pending'   => '#fbbf24',
    'approved'  => '#4ade80',
    'rejected'  => '#ef4444',
    'cancelled' => '#5a8878',
    'ended'     => '#5a8878',
];
?>
<style>
.ae-wrap  { max-width:1100px; margin:0 auto; padding:1.5rem 1rem; }
.ae-h1    { font-family:'Fraunces',serif; font-weight:900; font-size:1.7rem;
  color:#f0faf5; margin-bottom:1.2rem; }

.ae-top-bar { display:flex; flex-wrap:wrap; align-items:center;
  justify-content:space-between; gap:0.75rem; margin-bottom:1rem; }
.ae-search { display:flex; gap:0.4rem; }
.ae-search input {
  background:rgba(0,0,0,0.3); border:1px solid rgba(0,168,120,0.2);
  color:#e8f5ee; border-radius:9px; padding:0.5rem 0.85rem; font-size:0.85rem; width:220px;
}
.ae-search button {
  background:#00A878; color:#fff; border:none; border-radius:9px;
  padding:0.5rem 1rem; font-weight:700; font-size:0.85rem; cursor:pointer;
}

/* Tabs */
.ae-tabs { display:flex; flex-wrap:wrap; gap:0.35rem; margin-bottom:1.2rem; }
.ae-tab {
  padding:0.35rem 0.9rem; border-radius:99px; font-size:0.82rem; font-weight:700;
  text-decoration:none; background:rgba(255,255,255,0.03);
  border:1px solid rgba(0,168,120,0.1); color:#8db8a8; transition:all 0.12s;
}
.ae-tab:hover, .ae-tab.active {
  background:rgba(0,168,120,0.12); color:#e8f5ee; border-color:rgba(0,168,120,0.3);
}
.ae-tab.active { font-weight:800; }
.ae-badge {
  display:inline-block; background:rgba(0,168,120,0.2); color:#00A878;
  font-size:0.65rem; font-weight:900; padding:0.05rem 0.4rem;
  border-radius:99px; margin-left:0.25rem; vertical-align:middle;
}
.ae-badge.red { background:rgba(239,68,68,0.2); color:#ef4444; }
.ae-badge.yellow { background:rgba(251,191,36,0.2); color:#fbbf24; }

.ae-flash-ok  { background:rgba(74,222,128,0.1); border:1px solid rgba(74,222,128,0.25);
  color:#4ade80; border-radius:8px; padding:0.6rem 0.9rem; margin-bottom:1rem; font-size:0.85rem; }
.ae-flash-err { background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.25);
  color:#ef4444; border-radius:8px; padding:0.6rem 0.9rem; margin-bottom:1rem; font-size:0.85rem; }

/* Table */
.ae-table-wrap { overflow-x:auto; }
table.ae-table { width:100%; border-collapse:collapse; font-size:0.82rem; }
.ae-table th {
  text-align:left; padding:0.5rem 0.75rem; font-size:0.7rem; font-weight:800;
  letter-spacing:0.06em; text-transform:uppercase; color:#5a8878;
  border-bottom:1px solid rgba(0,168,120,0.1); white-space:nowrap;
}
.ae-table td {
  padding:0.7rem 0.75rem; border-bottom:1px solid rgba(255,255,255,0.04);
  color:#8db8a8; vertical-align:top;
}
.ae-table tr:hover td { background:rgba(255,255,255,0.02); }
.ae-ev-title { font-weight:700; color:#e8f5ee; font-size:0.88rem; }
.ae-ev-date  { font-size:0.75rem; color:#5a8878; }
.ae-status {
  font-size:0.7rem; font-weight:800; padding:0.18rem 0.6rem; border-radius:99px;
  display:inline-block;
}

/* Action buttons */
.ae-actions { display:flex; flex-wrap:wrap; gap:0.3rem; }
.ae-btn {
  font-size:0.72rem; font-weight:700; padding:0.22rem 0.65rem; border-radius:6px;
  cursor:pointer; border:none; text-decoration:none; display:inline-block;
}
.ae-btn-approve { background:rgba(74,222,128,0.15); color:#4ade80; }
.ae-btn-reject  { background:rgba(239,68,68,0.12); color:#ef4444; }
.ae-btn-cancel  { background:rgba(251,191,36,0.12); color:#fbbf24; }
.ae-btn-feature { background:rgba(0,168,120,0.12); color:#00A878; }
.ae-btn-delete  { background:rgba(239,68,68,0.08); color:#ef4444; }
.ae-btn-view    { background:rgba(96,165,250,0.12); color:#60a5fa; }

/* Inline reject form row */
.ae-mod-row { display:none; }
.ae-mod-row td {
  background:rgba(239,68,68,0.05); border-bottom:2px solid rgba(239,68,68,0.15);
}
.ae-mod-inner { display:flex; gap:0.5rem; align-items:flex-start; }
.ae-mod-inner input {
  flex:1; background:rgba(0,0,0,0.3); border:1px solid rgba(239,68,68,0.25);
  color:#e8f5ee; border-radius:8px; padding:0.5rem 0.7rem; font-size:0.83rem;
}
.ae-mod-inner input:focus { outline:none; border-color:#ef4444; }

/* Empty */
.ae-empty { text-align:center; padding:3.5rem; color:#5a8878; }

/* Pagination */
.ae-pages { display:flex; gap:0.35rem; justify-content:center; margin-top:1.2rem; flex-wrap:wrap; }
.ae-pages a, .ae-pages span {
  padding:0.28rem 0.75rem; border-radius:6px; font-size:0.8rem; text-decoration:none;
  background:rgba(255,255,255,0.03); border:1px solid rgba(0,168,120,0.1); color:#8db8a8;
}
.ae-pages a:hover { border-color:rgba(0,168,120,0.35); color:#e8f5ee; }
.ae-pages .cur    { background:#00A878; color:#fff; border-color:#00A878; }
</style>

<div class="ae-wrap">
  <h1 class="ae-h1">📅 <?= t('Events Moderation','Modération des Événements') ?></h1>

  <!-- Top bar -->
  <div class="ae-top-bar">
    <form method="GET" action="<?= SITE_URL ?>/admin/events" class="ae-search">
      <input type="hidden" name="tab" value="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>">
      <input type="text" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
             placeholder="<?= t('Search events…','Chercher…') ?>">
      <button type="submit">🔍</button>
    </form>
    <a href="<?= SITE_URL ?>/events" target="_blank"
       style="font-size:0.82rem;color:#5a8878;text-decoration:none;">
      👁 <?= t('View public events','Voir les événements publics') ?>
    </a>
  </div>

  <!-- Tabs -->
  <div class="ae-tabs">
    <?php
    $tabs = [
      'pending'   => ['label'=>t('Pending','En attente'),   'cls'=>'yellow'],
      'approved'  => ['label'=>t('Approved','Approuvés'),   'cls'=>''],
      'rejected'  => ['label'=>t('Rejected','Refusés'),     'cls'=>'red'],
      'cancelled' => ['label'=>t('Cancelled','Annulés'),    'cls'=>''],
      'all'       => ['label'=>t('All','Tous'),             'cls'=>''],
    ];
    foreach ($tabs as $tv => $td):
      $n = $tv === 'all' ? array_sum($counts) : ($counts[$tv] ?? 0);
    ?>
      <a href="<?= adminEvUrl(['tab'=>$tv,'page'=>'']) ?>" class="ae-tab <?= $tab===$tv?'active':'' ?>">
        <?= $td['label'] ?>
        <?php if ($n > 0): ?>
          <span class="ae-badge <?= $td['cls'] ?>"><?= $n ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- Flash -->
  <?php if ($flash): ?>
    <div class="ae-flash-<?= $flash['type']==='success'?'ok':'err' ?>">
      <?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?>
    </div>
  <?php endif; ?>

  <!-- Table -->
  <div class="ae-table-wrap">
    <?php if ($events): ?>
      <table class="ae-table">
        <thead>
          <tr>
            <th><?= t('Event','Événement') ?></th>
            <th><?= t('Organiser','Organisateur') ?></th>
            <th><?= t('Date','Date') ?></th>
            <th><?= t('Type','Type') ?></th>
            <th><?= t('Regs','Inscrits') ?></th>
            <th><?= t('Status','Statut') ?></th>
            <th><?= t('Actions','Actions') ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($events as $ev):
            $sc = $statusColour[$ev['status']] ?? '#8db8a8';
            $et = htmlspecialchars($ev['title'], ENT_QUOTES, 'UTF-8');
          ?>
            <tr id="row-<?= $ev['id'] ?>">
              <td>
                <div class="ae-ev-title"><?= $et ?></div>
                <?php if ($ev['title_fr']): ?>
                  <div class="ae-ev-date"><?= htmlspecialchars($ev['title_fr'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <?php if ($ev['featured']): ?>
                  <span style="font-size:0.65rem;color:#00A878;">⭐ <?= t('Featured','À la une') ?></span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($ev['biz_name']): ?>
                  <a href="<?= SITE_URL ?>/listing/<?= htmlspecialchars($ev['biz_slug'], ENT_QUOTES, 'UTF-8') ?>"
                     target="_blank" style="color:#00A878;text-decoration:none;font-size:0.8rem;">
                    <?= htmlspecialchars($ev['biz_name'], ENT_QUOTES, 'UTF-8') ?>
                  </a>
                <?php else: ?>
                  <span style="color:#5a8878;">—</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="ae-ev-date"><?= date('d M Y', strtotime($ev['starts_at'])) ?></div>
                <div class="ae-ev-date"><?= date('H:i', strtotime($ev['starts_at'])) ?></div>
              </td>
              <td><?= ($typeEmoji[$ev['event_type']] ?? '📌') ?> <?= htmlspecialchars($ev['event_type'], ENT_QUOTES, 'UTF-8') ?></td>
              <td style="text-align:center;"><?= $ev['reg_count'] ?><?= $ev['capacity'] ? '/'.$ev['capacity'] : '' ?></td>
              <td>
                <span class="ae-status" style="background:<?= $sc ?>22;color:<?= $sc ?>;">
                  <?= ucfirst($ev['status']) ?>
                </span>
              </td>
              <td>
                <div class="ae-actions">
                  <?php if ($ev['status'] === 'approved'): ?>
                    <a href="<?= SITE_URL ?>/event/<?= htmlspecialchars($ev['slug'], ENT_QUOTES, 'UTF-8') ?>"
                       target="_blank" class="ae-btn ae-btn-view">👁</a>
                  <?php endif; ?>

                  <?php if ($ev['status'] === 'pending'): ?>
                    <form method="POST" style="display:inline;">
                      <?= csrf() ?>
                      <input type="hidden" name="action" value="approve">
                      <input type="hidden" name="event_id" value="<?= $ev['id'] ?>">
                      <button type="submit" class="ae-btn ae-btn-approve">✔ <?= t('Approve','Approuver') ?></button>
                    </form>
                    <button class="ae-btn ae-btn-reject" onclick="toggleModRow(<?= $ev['id'] ?>)">
                      ✕ <?= t('Reject','Refuser') ?>
                    </button>
                  <?php endif; ?>

                  <?php if ($ev['status'] === 'rejected'): ?>
                    <form method="POST" style="display:inline;">
                      <?= csrf() ?>
                      <input type="hidden" name="action" value="approve">
                      <input type="hidden" name="event_id" value="<?= $ev['id'] ?>">
                      <button type="submit" class="ae-btn ae-btn-approve">↺ <?= t('Reactivate','Réactiver') ?></button>
                    </form>
                  <?php endif; ?>

                  <?php if ($ev['status'] === 'approved'): ?>
                    <form method="POST" style="display:inline;">
                      <?= csrf() ?>
                      <input type="hidden" name="action" value="cancel">
                      <input type="hidden" name="event_id" value="<?= $ev['id'] ?>">
                      <button type="submit" class="ae-btn ae-btn-cancel"
                              onclick="return confirm('<?= t('Cancel this event?','Annuler cet événement ?') ?>')">
                        ✕ <?= t('Cancel','Annuler') ?>
                      </button>
                    </form>
                    <form method="POST" style="display:inline;">
                      <?= csrf() ?>
                      <input type="hidden" name="action" value="feature">
                      <input type="hidden" name="event_id" value="<?= $ev['id'] ?>">
                      <button type="submit" class="ae-btn ae-btn-feature">
                        <?= $ev['featured'] ? '★' : '☆' ?> <?= t('Feature','À la une') ?>
                      </button>
                    </form>
                  <?php endif; ?>

                  <form method="POST" style="display:inline;">
                    <?= csrf() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="event_id" value="<?= $ev['id'] ?>">
                    <button type="submit" class="ae-btn ae-btn-delete"
                            onclick="return confirm('<?= t('Delete permanently?','Supprimer définitivement ?') ?>')">
                      🗑
                    </button>
                  </form>
                </div>
              </td>
            </tr>

            <!-- Inline reject form -->
            <tr class="ae-mod-row" id="mod-<?= $ev['id'] ?>">
              <td colspan="7">
                <form method="POST">
                  <?= csrf() ?>
                  <input type="hidden" name="action" value="reject">
                  <input type="hidden" name="event_id" value="<?= $ev['id'] ?>">
                  <div class="ae-mod-inner">
                    <input type="text" name="rejection_note" required
                           placeholder="<?= t('Reason for rejection (shown to organiser)','Motif de refus (affiché à l\'organisateur)') ?>">
                    <button type="submit" class="ae-btn ae-btn-reject">
                      ✕ <?= t('Submit Rejection','Confirmer le refus') ?>
                    </button>
                    <button type="button" class="ae-btn" onclick="toggleModRow(<?= $ev['id'] ?>)"
                            style="background:rgba(255,255,255,0.06);color:#8db8a8;">
                      <?= t('Cancel','Annuler') ?>
                    </button>
                  </div>
                </form>
              </td>
            </tr>

          <?php endforeach; ?>
        </tbody>
      </table>

    <?php else: ?>
      <div class="ae-empty">
        📅 <?= t('No events found.','Aucun événement trouvé.') ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Pagination -->
  <?php if ($pages > 1): ?>
    <div class="ae-pages">
      <?php for ($p = 1; $p <= min($pages, 10); $p++): ?>
        <?php if ($p === $page): ?>
          <span class="cur"><?= $p ?></span>
        <?php else: ?>
          <a href="<?= adminEvUrl(['page' => $p]) ?>"><?= $p ?></a>
        <?php endif; ?>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>

<script>
function toggleModRow(id) {
  const row = document.getElementById('mod-' + id);
  if (!row) return;
  const open = row.style.display === 'table-row';
  // close all
  document.querySelectorAll('.ae-mod-row').forEach(r => r.style.display = 'none');
  if (!open) {
    row.style.display = 'table-row';
    row.querySelector('input[type=text]')?.focus();
  }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
