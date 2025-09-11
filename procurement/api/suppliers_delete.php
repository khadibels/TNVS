<?php
require_once __DIR__ . "/../../includes/config.php";
require_once __DIR__ . "/../../includes/auth.php";
require_once __DIR__ . "/../../includes/db.php";

require_role(['admin','procurement_officer'], 'json');

header('Content-Type: application/json; charset=utf-8');

$pdo = db('proc') ?: db('wms');
if (!$pdo instanceof PDO) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'err'=>'DB not available']);
  exit;
}


$id = (int) ($_POST["id"] ?? 0);
$active = isset($_POST["active"]) && $_POST["active"] == "1" ? 1 : 0;

if ($id <= 0) {
    http_response_code(422);
    echo json_encode(["error" => "INVALID_ID"]);
    exit();
}

$pdo->prepare("UPDATE suppliers SET is_active=? WHERE id=?")->execute([
    $active,
    $id,
]);
echo json_encode(["ok" => true]);
exit();
