<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

try {
  require_login(); require_role(['admin','procurement_officer']);
  if ($_SERVER['REQUEST_METHOD']!=='POST') throw new Exception('POST only');

  $pdo = db('proc'); if(!$pdo instanceof PDO) throw new Exception('DB error');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $rfq_id = (int)($_POST['rfq_id'] ?? 0);
  $rows   = json_decode($_POST['rows'] ?? '[]', true); // [{quote_id, score, rank, notes}]
  if ($rfq_id<=0) throw new Exception('Invalid RFQ');

  $pdo->beginTransaction();
  $st = $pdo->prepare("UPDATE quotes SET eval_score=?, eval_rank=?, eval_notes=? WHERE id=? AND rfq_id=?");
  foreach ($rows as $r) {
    $st->execute([
      isset($r['score']) ? (float)$r['score'] : null,
      isset($r['rank'])  ? (int)$r['rank']   : null,
      substr(trim($r['notes'] ?? ''),0,500),
      (int)$r['quote_id'],
      $rfq_id
    ]);
  }
  $pdo->commit();
  echo json_encode(['ok'=>1]);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  http_response_code(400);
  echo json_encode(['error'=>$e->getMessage()]);
}
