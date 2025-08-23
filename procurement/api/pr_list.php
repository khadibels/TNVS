<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/config.php';
if (file_exists(__DIR__ . '/../../includes/auth.php')) require_once __DIR__ . '/../../includes/auth.php';
if (function_exists('require_login')) require_login();
header('Content-Type: application/json; charset=utf-8');

try {
  // Read filters (not used yet)
  $page  = max(1, (int)($_GET['page'] ?? 1));
  $per   = max(1, min(100, (int)($_GET['per_page'] ?? 10)));
  $data = [
    [
      'id'=>1, 'pr_no'=>'PR-0001', 'title'=>'Monitors for Devs', 'requestor'=>'Jane D.',
      'department'=>'IT', 'needed_by'=>'2025-09-15', 'estimated_total'=>45000, 'status'=>'submitted'
    ],
    [
      'id'=>2, 'pr_no'=>'PR-0002', 'title'=>'Office Chairs', 'requestor'=>'Mark S.',
      'department'=>'Admin', 'needed_by'=>'2025-10-01', 'estimated_total'=>32000, 'status'=>'draft'
    ]
  ];
  echo json_encode([
    'data' => $data,
    'pagination' => ['page'=>$page, 'perPage'=>$per, 'total'=>count($data)]
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => 'server_error: '.$e->getMessage()]);
}
