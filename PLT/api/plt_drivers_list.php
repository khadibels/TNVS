<?php
require_once __DIR__ . "/../../includes/config.php";
header("Content-Type: application/json");

$page = max(1, (int) ($_GET["page"] ?? 1));
$per = max(1, min(100, (int) ($_GET["per_page"] ?? 10)));
$search = trim($_GET["search"] ?? "");
$onlyActive =
    isset($_GET["active"]) && $_GET["active"] !== ""
        ? (int) $_GET["active"]
        : null;

$where = [];
$bind = [];
if ($search !== "") {
    $where[] = "(name LIKE :q OR phone LIKE :q OR license_no LIKE :q)";
    $bind[":q"] = "%$search%";
}
if ($onlyActive !== null) {
    $where[] = "active=:a";
    $bind[":a"] = $onlyActive;
}
$whereSQL = $where ? "WHERE " . implode(" AND ", $where) : "";

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM plt_drivers $whereSQL");
    $stmt->execute($bind);
    $total = (int) $stmt->fetchColumn();

    $off = ($page - 1) * $per;
    $sql = "SELECT id, name, phone, license_no, notes, active
        FROM plt_drivers
        $whereSQL
        ORDER BY id DESC
        LIMIT :lim OFFSET :off";
    $stmt = $pdo->prepare($sql);
    foreach ($bind as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(":lim", $per, PDO::PARAM_INT);
    $stmt->bindValue(":off", $off, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        "data" => $rows,
        "pagination" => ["page" => $page, "perPage" => $per, "total" => $total],
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(["error" => $e->getMessage()]);
}
