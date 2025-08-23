<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/config.php';
if (file_exists(__DIR__ . '/../../includes/auth.php')) require_once __DIR__ . '/../../includes/auth.php';
if (function_exists('require_login')) require_login();
header('Content-Type: application/json; charset=utf-8');

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'POST required']); exit; }

  // Here you’d read $_POST and insert/update DB
  // For now, pretend success and echo a fake ID:
  echo json_encode(['ok'=>true, 'id'=> rand(100,999), 'message'=>'Saved']);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>'server_error: '.$e->getMessage()]);
}
