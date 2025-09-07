<?php
require_once __DIR__ . "/../../includes/config.php";
require_once __DIR__ . "/../../includes/auth.php";

require_login("json");
header("Content-Type: application/json; charset=utf-8");
require_role(['admin', 'manager']);

function jerr($msg, $code=400){
  http_response_code($code);
  echo json_encode(['error'=>$msg], JSON_UNESCAPED_UNICODE);
  exit;
}
function col_exists(PDO $pdo, string $table, string $col): bool {
  $st=$pdo->prepare("SELECT 1 FROM information_schema.columns
                     WHERE table_schema = DATABASE()
                       AND table_name = ?
                       AND column_name = ?
                     LIMIT 1");
  $st->execute([$table,$col]);
  return (bool)$st->fetchColumn();
}

$sku   = trim($_POST["sku"] ?? "");
$name  = trim($_POST["name"] ?? "");
$cat   = trim($_POST["category"] ?? "");
$reord = (int) ($_POST["reorder_level"] ?? 0);
$loc   = trim($_POST["location"] ?? "");

$errors = [];
if ($sku === "")   $errors[] = "SKU is required";
if ($name === "")  $errors[] = "Name is required";
if ($cat === "")   $errors[] = "Category is required";
if ($reord < 0)    $errors[] = "Reorder must be ≥ 0";

if ($cat !== "") {
  $okCat = $pdo->prepare("SELECT 1 FROM inventory_categories WHERE name = ? AND active = 1");
  $okCat->execute([$cat]);
  if (!$okCat->fetchColumn()) $errors[] = "Invalid category";
}
if ($errors) { jerr(implode(", ", $errors), 422); }

try {
  
  $hasLocationCol = col_exists($pdo, 'inventory_items', 'location');
  $hasDefLocCol   = col_exists($pdo, 'inventory_items', 'default_location');

  
  $cols = ['sku','name','category','reorder_level'];
  $vals = [':sku',':name',':cat',':reord'];
  $args = [
    ':sku'   => $sku,
    ':name'  => $name,
    ':cat'   => $cat,
    ':reord' => $reord,
  ];

  if ($loc !== "") {
    if ($hasLocationCol) {
      $cols[] = 'location';
      $vals[] = ':loc';
      $args[':loc'] = $loc;
    } elseif ($hasDefLocCol) {
      $cols[] = 'default_location';
      $vals[] = ':loc';
      $args[':loc'] = $loc;
    }
  }

  $sql = "INSERT INTO inventory_items (".implode(',', $cols).") VALUES (".implode(',', $vals).")";
  $st = $pdo->prepare($sql);
  $st->execute($args);

  
  if ($loc !== "") {
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS warehouse_locations (
      id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      code VARCHAR(64) NOT NULL UNIQUE,
      name VARCHAR(255) NOT NULL UNIQUE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $check = $pdo->prepare("SELECT id FROM warehouse_locations WHERE name = ? LIMIT 1");
    $check->execute([$loc]);
    $locId = (int)($check->fetchColumn() ?? 0);

    if ($locId === 0) {
      $code = strtoupper(preg_replace('/[^A-Z0-9]+/i', '_', substr($loc, 0, 20)));
      if ($code === "") $code = "LOC".mt_rand(10000,99999);
      $insLoc = $pdo->prepare("INSERT INTO warehouse_locations (code, name) VALUES (?, ?)");
      $insLoc->execute([$code, $loc]);
    }
  }

  echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
  if ($e->getCode() === "23000") jerr("SKU already exists", 409);
  jerr("Database error: ".$e->getMessage(), 500);
}
