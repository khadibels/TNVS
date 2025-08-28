<?php
declare(strict_types=1);
$inc = __DIR__ . '/../../includes';
if (file_exists($inc . '/config.php')) require_once $inc . '/config.php';
if (file_exists($inc . '/auth.php'))  require_once $inc . '/auth.php';
if (function_exists('require_login')) require_login();
header('Content-Type: application/json; charset=utf-8');

try {
  if (!isset($pdo)) throw new Exception('DB missing');

  $id     = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
  $status = strtolower(trim((string)($_POST['status'] ?? $_GET['status'] ?? '')));

  if ($id <= 0) throw new Exception('id required');
  $allowed = ['planned','ongoing','completed','delayed','closed']; // UI uses ongoing/delayed only
  if (!in_array($status, $allowed, true)) throw new Exception('Invalid status');

  // Prevent bypassing close rules via this endpoint (safety)
  if ($status === 'closed') throw new Exception('Use the Close Project action');

  $pdo->prepare("UPDATE plt_projects SET status=? WHERE id=?")->execute([$status, $id]);

  echo json_encode(['ok'=>1]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['error'=>$e->getMessage()]);
}
