<?php
require_once __DIR__."/../../includes/config.php";
require_once __DIR__."/../../includes/auth.php";
require_once __DIR__."/../../includes/db.php";

require_login("json");
header("Content-Type: application/json; charset=utf-8");

$pdo = db('wms');

$id = (int)($_GET['id'] ?? 0);

function col_exists(PDO $pdo, string $t, string $c): bool {
  $s=$pdo->prepare("SELECT 1 FROM information_schema.columns
                     WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1");
  $s->execute([$t,$c]); return (bool)$s->fetchColumn();
}

try {
  $hasLat = col_exists($pdo, 'warehouse_locations', 'latitude');
  $hasLng = col_exists($pdo, 'warehouse_locations', 'longitude');
  $geoSel = ($hasLat && $hasLng) ? ", latitude, longitude" : ", NULL AS latitude, NULL AS longitude";

  if ($id) {
    $st = $pdo->prepare("SELECT id, code, name, address{$geoSel} FROM warehouse_locations WHERE id=?");
    $st->execute([$id]);
    echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
    exit;
  }
  $rows = $pdo->query("SELECT id, code, name, address{$geoSel} FROM warehouse_locations ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
  echo json_encode($rows ?: []);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["ok"=>false, "err"=>"Query failed"]);
}
