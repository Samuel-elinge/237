<?php
/**
 * Partner Portal Navigation Partial
 * Usage:
 *   require_once __DIR__ . '/includes/nav.php';
 *   partnerNavBadges($pid, $pdo);   // call once after $pid/$pdo are set
 *   partnerNav('dashboard');
 *   partnerNavAssets();
 */

/** Queries the badge counts needed for the nav. Call once per page before partnerNav(). */
function partnerNavBadges(int $pid, PDO $pdo): void {
    $st = $pdo->prepare("SELECT COUNT(*) FROM growth_tasks WHERE partner_id=? AND status IN ('todo','in_progress') AND due_date < CURDATE()");
    $st->execute([$pid]);
    $GLOBALS['_pnav_overdue_tasks'] = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT COUNT(*) FROM partner_leads WHERE partner_id=? AND follow_up_date < CURDATE() AND status NOT IN ('converted','lost','closed')");
    $st->execute([$pid]);
    $GLOBALS['_pnav_overdue_leads'] = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT COUNT(*) FROM growth_alerts WHERE partner_id=? AND status IN ('new','viewed')");
    $st->execute([$pid]);
    $GLOBALS['_pnav_alerts'] = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT COUNT(*) FROM growth_alerts WHERE partner_id=? AND status IN ('new','viewed') AND priority='urgent'");
    $st->execute([$pid]);
    $GLOBALS['_pnav_urgent'] = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT COUNT(*) FROM ai_recommendations WHERE partner_id=? AND status IN ('new','viewed')");
    $st->execute([$pid]);
    $GLOBALS['_pnav_recs'] = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT COUNT(*) FROM marketplace_orders WHERE partner_profile_id=? AND order_state NOT IN ('COMPLETED','CANCELLED','REJECTED')");
    $st->execute([$pid]);
    $GLOBALS['_pnav_active_orders'] = (int)$st->fetchColumn();
}

function partnerNav(string $activePage = ''): void {
    if ($activePage === '') {
        $activePage = basename($_SERVER['SCRIPT_FILENAME'] ?? '', '.php');
    }
    // Alias single-order view → orders list tab
    if ($activePage === 'marketplace-order' || $activePage === 'marketplace-pay') {
        $activePage = 'marketplace-orders';
    }
    $base = defined('SITE_URL') ? SITE_URL : '';

    $overdueT     = (int)($GLOBALS['_pnav_overdue_tasks'] ?? 0);
    $overdueL     = (int)($GLOBALS['_pnav_overdue_leads'] ?? 0);
    $alerts       = (int)($GLOBALS['_pnav_alerts'] ?? 0);
    $urgent       = (int)($GLOBALS['_pnav_urgent'] ?? 0);
    $recs         = (int)($GLOBALS['_pnav_recs'] ?? 0);
    $activeOrders = (int)($GLOBALS['_pnav_active_orders'] ?? 0);

    // Nav groups: [ label, [ [key, icon, label, url, badge, urgent?], ... ] ]
    $groups = [
        ['Overview', [
            ['dashboard',  '🏠', 'Dashboard',     $base . '/partner/dashboard',  0,             false],
        ]],
        ['Sales', [
            ['leads',      '💬', 'Leads',          $base . '/partner/leads',      $overdueL,     false],
            ['pipeline',   '📊', 'Pipeline',       $base . '/partner/pipeline',   0,             false],
            ['whatsapp',   '📲', 'WhatsApp',       $base . '/partner/whatsapp',   0,             false],
            ['saleskit',   '🗂',  'Sales Kit',      $base . '/partner/saleskit',   0,             false],
        ]],
        ['Services', [
            ['marketplace',        '🛒', 'Order Services', $base . '/partner/marketplace',        0,             false],
            ['marketplace-orders', '📦', 'My Orders',      $base . '/partner/marketplace-orders', $activeOrders, false],
        ]],
        ['Portfolio', [
            ['portfolio',  '📋', 'Businesses',    $base . '/partner/portfolio',  0,    false],
            ['tasks',      '📝', 'Notes',         $base . '/partner/tasks',      $overdueT, false],
            ['commissions','💰', 'Commissions',   $base . '/partner/commissions',0,    false],
        ]],
        ['Tools', [
            ['bhc-send',   '🔗', 'BHC Link',      $base . '/partner/bhc-send',   0,    false],
        ]],
    ];

    $baseEsc  = htmlspecialchars($base, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!-- ── PARTNER SIDEBAR ─────────────────────────────────── -->
<div class="pnav-backdrop" id="pnavBackdrop" onclick="pnavClose()"></div>

<aside class="partner-sidebar" id="partnerSidebar" role="navigation" aria-label="Partner navigation">
  <div class="psb-header">
    <span class="psb-logo">🤝 Partner</span>
    <button class="psb-close" onclick="pnavClose()" aria-label="Close menu">✕</button>
  </div>

  <nav class="psb-nav">
<?php foreach ($groups as [$groupLabel, $items]): ?>
    <div class="psb-group">
      <div class="psb-group-label"><?= htmlspecialchars($groupLabel) ?></div>
<?php foreach ($items as [$key, $icon, $label, $url, $badge, $isUrgent]): ?>
      <a href="<?= htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
         class="psb-item<?= $activePage === $key ? ' active' : '' ?>">
        <span class="psb-icon"><?= $icon ?></span>
        <span class="psb-label"><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
<?php if ($badge > 0): $bStyle = $isUrgent ? ' style="background:#f59e0b"' : ''; ?>
        <span class="psb-badge"<?= $bStyle ?>><?= (int)$badge ?></span>
<?php endif; ?>
      </a>
<?php endforeach; ?>
    </div>
<?php endforeach; ?>
  </nav>

  <div class="psb-footer">
    <a href="<?= $baseEsc ?>/dashboard" class="psb-back">← Main Portal</a>
  </div>
</aside>

<!-- ── MOBILE TOP BAR ──────────────────────────────────── -->
<div class="pnav-topbar">
  <button class="pnav-hamburger" onclick="pnavOpen()" aria-label="Open menu">
    <span></span><span></span><span></span>
  </button>
  <span class="pnav-title">🤝 Partner Centre</span>
  <a href="<?= $baseEsc ?>/dashboard" class="pnav-portal-link">Portal</a>
</div>

<?php
}

function partnerNavAssets(): void {
    ?>
<style>
/* ═══════════════════════════════════════════════════════════
   PARTNER SIDEBAR NAVIGATION
   ═══════════════════════════════════════════════════════════ */

:root {
  --psb-width: 210px;
  --psb-bg: #04110b;
  --psb-border: rgba(0,168,120,0.18);
  --psb-accent: #00A878;
  --psb-text: #8db8a8;
  --psb-text-active: #e8f4ee;
  --psb-hover-bg: rgba(0,168,120,0.07);
  --psb-active-bg: rgba(0,168,120,0.14);
  --psb-topbar-h: 52px;
}

/* ── Sidebar ─────────────────────────────────────────── */
.partner-sidebar {
  position: fixed;
  top: 0; left: 0; bottom: 0;
  width: var(--psb-width);
  background: var(--psb-bg);
  border-right: 1px solid var(--psb-border);
  display: flex;
  flex-direction: column;
  z-index: 300;
  overflow: hidden;
  transition: transform 0.25s ease;
}

.psb-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 14px 12px;
  border-bottom: 1px solid var(--psb-border);
  flex-shrink: 0;
}
.psb-logo {
  font-size: 0.88rem;
  font-weight: 800;
  color: var(--psb-accent);
  letter-spacing: 0.02em;
}
.psb-close {
  display: none;
  background: none;
  border: none;
  color: var(--psb-text);
  font-size: 1rem;
  cursor: pointer;
  padding: 4px 6px;
  border-radius: 6px;
  line-height: 1;
}
.psb-close:hover { color: var(--psb-text-active); background: var(--psb-hover-bg); }

/* ── Nav scroll area ─────────────────────────────────── */
.psb-nav {
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
  padding: 8px 0 16px;
  scrollbar-width: thin;
  scrollbar-color: rgba(0,168,120,0.2) transparent;
}
.psb-nav::-webkit-scrollbar { width: 4px; }
.psb-nav::-webkit-scrollbar-thumb { background: rgba(0,168,120,0.2); border-radius: 2px; }

/* ── Groups ──────────────────────────────────────────── */
.psb-group { margin-bottom: 4px; }
.psb-group-label {
  font-size: 0.65rem;
  font-weight: 800;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: rgba(0,168,120,0.45);
  padding: 10px 16px 4px;
}

/* ── Items ───────────────────────────────────────────── */
.psb-item {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 8px 14px 8px 16px;
  color: var(--psb-text);
  text-decoration: none;
  font-size: 0.82rem;
  font-weight: 500;
  border-left: 2px solid transparent;
  transition: all 0.13s ease;
  position: relative;
}
.psb-item:hover {
  color: var(--psb-text-active);
  background: var(--psb-hover-bg);
  border-left-color: rgba(0,168,120,0.3);
}
.psb-item.active {
  color: var(--psb-accent);
  background: var(--psb-active-bg);
  border-left-color: var(--psb-accent);
  font-weight: 700;
}
.psb-icon { font-size: 1rem; flex-shrink: 0; line-height: 1; }
.psb-label { flex: 1; }
.psb-badge {
  background: #ef4444;
  color: #fff;
  border-radius: 10px;
  font-size: 0.6rem;
  font-weight: 800;
  min-width: 16px;
  height: 16px;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0 4px;
  flex-shrink: 0;
}

/* ── Footer ──────────────────────────────────────────── */
.psb-footer {
  border-top: 1px solid var(--psb-border);
  padding: 12px 14px;
  flex-shrink: 0;
}
.psb-back {
  display: block;
  font-size: 0.78rem;
  color: var(--psb-text);
  text-decoration: none;
  padding: 6px 8px;
  border-radius: 8px;
  transition: all 0.13s;
}
.psb-back:hover { color: var(--psb-accent); background: var(--psb-hover-bg); }

/* ── Backdrop (mobile) ───────────────────────────────── */
.pnav-backdrop {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.6);
  z-index: 290;
}
.pnav-backdrop.open { display: block; }

/* ── Mobile top bar ──────────────────────────────────── */
.pnav-topbar {
  display: none;
  align-items: center;
  gap: 12px;
  height: var(--psb-topbar-h);
  padding: 0 16px;
  background: var(--psb-bg);
  border-bottom: 1px solid var(--psb-border);
  position: sticky;
  top: 0;
  z-index: 200;
}
.pnav-hamburger {
  display: flex;
  flex-direction: column;
  gap: 4px;
  background: none;
  border: none;
  cursor: pointer;
  padding: 6px;
  border-radius: 8px;
}
.pnav-hamburger span {
  display: block;
  width: 20px;
  height: 2px;
  background: var(--psb-text);
  border-radius: 2px;
  transition: background 0.15s;
}
.pnav-hamburger:hover span { background: var(--psb-accent); }
.pnav-title {
  font-size: 0.9rem;
  font-weight: 700;
  color: var(--psb-text-active);
  flex: 1;
}
.pnav-portal-link {
  font-size: 0.78rem;
  color: var(--psb-text);
  text-decoration: none;
  padding: 5px 10px;
  border: 1px solid var(--psb-border);
  border-radius: 8px;
}
.pnav-portal-link:hover { color: var(--psb-accent); border-color: var(--psb-accent); }

/* ── Body offset (desktop) ───────────────────────────── */
body {
  margin-left: var(--psb-width);
}

/* ── Responsive ──────────────────────────────────────── */
@media (max-width: 860px) {
  .partner-sidebar {
    transform: translateX(calc(-1 * var(--psb-width) - 2px));
  }
  .partner-sidebar.open {
    transform: translateX(0);
    box-shadow: 4px 0 24px rgba(0,0,0,0.5);
  }
  .psb-close { display: block; }
  .pnav-topbar { display: flex; }
  body { margin-left: 0; }
}
</style>
<script>
function pnavOpen() {
  document.getElementById('partnerSidebar').classList.add('open');
  document.getElementById('pnavBackdrop').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function pnavClose() {
  document.getElementById('partnerSidebar').classList.remove('open');
  document.getElementById('pnavBackdrop').classList.remove('open');
  document.body.style.overflow = '';
}
// Close on ESC
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') pnavClose();
});
</script>
    <?php
}
