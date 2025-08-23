<?php
// /procurement/api/pr_get.php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/config.php';
if (file_exists(__DIR__ . '/../../includes/auth.php')) require_once __DIR__ . '/../../includes/auth.php';
if (function_exists('require_login')) require_login();
header('Content-Type: application/json; charset=utf-8');

try {
  $id = (int)($_GET['id'] ?? 0);
  if ($id <= 0) { http_response_code(400); echo json_encode(['error'=>'Missing id']); exit; }

  // Dummy payload
  $header = [
    'title'=>'Monitors for Devs','needed_by'=>'2025-09-15','priority'=>'high',
    'requestor'=>'Jane D.','department_id'=>1,'status'=>'submitted','notes'=>'24" or larger'
  ];
  $items = [
    ['descr'=>'27" IPS Monitor', 'qty'=>3, 'price'=>15000],
    ['descr'=>'HDMI Cable', 'qty'=>3, 'price'=>300]
  ];

  echo json_encode(['header'=>$header,'items'=>$items]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>'server_error: '.$e->getMessage()]);
}
