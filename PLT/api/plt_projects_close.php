<?php
declare(strict_types=1);
$inc = __DIR__ . '/../../includes';
if (file_exists($inc . '/config.php')) require_once $inc . '/config.php';
if (file_exists($inc . '/auth.php'))  require_once $inc . '/auth.php';
if (function_exists('require_login')) require_login();
header('Content-Type: application/json; charset=utf-8');

try{
  if(!isset($pdo)) throw new Exception('DB missing');
  $id=(int)($_GET['id']??0);
  if(!$id) throw new Exception('Missing project id');

  // All shipments delivered?
  $sqlShip="SELECT COUNT(*) FROM plt_shipments WHERE project_id=? AND status<>'delivered' AND status<>'cancelled'";
  $st=$pdo->prepare($sqlShip); $st->execute([$id]);
  $openShip=(int)$st->fetchColumn();

  if($openShip>0){
    echo json_encode(['ok'=>0,'error'=>"There are $openShip shipment(s) not yet delivered/cancelled."]); exit;
  }

  // Required docs: POD, DR, BOL
  // We accept docs either linked directly to project or to its shipments
  $required=['POD','DR','BOL'];
  $missing=[];

  foreach($required as $t){
    // project-level
    $q1=$pdo->prepare("SELECT COUNT(*) FROM plt_documents WHERE (project_id=?) AND doc_type=?");
    $q1->execute([$id,$t]); $c1=(int)$q1->fetchColumn();

    // shipment-level
    $q2=$pdo->prepare("SELECT COUNT(*) FROM plt_documents d
                       WHERE d.doc_type=? AND EXISTS(
                         SELECT 1 FROM plt_shipments s WHERE s.id=d.shipment_id AND s.project_id=?
                       )");
    $q2->execute([$t,$id]); $c2=(int)$q2->fetchColumn();

    if(($c1+$c2)===0) $missing[]=$t;
  }

  if($missing){
    echo json_encode(['ok'=>0,'error'=>'Missing required documents: '.implode(', ',$missing)]); exit;
  }

  $pdo->prepare("UPDATE plt_projects SET status='closed' WHERE id=?")->execute([$id]);
  echo json_encode(['ok'=>1]);
}catch(Throwable $e){
  http_response_code(400);
  echo json_encode(['error'=>$e->getMessage()]);
}
