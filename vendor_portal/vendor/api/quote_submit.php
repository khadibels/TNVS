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
  $terms  = trim((string)($_POST['terms'] ?? ''));
  $lead   = isset($_POST['lead_time_days']) ? (int)$_POST['lead_time_days'] : null;

  // Items prices submitted as items[<item_id>] => unit_price
  $items = (isset($_POST['items']) && is_array($_POST['items'])) ? $_POST['items'] : [];

  if ($rfq_id <= 0) throw new Exception('Invalid RFQ');

  // Get RFQ meta and items to calculate total
  $st = $pdo->prepare("SELECT status, due_at, currency, awarded_vendor_id FROM rfqs WHERE id=?");
  $st->execute([$rfq_id]);
  $rfq = $st->fetch(PDO::FETCH_ASSOC);
  if (!$rfq) throw new Exception('RFQ not found');

  // Fetch RFQ items to get quantities for total calculation
  $iq = $pdo->prepare("SELECT id, qty FROM rfq_items WHERE rfq_id=?");
  $iq->execute([$rfq_id]);
  $rfq_item_data = $iq->fetchAll(PDO::FETCH_ASSOC);
  $qtyMap = [];
  foreach ($rfq_item_data as $rid) {
    $qtyMap[(int)$rid['id']] = (float)$rid['qty'];
  }

  // Calculate Total
  $calculatedTotal = 0;
  foreach ($items as $itemId => $price) {
    $itemId = (int)$itemId;
    $price  = (float)$price;
    if ($price > 0 && isset($qtyMap[$itemId])) {
      $calculatedTotal += ($price * $qtyMap[$itemId]);
    }
  }

  if ($calculatedTotal <= 0 && !empty($rfq_item_data)) {
    throw new Exception('Please provide pricing for at least one item.');
  }

  $status = strtolower((string)$rfq['status']);
  $dueAt  = $rfq['due_at'] ? strtotime($rfq['due_at']) : null;
  $now    = time();

  // Do not allow if already awarded to someone else
  if ($status === 'awarded' && (int)$rfq['awarded_vendor_id'] && (int)$rfq['awarded_vendor_id'] !== $vendorId) {
    throw new Exception('RFQ already awarded to another vendor');
  }
  if ($status !== 'sent') throw new Exception('RFQ not open for quotes');
  if ($dueAt && $now > $dueAt) throw new Exception('RFQ past due date');

  $pdo->beginTransaction();

  // Insert quote header
  $ins = $pdo->prepare("
    INSERT INTO quotes (rfq_id, vendor_id, total, currency, terms, lead_time_days, created_at)
    VALUES (?,?,?,?,?,?,NOW())
  ");
  $ins->execute([$rfq_id, $vendorId, $calculatedTotal, $rfq['currency'], $terms, $lead]);
  $quoteId = (int)$pdo->lastInsertId();

  // Insert quote items
  if (!empty($items)) {
    $qi = $pdo->prepare("
      INSERT INTO quote_items (quote_id, rfq_item_id, unit_price)
      VALUES (?,?,?)
    ");

    foreach ($items as $itemId => $price) {
      $itemId = (int)$itemId;
      $price  = (float)$price;
      if ($price > 0 && isset($qtyMap[$itemId])) {
        $qi->execute([$quoteId, $itemId, $price]);
      }
    }
  }

  $pdo->commit();
  echo json_encode(['ok' => 1, 'id' => $quoteId, 'total' => $calculatedTotal]);
} catch (Throwable $e) {
  if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
  http_response_code(400);
  echo json_encode(['error' => $e->getMessage()]);
}
