<?php

require_once __DIR__ . "/../../includes/config.php";
require_once __DIR__ . "/../../includes/auth.php";
require_once __DIR__ . "/../../includes/db.php";
require_login();
require_role(['admin', 'manager']);
header("Content-Type: application/json");

$pdo = db('wms');

$id = (int) ($_POST["id"] ?? 0);
if ($id <= 0) {
    http_response_code(422);
    echo json_encode(["success" => false, "error" => "Invalid id"]);
    exit();
}

try {
    $stmt = $pdo->prepare(
        "UPDATE inventory_items SET archived = 0, archived_at = NULL WHERE id = :id"
    );
    $stmt->execute([":id" => $id]);
    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(["success" => false, "error" => "Item not found"]);
        exit();
    }
    echo json_encode(["success" => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "DB error"]);
}
