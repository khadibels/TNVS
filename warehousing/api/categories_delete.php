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
  // optional guard: block delete if any items use this category
  $cnt = $pdo->prepare("SELECT COUNT(*) FROM inventory_items WHERE category_id=? OR category=?");
  // supports either schema (id FK or name)
  $cnt->execute([$id, (string)$id]); 
  if ((int)$cnt->fetchColumn() > 0) {
    http_response_code(409);
    echo json_encode(["ok"=>false,"err"=>"Category is in use by items"]);
    exit;
  }

  $st = $pdo->prepare("DELETE FROM inventory_categories WHERE id=?");
  $st->execute([$id]);
  echo json_encode(["ok"=>true]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["ok"=>false,"err"=>"Delete failed"]);
}
