<?php
require_once __DIR__ . '/includes/config.php';
requireLogin();

$u      = currentUser();
$userId = (int)$u['id'];
$isFr   = defined('IS_FR') ? IS_FR : false;

// Mark-all-read on GET with ?mark_read=1
if (isset($_GET['mark_read']) && $_GET['mark_read'] === '1') {
    try {
        db()->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0")
             ->execute([$userId]);
    } catch (Exception $e) {}
    header('Location: ' . SITE_URL . '/notifications');
    exit;
}

// Filter
$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, ['all', 'unread'])) $filter = 'all';

// Fetch
$notifs = [];
try {
    $where = $filter === 'unread' ? 'AND n.is_read = 0' : '';
    $nq = db()->prepare("
        SELECT n.*
        FROM notifications n
        WHERE n.user_id = ? AND n.channel = 'bell'
        {$where}
        ORDER BY n.created_at DESC
        LIMIT 100
    ");
    $nq->execute([$userId]);
    $notifs = $nq->fetchAll();
} catch (Exception $e) {}

// Unread count
$unreadCount = 0;
try {
    $ucq = db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND channel='bell' AND is_read=0");
    $ucq->execute([$userId]);
    $unreadCount = (int)$ucq->fetchColumn();
} catch (Exception $e) {}

$pageTitle = t('Notifications — 237Biz', 'Notifications — 237Biz');
require_once __DIR__ . '/includes/header.php';
?>

<style>
.notif-list { display:flex; flex-direction:column; gap:0; }
.notif-item {
  display: flex;
  gap: 1rem;
  padding: 1rem 1.25rem;
  border-bottom: 1px solid var(--border);
  transition: background .12s;
  position: relative;
}
.notif-item:last-child { border-bottom: none; }
.notif-item.unread { background: rgba(0,168,120,0.04); }
.notif-item:hover  { background: rgba(255,255,255,0.03); }
.notif-dot {
  width: 8px; height: 8px;
  border-radius: 50%;
  background: var(--green);
  margin-top: 6px;
  flex-shrink: 0;
}
.notif-dot.read { background: transparent; border: 1px solid var(--border); }
.notif-body { flex: 1; min-width: 0; }
.notif-title { font-weight: 600; font-size: .875rem; color: var(--white); margin-bottom: .2rem; }
.notif-text  { font-size: .8rem; color: var(--muted); line-height: 1.45; }
.notif-meta  { font-size: .73rem; color: var(--muted-2); margin-top: .35rem; display: flex; gap: .75rem; flex-wrap: wrap; align-items: center; }
.notif-link  { font-size: .8rem; color: var(--green); text-decoration: none; }
.notif-link:hover { text-decoration: underline; }
.notif-filters { display: flex; gap: 6px; margin-bottom: 1.25rem; flex-wrap: wrap; }
</style>

<div class="page-header">
  <div class="container" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
    <div>
      <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:clamp(1.6rem,3vw,2.2rem);">
        🔔 <?= t('Notifications', 'Notifications') ?>
      </h1>
      <?php if ($unreadCount): ?>
      <p style="color:var(--green);margin-top:.3rem;font-size:.875rem;">
        <?= $unreadCount ?> <?= t('unread','non lues') ?>
      </p>
      <?php endif; ?>
    </div>
    <?php if ($unreadCount): ?>
    <a href="?mark_read=1" class="btn btn-outline btn-sm">✓ <?= t('Mark all read','Tout marquer comme lu') ?></a>
    <?php endif; ?>
  </div>
</div>

<section class="page-section">
  <div class="container">

    <div class="notif-filters">
      <a href="?filter=all"    class="btn btn-sm <?= $filter==='all'    ? 'btn-primary' : 'btn-outline' ?>"><?= t('All','Toutes') ?></a>
      <a href="?filter=unread" class="btn btn-sm <?= $filter==='unread' ? 'btn-primary' : 'btn-outline' ?>">
        <?= t('Unread','Non lues') ?>
        <?php if ($unreadCount): ?><span style="font-size:.72rem;background:rgba(0,168,120,.2);color:var(--green);border-radius:99px;padding:1px 6px;margin-left:2px;"><?= $unreadCount ?></span><?php endif; ?>
      </a>
    </div>

    <div class="cdash-card">
      <?php if ($notifs): ?>
      <div class="notif-list">
        <?php foreach ($notifs as $n): ?>
        <?php
          $title = $isFr && $n['title_fr'] ? $n['title_fr'] : $n['title_en'];
          $body  = $isFr && $n['body_fr']  ? $n['body_fr']  : $n['body_en'];
          $ago   = human_time_diff(strtotime($n['created_at']));
        ?>
        <div class="notif-item <?= !$n['is_read'] ? 'unread' : '' ?>"
             id="notif-<?= $n['id'] ?>">
          <div class="notif-dot <?= $n['is_read'] ? 'read' : '' ?>"></div>
          <div class="notif-body">
            <div class="notif-title"><?= e($title) ?></div>
            <?php if ($body): ?>
            <div class="notif-text"><?= e($body) ?></div>
            <?php endif; ?>
            <div class="notif-meta">
              <span><?= e($ago) ?></span>
              <?php if ($n['link']): ?>
              <a href="<?= SITE_URL . '/' . ltrim(e($n['link']), '/') ?>" class="notif-link"><?= t('View details','Voir détails') ?> →</a>
              <?php endif; ?>
              <?php if (!$n['is_read']): ?>
              <button onclick="markRead(<?= $n['id'] ?>, this)"
                      style="background:none;border:none;color:var(--muted);font-size:.73rem;cursor:pointer;padding:0;text-decoration:underline;">
                <?= t('Mark read','Marquer lu') ?>
              </button>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <?php else: ?>
      <div class="cdash-empty">
        <div class="cdash-empty-icon">🔔</div>
        <h3><?= $filter==='unread' ? t('No unread notifications','Aucune notification non lue') : t('No notifications yet','Aucune notification') ?></h3>
        <p style="color:var(--muted);font-size:.875rem;"><?= t('Booking confirmations, event reminders and business updates will appear here.','Les confirmations de réservation, rappels d\'événements et mises à jour apparaîtront ici.') ?></p>
      </div>
      <?php endif; ?>
    </div>

    <div style="margin-top:1rem;display:flex;gap:.75rem;flex-wrap:wrap;">
      <a href="<?= SITE_URL ?>/customer-dashboard" class="btn btn-outline btn-sm">← <?= t('Back to My Account','Mon Compte') ?></a>
      <a href="<?= SITE_URL ?>/customer-dashboard?tab=preferences" class="btn btn-outline btn-sm">⚙️ <?= t('Notification Preferences','Préférences') ?></a>
    </div>

  </div>
</section>

<?php
// Helper: human-readable time difference
function human_time_diff(int $ts): string {
    $diff = time() - $ts;
    if ($diff < 60)   return t('Just now','À l\'instant');
    if ($diff < 3600) return intval($diff/60) . ' ' . t('min ago','min');
    if ($diff < 86400) return intval($diff/3600) . t('h ago','h');
    if ($diff < 604800) return intval($diff/86400) . ' ' . t('days ago','j');
    return date('d M Y', $ts);
}
?>

<script>
function markRead(notifId, btn) {
  fetch('<?= SITE_URL ?>/api/notifications', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({action:'mark_read', notif_id:notifId, csrf:'<?= csrf() ?>'}),
  })
  .then(r => r.json())
  .then(d => {
    if (d.ok) {
      const item = document.getElementById('notif-' + notifId);
      if (item) {
        item.classList.remove('unread');
        const dot = item.querySelector('.notif-dot');
        if (dot) dot.classList.add('read');
        btn.remove();
      }
    }
  })
  .catch(()=>{});
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
