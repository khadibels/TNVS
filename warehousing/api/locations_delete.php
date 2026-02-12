<?php
require_once __DIR__."/../../includes/config.php";
require_once __DIR__."/../../includes/auth.php";
require_once __DIR__."/../../includes/db.php";

require_login("json");
require_role(["admin", "manager"], "json");
header("Content-Type: application/json; charset=utf-8");

$pdo = db('wms');
$id  = (int)($_POST['id'] ?? 0);
if (!$id) { http_response_code(400); echo json_encode(["ok"=>false,"err"=>"Missing id"]); exit; }

function table_exists(PDO $pdo, string $table): bool {
  $s = $pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1");
  $s->execute([$table]);
  return (bool)$s->fetchColumn();
}

function column_exists(PDO $pdo, string $table, string $column): bool {
  $s = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
  $s->execute([$table, $column]);
  return (bool)$s->fetchColumn();
}

try {
  // Prevent delete if referenced anywhere
  $refCount = 0;

  if (table_exists($pdo, 'shipments') && column_exists($pdo, 'shipments', 'origin_id') && column_exists($pdo, 'shipments', 'destination_id')) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM shipments WHERE origin_id=? OR destination_id=?");
    $st->execute([$id, $id]);
    $refCount += (int)$st->fetchColumn();
  }

  if (table_exists($pdo, 'stock_levels') && column_exists($pdo, 'stock_levels', 'location_id')) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM stock_levels WHERE location_id=?");
    $st->execute([$id]);
    $refCount += (int)$st->fetchColumn();
  }

  if (table_exists($pdo, 'stock_transactions')) {
    $parts = [];
    $params = [];
    if (column_exists($pdo, 'stock_transactions', 'from_location_id')) {
      $parts[] = "from_location_id=?";
      $params[] = $id;
    }
    if (column_exists($pdo, 'stock_transactions', 'to_location_id')) {
      $parts[] = "to_location_id=?";
      $params[] = $id;
    }
    if (column_exists($pdo, 'stock_transactions', 'location_id')) {
      $parts[] = "location_id=?";
      $params[] = $id;
    }
    if ($parts) {
      $sql = "SELECT COUNT(*) FROM stock_transactions WHERE " . implode(" OR ", $parts);
      $st = $pdo->prepare($sql);
      $st->execute($params);
      $refCount += (int)$st->fetchColumn();
    }
  }

  if (table_exists($pdo, 'inventory_items') && column_exists($pdo, 'inventory_items', 'default_location_id')) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM inventory_items WHERE default_location_id=?");
    $st->execute([$id]);
    $refCount += (int)$st->fetchColumn();
  }

  if ($refCount > 0) {
    http_response_code(409);
    echo json_encode(["ok"=>false,"err"=>"Location is in use and cannot be deleted"]);
    exit;
  }

  $st = $pdo->prepare("DELETE FROM warehouse_locations WHERE id=?");
  $st->execute([$id]);
  if ($st->rowCount() < 1) {
    http_response_code(404);
    echo json_encode(["ok"=>false,"err"=>"Location not found"]);
    exit;
  }
  echo json_encode(["ok"=>true]);
} catch (Throwable $e) {
  http_response_code(500);
  $msg = (defined('APP_DEBUG') && APP_DEBUG) ? ("Delete failed: " . $e->getMessage()) : "Delete failed";
  echo json_encode(["ok"=>false,"err"=>$msg]);
}
