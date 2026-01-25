<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/vendor_notifications.php';

header('Content-Type: application/json; charset=utf-8');

try {
  require_login(); require_role(['admin','procurement_officer']);
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('POST only');

  $pdo = db('proc'); if(!$pdo instanceof PDO) throw new Exception('DB error');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $rfq_id  = (int)($_POST['rfq_id'] ?? 0);
  $quote_id= (int)($_POST['quote_id'] ?? 0);
  $note    = substr(trim($_POST['note'] ?? ''),0,500);
  if ($rfq_id<=0 || $quote_id<=0) throw new Exception('Missing ids');

  // get quote info (and vendor)
  $q = $pdo->prepare("SELECT q.id, q.vendor_id, COALESCE(q.total,q.price) AS total
                      FROM quotes q WHERE q.id=? AND q.rfq_id=?");
  $q->execute([$quote_id,$rfq_id]);
  $quote = $q->fetch(PDO::FETCH_ASSOC);
  if (!$quote) throw new Exception('Quote not found');

  $pdo->beginTransaction();

  // mark winner / losers flags
  $pdo->prepare("UPDATE quotes SET is_awarded=CASE WHEN id=? THEN 1 ELSE 0 END WHERE rfq_id=?")
      ->execute([$quote_id,$rfq_id]);

  $pdo->prepare("UPDATE rfqs SET awarded_vendor_id=?, awarded_quote_id=?, awarded_at=NOW(), award_notes=? WHERE id=?")
      ->execute([(int)$quote['vendor_id'], $quote_id, $note, $rfq_id]);

  try {
    $pdo->prepare("UPDATE rfq_suppliers SET status='awarded' WHERE rfq_id=? AND vendor_id=?")
        ->execute([$rfq_id,(int)$quote['vendor_id']]);
    $pdo->prepare("UPDATE rfq_suppliers SET status='lost' WHERE rfq_id=? AND vendor_id<>?")
        ->execute([$rfq_id,(int)$quote['vendor_id']]);
  } catch (Throwable $e) {  }

  // notify winner + non-selected vendors
  try {
    $pdo->prepare("INSERT INTO vendor_notifications (vendor_id, rfq_id, title, body, is_read, created_at)
                   VALUES (?,?,?,?,0,NOW())")
        ->execute([
           (int)$quote['vendor_id'],
           $rfq_id,
           'Quotation Approved',
           'Congratulations! Your quotation was accepted.'
        ]);

    $losers = $pdo->prepare("SELECT vendor_id FROM rfq_suppliers WHERE rfq_id=? AND vendor_id<>?");
    $losers->execute([$rfq_id, (int)$quote['vendor_id']]);
    $insLose = $pdo->prepare("INSERT INTO vendor_notifications (vendor_id, rfq_id, title, body, is_read, created_at)
                              VALUES (?,?,?,?,0,NOW())");
    foreach ($losers->fetchAll(PDO::FETCH_COLUMN) as $vid) {
      $insLose->execute([
        (int)$vid,
        $rfq_id,
        'Quotation Not Approved',
        'Thank you for your submission. Your quotation was not selected.'
      ]);
    }
  } catch(Throwable $e){/* ignore */}

  // Email notifications (winner + non-selected)
  try {
    $rfqInfo = $pdo->prepare("SELECT rfq_no, title FROM rfqs WHERE id=?");
    $rfqInfo->execute([$rfq_id]);
    $rfqRow = $rfqInfo->fetch(PDO::FETCH_ASSOC) ?: ['rfq_no'=>'','title'=>''];

    $vendorRows = $pdo->prepare("
      SELECT v.id, v.company_name, v.contact_person, v.email
      FROM rfq_suppliers rs
      JOIN vendors v ON v.id = rs.vendor_id
      WHERE rs.rfq_id=?
    ");
    $vendorRows->execute([$rfq_id]);
    foreach ($vendorRows->fetchAll(PDO::FETCH_ASSOC) as $v) {
      if (empty($v['email'])) continue;
      $result = ((int)$v['id'] === (int)$quote['vendor_id']) ? 'approved' : 'not_approved';
      sendVendorQuotationResultEmail($v, $rfqRow, $result);
    }
  } catch (Throwable $e) { }
  $pdo->commit();
  echo json_encode(['ok'=>1]);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  http_response_code(400);
  echo json_encode(['error'=>$e->getMessage()]);
}
