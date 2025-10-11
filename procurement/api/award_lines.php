<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

try {
  require_login(); require_role(['admin','procurement_officer']);
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('POST only');

  $pdo = db('proc'); if(!$pdo instanceof PDO) throw new Exception('DB error');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $rfq_id = (int)($_POST['rfq_id'] ?? 0);
  // awards: [{rfq_item_id, quote_id}]
  $awards = json_decode($_POST['awards'] ?? '[]', true);
  if ($rfq_id<=0 || !is_array($awards)) throw new Exception('Invalid input');

  // pull quote→vendor map
  $q = $pdo->prepare("SELECT id, vendor_id FROM quotes WHERE rfq_id=?");
  $q->execute([$rfq_id]);
  $qmap = [];
  foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $qmap[(int)$r['id']] = (int)$r['vendor_id'];

  $pdo->beginTransaction();
  $st = $pdo->prepare("UPDATE rfq_items SET awarded_quote_item_id=?, awarded_vendor_id=? WHERE id=? AND rfq_id=?");
  foreach ($awards as $a) {
    $qid = (int)($a['quote_id'] ?? 0);
    $iid = (int)($a['rfq_item_id'] ?? 0);
    if ($qid<=0 || $iid<=0 || !isset($qmap[$qid])) continue;
    $st->execute([$qid, $qmap[$qid], $iid, $rfq_id]);
  }
  $pdo->commit();
  echo json_encode(['ok'=>1]);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  http_response_code(400);
  echo json_encode(['error'=>$e->getMessage()]);
}
