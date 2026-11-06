<?php
// Redirects to the new Pipeline page (acquisition-dashboard.php)
// my-prospects.php has been merged into the Pipeline page.
require_once '../includes/config.php';
require_once '../includes/partner-helpers.php';
requireGrowthPartner(); // auth check before redirect

$qs = '';
if (!empty($_SERVER['QUERY_STRING'])) {
    $qs = '?' . $_SERVER['QUERY_STRING'];
}
header('Location: ' . (defined('SITE_URL') ? rtrim(SITE_URL, '/') : '') . '/partner/acquisition-dashboard' . $qs, true, 301);
exit;
