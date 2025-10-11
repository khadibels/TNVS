<?php
// File: vendor_portal/vendor/api/notifications_mark.php
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$pdo = db('proc');
if (!$pdo instanceof PDO) { echo json_encode(['error' => 'DB error']); exit; }

$vendorId = (int)($_SESSION['user']['vendor_id'] ?? 0);
$id       = (int)($_POST['id'] ?? 0);

if ($vendorId <= 0 || $id <= 0) {
  echo json_encode(['error'=>'Invalid request']);
  exit;
}

try {
  $st = $pdo->prepare("UPDATE vendor_notifications SET is_read = 1 WHERE id = ? AND vendor_id = ?");
  $st->execute([$id, $vendorId]);
  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  echo json_encode(['error'=>$e->getMessage()]);
}
