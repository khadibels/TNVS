<?php
require_once __DIR__."/../../../includes/config.php";
require_once __DIR__."/../../../includes/auth.php";
require_once __DIR__."/../../../includes/db.php";

require_login("json");
require_role(["admin","manager"], "json");
header("Content-Type: application/json; charset=utf-8");

$pdo = db('wms');
if (!$pdo instanceof PDO) { http_response_code(500); echo json_encode(["ok"=>false,"err"=>"DB not available"]); exit; }

if (session_status() === PHP_SESSION_NONE) session_start();

$id = (int)($_POST['id'] ?? 0);
$destinationId = (int)($_POST['destination_id'] ?? 0);

if (!$id || !$destinationId) {
  http_response_code(400);
  echo json_encode(["ok"=>false,"err"=>"Missing shipment or destination"]);
  exit;
}

try {
  $st = $pdo->prepare("SELECT id, origin_id, destination_id, ref_no, status FROM shipments WHERE id=? LIMIT 1");
  $st->execute([$id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    http_response_code(404);
    echo json_encode(["ok"=>false,"err"=>"Shipment not found"]);
    exit;
  }

  $originId = (int)$row['origin_id'];
  $currentDestination = (int)$row['destination_id'];
  if ($destinationId === $originId) {
    http_response_code(422);
    echo json_encode(["ok"=>false,"err"=>"Destination must be different from Origin"]);
    exit;
  }
  if ($destinationId === $currentDestination) {
    echo json_encode(["ok"=>true,"unchanged"=>true]);
    exit;
  }

  $loc = $pdo->prepare("SELECT id, code, name FROM warehouse_locations WHERE id=? LIMIT 1");
  $loc->execute([$destinationId]);
  $dest = $loc->fetch(PDO::FETCH_ASSOC);
  if (!$dest) {
    http_response_code(422);
    echo json_encode(["ok"=>false,"err"=>"Selected destination not found"]);
    exit;
  }

  $pdo->beginTransaction();
  $u = $pdo->prepare("UPDATE shipments SET destination_id=? WHERE id=?");
  $u->execute([$destinationId, $id]);

  $detail = "Destination updated to " . trim(($dest['code'] ?? '') . " - " . ($dest['name'] ?? ''));
  $e = $pdo->prepare("INSERT INTO shipment_events (shipment_id, event_type, details, user_id) VALUES (?,?,?,?)");
  $e->execute([$id, 'Destination Changed', $detail, $_SESSION['user']['id'] ?? null]);
  $pdo->commit();

  echo json_encode(["ok"=>true]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(["ok"=>false,"err"=>"Failed to update destination"]);
}
  $status = strtolower(trim((string)($row['status'] ?? '')));
  if ($status === 'delivered') {
    http_response_code(422);
    echo json_encode(["ok"=>false,"err"=>"Delivered shipments can no longer change destination"]);
    exit;
  }

