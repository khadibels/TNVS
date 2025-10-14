<?php
require_once __DIR__ . '/../../../../includes/config.php';
require_once __DIR__ . '/../../../../includes/db.php';
require_once __DIR__ . '/../../../../includes/auth.php';
header('Content-Type: application/json');
require_login();
require_role(['vendor']);

$pdo = db('proc');
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo json_encode(['error'=>'Invalid id']); exit; }

try {
  $st = $pdo->prepare("UPDATE pos SET vendor_ack_status='acknowledged', vendor_ack_at=NOW() WHERE id=?");
  $st->execute([$id]);
  echo json_encode(['ok'=>true]);
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['error'=>$e->getMessage()]);
}
