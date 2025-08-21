<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login('json');

header('Content-Type: application/json');

$id   = (int)($_POST['id'] ?? 0);
$code = trim($_POST['code'] ?? '');
$name = trim($_POST['name'] ?? '');
$desc = trim($_POST['description'] ?? '');
$active = isset($_POST['active']) && $_POST['active']=='1' ? 1 : 0;

if ($code==='' || $name==='') {
  http_response_code(422);
  echo json_encode(['ok'=>false,'error'=>'VALIDATION']); exit;
}

try {
  if ($id > 0) {
    $st = $pdo->prepare("UPDATE inventory_categories SET code=?, name=?, description=?, active=? WHERE id=?");
    $st->execute([$code,$name,($desc!==''?$desc:null),$active,$id]);
  } else {
    $st = $pdo->prepare("INSERT INTO inventory_categories (code,name,description,active) VALUES (?,?,?,?)");
    $st->execute([$code,$name,($desc!==''?$desc:null),$active]);
  }
  echo json_encode(['ok'=>true]);
} catch (PDOException $e) {
  if ($e->getCode()==='23000') { http_response_code(409); echo json_encode(['ok'=>false,'error'=>'DUPLICATE']); }
  else { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'SERVER_ERROR']); }
}
