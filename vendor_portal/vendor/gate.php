<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

vendor_require_login();

$status = strtolower($_SESSION['user']['vendor_status'] ?? 'pending');
$dest = $status === 'approved'
    ? rtrim(BASE_URL,'/') . '/vendor_portal/vendor/dashboard.php'
    : rtrim(BASE_URL,'/') . '/vendor_portal/vendor/pending.php';

header('Location: ' . $dest);
exit;
