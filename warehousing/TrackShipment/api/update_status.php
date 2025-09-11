<?php
require_once __DIR__."/../../../includes/config.php";
require_once __DIR__."/../../../includes/auth.php";
require_once __DIR__."/../../../includes/db.php";

require_login("json");
header("Content-Type: application/json; charset=utf-8");

$pdo = db('wms');
if (!$pdo instanceof PDO) { http_response_code(500); echo json_encode(["ok"=>false,"err"=>"DB not available"]); exit; }

if (session_status() === PHP_SESSION_NONE) session_start();

$id      = (int)($_POST['id'] ?? 0);
$status  = trim($_POST['status'] ?? '');
$details = trim($_POST['details'] ?? '');

$allowed = ['Draft','Ready','Dispatched','In Transit','Delivered','Delayed','Cancelled','Returned'];
if (!$id || !in_array($status, $allowed, true)) { http_response_code(400); echo json_encode(["ok"=>false,"err"=>"Invalid input"]); exit; }

$pdo->beginTransaction();
try {
  $u = $pdo->prepare("UPDATE shipments SET status=? WHERE id=?");
  $u->execute([$status, $id]);

  $e = $pdo->prepare("INSERT INTO shipment_events (shipment_id, event_type, details, user_id) VALUES (?,?,?,?)");
  $e->execute([$id, $status, $details, $_SESSION['user']['id'] ?? null]);

  $pdo->commit();
  echo json_encode(["ok"=>true]);
} catch (Throwable $e) {
  $pdo->rollBack();
  http_response_code(500);
  echo json_encode(["ok"=>false,"err"=>"Update failed"]);
}
