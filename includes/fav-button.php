<?php
/**
 * Favourite heart button — include on listing, offer, and event pages.
 *
 * Usage:
 *   $favItemType = 'listing'; // or 'offer' / 'event'
 *   $favItemId   = (int)$listing['id'];
 *   require __DIR__ . '/includes/fav-button.php';
 *
 * Outputs a <button> (or login link) + inline JS. Safe to include multiple
 * times per page (guards with a once-flag on the JS block).
 */

// Only render if we have the required variables
if (empty($favItemType) || empty($favItemId)) return;

$_favLoggedIn = isset($_SESSION['user_id']);
$_favInitSaved = false;
$_favInitId    = 0;

if ($_favLoggedIn) {
    try {
        $chk = db()->prepare("SELECT id FROM user_favourites WHERE user_id=? AND item_type=? AND item_id=? LIMIT 1");
        $chk->execute([(int)$_SESSION['user_id'], $favItemType, (int)$favItemId]);
        $chkRow = $chk->fetch();
        $_favInitSaved = (bool)$chkRow;
        $_favInitId    = $chkRow ? (int)$chkRow['id'] : 0;
    } catch (Exception $e) {}
}

$_favHtmlId = 'fav-btn-' . $favItemType . '-' . (int)$favItemId;
?>

<?php if ($_favLoggedIn): ?>
<button id="<?= $_favHtmlId ?>"
        class="fav-heart-btn <?= $_favInitSaved ? 'is-saved' : '' ?>"
        data-item-type="<?= e($favItemType) ?>"
        data-item-id="<?= (int)$favItemId ?>"
        data-fav-id="<?= $_favInitId ?>"
        data-csrf="<?= csrf() ?>"
        aria-label="<?= $_favInitSaved ? t('Remove from favourites','Retirer des favoris') : t('Save to favourites','Ajouter aux favoris') ?>"
        title="<?= $_favInitSaved ? t('Saved','Enregistré') : t('Save','Enregistrer') ?>">
  <span class="fav-heart-icon"><?= $_favInitSaved ? '❤️' : '🤍' ?></span>
</button>
<?php else: ?>
<a href="<?= SITE_URL ?>/login?redirect=<?= urlencode($_SERVER['REQUEST_URI'] ?? '/') ?>"
   class="fav-heart-btn"
   title="<?= t('Sign in to save','Connectez-vous pour enregistrer') ?>">
  <span class="fav-heart-icon">🤍</span>
</a>
<?php endif; ?>

<style>
.fav-heart-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 38px;
  height: 38px;
  border-radius: 50%;
  background: rgba(255,255,255,0.06);
  border: 1px solid rgba(255,255,255,0.12);
  cursor: pointer;
  text-decoration: none;
  transition: background .15s, transform .12s;
  flex-shrink: 0;
}
.fav-heart-btn:hover { background: rgba(255,255,255,0.1); transform: scale(1.08); }
.fav-heart-btn.is-saved { background: rgba(220,50,50,0.1); border-color: rgba(220,50,50,0.3); }
.fav-heart-btn.loading  { opacity: .5; pointer-events: none; }
.fav-heart-icon { font-size: 1rem; line-height: 1; }
</style>

<?php if (!defined('_FAV_BTN_JS_LOADED')): define('_FAV_BTN_JS_LOADED', true); ?>
<script>
document.addEventListener('click', function(e) {
  var btn = e.target.closest('button.fav-heart-btn');
  if (!btn) return;
  e.preventDefault();

  btn.classList.add('loading');
  var itemType = btn.dataset.itemType;
  var itemId   = parseInt(btn.dataset.itemId, 10);
  var csrf     = btn.dataset.csrf;

  fetch('<?= SITE_URL ?>/api/favourites.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({action:'toggle', item_type:itemType, item_id:itemId, csrf:csrf}),
  })
  .then(function(r){ return r.json(); })
  .then(function(d){
    btn.classList.remove('loading');
    if (!d.ok) { alert(d.error || 'Error'); return; }
    var icon = btn.querySelector('.fav-heart-icon');
    if (d.saved) {
      btn.classList.add('is-saved');
      btn.dataset.favId = d.fav_id;
      icon.textContent = '❤️';
      btn.setAttribute('aria-label', '<?= t('Remove from favourites','Retirer des favoris') ?>');
      btn.title = '<?= t('Saved','Enregistré') ?>';
    } else {
      btn.classList.remove('is-saved');
      btn.dataset.favId = '0';
      icon.textContent = '🤍';
      btn.setAttribute('aria-label', '<?= t('Save to favourites','Ajouter aux favoris') ?>');
      btn.title = '<?= t('Save','Enregistrer') ?>';
    }
  })
  .catch(function(){ btn.classList.remove('loading'); });
});
</script>
<?php endif; ?>
