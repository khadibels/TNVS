<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role(['admin','manager'], 'json');

header('Content-Type: application/json');

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  http_response_code(422);
  echo json_encode(['ok'=>false,'error'=>'INVALID_ID']);
  exit;
}

try {
  // Guard: refuse delete if referenced
  $inLevels = $pdo->prepare("SELECT 1 FROM stock_levels WHERE location_id = ? LIMIT 1");
  $inLevels->execute([$id]);
  if ($inLevels->fetch()) {
    http_response_code(409);
    echo json_encode(['ok'=>false,'error'=>'IN_USE_STOCK_LEVELS']);
    exit;
  }
  $inTx = $pdo->prepare("SELECT 1 FROM stock_transactions WHERE from_location_id = ? OR to_location_id = ? LIMIT 1");
  $inTx->execute([$id,$id]);
  if ($inTx->fetch()) {
    http_response_code(409);
    echo json_encode(['ok'=>false,'error'=>'IN_USE_TRANSACTIONS']);
    exit;
  }

  $st = $pdo->prepare("DELETE FROM warehouse_locations WHERE id = ?");
  $st->execute([$id]);

  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'SERVER_ERROR']);
}
