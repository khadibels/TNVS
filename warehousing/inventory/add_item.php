<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

require_login('json');                   
header('Content-Type: application/json');

$sku   = trim($_POST['sku'] ?? '');
$name  = trim($_POST['name'] ?? '');
$cat   = trim($_POST['category'] ?? '');
$reord = (int)($_POST['reorder_level'] ?? 0);
$loc   = trim($_POST['location'] ?? '');
$stock = 0;

$errors = [];
if ($sku === '')  $errors[] = 'SKU is required';
if ($name === '') $errors[] = 'Name is required';
if ($cat === '')  $errors[] = 'Category is required';
if ($reord < 0)   $errors[] = 'Reorder must be ≥ 0';

if ($cat !== '') {
  $okCat = $pdo->prepare("SELECT 1 FROM inventory_categories WHERE name = ? AND active = 1");
  $okCat->execute([$cat]);
  if (!$okCat->fetchColumn()) {
    $errors[] = 'Invalid category';
  }
}

if ($errors) {
  http_response_code(422);
  echo json_encode(['errors' => $errors]);
  exit;
}

try {
  
  $stmt = $pdo->prepare("
    INSERT INTO inventory_items (sku, name, category, stock, reorder_level, location)
    VALUES (:sku, :name, :cat, :stock, :reord, :loc)
  ");
  $stmt->execute([
    ':sku'   => $sku,
    ':name'  => $name,
    ':cat'   => $cat, 
    ':stock' => $stock,
    ':reord' => $reord,
    ':loc'   => ($loc !== '' ? $loc : null),
  ]);

 
  if ($loc !== '') {
    $check = $pdo->prepare("SELECT id FROM warehouse_locations WHERE name = ? LIMIT 1");
    $check->execute([$loc]);
    $locId = (int)($check->fetchColumn() ?? 0);

    if ($locId === 0) {
      $code = strtoupper(preg_replace('/[^A-Z0-9]+/i','_', substr($loc, 0, 20)));
      if ($code === '') $code = 'LOC'.mt_rand(10000,99999);
      $insLoc = $pdo->prepare("INSERT INTO warehouse_locations (code, name) VALUES (?, ?)");
      $insLoc->execute([$code, $loc]);
    }
  }

  echo json_encode(['ok' => true]);
} catch (PDOException $e) {
  http_response_code(400);
  $msg = ($e->getCode() === '23000') ? 'SKU already exists' : 'Database error';
  echo json_encode(['error' => $msg]);
}
