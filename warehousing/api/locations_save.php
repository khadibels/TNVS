<?php
require_once __DIR__."/../../includes/config.php";
require_once __DIR__."/../../includes/auth.php";
require_once __DIR__."/../../includes/db.php";

require_login("json");
require_role(["admin", "manager"], "json");
header("Content-Type: application/json; charset=utf-8");

$pdo = db('wms');

$id   = (int)($_POST['id'] ?? 0);
$code = trim($_POST['code'] ?? "");
$name = trim($_POST['name'] ?? "");
$addr = trim($_POST['address'] ?? "");
$latIn = trim((string)($_POST['latitude'] ?? ''));
$lngIn = trim((string)($_POST['longitude'] ?? ''));
$lat = ($latIn !== '' && is_numeric($latIn)) ? (float)$latIn : null;
$lng = ($lngIn !== '' && is_numeric($lngIn)) ? (float)$lngIn : null;

if ($code === "" || $name === "") { http_response_code(422); echo json_encode(["ok"=>false,"err"=>"Code and Name are required"]); exit; }
if (($lat !== null || $lng !== null) && ($lat === null || $lng === null)) {
  http_response_code(422);
  echo json_encode(["ok"=>false,"err"=>"Latitude and longitude must both be set"]);
  exit;
}
if ($lat !== null && $lng !== null) {
  $inPh = ($lat >= 4.2 && $lat <= 21.8 && $lng >= 116.0 && $lng <= 127.2);
  if (!$inPh) {
    http_response_code(422);
    echo json_encode(["ok"=>false,"err"=>"Coordinates must be inside the Philippines"]);
    exit;
  }
}

function col_exists(PDO $pdo, string $t, string $c): bool {
  $s=$pdo->prepare("SELECT 1 FROM information_schema.columns
                     WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1");
  $s->execute([$t,$c]); return (bool)$s->fetchColumn();
}

function ensure_geo_columns(PDO $pdo): void {
  if (!col_exists($pdo, 'warehouse_locations', 'latitude')) {
    $pdo->exec("ALTER TABLE warehouse_locations ADD COLUMN latitude DECIMAL(10,7) NULL AFTER address");
  }
  if (!col_exists($pdo, 'warehouse_locations', 'longitude')) {
    $pdo->exec("ALTER TABLE warehouse_locations ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude");
  }
}

try {
  ensure_geo_columns($pdo);
  if ($id) {
    $st = $pdo->prepare("UPDATE warehouse_locations SET code=?, name=?, address=?, latitude=?, longitude=? WHERE id=?");
    $st->execute([$code,$name,$addr,$lat,$lng,$id]);
  } else {
    $st = $pdo->prepare("INSERT INTO warehouse_locations (code,name,address,latitude,longitude) VALUES (?,?,?,?,?)");
    $st->execute([$code,$name,$addr,$lat,$lng]);
  }
  echo json_encode(["ok"=>true]);
} catch (PDOException $e) {
  if ($e->errorInfo[1] == 1062) { http_response_code(409); echo json_encode(["ok"=>false,"err"=>"Code or Name already exists"]); }
  else { http_response_code(500); echo json_encode(["ok"=>false,"err"=>"Save failed"]); }
}
