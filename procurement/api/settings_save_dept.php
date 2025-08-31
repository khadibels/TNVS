<?php
declare(strict_types=1);
$inc = __DIR__ . "/../../includes";
if (file_exists($inc . "/config.php")) {
    require_once $inc . "/config.php";
}
if (file_exists($inc . "/auth.php")) {
    require_once $inc . "/auth.php";
}
if (function_exists("require_login")) {
    require_login();
}

header("Content-Type: application/json; charset=utf-8");

try {
    if (!isset($pdo)) {
        throw new Exception("DB missing");
    }

    if (!empty($_POST["delete"]) && isset($_POST["id"])) {
        $id = (int) $_POST["id"];
        $pdo->prepare("DELETE FROM departments WHERE id=?")->execute([$id]);
        echo json_encode(["ok" => 1, "deleted" => $id]);
        exit();
    }

    $id = (int) ($_POST["id"] ?? 0);
    $name = trim((string) ($_POST["name"] ?? ""));
    $active = isset($_POST["is_active"]) ? (int) $_POST["is_active"] : 1;

    if ($name === "") {
        throw new Exception("Name required");
    }

    if ($id > 0) {
        $st = $pdo->prepare(
            "UPDATE departments SET name=?, is_active=? WHERE id=?"
        );
        $st->execute([$name, $active, $id]);
        echo json_encode(["ok" => 1, "id" => $id, "mode" => "update"]);
    } else {
        $st = $pdo->prepare(
            "INSERT INTO departments (name,is_active) VALUES (?,?)"
        );
        $st->execute([$name, $active]);
        echo json_encode([
            "ok" => 1,
            "id" => $pdo->lastInsertId(),
            "mode" => "insert",
        ]);
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(["error" => $e->getMessage()]);
}
