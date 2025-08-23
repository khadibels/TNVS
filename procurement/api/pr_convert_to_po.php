<?php
// /procurement/api/pr_convert_to_po.php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/config.php';
if (file_exists(__DIR__ . '/../../includes/auth.php')) require_once __DIR__ . '/../../includes/auth.php';
if (function_exists('require_login')) require_login();
header('Content-Type: application/json; charset=utf-8');

/** Generate PO-YYYY-#### */
function gen_po_no(PDO $pdo): string {
  $y = date('Y');
  $st = $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE YEAR(created_at) = $y");
  $n  = (int)$st->fetchColumn() + 1;
  return sprintf("PO-%s-%04d", $y, $n);
}

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'POST required']); exit; }
  $pdo = $pdo ?? db();

  $prId = (int)($_POST['id'] ?? 0);
  if ($prId <= 0) { http_response_code(400); echo json_encode(['error'=>'Missing id']); exit; }

  // Read PR header
  $st = $pdo->prepare("SELECT * FROM procurement_requests WHERE id=? AND is_deleted=0");
  $st->execute([$prId]);
  $pr = $st->fetch(PDO::FETCH_ASSOC);
  if (!$pr) { http_response_code(404); echo json_encode(['error'=>'PR not found']); exit; }

  // Read PR items
  $st = $pdo->prepare("SELECT descr, qty, price FROM procurement_request_items WHERE pr_id=? ORDER BY id");
  $st->execute([$prId]);
  $items = $st->fetchAll(PDO::FETCH_ASSOC);
  if (!$items) { http_response_code(409); echo json_encode(['error'=>'PR has no items']); exit; }

  // Create PO
  $pdo->beginTransaction();

  $poNo = gen_po_no($pdo);
  $total = 0.0; foreach ($items as $it) { $total += (float)$it['qty'] * (float)$it['price']; }

  // Insert PO header (supplier unset for now; you’ll pick it in the PO modal)
  $insPO = $pdo->prepare("
    INSERT INTO purchase_orders
      (po_no, supplier_id, order_date, expected_date, status, notes, total, pr_id, created_at, updated_at)
    VALUES
      (?,      NULL,        CURDATE(), NULL,         'draft', ?,     ?,     ?,     NOW(),    NOW())
  ");
  $notes = "Converted from {$pr['pr_no']} — {$pr['title']}";
  $insPO->execute([$poNo, $notes, $total, $prId]);
  $poId = (int)$pdo->lastInsertId();

  // Insert PO items
  $insItem = $pdo->prepare("
    INSERT INTO purchase_order_items (po_id, descr, qty, price)
    VALUES (?, ?, ?, ?)
  ");
  foreach ($items as $it) {
    $insItem->execute([$poId, $it['descr'], (float)$it['qty'], (float)$it['price']]);
  }

  // Optional: bump PR status to approved if still submitted/draft
  if (in_array(strtolower($pr['status']), ['draft','submitted'], true)) {
    $pdo->prepare("UPDATE procurement_requests SET status='approved', updated_at=NOW() WHERE id=?")->execute([$prId]);
  }

  $pdo->commit();

  echo json_encode(['ok'=>true, 'po_id'=>$poId, 'po_no'=>$poNo, 'from_pr'=>$prId]);
} catch (Throwable $e) {
  if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['error'=>'server_error','detail'=>$e->getMessage()]);
}
