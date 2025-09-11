<?php
require_once __DIR__."/../../includes/config.php";
require_once __DIR__."/../../includes/auth.php";
require_once __DIR__."/../../includes/db.php";

require_login("json");
require_role(["admin","manager"], "json");
header("Content-Type: application/json; charset=utf-8");

$pdo = db('wms');

$id   = (int)($_POST['id'] ?? 0);
$code = trim($_POST['code'] ?? "");
$name = trim($_POST['name'] ?? "");
$desc = trim($_POST['description'] ?? "");
$act  = isset($_POST['active']) && $_POST['active'] == '1' ? 1 : 0;

if ($code==="" || $name==="") { http_response_code(422); echo json_encode(["ok"=>false,"err"=>"Code and Name are required"]); exit; }

try {
  if ($id) {
    $st = $pdo->prepare("UPDATE inventory_categories SET code=?, name=?, description=?, active=? WHERE id=?");
    $st->execute([$code,$name,$desc,$act,$id]);
  } else {
    $st = $pdo->prepare("INSERT INTO inventory_categories (code,name,description,active) VALUES (?,?,?,?)");
    $st->execute([$code,$name,$desc,$act]);
  }
  echo json_encode(["ok"=>true]);
} catch (PDOException $e) {
  if ($e->errorInfo[1] == 1062) { http_response_code(409); echo json_encode(["ok"=>false,"err"=>"Code or Name already exists"]); }
  else { http_response_code(500); echo json_encode(["ok"=>false,"err"=>"Save failed"]); }
}
