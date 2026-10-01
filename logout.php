<?php
// logout.php
require_once __DIR__ . '/includes/config.php';
session_destroy();
redirect(SITE_URL . '/index.php');
