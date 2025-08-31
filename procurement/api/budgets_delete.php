<?php
declare(strict_types=1);
require_once __DIR__ . "/../../includes/config.php";
require_once __DIR__ . "/../../includes/auth.php";
require_login();
header("Content-Type: application/json; charset=utf-8");

$id = (int) ($_POST["id"] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(["error" => "missing_id"]);
    exit();
}
$pdo->prepare("DELETE FROM budgets WHERE id=?")->execute([$id]);
echo json_encode(["ok" => 1]);
