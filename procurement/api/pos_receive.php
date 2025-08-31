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

try {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        bad("POST required");
    }
    $po_id = (int) ($_POST["po_id"] ?? 0);
    if ($po_id <= 0) {
        bad("po_id required");
    }

    // Expect: items[<po_item_id>][qty] = number to receive now
    $items = $_POST["items"] ?? [];
    if (!is_array($items) || !$items) {
        bad("no items passed");
    }

    $pdo->beginTransaction();

    // Lock header & guard status
    $st = $pdo->prepare(
        "SELECT status FROM purchase_orders WHERE id=? FOR UPDATE"
    );
    $st->execute([$po_id]);
    $cur = $st->fetchColumn();
    if ($cur === false) {
        $pdo->rollBack();
        bad("PO not found", 404);
    }
    $cur = strtolower((string) $cur);
    if (in_array($cur, ["closed", "cancelled"], true)) {
        $pdo->rollBack();
        bad("PO is $cur", 409);
    }

    // Lock items (single proper fetch, no FETCH_KEY_PAIR)
    $st = $pdo->prepare("
    SELECT id, qty, COALESCE(qty_received,0) AS qty_received
    FROM purchase_order_items
    WHERE po_id=? FOR UPDATE
  ");
    $st->execute([$po_id]);
    $lines = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$lines) {
        $pdo->rollBack();
        bad("No PO items", 409);
    }

    $byId = [];
    foreach ($lines as $r) {
        $byId[(int) $r["id"]] = $r;
    }

    $upd = $pdo->prepare("
    UPDATE purchase_order_items
    SET qty_received = LEAST(qty, COALESCE(qty_received,0) + ?)
    WHERE id=? AND po_id=?
  ");

    $applied = 0;
    foreach ($items as $pid => $data) {
        $pid = (int) $pid;
        if (!isset($byId[$pid])) {
            continue;
        }
        $delta = (float) ($data["qty"] ?? 0);
        if ($delta <= 0) {
            continue;
        }

        $upd->execute([$delta, $pid, $po_id]);
        $applied++;
    }
    if ($applied === 0) {
        $pdo->rollBack();
        bad("No valid receive lines");
    }

    // Recompute status
    $chk = $pdo->prepare("
    SELECT SUM(qty) AS q, SUM(COALESCE(qty_received,0)) AS r
    FROM purchase_order_items
    WHERE po_id=?
  ");
    $chk->execute([$po_id]);
    $tot = $chk->fetch(PDO::FETCH_ASSOC);
    $newStatus =
        (float) $tot["r"] >= (float) $tot["q"]
            ? "received"
            : "partially_received";

    $pdo->prepare("UPDATE purchase_orders SET status=? WHERE id=?")->execute([
        $newStatus,
        $po_id,
    ]);

    $pdo->commit();
    echo json_encode([
        "ok" => true,
        "status" => $newStatus,
        "lines_updated" => $applied,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    bad("server_error: " . $e->getMessage(), 500);
}
