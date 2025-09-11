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


function table_exists(PDO $pdo, string $name): bool
{
    $st = $pdo->prepare("SELECT 1 FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = ?");
    $st->execute([$name]);
    return (bool) $st->fetchColumn();
}
function column_exists(PDO $pdo, string $t, string $c): bool
{
    $st = $pdo->prepare("SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $st->execute([$t, $c]);
    return (bool) $st->fetchColumn();
}
function first_column(PDO $pdo, string $t, array $cands): ?string
{
    foreach ($cands as $c) {
        if (column_exists($pdo, $t, $c)) {
            return $c;
        }
    }
    return null;
}

try {
    $rfqId = (int) ($_GET["rfq_id"] ?? 0);
    if ($rfqId <= 0) {
        echo json_encode([]);
        exit();
    }

    $quotesTotalCol = first_column($pdo, "quotes", [
        "total_amount",
        "total",
        "grand_total",
        "total_cache",
    ]);
    $quotesTotalExpr = $quotesTotalCol ? "q.`$quotesTotalCol`" : null;

    $submittedCol = first_column($pdo, "quotes", [
        "submitted_at",
        "created_at",
        "updated_at",
    ]);
    $submittedExpr = $submittedCol ? "q.`$submittedCol`" : "NULL";
    $hasIsFinal = column_exists($pdo, "quotes", "is_final");

    $leadExpr = column_exists($pdo, "quotes", "lead_time_days")
        ? "q.lead_time_days"
        : (column_exists($pdo, "suppliers", "lead_time_days")
            ? "s.lead_time_days"
            : "NULL");

    $ratingExpr = column_exists($pdo, "suppliers", "rating")
        ? "s.rating"
        : "NULL";

    $joinItems = "";
    if (!$quotesTotalExpr && table_exists($pdo, "quote_items")) {
        $line = first_column($pdo, "quote_items", [
            "line_total",
            "amount",
            "total_amount",
            "total",
        ]);
        $qty = first_column($pdo, "quote_items", [
            "quantity",
            "qty",
            "qty_ordered",
            "qty_approved",
        ]);
        $price = first_column($pdo, "quote_items", [
            "unit_price",
            "price",
            "unit_cost",
            "rate",
            "unitrate",
        ]);
        $fk = first_column($pdo, "quote_items", [
            "quote_id",
            "q_id",
            "quotes_id",
            "quoteId",
        ]);

        $itemTotalExpr = $line
            ? "SUM(COALESCE(`$line`,0))"
            : ($qty && $price
                ? "SUM(COALESCE(`$qty`,0)*COALESCE(`$price`,0))"
                : "0");

        if ($fk) {
            $joinItems = "LEFT JOIN (
        SELECT `$fk` AS qi_fk, $itemTotalExpr AS item_total
        FROM quote_items GROUP BY `$fk`
      ) t ON t.qi_fk = q.id";
        }
    }

    $totalExpr = $quotesTotalExpr ?: ($joinItems ? "t.item_total" : "0");

    $where = "WHERE q.rfq_id = :rfq";
    if ($hasIsFinal) {
        $where .= " AND COALESCE(q.is_final,1) = 1";
    }

    $sql = "SELECT
            q.supplier_id,
            s.name AS supplier_name,
            CAST(COALESCE($totalExpr,0) AS DECIMAL(18,2)) AS total,
            $leadExpr   AS lead_time_days,
            $ratingExpr AS rating,
            $submittedExpr AS submitted_at
          FROM quotes q
          JOIN suppliers s ON s.id = q.supplier_id
          $joinItems
          $where
          ORDER BY total ASC, submitted_at ASC";
    $st = $pdo->prepare($sql);
    $st->execute([":rfq" => $rfqId]);
    echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
