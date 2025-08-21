<?php

declare(strict_types=1);

$inc = __DIR__ . '/../../includes';
require_once $inc . '/config.php';
require_once $inc . '/auth.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

function bad($m, $code = 400){
  http_response_code($code);
  echo json_encode(['error'=>$m]);
  exit;
}

function hasCol(PDO $pdo, string $table, string $col): bool {
  $st=$pdo->prepare("SELECT 1
                     FROM information_schema.columns
                     WHERE table_schema = DATABASE()
                       AND table_name   = ?
                       AND column_name  = ?");
  $st->execute([$table, $col]);
  return (bool)$st->fetchColumn();
}

function gen_po_no(PDO $pdo): string {
  $prefix = 'PO-'.date('Ym').'-';
  $st = $pdo->prepare("SELECT po_no
                       FROM purchase_orders
                       WHERE po_no LIKE ?
                       ORDER BY po_no DESC
                       LIMIT 1");
  $st->execute([$prefix.'%']);
  $last = $st->fetchColumn();
  $n = 1;
  if ($last && preg_match('/-(\d{4})$/', $last, $m)) $n = (int)$m[1] + 1;
  return $prefix . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') bad('POST required');
  $rfq_id     = (int)($_POST['rfq_id'] ?? 0);
  $supplier_id= (int)($_POST['supplier_id'] ?? 0);
  if ($rfq_id <= 0 || $supplier_id <= 0) bad('rfq_id and supplier_id required');

  $pdo->beginTransaction();


  $st = $pdo->prepare("SELECT status FROM rfqs WHERE id=? FOR UPDATE");
  $st->execute([$rfq_id]);
  $curStatus = $st->fetchColumn();
  if ($curStatus === false) throw new Exception('RFQ not found');
  if (strtolower((string)$curStatus) === 'awarded') throw new Exception('This RFQ is already awarded.');


  $totalCandidates = ['total_amount','total','grand_total','total_cache','amount','subtotal'];
  $whenCandidates  = ['submitted_at','updated_at','created_at'];

  $totalExprParts = [];
  foreach ($totalCandidates as $c) if (hasCol($pdo,'quotes',$c)) $totalExprParts[] = "q.`$c`";
  $totalExpr = $totalExprParts ? ('COALESCE('.implode(', ', $totalExprParts).', 0)') : '0';

  $whenExprParts = [];
  foreach ($whenCandidates as $c) if (hasCol($pdo,'quotes',$c)) $whenExprParts[] = "q.`$c`";
  $whenExpr = $whenExprParts ? ('COALESCE('.implode(', ', $whenExprParts).', NOW())') : 'NOW()';

  
  $sql = "
    SELECT q.id AS quote_id,
           $totalExpr AS q_total,
           $whenExpr  AS q_when
    FROM quotes q
    WHERE q.rfq_id = ? AND q.supplier_id = ?
    ORDER BY q_when DESC
    LIMIT 1
  ";
  $q = $pdo->prepare($sql);
  $q->execute([$rfq_id, $supplier_id]);
  $quote = $q->fetch(PDO::FETCH_ASSOC);
  if (!$quote) throw new Exception('No quote found for this supplier.');

 
  $po_no = gen_po_no($pdo);
  $hasPoNumber = hasCol($pdo, 'purchase_orders', 'po_number');

  if ($hasPoNumber) {
   
    $sqlIns = "
      INSERT INTO purchase_orders
        (po_number, po_no, supplier_id, order_date, expected_date, status, notes, total)
      VALUES
        (?,         ?,     ?,           CURDATE(),  CURDATE(),   'ordered', CONCAT('From RFQ #', ?), 0)
    ";
    $pdo->prepare($sqlIns)->execute([$po_no, $po_no, $supplier_id, $rfq_id]);
  } else {
    $sqlIns = "
      INSERT INTO purchase_orders
        (po_no, supplier_id, order_date, expected_date, status, notes, total)
      VALUES
        (?,     ?,           CURDATE(),  CURDATE(),   'ordered', CONCAT('From RFQ #', ?), 0)
    ";
    $pdo->prepare($sqlIns)->execute([$po_no, $supplier_id, $rfq_id]);
  }
  $po_id = (int)$pdo->lastInsertId();

 
  $qiCols = $pdo->query("SHOW COLUMNS FROM quote_items")->fetchAll(PDO::FETCH_COLUMN, 0);
  $has = fn($c) => in_array($c, $qiCols, true);

  $qtyCol   = $has('quantity')    ? 'quantity'
            : ($has('qty')        ? 'qty'
            : null);

  $priceCol = $has('unit_price')  ? 'unit_price'
            : ($has('price')      ? 'price'
            : ($has('unit_cost')  ? 'unit_cost' : null));

  $descCol  = $has('description') ? 'description'
            : ($has('item_name')  ? 'item_name'
            : ($has('name')       ? 'name'      : null));

  $lineCol  = $has('line_total')  ? 'line_total'
            : ($has('amount')     ? 'amount'
            : ($has('total')      ? 'total'     : null));

  $stIt = $pdo->prepare("SELECT * FROM quote_items WHERE quote_id=?");
  $stIt->execute([$quote['quote_id']]);

  
  $insItem = $pdo->prepare("INSERT INTO purchase_order_items (po_id, descr, qty, price) VALUES (?, ?, ?, ?)");
  $poTotal = 0.0;

  while ($r = $stIt->fetch(PDO::FETCH_ASSOC)) {
    $descr = $descCol  ? (string)($r[$descCol]  ?? 'Item') : 'Item';
    $qty   = $qtyCol   ? (float) ($r[$qtyCol]   ?? 1)      : 1.0;
    $price = $priceCol ? (float) ($r[$priceCol] ?? 0)      : 0.0;
    $line  = $lineCol  ? (float) ($r[$lineCol]  ?? ($qty*$price)) : ($qty*$price);

    $insItem->execute([$po_id, $descr, $qty, $price]);
    $poTotal += $line; 
  }

 
  $pdo->prepare("UPDATE purchase_orders SET total=? WHERE id=?")
      ->execute([round($poTotal,2), $po_id]);

  if (hasCol($pdo,'rfqs','awarded_supplier_id')) {
    $pdo->prepare("UPDATE rfqs SET status='awarded', awarded_supplier_id=? WHERE id=?")
        ->execute([$supplier_id, $rfq_id]);
  } else {
    $pdo->prepare("UPDATE rfqs SET status='awarded' WHERE id=?")
        ->execute([$rfq_id]);
  }

  $pdo->commit();
  echo json_encode(['ok'=>true, 'po_number'=>$po_no, 'po_id'=>$po_id]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(400);
  echo json_encode(['error' => $e->getMessage()]);
}
