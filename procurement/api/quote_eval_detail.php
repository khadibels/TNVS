<?php
// File: procurement/api/quote_eval_detail.php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

try {
  require_login();
  require_role(['admin','procurement_officer']);

  $pdo = db('proc');
  if (!$pdo instanceof PDO) throw new Exception('DB error');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $rfq_id = (int)($_GET['rfq_id'] ?? 0);
  if ($rfq_id <= 0) throw new Exception('Invalid rfq_id');

  // RFQ (include award fields)
  $st = $pdo->prepare("
    SELECT id, rfq_no, title, description, currency, status, due_at,
           awarded_vendor_id, awarded_at
    FROM rfqs WHERE id=?
  ");
  $st->execute([$rfq_id]);
  $rfq = $st->fetch(PDO::FETCH_ASSOC);
  if (!$rfq) throw new Exception('RFQ not found');

  // Items
  $it = $pdo->prepare("SELECT id, line_no, item, specs, qty, uom FROM rfq_items WHERE rfq_id=? ORDER BY line_no ASC, id ASC");
  $it->execute([$rfq_id]);
  $items = $it->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // Which amount column exists in quotes?
  $chk = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='quotes' AND COLUMN_NAME=?");
  $chk->execute(['total']); $hasTotal = (bool)$chk->fetchColumn();
  if (!$hasTotal) { $chk->execute(['price']); $hasPrice = (bool)$chk->fetchColumn(); }
  $amountCol = $hasTotal ? 'total' : ((isset($hasPrice) && $hasPrice) ? 'price' : null);
  if (!$amountCol) throw new Exception("Quotes table missing 'total' or 'price' column");

  // Quotes list
  $qs = $pdo->prepare("
    SELECT q.id, q.vendor_id, q.$amountCol AS total, q.currency, q.terms, q.created_at,
           v.company_name AS supplier_name
    FROM quotes q
    JOIN vendors v ON v.id = q.vendor_id
    WHERE q.rfq_id=?
    ORDER BY q.$amountCol ASC, q.created_at ASC
  ");
  $qs->execute([$rfq_id]);
  $quotes = $qs->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // Per-line matrix
  $matrix = [];
  if ($quotes) {
    $quoteIds = array_map('intval', array_column($quotes,'id'));
    $in = implode(',', $quoteIds);
    if ($in) {
      // Try modern schema (rfq_item_id + unit_price)
      try {
        $qi = $pdo->query("SELECT quote_id, rfq_item_id, unit_price FROM quote_items WHERE quote_id IN ($in)");
        $rows = $qi->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
          // map rfq_item.id -> line_no
          $map = []; foreach ($items as $r) $map[(int)$r['id']] = (int)$r['line_no'];
          foreach ($rows as $r) {
            $qid = (int)$r['quote_id'];
            $ln  = $map[(int)$r['rfq_item_id']] ?? null;
            if ($ln !== null) $matrix[$qid][$ln] = (float)$r['unit_price'];
          }
        }
      } catch (Throwable $e) {
        // Legacy schema (line_no + price)
        $qi = $pdo->query("SELECT quote_id, line_no, price FROM quote_items WHERE quote_id IN ($in)");
        foreach ($qi->fetchAll(PDO::FETCH_ASSOC) as $r) {
          $matrix[(int)$r['quote_id']][(int)$r['line_no']] = (float)$r['price'];
        }
      }
    }
  }

  echo json_encode(['rfq'=>$rfq,'items'=>$items,'quotes'=>$quotes,'matrix'=>$matrix]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['error' => $e->getMessage()]);
}
