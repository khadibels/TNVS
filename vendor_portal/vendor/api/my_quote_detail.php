<?php
require_once __DIR__ . "/../../../includes/config.php";
require_once __DIR__ . "/../../../includes/db.php";
require_once __DIR__ . "/../../../includes/auth.php";

require_login();
$u = current_user();
$vendorId = (int)($u['vendor_id'] ?? 0);
if ($vendorId <= 0) { http_response_code(403); echo json_encode(['error'=>'No vendor id']); exit; }

header('Content-Type: application/json; charset=utf-8');
$pdo = db('proc');
if (!$pdo instanceof PDO) { http_response_code(500); echo json_encode(['error'=>'DB error']); exit; }
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
  $id = (int)($_GET['id'] ?? 0);
  if ($id <= 0) throw new Exception('Invalid quote id');

  // Load quote and make sure it belongs to this vendor
  $q = $pdo->prepare("SELECT * FROM quotes WHERE id=? AND vendor_id=?");
  $q->execute([$id, $vendorId]);
  $quote = $q->fetch(PDO::FETCH_ASSOC);
  if (!$quote) throw new Exception('Quote not found');

  // RFQ info
  $r = $pdo->prepare("SELECT id, rfq_no, title, description, currency, status, due_at, awarded_vendor_id FROM rfqs WHERE id=?");
  $r->execute([(int)$quote['rfq_id']]);
  $rfq = $r->fetch(PDO::FETCH_ASSOC);
  if (!$rfq) throw new Exception('RFQ not found');

  // Normalize RFQ status label
  $st = strtolower((string)$rfq['status']);
  $rfq['status_label'] = ($st==='sent') ? 'open' : $rfq['status'];

  // RFQ items
  $it = $pdo->prepare("SELECT id, line_no, item, specs, qty, uom FROM rfq_items WHERE rfq_id=? ORDER BY line_no ASC, id ASC");
  $it->execute([(int)$rfq['id']]);
  $items = $it->fetchAll(PDO::FETCH_ASSOC);

 // Quote items (unit prices per RFQ line) — get line_no from rfq_items
$qi = $pdo->prepare("
  SELECT
    ri.line_no,
    qi.rfq_item_id,
    qi.unit_price
  FROM quote_items qi
  JOIN rfq_items   ri ON ri.id = qi.rfq_item_id
  WHERE qi.quote_id = ?
  ORDER BY ri.line_no ASC
");
$qi->execute([$id]);
$quoteItems = $qi->fetchAll(PDO::FETCH_ASSOC);


  // outcome
  $outcome = 'pending';
  if ($st==='awarded') {
    $outcome = ((int)$rfq['awarded_vendor_id'] === $vendorId) ? 'awarded_me' : 'lost';
  } elseif (in_array($st, ['closed','cancelled'], true)) {
    $outcome = 'lost';
  }

  echo json_encode([
    'quote' => $quote,
    'rfq'   => $rfq,
    'items' => $items,
    'quote_items' => $quoteItems,
    'outcome' => $outcome
  ]);
} catch (Throwable $e) {
  http_response_code(400); echo json_encode(['error'=>$e->getMessage()]);
}
