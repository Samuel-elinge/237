<?php
/**
 * partner/events.php — Business organiser: create & manage events
 * URL: /partner/events
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/entitlement-helpers.php';
requireLogin();
$isFr = lang() === 'fr';
$uid  = currentUser()['id'];

// ── Load owned approved listings ──────────────────────────────────────────
try {
    $ls = db()->prepare("SELECT id, title FROM listings WHERE user_id=? AND status='approved' ORDER BY title");
    $ls->execute([$uid]);
    $myListings = $ls->fetchAll(PDO::FETCH_KEY_PAIR); // id => title
} catch (Exception $e) { $myListings = []; }

if (!$myListings) {
    flash('error', t('You need an approved business listing to manage events.',
                     'Vous avez besoin d\'une annonce approuvée pour gérer des événements.'));
    redirect(SITE_URL . '/partner/dashboard');
}

$validLids = array_keys($myListings);
$lid = (int)($_GET['lid'] ?? $_POST['lid'] ?? $validLids[0]);
if (!in_array($lid, $validLids)) { $lid = $validLids[0]; }

// ── Handle actions ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // ── Create ────────────────────────────────────────────────────────────
    if ($action === 'create') {
        $postLid = (int)$_POST['lid'];
        if (!in_array($postLid, $validLids)) die('Forbidden');

        $title      = trim($_POST['title']       ?? '');
        $titleFr    = trim($_POST['title_fr']    ?? '');
        $desc       = trim($_POST['description'] ?? '');
        $descFr     = trim($_POST['description_fr'] ?? '');
        $evType     = $_POST['event_type']   ?? 'other';
        $startsAt   = trim($_POST['starts_at'] ?? '');
        $endsAt     = trim($_POST['ends_at']   ?? '');
        $isOnline   = isset($_POST['is_online']) ? 1 : 0;
        $venueName  = trim($_POST['venue_name']    ?? '');
        $venueAddr  = trim($_POST['venue_address'] ?? '');
        $locId      = (int)$_POST['location_id'] ?: null;
        $isFreeEv   = isset($_POST['is_free']) ? 1 : 0;
        $price      = $isFreeEv ? null : (floatval($_POST['ticket_price']) ?: null);
        $capacity   = (int)$_POST['capacity'] ?: null;
        $ticketUrl  = trim($_POST['ticket_url'] ?? '');

        $validTypes = ['workshop','networking','exhibition','conference','social','other'];
        if (!$title || !$startsAt || !in_array($evType, $validTypes)) {
            flash('error', t('Title, start date and event type are required.',
                             'Le titre, la date de début et le type sont obligatoires.'));
            redirect(SITE_URL . '/partner/events?lid=' . $postLid);
        }

        // Image upload
        $image = null;
        if (!empty($_FILES['image']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','webp'])) {
                $fn = "event-{$postLid}-" . time() . ".$ext";
                if (!is_dir(__DIR__ . '/../uploads/events/')) mkdir(__DIR__ . '/../uploads/events/', 0755, true);
                if (move_uploaded_file($_FILES['image']['tmp_name'], __DIR__ . '/../uploads/events/' . $fn)) {
                    $image = 'events/' . $fn;
                }
            }
        }

        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($title)) . '-' . substr(md5(uniqid()), 0, 6);

        // ── Entitlement check ─────────────────────────────────────────────
        if (!checkEntitlement($postLid, 'events')) {
            $ent = getListingEntitlements($postLid);
            flash('error', t(
                'You have reached your active event limit (' . $ent['total_events'] . '). Purchase additional event capacity or upgrade your plan.',
                'Vous avez atteint votre limite d\'événements actifs (' . $ent['total_events'] . '). Achetez de la capacité supplémentaire ou passez à un plan supérieur.'
            ));
            redirect(SITE_URL . '/partner/events?lid=' . $postLid);
        }

        try {
            db()->prepare("
                INSERT INTO events
                  (listing_id, slug, title, title_fr, description, description_fr,
                   event_type, starts_at, ends_at, is_online, venue_name, venue_address,
                   location_id, is_free, ticket_price, ticket_url, capacity, image, status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'pending')
            ")->execute([$postLid, $slug, $title, $titleFr ?: null, $desc, $descFr ?: null,
                         $evType, $startsAt, $endsAt ?: null, $isOnline,
                         $venueName ?: null, $venueAddr ?: null, $locId, $isFreeEv, $price,
                         $ticketUrl ?: null, $capacity, $image]);
            flash('success', t('Event submitted for review!','Événement soumis pour examen !'));
        } catch (Exception $e) {
            flash('error', t('Could not save event.','Impossible d\'enregistrer l\'événement.'));
        }
        redirect(SITE_URL . '/partner/events?lid=' . $postLid);
    }

    // ── Update ────────────────────────────────────────────────────────────
    if ($action === 'update') {
        $eid = (int)$_POST['event_id'];
        // Ownership check
        try {
            $own = db()->prepare("SELECT id FROM events WHERE id=? AND listing_id=?");
            $own->execute([$eid, $lid]);
            if (!$own->fetch()) die('Forbidden');
        } catch (Exception $e) { die('Error'); }

        $title      = trim($_POST['title']          ?? '');
        $titleFr    = trim($_POST['title_fr']       ?? '');
        $desc       = trim($_POST['description']    ?? '');
        $descFr     = trim($_POST['description_fr'] ?? '');
        $evType     = $_POST['event_type']   ?? 'other';
        $startsAt   = trim($_POST['starts_at'] ?? '');
        $endsAt     = trim($_POST['ends_at']   ?? '');
        $isOnline   = isset($_POST['is_online']) ? 1 : 0;
        $venueName  = trim($_POST['venue_name']    ?? '');
        $venueAddr  = trim($_POST['venue_address'] ?? '');
        $locId      = (int)$_POST['location_id'] ?: null;
        $isFreeEv   = isset($_POST['is_free']) ? 1 : 0;
        $price      = $isFreeEv ? null : (floatval($_POST['ticket_price']) ?: null);
        $capacity   = (int)$_POST['capacity'] ?: null;
        $ticketUrl  = trim($_POST['ticket_url'] ?? '');

        // Image re-upload (optional)
        $imageSet = 'image = COALESCE(?,image)';
        $newImage = null;
        if (!empty($_FILES['image']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','webp'])) {
                $fn = "event-{$lid}-" . time() . ".$ext";
                if (!is_dir(__DIR__ . '/../uploads/events/')) mkdir(__DIR__ . '/../uploads/events/', 0755, true);
                if (move_uploaded_file($_FILES['image']['tmp_name'], __DIR__ . '/../uploads/events/' . $fn)) {
                    $newImage = 'events/' . $fn;
                }
            }
        }

        try {
            db()->prepare("
                UPDATE events SET
                  title=?, title_fr=?, description=?, description_fr=?,
                  event_type=?, starts_at=?, ends_at=?,
                  is_online=?, venue_name=?, venue_address=?, location_id=?,
                  is_free=?, ticket_price=?, ticket_url=?, capacity=?,
                  image = COALESCE(?,image),
                  status = IF(status IN ('rejected'), 'pending', status)
                WHERE id=? AND listing_id=?
            ")->execute([
                $title, $titleFr ?: null, $desc, $descFr ?: null,
                $evType, $startsAt, $endsAt ?: null,
                $isOnline, $venueName ?: null, $venueAddr ?: null, $locId,
                $isFreeEv, $price, $ticketUrl ?: null, $capacity,
                $newImage,
                $eid, $lid
            ]);
            flash('success', t('Event updated.','Événement mis à jour.'));
        } catch (Exception $e) {
            flash('error', t('Update failed.','Mise à jour échouée.'));
        }
        redirect(SITE_URL . '/partner/events?lid=' . $lid);
    }

    // ── Cancel ────────────────────────────────────────────────────────────
    if ($action === 'cancel') {
        $eid = (int)$_POST['event_id'];
        try {
            $own = db()->prepare("SELECT id FROM events WHERE id=? AND listing_id=?");
            $own->execute([$eid, $lid]);
            if (!$own->fetch()) die('Forbidden');
            db()->prepare("UPDATE events SET status='cancelled' WHERE id=? AND listing_id=?")->execute([$eid, $lid]);
            flash('success', t('Event cancelled.','Événement annulé.'));
        } catch (Exception $e) {}
        redirect(SITE_URL . '/partner/events?lid=' . $lid);
    }

    // ── Delete (draft/rejected only) ──────────────────────────────────────
    if ($action === 'delete') {
        $eid = (int)$_POST['event_id'];
        try {
            $own = db()->prepare("SELECT id, status FROM events WHERE id=? AND listing_id=?");
            $own->execute([$eid, $lid]);
            $row = $own->fetch();
            if (!$row) die('Forbidden');
            if (in_array($row['status'], ['draft','rejected'])) {
                db()->prepare("DELETE FROM events WHERE id=?")->execute([$eid]);
                flash('success', t('Event deleted.','Événement supprimé.'));
            } else {
                flash('error', t('Only draft or rejected events can be deleted.','Seuls les événements brouillons ou refusés peuvent être supprimés.'));
            }
        } catch (Exception $e) {}
        redirect(SITE_URL . '/partner/events?lid=' . $lid);
    }
}

// ── Load events for this listing ──────────────────────────────────────────
try {
    $evs = db()->prepare("
        SELECT e.*, COUNT(er.id) AS reg_total
        FROM events e
        LEFT JOIN event_registrations er ON er.event_id = e.id
        WHERE e.listing_id = ?
        GROUP BY e.id
        ORDER BY e.starts_at DESC
    ");
    $evs->execute([$lid]);
    $myEvents = $evs->fetchAll();
} catch (Exception $e) { $myEvents = []; }

// ── Locations ─────────────────────────────────────────────────────────────
try {
    $locsQ = db()->query("SELECT id, name_en, name_fr FROM locations ORDER BY name_en");
    $locsList = $locsQ->fetchAll();
} catch (Exception $e) { $locsList = []; }

$flash = flash();
$pageTitle = t('My Events','Mes Événements') . ' — 237Biz Partner';
require_once __DIR__ . '/../includes/header.php';

$statusColour = [
    'draft'     => '#8db8a8',
    'pending'   => '#fbbf24',
    'approved'  => '#4ade80',
    'rejected'  => '#ef4444',
    'cancelled' => '#5a8878',
    'ended'     => '#5a8878',
];
?>
<style>
.ep-wrap { max-width:1000px; margin:0 auto; padding:1.5rem 1rem; }
.ep-header { display:flex; align-items:center; justify-content:space-between;
  flex-wrap:wrap; gap:0.75rem; margin-bottom:1.5rem; }
.ep-header h1 { font-family:'Fraunces',serif; font-weight:900; font-size:1.6rem; color:#f0faf5; }

.ep-listing-tabs { display:flex; flex-wrap:wrap; gap:0.4rem; margin-bottom:1.2rem; }
.ep-listing-tab {
  padding:0.35rem 0.85rem; border-radius:99px; font-size:0.82rem; font-weight:700;
  text-decoration:none; background:rgba(255,255,255,0.04);
  border:1px solid rgba(0,168,120,0.12); color:#8db8a8; transition:all 0.12s;
}
.ep-listing-tab.active, .ep-listing-tab:hover {
  background:rgba(0,168,120,0.12); color:#e8f5ee; border-color:rgba(0,168,120,0.35);
}
.ep-listing-tab.active { font-weight:800; }

.ep-flash-ok  { background:rgba(74,222,128,0.1); border:1px solid rgba(74,222,128,0.25);
  color:#4ade80; border-radius:8px; padding:0.6rem 0.9rem; margin-bottom:1rem; font-size:0.85rem; }
.ep-flash-err { background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.25);
  color:#ef4444; border-radius:8px; padding:0.6rem 0.9rem; margin-bottom:1rem; font-size:0.85rem; }

/* Event cards */
.ep-events { display:flex; flex-direction:column; gap:0.8rem; margin-bottom:2rem; }
.ep-event-card {
  background:rgba(255,255,255,0.03); border:1px solid rgba(0,168,120,0.12);
  border-radius:12px; padding:1rem 1.2rem;
}
.ep-event-row { display:flex; gap:1rem; align-items:flex-start; }
.ep-event-thumb { width:80px; height:55px; border-radius:7px; object-fit:cover;
  background:rgba(0,168,120,0.08); flex-shrink:0; font-size:2rem;
  display:flex; align-items:center; justify-content:center; }
.ep-event-thumb img { width:100%; height:100%; object-fit:cover; border-radius:7px; }
.ep-event-body { flex:1; min-width:0; }
.ep-event-title { font-family:'Fraunces',serif; font-weight:800; color:#e8f5ee;
  font-size:0.97rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.ep-event-meta { font-size:0.77rem; color:#5a8878; margin-top:0.2rem; }
.ep-event-actions { display:flex; flex-wrap:wrap; gap:0.4rem; margin-top:0.5rem; }
.ep-action-btn {
  font-size:0.73rem; font-weight:700; padding:0.22rem 0.7rem; border-radius:6px;
  text-decoration:none; cursor:pointer; border:none;
}
.ep-btn-edit   { background:rgba(0,168,120,0.15); color:#00A878; }
.ep-btn-view   { background:rgba(96,165,250,0.12); color:#60a5fa; }
.ep-btn-cancel { background:rgba(251,191,36,0.12); color:#fbbf24; }
.ep-btn-delete { background:rgba(239,68,68,0.1); color:#ef4444; }

.ep-status-badge {
  font-size:0.68rem; font-weight:800; padding:0.18rem 0.55rem;
  border-radius:99px; margin-left:0.4rem;
}
.ep-rejection-note {
  background:rgba(239,68,68,0.07); border-left:3px solid rgba(239,68,68,0.4);
  border-radius:0 7px 7px 0; padding:0.5rem 0.75rem; margin-top:0.5rem;
  font-size:0.78rem; color:#ef4444;
}

/* Create form */
.ep-create-section { background:rgba(255,255,255,0.02); border:1px solid rgba(0,168,120,0.12);
  border-radius:12px; padding:1.4rem; }
.ep-create-section h2 { font-family:'Fraunces',serif; font-weight:800; color:#e8f5ee;
  font-size:1.05rem; margin-bottom:1.1rem; }
.ep-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:0.75rem; }
@media(max-width:640px) { .ep-form-grid { grid-template-columns:1fr; } }
.ep-form-field { display:flex; flex-direction:column; gap:0.25rem; }
.ep-form-field.full { grid-column:1/-1; }
.ep-form-field label { font-size:0.72rem; font-weight:800; color:#5a8878;
  text-transform:uppercase; letter-spacing:0.05em; }
.ep-form-field input, .ep-form-field select, .ep-form-field textarea {
  background:rgba(0,0,0,0.3); border:1px solid rgba(0,168,120,0.2);
  color:#e8f5ee; border-radius:8px; padding:0.55rem 0.8rem; font-size:0.85rem;
}
.ep-form-field input:focus, .ep-form-field select:focus, .ep-form-field textarea:focus {
  outline:none; border-color:#00A878;
}
.ep-form-checkbox { display:flex; align-items:center; gap:0.5rem; margin-top:0.3rem; }
.ep-form-checkbox input { width:auto; }
.ep-submit-btn {
  background:#00A878; color:#fff; border:none; border-radius:9px;
  padding:0.7rem 1.6rem; font-weight:800; font-size:0.9rem; cursor:pointer;
  margin-top:0.5rem;
}
.ep-submit-btn:hover { background:#00c48f; }

/* Edit modal overlay */
.ep-modal-overlay { display:none; position:fixed; inset:0;
  background:rgba(0,0,0,0.75); z-index:200; align-items:center; justify-content:center; }
.ep-modal-overlay.open { display:flex; }
.ep-modal { background:#0d1f19; border:1px solid rgba(0,168,120,0.2);
  border-radius:14px; padding:1.5rem; width:min(680px,96vw); max-height:90vh;
  overflow-y:auto; position:relative; }
.ep-modal-close { position:absolute; top:0.75rem; right:0.9rem;
  background:none; border:none; color:#5a8878; font-size:1.3rem; cursor:pointer; }
.ep-modal h3 { font-family:'Fraunces',serif; font-weight:800; color:#e8f5ee;
  font-size:1rem; margin-bottom:1rem; }
</style>

<div class="ep-wrap">
  <div class="ep-header">
    <h1>📅 <?= t('My Events','Mes Événements') ?></h1>
    <a href="<?= SITE_URL ?>/partner/dashboard" style="font-size:0.82rem;color:#5a8878;text-decoration:none;">
      ← <?= t('Dashboard','Tableau de bord') ?>
    </a>
  </div>

  <!-- Listing tabs -->
  <?php if (count($myListings) > 1): ?>
    <div class="ep-listing-tabs">
      <?php foreach ($myListings as $tid => $tname): ?>
        <a href="?lid=<?= $tid ?>" class="ep-listing-tab <?= $lid===$tid?'active':'' ?>">
          🏢 <?= htmlspecialchars($tname, ENT_QUOTES, 'UTF-8') ?>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- Flash -->
  <?php if ($flash): ?>
    <div class="ep-flash-<?= $flash['type']==='success'?'ok':'err' ?>">
      <?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?>
    </div>
  <?php endif; ?>

  <!-- Existing events -->
  <?php if ($myEvents): ?>
    <div class="ep-events">
      <?php foreach ($myEvents as $ev):
        $et = htmlspecialchars($ev['title'], ENT_QUOTES, 'UTF-8');
        $sc = $statusColour[$ev['status']] ?? '#8db8a8';
      ?>
        <div class="ep-event-card" id="event-<?= $ev['id'] ?>">
          <div class="ep-event-row">
            <div class="ep-event-thumb">
              <?php if ($ev['image']): ?>
                <img src="<?= UPLOAD_URL . htmlspecialchars($ev['image'], ENT_QUOTES, 'UTF-8') ?>" alt="">
              <?php else: ?>
                📅
              <?php endif; ?>
            </div>
            <div class="ep-event-body">
              <div class="ep-event-title">
                <?= $et ?>
                <span class="ep-status-badge" style="background:<?= $sc ?>22;color:<?= $sc ?>;">
                  <?= ucfirst($ev['status']) ?>
                </span>
              </div>
              <div class="ep-event-meta">
                <?= date('d M Y · H:i', strtotime($ev['starts_at'])) ?>
                · <?= $ev['is_free'] ? t('Free','Gratuit') : number_format($ev['ticket_price'] ?? 0).' XAF' ?>
                <?php if ($ev['reg_total']): ?>
                  · <?= $ev['reg_total'] ?> <?= t('registered','inscrits') ?>
                <?php endif; ?>
              </div>
              <div class="ep-event-actions">
                <?php if (in_array($ev['status'], ['draft','pending','rejected','approved'])): ?>
                  <button class="ep-action-btn ep-btn-edit"
                          onclick="openEditModal(<?= $ev['id'] ?>)">
                    ✏️ <?= t('Edit','Modifier') ?>
                  </button>
                <?php endif; ?>
                <?php if ($ev['status'] === 'approved'): ?>
                  <a href="<?= SITE_URL ?>/event/<?= htmlspecialchars($ev['slug'], ENT_QUOTES, 'UTF-8') ?>"
                     target="_blank" class="ep-action-btn ep-btn-view">
                    👁 <?= t('View','Voir') ?>
                  </a>
                <?php endif; ?>
                <?php if ($ev['status'] === 'approved'): ?>
                  <form method="POST" style="display:inline;"
                        onsubmit="return confirm('<?= t('Cancel this event?','Annuler cet événement ?') ?>')">
                    <?= csrf() ?>
                    <input type="hidden" name="action" value="cancel">
                    <input type="hidden" name="event_id" value="<?= $ev['id'] ?>">
                    <input type="hidden" name="lid" value="<?= $lid ?>">
                    <button type="submit" class="ep-action-btn ep-btn-cancel">
                      ✕ <?= t('Cancel','Annuler') ?>
                    </button>
                  </form>
                <?php elseif (in_array($ev['status'], ['draft','rejected'])): ?>
                  <form method="POST" style="display:inline;"
                        onsubmit="return confirm('<?= t('Delete this event?','Supprimer cet événement ?') ?>')">
                    <?= csrf() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="event_id" value="<?= $ev['id'] ?>">
                    <input type="hidden" name="lid" value="<?= $lid ?>">
                    <button type="submit" class="ep-action-btn ep-btn-delete">
                      🗑 <?= t('Delete','Supprimer') ?>
                    </button>
                  </form>
                <?php endif; ?>
              </div>

              <?php if ($ev['status'] === 'rejected' && $ev['rejection_note']): ?>
                <div class="ep-rejection-note">
                  <strong><?= t('Rejection reason:','Raison du refus :') ?></strong>
                  <?= htmlspecialchars($ev['rejection_note'], ENT_QUOTES, 'UTF-8') ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p style="color:#5a8878;margin-bottom:1.5rem;">
      <?= t('No events yet. Create your first event below!','Aucun événement. Créez votre premier événement ci-dessous !') ?>
    </p>
  <?php endif; ?>

  <!-- Create form -->
  <div class="ep-create-section">
    <h2>+ <?= t('Create New Event','Créer un Nouvel Événement') ?></h2>
    <form method="POST" enctype="multipart/form-data">
      <?= csrf() ?>
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="lid" value="<?= $lid ?>">
      <div class="ep-form-grid">
        <div class="ep-form-field full">
          <label><?= t('Event Title (EN) *','Titre de l\'événement (EN) *') ?></label>
          <input type="text" name="title" required maxlength="200"
                 placeholder="<?= t('Annual Tech Summit Douala','Sommet Tech Annuel Douala') ?>">
        </div>
        <div class="ep-form-field full">
          <label><?= t('Title (French)','Titre (Français)') ?></label>
          <input type="text" name="title_fr" maxlength="200">
        </div>
        <div class="ep-form-field">
          <label><?= t('Event Type *','Type d\'événement *') ?></label>
          <select name="event_type" required>
            <?php foreach ([
              'workshop'=>t('Workshop','Atelier'),'networking'=>t('Networking','Réseautage'),
              'exhibition'=>t('Exhibition','Exposition'),'conference'=>t('Conference','Conférence'),
              'social'=>t('Social','Social'),'other'=>t('Other','Autre'),
            ] as $v => $l): ?>
              <option value="<?= $v ?>"><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="ep-form-field">
          <label><?= t('Location','Ville') ?></label>
          <select name="location_id">
            <option value=""><?= t('— Select city —','— Choisir une ville —') ?></option>
            <?php foreach ($locsList as $loc): ?>
              <option value="<?= $loc['id'] ?>"><?= htmlspecialchars($isFr ? $loc['name_fr'] : $loc['name_en'], ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="ep-form-field">
          <label><?= t('Start Date &amp; Time *','Date &amp; Heure début *') ?></label>
          <input type="datetime-local" name="starts_at" required>
        </div>
        <div class="ep-form-field">
          <label><?= t('End Date &amp; Time','Date &amp; Heure fin') ?></label>
          <input type="datetime-local" name="ends_at">
        </div>
        <div class="ep-form-field">
          <label><?= t('Venue Name','Nom du lieu') ?></label>
          <input type="text" name="venue_name" maxlength="200"
                 placeholder="<?= t('e.g. Hilton Yaoundé','ex. Hilton Yaoundé') ?>">
        </div>
        <div class="ep-form-field">
          <label><?= t('Venue Address','Adresse du lieu') ?></label>
          <input type="text" name="venue_address" maxlength="300">
        </div>
        <div class="ep-form-field">
          <label><?= t('Ticket Price (XAF, leave blank if free)','Prix billet (XAF, vide si gratuit)') ?></label>
          <input type="number" name="ticket_price" min="0" step="100" id="price-field"
                 placeholder="5000">
        </div>
        <div class="ep-form-field">
          <label><?= t('Capacity (max attendees)','Capacité (max participants)') ?></label>
          <input type="number" name="capacity" min="1"
                 placeholder="<?= t('leave blank = unlimited','laisser vide = illimité') ?>">
        </div>
        <div class="ep-form-field full">
          <label><?= t('Ticket / Registration URL (optional)','URL billet / inscription (optionnel)') ?></label>
          <input type="url" name="ticket_url" placeholder="https://…">
        </div>
        <div class="ep-form-field full">
          <label><?= t('Description (EN)','Description (EN)') ?></label>
          <textarea name="description" rows="4" style="resize:vertical;"></textarea>
        </div>
        <div class="ep-form-field full">
          <label><?= t('Description (French)','Description (Français)') ?></label>
          <textarea name="description_fr" rows="3" style="resize:vertical;"></textarea>
        </div>
        <div class="ep-form-field">
          <label><?= t('Event Image','Image de l\'événement') ?></label>
          <input type="file" name="image" accept="image/*">
        </div>
        <div class="ep-form-field" style="justify-content:flex-end;">
          <label class="ep-form-checkbox">
            <input type="checkbox" name="is_online" value="1"> <?= t('Online event','Événement en ligne') ?>
          </label>
          <label class="ep-form-checkbox" id="free-check-wrap">
            <input type="checkbox" name="is_free" value="1" id="free-check"
                   onchange="document.getElementById('price-field').disabled=this.checked;">
            <?= t('Free entry','Entrée gratuite') ?>
          </label>
        </div>
      </div>
      <button type="submit" class="ep-submit-btn">
        📅 <?= t('Submit for Review','Soumettre pour examen') ?>
      </button>
    </form>
  </div>
</div>

<!-- Edit modal -->
<div class="ep-modal-overlay" id="edit-modal">
  <div class="ep-modal">
    <button class="ep-modal-close" onclick="closeEditModal()">✕</button>
    <h3>✏️ <?= t('Edit Event','Modifier l\'événement') ?></h3>
    <form method="POST" enctype="multipart/form-data" id="edit-form">
      <?= csrf() ?>
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="lid" value="<?= $lid ?>">
      <input type="hidden" name="event_id" id="edit-event-id">
      <div class="ep-form-grid">
        <div class="ep-form-field full">
          <label><?= t('Title (EN) *','Titre (EN) *') ?></label>
          <input type="text" name="title" id="edit-title" required maxlength="200">
        </div>
        <div class="ep-form-field full">
          <label><?= t('Title (FR)','Titre (FR)') ?></label>
          <input type="text" name="title_fr" id="edit-title-fr" maxlength="200">
        </div>
        <div class="ep-form-field">
          <label><?= t('Type *','Type *') ?></label>
          <select name="event_type" id="edit-type" required>
            <?php foreach (['workshop'=>t('Workshop','Atelier'),'networking'=>t('Networking','Réseautage'),
              'exhibition'=>t('Exhibition','Exposition'),'conference'=>t('Conference','Conférence'),
              'social'=>t('Social','Social'),'other'=>t('Other','Autre')] as $v=>$l): ?>
              <option value="<?= $v ?>"><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="ep-form-field">
          <label><?= t('City','Ville') ?></label>
          <select name="location_id" id="edit-loc">
            <option value=""><?= t('— Select —','— Choisir —') ?></option>
            <?php foreach ($locsList as $loc): ?>
              <option value="<?= $loc['id'] ?>"><?= htmlspecialchars($isFr ? $loc['name_fr'] : $loc['name_en'], ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="ep-form-field">
          <label><?= t('Start *','Début *') ?></label>
          <input type="datetime-local" name="starts_at" id="edit-starts" required>
        </div>
        <div class="ep-form-field">
          <label><?= t('End','Fin') ?></label>
          <input type="datetime-local" name="ends_at" id="edit-ends">
        </div>
        <div class="ep-form-field">
          <label><?= t('Venue Name','Lieu') ?></label>
          <input type="text" name="venue_name" id="edit-venue" maxlength="200">
        </div>
        <div class="ep-form-field">
          <label><?= t('Venue Address','Adresse') ?></label>
          <input type="text" name="venue_address" id="edit-vaddr" maxlength="300">
        </div>
        <div class="ep-form-field">
          <label><?= t('Price (XAF)','Prix (XAF)') ?></label>
          <input type="number" name="ticket_price" id="edit-price" min="0" step="100">
        </div>
        <div class="ep-form-field">
          <label><?= t('Capacity','Capacité') ?></label>
          <input type="number" name="capacity" id="edit-cap" min="1">
        </div>
        <div class="ep-form-field full">
          <label><?= t('Ticket URL','URL billet') ?></label>
          <input type="url" name="ticket_url" id="edit-turl">
        </div>
        <div class="ep-form-field full">
          <label><?= t('Description (EN)','Description (EN)') ?></label>
          <textarea name="description" id="edit-desc" rows="3" style="resize:vertical;"></textarea>
        </div>
        <div class="ep-form-field full">
          <label><?= t('Description (FR)','Description (FR)') ?></label>
          <textarea name="description_fr" id="edit-descfr" rows="2" style="resize:vertical;"></textarea>
        </div>
        <div class="ep-form-field">
          <label><?= t('New Image (optional)','Nouvelle image (optionnel)') ?></label>
          <input type="file" name="image" accept="image/*">
        </div>
        <div class="ep-form-field" style="justify-content:flex-end;">
          <label class="ep-form-checkbox">
            <input type="checkbox" name="is_online" id="edit-online" value="1">
            <?= t('Online event','Événement en ligne') ?>
          </label>
          <label class="ep-form-checkbox">
            <input type="checkbox" name="is_free" id="edit-free" value="1">
            <?= t('Free entry','Entrée gratuite') ?>
          </label>
        </div>
      </div>
      <button type="submit" class="ep-submit-btn">
        💾 <?= t('Save Changes','Sauvegarder') ?>
      </button>
    </form>
  </div>
</div>

<script>
// Flatten event data for JS
const evData = <?= json_encode(array_column($myEvents, null, 'id'), JSON_HEX_TAG) ?>;

function openEditModal(eid) {
  const ev = evData[eid];
  if (!ev) return;
  document.getElementById('edit-event-id').value = eid;
  document.getElementById('edit-title').value    = ev.title || '';
  document.getElementById('edit-title-fr').value = ev.title_fr || '';
  document.getElementById('edit-type').value     = ev.event_type || 'other';
  document.getElementById('edit-loc').value      = ev.location_id || '';
  document.getElementById('edit-starts').value   = (ev.starts_at||'').replace(' ','T').slice(0,16);
  document.getElementById('edit-ends').value     = (ev.ends_at||'').replace(' ','T').slice(0,16);
  document.getElementById('edit-venue').value    = ev.venue_name || '';
  document.getElementById('edit-vaddr').value    = ev.venue_address || '';
  document.getElementById('edit-price').value    = ev.ticket_price || '';
  document.getElementById('edit-cap').value      = ev.capacity || '';
  document.getElementById('edit-turl').value     = ev.ticket_url || '';
  document.getElementById('edit-desc').value     = ev.description || '';
  document.getElementById('edit-descfr').value   = ev.description_fr || '';
  document.getElementById('edit-online').checked = !!+ev.is_online;
  document.getElementById('edit-free').checked   = !!+ev.is_free;
  document.getElementById('edit-modal').classList.add('open');
}
function closeEditModal() {
  document.getElementById('edit-modal').classList.remove('open');
}
document.getElementById('edit-modal').addEventListener('click', function(e) {
  if (e.target === this) closeEditModal();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
