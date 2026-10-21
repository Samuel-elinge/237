<?php
/**
 * Admin Navigation Partial
 * Usage: require_once __DIR__ . '/../includes/nav.php'; adminNav('blog');
 */

function adminNav(string $activePage = ''): void {
    if ($activePage === '') {
        $script = basename($_SERVER['SCRIPT_FILENAME'] ?? '', '.php');
        $activePage = $script;
    }

    $base = defined('SITE_URL') ? SITE_URL : '';

    // Groups: label => [ icon (raw, safe constant), items[] ]
    // item: [ label, icon, url, page-keys[] ]
    $groups = [
        ['Listings', '&#x1F4CB;', [
            ['Listings',    '&#x1F4CB;', $base . '/admin/',                    ['index']],
            ['Categories',  '&#x1F4C2;', $base . '/admin/categories.php',      ['categories']],
            ['Locations',   '&#x1F4CD;', $base . '/admin/locations.php',       ['locations']],
            ['Reviews',     '&#x2B50;',  $base . '/admin/reviews.php',         ['reviews']],
            ['Claims',      '&#x1F3E2;', $base . '/admin/claims.php',          ['claims']],
        ]],
        ['Users', '&#x1F464;', [
            ['Users',       '&#x1F464;', $base . '/admin/users.php',           ['users']],
            ['Agents',      '&#x1F454;', $base . '/admin/manage-agents.php',   ['manage-agents']],
            ['Creators',    '&#x1F3AC;', $base . '/admin/manage-creators.php', ['manage-creators']],
            ['Referrals',   '&#x1F4B0;', $base . '/admin/manage-referrals.php',['manage-referrals']],
        ]],
        ['Commerce', '&#x1F4B3;', [
            ['Orders',      '&#x1F4E6;', $base . '/admin/orders.php',          ['orders']],
            ['Packages',    '&#x1F4B3;', $base . '/admin/packages.php',        ['packages']],
            ['Promo Codes', '&#x1F381;', $base . '/admin/promos.php',          ['promos']],
            ['Campaigns',   '&#x1F4E3;', $base . '/admin/manage-campaigns.php',['manage-campaigns']],
        ]],
        ['Monetisation', '&#x1F4B8;', [
            ['Revenue Overview','&#x1F4CA;', $base . '/admin/revenue-overview.php',   ['revenue-overview']],
            ['Subscriptions',   '&#x2B50;',  $base . '/admin/subscriptions.php',      ['subscriptions']],
            ['Promo Orders',    '&#x1F4E3;', $base . '/admin/promotion-orders.php',   ['promotion-orders']],
            ['Commissions',     '&#x1F4B0;', $base . '/admin/commissions.php',        ['commissions']],
            ['Sub Plans',       '&#x1F4CB;', $base . '/admin/subscription-plans.php', ['subscription-plans']],
            ['Promo Products',  '&#x1F4E6;', $base . '/admin/promotion-products.php', ['promotion-products']],
        ]],
        ['Comms', '&#x1F4EC;', [
            ['Enquiries',   '&#x1F4EC;', $base . '/admin/enquiries.php',       ['enquiries']],
            ['Leads',       '&#x1F310;', $base . '/admin/leads.php',           ['leads']],
            ['Newsletter',  '&#x1F4EC;', $base . '/admin/subscribers.php',         ['subscribers']],
            ['Notif. Subs', '&#x1F514;', $base . '/admin/notify-subscribers.php', ['notify-subscribers']],
            ['Notif. Camps','&#x1F4E3;', $base . '/admin/notify-campaigns.php',   ['notify-campaigns']],
            ['Emails',      '&#x2709;',  $base . '/admin/emails.php',             ['emails']],
            ['Blog',        '&#x1F4DD;', $base . '/admin/blog.php',            ['blog']],
        ]],
        ['System', '&#x2699;', [
            ['Dashboard',   '&#x1F4CA;', $base . '/admin/dashboard.php',                ['dashboard']],
            ['SEO Content', '&#x1F4DD;', $base . '/admin/seo-content.php',              ['seo-content']],
            ['Health Check','&#x1FA7A;', $base . '/admin/health-check-overview.php',    ['health-check-overview','health-check-questions','health-check-staff']],
            ['Automation',  '&#x1F916;', $base . '/admin/automation/',                  ['automation']],
        ]],
    ];

    // Find active group
    $activeGroup = -1;
    foreach ($groups as $gi => $group) {
        foreach ($group[2] as $item) {
            if (in_array($activePage, $item[3])) {
                $activeGroup = $gi;
                break 2;
            }
        }
    }

    echo '<nav class="admin-nav" role="navigation" aria-label="Admin navigation">' . "\n";
    echo '<div class="admin-nav-inner">' . "\n";

    foreach ($groups as $gi => $group) {
        $isActive = ($gi === $activeGroup);
        $cls = 'admin-nav-group' . ($isActive ? ' active-group' : '');
        $expanded = $isActive ? 'true' : 'false';
        echo '<div class="' . $cls . '">' . "\n";
        echo '  <button class="admin-nav-trigger" aria-expanded="' . $expanded . '" aria-haspopup="true">';
        echo $group[1] . ' ' . htmlspecialchars($group[0], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo ' <span class="admin-nav-chevron">&#9662;</span></button>' . "\n";
        echo '  <div class="admin-nav-dropdown" role="menu">' . "\n";
        foreach ($group[2] as $item) {
            $isCurrent = in_array($activePage, $item[3]);
            $linkCls = 'admin-nav-item' . ($isCurrent ? ' current' : '');
            $url = htmlspecialchars($item[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $label = htmlspecialchars($item[0], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            echo '    <a href="' . $url . '" class="' . $linkCls . '" role="menuitem">';
            echo $item[1] . ' ' . $label;
            echo '</a>' . "\n";
        }
        echo '  </div>' . "\n";
        echo '</div>' . "\n";
    }

    echo '<a href="' . htmlspecialchars($base . '/dashboard', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" class="admin-nav-back">&#8592; Portal</a>' . "\n";
    echo '</div></nav>' . "\n";
}

function adminNavAssets(): void {
    ?>
<style>
/* ── Admin Nav ─────────────────────────────────────── */
.admin-nav {
  background: #081410;
  border-bottom: 1px solid rgba(0,168,120,0.2);
  position: sticky;
  top: 0;
  z-index: 200;
  padding: 0 1rem;
}
.admin-nav-inner {
  display: flex;
  align-items: stretch;
  gap: 0;
  max-width: 1400px;
  margin: 0 auto;
  flex-wrap: wrap;
}
.admin-nav-group { position: relative; }
.admin-nav-trigger {
  background: none;
  border: none;
  color: #8db8a8;
  cursor: pointer;
  font-size: 0.82rem;
  font-weight: 600;
  padding: 0.75rem 0.9rem;
  display: flex;
  align-items: center;
  gap: 0.35rem;
  white-space: nowrap;
  transition: color 0.15s, background 0.15s;
  border-bottom: 2px solid transparent;
  height: 100%;
}
.admin-nav-trigger:hover {
  color: #00A878;
  background: rgba(0,168,120,0.08);
}
.admin-nav-group.active-group > .admin-nav-trigger {
  color: #00A878;
  border-bottom-color: #00A878;
}
.admin-nav-chevron { font-size: 0.65rem; opacity: 0.6; transition: transform 0.15s; }
.admin-nav-group.open > .admin-nav-trigger .admin-nav-chevron { transform: rotate(180deg); }
.admin-nav-dropdown {
  display: none;
  position: absolute;
  top: 100%;
  left: 0;
  background: #0a1e15;
  border: 1px solid rgba(0,168,120,0.25);
  border-radius: 0 0 8px 8px;
  min-width: 170px;
  box-shadow: 0 8px 24px rgba(0,0,0,0.5);
  z-index: 300;
}
.admin-nav-group.open > .admin-nav-dropdown { display: block; }
.admin-nav-item {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.55rem 1rem;
  font-size: 0.82rem;
  color: #8db8a8;
  text-decoration: none;
  transition: background 0.12s, color 0.12s;
  white-space: nowrap;
}
.admin-nav-item:hover { background: rgba(0,168,120,0.12); color: #fff; }
.admin-nav-item.current { background: rgba(0,168,120,0.18); color: #00A878; font-weight: 700; }
.admin-nav-back {
  margin-left: auto;
  padding: 0.75rem 0.9rem;
  font-size: 0.82rem;
  color: #5a8878;
  text-decoration: none;
  display: flex;
  align-items: center;
  transition: color 0.15s;
}
.admin-nav-back:hover { color: #00A878; }

/* ── Delete Confirm Modal ───────────────────────────── */
#admin-confirm-modal {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.7);
  z-index: 9999;
  align-items: center;
  justify-content: center;
}
#admin-confirm-modal.open { display: flex; }
.admin-modal-box {
  background: #0e1e14;
  border: 1px solid rgba(0,168,120,0.3);
  border-radius: 12px;
  padding: 2rem;
  max-width: 420px;
  width: calc(100% - 2rem);
  box-shadow: 0 20px 60px rgba(0,0,0,0.7);
  text-align: center;
}
.admin-modal-icon { font-size: 2.5rem; margin-bottom: 0.75rem; }
.admin-modal-title { font-size: 1.1rem; font-weight: 700; color: #fff; margin-bottom: 0.5rem; }
.admin-modal-msg { font-size: 0.875rem; color: #8db8a8; margin-bottom: 1.5rem; line-height: 1.5; }
.admin-modal-actions { display: flex; gap: 0.75rem; justify-content: center; }
.admin-modal-cancel {
  background: rgba(255,255,255,0.08); color: #ccc;
  border: 1px solid rgba(255,255,255,0.15);
  padding: 0.55rem 1.25rem; border-radius: 7px;
  cursor: pointer; font-size: 0.875rem; transition: background 0.15s;
}
.admin-modal-cancel:hover { background: rgba(255,255,255,0.14); }
.admin-modal-confirm {
  background: #c0392b; color: #fff; border: none;
  padding: 0.55rem 1.25rem; border-radius: 7px;
  cursor: pointer; font-size: 0.875rem; font-weight: 600; transition: background 0.15s;
}
.admin-modal-confirm:hover { background: #e74c3c; }
</style>

<!-- Delete confirmation modal -->
<div id="admin-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="admin-modal-title-text">
  <div class="admin-modal-box">
    <div class="admin-modal-icon">&#x1F5D1;</div>
    <div class="admin-modal-title" id="admin-modal-title-text">Confirm Delete</div>
    <div class="admin-modal-msg" id="admin-modal-msg">This action cannot be undone.</div>
    <div class="admin-modal-actions">
      <button class="admin-modal-cancel" onclick="adminModalClose()">Cancel</button>
      <button class="admin-modal-confirm" id="admin-modal-confirm-btn">Delete</button>
    </div>
  </div>
</div>

<script>
(function() {
  // Dropdown nav
  document.querySelectorAll('.admin-nav-trigger').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
      e.stopPropagation();
      var group = btn.closest('.admin-nav-group');
      var isOpen = group.classList.contains('open');
      document.querySelectorAll('.admin-nav-group.open').forEach(function(g) {
        g.classList.remove('open');
        g.querySelector('.admin-nav-trigger').setAttribute('aria-expanded','false');
      });
      if (!isOpen) {
        group.classList.add('open');
        btn.setAttribute('aria-expanded','true');
      }
    });
  });
  document.addEventListener('click', function() {
    document.querySelectorAll('.admin-nav-group.open').forEach(function(g) {
      g.classList.remove('open');
      g.querySelector('.admin-nav-trigger').setAttribute('aria-expanded','false');
    });
  });
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      document.querySelectorAll('.admin-nav-group.open').forEach(function(g) {
        g.classList.remove('open');
        g.querySelector('.admin-nav-trigger').setAttribute('aria-expanded','false');
      });
      adminModalClose();
    }
  });

  // Delete confirm modal
  window._adminModalForm = null;
  window._adminModalHref = null;
  window.adminConfirm = function(msg, form) {
    document.getElementById('admin-modal-msg').textContent = msg || 'This action cannot be undone.';
    window._adminModalForm = form;
    window._adminModalHref = null;
    document.getElementById('admin-confirm-modal').classList.add('open');
    document.getElementById('admin-modal-confirm-btn').focus();
  };
  window.adminModalClose = function() {
    document.getElementById('admin-confirm-modal').classList.remove('open');
    window._adminModalForm = null;
    window._adminModalHref = null;
  };
  window.adminConfirmHref = function(linkEl, msg) {
    window._adminModalHref = linkEl.href;
    window._adminModalForm = null;
    document.getElementById('admin-modal-msg').textContent = msg || 'This action cannot be undone.';
    document.getElementById('admin-confirm-modal').classList.add('open');
    document.getElementById('admin-modal-confirm-btn').focus();
  };
  document.getElementById('admin-modal-confirm-btn').addEventListener('click', function() {
    if (window._adminModalForm) {
      window._adminModalForm.submit();
    } else if (window._adminModalHref) {
      window.location.href = window._adminModalHref;
    }
    adminModalClose();
  });
  document.getElementById('admin-confirm-modal').addEventListener('click', function(e) {
    if (e.target === this) adminModalClose();
  });

  // Auto-wire data-confirm forms
  document.addEventListener('submit', function(e) {
    var form = e.target;
    var msg = form.getAttribute('data-confirm');
    if (msg !== null) {
      e.preventDefault();
      adminConfirm(msg, form);
    }
  }, true);
})();
</script>
    <?php
}
