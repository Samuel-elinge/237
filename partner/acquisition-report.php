<?php
// Redirects to the new Reports page (acquisition-reports.php?tab=performance)
// acquisition-report.php has been merged into the Reports page.
require_once '../includes/config.php';
require_once '../includes/partner-helpers.php';
requireGrowthPartner(); // auth check before redirect

header('Location: ' . (defined('SITE_URL') ? rtrim(SITE_URL, '/') : '') . '/partner/acquisition-reports?tab=performance', true, 301);
exit;
