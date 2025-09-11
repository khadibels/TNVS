<?php
require_once __DIR__."/../../includes/config.php";
require_once __DIR__."/../../includes/auth.php";
require_once __DIR__."/../../includes/db.php";

require_login("json");
header("Content-Type: application/json; charset=utf-8");

$pdo = db('wms');
$id  = (int)($_GET['id'] ?? 0);

try {
  if ($id) {
    $st = $pdo->prepare("SELECT id, code, name, description, active FROM inventory_categories WHERE id=?");
    $st->execute([$id]);
    echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
    exit;
  }
  $rows = $pdo->query("SELECT id, code, name, description, active FROM inventory_categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
  echo json_encode($rows ?: []);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["ok"=>false,"err"=>"Query failed"]);
}
