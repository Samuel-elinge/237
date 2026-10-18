<?php
/**
 * Agent Portal Navigation Partial
 * Usage: require_once __DIR__ . '/includes/nav.php'; agentNav('dashboard');
 */

function agentNav(string $activePage = ''): void {
    if ($activePage === '') {
        $activePage = basename($_SERVER['SCRIPT_FILENAME'] ?? '', '.php');
    }
    $base = defined('SITE_URL') ? SITE_URL : '';

    $items = [
        ['dashboard', '&#x1F454;', 'My Dashboard', $base . '/agent/dashboard'],
    ];

    echo '<nav class="agent-nav" role="navigation" aria-label="Agent navigation">' . "\n";
    echo '<div class="agent-nav-inner">' . "\n";
    foreach ($items as $item) {
        [$key, $icon, $label, $url] = $item;
        $cls = 'agent-nav-item' . ($activePage === $key ? ' active' : '');
        $urlEsc   = htmlspecialchars($url,   ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $labelEsc = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo '  <a href="' . $urlEsc . '" class="' . $cls . '">' . $icon . ' ' . $labelEsc . '</a>' . "\n";
    }
    echo '<a href="' . htmlspecialchars($base . '/dashboard', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" class="agent-nav-back">&#8592; Portal</a>' . "\n";
    echo '</div></nav>' . "\n";
}

function agentNavAssets(): void {
    ?>
<style>
/* ── Agent Nav ─────────────────────────────────────────── */
.agent-nav {
  background: #081410;
  border-bottom: 1px solid rgba(0,168,120,0.2);
  position: sticky;
  top: 0;
  z-index: 200;
  padding: 0 1rem;
}
.agent-nav-inner {
  display: flex;
  align-items: stretch;
  gap: 4px;
  max-width: 1200px;
  margin: 0 auto;
  flex-wrap: wrap;
}
.agent-nav-item {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.75rem 0.9rem;
  font-size: 0.82rem;
  font-weight: 600;
  color: #8db8a8;
  text-decoration: none;
  border-bottom: 2px solid transparent;
  white-space: nowrap;
  transition: color 0.15s, background 0.15s;
}
.agent-nav-item:hover {
  color: #00A878;
  background: rgba(0,168,120,0.08);
}
.agent-nav-item.active {
  color: #00A878;
  border-bottom-color: #00A878;
}
.agent-nav-back {
  margin-left: auto;
  padding: 0.75rem 0.9rem;
  font-size: 0.82rem;
  color: #5a8878;
  text-decoration: none;
  display: flex;
  align-items: center;
  transition: color 0.15s;
}
.agent-nav-back:hover { color: #00A878; }
</style>
    <?php
}
