<?php
require_once __DIR__ . "/../../../includes/config.php";
require_once __DIR__ . "/../../../includes/db.php";
header("Content-Type: application/json; charset=utf-8");

function log2_auth_ok(): bool {
  if (!defined('LOG2_API_KEY') || LOG2_API_KEY === '') return true;
  $key = $_SERVER['HTTP_X_LOG2_KEY'] ?? ($_GET['key'] ?? '');
  return hash_equals((string)LOG2_API_KEY, (string)$key);
}

if (!log2_auth_ok()) {
  http_response_code(401);
  echo json_encode(["ok" => false, "err" => "Unauthorized"]);
  exit;
}

$pdo = db('wms');
if (!$pdo instanceof PDO) { http_response_code(500); echo json_encode(["ok"=>false,"err"=>"DB not available"]); exit; }

$status = trim((string)($_GET['status'] ?? 'Ready'));
$allowed = ['Ready','Dispatched','In Transit','Delivered','Delayed','Cancelled','Returned','Draft'];
if ($status !== '' && !in_array($status, $allowed, true)) {
  http_response_code(400);
  echo json_encode(["ok"=>false,"err"=>"Invalid status"]);
  exit;
}

$where = $status === '' ? '' : 'WHERE s.status = ?';
$params = $status === '' ? [] : [$status];

$sql = "
  SELECT s.id, s.ref_no, s.status, s.carrier,
         DATE_FORMAT(s.expected_pickup, '%Y-%m-%d') AS expected_pickup,
         DATE_FORMAT(s.expected_delivery, '%Y-%m-%d') AS expected_delivery,
         s.contact_name, s.contact_phone, s.notes,
         COALESCE(CONCAT(o.code,' - ',o.name), '—') AS origin,
         COALESCE(o.address, '') AS origin_address,
         COALESCE(CONCAT(d.code,' - ',d.name), '—') AS destination,
         COALESCE(d.address, '') AS destination_address,
         s.created_at
    FROM shipments s
    LEFT JOIN warehouse_locations o ON o.id=s.origin_id
    LEFT JOIN warehouse_locations d ON d.id=s.destination_id
    $where
   ORDER BY s.id DESC
";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Only pending requests by default (Ready)
$pending = array_values(array_filter($rows, function($r){
  return (string)($r['status'] ?? '') === 'Ready';
}));

echo json_encode([
  "ok" => true,
  "status" => $status,
  "count" => count($rows),
  "pending_count" => count($pending),
  "rows" => $rows
]);
