<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

function bad($m,$c=400){ http_response_code($c); echo json_encode(['error'=>$m]); exit; }

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) bad('missing_id');

function has_col(PDO $pdo, string $table, string $col): bool {
  $q = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $q->execute([$table,$col]);
  return (bool)$q->fetchColumn();
}
function col_nullable(PDO $pdo, string $table, string $col): ?bool {
  $q = $pdo->prepare("SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $q->execute([$table,$col]);
  $v = $q->fetchColumn();
  if ($v === false) return null;
  return strtoupper((string)$v) === 'YES';
}

try {
  // 1) Load PR (must be approved)
  $h = $pdo->prepare("SELECT * FROM procurement_requests WHERE id=?");
  $h->execute([$id]);
  $pr = $h->fetch(PDO::FETCH_ASSOC);
  if (!$pr) bad('pr_not_found',404);
  if (strtolower((string)$pr['status']) !== 'approved') bad('pr_must_be_approved');

  // 2) Load items
  $it = $pdo->prepare("SELECT descr, qty, price FROM procurement_request_items WHERE pr_id=?");
  $it->execute([$id]);
  $rows = $it->fetchAll(PDO::FETCH_ASSOC);
  if (!$rows) bad('pr_has_no_items');

  $pdo->beginTransaction();

  // 3) Generate PO no
  $po_no = 'PO-'.date('Ymd').'-'.str_pad((string)random_int(1,9999), 4, '0', STR_PAD_LEFT);

  // 4) Determine a supplier_id value that will not violate NOT NULL/FK
  $supplierId = null;
  if (has_col($pdo,'purchase_orders','supplier_id')) {
    $nullable = col_nullable($pdo,'purchase_orders','supplier_id');
    if ($nullable === false) {
      // NOT NULL — try to pick an existing supplier
      $s = $pdo->query("SELECT id FROM suppliers ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
      if ($s && isset($s['id'])) {
        $supplierId = (int)$s['id'];
      } else {
        // if you actually have a FK constraint, failing here is clearer
        bad('no_supplier_available_create_one_first');
      }
    } else {
      // nullable
      $supplierId = null;
    }
  }

  // 5) Build dynamic insert for purchase_orders
  $poTable = 'purchase_orders';
  $must = ['po_no','status','total'];
  foreach ($must as $m) if (!has_col($pdo,$poTable,$m)) bad("$poTable.$m missing");
  $candidates = [
    'po_no'        => $po_no,
    'supplier_id'  => $supplierId,
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

  // 6) Insert items — verify expected columns exist
  $itemsTable = 'purchase_order_items';
  foreach (['po_id','descr','qty','price','line_total'] as $c) {
    if (!has_col($pdo,$itemsTable,$c)) bad("$itemsTable.$c missing");
  }

  $insItem = $pdo->prepare("INSERT INTO {$itemsTable} (po_id, descr, qty, price, line_total) VALUES (?,?,?,?,?)");
  $total = 0.0;
  foreach ($rows as $r) {
    $qty = (float)$r['qty'];
    $price = (float)$r['price'];
    $lt = $qty * $price;
    $insItem->execute([$po_id, $r['descr'], $qty, $price, $lt]);
    $total += $lt;
  }

  // 7) Update total
  $pdo->prepare("UPDATE {$poTable} SET total=? WHERE id=?")->execute([$total,$po_id]);

  // 8) Mark PR fulfilled (if you prefer keeping it "approved", comment this out)
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
