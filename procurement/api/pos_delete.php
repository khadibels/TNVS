<?php
declare(strict_types=1);
require_once __DIR__ . "/../../includes/config.php";
require_once __DIR__ . "/../../includes/auth.php";
require_login();
header("Content-Type: application/json; charset=utf-8");

function bad($m, $c = 400)
{
    http_response_code($c);
    echo json_encode(["error" => $m]);
    exit();
}

try {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        bad("POST required");
    }
    $id = (int) ($_POST["id"] ?? 0);
    if ($id <= 0) {
        bad("id required");
    }

    $pdo->beginTransaction();
    $st = $pdo->prepare(
        "SELECT status FROM purchase_orders WHERE id=? FOR UPDATE"
    );
    $st->execute([$id]);
    $status = $st->fetchColumn();
    if ($status === false) {
        $pdo->rollBack();
        bad("PO not found", 404);
    }
    if (strtolower($status) !== "draft") {
        $pdo->rollBack();
        bad("Only draft POs can be deleted", 409);
    }

    $pdo->prepare("DELETE FROM purchase_order_items WHERE po_id=?")->execute([
        $id,
    ]);
    $pdo->prepare("DELETE FROM purchase_orders WHERE id=?")->execute([$id]);
    $pdo->commit();

    echo json_encode(["ok" => true]);
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    bad("server_error: " . $e->getMessage(), 500);
}
