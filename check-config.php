<?php
// check-config.php — DELETE AFTER USE
require_once __DIR__ . '/includes/config.php';
echo json_encode([
    'SITE_EMAIL'     => defined('SITE_EMAIL')     ? SITE_EMAIL     : 'NOT DEFINED',
    'SMTP_HOST'      => defined('SMTP_HOST')       ? SMTP_HOST      : 'NOT DEFINED',
    'SMTP_USER'      => defined('SMTP_USER')       ? SMTP_USER      : 'NOT DEFINED',
    'SMTP_PASS_SET'  => defined('SMTP_PASS')       ? (SMTP_PASS ? 'YES ('.strlen(SMTP_PASS).' chars)' : 'EMPTY') : 'NOT DEFINED',
    'SMTP_PORT'      => defined('SMTP_PORT')       ? SMTP_PORT      : 'NOT DEFINED',
    'SMTP_SECURE'    => defined('SMTP_SECURE')     ? SMTP_SECURE    : 'NOT DEFINED',
    'AUTOMATION_TOKEN' => defined('AUTOMATION_WEBHOOK_TOKEN') ? 'SET' : 'NOT DEFINED',
], JSON_PRETTY_PRINT);
