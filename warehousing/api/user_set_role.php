<?php
require_once __DIR__ . "/../../includes/config.php";
require_once __DIR__ . "/../../includes/auth.php";
require_role(["admin"], "json");

header("Content-Type: application/json");

$id = (int) ($_POST["id"] ?? 0);
$role = trim($_POST["role"] ?? "");

$allowed = ["admin", "manager", "staff", "viewer", "procurement"];
if ($id <= 0 || !in_array($role, $allowed, true)) {
    http_response_code(422);
    echo json_encode(["ok" => false, "error" => "INVALID_INPUT"]);
    exit();
}

try {
    
    $me = current_user();
    if ($me && (int) $me["id"] === $id && $role !== "admin") {
        http_response_code(409);
        echo json_encode(["ok" => false, "error" => "CANNOT_DEMOTE_SELF"]);
        exit();
    }

    $st = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
    $st->execute([$role, $id]);

    echo json_encode(["ok" => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["ok" => false, "error" => "SERVER_ERROR"]);
}
