<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/config.php';
require_once __DIR__.'/../../includes/auth.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

function bad($m,$c=400){ http_response_code($c); echo json_encode(['error'=>$m]); exit; }

try{
  if($_SERVER['REQUEST_METHOD']!=='POST') bad('POST required',405);

  $id = (int)($_POST['id'] ?? 0);
  $title = trim($_POST['title'] ?? '');
  if($title==='') bad('title required');
  $needed = $_POST['needed_by'] ?? null;
  $priority = $_POST['priority'] ?? 'normal';
  $requestor = trim($_POST['requestor'] ?? '');
  $dept_id = (int)($_POST['department_id'] ?? 0);
  $status = $_POST['status'] ?? 'draft';
  $notes = trim($_POST['notes'] ?? '');

  $d = $_POST['items']['descr'] ?? [];
  $q = $_POST['items']['qty']   ?? [];
  $p = $_POST['items']['price'] ?? [];

  $pdo->beginTransaction();

  if ($id>0){
    $st=$pdo->prepare("UPDATE procurement_requests
      SET title=?, needed_by=?, priority=?, requestor=?, department_id=?, status=?, notes=?, updated_at=NOW()
      WHERE id=?");
    $st->execute([$title,$needed,$priority,$requestor,$dept_id,$status,$notes,$id]);
    $pdo->prepare("DELETE FROM procurement_request_items WHERE pr_id=?")->execute([$id]);
  } else {
    $pr_no='PR-'.date('Ymd').'-'.str_pad((string)random_int(1,9999),4,'0',STR_PAD_LEFT);
    $st=$pdo->prepare("INSERT INTO procurement_requests
      (pr_no,title,needed_by,priority,requestor,department_id,status,notes,estimated_total,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,0,NOW(),NOW())");
    $st->execute([$pr_no,$title,$needed,$priority,$requestor,$dept_id,$status,$notes]);
    $id=(int)$pdo->lastInsertId();
  }

  $ins=$pdo->prepare("INSERT INTO procurement_request_items (pr_id,descr,qty,price,line_total) VALUES (?,?,?,?,?)");
  $grand=0;
  foreach($d as $i=>$descr){
    $descr=trim($descr); if($descr==='') continue;
    $qty=(float)($q[$i] ?? 0); $price=(float)($p[$i] ?? 0); $lt=$qty*$price; $grand+=$lt;
    $ins->execute([$id,$descr,$qty,$price,$lt]);
  }
  $pdo->prepare("UPDATE procurement_requests SET estimated_total=? WHERE id=?")->execute([$grand,$id]);

  $pdo->commit();
  echo json_encode(['ok'=>1,'id'=>$id]);
}catch(Throwable $e){
  if($pdo->inTransaction()) $pdo->rollBack();
  bad('server_error: '.$e->getMessage(),500);
}
