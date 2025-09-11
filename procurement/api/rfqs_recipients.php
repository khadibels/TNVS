<?php
require_once __DIR__ . "/../../includes/config.php";
require_once __DIR__ . "/../../includes/auth.php";
require_once __DIR__ . "/../../includes/db.php";

require_role(['admin','procurement_officer'], 'json');
header('Content-Type: application/json; charset=utf-8');

$pdo = db('proc');
if (!$pdo instanceof PDO) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'err'=>'DB not available']);
  exit;
}


$rfq = (int) ($_GET["rfq_id"] ?? 0);
$stmt = $pdo->prepare("SELECT supplier_id FROM rfq_recipients WHERE rfq_id=?");
$stmt->execute([$rfq]);
echo json_encode(array_map("intval", $stmt->fetchAll(PDO::FETCH_COLUMN, 0)));
