<?php
/**
 * includes/partner-header.php
 * Shared HTML header for the Business Acquisition module pages.
 * Sets up: DOCTYPE, Bootstrap 5, Bootstrap Icons, partner top-nav.
 *
 * Pages must set $pageTitle and call requireGrowthPartner() BEFORE including this.
 * $partnerProfile must be set (returned by requireGrowthPartner()).
 */

// Defensive: ensure session started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_pHeader_title = htmlspecialchars($pageTitle ?? 'Partner Portal', ENT_QUOTES, 'UTF-8');
$_pHeader_name  = htmlspecialchars($partnerProfile['business_name'] ?? $partnerProfile['full_name'] ?? 'Partner', ENT_QUOTES, 'UTF-8');
$_pHeader_base  = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';

// Active page detection (filename without .php)
$_pHeader_active = basename($_SERVER['SCRIPT_FILENAME'] ?? '', '.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $_pHeader_title ?> — 237Biz Partner</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
<style>
  /* Reset any global site overrides so Bootstrap works correctly */
  body { font-family: 'Inter', system-ui, sans-serif; background: #f8f9fa; }
  .card { border-radius: 0.375rem !important; background-color: #fff !important; border: 1px solid rgba(0,0,0,.125) !important; }
  .card-header { border-radius: 0 !important; background-color: #f8f9fa !important; border-bottom: 1px solid rgba(0,0,0,.125) !important; }
  .btn { border-radius: 0.375rem !important; }
  .badge { border-radius: 0.375rem !important; }
  .alert { border-radius: 0.375rem !important; }
  .table { border-radius: 0 !important; }
  .form-control, .form-select { border-radius: 0.375rem !important; }
  .list-group-item { border-radius: 0 !important; }

  /* ── Top navbar ── */
  .pnav-top {
    background: #0f172a;
    padding: 0 1rem;
    position: sticky; top: 0; z-index: 1030;
  }
  .pnav-top .navbar-brand {
    font-weight: 700; font-size: 1.1rem; color: #fff; text-decoration: none;
  }
  .pnav-top .navbar-brand span { color: #38bdf8; }

  /* ── Horizontal scrollable sub-nav ── */
  .pnav-sub {
    background: #1e293b;
    border-bottom: 1px solid #334155;
    overflow-x: auto;
    white-space: nowrap;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
  }
  .pnav-sub::-webkit-scrollbar { display: none; }
  .pnav-sub-inner {
    display: inline-flex;
    gap: 0;
    padding: 0 0.5rem;
    min-width: 100%;
  }
  .pnav-sub a {
    display: inline-flex; align-items: center; gap: 0.35rem;
    padding: 0.65rem 0.9rem;
    color: #94a3b8;
    text-decoration: none;
    font-size: 0.82rem; font-weight: 500;
    border-bottom: 2px solid transparent;
    transition: color 0.15s, border-color 0.15s;
    white-space: nowrap;
  }
  .pnav-sub a:hover { color: #e2e8f0; }
  .pnav-sub a.active {
    color: #38bdf8;
    border-bottom-color: #38bdf8;
  }

  /* Badge on nav links */
  .pnav-badge {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 1.2rem; height: 1.2rem;
    background: #ef4444; color: #fff;
    font-size: 0.65rem; font-weight: 700;
    border-radius: 0.6rem; padding: 0 0.3rem;
  }

  /* Page flash messages */
  .flash-container { max-width: 100%; }
</style>
</head>
<body>

<!-- ── Top navbar ─────────────────────────────────────────────── -->
<nav class="pnav-top navbar navbar-expand-lg">
  <div class="container-fluid">
    <a class="navbar-brand" href="<?= $_pHeader_base ?>/partner/dashboard">
      237<span>Biz</span> <span class="text-white-50 fw-normal ms-1 small">Partner</span>
    </a>
    <div class="d-flex align-items-center gap-3 ms-auto">
      <span class="text-white-50 small d-none d-md-inline"><?= $_pHeader_name ?></span>
      <a href="<?= $_pHeader_base ?>/partner/dashboard" class="btn btn-sm btn-outline-secondary text-white border-secondary">
        <i class="bi bi-grid me-1"></i>Dashboard
      </a>
      <a href="<?= $_pHeader_base ?>/logout.php" class="text-white-50 small text-decoration-none">
        <i class="bi bi-box-arrow-right"></i>
      </a>
    </div>
  </div>
</nav>

<!-- ── Acquisition sub-nav ────────────────────────────────────── -->
<div class="pnav-sub">
  <div class="pnav-sub-inner">
    <?php
    $navItems = [
        ['acquisition-dashboard',  'bi-speedometer2',      'Overview',      '/partner/acquisition-dashboard'],
        ['find-businesses',        'bi-search',            'Find Businesses','/partner/find-businesses'],
        ['my-prospects',           'bi-person-lines-fill', 'My Prospects',  '/partner/my-prospects'],
        ['acquisition-insights',   'bi-lightbulb',         'Insights',      '/partner/acquisition-insights'],
        ['acquisition-report',     'bi-graph-up-arrow',    'Report',        '/partner/acquisition-report'],
    ];
    foreach ($navItems as [$key, $icon, $label, $path]):
        $active = $_pHeader_active === $key ? ' active' : '';
    ?>
    <a href="<?= $_pHeader_base . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') ?>" class="<?= $active ?>">
      <i class="bi <?= $icon ?>"></i> <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
    </a>
    <?php endforeach; ?>

    <span style="flex:1"></span>
    <a href="<?= $_pHeader_base ?>/partner/dashboard" style="border-left:1px solid #334155; margin-left:0.25rem; padding-left:1rem;">
      <i class="bi bi-arrow-left-short"></i> Partner Home
    </a>
  </div>
</div>

<!-- ── Flash messages ─────────────────────────────────────────── -->
<?php if (!empty($_SESSION['flash_success'])): ?>
<div class="container-fluid px-4 pt-3 flash-container">
  <div class="alert alert-success alert-dismissible fade show mb-0" role="alert">
    <?= htmlspecialchars($_SESSION['flash_success'], ENT_QUOTES, 'UTF-8') ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
</div>
<?php unset($_SESSION['flash_success']); endif; ?>
<?php if (!empty($_SESSION['flash_error'])): ?>
<div class="container-fluid px-4 pt-3 flash-container">
  <div class="alert alert-danger alert-dismissible fade show mb-0" role="alert">
    <?= htmlspecialchars($_SESSION['flash_error'], ENT_QUOTES, 'UTF-8') ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
</div>
<?php unset($_SESSION['flash_error']); endif; ?>
