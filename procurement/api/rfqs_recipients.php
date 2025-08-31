<?php
declare(strict_types=1);
$inc = __DIR__ . "/../../includes";
require_once $inc . "/config.php";
if (file_exists($inc . "/auth.php")) {
    require_once $inc . "/auth.php";
}
if (function_exists("require_login")) {
    require_login();
}
header("Content-Type: application/json; charset=utf-8");

$rfq = (int) ($_GET["rfq_id"] ?? 0);
$stmt = $pdo->prepare("SELECT supplier_id FROM rfq_recipients WHERE rfq_id=?");
$stmt->execute([$rfq]);
echo json_encode(array_map("intval", $stmt->fetchAll(PDO::FETCH_COLUMN, 0)));
