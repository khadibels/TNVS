<?php
// procurement/api/rfqs_award.php
declare(strict_types=1);

$inc = __DIR__ . '/../../includes';
require_once $inc . '/config.php';
require_once $inc . '/auth.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

function bad($m, $code = 400){ http_response_code($code); echo json_encode(['error'=>$m]); exit; }
function col_exists(PDO $pdo, string $t, string $c): bool {
  $st=$pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
  $st->execute([$t,$c]); return (bool)$st->fetchColumn();
}

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') bad('POST required');
  $rfq_id = (int)($_POST['rfq_id'] ?? 0);
  $supplier_id = (int)($_POST['supplier_id'] ?? 0);
  if ($rfq_id <= 0 || $supplier_id <= 0) bad('rfq_id and supplier_id required');

  $pdo->beginTransaction();

  // 1) Lock RFQ & prevent double award
  $st = $pdo->prepare("SELECT status FROM rfqs WHERE id=? FOR UPDATE");
  $st->execute([$rfq_id]);
  $curStatus = $st->fetchColumn();
  if ($curStatus === false) throw new Exception('RFQ not found');
  if (strtolower((string)$curStatus) === 'awarded') throw new Exception('This RFQ is already awarded.');

  // 2) Find the latest quote for this supplier on this RFQ
  $q = $pdo->prepare("
    SELECT q.id AS quote_id,
           COALESCE(q.total_amount, q.total, q.grand_total, q.total_cache, 0) AS q_total,
           COALESCE(q.submitted_at, q.updated_at, q.created_at, NOW()) AS q_when
    FROM quotes q
    WHERE q.rfq_id = ? AND q.supplier_id = ?
    ORDER BY q_when DESC
    LIMIT 1
  ");
  $q->execute([$rfq_id, $supplier_id]);
  $quote = $q->fetch(PDO::FETCH_ASSOC);
  if (!$quote) throw new Exception('No quote found for this supplier.');

  // 3) Create PO header
  $poNumber = 'PO-' . date('Ymd') . '-' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
  $pdo->prepare("INSERT INTO purchase_orders (po_no, supplier_id, issue_date, expected_date, status, notes, total)
                 VALUES (?, ?, CURDATE(), CURDATE(), 'ordered', CONCAT('From RFQ #', ?), 0)")
      ->execute([$poNumber, $supplier_id, $rfq_id]);
  $po_id = (int)$pdo->lastInsertId();

  // 4) Copy quote items -> PO items
  //    Try to be flexible with quote_items columns
  $qiCols = $pdo->query("SHOW COLUMNS FROM quote_items")->fetchAll(PDO::FETCH_COLUMN, 0);
  $has = fn($c) => in_array($c, $qiCols, true);

  $qtyCol   = $has('quantity') ? 'quantity' : ($has('qty') ? 'qty' : null);
  $priceCol = $has('unit_price') ? 'unit_price' : ($has('price') ? 'price' : ($has('unit_cost') ? 'unit_cost' : null));
  $descCol  = $has('description') ? 'description' : ($has('item_name') ? 'item_name' : ($has('name') ? 'name' : null));
  $lineCol  = $has('line_total') ? 'line_total' : ($has('amount') ? 'amount' : ($has('total') ? 'total' : null));

  $stIt = $pdo->prepare("SELECT * FROM quote_items WHERE quote_id=?");
  $stIt->execute([$quote['quote_id']]);

  $ins = $pdo->prepare("INSERT INTO purchase_order_items (po_id, description, qty, unit_price, line_total)
                        VALUES (?, ?, ?, ?, ?)");
  $poTotal = 0.0;

  while ($r = $stIt->fetch(PDO::FETCH_ASSOC)) {
    $descr = $descCol ? (string)($r[$descCol] ?? 'Item') : 'Item';
    $qty   = $qtyCol   ? (float)($r[$qtyCol] ?? 1) : 1.0;
    $price = $priceCol ? (float)($r[$priceCol] ?? 0) : 0.0;
    $line  = $lineCol  ? (float)($r[$lineCol] ?? ($qty * $price)) : ($qty * $price);

    $ins->execute([$po_id, $descr, $qty, $price, $line]);
    $poTotal += $line;
  }

  // 5) Update PO total; mark RFQ awarded
  $pdo->prepare("UPDATE purchase_orders SET total=? WHERE id=?")->execute([$poTotal, $po_id]);
  $pdo->prepare("UPDATE rfqs SET status='awarded', awarded_supplier_id=? WHERE id=?")
      ->execute([$supplier_id, $rfq_id]);

  $pdo->commit();
  echo json_encode(['ok'=>true, 'po_number'=>$poNumber, 'po_id'=>$po_id]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(400);
  echo json_encode(['error' => $e->getMessage()]);
}
