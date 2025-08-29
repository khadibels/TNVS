<?php
require_once __DIR__ . "/../../includes/config.php";
require_once __DIR__ . "/../../includes/auth.php";
require_login();
header("Content-Type: application/json");

$name = trim($_POST["name"] ?? "");
if ($name === "") {
    http_response_code(422);
    echo json_encode(["error" => "Name is required"]);
    exit();
}

$st = $pdo->prepare(
    "SELECT id FROM warehouse_locations WHERE name = ? LIMIT 1"
);
$st->execute([$name]);
$id = (int) ($st->fetchColumn() ?? 0);

if (!$id) {
    $code = strtoupper(
        preg_replace("/[^A-Z0-9]+/i", "_", substr($name, 0, 20))
    );
    if ($code === "") {
        $code = "LOC" . mt_rand(10000, 99999);
    }
    $pdo->prepare(
        "INSERT INTO warehouse_locations (code, name) VALUES (?,?)"
    )->execute([$code, $name]);
    $id = (int) $pdo->lastInsertId();
}
echo json_encode(["ok" => true, "id" => $id, "name" => $name]);
