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
    $stmt = $pdo->prepare(
        "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?"
    );
    $stmt->execute([$name]);
    return (bool) $stmt->fetchColumn();
}

try {
    $page = max(1, (int) ($_GET["page"] ?? 1));
    $perPage = min(100, max(1, (int) ($_GET["per_page"] ?? 10)));
    $search = trim((string) ($_GET["search"] ?? ""));
    $status = trim((string) ($_GET["status"] ?? ""));
    $sort = $_GET["sort"] ?? "newest";

    $where = [];
    $p = [];
    if ($search !== "") {
        $where[] = "(rfq_no LIKE :q OR title LIKE :q)";
        $p[":q"] = "%$search%";
    }
    if ($status !== "") {
        $where[] = "LOWER(status) = LOWER(:status)";
        $p[":status"] = $status;
    }
    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

    $order = "ORDER BY id DESC";
    if ($sort === "title") {
        $order = "ORDER BY title ASC, id DESC";
    }
    if ($sort === "oldest") {
        $order = "ORDER BY id ASC";
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM rfqs $whereSql");
    $stmt->execute($p);
    $total = (int) $stmt->fetchColumn();

    $offset = ($page - 1) * $perPage;

    $hasRecipients = table_exists($pdo, "rfq_recipients");
    $hasQuotes = table_exists($pdo, "quotes");

    $invitedExpr = $hasRecipients
        ? "(SELECT COUNT(*) FROM rfq_recipients rr WHERE rr.rfq_id = r.id)"
        : "0";
    $quotedExpr = $hasQuotes
        ? "(SELECT COUNT(DISTINCT q.supplier_id) FROM quotes q WHERE q.rfq_id = r.id AND q.is_final=1)"
        : "0";

    $sql = "SELECT r.id, r.rfq_no, r.title, r.due_date, r.status, r.notes,
                 $invitedExpr AS invited_count,
                 $quotedExpr  AS quoted_count
          FROM rfqs r
          $whereSql
          $order
          LIMIT :lim OFFSET :off";
    $stmt = $pdo->prepare($sql);
    foreach ($p as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(":lim", $perPage, PDO::PARAM_INT);
    $stmt->bindValue(":off", $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "data" => $rows,
        "pagination" => [
            "page" => $page,
            "perPage" => $perPage,
            "total" => $total,
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(["error" => $e->getMessage()]);
}
