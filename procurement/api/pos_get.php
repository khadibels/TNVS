<?php
// ./api/pos_get.php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

function bad($m,$c=400){
  http_response_code($c);
  echo json_encode(['error'=>$m]);
  exit;
}

try {
  $id = (int)($_GET['id'] ?? 0);
  if ($id <= 0) bad('id required');

  // ---- header ----
  $st = $pdo->prepare("
    SELECT po.id, po.po_no, po.supplier_id, po.order_date, po.expected_date,
           po.status, po.notes, po.total,
           s.code AS supplier_code, s.name AS supplier_name
    FROM purchase_orders po
    LEFT JOIN suppliers s ON s.id = po.supplier_id
    WHERE po.id = ?
  ");
  $st->execute([$id]);
  $hdr = $st->fetch(PDO::FETCH_ASSOC);
  if (!$hdr) bad('PO not found', 404);

  // ---- items ----
  $st = $pdo->prepare("
    SELECT id, descr, qty, price,
           COALESCE(qty_received,0) AS qty_received,
           (qty*price) AS line_total
    FROM purchase_order_items
    WHERE po_id = ?
    ORDER BY id
  ");
  $st->execute([$id]);
  $items = $st->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode(['header'=>$hdr, 'items'=>$items]);
} catch (Throwable $e) {
  bad('server_error: '.$e->getMessage(), 500);
}
