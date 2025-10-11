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
  if ($id <= 0) throw new Exception('Invalid RFQ id');

  $chk = $pdo->prepare("SELECT 1 FROM rfq_suppliers WHERE rfq_id=? AND vendor_id=?");
  $chk->execute([$id, $vendorId]);
  if (!$chk->fetchColumn()) throw new Exception('Not authorized for this RFQ');

  $r = $pdo->prepare("SELECT id, rfq_no, title, description, currency, status, due_at, awarded_vendor_id 
                      FROM rfqs WHERE id=?");
  $r->execute([$id]);
  $rfq = $r->fetch(PDO::FETCH_ASSOC);
  if (!$rfq) throw new Exception('RFQ not found');

  $st = strtolower((string)$rfq['status']);
  $aw = (int)($rfq['awarded_vendor_id'] ?? 0);
  if ($st === 'awarded' && $aw && $aw !== $vendorId) {
    $rfq['status'] = 'closed';
  }

  $it = $pdo->prepare("SELECT id, line_no, item, specs, qty, uom FROM rfq_items WHERE rfq_id=? ORDER BY line_no ASC, id ASC");
  $it->execute([$id]); 
  $items = $it->fetchAll(PDO::FETCH_ASSOC);

  $qs = $pdo->prepare("SELECT id, rfq_id, vendor_id, total, currency, terms, lead_time_days, created_at
                       FROM quotes WHERE rfq_id=? AND vendor_id=? ORDER BY created_at DESC");
  $qs->execute([$id, $vendorId]); 
  $quotes = $qs->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode(['rfq'=>$rfq,'items'=>$items,'my_quotes'=>$quotes]);
} catch (Throwable $e) {
  http_response_code(400); echo json_encode(['error'=>$e->getMessage()]);
}
