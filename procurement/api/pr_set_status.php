<?php
declare(strict_types=1);
require_once __DIR__ . "/../../includes/config.php";
require_once __DIR__ . "/../../includes/auth.php";
require_login();
header("Content-Type: application/json; charset=utf-8");

function bad($m, $c = 400)
{
    http_response_code($c);
    echo json_encode(["error" => $m]);
    exit();
}

$id = (int) ($_POST["id"] ?? 0);
$status = strtolower(trim($_POST["status"] ?? ""));
$allowed = [
    "draft",
    "submitted",
    "approved",
    "rejected",
    "fulfilled",
    "cancelled",
];
if ($id <= 0) {
    bad("missing_id");
}
if (!in_array($status, $allowed, true)) {
    bad("invalid_status");
}

$st = $pdo->prepare(
    "UPDATE procurement_requests SET status=?, updated_at=NOW() WHERE id=?"
);
$st->execute([$status, $id]);
if ($st->rowCount() < 1) {
    bad("not_found", 404);
}
echo json_encode(["ok" => 1]);
