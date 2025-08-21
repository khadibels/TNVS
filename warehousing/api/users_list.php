<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role(['admin'], 'json'); // only admins can view/manage users

header('Content-Type: application/json');

try {
  $st = $pdo->query("SELECT id, name, email, role FROM users ORDER BY name");
  echo json_encode($st->fetchAll());
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'SERVER_ERROR']);
}
