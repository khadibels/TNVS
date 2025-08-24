<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

function bad($m,$c=400){ http_response_code($c); echo json_encode(['error'=>$m]); exit; }

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) bad('missing_id');

try {
  $pdo->beginTransaction();

  $st = $pdo->prepare("SELECT status FROM procurement_requests WHERE id=?");
  $st->execute([$id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) { $pdo->rollBack(); bad('not_found',404); }
  if (strtolower($row['status']) !== 'draft') { $pdo->rollBack(); bad('only_draft_can_be_deleted'); }

  $pdo->prepare("DELETE FROM procurement_request_items WHERE pr_id=?")->execute([$id]);
  $pdo->prepare("DELETE FROM procurement_requests WHERE id=?")->execute([$id]);

  $pdo->commit();
  echo json_encode(['ok'=>1]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  bad('server_error: '.$e->getMessage(), 500);
}
