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


$id = (int) ($_GET["id"] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(["error" => "missing_id"]);
    exit();
}

$h = $pdo->prepare("SELECT * FROM procurement_requests WHERE id=?");
$h->execute([$id]);
$header = $h->fetch(PDO::FETCH_ASSOC);
if (!$header) {
    http_response_code(404);
    echo json_encode(["error" => "not_found"]);
    exit();
}

$i = $pdo->prepare(
    "SELECT id, descr, qty, price, line_total FROM procurement_request_items WHERE pr_id=? ORDER BY id"
);
$i->execute([$id]);
$items = $i->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(["header" => $header, "items" => $items]);
