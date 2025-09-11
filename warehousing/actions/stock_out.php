<?php
require_once __DIR__ . "/../../includes/config.php";
require_once __DIR__ . "/../../includes/auth.php";
require_once __DIR__ . "/../../includes/db.php";
require_once __DIR__ . "/../../includes/stock_helpers.php";
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_login();

$pdo = db('wms');

$itemId = (int) ($_POST["item_id"] ?? 0);
$locId = (int) ($_POST["location_id"] ?? 0);
$qty = (int) ($_POST["qty"] ?? 0);
$note = trim($_POST["note"] ?? "");
$userId = $_SESSION["user"]["id"] ?? null;

$back = "../stockmanagement/stockLevelManagement.php";

try {
    stock_out($pdo, $itemId, $locId, $qty, $note, $userId);

    header("Location: " . $back . "?ok=" . urlencode("Stock deducted"));
    exit();
} catch (Throwable $e) {
    header("Location: " . $back . "?err=" . urlencode($e->getMessage()));
    exit();
}
