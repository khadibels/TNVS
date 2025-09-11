<?php
require_once __DIR__."/../../../includes/config.php";
require_once __DIR__."/../../../includes/auth.php";
require_once __DIR__."/../../../includes/db.php";

require_login("json");
header("Content-Type: application/json; charset=utf-8");

$pdo = db('wms');
if (!$pdo instanceof PDO) { http_response_code(500); echo json_encode(["ok"=>false,"err"=>"DB not available"]); exit; }

function col_exists(PDO $pdo, string $t, string $c): bool {
  $s=$pdo->prepare("SELECT 1 FROM information_schema.columns
                     WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1");
  $s->execute([$t,$c]);
  return (bool)$s->fetchColumn();
}

try {
  // choose best columns available
  $label = col_exists($pdo,'warehouse_locations','name') ? 'name'
         : (col_exists($pdo,'warehouse_locations','label') ? 'label' : null);
  if ($label === null) { echo json_encode([]); exit; }

  $code  = col_exists($pdo,'warehouse_locations','code') ? 'code' : null;

  $sql = $code
    ? "SELECT id, CONCAT_WS(' - ', $code, $label) AS name FROM warehouse_locations ORDER BY $label"
    : "SELECT id, $label AS name FROM warehouse_locations ORDER BY $label";

  $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
  echo json_encode($rows ?: []);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["ok"=>false, "err"=>"Query failed"]);
}
