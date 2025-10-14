<?php
require_once __DIR__ . '/../../../../includes/config.php';
require_once __DIR__ . '/../../../../includes/db.php';
require_once __DIR__ . '/../../../../includes/auth.php';
header('Content-Type: application/json');
require_login();
require_role(['vendor']);

$pdo = db('proc');
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo json_encode(['error'=>'Invalid id']); exit; }

try {
  $st = $pdo->prepare("SELECT * FROM pos WHERE id=?");
  $st->execute([$id]);
  $po = $st->fetch(PDO::FETCH_ASSOC);
  if (!$po) throw new Exception('PO not found');

  $it = $pdo->prepare("SELECT * FROM po_items WHERE po_id=? ORDER BY line_no ASC");
  $it->execute([$id]);
  $items = $it->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode(['po'=>$po,'items'=>$items]);
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['error'=>$e->getMessage()]);
}
