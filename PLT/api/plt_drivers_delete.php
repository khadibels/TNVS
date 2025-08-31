<?php
require_once __DIR__ . "/../../includes/config.php";
header("Content-Type: application/json");

$id = (int) ($_POST["id"] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid id"]);
    exit();
}

try {
    $pdo->prepare("DELETE FROM plt_drivers WHERE id=?")->execute([$id]);
    echo json_encode(["ok" => true]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(["error" => $e->getMessage()]);
}
