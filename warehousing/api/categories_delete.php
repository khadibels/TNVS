<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login('json');

header('Content-Type: application/json');
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { http_response_code(422); echo json_encode(['ok'=>false,'error'=>'INVALID_ID']); exit; }

try {
  
  $g = $pdo->prepare("SELECT name FROM inventory_categories WHERE id=?");
  $g->execute([$id]);
  $cat = $g->fetchColumn();

  if (!$cat) { echo json_encode(['ok'=>true]); exit; }

  $chk = $pdo->prepare("SELECT 1 FROM inventory_items WHERE category=? LIMIT 1");
  $chk->execute([$cat]);
  if ($chk->fetch()) {
    http_response_code(409);
    echo json_encode(['ok'=>false,'error'=>'IN_USE_ITEMS']);
    exit;
  }

  $st = $pdo->prepare("DELETE FROM inventory_categories WHERE id=?");
  $st->execute([$id]);
  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'SERVER_ERROR']);
}
