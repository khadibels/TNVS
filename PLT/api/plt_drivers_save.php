<?php
require_once __DIR__ . '/../../includes/config.php';
header('Content-Type: application/json');

$id    = (int)($_POST['id'] ?? 0);
$name  = trim($_POST['name'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$lic   = trim($_POST['license_no'] ?? '');
$notes = trim($_POST['notes'] ?? '');
$active= isset($_POST['active']) ? (int)$_POST['active'] : 1;

if ($name===''){ http_response_code(400); echo json_encode(['error'=>'Name is required']); exit; }

try{
  if ($id>0){
    $s=$pdo->prepare("UPDATE plt_drivers SET name=:n, phone=:p, license_no=:l, notes=:no, active=:a WHERE id=:id");
    $s->execute([':n'=>$name,':p'=>$phone,':l'=>$lic,':no'=>$notes,':a'=>$active,':id'=>$id]);
  }else{
    $s=$pdo->prepare("INSERT INTO plt_drivers(name,phone,license_no,notes,active) VALUES(:n,:p,:l,:no,:a)");
    $s->execute([':n'=>$name,':p'=>$phone,':l'=>$lic,':no'=>$notes,':a'=>$active]);
    $id=(int)$pdo->lastInsertId();
  }
  echo json_encode(['ok'=>true,'id'=>$id]);
}catch(Throwable $e){
  http_response_code(400);
  echo json_encode(['error'=>$e->getMessage()]);
}
