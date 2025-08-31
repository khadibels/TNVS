<?php
require_once __DIR__ . "/../../includes/config.php";
require_once __DIR__ . "/../../includes/auth.php";

require_login("json");
header("Content-Type: application/json");

$id = (int) ($_POST["id"] ?? 0);
$name = trim($_POST["name"] ?? "");
$cat = trim($_POST["category"] ?? "");
$reord = (int) ($_POST["reorder_level"] ?? 0);
$loc = trim($_POST["location"] ?? "");

$errors = [];
if ($id <= 0) {
    $errors[] = "Invalid ID";
}
if ($name === "") {
    $errors[] = "Name is required";
}
if ($cat === "") {
    $errors[] = "Category is required";
}
if ($reord < 0) {
    $errors[] = "Reorder must be ≥ 0";
}

if ($cat !== "") {
    $okCat = $pdo->prepare(
        "SELECT 1 FROM inventory_categories WHERE name = ? AND active = 1"
    );
    $okCat->execute([$cat]);
    if (!$okCat->fetchColumn()) {
        $errors[] = "Invalid category";
    }
}

if ($errors) {
    http_response_code(422);
    echo json_encode(["errors" => $errors]);
    exit();
}

try {
    $stmt = $pdo->prepare("
    UPDATE inventory_items
       SET name = :name,
           category = :cat,           -- still storing the NAME for now
           reorder_level = :reord,
           location = :loc
     WHERE id = :id
  ");
    $stmt->execute([
        ":name" => $name,
        ":cat" => $cat,
        ":reord" => $reord,
        ":loc" => $loc !== "" ? $loc : null,
        ":id" => $id,
    ]);

    if ($loc !== "") {
        $check = $pdo->prepare(
            "SELECT id FROM warehouse_locations WHERE name = ? LIMIT 1"
        );
        $check->execute([$loc]);
        if (!$check->fetchColumn()) {
            $code = strtoupper(
                preg_replace("/[^A-Z0-9]+/i", "_", substr($loc, 0, 20))
            );
            if ($code === "") {
                $code = "LOC" . mt_rand(10000, 99999);
            }
            $pdo->prepare(
                "INSERT INTO warehouse_locations (code, name) VALUES (?, ?)"
            )->execute([$code, $loc]);
        }
    }

    echo json_encode(["ok" => true]);
} catch (PDOException $e) {
    http_response_code(400);
    echo json_encode(["error" => "Database error"]);
}
