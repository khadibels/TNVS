<?php
declare(strict_types=1);
$inc = __DIR__ . '/../../includes';
if (file_exists($inc . '/config.php')) require_once $inc . '/config.php';
if (file_exists($inc . '/auth.php'))  require_once $inc . '/auth.php';
if (function_exists('require_login')) require_login();
header('Content-Type: application/json; charset=utf-8');

try{
  if(!isset($pdo)) throw new Exception('DB missing');
  $pdo->beginTransaction();

  $id   = (int)($_POST['id'] ?? 0);
  $code = trim((string)($_POST['code'] ?? ''));
  $name = trim((string)($_POST['name'] ?? ''));
  if($name==='') throw new Exception('Name required');

  $scope= trim((string)($_POST['scope'] ?? ''));
  $start= $_POST['start_date'] ?? null;
  $dead = $_POST['deadline_date'] ?? null;
  $status = strtolower(trim((string)($_POST['status'] ?? 'planned')));
  if(!in_array($status,['planned','ongoing','completed','delayed','closed'],true)) $status='planned';
  $owner = trim((string)($_POST['owner_name'] ?? ''));

  if($id>0){
    $sql="UPDATE plt_projects SET code=NULLIF(?,''), name=?, scope=NULLIF(?,''), start_date=?, deadline_date=?, status=?, owner_name=NULLIF(?, '') WHERE id=?";
    $pdo->prepare($sql)->execute([$code,$name,$scope,$start?:null,$dead?:null,$status,$owner,$id]);
  } else {
    if($code==='') $code = 'PRJ-'.date('ymd').'-'.substr((string)microtime(true),-4);
    $sql="INSERT INTO plt_projects (code,name,scope,start_date,deadline_date,status,owner_name)
          VALUES (?,?,?,?,?,?,?)";
    $pdo->prepare($sql)->execute([$code,$name,$scope?:null,$start?:null,$dead?:null,$status,$owner?:null]);
    $id=(int)$pdo->lastInsertId();
  }

  // Milestones (overwrite for simplicity)
  $pdo->prepare("DELETE FROM plt_milestones WHERE project_id=?")->execute([$id]);
  $msJson = $_POST['milestones_json'] ?? '[]';
  $rows = json_decode($msJson, true) ?: [];
  if($rows){
    $ins = $pdo->prepare("INSERT INTO plt_milestones (project_id,title,due_date,status,owner) VALUES (?,?,?,?,?)");
    foreach($rows as $m){
      $title=trim((string)($m['title'] ?? ''));
      if($title==='') continue;
      $ins->execute([$id,$title,($m['due_date'] ?? null)?:null, ($m['status'] ?? 'pending'), NULLIF(trim((string)($m['owner']??'')),'')]);
    }
  }

  $pdo->commit();
  echo json_encode(['ok'=>1,'id'=>$id,'code'=>$code]);
}catch(Throwable $e){
  if($pdo && $pdo->inTransaction()) $pdo->rollBack();
  http_response_code(400);
  echo json_encode(['error'=>$e->getMessage()]);
}
