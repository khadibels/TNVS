<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

function bad($m,$c=400){ http_response_code($c); echo json_encode(['error'=>$m]); exit; }

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) bad('missing_id');

/* ===== schema helpers ===== */
function has_col(PDO $pdo, string $table, string $col): bool {
  $q = $pdo->prepare("
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $q->execute([$table,$col]);
  return (bool)$q->fetchColumn();
}
function col_nullable(PDO $pdo, string $table, string $col): ?bool {
  $q = $pdo->prepare("
    SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $q->execute([$table,$col]);
  $v = $q->fetchColumn();
  if ($v === false) return null;
  return strtoupper((string)$v) === 'YES';
}
function first_existing_col(PDO $pdo, string $table, array $candidates): ?string {
  foreach ($candidates as $c) if (has_col($pdo,$table,$c)) return $c;
  return null;
}

try {
  /* 1) Load PR (must be approved) */
  $h = $pdo->prepare("SELECT * FROM procurement_requests WHERE id=?");
  $h->execute([$id]);
  $pr = $h->fetch(PDO::FETCH_ASSOC);
  if (!$pr) bad('pr_not_found',404);
  if (strtolower((string)$pr['status']) !== 'approved') bad('pr_must_be_approved');

  /* 2) Load PR items */
  $it = $pdo->prepare("SELECT descr, qty, price FROM procurement_request_items WHERE pr_id=?");
  $it->execute([$id]);
  $rows = $it->fetchAll(PDO::FETCH_ASSOC);
  if (!$rows) bad('pr_has_no_items');

  $pdo->beginTransaction();

  /* 3) PO number */
  $po_no = 'PO-'.date('Ymd').'-'.str_pad((string)random_int(1,9999), 4, '0', STR_PAD_LEFT);

  /* 4) supplier_id value that won’t violate NOT NULL/FK */
  $supplierId = null;
  if (has_col($pdo,'purchase_orders','supplier_id')) {
    $nullable = col_nullable($pdo,'purchase_orders','supplier_id');
    if ($nullable === false) {
      // Prefer active with best rating, else any supplier
      $s = $pdo->query("
        SELECT id FROM suppliers
        WHERE is_active = 1
        ORDER BY rating DESC, id ASC
        LIMIT 1
      ")->fetchColumn();
      if (!$s) { $s = $pdo->query("SELECT id FROM suppliers ORDER BY id ASC LIMIT 1")->fetchColumn(); }
      if (!$s) bad('no_supplier_available_create_one_first');
      $supplierId = (int)$s;
    }
  }

  /* 5) Insert into purchase_orders (dynamic columns) */
  $poTable = 'purchase_orders';
  foreach (['po_no','status','total'] as $m) if (!has_col($pdo,$poTable,$m)) bad("$poTable.$m missing");
  $candidates = [
    'po_no'        => $po_no,
    'supplier_id'  => $supplierId,           // may be null if nullable
    'order_date'   => date('Y-m-d'),
    'expected_date'=> $pr['needed_by'] ?? null,
    'status'       => 'ordered',
    'notes'        => $pr['title'] ?? '',
    'total'        => 0,
    'created_at'   => date('Y-m-d H:i:s'),
    'updated_at'   => date('Y-m-d H:i:s'),
    'pr_id'        => $id,
  ];
  $cols=[]; $vals=[]; $ph=[];
  foreach ($candidates as $col=>$val) {
    if (has_col($pdo,$poTable,$col)) { $cols[]=$col; $vals[]=$val; $ph[]='?'; }
  }
  $sql = "INSERT INTO {$poTable} (".implode(',',$cols).") VALUES (".implode(',',$ph).")";
  $pdo->prepare($sql)->execute($vals);
  $po_id = (int)$pdo->lastInsertId();

  /* 6) Insert items — detect real column names */
  $itemsTable = 'purchase_order_items';
  if (!has_col($pdo,$itemsTable,'po_id')) bad("$itemsTable.po_id missing");

  // Try to discover column names for description, qty, price, line total
  $descrCol = first_existing_col($pdo, $itemsTable, ['descr','description','item_desc','item_name','name','detail']);
  $qtyCol   = first_existing_col($pdo, $itemsTable, ['qty','quantity','qty_ordered']);
  $priceCol = first_existing_col($pdo, $itemsTable, ['price','unit_price','unit_cost','cost','rate']);
  $lineCol  = first_existing_col($pdo, $itemsTable, ['line_total','lineamount','line_amount','amount','total','extended_price']);

  if (!$descrCol) bad("$itemsTable description column missing (tried: descr, description, item_desc, item_name, name, detail)");
  if (!$qtyCol)   bad("$itemsTable quantity column missing (tried: qty, quantity, qty_ordered)");
  if (!$priceCol) bad("$itemsTable unit price column missing (tried: price, unit_price, unit_cost, cost, rate)");
  // lineCol is optional — we’ll skip it if the table doesn’t have one

  // Build dynamic insert for items
  $itemCols = array_filter(['po_id', $descrCol, $qtyCol, $priceCol, $lineCol]);
  $itemPh   = implode(',', array_fill(0, count($itemCols), '?'));
  $insItem  = $pdo->prepare("INSERT INTO {$itemsTable} (".implode(',',$itemCols).") VALUES ($itemPh)");

  $total = 0.0;
  foreach ($rows as $r) {
    $qty = (float)$r['qty'];
    $price = (float)$r['price'];
    $lt = $qty * $price;
    $total += $lt;

    $params = [$po_id, $r['descr'], $qty, $price];
    if ($lineCol) $params[] = $lt; // only add if column exists
    $insItem->execute($params);
  }

  /* 7) Update PO total */
  $pdo->prepare("UPDATE {$poTable} SET total=? WHERE id=?")->execute([$total,$po_id]);

  /* 8) Mark PR fulfilled */
  $hasUpdatedAt = has_col($pdo,'procurement_requests','updated_at');
  $sqlFul = $hasUpdatedAt
    ? "UPDATE procurement_requests SET status='fulfilled', updated_at=NOW() WHERE id=?"
    : "UPDATE procurement_requests SET status='fulfilled' WHERE id=?";
  $pdo->prepare($sqlFul)->execute([$id]);

  $pdo->commit();
  echo json_encode(['ok'=>1,'po_id'=>$po_id,'po_no'=>$po_no]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  bad('server_error: '.$e->getMessage(), 500);
}
