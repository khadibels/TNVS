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


$from = $_GET["from"] ?? "";
$to = $_GET["to"] ?? "";
$dept = $_GET["dept"] ?? "";
$cat = $_GET["cat"] ?? "";

$rangeWhere = [];
$rangeP = [];
if ($from !== "") {
    $rangeWhere[] = "po.order_date >= ?";
    $rangeP[] = $from;
}
if ($to !== "") {
    $rangeWhere[] = "po.order_date <= ?";
    $rangeP[] = $to;
}
if ($dept !== "") {
    $rangeWhere[] = "pr.department_id = ?";
    $rangeP[] = (int) $dept;
}
$poWhere = $rangeWhere ? "WHERE " . implode(" AND ", $rangeWhere) : "";

try {
    $st = $pdo->prepare(
        "
    SELECT COALESCE(SUM(po.total),0) AS spend
    FROM purchase_orders po
    LEFT JOIN procurement_requests pr ON pr.id=po.pr_id
    $poWhere
      " .
            ($poWhere ? " AND " : " WHERE ") .
            " po.status IN ('ordered','partially_received','received','closed')
  "
    );
    $st->execute($rangeP);
    $spendTotal = (float) ($st->fetchColumn() ?: 0);

    $bWhere = [];
    $bP = [];
    if ($dept !== "") {
        $bWhere[] = "(b.department_id = ?)";
        $bP[] = (int) $dept;
    }
    if ($cat !== "") {
        $bWhere[] = "(c.name = ?)";
        $bP[] = $cat;
    }
    if ($from !== "" || $to !== "") {
        $yFrom = $from !== "" ? (int) substr($from, 0, 4) : null;
        $yTo = $to !== "" ? (int) substr($to, 0, 4) : null;
        if ($yFrom !== null && $yTo !== null) {
            $bWhere[] = "b.fiscal_year BETWEEN ? AND ?";
            $bP[] = $yFrom;
            $bP[] = $yTo;
        } elseif ($yFrom !== null) {
            $bWhere[] = "b.fiscal_year >= ?";
            $bP[] = $yFrom;
        } elseif ($yTo !== null) {
            $bWhere[] = "b.fiscal_year <= ?";
            $bP[] = $yTo;
        }
    }
    $sqlBWhere = $bWhere ? "WHERE " . implode(" AND ", $bWhere) : "";
    $st = $pdo->prepare("
    SELECT COALESCE(SUM(b.amount),0) AS budget
    FROM budgets b
    LEFT JOIN inventory_categories c ON c.id=b.category_id
    $sqlBWhere
  ");
    $st->execute($bP);
    $budgetTotal = (float) ($st->fetchColumn() ?: 0);

    $util =
        $budgetTotal > 0 ? round(($spendTotal / $budgetTotal) * 100, 1) : 0.0;

    $st = $pdo->prepare(
        "
    SELECT d.name AS department,
           COALESCE(SUM(b.amount),0) AS budget,
           0 AS spend
    FROM budgets b
    LEFT JOIN departments d ON d.id=b.department_id
    " .
            ($cat !== ""
                ? " LEFT JOIN inventory_categories c ON c.id=b.category_id "
                : "") .
            "
    " .
            ($dept !== "" ? " WHERE b.department_id = ? " : " ") .
            "
    " .
            ($cat !== ""
                ? ($dept !== "" ? " AND " : " WHERE ") . " c.name = ? "
                : "") .
            "
    GROUP BY department
    ORDER BY department
  "
    );
    $bp = [];
    if ($dept !== "") {
        $bp[] = (int) $dept;
    }
    if ($cat !== "") {
        $bp[] = $cat;
    }
    $st->execute($bp);
    $byDeptBudget = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $pdo->prepare(
        "
    SELECT d.name AS department, COALESCE(SUM(po.total),0) AS spend
    FROM purchase_orders po
    LEFT JOIN procurement_requests pr ON pr.id=po.pr_id
    LEFT JOIN departments d ON d.id=pr.department_id
    $poWhere
      " .
            ($poWhere ? " AND " : " WHERE ") .
            " po.status IN ('ordered','partially_received','received','closed')
    GROUP BY department
    ORDER BY department
  "
    );
    $st->execute($rangeP);
    $byDeptSpend = $st->fetchAll(PDO::FETCH_KEY_PAIR); // department => spend

    $byDept = [];
    foreach ($byDeptBudget as $r) {
        $name = $r["department"] ?? "-";
        $bud = (float) $r["budget"];
        $sp = (float) ($byDeptSpend[$name] ?? 0);
        $byDept[] = ["department" => $name, "budget" => $bud, "spend" => $sp];
    }

    $st = $pdo->prepare(
        "
    SELECT c.name AS category, COALESCE(SUM(b.amount),0) AS budget
    FROM budgets b
    LEFT JOIN inventory_categories c ON c.id=b.category_id
    " .
            ($dept !== "" ? " WHERE b.department_id = ? " : " ") .
            "
    " .
            ($cat !== ""
                ? ($dept !== "" ? " AND " : " WHERE ") . " c.name = ? "
                : "") .
            "
    GROUP BY category
    ORDER BY category
  "
    );
    $bp = [];
    if ($dept !== "") {
        $bp[] = (int) $dept;
    }
    if ($cat !== "") {
        $bp[] = $cat;
    }
    $st->execute($bp);
    $byCatBudget = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $pdo->prepare(
        "
    SELECT COALESCE(ii.category,'-') AS category, COALESCE(SUM(
      COALESCE(poi.qty, poi.qty_ordered) * COALESCE(poi.price, poi.unit_cost)
    ),0) AS spend
    FROM purchase_orders po
    JOIN purchase_order_items poi ON poi.po_id=po.id
    LEFT JOIN inventory_items ii ON ii.id=poi.item_id
    LEFT JOIN procurement_requests pr ON pr.id=po.pr_id
    $poWhere
      " .
            ($poWhere ? " AND " : " WHERE ") .
            " po.status IN ('ordered','partially_received','received','closed')
      " .
            ($cat !== "" ? " AND ii.category = ? " : "") .
            "
    GROUP BY category
    ORDER BY category
  "
    );
    $rp = $rangeP;
    if ($cat !== "") {
        $rp[] = $cat;
    }
    $st->execute($rp);
    $byCatSpend = $st->fetchAll(PDO::FETCH_KEY_PAIR);

    $byCat = [];
    foreach ($byCatBudget as $r) {
        $name = $r["category"] ?? "-";
        $bud = (float) $r["budget"];
        $sp = (float) ($byCatSpend[$name] ?? 0);
        $byCat[] = ["category" => $name, "budget" => $bud, "spend" => $sp];
    }

    echo json_encode([
        "totals" => [
            "budget" => $budgetTotal,
            "spend" => $spendTotal,
            "utilization" => $util,
        ],
        "by_dept" => $byDept,
        "by_cat" => $byCat,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["error" => "server_error: " . $e->getMessage()]);
}
