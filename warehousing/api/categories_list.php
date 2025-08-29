<?php
require_once __DIR__ . "/../../includes/config.php";
require_once __DIR__ . "/../../includes/auth.php";
require_login("json");

header("Content-Type: application/json");
$id = (int) ($_GET["id"] ?? 0);

try {
    if ($id > 0) {
        $st = $pdo->prepare(
            "SELECT id, code, name, description, active FROM inventory_categories WHERE id=?"
        );
        $st->execute([$id]);
        echo json_encode($st->fetchAll());
    } else {
        $st = $pdo->query(
            "SELECT id, code, name, description, active FROM inventory_categories ORDER BY name"
        );
        echo json_encode($st->fetchAll());
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["ok" => false, "error" => "SERVER_ERROR"]);
}
