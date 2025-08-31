<?php
declare(strict_types=1);
require_once __DIR__ . "/../../includes/config.php";
require_once __DIR__ . "/../../includes/auth.php";
require_login();
header("Content-Type: application/json; charset=utf-8");

$id = isset($_POST["id"]) && $_POST["id"] !== "" ? (int) $_POST["id"] : null;
$year = (int) ($_POST["fiscal_year"] ?? 0);
$month = ($_POST["month"] ?? "") === "" ? null : (int) $_POST["month"];
$dept =
    ($_POST["department_id"] ?? "") === ""
        ? null
        : (int) $_POST["department_id"];
$cat =
    ($_POST["category_id"] ?? "") === "" ? null : (int) $_POST["category_id"];
$amount = (float) ($_POST["amount"] ?? 0);
$notes = trim((string) ($_POST["notes"] ?? ""));

if ($year < 2000 || $year > 2100) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid year"]);
    exit();
}
if ($month !== null && ($month < 1 || $month > 12)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid month"]);
    exit();
}

if ($id) {
    $st = $pdo->prepare(
        "UPDATE budgets SET fiscal_year=?, month=?, department_id=?, category_id=?, amount=?, notes=?, updated_at=NOW() WHERE id=?"
    );
    $st->execute([$year, $month, $dept, $cat, $amount, $notes, $id]);
    echo json_encode(["ok" => 1, "id" => $id]);
    exit();
} else {
    $st = $pdo->prepare(
        "INSERT INTO budgets (fiscal_year,month,department_id,category_id,amount,notes) VALUES (?,?,?,?,?,?)"
    );
    $st->execute([$year, $month, $dept, $cat, $amount, $notes]);
    echo json_encode(["ok" => 1, "id" => (int) $pdo->lastInsertId()]);
    exit();
}
