<?php
require_once __DIR__ . "/../../../includes/config.php";
require_once __DIR__ . "/../../../includes/db.php";
header("Content-Type: application/json; charset=utf-8");

function log2_auth_ok(): bool {
  if (!defined('LOG2_API_KEY') || LOG2_API_KEY === '') return true;
  $key = $_SERVER['HTTP_X_LOG2_KEY'] ?? ($_POST['key'] ?? ($_GET['key'] ?? ''));
  return hash_equals((string)LOG2_API_KEY, (string)$key);
}

if (!log2_auth_ok()) {
  http_response_code(401);
  echo json_encode(["ok" => false, "err" => "Unauthorized"]);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(["ok"=>false,"err"=>"POST required"]);
  exit;
}

$pdo = db('wms');
if (!$pdo instanceof PDO) { http_response_code(500); echo json_encode(["ok"=>false,"err"=>"DB not available"]); exit; }
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$id = (int)($_POST['id'] ?? 0);
$ref = trim((string)($_POST['ref_no'] ?? ''));
$status = trim((string)($_POST['status'] ?? ''));
$details = trim((string)($_POST['details'] ?? ''));

$allowed = ['Draft','Ready','Dispatched','In Transit','Delivered','Delayed','Cancelled','Returned'];
if (!in_array($status, $allowed, true)) {
  http_response_code(400);
  echo json_encode(["ok"=>false,"err"=>"Invalid status"]);
  exit;
}

if ($id <= 0 && $ref === '') {
  http_response_code(400);
  echo json_encode(["ok"=>false,"err"=>"Missing id or ref_no"]);
  exit;
}

try {
  if ($id <= 0 && $ref !== '') {
    $st = $pdo->prepare("SELECT id FROM shipments WHERE ref_no=? LIMIT 1");
    $st->execute([$ref]);
    $id = (int)$st->fetchColumn();
  }
  if ($id <= 0) throw new Exception('Shipment not found');

  $pdo->beginTransaction();
  $u = $pdo->prepare("UPDATE shipments SET status=? WHERE id=?");
  $u->execute([$status, $id]);

  $e = $pdo->prepare("INSERT INTO shipment_events (shipment_id, event_type, details, user_id) VALUES (?,?,?,?)");
  $e->execute([$id, $status, $details ?: null, null]);
  $pdo->commit();

  echo json_encode(["ok"=>true, "id"=>$id, "status"=>$status]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(400);
  echo json_encode(["ok"=>false,"err"=>$e->getMessage()]);
}
