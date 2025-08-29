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
    $new = $_POST["status"] ?? "";
    if ($id <= 0) {
        bad("id required");
    }

    $allowedAll = [
        "draft",
        "approved",
        "ordered",
        "partially_received",
        "received",
        "closed",
        "cancelled",
    ];
    if (!in_array($new, $allowedAll, true)) {
        bad("invalid status");
    }

    $pdo->beginTransaction();
    $st = $pdo->prepare(
        "SELECT status FROM purchase_orders WHERE id=? FOR UPDATE"
    );
    $st->execute([$id]);
    $cur = $st->fetchColumn();
    if ($cur === false) {
        $pdo->rollBack();
        bad("PO not found", 404);
    }

    $allowed = [
        "draft" => ["approved", "cancelled"],
        "approved" => ["ordered", "cancelled"],
        "ordered" => ["partially_received", "received", "cancelled"],
        "partially_received" => ["received", "cancelled"],
        "received" => ["closed"],
        "closed" => [],
        "cancelled" => [],
    ];
    if (!in_array($new, $allowed[$cur] ?? [], true)) {
        $pdo->rollBack();
        bad("Cannot change status from $cur to $new", 409);
    }

    $pdo->prepare("UPDATE purchase_orders SET status=? WHERE id=?")->execute([
        $new,
        $id,
    ]);
    $pdo->commit();
    echo json_encode(["ok" => true]);
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    bad("server_error: " . $e->getMessage(), 500);
}
