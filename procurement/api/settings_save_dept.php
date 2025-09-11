<?php
require_once __DIR__ . "/../../includes/config.php";
require_once __DIR__ . "/../../includes/auth.php";
require_once __DIR__ . "/../../includes/db.php";

require_role(['admin','procurement_officer'], 'json');

header('Content-Type: application/json; charset=utf-8');

$pdo = db('proc') ?: db('wms');
if (!$pdo instanceof PDO) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'err'=>'DB not available']);
  exit;
}


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
