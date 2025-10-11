<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

vendor_require_login();

$auth = $_SESSION['user'] ?? [];
$vendorId = (int)($auth['vendor_id'] ?? 0);

$status = 'draft';
if ($vendorId) {
  $st = db('proc')->prepare("SELECT status FROM vendors WHERE id=?");
  $st->execute([$vendorId]);
  $status = strtolower($st->fetchColumn() ?: 'draft');

  $u = db('auth')->prepare("UPDATE users SET vendor_status=? WHERE id=?");
  $u->execute([$status, (int)$auth['id']]);
}

$base = rtrim(BASE_URL,'/');

switch ($status) {
  case 'approved':
    $dest = "$base/vendor_portal/vendor/dashboard.php"; break;
  case 'pending':
  case 'rejected':
    $dest = "$base/vendor_portal/vendor/pending.php";   break;
  case 'draft':
  default:
    $dest = "$base/vendor_portal/vendor/compliance.php"; break;
}
header("Location: $dest"); exit;
