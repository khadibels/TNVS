<?php
// File: vendor_portal/vendor/api/quote_submit.php
require_once __DIR__ . "/../../../includes/config.php";
require_once __DIR__ . "/../../../includes/db.php";
require_once __DIR__ . "/../../../includes/auth.php";

require_login();
$u = current_user();
$vendorId = (int)($u['vendor_id'] ?? 0);
if ($vendorId <= 0) {
  http_response_code(403);
  echo json_encode(['error' => 'No vendor id']);
  exit;
}

header('Content-Type: application/json; charset=utf-8');

$pdo = db('proc');
if (!$pdo instanceof PDO) {
  http_response_code(500);
  echo json_encode(['error' => 'DB error']);
  exit;
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
  $rfq_id = (int)($_POST['rfq_id'] ?? 0);
  $total  = (float)($_POST['total']   ?? 0);
  $terms  = trim((string)($_POST['terms'] ?? ''));
  $lead   = isset($_POST['lead_time_days']) ? (int)$_POST['lead_time_days'] : null;

  // Optional per-line prices submitted as price[<line_no>] => unit_price
  $prices = (isset($_POST['price']) && is_array($_POST['price'])) ? $_POST['price'] : [];

  if ($rfq_id <= 0) throw new Exception('Invalid RFQ');
  if ($total  <= 0) throw new Exception('Total required');

  // Ensure vendor is invited to this RFQ
  $chk = $pdo->prepare("SELECT 1 FROM rfq_suppliers WHERE rfq_id=? AND vendor_id=?");
  $chk->execute([$rfq_id, $vendorId]);
  if (!$chk->fetchColumn()) throw new Exception('Not authorized for this RFQ');

  // Get RFQ meta
  $st = $pdo->prepare("SELECT status, due_at, currency, awarded_vendor_id FROM rfqs WHERE id=?");
  $st->execute([$rfq_id]);
  $rfq = $st->fetch(PDO::FETCH_ASSOC);
  if (!$rfq) throw new Exception('RFQ not found');

  $status = strtolower((string)$rfq['status']);
  $dueAt  = $rfq['due_at'] ? strtotime($rfq['due_at']) : null;
  $now    = time();

  // Do not allow if already awarded to someone else
  if ($status === 'awarded' && (int)$rfq['awarded_vendor_id'] && (int)$rfq['awarded_vendor_id'] !== $vendorId) {
    throw new Exception('RFQ already awarded to another vendor');
  }
  // Only allow quoting while globally "sent" (buyer published) and before due date
  if ($status !== 'sent') {
    throw new Exception('RFQ not open for quotes');
  }
  if ($dueAt && $now > $dueAt) {
    throw new Exception('RFQ past due date');
  }

  $pdo->beginTransaction();

  // Insert quote header
  $ins = $pdo->prepare("
    INSERT INTO quotes (rfq_id, vendor_id, total, currency, terms, lead_time_days, created_at)
    VALUES (?,?,?,?,?,?,NOW())
  ");
  $ins->execute([$rfq_id, $vendorId, $total, $rfq['currency'], $terms, $lead]);
  $quoteId = (int)$pdo->lastInsertId();

  // Per-line prices (map line_no -> rfq_item_id, then insert without line_no column)
  if (!empty($prices)) {
    // Build map: line_no => rfq_items.id
    $it = $pdo->prepare("SELECT id, line_no FROM rfq_items WHERE rfq_id=?");
    $it->execute([$rfq_id]);
    $map = [];
    foreach ($it->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $map[(int)$row['line_no']] = (int)$row['id'];
    }

    $qi = $pdo->prepare("
      INSERT INTO quote_items (quote_id, rfq_item_id, unit_price)
      VALUES (?,?,?)
    ");

    foreach ($prices as $lineNo => $price) {
      $lineNo = (int)$lineNo;
      $price  = (float)$price;
      if ($lineNo > 0 && $price > 0 && isset($map[$lineNo])) {
        $qi->execute([$quoteId, $map[$lineNo], $price]);
      }
    }
  }

  $pdo->commit();
  echo json_encode(['ok' => 1, 'id' => $quoteId]);
} catch (Throwable $e) {
  if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
  http_response_code(400);
  echo json_encode(['error' => $e->getMessage()]);
}
