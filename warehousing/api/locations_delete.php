<?php
require_once __DIR__."/../../includes/config.php";
require_once __DIR__."/../../includes/auth.php";
require_once __DIR__."/../../includes/db.php";

require_login("json");
require_role(["admin"], "json");
header("Content-Type: application/json; charset=utf-8");

$pdo = db('wms');
$id  = (int)($_POST['id'] ?? 0);
if (!$id) { http_response_code(400); echo json_encode(["ok"=>false,"err"=>"Missing id"]); exit; }

try {
  // Optional guard: prevent delete if referenced by shipments or items
  $ref = $pdo->prepare("SELECT 
      (SELECT COUNT(*) FROM shipments WHERE origin_id=? OR destination_id=?) +
      (SELECT COUNT(*) FROM inventory_items WHERE default_location_id=?) AS cnt");
  $ref->execute([$id,$id,$id]);
  if ((int)$ref->fetchColumn() > 0) {
    http_response_code(409);
    echo json_encode(["ok"=>false,"err"=>"Location is in use"]);
    exit;
  }

  $st = $pdo->prepare("DELETE FROM warehouse_locations WHERE id=?");
  $st->execute([$id]);
  echo json_encode(["ok"=>true]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["ok"=>false,"err"=>"Delete failed"]);
}
