<?php
// includes/event-popup.php — Business Owners Gathering flyer popup (The Tahirih Effect x Sabi Pot x 237Biz)
// Included from index.php. Self-contained: needs only t(). Auto-expires after the event ends.
// To retire early, delete the require line in index.php (or this file).

// Event ends Thu 29 Oct 2026, 6pm Cameroon time (UTC+1)
if (time() > strtotime('2026-10-29 18:00:00 +0100')) { return; }

$wa1 = '670673339';
$wa2 = '670522785';
$fmt = function ($n) { return substr($n, 0, 3) . ' ' . substr($n, 3, 3) . ' ' . substr($n, 6); };
?>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,800&display=swap" rel="stylesheet">
<style>
/* --u = one "flyer pixel" in real px (set by JS). Flyer is designed on a 1080x1350 canvas;
   small text has a minimum size so it stays readable on phones. */
#evtPop { --u:.5; display:none; position:fixed; inset:0; z-index:2000; background:rgba(4,16,10,0.82); overflow-y:auto; padding:16px; box-sizing:border-box; }
#evtPop.open { display:flex; }
#evtBox { margin:auto; width:calc(var(--u)*1080px); border-radius:14px; overflow:hidden; box-shadow:0 24px 80px rgba(0,0,0,0.55); flex:none; }
#evtFlyer { box-sizing:border-box; width:100%; min-height:calc(var(--u)*1350px); padding:calc(var(--u)*64px) calc(var(--u)*72px); background:#0B3D2E; color:#F7F5EE; font-family:'DM Sans',sans-serif; display:flex; flex-direction:column; justify-content:space-between; gap:calc(var(--u)*24px); }
#evtFlyer a { color:inherit; }
#evtFlyer .d { font-family:'Bricolage Grotesque','Fraunces',sans-serif; font-weight:800; }
#evtFlyer .a { color:#F2B33D; }
#evtFlyer .row { display:flex; align-items:center; justify-content:space-between; }
#evtFlyer .col { display:flex; flex-direction:column; }
#evtFlyer .mut { color:#DCE6DF; }
.f-hd  { font-size:max(9.5px,calc(var(--u)*22px)); font-weight:700; letter-spacing:calc(var(--u)*2px); }
.f-bdg { align-self:flex-start; border:2px solid #F2B33D; padding:calc(var(--u)*10px) calc(var(--u)*22px); font-size:max(9.5px,calc(var(--u)*22px)); font-weight:700; letter-spacing:calc(var(--u)*3px); }
.f-h1  { font-size:calc(var(--u)*118px); line-height:.98; letter-spacing:calc(var(--u)*-2px); }
.f-p   { margin:0; font-size:max(13px,calc(var(--u)*32px)); line-height:1.35; max-width:calc(var(--u)*860px); }
.f-big { font-size:calc(var(--u)*250px); line-height:.8; }
.f-mon { font-size:max(18px,calc(var(--u)*60px)); line-height:1; }
.f-inf { font-size:max(12px,calc(var(--u)*30px)); font-weight:500; }
.f-num { font-size:max(9.5px,calc(var(--u)*22px)); font-weight:700; letter-spacing:calc(var(--u)*2px); }
.f-ttl { font-size:max(14px,calc(var(--u)*38px)); }
.f-sub { font-size:max(11px,calc(var(--u)*23px)); line-height:1.3; }
.f-lbl { font-size:max(9px,calc(var(--u)*22px)); font-weight:700; letter-spacing:calc(var(--u)*3px); }
.f-prc { font-size:max(24px,calc(var(--u)*64px)); line-height:1; }
.f-reg { font-size:max(12px,calc(var(--u)*30px)); font-weight:700; text-align:right; line-height:1.25; }
.f-ft  { font-size:max(11px,calc(var(--u)*26px)); font-weight:700; letter-spacing:calc(var(--u)*1px); }
#evtClose { position:fixed; top:12px; right:12px; z-index:2; width:44px; height:44px; border:0; border-radius:50%; background:#F2B33D; color:#0B3D2E; font-size:28px; line-height:1; cursor:pointer; box-shadow:0 2px 10px rgba(0,0,0,0.45); }
#evtClose:focus-visible { outline:3px solid #F7F5EE; outline-offset:2px; }
</style>

<div id="evtPop" role="dialog" aria-modal="true" aria-label="<?= t('Business owners gathering','Rencontre des chefs d\'entreprise') ?>">
  <button id="evtClose" type="button" aria-label="<?= t('Close','Fermer') ?>">&times;</button>
  <div id="evtBox">
    <div id="evtFlyer">

      <div class="row f-hd">
        <span>THE TAHIRIH EFFECT</span><span class="a">&times;</span><span>SABI POT</span><span class="a">&times;</span><span>237BIZ.NET</span>
      </div>

      <div class="col" style="gap:calc(var(--u)*28px)">
        <div class="f-bdg a"><?= t('EXCLUSIVE &middot; BUSINESS OWNERS','EXCLUSIF &middot; CHEFS D\'ENTREPRISE') ?></div>
        <div class="f-h1 d"><?= t('Get your business <span class="a">seen.</span>','Rendez votre entreprise <span class="a">visible.</span>') ?></div>
        <p class="f-p mut"><?= t('An exclusive 2-hour gathering bringing business owners together to grow visibility, win customers and build valuable connections.','Une rencontre exclusive de 2 heures qui réunit des chefs d\'entreprise pour gagner en visibilité, attirer des clients et créer des connexions utiles.') ?></p>
      </div>

      <div style="display:flex;align-items:flex-end;gap:calc(var(--u)*36px);border-top:2px solid rgba(247,245,238,0.25);padding-top:calc(var(--u)*28px)">
        <div class="f-big d a">29</div>
        <div class="col" style="gap:calc(var(--u)*8px);padding-bottom:calc(var(--u)*6px)">
          <div class="f-mon d"><?= t('OCTOBER 2026','OCTOBRE 2026') ?></div>
          <div class="f-inf mut"><?= t('Thursday &middot; 4pm to 6pm &middot; 2 hours','Jeudi &middot; 16h à 18h &middot; 2 heures') ?></div>
          <div class="f-inf mut">Lumpsom, <?= t('Adjacent Kontri Man','à côté de Kontri Man') ?></div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:calc(var(--u)*28px)">
        <div><div class="f-num a">01</div><div class="f-ttl d"><?= t('Visibility','Visibilité') ?></div><div class="f-sub mut"><?= t('Reach more potential customers.','Touchez plus de clients potentiels.') ?></div></div>
        <div><div class="f-num a">02</div><div class="f-ttl d"><?= t('Growth','Croissance') ?></div><div class="f-sub mut"><?= t('Practical ways to grow your business.','Des moyens concrets de développer votre entreprise.') ?></div></div>
        <div><div class="f-num a">03</div><div class="f-ttl d"><?= t('Connections','Connexions') ?></div><div class="f-sub mut"><?= t('Meet owners who can open doors.','Rencontrez des entrepreneurs qui ouvrent des portes.') ?></div></div>
      </div>

      <div class="row" style="background:#F2B33D;color:#0B3D2E;padding:calc(var(--u)*26px) calc(var(--u)*36px);gap:calc(var(--u)*16px)">
        <div class="col" style="gap:calc(var(--u)*2px)">
          <div class="f-lbl"><?= t('PARTICIPATION &middot; PER BUSINESS','PARTICIPATION &middot; PAR ENTREPRISE') ?></div>
          <div class="f-prc d">5,000 FCFA</div>
        </div>
        <div class="f-reg">
          <?= t('Register on WhatsApp:','Inscription sur WhatsApp :') ?><br>
          <a href="https://wa.me/237<?= $wa1 ?>" target="_blank" rel="noopener" style="text-decoration:underline"><?= $fmt($wa1) ?></a><br>
          <a href="https://wa.me/237<?= $wa2 ?>" target="_blank" rel="noopener" style="text-decoration:underline"><?= $fmt($wa2) ?></a>
        </div>
      </div>

      <div class="row f-ft">
        <span>thetahiriheffect.com</span><span class="a">&middot;</span><span>237biz.net</span>
      </div>

    </div>
  </div>
</div>

<script>
(function () {
  var KEY = 'evtPop_2026_10_29', DAY = 86400000;
  var pop = document.getElementById('evtPop'), closeBtn = document.getElementById('evtClose'), lastFocus = null;
  try { var t0 = parseInt(localStorage.getItem(KEY) || '0', 10); if (t0 && Date.now() - t0 < DAY) return; } catch (e) {}

  function fit() {
    var w = window.innerWidth, h = window.innerHeight;
    // Phones: fit width and let the popup scroll; larger screens: fit the whole flyer on screen
    var u = w < 700 ? (w - 32) / 1080 : Math.min(1, (w - 32) / 1080, (h - 32) / 1350);
    pop.style.setProperty('--u', u.toFixed(4));
  }
  function open() { lastFocus = document.activeElement; fit(); pop.classList.add('open'); closeBtn.focus(); }
  function close() {
    pop.classList.remove('open');
    try { localStorage.setItem(KEY, String(Date.now())); } catch (e) {}
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }
  closeBtn.addEventListener('click', close);
  pop.addEventListener('click', function (e) { if (e.target === pop) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && pop.classList.contains('open')) close(); });
  window.addEventListener('resize', function () { if (pop.classList.contains('open')) fit(); });
  setTimeout(open, 1500);
})();
</script>
