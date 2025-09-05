<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role(['admin']); // only admins can change roles

header('Content-Type: application/json; charset=utf-8');

$id   = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$role = isset($_POST['role']) ? trim($_POST['role']) : '';

$allowed = [
  'admin',
  'manager',
  'warehouse_staff',
  'procurement_officer',
  'asset_manager',
  'document_controller',
  'project_lead',
  'viewer'
];

if ($id <= 0 || $role === '' || !in_array($role, $allowed, true)) {
  echo json_encode(['ok' => false, 'error' => 'INVALID_INPUT']);
  exit;
}

// check user exists
$chk = $pdo->prepare("SELECT id FROM users WHERE id=?");
$chk->execute([$id]);
if (!$chk->fetchColumn()) {
  echo json_encode(['ok' => false, 'error' => 'USER_NOT_FOUND']);
  exit;
}

// update role
$st = $pdo->prepare("UPDATE users SET role=? WHERE id=?");
$st->execute([$role, $id]);

echo json_encode(['ok' => true]);
