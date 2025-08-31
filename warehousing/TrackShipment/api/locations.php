<?php
require_once __DIR__ . "/../../../includes/config.php";
require_once __DIR__ . "/../../../includes/auth.php";
require_login();
header("Content-Type: application/json");

try {
    try {
        $stmt = $pdo->query(
            'SELECT id, CONCAT_WS(" - ", code, name) AS name FROM warehouse_locations ORDER BY name'
        );
    } catch (PDOException $e) {
        $stmt = $pdo->query(
            "SELECT id, name FROM warehouse_locations ORDER BY name"
        );
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        echo json_encode([
            "ok" => true,
            "rows" => [],
            "empty" => true,
            "msg" => "No locations found",
        ]);
        exit();
    }

    echo json_encode($rows);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["ok" => false, "err" => "Failed to load locations"]);
}
