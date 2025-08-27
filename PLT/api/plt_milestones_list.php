<?php
declare(strict_types=1);
$inc = __DIR__ . '/../../includes';
if (file_exists($inc . '/config.php')) require_once $inc . '/config.php';
if (file_exists($inc . '/auth.php'))  require_once $inc . '/auth.php';
if (function_exists('require_login')) require_login();
header('Content-Type: application/json; charset=utf-8');

try {
  if (!isset($pdo)) throw new Exception('DB missing');
  $pid = (int)($_GET['project_id'] ?? 0);
  if (!$pid) { echo json_encode([]); exit; }

  $st = $pdo->prepare("SELECT id, project_id, title, due_date, status, owner
                         FROM plt_milestones
                        WHERE project_id = ?
                        ORDER BY COALESCE(due_date, '9999-12-31'), id");
  $st->execute([$pid]);
  echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['error'=>$e->getMessage()]);
}
