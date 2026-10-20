<?php
/**
 * event.php — Single Event Detail + Registration
 * URL: /event/{slug}
 */
require_once __DIR__ . '/includes/config.php';
$isFr = lang() === 'fr';

// ── Resolve slug ──────────────────────────────────────────────────────────
$slug = $_GET['slug'] ?? basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$slug = preg_replace('/[^a-z0-9-]/', '', strtolower($slug));

if (!$slug) { redirect(SITE_URL . '/events'); }

// ── Load event ────────────────────────────────────────────────────────────
try {
    $st = db()->prepare("
        SELECT e.*,
               loc.name_en AS loc_en, loc.name_fr AS loc_fr,
               l.title AS biz_name, l.slug AS biz_slug, l.logo AS biz_logo,
               l.phone AS biz_phone, l.whatsapp AS biz_wa
        FROM events e
        LEFT JOIN locations loc ON loc.id = e.location_id
        LEFT JOIN listings  l   ON l.id  = e.listing_id
        WHERE e.slug = ?
    ");
    $st->execute([$slug]);
    $ev = $st->fetch();
} catch (Exception $e) { $ev = null; }

if (!$ev) { redirect(SITE_URL . '/events'); }

$isApproved = $ev['status'] === 'approved';
if (!$isApproved && !isAdmin()) { redirect(SITE_URL . '/events'); }

$etitle = $isFr && $ev['title_fr'] ? $ev['title_fr'] : $ev['title'];
$edesc  = $isFr && $ev['description_fr'] ? $ev['description_fr'] : $ev['description'];
$locName = $isFr ? $ev['loc_fr'] : $ev['loc_en'];

// ── Registration count ────────────────────────────────────────────────────
$regCount = (int)$ev['registered_count'];
$spotsLeft = $ev['capacity'] ? max(0, $ev['capacity'] - $regCount) : null;
$isFull    = $spotsLeft !== null && $spotsLeft === 0;
$hasEnded  = strtotime($ev['starts_at']) < time();

// ── Handle registration ───────────────────────────────────────────────────
$regSuccess = false;
$regError   = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register') {
    verifyCsrf();
    $rName    = trim($_POST['reg_name']  ?? '');
    $rEmail   = trim($_POST['reg_email'] ?? '');
    $rPhone   = trim($_POST['reg_phone'] ?? '');
    $rNotes   = trim($_POST['reg_notes'] ?? '');
    $rQty     = max(1, (int)($_POST['reg_qty'] ?? 1));

    if (!$rName || !$rEmail || !filter_var($rEmail, FILTER_VALIDATE_EMAIL)) {
        $regError = t('Please enter a valid name and email.','Veuillez saisir un nom et un email valides.');
    } elseif ($isFull) {
        $regError = t('This event is fully booked.','Cet événement est complet.');
    } elseif ($hasEnded) {
        $regError = t('This event has already taken place.','Cet événement a déjà eu lieu.');
    } else {
        // Check duplicate
        try {
            $dup = db()->prepare("SELECT id FROM event_registrations WHERE event_id=? AND email=?");
            $dup->execute([$ev['id'], $rEmail]);
            if ($dup->fetch()) {
                $regError = t('You are already registered for this event.','Vous êtes déjà inscrit à cet événement.');
            }
        } catch (Exception $e) {}

        if (!$regError) {
            try {
                $ins = db()->prepare("
                    INSERT INTO event_registrations
                      (event_id, name, email, phone, ticket_qty, notes, status, registered_at)
                    VALUES (?,?,?,?,?,'','confirmed',NOW())
                ");
                $ins->execute([$ev['id'], $rName, $rEmail, $rPhone, $rQty]);
                // bump counter
                db()->prepare("UPDATE events SET registered_count = registered_count + ? WHERE id=?")->execute([$rQty, $ev['id']]);
                $regCount  += $rQty;
                $spotsLeft  = $ev['capacity'] ? max(0, $ev['capacity'] - $regCount) : null;
                $isFull     = $spotsLeft !== null && $spotsLeft === 0;
                $regSuccess = true;
            } catch (Exception $e) {
                $regError = t('Registration failed. Please try again.','Inscription échouée. Veuillez réessayer.');
            }
        }
    }
}

// ── More events from same organiser ───────────────────────────────────────
$moreEvents = [];
if ($ev['listing_id']) {
    try {
        $me = db()->prepare("
            SELECT slug, title, title_fr, starts_at, is_free, ticket_price, is_online, event_type
            FROM events
            WHERE listing_id=? AND id!=? AND status='approved' AND starts_at > NOW()
            ORDER BY starts_at ASC LIMIT 3
        ");
        $me->execute([$ev['listing_id'], $ev['id']]);
        $moreEvents = $me->fetchAll();
    } catch (Exception $e) {}
}

$typeEmoji = [
    'workshop'=>'🛠','networking'=>'🤝','exhibition'=>'🖼',
    'conference'=>'🎤','social'=>'🎉','other'=>'📌',
];

$pageTitle = htmlspecialchars($etitle, ENT_QUOTES, 'UTF-8') . ' — 237Biz Events';
require_once __DIR__ . '/includes/header.php';
?>
<style>
.ev-detail-wrap { max-width:1000px; margin:0 auto; padding:1.5rem 1rem; }
.ev-detail-grid { display:grid; grid-template-columns:1fr 320px; gap:1.5rem; }
@media(max-width:720px) { .ev-detail-grid { grid-template-columns:1fr; } }

/* Breadcrumb */
.ev-bc { font-size:0.78rem; color:#5a8878; margin-bottom:1.2rem; }
.ev-bc a { color:#00A878; text-decoration:none; }
.ev-bc a:hover { text-decoration:underline; }

/* Admin notice */
.ev-admin-notice {
  background:rgba(251,191,36,0.1); border:1px solid rgba(251,191,36,0.3);
  border-radius:8px; padding:0.6rem 1rem; margin-bottom:1rem;
  font-size:0.82rem; color:#fbbf24;
}

/* Main image */
.ev-hero-img { width:100%; border-radius:12px; aspect-ratio:16/7; object-fit:cover;
  background:rgba(0,168,120,0.06); margin-bottom:1.2rem; }
.ev-hero-thumb { width:100%; border-radius:12px; aspect-ratio:16/7;
  background:linear-gradient(135deg,rgba(0,168,120,0.1),rgba(0,168,120,0.03));
  display:flex; align-items:center; justify-content:center;
  font-size:5rem; margin-bottom:1.2rem; }

.ev-title { font-family:'Fraunces',serif; font-weight:900;
  font-size:clamp(1.5rem,3vw,2.2rem); color:#f0faf5; line-height:1.2; margin-bottom:0.6rem; }

.ev-pills { display:flex; flex-wrap:wrap; gap:0.4rem; margin-bottom:1.2rem; }
.ev-pill {
  font-size:0.72rem; font-weight:700; padding:0.25rem 0.65rem;
  border-radius:99px;
}
.ev-pill-type   { background:rgba(255,255,255,0.07); color:#8db8a8; }
.ev-pill-free   { background:rgba(74,222,128,0.12); color:#4ade80; }
.ev-pill-paid   { background:rgba(251,191,36,0.12); color:#fbbf24; }
.ev-pill-online { background:rgba(96,165,250,0.12); color:#60a5fa; }
.ev-pill-full   { background:rgba(239,68,68,0.12); color:#ef4444; }

.ev-desc h2 { font-family:'Fraunces',serif; font-weight:800; font-size:1.05rem;
  color:#e8f5ee; margin:1.2rem 0 0.5rem; }
.ev-desc p  { color:#8db8a8; font-size:0.9rem; line-height:1.65; margin-bottom:0.75rem; }

/* Organiser box */
.ev-org-box {
  display:flex; align-items:center; gap:0.75rem;
  background:rgba(255,255,255,0.03); border:1px solid rgba(0,168,120,0.12);
  border-radius:10px; padding:0.8rem; margin-top:1.5rem;
}
.ev-org-logo { width:44px; height:44px; border-radius:8px; object-fit:cover;
  background:rgba(0,168,120,0.1); }
.ev-org-name { font-weight:700; color:#e8f5ee; font-size:0.88rem; }
.ev-org-link { font-size:0.75rem; color:#00A878; text-decoration:none; }

/* Sidebar */
.ev-sidebar { display:flex; flex-direction:column; gap:1rem; }
.ev-info-card {
  background:rgba(255,255,255,0.03); border:1px solid rgba(0,168,120,0.12);
  border-radius:12px; padding:1.2rem;
}
.ev-info-card h3 { font-family:'Fraunces',serif; font-weight:800; font-size:1rem;
  color:#e8f5ee; margin-bottom:0.8rem; }
.ev-info-row { display:flex; gap:0.6rem; margin-bottom:0.6rem; font-size:0.85rem; }
.ev-info-icon { color:#00A878; min-width:18px; }
.ev-info-label { color:#8db8a8; }
.ev-info-val { color:#e8f5ee; }

.ev-reg-btn {
  display:block; text-align:center; background:#00A878; color:#fff;
  border:none; border-radius:10px; padding:0.85rem; font-size:0.95rem;
  font-weight:800; cursor:pointer; text-decoration:none; margin-top:0.5rem; width:100%;
}
.ev-reg-btn:hover { background:#00c48f; }
.ev-reg-btn:disabled { background:#2a4a3e; color:#5a8878; cursor:default; }

.ev-wa-btn {
  display:flex; align-items:center; justify-content:center; gap:0.5rem;
  background:rgba(37,211,102,0.12); border:1px solid rgba(37,211,102,0.2);
  color:#25D366; border-radius:10px; padding:0.7rem; font-size:0.88rem;
  font-weight:700; text-decoration:none; margin-top:0.5rem;
}
.ev-wa-btn:hover { background:rgba(37,211,102,0.2); }

/* Registration form */
.ev-reg-form { background:rgba(0,168,120,0.04); border:1px solid rgba(0,168,120,0.15);
  border-radius:12px; padding:1.2rem; margin-top:0.5rem; }
.ev-reg-form h4 { font-family:'Fraunces',serif; font-weight:800; color:#e8f5ee;
  font-size:0.95rem; margin-bottom:0.8rem; }
.ev-form-field { margin-bottom:0.65rem; }
.ev-form-field label { display:block; font-size:0.75rem; color:#5a8878;
  font-weight:700; margin-bottom:0.25rem; text-transform:uppercase; letter-spacing:0.04em; }
.ev-form-field input, .ev-form-field textarea, .ev-form-field select {
  width:100%; background:rgba(0,0,0,0.3); border:1px solid rgba(0,168,120,0.2);
  color:#e8f5ee; border-radius:8px; padding:0.55rem 0.75rem; font-size:0.85rem;
}
.ev-form-field input:focus, .ev-form-field textarea:focus { outline:none; border-color:#00A878; }
.ev-error { background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.25);
  color:#ef4444; border-radius:8px; padding:0.55rem 0.75rem; font-size:0.82rem; margin-bottom:0.5rem; }
.ev-success { background:rgba(74,222,128,0.1); border:1px solid rgba(74,222,128,0.25);
  color:#4ade80; border-radius:8px; padding:0.8rem; font-size:0.85rem; text-align:center; }

/* More events */
.ev-more { margin-top:2.5rem; }
.ev-more h3 { font-family:'Fraunces',serif; font-weight:800; color:#e8f5ee;
  font-size:1.05rem; margin-bottom:0.8rem; }
.ev-more-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:0.8rem; }
.ev-more-card {
  background:rgba(255,255,255,0.03); border:1px solid rgba(0,168,120,0.1);
  border-radius:10px; padding:0.9rem; text-decoration:none; display:block;
  transition:border-color 0.12s;
}
.ev-more-card:hover { border-color:rgba(0,168,120,0.3); }
.ev-more-date { font-size:0.7rem; font-weight:700; color:#00A878; text-transform:uppercase; }
.ev-more-title { font-family:'Fraunces',serif; font-weight:800; color:#e8f5ee;
  font-size:0.9rem; margin-top:0.2rem; }
</style>

<div class="ev-detail-wrap">

  <!-- Breadcrumb -->
  <div class="ev-bc">
    <a href="<?= SITE_URL ?>/events">← <?= t('Events','Événements') ?></a>
    › <?= htmlspecialchars($etitle, ENT_QUOTES, 'UTF-8') ?>
  </div>

  <!-- Admin preview notice -->
  <?php if (!$isApproved && isAdmin()): ?>
    <div class="ev-admin-notice">
      ⚠️ <?= t('Admin Preview — Status:','Aperçu Admin — Statut :') ?>
      <strong><?= htmlspecialchars(ucfirst($ev['status']), ENT_QUOTES, 'UTF-8') ?></strong>
    </div>
  <?php endif; ?>

  <div class="ev-detail-grid">

    <!-- LEFT: main content -->
    <div>
      <?php if ($ev['image']): ?>
        <img src="<?= UPLOAD_URL . htmlspecialchars($ev['image'], ENT_QUOTES, 'UTF-8') ?>"
             alt="<?= htmlspecialchars($etitle, ENT_QUOTES, 'UTF-8') ?>" class="ev-hero-img">
      <?php else: ?>
        <div class="ev-hero-thumb"><?= $typeEmoji[$ev['event_type']] ?? '📅' ?></div>
      <?php endif; ?>

      <h1 class="ev-title"><?= htmlspecialchars($etitle, ENT_QUOTES, 'UTF-8') ?></h1>

      <!-- Pills -->
      <div class="ev-pills">
        <?php $tl = ['workshop'=>t('Workshop','Atelier'),'networking'=>t('Networking','Réseautage'),
          'exhibition'=>t('Exhibition','Exposition'),'conference'=>t('Conference','Conférence'),
          'social'=>t('Social','Social'),'other'=>t('Other','Autre')]; ?>
        <span class="ev-pill ev-pill-type">
          <?= ($typeEmoji[$ev['event_type']]??'📌') ?> <?= $tl[$ev['event_type']] ?? $ev['event_type'] ?>
        </span>
        <?php if ($ev['is_free']): ?>
          <span class="ev-pill ev-pill-free"><?= t('Free','Gratuit') ?></span>
        <?php elseif ($ev['ticket_price']): ?>
          <span class="ev-pill ev-pill-paid"><?= number_format($ev['ticket_price']) ?> XAF</span>
        <?php endif; ?>
        <?php if ($ev['is_online']): ?>
          <span class="ev-pill ev-pill-online">🌐 <?= t('Online','En ligne') ?></span>
        <?php endif; ?>
        <?php if ($isFull): ?>
          <span class="ev-pill ev-pill-full"><?= t('Fully Booked','Complet') ?></span>
        <?php endif; ?>
      </div>

      <!-- Description -->
      <div class="ev-desc">
        <?php if ($edesc): ?>
          <h2><?= t('About This Event','À propos de cet événement') ?></h2>
          <?php foreach (explode("\n\n", $edesc) as $para): if (trim($para)): ?>
            <p><?= nl2br(htmlspecialchars(trim($para), ENT_QUOTES, 'UTF-8')) ?></p>
          <?php endif; endforeach; ?>
        <?php endif; ?>
      </div>

      <!-- Organiser -->
      <?php if ($ev['biz_name']): ?>
        <div class="ev-org-box">
          <?php if ($ev['biz_logo']): ?>
            <img src="<?= UPLOAD_URL . htmlspecialchars($ev['biz_logo'], ENT_QUOTES, 'UTF-8') ?>"
                 alt="<?= htmlspecialchars($ev['biz_name'], ENT_QUOTES, 'UTF-8') ?>" class="ev-org-logo">
          <?php else: ?>
            <div class="ev-org-logo" style="display:flex;align-items:center;justify-content:center;font-size:1.4rem;">🏢</div>
          <?php endif; ?>
          <div>
            <div class="ev-org-name"><?= htmlspecialchars($ev['biz_name'], ENT_QUOTES, 'UTF-8') ?></div>
            <a href="<?= SITE_URL ?>/listing/<?= htmlspecialchars($ev['biz_slug'], ENT_QUOTES, 'UTF-8') ?>" class="ev-org-link">
              <?= t('View Business Profile →','Voir le Profil Entreprise →') ?>
            </a>
          </div>
        </div>
      <?php endif; ?>

      <!-- More events -->
      <?php if ($moreEvents): ?>
        <div class="ev-more">
          <h3><?= t('More Events from this Organiser','Plus d\'événements de cet organisateur') ?></h3>
          <div class="ev-more-grid">
            <?php foreach ($moreEvents as $me):
              $mt = $isFr && $me['title_fr'] ? $me['title_fr'] : $me['title'];
            ?>
              <a href="<?= SITE_URL ?>/event/<?= htmlspecialchars($me['slug'], ENT_QUOTES, 'UTF-8') ?>" class="ev-more-card">
                <div class="ev-more-date"><?= date('D d M', strtotime($me['starts_at'])) ?></div>
                <div class="ev-more-title"><?= htmlspecialchars($mt, ENT_QUOTES, 'UTF-8') ?></div>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <!-- RIGHT: sidebar -->
    <aside class="ev-sidebar">

      <!-- Event info card -->
      <div class="ev-info-card">
        <h3>📋 <?= t('Event Details','Détails') ?></h3>

        <div class="ev-info-row">
          <span class="ev-info-icon">📅</span>
          <div>
            <div class="ev-info-val"><?= date('l d F Y', strtotime($ev['starts_at'])) ?></div>
            <div class="ev-info-label"><?= date('H:i', strtotime($ev['starts_at'])) ?>
              <?php if ($ev['ends_at']): ?> – <?= date('H:i', strtotime($ev['ends_at'])) ?><?php endif; ?>
            </div>
          </div>
        </div>

        <?php if ($ev['is_online']): ?>
          <div class="ev-info-row">
            <span class="ev-info-icon">🌐</span>
            <div class="ev-info-val"><?= t('Online Event','Événement en ligne') ?></div>
          </div>
        <?php elseif ($ev['venue_name'] || $ev['venue_address']): ?>
          <div class="ev-info-row">
            <span class="ev-info-icon">📍</span>
            <div>
              <?php if ($ev['venue_name']): ?>
                <div class="ev-info-val"><?= htmlspecialchars($ev['venue_name'], ENT_QUOTES, 'UTF-8') ?></div>
              <?php endif; ?>
              <?php if ($ev['venue_address']): ?>
                <div class="ev-info-label"><?= htmlspecialchars($ev['venue_address'], ENT_QUOTES, 'UTF-8') ?></div>
              <?php endif; ?>
              <?php if ($locName): ?>
                <div class="ev-info-label">📌 <?= htmlspecialchars($locName, ENT_QUOTES, 'UTF-8') ?></div>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>

        <div class="ev-info-row">
          <span class="ev-info-icon">🎟</span>
          <div class="ev-info-val">
            <?php if ($ev['is_free']): ?>
              <?= t('Free Entry','Entrée Libre') ?>
            <?php elseif ($ev['ticket_price']): ?>
              <?= number_format($ev['ticket_price']) ?> XAF
            <?php else: ?>
              <?= t('Contact Organiser','Contacter l\'organisateur') ?>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($ev['capacity']): ?>
          <div class="ev-info-row">
            <span class="ev-info-icon">👥</span>
            <div>
              <div class="ev-info-val"><?= $regCount ?>/<?= $ev['capacity'] ?> <?= t('registered','inscrits') ?></div>
              <?php if ($spotsLeft !== null && $spotsLeft <= 10 && !$isFull): ?>
                <div class="ev-info-label" style="color:#fbbf24;">
                  ⚠ <?= $spotsLeft ?> <?= t('spots left','places restantes') ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <!-- Registration -->
      <div class="ev-info-card">
        <h3>✍️ <?= t('Register','S\'inscrire') ?></h3>

        <?php if ($hasEnded): ?>
          <p style="color:#5a8878;font-size:0.85rem;"><?= t('This event has already taken place.','Cet événement a déjà eu lieu.') ?></p>

        <?php elseif ($regSuccess): ?>
          <div class="ev-success">
            ✅ <?= t('You\'re registered! See you there.','Vous êtes inscrit ! À bientôt.') ?>
          </div>

        <?php else: ?>
          <?php if ($regError): ?>
            <div class="ev-error"><?= htmlspecialchars($regError, ENT_QUOTES, 'UTF-8') ?></div>
          <?php endif; ?>

          <?php if ($isFull): ?>
            <button class="ev-reg-btn" disabled><?= t('Event Full','Événement Complet') ?></button>
          <?php else: ?>
            <form method="POST" class="ev-reg-form">
              <?= csrf() ?>
              <input type="hidden" name="action" value="register">
              <h4><?= t('Your Details','Vos Coordonnées') ?></h4>
              <div class="ev-form-field">
                <label><?= t('Full Name *','Nom Complet *') ?></label>
                <input type="text" name="reg_name" required
                       value="<?= htmlspecialchars($_POST['reg_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="<?= t('Jean Fotso','Jean Fotso') ?>">
              </div>
              <div class="ev-form-field">
                <label><?= t('Email *','Email *') ?></label>
                <input type="email" name="reg_email" required
                       value="<?= htmlspecialchars($_POST['reg_email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
              </div>
              <div class="ev-form-field">
                <label><?= t('Phone (optional)','Téléphone (optionnel)') ?></label>
                <input type="tel" name="reg_phone"
                       value="<?= htmlspecialchars($_POST['reg_phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="+237 6xx xxx xxx">
              </div>
              <?php if ($ev['capacity'] && $spotsLeft > 1): ?>
                <div class="ev-form-field">
                  <label><?= t('Tickets','Billets') ?></label>
                  <select name="reg_qty">
                    <?php for ($q=1; $q<=min(5,$spotsLeft); $q++): ?>
                      <option value="<?= $q ?>" <?= (($_POST['reg_qty']??1)==$q)?'selected':'' ?>>
                        <?= $q ?>
                      </option>
                    <?php endfor; ?>
                  </select>
                </div>
              <?php endif; ?>
              <div class="ev-form-field">
                <label><?= t('Notes (optional)','Notes (optionnel)') ?></label>
                <textarea name="reg_notes" rows="2" style="resize:vertical;"
                          placeholder="<?= t('Any special requirements…','Besoins particuliers…') ?>"><?= htmlspecialchars($_POST['reg_notes'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
              </div>
              <button type="submit" class="ev-reg-btn">
                🎟 <?= $ev['is_free'] ? t('Register Free','S\'inscrire Gratuitement') : t('Reserve My Spot','Réserver Ma Place') ?>
              </button>
            </form>
          <?php endif; ?>
        <?php endif; ?>

        <!-- WhatsApp / Phone CTAs -->
        <?php if ($ev['biz_wa']): ?>
          <?php
            $waNum = preg_replace('/\D/', '', $ev['biz_wa']);
            $waMsg = urlencode(t(
              "Hi, I'm interested in your event: {$etitle}",
              "Bonjour, je suis intéressé par votre événement : {$etitle}"
            ));
          ?>
          <a href="https://wa.me/<?= $waNum ?>?text=<?= $waMsg ?>"
             target="_blank" rel="noopener" class="ev-wa-btn">
            💬 <?= t('WhatsApp Organiser','WhatsApp Organisateur') ?>
          </a>
        <?php endif; ?>
      </div>

    </aside>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
