<?php
/**
 * includes/admin-header.php
 *
 * Convenience wrapper used by Phase 3 admin pages.
 * Outputs the standard site header + admin nav bar.
 *
 * Usage (at top of an admin page, before any HTML output):
 *   $pageTitle = 'Page Title — Admin';
 *   require_once __DIR__ . '/../includes/admin-header.php';
 *
 * The calling script's $pageTitle is used for the <title> tag.
 * The active nav item is auto-detected from the calling script's filename.
 */
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/partner-helpers.php';
require_once __DIR__ . '/../admin/includes/nav.php';

// Derive active page key from the calling script's filename (no extension)
$_adminNavActivePage = basename($_SERVER['SCRIPT_FILENAME'] ?? '', '.php');
adminNav($_adminNavActivePage);
adminNavAssets();
?>
<style>
/* ── Phase 3 admin layout helpers ─────────────────────── */
.admin-page-header{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.75rem;padding:1.25rem 0;margin-bottom:1rem;}
.admin-page-header h1{font-family:'Fraunces',serif;font-weight:900;font-size:1.6rem;margin:0;}
.admin-card{background:var(--card,#101e17);border:1px solid var(--border,rgba(255,255,255,.08));border-radius:10px;padding:1.25rem;}
.admin-table{width:100%;border-collapse:collapse;font-size:.84rem;}
.admin-table thead tr{background:rgba(255,255,255,.03);}
.admin-table th{padding:.7rem 1rem;text-align:left;color:var(--muted,#6b7280);font-weight:600;white-space:nowrap;}
.admin-table td{padding:.7rem 1rem;border-top:1px solid var(--border,rgba(255,255,255,.08));vertical-align:middle;}
.admin-table tbody tr:hover{background:rgba(255,255,255,.02);}
</style>
<?php
