<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

require_role(['vendor']);

$status = strtolower($_SESSION['user']['vendor_status'] ?? 'pending');
$dest = $status === 'approved'
    ? BASE_URL . '/vendor_portal/vendor/dashboard.php'
    : BASE_URL . '/vendor_portal/vendor/pending.php';

header('Location: ' . $dest);
exit;
