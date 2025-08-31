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

function table_exists(PDO $pdo, string $name): bool
{
    $st = $pdo->prepare(
        "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?"
    );
    $st->execute([$name]);
    return (bool) $st->fetchColumn();
}
function col_exists(PDO $pdo, string $table, string $col): bool
{
    $st = $pdo->prepare(
        "SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?"
    );
    $st->execute([$table, $col]);
    return (bool) $st->fetchColumn();
}

try {
    // inputs
    $page = max(1, (int) ($_GET["page"] ?? 1));
    $per = max(1, min(100, (int) ($_GET["per_page"] ?? 10)));
    $search = trim($_GET["search"] ?? "");
    $status = trim($_GET["status"] ?? "");
    $sort = $_GET["sort"] ?? "newest";
    $offset = ($page - 1) * $per;

    // detect PR linkage safely
    $hasPrIdCol = col_exists($pdo, "purchase_orders", "pr_id");
    $hasPrTable = $hasPrIdCol && table_exists($pdo, "procurement_requests");
    $prIdFilter = null;
    if ($hasPrIdCol && isset($_GET["pr_id"]) && $_GET["pr_id"] !== "") {
        $prIdFilter = (int) $_GET["pr_id"];
    }

    // where
    $where = [];
    $args = [];

    if ($search !== "") {
        $where[] = "(po.po_no LIKE ? OR po.notes LIKE ?)";
        $like = "%" . $search . "%";
        $args[] = $like;
        $args[] = $like;
    }
    if ($status !== "") {
        $where[] = "po.status = ?";
        $args[] = $status;
    }
    if ($prIdFilter !== null) {
        $where[] = "po.pr_id = ?";
        $args[] = $prIdFilter;
    }
    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

    // sort
    $order = "po.id DESC";
    if ($sort === "due") {
        $order = "po.expected_date IS NULL, po.expected_date ASC, po.id DESC";
    }

    // total
    $st = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders po $whereSql");
    $st->execute($args);
    $total = (int) $st->fetchColumn();

    // select fields (add PR fields only when available)
    $select = "
    SELECT
      po.id,
      po.po_no,
      po.total,
      po.order_date   AS issue_date,
      po.expected_date,
      po.status,
      s.name          AS supplier_name";

    if ($hasPrIdCol) {
        $select .= ", po.pr_id";
    } else {
        $select .= ", NULL AS pr_id";
    }

    if ($hasPrTable) {
        $select .= ", pr.pr_no";
    } else {
        $select .= ", NULL AS pr_no";
    }

    // joins
    $joins = " LEFT JOIN suppliers s ON s.id = po.supplier_id ";
    if ($hasPrTable) {
        $joins .= " LEFT JOIN procurement_requests pr ON pr.id = po.pr_id ";
    }

    // rows
    $sql = "$select
          FROM purchase_orders po
          $joins
          $whereSql
          ORDER BY $order
          LIMIT $per OFFSET $offset";
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "data" => $rows,
        "pagination" => ["page" => $page, "perPage" => $per, "total" => $total],
    ]);
} catch (Throwable $e) {
    bad("server_error: " . $e->getMessage(), 500);
}
