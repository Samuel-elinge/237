<?php
/**
 * Follow / Unfollow button — include on listing pages.
 *
 * Usage:
 *   $followItemType = 'listing';   // only 'listing' supported for now
 *   $followItemId   = (int)$listing['id'];
 *   require __DIR__ . '/includes/follow-button.php';
 *
 * Outputs a <button> (or login link) + inline JS. Safe to include multiple
 * times per page (guards the JS block with a once-flag).
 */

if (empty($followItemType) || empty($followItemId)) return;

$_flLoggedIn  = isset($_SESSION['user_id']);
$_flFollowing = false;
$_flFollowId  = 0;

if ($_flLoggedIn) {
    try {
        $chk = db()->prepare("
            SELECT id FROM user_follows
            WHERE user_id = ? AND follow_type = ? AND follow_id = ?
              AND unfollowed_at IS NULL
            LIMIT 1
        ");
        $chk->execute([(int)$_SESSION['user_id'], $followItemType, (int)$followItemId]);
        $chkRow = $chk->fetch();
        $_flFollowing = (bool)$chkRow;
        $_flFollowId  = $chkRow ? (int)$chkRow['id'] : 0;
    } catch (Exception $e) {}
}

$_flHtmlId = 'follow-btn-' . $followItemType . '-' . (int)$followItemId;
?>

<?php if ($_flLoggedIn): ?>
<button id="<?= $_flHtmlId ?>"
        class="follow-btn <?= $_flFollowing ? 'is-following' : '' ?>"
        data-follow-type="<?= e($followItemType) ?>"
        data-follow-id="<?= (int)$followItemId ?>"
        data-row-id="<?= $_flFollowId ?>"
        data-csrf="<?= csrf() ?>"
        aria-label="<?= $_flFollowing ? t('Unfollow business','Ne plus suivre') : t('Follow business','Suivre') ?>"
        title="<?= $_flFollowing ? t('Following','Abonné') : t('Follow','Suivre') ?>">
  <span class="follow-btn-icon"><?= $_flFollowing ? '🔔' : '🔕' ?></span>
  <span class="follow-btn-label"><?= $_flFollowing ? t('Following','Abonné') : t('Follow','Suivre') ?></span>
</button>
<?php else: ?>
<a href="<?= SITE_URL ?>/login?redirect=<?= urlencode($_SERVER['REQUEST_URI'] ?? '/') ?>"
   class="follow-btn"
   title="<?= t('Sign in to follow','Connectez-vous pour suivre') ?>">
  <span class="follow-btn-icon">🔕</span>
  <span class="follow-btn-label"><?= t('Follow','Suivre') ?></span>
</a>
<?php endif; ?>

<style>
.follow-btn {
  display: inline-flex;
  align-items: center;
  gap: .45em;
  padding: .45em 1em;
  border-radius: 999px;
  background: rgba(255,255,255,0.06);
  border: 1px solid rgba(255,255,255,0.15);
  color: inherit;
  cursor: pointer;
  text-decoration: none;
  font-size: .85rem;
  font-weight: 500;
  transition: background .15s, border-color .15s, transform .12s;
  flex-shrink: 0;
}
.follow-btn:hover { background: rgba(255,255,255,0.1); transform: scale(1.04); }
.follow-btn.is-following {
  background: rgba(0,168,120,0.12);
  border-color: rgba(0,168,120,0.4);
  color: #00A878;
}
.follow-btn.loading { opacity: .5; pointer-events: none; }
.follow-btn-icon { font-size: 1em; line-height: 1; }
</style>

<?php if (!defined('_FOLLOW_BTN_JS_LOADED')): define('_FOLLOW_BTN_JS_LOADED', true); ?>
<script>
document.addEventListener('click', function(e) {
  var btn = e.target.closest('button.follow-btn');
  if (!btn) return;
  e.preventDefault();

  btn.classList.add('loading');
  var followType = btn.dataset.followType;
  var followId   = parseInt(btn.dataset.followId, 10);
  var csrf       = btn.dataset.csrf;

  fetch('<?= SITE_URL ?>/api/follows', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({action:'toggle', follow_type:followType, follow_id:followId, csrf:csrf}),
  })
  .then(function(r){ return r.json(); })
  .then(function(d){
    btn.classList.remove('loading');
    if (!d.ok) {
      if (d.redirect) { window.location.href = d.redirect; return; }
      alert(d.error || 'Error');
      return;
    }
    var icon  = btn.querySelector('.follow-btn-icon');
    var label = btn.querySelector('.follow-btn-label');
    if (d.following) {
      btn.classList.add('is-following');
      btn.dataset.rowId = d.row_id;
      icon.textContent  = '🔔';
      label.textContent = '<?= t('Following','Abonné') ?>';
      btn.setAttribute('aria-label', '<?= t('Unfollow business','Ne plus suivre') ?>');
      btn.title = '<?= t('Following','Abonné') ?>';
    } else {
      btn.classList.remove('is-following');
      btn.dataset.rowId = '0';
      icon.textContent  = '🔕';
      label.textContent = '<?= t('Follow','Suivre') ?>';
      btn.setAttribute('aria-label', '<?= t('Follow business','Suivre') ?>');
      btn.title = '<?= t('Follow','Suivre') ?>';
    }
  })
  .catch(function(){ btn.classList.remove('loading'); });
});
</script>
<?php endif; ?>
