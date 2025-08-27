<?php
declare(strict_types=1);
$inc = __DIR__ . '/../../includes';
if (file_exists($inc . '/config.php')) require_once $inc . '/config.php';
if (file_exists($inc . '/auth.php'))  require_once $inc . '/auth.php';
if (function_exists('require_login')) require_login();
header('Content-Type: application/json; charset=utf-8');

try{
  if(!isset($pdo)) throw new Exception('DB missing');
  $id=(int)($_POST['id']??0);
  $status=strtolower(trim((string)($_POST['status']??'')));
  if(!$id) throw new Exception('Missing id');
  if(!in_array($status,['planned','ongoing','completed','delayed','closed'],true)) throw new Exception('Invalid status');

  $pdo->prepare("UPDATE plt_projects SET status=? WHERE id=?")->execute([$status,$id]);
  echo json_encode(['ok'=>1,'id'=>$id,'status'=>$status]);
}catch(Throwable $e){
  http_response_code(400);
  echo json_encode(['error'=>$e->getMessage()]);
}
