<?php
require_once __DIR__."/../../includes/config.php";
require_once __DIR__."/../../includes/auth.php";
require_once __DIR__."/../../includes/db.php";

require_login("json");
require_role(["admin","manager"], "json");
header("Content-Type: application/json; charset=utf-8");

$pdo = db('wms');
$id  = (int)($_POST['id'] ?? 0);
if (!$id) { http_response_code(400); echo json_encode(["ok"=>false,"err"=>"Missing id"]); exit; }

try {
  // optional guard: block delete if any items use this category
  $catName = null;
  $stName = $pdo->prepare("SELECT name FROM inventory_categories WHERE id=?");
  $stName->execute([$id]);
  $catName = $stName->fetchColumn() ?: null;

  // Detect which columns exist in inventory_items to avoid invalid column errors
  $cols = $pdo->query("SHOW COLUMNS FROM inventory_items")->fetchAll(PDO::FETCH_COLUMN, 0);
  $hasCategoryId = in_array('category_id', $cols, true);
  $hasCategory   = in_array('category', $cols, true);

  if ($hasCategoryId || $hasCategory) {
    $where = [];
    $params = [];
    if ($hasCategoryId) { $where[] = "category_id=?"; $params[] = $id; }
    if ($hasCategory)   { $where[] = "category=?";   $params[] = (string)($catName ?? $id); }
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM inventory_items WHERE " . implode(" OR ", $where));
    $cnt->execute($params);
    if ((int)$cnt->fetchColumn() > 0) {
      http_response_code(409);
      echo json_encode(["ok"=>false,"err"=>"Category is in use by items"]);
      exit;
    }
  }

  $st = $pdo->prepare("DELETE FROM inventory_categories WHERE id=?");
  $st->execute([$id]);
  echo json_encode(["ok"=>true]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["ok"=>false,"err"=>"Delete failed"]);
}
