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
}

function partnerNav(string $activePage = ''): void {
    if ($activePage === '') {
        $activePage = basename($_SERVER['SCRIPT_FILENAME'] ?? '', '.php');
    }
    $base = defined('SITE_URL') ? SITE_URL : '';

    $overdueT  = (int)($GLOBALS['_pnav_overdue_tasks'] ?? 0);
    $overdueL  = (int)($GLOBALS['_pnav_overdue_leads'] ?? 0);
    $alerts    = (int)($GLOBALS['_pnav_alerts'] ?? 0);
    $urgent    = (int)($GLOBALS['_pnav_urgent'] ?? 0);
    $recs      = (int)($GLOBALS['_pnav_recs'] ?? 0);

    $items = [
        ['dashboard',       '&#x1F3E0;', 'Dashboard',       $base . '/partner/dashboard',       0, false],
        ['portfolio',       '&#x1F4CB;', 'Portfolio',        $base . '/partner/portfolio',       0, false],
        ['tasks',           '&#x2705;',  'Tasks',            $base . '/partner/tasks',           $overdueT, false],
        ['leads',           '&#x1F4AC;', 'Leads',            $base . '/partner/leads',           $overdueL, false],
        ['campaigns',       '&#x1F4E3;', 'Campaigns',        $base . '/partner/campaigns',       0, false],
        ['content',         '&#x1F4C5;', 'Content',          $base . '/partner/content',         0, false],
        ['reports',         '&#x1F4CA;', 'Reports',          $base . '/partner/reports',         0, false],
        ['recommendations', '&#x2736;',  'Recommendations',  $base . '/partner/recommendations', $recs, false],
        ['alerts',          '&#x1F514;', 'Alerts',           $base . '/partner/alerts',          $alerts, $urgent > 0],
        ['commissions',     '&#x1F4B0;', 'Commissions',      $base . '/partner/commissions',     0, false],
    ];

    echo '<nav class="partner-nav" role="navigation" aria-label="Partner navigation">' . "\n";
    echo '<div class="partner-nav-inner">' . "\n";
    foreach ($items as $item) {
        [$key, $icon, $label, $url, $badge, $isUrgent] = $item;
        $cls      = 'partner-nav-item' . ($activePage === $key ? ' active' : '');
        $urlEsc   = htmlspecialchars($url,   ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $labelEsc = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo '  <a href="' . $urlEsc . '" class="' . $cls . '">';
        echo $icon . ' ' . $labelEsc;
        if ($badge > 0) {
            $bStyle = $isUrgent ? ' style="background:#f59e0b"' : '';
            echo ' <span class="partner-nav-dot"' . $bStyle . '>' . (int)$badge . '</span>';
        }
        echo '</a>' . "\n";
    }
    echo '<a href="' . htmlspecialchars($base . '/dashboard', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" class="partner-nav-back">&#8592; Portal</a>' . "\n";
    echo '</div></nav>' . "\n";
}

function partnerNavAssets(): void {
    ?>
<style>
/* ── Partner Nav ───────────────────────────────────────── */
.partner-nav {
  background: #081410;
  border-bottom: 1px solid rgba(0,168,120,0.2);
  position: sticky;
  top: 0;
  z-index: 200;
  padding: 0 1rem;
  overflow-x: auto;
}
.partner-nav-inner {
  display: flex;
  align-items: stretch;
  gap: 2px;
  min-width: max-content;
  margin: 0 auto;
}
.partner-nav-item {
  position: relative;
  display: flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.75rem 0.8rem;
  font-size: 0.8rem;
  font-weight: 600;
  color: #8db8a8;
  text-decoration: none;
  border-bottom: 2px solid transparent;
  white-space: nowrap;
  transition: color 0.15s, background 0.15s;
}
.partner-nav-item:hover {
  color: #00A878;
  background: rgba(0,168,120,0.08);
}
.partner-nav-item.active {
  color: #00A878;
  border-bottom-color: #00A878;
}
.partner-nav-dot {
  position: absolute;
  top: 6px;
  right: 2px;
  background: #ef4444;
  color: #fff;
  border-radius: 50%;
  font-size: 0.6rem;
  font-weight: 700;
  min-width: 16px;
  height: 16px;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0 3px;
}
.partner-nav-back {
  margin-left: auto;
  padding: 0.75rem 0.9rem;
  font-size: 0.82rem;
  color: #5a8878;
  text-decoration: none;
  display: flex;
  align-items: center;
  white-space: nowrap;
  transition: color 0.15s;
}
.partner-nav-back:hover { color: #00A878; }
</style>
    <?php
}
