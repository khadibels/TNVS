<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

vendor_require_login();

$authUser = $_SESSION['user'] ?? [];
$vendorId = (int)($authUser['vendor_id'] ?? 0);

if ($vendorId <= 0) {
    session_destroy();
    header('Location: ' . rtrim(BASE_URL, '/') . '/login.php');
    exit;
}

$proc = db('proc');
$auth = db('auth');

$status = 'draft';

$st = $proc->prepare("SELECT status FROM vendors WHERE id=?");
$st->execute([$vendorId]);
$status = strtolower($st->fetchColumn() ?: 'draft');

$u = $auth->prepare("UPDATE users SET vendor_status=? WHERE id=?");
$u->execute([$status, (int)$authUser['id']]);

$_SESSION['user']['vendor_status'] = $status;

$base = rtrim(BASE_URL, '/');

switch ($status) {
    case 'approved':
        $dest = "$base/vendor_portal/vendor/dashboard.php";
        break;
    case 'pending':
    case 'rejected':
        $dest = "$base/vendor_portal/vendor/pending.php";
        break;
    case 'draft':
    default:
        $dest = "$base/vendor_portal/vendor/compliance.php";
        break;
}
header("Location: $dest");
exit;
