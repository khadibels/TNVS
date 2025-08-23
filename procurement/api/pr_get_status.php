<?php
// /procurement/api/pr_set_status.php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/config.php';
if (file_exists(__DIR__ . '/../../includes/auth.php')) require_once __DIR__ . '/../../includes/auth.php';
if (function_exists('require_login')) require_login();
header('Content-Type: application/json; charset=utf-8');

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'POST required']); exit; }
  $id = (int)($_POST['id'] ?? 0);
  $status = strtolower(trim($_POST['status'] ?? ''));
  if ($id<=0 || $status==='') { http_response_code(400); echo json_encode(['error'=>'id and status required']); exit; }

  // Here you’d validate and update status in DB
  echo json_encode(['ok'=>true, 'id'=>$id, 'status'=>$status]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>'server_error: '.$e->getMessage()]);
}
