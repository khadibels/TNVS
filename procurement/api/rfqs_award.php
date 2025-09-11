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


function bad(string $m, int $code = 400)
{
    http_response_code($code);
    echo json_encode(["error" => $m]);
    exit();
}

function hasCol(PDO $pdo, string $tbl, string $col): bool
{
    $st = $pdo->prepare("SELECT 1
                       FROM information_schema.columns
                      WHERE table_schema = DATABASE()
                        AND table_name   = ?
                        AND column_name  = ?");
    $st->execute([$tbl, $col]);
    return (bool) $st->fetchColumn();
}
function hasTable(PDO $pdo, string $tbl): bool
{
    $st = $pdo->prepare("SELECT 1
                       FROM information_schema.tables
                      WHERE table_schema = DATABASE()
                        AND table_name   = ?");
    $st->execute([$tbl]);
    return (bool) $st->fetchColumn();
}
function gen_po_no(PDO $pdo): string
{
    $prefix = "PO-" . date("Ym") . "-";
    $st = $pdo->prepare("SELECT po_no
                         FROM purchase_orders
                        WHERE po_no LIKE ?
                        ORDER BY po_no DESC
                        LIMIT 1");
    $st->execute([$prefix . "%"]);
    $last = $st->fetchColumn();
    $n = 1;
    if ($last && preg_match('/-(\d{4})$/', (string) $last, $m)) {
        $n = (int) $m[1] + 1;
    }
    return $prefix . str_pad((string) $n, 4, "0", STR_PAD_LEFT);
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

    // basic existence checks
    if (!hasTable($pdo, "rfqs")) {
        bad("Missing rfqs table", 500);
    }
    if (!hasTable($pdo, "quotes")) {
        bad("Missing quotes table", 500);
    }
    if (!hasTable($pdo, "purchase_orders")) {
        bad("Missing purchase_orders table", 500);
    }
    if (!hasTable($pdo, "purchase_order_items")) {
        bad("Missing purchase_order_items table", 500);
    }

    $pdo->beginTransaction();

    $st = $pdo->prepare(
        "SELECT id, status, due_date FROM rfqs WHERE id=? FOR UPDATE"
    );
    $st->execute([$rfq_id]);
    $rfq = $st->fetch(PDO::FETCH_ASSOC);
    if (!$rfq) {
        $pdo->rollBack();
        bad("RFQ not found", 404);
    }
    $curStatus = strtolower((string) ($rfq["status"] ?? ""));
    if ($curStatus === "awarded") {
        $pdo->rollBack();
        bad("This RFQ is already awarded.", 409);
    }

    $totalCandidates = [
        "total_amount",
        "total",
        "grand_total",
        "total_cache",
        "amount",
        "subtotal",
    ];
    $timeCandidates = ["submitted_at", "updated_at", "created_at"];

    $partsT = [];
    foreach ($totalCandidates as $c) {
        if (hasCol($pdo, "quotes", $c)) {
            $partsT[] = "q.`$c`";
        }
    }
    $totalExpr = $partsT ? "COALESCE(" . implode(", ", $partsT) . ", 0)" : "0";

    $partsW = [];
    foreach ($timeCandidates as $c) {
        if (hasCol($pdo, "quotes", $c)) {
            $partsW[] = "q.`$c`";
        }
    }
    $whenExpr = $partsW
        ? "COALESCE(" . implode(", ", $partsW) . ", NOW())"
        : "NOW()";

    $sqlQ = "SELECT q.id AS quote_id, $totalExpr AS q_total, $whenExpr AS q_when
             FROM quotes q
            WHERE q.rfq_id=? AND q.supplier_id=?
            ORDER BY q_when DESC
            LIMIT 1";
    $st = $pdo->prepare($sqlQ);
    $st->execute([$rfq_id, $supplier_id]);
    $quote = $st->fetch(PDO::FETCH_ASSOC);
    if (!$quote) {
        $pdo->rollBack();
        bad("No quote found for this supplier.", 409);
    }

    // Prepare PO header values
    $po_no = gen_po_no($pdo);
    $today = date("Y-m-d");
    $expected_date = !empty($rfq["due_date"]) ? $rfq["due_date"] : $today;
    $hasPoNumber = hasCol($pdo, "purchase_orders", "po_number"); // legacy dual-column support

    // Insert PO header (status = ordered)
    if ($hasPoNumber) {
        $sql = "INSERT INTO purchase_orders
              (po_number, po_no, supplier_id, order_date, expected_date, status, notes, total)
            VALUES
              (?,         ?,     ?,           ?,          ?,            'ordered', CONCAT('From RFQ #', ?), 0)";
        $pdo->prepare($sql)->execute([
            $po_no,
            $po_no,
            $supplier_id,
            $today,
            $expected_date,
            $rfq_id,
        ]);
    } else {
        $sql = "INSERT INTO purchase_orders
              (po_no, supplier_id, order_date, expected_date, status, notes, total)
            VALUES
              (?,     ?,           ?,          ?,            'ordered', CONCAT('From RFQ #', ?), 0)";
        $pdo->prepare($sql)->execute([
            $po_no,
            $supplier_id,
            $today,
            $expected_date,
            $rfq_id,
        ]);
    }
    $po_id = (int) $pdo->lastInsertId();

    $poTotal = 0.0;

    if (hasTable($pdo, "quote_items")) {
        $cols = $pdo
            ->query("SHOW COLUMNS FROM quote_items")
            ->fetchAll(PDO::FETCH_COLUMN, 0);
        $has = fn(string $c) => in_array($c, $cols, true);

        $qtyCol = $has("quantity") ? "quantity" : ($has("qty") ? "qty" : null);
        $priceCol = $has("unit_price")
            ? "unit_price"
            : ($has("price")
                ? "price"
                : ($has("unit_cost")
                    ? "unit_cost"
                    : null));
        $descCol = $has("description")
            ? "description"
            : ($has("item_name")
                ? "item_name"
                : ($has("name")
                    ? "name"
                    : null));
        $lineCol = $has("line_total")
            ? "line_total"
            : ($has("amount")
                ? "amount"
                : ($has("total")
                    ? "total"
                    : null));

        $st = $pdo->prepare(
            "SELECT * FROM quote_items WHERE quote_id=? ORDER BY id ASC"
        );
        $st->execute([(int) $quote["quote_id"]]);

        $ins = $pdo->prepare(
            "INSERT INTO purchase_order_items (po_id, descr, qty, price) VALUES (?,?,?,?)"
        );
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $descr = $descCol ? (string) ($r[$descCol] ?? "Item") : "Item";
            $qty = $qtyCol ? (float) ($r[$qtyCol] ?? 1) : 1.0;
            $price = $priceCol ? (float) ($r[$priceCol] ?? 0) : 0.0;
            $line = $lineCol
                ? (float) ($r[$lineCol] ?? $qty * $price)
                : $qty * $price;

            $ins->execute([$po_id, $descr, max($qty, 1), $price]);
            $poTotal += $line;
        }

        if ($poTotal <= 0 && isset($quote["q_total"])) {
            $poTotal = (float) $quote["q_total"];
            $pdo->prepare(
                "INSERT INTO purchase_order_items (po_id, descr, qty, price) VALUES (?,?,?,?)"
            )->execute([$po_id, "Awarded total", 1, $poTotal]);
        }
    } else {
        $poTotal = (float) $quote["q_total"];
        $pdo->prepare(
            "INSERT INTO purchase_order_items (po_id, descr, qty, price) VALUES (?,?,?,?)"
        )->execute([$po_id, "Awarded total", 1, $poTotal]);
    }

    $pdo->prepare("UPDATE purchase_orders SET total=? WHERE id=?")->execute([
        round($poTotal, 2),
        $po_id,
    ]);

    if (hasCol($pdo, "rfqs", "awarded_supplier_id")) {
        $pdo->prepare(
            "UPDATE rfqs SET status='awarded', awarded_supplier_id=? WHERE id=?"
        )->execute([$supplier_id, $rfq_id]);
    } else {
        $pdo->prepare("UPDATE rfqs SET status='awarded' WHERE id=?")->execute([
            $rfq_id,
        ]);
    }

    $pdo->commit();
    echo json_encode(["ok" => true, "po_number" => $po_no, "po_id" => $po_id]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    bad("server_error: " . $e->getMessage(), 500);
}
