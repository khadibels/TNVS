<?php
declare(strict_types=1);

$inc = __DIR__ . '/../../includes';
if (file_exists($inc . '/config.php')) require_once $inc . '/config.php';
if (file_exists($inc . '/auth.php'))  require_once $inc . '/auth.php';
if (function_exists('require_login')) require_login();

header('Content-Type: application/json; charset=utf-8');

try {
  if (!isset($pdo)) throw new Exception('DB missing');

  // Accept POST (preferred) or GET (handy during dev)
  $id  = (int)($_POST['id']    ?? $_GET['id']    ?? 0);
  $to  = strtolower(trim((string)($_POST['status'] ?? $_GET['status'] ?? '')));

  if ($id <= 0)               throw new Exception('id required');
  if ($to === '')             throw new Exception('status required');

  $allowed = ['planned','picked','in_transit','delivered','cancelled'];
  if (!in_array($to, $allowed, true)) throw new Exception('Invalid status');

  // Current status
  $st = $pdo->prepare('SELECT status FROM plt_shipments WHERE id=?');
  $st->execute([$id]);
  $cur = $st->fetchColumn();
  if ($cur === false) throw new Exception('Shipment not found');

  // Allowed transitions (server-side safety)
  $ok = false;
  switch ($cur) {
    case 'planned':     $ok = in_array($to, ['picked','in_transit','cancelled'], true); break;
    case 'picked':      $ok = in_array($to, ['in_transit','cancelled'], true); break;
    case 'in_transit':  $ok = in_array($to, ['delivered','cancelled'], true); break;
    default:            $ok = false; // delivered/cancelled are terminal
  }
  if (!$ok) throw new Exception("Illegal transition: $cur → $to");

  $pdo->prepare('UPDATE plt_shipments SET status=? WHERE id=?')->execute([$to, $id]);

  echo json_encode(['ok' => 1]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['error' => $e->getMessage()]);
}
