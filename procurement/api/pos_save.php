<?php
// ./api/pos_save.php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

function bad($m,$c=400){ http_response_code($c); echo json_encode(['error'=>$m]); exit; }
function gen_po_no(PDO $pdo): string {
  $prefix = 'PO-'.date('Ym').'-';
  $st = $pdo->prepare("SELECT po_no FROM purchase_orders WHERE po_no LIKE ? ORDER BY po_no DESC LIMIT 1");
  $st->execute([$prefix.'%']);
  $last = $st->fetchColumn();
  $n = 1;
  if ($last && preg_match('/-(\d{4})$/', $last, $m)) $n = (int)$m[1] + 1;
  return $prefix . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') bad('POST required');

  $id = (int)($_POST['id'] ?? 0);
  $supplier_id   = (int)($_POST['supplier_id'] ?? 0);
  $order_date    = $_POST['issue_date'] ?? null;     // from form, map to order_date
  $expected_date = $_POST['expected_date'] ?? null;
  $status        = $_POST['status'] ?? 'draft';
  $notes         = trim($_POST['notes'] ?? '');

  $descrs = $_POST['items']['descr'] ?? [];
  $qtys   = $_POST['items']['qty']   ?? [];
  $prices = $_POST['items']['price'] ?? [];

  if ($supplier_id <= 0) bad('supplier_id required');

  $allowedStatuses = ['draft','approved','ordered','partially_received','received','closed','cancelled'];
  if (!in_array($status, $allowedStatuses, true)) bad('invalid status');

  // sanitize items
  $items = [];
  $n = max(count($descrs), count($qtys), count($prices));
  for ($i=0;$i<$n;$i++){
    $d = trim($descrs[$i] ?? '');
    $q = (float)($qtys[$i] ?? 0);
    $p = (float)($prices[$i] ?? 0);
    if ($d === '' || $q<=0 || $p<0) continue;
    $items[] = ['descr'=>$d,'qty'=>$q,'price'=>$p];
  }
  if (!$items) bad('At least one valid item (descr, qty>0, price>=0) is required');

  $pdo->beginTransaction();

  if ($id > 0) {
    $st = $pdo->prepare("SELECT status FROM purchase_orders WHERE id=? FOR UPDATE");
    $st->execute([$id]);
    $cur = $st->fetch(PDO::FETCH_ASSOC);
    if (!$cur) { $pdo->rollBack(); bad('PO not found', 404); }
    if (in_array($cur['status'], ['received','closed','cancelled'], true)) {
      $pdo->rollBack(); bad('Cannot edit a '.$cur['status'].' PO', 409);
    }
  }

 if ($id === 0) {
  $po_no = gen_po_no($pdo);

  //insert header (new po)
$st = $pdo->prepare("
  INSERT INTO purchase_orders
    (po_no, supplier_id, order_date, expected_date, status, notes, total)
  VALUES
    (?,     ?,           ?,          ?,            ?,     ?,     0)
");
$st->execute([$po_no, $supplier_id, $order_date ?: null, $expected_date ?: null, $status, $notes]);

  $id = (int)$pdo->lastInsertId();
  } else {
    $st = $pdo->prepare("
      UPDATE purchase_orders
      SET supplier_id=?, order_date=?, expected_date=?, status=?, notes=?
      WHERE id=?
    ");
    $st->execute([$supplier_id, $order_date ?: null, $expected_date ?: null, $status, $notes, $id]);
    $pdo->prepare("DELETE FROM purchase_order_items WHERE po_id=?")->execute([$id]);
  }

  $ins = $pdo->prepare("INSERT INTO purchase_order_items (po_id, descr, qty, price) VALUES (?,?,?,?)");
  $total = 0.0;
  foreach ($items as $it) {
    $ins->execute([$id, $it['descr'], $it['qty'], $it['price']]);
    $total += $it['qty'] * $it['price'];
  }
  $pdo->prepare("UPDATE purchase_orders SET total=? WHERE id=?")->execute([round($total,2), $id]);

  $pdo->commit();
  echo json_encode(['ok'=>true, 'id'=>$id]);
} catch (Throwable $e) {
  if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
  bad('server_error: '.$e->getMessage(), 500);
}
