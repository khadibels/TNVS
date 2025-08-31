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

    $rfq_id = (int) ($_POST["rfq_id"] ?? 0);
    $supplier_id = (int) ($_POST["supplier_id"] ?? 0);
    if ($rfq_id <= 0 || $supplier_id <= 0) {
        bad("rfq_id and supplier_id required");
    }

    $lead_time_days = (int) ($_POST["lead_time_days"] ?? 0);
    $notes = trim($_POST["notes"] ?? "");
    $is_final = (int) ($_POST["is_final"] ?? 1);

    $descrs = $_POST["items"]["descr"] ?? [];
    $qtys = $_POST["items"]["qty"] ?? [];
    $prices = $_POST["items"]["price"] ?? [];

    $items = [];
    $n = max(count($descrs), count($qtys), count($prices));
    for ($i = 0; $i < $n; $i++) {
        $d = trim($descrs[$i] ?? "");
        $q = (float) ($qtys[$i] ?? 0);
        $p = (float) ($prices[$i] ?? 0);
        if ($d === "" || $q <= 0 || $p < 0) {
            continue;
        }
        $items[] = ["descr" => $d, "qty" => $q, "price" => $p];
    }

    $pdo->beginTransaction();

    $st = $pdo->prepare("
    INSERT INTO quotes (rfq_id, supplier_id, lead_time_days, notes, is_final, submitted_at, total_amount)
    VALUES (?, ?, ?, ?, ?, NOW(), 0)
  ");
    $st->execute([
        $rfq_id,
        $supplier_id,
        $lead_time_days ?: null,
        $notes,
        $is_final ? 1 : 0,
    ]);
    $quote_id = (int) $pdo->lastInsertId();

    $total = 0.0;
    if ($items) {
        $ins = $pdo->prepare("INSERT INTO quote_items (quote_id, descr, qty, unit_price, line_total)
                          VALUES (?,?,?,?,?)");
        foreach ($items as $it) {
            $line = $it["qty"] * $it["price"];
            $ins->execute([
                $quote_id,
                $it["descr"],
                $it["qty"],
                $it["price"],
                $line,
            ]);
            $total += $line;
        }
    } else {
        $total = (float) ($_POST["total_amount"] ?? 0);
    }

    $pdo->prepare("UPDATE quotes SET total_amount=? WHERE id=?")->execute([
        round($total, 2),
        $quote_id,
    ]);

    $pdo->commit();
    echo json_encode([
        "ok" => true,
        "quote_id" => $quote_id,
        "total" => round($total, 2),
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    bad("server_error: " . $e->getMessage(), 500);
}
