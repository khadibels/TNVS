<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

try {
  require_login(); require_role(['admin','procurement_officer']);
  $rfq_id = (int)($_GET['rfq_id'] ?? 0);
  if ($rfq_id <= 0) throw new Exception('Invalid RFQ');

  $pdo = db('proc'); if(!$pdo instanceof PDO) throw new Exception('DB error');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // RFQ + items
  $rfq = $pdo->prepare("SELECT id, rfq_no, title, currency, due_at, status, awarded_vendor_id, awarded_quote_id, awarded_at
                        FROM rfqs WHERE id=?");
  $rfq->execute([$rfq_id]);
  $rfq = $rfq->fetch(PDO::FETCH_ASSOC);
  if (!$rfq) throw new Exception('RFQ not found');

  $items = $pdo->prepare("SELECT id, line_no, item, specs, qty, uom FROM rfq_items WHERE rfq_id=? ORDER BY line_no ASC, id ASC");
  $items->execute([$rfq_id]);
  $items = $items->fetchAll(PDO::FETCH_ASSOC);

  // Vendor list invited to this RFQ
  $vendors = $pdo->prepare("
    SELECT v.id, v.company_name, v.contact_email, rs.status
    FROM rfq_suppliers rs
    JOIN vendors v ON v.id = rs.vendor_id
    WHERE rs.rfq_id = ?
    ORDER BY v.company_name ASC
  ");
  $vendors->execute([$rfq_id]);
  $vendors = $vendors->fetchAll(PDO::FETCH_ASSOC);

  // Quotes (normalize total/price → total_out)
  $hasTotal = (bool)$pdo->query("
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='quotes' AND COLUMN_NAME='total'
  ")->fetchColumn();

  $col = $hasTotal ? 'q.total' : 'q.price';

  $quotes = $pdo->prepare("
    SELECT q.id, q.vendor_id, $col AS total_out, q.currency, q.lead_time_days,
           q.terms, q.eval_score, q.eval_rank, q.eval_notes, q.is_awarded,
           q.created_at
    FROM quotes q
    WHERE q.rfq_id = ?
    ORDER BY total_out ASC, q.created_at ASC
  ");
  $quotes->execute([$rfq_id]);
  $quotes = $quotes->fetchAll(PDO::FETCH_ASSOC);

  $qi = $pdo->prepare("SELECT quote_id, rfq_item_id, unit_price FROM quote_items WHERE quote_id IN (
                         SELECT id FROM quotes WHERE rfq_id=?
                       )");
  $useModern = true;
  try {
    $qi->execute([$rfq_id]);
    $quoteItems = $qi->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    $useModern = false;
    $qi = $pdo->prepare("SELECT quote_id, line_no, price FROM quote_items WHERE quote_id IN (
                           SELECT id FROM quotes WHERE rfq_id=?
                         )");
    $qi->execute([$rfq_id]);
    $quoteItems = $qi->fetchAll(PDO::FETCH_ASSOC);
  }

  $unitMap = [];
  foreach ($quoteItems as $r) {
    $qid = (int)$r['quote_id'];
    $lineKey = $useModern ? ('id:' . (int)$r['rfq_item_id']) : ('ln:' . (int)$r['line_no']);
    $unit = (float)($useModern ? $r['unit_price'] : $r['price']);
    $unitMap[$qid][$lineKey] = $unit;
  }

  // quick scoring (cheapest = 100, others scaled)
  $totals = array_map(fn($q)=> (float)$q['total_out'], $quotes);
  $min = count($totals) ? min($totals) : 0;
  foreach ($quotes as &$q) {
    $q['suggested_score'] = $min>0 ? round(($min / (float)$q['total_out']) * 100, 2) : 0.00;
  } unset($q);

  echo json_encode([
    'rfq'        => $rfq,
    'items'      => $items,
    'vendors'    => $vendors,
    'quotes'     => $quotes,
    'unit_prices'=> $unitMap,
    'use_modern' => $useModern ? 1 : 0
  ]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['error'=>$e->getMessage()]);
}
