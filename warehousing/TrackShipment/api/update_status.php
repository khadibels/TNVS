<?php
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_login();
if (session_status()===PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

$id      = (int)($_POST['id'] ?? 0);
$status  = trim($_POST['status'] ?? '');
$details = trim($_POST['details'] ?? '');

$valid = ['Ready','Dispatched','In Transit','Delivered','Delayed','Cancelled','Returned'];
if (!$id || !in_array($status, $valid, true)) {
  http_response_code(400); echo json_encode(['ok'=>false,'err'=>'Invalid input']); exit;
}

$pdo->beginTransaction();
try {
  $pdo->prepare("UPDATE shipments SET status=? WHERE id=?")->execute([$status,$id]);
  $pdo->prepare("INSERT INTO shipment_events (shipment_id,event_type,details,user_id) VALUES (?,?,?,?)")
      ->execute([$id,$status,($details!==''?$details:null),($_SESSION['user']['id']??null)]);
  $pdo->commit();
  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  $pdo->rollBack(); http_response_code(500); echo json_encode(['ok'=>false,'err'=>'Update failed']);
}
