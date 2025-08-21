<?php
require_once __DIR__ . "/../../includes/config.php";
require_once __DIR__ . "/../../includes/auth.php";
require_role(["admin", "manager"], "json"); // only admins/managers may write

header("Content-Type: application/json");

$id = (int) ($_POST["id"] ?? 0);
$code = trim($_POST["code"] ?? "");
$name = trim($_POST["name"] ?? "");
$address = trim($_POST["address"] ?? "");

if ($code === "" || $name === "") {
    http_response_code(422);
    echo json_encode([
        "ok" => false,
        "error" => "VALIDATION",
        "fields" => ["code", "name"],
    ]);
    exit();
}

try {
    if ($id > 0) {
        $st = $pdo->prepare(
            "UPDATE warehouse_locations SET code=?, name=?, address=? WHERE id=?"
        );
        $st->execute([$code, $name, $address !== "" ? $address : null, $id]);
    } else {
        $st = $pdo->prepare(
            "INSERT INTO warehouse_locations (code,name,address) VALUES (?,?,?)"
        );
        $st->execute([$code, $name, $address !== "" ? $address : null]);
    }
    echo json_encode(["ok" => true]);
} catch (PDOException $e) {
    if ($e->getCode() === "23000") {
        
        http_response_code(409);
        echo json_encode(["ok" => false, "error" => "DUPLICATE_CODE"]);
    } else {
        http_response_code(500);
        echo json_encode(["ok" => false, "error" => "SERVER_ERROR"]);
    }
}
