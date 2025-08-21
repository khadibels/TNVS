<?php
declare(strict_types=1);
$inc = __DIR__ . '/../../includes';
require_once $inc . '/config.php';
require_once $inc . '/auth.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

try {
  if ($_SERVER['REQUEST_METHOD']!=='POST') throw new Exception('POST required');
  $rfq_id = (int)($_POST['rfq_id'] ?? 0);
  if ($rfq_id<=0) throw new Exception('rfq_id required');

  // flip status to sent (if draft)
  $pdo->prepare("UPDATE rfqs SET status = CASE WHEN status='draft' THEN 'sent' ELSE status END WHERE id=?")
      ->execute([$rfq_id]);

  // create / update recipients rows already exist via save, so just stamp
  $upd = $pdo->prepare("UPDATE rfq_recipients
    SET sent_at = NOW(),
        invite_token = SHA2(UUID(),256),
        token_expires_at = NOW() + INTERVAL 7 DAY
    WHERE rfq_id = ?");
  $upd->execute([$rfq_id]);

  echo json_encode(['sent'=>$upd->rowCount()]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['error'=>$e->getMessage()]);
}
