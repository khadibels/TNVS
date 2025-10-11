<?php
require_once __DIR__ . "/../../includes/config.php";
require_once __DIR__ . "/../../includes/db.php";
require_once __DIR__ . "/../../includes/auth.php";

require_login();
$u = current_user();
$vendorId = (int)($u['vendor_id'] ?? 0);
if ($vendorId <= 0) { http_response_code(403); echo json_encode(['error'=>'No vendor id']); exit; }

header('Content-Type: application/json; charset=utf-8');
$pdo = db('proc');
if (!$pdo instanceof PDO) { http_response_code(500); echo json_encode(['error'=>'DB error']); exit; }
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('POST required');

  $rfq_id = (int)($_POST['rfq_id'] ?? 0);
  $total  = (float)($_POST['total'] ?? 0);
  $lead   = (int)($_POST['lead_time_days'] ?? 0);
  $terms  = trim($_POST['terms'] ?? '');
  $prices = $_POST['price'] ?? []; // price[line_no] => number

  if ($rfq_id <= 0) throw new Exception('Invalid RFQ');
  if ($total <= 0)  throw new Exception('Total is required');

  // RFQ + vendor access
  $chk = $pdo->prepare("SELECT r.currency FROM rfqs r JOIN rfq_suppliers rs ON rs.rfq_id=r.id AND rs.vendor_id=? WHERE r.id=?");
  $chk->execute([$vendorId,$rfq_id]);
  $currency = $chk->fetchColumn();
  if (!$currency) throw new Exception('Not invited to this RFQ');

  $items = $pdo->prepare("SELECT line_no FROM rfq_items WHERE rfq_id=?");
  $items->execute([$rfq_id]);
  $validLines = array_map('intval', array_column($items->fetchAll(PDO::FETCH_ASSOC),'line_no'));

  $pdo->beginTransaction();
  $ins = $pdo->prepare("INSERT INTO quotes (rfq_id,vendor_id,total,currency,terms,lead_time_days,created_at)
                        VALUES (?,?,?,?,?,?,NOW())");
  $ins->execute([$rfq_id,$vendorId,$total,$currency,$terms,$lead]);
  $qid = (int)$pdo->lastInsertId();

  if (!empty($prices) && is_array($prices)) {
    $qi = $pdo->prepare("INSERT INTO quote_items (quote_id,line_no,price) VALUES (?,?,?)");
    foreach ($prices as $ln=>$p) {
      $ln=(int)$ln; $p=(float)$p;
      if ($ln>0 && in_array($ln,$validLines,true) && $p>=0) $qi->execute([$qid,$ln,$p]);
    }
  }

  // mark responded
  $pdo->prepare("UPDATE rfq_suppliers SET status='responded', responded_at=NOW() WHERE rfq_id=? AND vendor_id=?")
      ->execute([$rfq_id,$vendorId]);

  $pdo->commit();
  echo json_encode(['ok'=>true,'quote_id'=>$qid]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(400); echo json_encode(['error'=>$e->getMessage()]);
}
