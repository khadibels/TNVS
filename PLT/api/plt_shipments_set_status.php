<?php
require_once __DIR__ . "/../../includes/config.php";
require_once __DIR__ . "/../../includes/auth.php";
require_login();

header("Content-Type: application/json; charset=utf-8");

$id = (int) ($_POST["id"] ?? 0);
$status = strtolower(trim($_POST["status"] ?? ""));

if (!$id || $status === "") {
    http_response_code(400);
    echo json_encode(["error" => "Missing id or status"]);
    exit();
}

try {
    if ($status === "delivered") {
        $sql = "UPDATE plt_shipments
            SET status = 'delivered',
                delivered_at = COALESCE(delivered_at, NOW())
            WHERE id = :id";
        $pdo->prepare($sql)->execute([":id" => $id]);
    } else {
        $sql = "UPDATE plt_shipments
            SET status = :st,
                delivered_at = CASE WHEN :st <> 'delivered' THEN NULL ELSE delivered_at END
            WHERE id = :id";
        $pdo->prepare($sql)->execute([":id" => $id, ":st" => $status]);
    }

    echo json_encode(["ok" => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
