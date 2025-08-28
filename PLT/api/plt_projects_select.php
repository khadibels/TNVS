<?php
declare(strict_types=1);
$inc = __DIR__ . '/../../includes';
if (file_exists($inc . '/config.php')) require_once $inc . '/config.php';
if (file_exists($inc . '/auth.php'))  require_once $inc . '/auth.php';
if (function_exists('require_login')) require_login();
header('Content-Type: application/json; charset=utf-8');

try {
  if (!isset($pdo)) throw new Exception('DB missing');

  $active = isset($_GET['active']) ? (int)$_GET['active'] : 0; // active=1 => exclude closed
  $q      = trim((string)($_GET['q'] ?? ''));

  $where = [];
  $args  = [];

  if ($active) {
    $where[] = "status <> 'closed'";
  }
  if ($q !== '') {
    $where[] = "(code LIKE ? OR name LIKE ?)";
    $args[] = "%$q%";
    $args[] = "%$q%";
  }

  $whereSql = $where ? 'WHERE '.implode(' AND ', $where) : '';
  $sql = "SELECT id, code, name, status FROM plt_projects $whereSql ORDER BY name ASC";
  $st  = $pdo->prepare($sql);
  $st->execute($args);

  echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['error'=>$e->getMessage()]);
}
