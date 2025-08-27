<?php
declare(strict_types=1);
$inc = __DIR__ . '/../../includes';
if (file_exists($inc . '/config.php')) require_once $inc . '/config.php';
if (file_exists($inc . '/auth.php'))  require_once $inc . '/auth.php';
if (function_exists('require_login')) require_login();
header('Content-Type: application/json; charset=utf-8');

try {
  if (!isset($pdo)) throw new Exception('DB missing');

  $id         = (int)($_POST['id'] ?? 0);
  $project_id = (int)($_POST['project_id'] ?? 0);
  $shipment_no= trim((string)($_POST['shipment_no'] ?? ''));
  $status     = strtolower(trim((string)($_POST['status'] ?? 'planned')));
  $origin     = trim((string)($_POST['origin'] ?? ''));
  $destination= trim((string)($_POST['destination'] ?? ''));
  $schedule   = $_POST['schedule_date'] ?? null;
  $eta        = $_POST['eta_date'] ?? null;
  $vehicle    = trim((string)($_POST['vehicle'] ?? ''));
  $driver     = trim((string)($_POST['driver'] ?? ''));
  $notes      = trim((string)($_POST['notes'] ?? ''));

  if (!in_array($status, ['planned','picked','in_transit','delivered','cancelled'], true)) $status='planned';

  if ($id>0){
    $sql="UPDATE plt_shipments SET project_id=?, shipment_no=?, status=?, origin=?, destination=?, schedule_date=?, eta_date=?, vehicle=?, driver=?, notes=? WHERE id=?";
    $pdo->prepare($sql)->execute([$project_id?:null, $shipment_no?:null, $status, $origin?:null, $destination?:null, $schedule?:null, $eta?:null, $vehicle?:null, $driver?:null, $notes?:null, $id]);
    echo json_encode(['ok'=>1,'id'=>$id,'mode'=>'update']);
  } else {
    if ($shipment_no==='') $shipment_no='SHP-'.date('Ym').'-'.substr((string)microtime(true),-4);
    $sql="INSERT INTO plt_shipments (project_id, shipment_no, status, origin, destination, schedule_date, eta_date, vehicle, driver, notes, assigned_user_id)
          VALUES (?,?,?,?,?,?,?,?,?,?,?)";
    $assigned = function_exists('current_user') ? (int)(current_user()['id'] ?? 0) : null;
    $pdo->prepare($sql)->execute([$project_id?:null,$shipment_no,$status,$origin?:null,$destination?:null,$schedule?:null,$eta?:null,$vehicle?:null,$driver?:null,$notes?:null,$assigned?:null]);
    echo json_encode(['ok'=>1,'id'=>$pdo->lastInsertId(),'mode'=>'insert','shipment_no'=>$shipment_no]);
  }
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['error'=>$e->getMessage()]);
}
