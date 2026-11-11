<?php
/**
 * partner/saleskit.php — Digital Sales Kit (Phase 5B)
 * - Business profile share card
 * - QR code display (reuses existing listing QR)
 * - Tracking link generator
 * - Campaign-specific tracking links
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';
require_once __DIR__ . '/../includes/partner-lang.php';
require_once __DIR__ . '/includes/nav.php';
if (!function_exists('partnerNavBadges')) {
    function partnerNavBadges(int $pid, PDO $pdo): void {
        $st = $pdo->prepare("SELECT COUNT(*) FROM growth_tasks WHERE partner_id=? AND status IN ('todo','in_progress') AND due_date < CURDATE()");
        $st->execute([$pid]); $GLOBALS['_pnav_overdue_tasks'] = (int)$st->fetchColumn();
        $st = $pdo->prepare("SELECT COUNT(*) FROM partner_leads WHERE partner_id=? AND follow_up_date < CURDATE() AND status NOT IN ('converted','lost','closed')");
        $st->execute([$pid]); $GLOBALS['_pnav_overdue_leads'] = (int)$st->fetchColumn();
        $st = $pdo->prepare("SELECT COUNT(*) FROM growth_alerts WHERE partner_id=? AND status IN ('new','viewed')");
        $st->execute([$pid]); $GLOBALS['_pnav_alerts'] = (int)$st->fetchColumn();
        $st = $pdo->prepare("SELECT COUNT(*) FROM growth_alerts WHERE partner_id=? AND status IN ('new','viewed') AND priority='urgent'");
        $st->execute([$pid]); $GLOBALS['_pnav_urgent'] = (int)$st->fetchColumn();
        $st = $pdo->prepare("SELECT COUNT(*) FROM ai_recommendations WHERE partner_id=? AND status IN ('new','viewed')");
        $st->execute([$pid]); $GLOBALS['_pnav_recs'] = (int)$st->fetchColumn();
        $st = $pdo->prepare("SELECT COUNT(*) FROM marketplace_orders WHERE partner_profile_id=? AND order_state NOT IN ('COMPLETED','CANCELLED','REJECTED')");
        $st->execute([$pid]); $GLOBALS['_pnav_active_orders'] = (int)$st->fetchColumn();
    }
}

$partnerProfile = requireGrowthPartner();
$partnerId      = (int)$partnerProfile['id'];
$userId         = currentUser()['id'];
$pdo            = db();

// ── POST handler ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // Create tracking link
    if ($action === 'create_link') {
        $label      = trim($_POST['label'] ?? '');
        $targetUrl  = trim($_POST['target_url'] ?? '');
        $listingId  = (int)($_POST['listing_id'] ?? 0) ?: null;
        $campaignId = (int)($_POST['campaign_id'] ?? 0) ?: null;

        if ($label && $targetUrl && filter_var($targetUrl, FILTER_VALIDATE_URL)) {
            if ($listingId && !partnerCanAccessListing($partnerId, $listingId)) {
                setFlash('error', 'Access denied to that business.');
            } else {
                // Generate unique ref code
                do {
                    $refCode = strtoupper(substr(md5(uniqid($partnerId, true)), 0, 8));
                    $exists  = $pdo->prepare("SELECT id FROM partner_tracking_links WHERE ref_code=?");
                    $exists->execute([$refCode]);
                } while ($exists->fetch());

                $pdo->prepare("INSERT INTO partner_tracking_links (partner_id, listing_id, ref_code, label, target_url, campaign_id)
                    VALUES (?,?,?,?,?,?)")
                    ->execute([$partnerId, $listingId, $refCode, $label, $targetUrl, $campaignId]);
                partnerAuditLog($partnerId, $userId, $listingId, 'tracking_link_created', "Link: {$label}");
                setFlash('success', "Tracking link created: {$refCode}");
            }
        } else {
            setFlash('error', 'Please enter a valid label and URL.');
        }
        redirect(SITE_URL . '/partner/saleskit');
    }

    // Delete tracking link
    if ($action === 'delete_link') {
        $linkId = (int)($_POST['link_id'] ?? 0);
        if ($linkId) {
            $chk = $pdo->prepare("SELECT label FROM partner_tracking_links WHERE id=? AND partner_id=?");
            $chk->execute([$linkId, $partnerId]);
            $lnk = $chk->fetch();
            if ($lnk) {
                $pdo->prepare("DELETE FROM partner_tracking_links WHERE id=? AND partner_id=?")->execute([$linkId, $partnerId]);
                setFlash('success', "Link deleted.");
                partnerAuditLog($partnerId, $userId, null, 'tracking_link_deleted', "Link: {$lnk['label']}");
            }
        }
        redirect(SITE_URL . '/partner/saleskit');
    }

    redirect(SITE_URL . '/partner/saleskit');
}

// ── Data ─────────────────────────────────────────────────────────
$listings = fetchPartnerListings($partnerId);

// Get selected business
$selectedId = (int)($_GET['biz'] ?? ($listings[0]['id'] ?? 0));
$selectedBiz = null;
foreach ($listings as $biz) {
    if ($biz['id'] === $selectedId) { $selectedBiz = $biz; break; }
}
if (!$selectedBiz && !empty($listings)) { $selectedBiz = $listings[0]; $selectedId = $selectedBiz['id']; }

// Full listing data for selected biz
$fullListing = null;
if ($selectedId) {
    $lst = $pdo->prepare("
        SELECT l.*, c.name_en AS cat_name, loc.name_en AS city
        FROM listings l
        JOIN categories c ON c.id = l.category_id
        JOIN locations loc ON loc.id = l.location_id
        WHERE l.id=?
    ");
    $lst->execute([$selectedId]);
    $fullListing = $lst->fetch();
}

// Tracking links for this partner
$linkSt = $pdo->prepare("
    SELECT tl.*, l.title AS biz_name
    FROM partner_tracking_links tl
    LEFT JOIN listings l ON l.id = tl.listing_id
    WHERE tl.partner_id = ?
    ORDER BY tl.created_at DESC
    LIMIT 50
");
$linkSt->execute([$partnerId]);
$trackingLinks = $linkSt->fetchAll();

// Campaigns for selected biz (for linking)
$campaigns = [];
if ($selectedId) {
    $cst = $pdo->prepare("SELECT id, title AS name FROM partner_campaigns WHERE partner_id=? AND listing_id=? AND status IN ('active','scheduled') ORDER BY created_at DESC");
    $cst->execute([$partnerId, $selectedId]);
    $campaigns = $cst->fetchAll();
}

$flash = getFlash();
$siteUrl = SITE_URL;

partnerNavBadges($partnerId, $pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Digital Sales Kit — 237Biz Partner</title>
<?php partnerNavAssets(); ?>
<style>
:root {
  --bg: #0a1a14;
  --card: #0f2318;
  --border: rgba(0,168,120,0.18);
  --accent: #00A878;
  --muted: #8db8a8;
  --text: #d4ede5;
  --danger: #ef4444;
}
body { background: var(--bg); color: var(--text); font-family: system-ui, sans-serif; margin: 0; }
.sk-wrap { max-width: 1100px; margin: 0 auto; padding: 1.5rem 1rem 4rem; }
.sk-header h1 { font-size: 1.4rem; color: #fff; margin: 0 0 0.25rem; }
.sk-header p  { color: var(--muted); margin: 0 0 1.5rem; font-size: 0.9rem; }

/* Biz picker bar */
.biz-picker { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
.biz-pill { padding: 0.4rem 0.9rem; border-radius: 20px; font-size: 0.82rem; font-weight: 600;
  border: 1px solid var(--border); color: var(--muted); background: var(--card); cursor: pointer; text-decoration: none; }
.biz-pill.active, .biz-pill:hover { border-color: var(--accent); color: var(--accent); background: rgba(0,168,120,0.08); }
.biz-pill.active { background: rgba(0,168,120,0.15); }

/* Grid layout */
.sk-grid { display: grid; grid-template-columns: 320px 1fr; gap: 1.25rem; }
@media(max-width:800px) { .sk-grid { grid-template-columns: 1fr; } }

/* Business card */
.biz-sharecard { background: linear-gradient(135deg, #0d2a1c 0%, #0a1f14 100%);
  border: 1px solid rgba(0,168,120,0.3); border-radius: 14px; padding: 1.5rem;
  position: relative; overflow: hidden; }
.biz-sharecard::before { content: ''; position: absolute; top: -40px; right: -40px; width: 120px; height: 120px;
  background: rgba(0,168,120,0.07); border-radius: 50%; }
.biz-sharecard::after  { content: ''; position: absolute; bottom: -20px; left: -20px; width: 80px; height: 80px;
  background: rgba(0,168,120,0.05); border-radius: 50%; }
.biz-logo { width: 60px; height: 60px; border-radius: 10px; object-fit: cover;
  border: 2px solid rgba(0,168,120,0.3); background: rgba(0,168,120,0.1); display: flex; align-items: center;
  justify-content: center; font-size: 1.5rem; overflow: hidden; margin-bottom: 0.75rem; }
.biz-logo img { width: 100%; height: 100%; object-fit: cover; }
.biz-sharecard .biz-name { font-size: 1.1rem; font-weight: 800; color: #fff; margin-bottom: 0.2rem; }
.biz-sharecard .biz-cat  { font-size: 0.8rem; color: var(--accent); margin-bottom: 0.75rem; }
.biz-contact { display: flex; flex-direction: column; gap: 0.4rem; margin-bottom: 1rem; }
.biz-contact-row { display: flex; align-items: center; gap: 0.5rem; font-size: 0.82rem; color: var(--muted); }
.biz-contact-row strong { color: #fff; }
.share-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; }
.share-btn { padding: 0.6rem; border-radius: 8px; font-size: 0.8rem; font-weight: 700; text-align: center;
  cursor: pointer; border: none; text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 0.3rem; }
.share-btn-wa  { background: #25D366; color: #fff; }
.share-btn-wa:hover { background: #1db954; }
.share-btn-copy { background: rgba(0,168,120,0.15); color: var(--accent); border: 1px solid rgba(0,168,120,0.25); }
.share-btn-copy:hover { background: rgba(0,168,120,0.3); }
.share-btn-link { background: rgba(255,255,255,0.07); color: var(--muted); grid-column: span 2; }
.share-btn-link:hover { color: #fff; }

/* QR section */
.qr-section { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 1.25rem;
  margin-top: 1rem; text-align: center; }
.qr-section h3 { color: #fff; font-size: 0.95rem; margin: 0 0 0.75rem; }
.qr-img { width: 140px; height: 140px; border-radius: 10px; background: #fff; padding: 8px;
  box-sizing: border-box; margin: 0 auto; display: block; }
.qr-label { font-size: 0.75rem; color: var(--muted); margin-top: 0.5rem; }

/* Right column tabs */
.sk-tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--border); margin-bottom: 1.25rem; }
.sk-tab  { padding: 0.55rem 1rem; font-size: 0.83rem; font-weight: 600; color: var(--muted);
           cursor: pointer; border-bottom: 2px solid transparent; background: none; border-top: none; border-left: none; border-right: none; }
.sk-tab.active, .sk-tab:hover { color: var(--accent); }
.sk-tab.active { border-bottom-color: var(--accent); }
.sk-panel { display: none; }
.sk-panel.active { display: block; }

/* Tracking links table */
.links-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.links-table th { text-align: left; padding: 0.5rem 0.75rem; color: var(--muted); font-size: 0.75rem;
  border-bottom: 1px solid var(--border); font-weight: 600; }
.links-table td { padding: 0.6rem 0.75rem; border-bottom: 1px solid rgba(0,168,120,0.07); vertical-align: middle; }
.links-table tr:last-child td { border-bottom: none; }
.ref-code { font-family: monospace; background: rgba(0,168,120,0.1); color: var(--accent);
  padding: 0.15rem 0.5rem; border-radius: 5px; font-size: 0.8rem; }
.click-count { font-weight: 700; color: #fff; }
.link-label { font-weight: 600; color: #fff; max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.link-url   { font-size: 0.75rem; color: var(--muted); max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

/* Form */
.card { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 1.25rem; }
.card-title { font-size: 0.95rem; font-weight: 700; color: #fff; margin: 0 0 1rem; }
.form-group { margin-bottom: 0.9rem; }
.form-label { display: block; font-size: 0.8rem; color: var(--muted); margin-bottom: 0.35rem; font-weight: 600; }
.form-input, .form-select {
  width: 100%; background: rgba(255,255,255,0.05); border: 1px solid var(--border);
  border-radius: 7px; color: #fff; font-size: 0.87rem; padding: 0.55rem 0.75rem; box-sizing: border-box; }
.form-input:focus, .form-select:focus { outline: none; border-color: var(--accent); }
.form-select option { background: #0f2318; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
@media(max-width:600px) { .form-row { grid-template-columns: 1fr; } }
.btn-primary { background: var(--accent); color: #fff; border: none; border-radius: 8px;
  padding: 0.6rem 1.3rem; font-weight: 700; cursor: pointer; font-size: 0.88rem; }
.btn-primary:hover { background: #00c490; }
.btn-sm-del { background: rgba(239,68,68,0.1); color: #ef4444; border: none; border-radius: 6px;
  padding: 0.25rem 0.6rem; font-size: 0.75rem; cursor: pointer; }
.btn-sm-del:hover { background: rgba(239,68,68,0.25); }
.btn-sm-copy { background: rgba(0,168,120,0.1); color: var(--accent); border: none; border-radius: 6px;
  padding: 0.25rem 0.6rem; font-size: 0.75rem; cursor: pointer; }
.btn-sm-copy:hover { background: rgba(0,168,120,0.25); }

.flash { padding: 0.7rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.87rem; font-weight: 600; }
.flash-success { background: rgba(0,168,120,0.15); border: 1px solid rgba(0,168,120,0.3); color: #6ee7c8; }
.flash-error   { background: rgba(239,68,68,0.12); border: 1px solid rgba(239,68,68,0.3); color: #fca5a5; }
.empty { text-align: center; padding: 2rem 1rem; color: var(--muted); }
.empty-icon { font-size: 2rem; margin-bottom: 0.5rem; }

/* Copied toast */
#copy-toast { position: fixed; bottom: 1.5rem; right: 1.5rem; background: #00A878; color: #fff;
  padding: 0.6rem 1.2rem; border-radius: 8px; font-weight: 700; font-size: 0.85rem;
  opacity: 0; transition: opacity 0.3s; pointer-events: none; z-index: 1000; }
#copy-toast.show { opacity: 1; }
</style>
</head>
<body>
<?php partnerNav('saleskit'); ?>
<div id="copy-toast">✅ Copied!</div>

<div class="sk-wrap">
  <div class="sk-header">
    <h1>🗂️ Digital Sales Kit</h1>
    <p>Share your business profile, display your QR code, and create trackable links for your marketing.</p>
  </div>

  <?php if ($flash): ?>
  <div class="flash flash-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['message']) ?></div>
  <?php endif; ?>

  <?php if (empty($listings)): ?>
    <div class="empty"><div class="empty-icon">📋</div><p>No businesses assigned to your portfolio yet.</p></div>
  <?php else: ?>

  <!-- Business picker -->
  <div class="biz-picker">
    <?php foreach ($listings as $biz): ?>
    <a href="?biz=<?= $biz['id'] ?>" class="biz-pill <?= $biz['id'] === $selectedId ? 'active' : '' ?>">
      <?= htmlspecialchars($biz['title']) ?>
    </a>
    <?php endforeach; ?>
  </div>

  <?php if ($fullListing): ?>
  <div class="sk-grid">
    <!-- Left: Business card + QR -->
    <div>
      <div class="biz-sharecard">
        <?php $logoUrl = $fullListing['logo'] ?? null; ?>
        <div class="biz-logo">
          <?php if ($logoUrl): ?>
            <img src="<?= htmlspecialchars($logoUrl) ?>" alt="logo">
          <?php else: ?>
            🏢
          <?php endif; ?>
        </div>
        <div class="biz-name"><?= htmlspecialchars($fullListing['title']) ?></div>
        <div class="biz-cat">📍 <?= htmlspecialchars($fullListing['cat_name']) ?> · <?= htmlspecialchars($fullListing['city']) ?></div>

        <div class="biz-contact">
          <?php if ($fullListing['phone'] ?? null): ?>
          <div class="biz-contact-row">📞 <strong><?= htmlspecialchars($fullListing['phone']) ?></strong></div>
          <?php endif; ?>
          <?php $wa = preg_replace('/\D/', '', $fullListing['whatsapp'] ?? '');
                if ($wa && strlen($wa) >= 8 && $wa[0] !== '2') $wa = '237' . $wa; ?>
          <?php if ($wa): ?>
          <div class="biz-contact-row">💬 <strong>+<?= htmlspecialchars($wa) ?></strong></div>
          <?php endif; ?>
          <?php if ($fullListing['website'] ?? null): ?>
          <div class="biz-contact-row">🌐 <strong><?= htmlspecialchars(preg_replace('#^https?://#', '', $fullListing['website'])) ?></strong></div>
          <?php endif; ?>
          <?php if ($fullListing['address'] ?? null): ?>
          <div class="biz-contact-row">📌 <strong><?= htmlspecialchars(mb_substr($fullListing['address'], 0, 60)) ?></strong></div>
          <?php endif; ?>
        </div>

        <div class="share-actions">
          <?php if ($wa): ?>
          <a href="https://wa.me/<?= $wa ?>?text=<?= urlencode("Hello! I'd like to know more about " . $fullListing['title']) ?>"
             class="share-btn share-btn-wa" target="_blank">💬 WhatsApp</a>
          <?php endif; ?>
          <button class="share-btn share-btn-copy" onclick="copyText('<?= htmlspecialchars($siteUrl . '/listing/' . ($fullListing['slug'] ?? $fullListing['id'])) ?>')">
            🔗 Copy Link
          </button>
          <button class="share-btn share-btn-link" onclick="copyBusinessCard()">
            📋 Copy Business Info
          </button>
        </div>
      </div>

      <!-- QR code -->
      <?php $qrPath = '/home/biz97h/public_html/assets/qr/' . $selectedId . '.png'; ?>
      <div class="qr-section">
        <h3>📱 QR Code</h3>
        <?php
        // Try different QR paths
        $qrUrl = null;
        $tryPaths = [
          SITE_URL . '/assets/qr/' . $selectedId . '.png',
          SITE_URL . '/assets/qr/listing-' . $selectedId . '.png',
        ];
        // We'll show the first path and let the browser decide (onerror fallback)
        $qrUrl = SITE_URL . '/assets/qr/' . $selectedId . '.png';
        ?>
        <img src="<?= htmlspecialchars($qrUrl) ?>" class="qr-img" alt="QR Code"
          onerror="this.style.display='none';document.getElementById('no-qr').style.display='block'">
        <div id="no-qr" style="display:none; padding:2rem; color:var(--muted); font-size:0.82rem;">
          QR code not yet generated for this listing.<br>
          <a href="<?= SITE_URL ?>/admin/listings/<?= $selectedId ?>" style="color:var(--accent);">Generate in admin</a>
        </div>
        <div class="qr-label">Scan to view <?= htmlspecialchars($fullListing['title']) ?>'s profile</div>
        <div style="margin-top:0.75rem">
          <a href="<?= htmlspecialchars($qrUrl) ?>" download class="share-btn share-btn-copy" style="font-size:0.78rem; padding:0.4rem 0.8rem; display:inline-flex; justify-content:center; gap:0.3rem;">
            ⬇️ Download QR
          </a>
        </div>
      </div>
    </div>

    <!-- Right: Tracking links + form -->
    <div>
      <div class="sk-tabs">
        <button class="sk-tab active" onclick="showSkTab('links')">🔗 Tracking Links</button>
        <button class="sk-tab" onclick="showSkTab('create')">➕ Create Link</button>
      </div>

      <!-- Tracking links list -->
      <div class="sk-panel active" id="sk-panel-links">
        <?php if (empty($trackingLinks)): ?>
          <div class="empty">
            <div class="empty-icon">🔗</div>
            <p>No tracking links yet.</p>
            <p style="font-size:0.82rem;">Create trackable links to see which campaigns drive the most visits.</p>
            <button class="btn-primary" onclick="showSkTab('create')">Create your first link</button>
          </div>
        <?php else: ?>
          <div class="card" style="padding:0; overflow:hidden;">
            <table class="links-table">
              <thead>
                <tr>
                  <th>Label</th>
                  <th>Ref</th>
                  <th>Clicks</th>
                  <th>Business</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($trackingLinks as $lnk): ?>
                <tr>
                  <td>
                    <div class="link-label"><?= htmlspecialchars($lnk['label']) ?></div>
                    <div class="link-url"><?= htmlspecialchars($lnk['target_url']) ?></div>
                  </td>
                  <td><span class="ref-code"><?= htmlspecialchars($lnk['ref_code']) ?></span></td>
                  <td><span class="click-count"><?= (int)$lnk['clicks'] ?></span></td>
                  <td style="font-size:0.8rem; color:var(--muted);"><?= htmlspecialchars($lnk['biz_name'] ?? '—') ?></td>
                  <td>
                    <div style="display:flex; gap:0.4rem;">
                      <button class="btn-sm-copy" onclick="copyText('<?= htmlspecialchars($siteUrl . '/go/' . $lnk['ref_code']) ?>')">Copy</button>
                      <form method="POST" style="display:inline" onsubmit="return confirm('Delete this tracking link?')">
                        <?= gpCsrfField() ?>
                        <input type="hidden" name="action" value="delete_link">
                        <input type="hidden" name="link_id" value="<?= $lnk['id'] ?>">
                        <button type="submit" class="btn-sm-del">🗑</button>
                      </form>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p style="font-size:0.78rem; color:var(--muted); margin-top:0.5rem;">
            Tracking links: <code style="color:var(--accent);"><?= htmlspecialchars($siteUrl) ?>/go/{ref}</code>
          </p>
        <?php endif; ?>
      </div>

      <!-- Create link form -->
      <div class="sk-panel" id="sk-panel-create">
        <div class="card">
          <div class="card-title">Create a Tracking Link</div>
          <form method="POST">
            <?= gpCsrfField() ?>
            <input type="hidden" name="action" value="create_link">

            <div class="form-group">
              <label class="form-label">Label (for your reference) *</label>
              <input type="text" class="form-input" name="label" required placeholder="e.g. WhatsApp Bio Link — Nov 2026">
            </div>
            <div class="form-group">
              <label class="form-label">Destination URL *</label>
              <input type="url" class="form-input" name="target_url" required
                placeholder="https://237biz.net/listing/..."
                value="<?= htmlspecialchars($siteUrl . '/listing/' . ($fullListing['slug'] ?? $selectedId)) ?>">
            </div>
            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Business (optional)</label>
                <select class="form-select" name="listing_id">
                  <option value="">— Any —</option>
                  <?php foreach ($listings as $biz): ?>
                  <option value="<?= $biz['id'] ?>" <?= $biz['id'] === $selectedId ? 'selected' : '' ?>><?= htmlspecialchars($biz['title']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group">
                <label class="form-label">Campaign (optional)</label>
                <select class="form-select" name="campaign_id">
                  <option value="">— None —</option>
                  <?php foreach ($campaigns as $camp): ?>
                  <option value="<?= $camp['id'] ?>"><?= htmlspecialchars($camp['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <button type="submit" class="btn-primary">🔗 Generate Link</button>
          </form>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<script>
function showSkTab(tab) {
  document.querySelectorAll('.sk-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.sk-tab').forEach(t => t.classList.remove('active'));
  document.getElementById('sk-panel-' + tab).classList.add('active');
  const idx = tab === 'links' ? 0 : 1;
  document.querySelectorAll('.sk-tab')[idx].classList.add('active');
}

function copyText(text) {
  if (navigator.clipboard) {
    navigator.clipboard.writeText(text).then(showToast).catch(() => fallbackCopy(text));
  } else { fallbackCopy(text); }
}

function fallbackCopy(text) {
  const ta = document.createElement('textarea');
  ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
  document.body.appendChild(ta); ta.select();
  try { document.execCommand('copy'); showToast(); } catch(e) {}
  document.body.removeChild(ta);
}

function showToast() {
  const t = document.getElementById('copy-toast');
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 2000);
}

function copyBusinessCard() {
  <?php if ($fullListing): ?>
  const info = [
    "📍 <?= addslashes(htmlspecialchars($fullListing['title'])) ?>",
    "🏷️ <?= addslashes(htmlspecialchars($fullListing['cat_name'] . ' · ' . $fullListing['city'])) ?>",
    <?php if ($fullListing['phone']): ?>"📞 <?= addslashes(htmlspecialchars($fullListing['phone'])) ?>",<?php endif; ?>
    <?php $wa2 = preg_replace('/\D/', '', $fullListing['whatsapp'] ?? ''); if ($wa2 && strlen($wa2) >= 8 && $wa2[0] !== '2') $wa2 = '237'.$wa2; ?>
    <?php if ($wa2): ?>"💬 +<?= addslashes($wa2) ?>",<?php endif; ?>
    <?php if ($fullListing['website']): ?>"🌐 <?= addslashes(htmlspecialchars($fullListing['website'])) ?>",<?php endif; ?>
    "🔗 <?= addslashes($siteUrl . '/listing/' . ($fullListing['slug'] ?? $selectedId)) ?>"
  ].join("\n");
  copyText(info);
  <?php endif; ?>
}
</script>
</body>
</html>
