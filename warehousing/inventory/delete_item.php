<?php

require_once __DIR__ . "/../../includes/config.php";
require_once __DIR__ . "/../../includes/auth.php";
require_login();
header("Content-Type: application/json");
require_role(['admin', 'manager']);

$id = (int) ($_POST["id"] ?? 0);
if ($id <= 0) {
    http_response_code(422);
    echo json_encode(["success" => false, "error" => "Invalid id"]);
    exit();
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(qty),0) FROM stock_levels WHERE item_id = :id"
    );
    $stmt->execute([":id" => $id]);
    $stock = (int) $stmt->fetchColumn();
    if ($stock !== 0) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode([
            "success" => false,
            "error" => "Cannot delete: stock is not zero.",
        ]);
        exit();
    }

    $pdo->prepare("DELETE FROM stock_levels WHERE item_id = :id")->execute([
        ":id" => $id,
    ]);

    $pdo->prepare(
        "DELETE FROM stock_transactions WHERE item_id = :id"
    )->execute([":id" => $id]);

    $stmt = $pdo->prepare("DELETE FROM inventory_items WHERE id = :id");
    $stmt->execute([":id" => $id]);
    if ($stmt->rowCount() === 0) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(["success" => false, "error" => "Item not found"]);
        exit();
    }

    $pdo->commit();
    echo json_encode(["success" => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "DB error"]);
}
