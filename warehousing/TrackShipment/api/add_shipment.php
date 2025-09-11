<?php
require_once __DIR__."/../../../includes/config.php";
require_once __DIR__."/../../../includes/auth.php";
require_once __DIR__."/../../../includes/db.php";

require_login("json");
header("Content-Type: application/json; charset=utf-8");

$pdo = db('wms');
if (!$pdo instanceof PDO) { http_response_code(500); echo json_encode(["ok"=>false,"err"=>"DB not available"]); exit; }

if (session_status() === PHP_SESSION_NONE) session_start();

function gen_ref(): string {
  return "SHP-".date("Ymd")."-".str_pad((string)random_int(0,9999), 4, "0", STR_PAD_LEFT);
}

$origin_id = (int)($_POST['origin_id'] ?? 0);
$dest_id   = (int)($_POST['destination_id'] ?? 0);
$carrier   = trim($_POST['carrier'] ?? '');
$contact   = trim($_POST['contact_name'] ?? '');
$phone     = trim($_POST['contact_phone'] ?? '');
$pickup    = $_POST['expected_pickup']   ?: null;
$eta       = $_POST['expected_delivery'] ?: null;
$notes     = trim($_POST['notes'] ?? '');

if (!$origin_id || !$dest_id) { http_response_code(400); echo json_encode(["ok"=>false,"err"=>"Origin and Destination are required"]); exit; }
if ($origin_id === $dest_id)  { http_response_code(400); echo json_encode(["ok"=>false,"err"=>"Origin and Destination must be different"]); exit; }
if (!$pickup)                 { http_response_code(400); echo json_encode(["ok"=>false,"err"=>"Pickup Date is required"]); exit; }
if (!$eta)                    { http_response_code(400); echo json_encode(["ok"=>false,"err"=>"ETA Delivery is required"]); exit; }
if (strtotime($eta) < strtotime($pickup)) { http_response_code(400); echo json_encode(["ok"=>false,"err"=>"ETA Delivery cannot be before Pickup Date"]); exit; }

$pdo->beginTransaction();
try {
  $ref = gen_ref(); $tries=0;
  while (true) {
    try {
      $ins = $pdo->prepare("INSERT INTO shipments
        (ref_no, origin_id, destination_id, status, carrier, contact_name, contact_phone, expected_pickup, expected_delivery, notes, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)");
      $ins->execute([$ref,$origin_id,$dest_id,'Draft',$carrier,$contact,$phone,$pickup,$eta,$notes,$_SESSION['user']['id'] ?? 0]);
      break;
    } catch (PDOException $e) {
      if ($e->errorInfo[1] == 1062 && $tries < 3) { $ref = gen_ref(); $tries++; continue; }
      throw $e;
    }
  }

  $sid = (int)$pdo->lastInsertId();
  $ev  = $pdo->prepare("INSERT INTO shipment_events (shipment_id, event_type, details, user_id) VALUES (?,?,?,?)");
  $ev->execute([$sid, 'Draft', 'Shipment created', $_SESSION['user']['id'] ?? null]);

  $pdo->commit();
  echo json_encode(["ok"=>true, "ref_no"=>$ref, "id"=>$sid]);
} catch (Throwable $e) {
  $pdo->rollBack();
  http_response_code(500);
  echo json_encode(["ok"=>false, "err"=>"Create failed"]);
}
