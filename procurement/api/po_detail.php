<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

try {
  require_login(); require_role(['admin','procurement_officer','manager']);

  $id = (int)($_GET['id'] ?? 0);
  if ($id <= 0) throw new Exception('Invalid id');

  $pdo = db('proc'); if(!$pdo instanceof PDO) throw new Exception('DB error');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $st = $pdo->prepare("
    SELECT p.*, r.rfq_no, r.title, v.company_name AS vendor_name
    FROM pos p
    LEFT JOIN rfqs r ON r.id=p.rfq_id
    LEFT JOIN vendors v ON v.id=p.vendor_id
    WHERE p.id=?
  ");
  $st->execute([$id]);
  $po = $st->fetch(PDO::FETCH_ASSOC);
  if (!$po) throw new Exception('PO not found');

  $it = $pdo->prepare("
    SELECT id, rfq_item_id, line_no, item, specs, qty, uom, unit_price, line_total
    FROM po_items
    WHERE po_id=?
    ORDER BY line_no ASC, id ASC
  ");
  $it->execute([$id]);
  $items = $it->fetchAll(PDO::FETCH_ASSOC) ?: [];

  echo json_encode(['po'=>$po,'items'=>$items]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['error'=>$e->getMessage()]);
}
