<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

try {
  require_login();
  require_role(['admin','procurement_officer']);

  if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('POST required');

  $pdo = db('proc'); if (!$pdo instanceof PDO) throw new Exception('DB error');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $rfq_id   = (int)($_POST['rfq_id'] ?? 0);
  $vendorId = (int)($_POST['vendor_id'] ?? 0);
  $mode     = trim((string)($_POST['mode'] ?? 'overall'));
  $lineIds  = isset($_POST['lines']) && is_array($_POST['lines']) ? array_map('intval', $_POST['lines']) : [];

  if ($rfq_id <= 0)   throw new Exception('Invalid rfq_id');
  if ($vendorId <= 0) throw new Exception('Invalid vendor_id');

  // RFQ + invited vendor check
  $rfq = $pdo->prepare("SELECT id, rfq_no, title, currency, status FROM rfqs WHERE id=?");
  $rfq->execute([$rfq_id]);
  $rfq = $rfq->fetch(PDO::FETCH_ASSOC);
  if (!$rfq) throw new Exception('RFQ not found');

  $inv = $pdo->prepare("SELECT COUNT(*) FROM rfq_suppliers WHERE rfq_id=? AND vendor_id=?");
  $inv->execute([$rfq_id,$vendorId]);
  if (!$inv->fetchColumn()) throw new Exception('Vendor not invited to this RFQ');

  $hasTotal = (bool)$pdo->query("
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='quotes' AND COLUMN_NAME='total'
  ")->fetchColumn();
  $amountCol = $hasTotal ? 'total' : 'price';

  $q = $pdo->prepare("
    SELECT q.id, q.vendor_id, q.$amountCol AS total, q.currency, q.terms
    FROM quotes q
    WHERE q.rfq_id=? AND q.vendor_id=?
    ORDER BY q.$amountCol ASC, q.created_at ASC
    LIMIT 1
  ");
  $q->execute([$rfq_id,$vendorId]);
  $quote = $q->fetch(PDO::FETCH_ASSOC);
  if (!$quote) throw new Exception('No quote from selected vendor');

  $it = $pdo->prepare("SELECT id, line_no, item, specs, qty, uom FROM rfq_items WHERE rfq_id=? ORDER BY line_no ASC, id ASC");
  $it->execute([$rfq_id]);
  $rfqItems = $it->fetchAll(PDO::FETCH_ASSOC);
  if (!$rfqItems) throw new Exception('RFQ has no items');

  $byId  = []; $byLn = [];
  foreach ($rfqItems as $r){ $byId[(int)$r['id']]=$r; $byLn[(int)$r['line_no']]=$r; }

  $unit = []; // key by rfq_item_id
  $qi = $pdo->prepare("SELECT quote_id, rfq_item_id, unit_price FROM quote_items WHERE quote_id=?");
  $useModern = true;
  try {
    $qi->execute([(int)$quote['id']]);
    foreach ($qi->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $unit[(int)$r['rfq_item_id']] = (float)$r['unit_price'];
    }
  } catch (Throwable $e) {
    $useModern = false;
    $qi = $pdo->prepare("SELECT line_no, price FROM quote_items WHERE quote_id=?");
    $qi->execute([(int)$quote['id']]);
    foreach ($qi->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $ln = (int)$r['line_no'];
      if (isset($byLn[$ln])) $unit[(int)$byLn[$ln]['id']] = (float)$r['price'];
    }
  }

  $awardedItemIds = [];
  if ($mode === 'lines') {
    if (!$lineIds) throw new Exception('No lines selected');
    foreach ($lineIds as $id) if (isset($byId[$id])) $awardedItemIds[] = $id;
    if (!$awardedItemIds) throw new Exception('Selected lines not found');
  } else {
    foreach ($rfqItems as $r) {
      $rid = (int)$r['id'];
      if (array_key_exists($rid, $unit)) $awardedItemIds[] = $rid;
    }
    if (!$awardedItemIds) throw new Exception('Winning quote has no unit prices');
  }

  // Helper: next PO number
  $nextPoNo = function(PDO $pdo): string {
    $prefix = 'PO-'.date('Ymd').'-';
    $st = $pdo->prepare("SELECT po_no FROM pos WHERE po_no LIKE ? ORDER BY id DESC LIMIT 1");
    $st->execute([$prefix.'%']);
    $last = $st->fetchColumn();
    $n = 1;
    if ($last && preg_match('/-(\d{4})$/', $last, $m)) $n = (int)$m[1]+1;
    return $prefix . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
  };

  $me   = current_user();
  $uid  = (int)($me['id'] ?? 0);

  $pdo->beginTransaction();

  // Create PO header (draft)
  $po_no = $nextPoNo($pdo);
  $insPO = $pdo->prepare("
    INSERT INTO pos (rfq_id, quote_id, vendor_id, po_no, status, currency, total, terms, notes, created_by, created_at, updated_at)
    VALUES (?,?,?,?, 'draft', ?, 0, ?, NULL, ?, NOW(), NOW())
  ");
  $insPO->execute([
    $rfq_id, (int)$quote['id'], $vendorId, $po_no,
    $quote['currency'] ?: $rfq['currency'],
    $quote['terms'] ?? '',
    $uid ?: null
  ]);
  $poId = (int)$pdo->lastInsertId();

  // Insert PO items
  $tot = 0.0;
  $insItem = $pdo->prepare("
    INSERT INTO po_items (po_id, rfq_item_id, line_no, item, specs, qty, uom, unit_price, line_total)
    VALUES (?,?,?,?,?,?,?,?,?)
  ");
  foreach ($awardedItemIds as $rid) {
    $src = $byId[$rid];
    $unitPrice = (float)($unit[$rid] ?? 0.0);
    $lineTotal = $unitPrice * (float)$src['qty'];
    $tot += $lineTotal;

    $insItem->execute([
      $poId, $rid, (int)$src['line_no'], (string)$src['item'], (string)($src['specs'] ?? ''),
      (float)$src['qty'], (string)($src['uom'] ?? ''), $unitPrice, $lineTotal
    ]);
  }

  // Update PO total
  $pdo->prepare("UPDATE pos SET total=?, updated_at=NOW() WHERE id=?")->execute([$tot, $poId]);

  // Mark quote & rfq as awarded
  $pdo->prepare("UPDATE quotes SET is_awarded=1 WHERE id=?")->execute([(int)$quote['id']]);
  $pdo->prepare("
    UPDATE rfqs
       SET status='awarded',
           awarded_vendor_id=?,
           awarded_quote_id=?,
           awarded_at=NOW()
     WHERE id=?
  ")->execute([$vendorId, (int)$quote['id'], $rfq_id]);

  $pdo->commit();

  echo json_encode([
    'ok'      => true,
    'message' => 'Award saved. PO created in draft.',
    'po_id'   => $poId,
    'po_no'   => $po_no
  ]);
} catch (Throwable $e) {
  if (!empty($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  http_response_code(400);
  echo json_encode(['error' => $e->getMessage()]);
}
