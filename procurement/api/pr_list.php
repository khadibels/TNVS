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


$page = max(1, (int) ($_GET["page"] ?? 1));
$per = max(1, min(100, (int) ($_GET["per_page"] ?? 10)));
$search = trim($_GET["search"] ?? "");
$status = trim($_GET["status"] ?? "");
$sort = $_GET["sort"] ?? "newest";

$where = [];
$args = [];
if ($search !== "") {
    $where[] = "(pr_no LIKE ? OR title LIKE ? OR requestor LIKE ?)";
    $like = "%$search%";
    array_push($args, $like, $like, $like);
}
if ($status !== "") {
    $where[] = "status=?";
    $args[] = $status;
}
$whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

$order = "pr.id DESC";
if ($sort === "needed") {
    $order = "pr.needed_by ASC, pr.id DESC";
}
if ($sort === "title") {
    $order = "pr.title ASC";
}

$total = $pdo->prepare(
    "SELECT COUNT(*) FROM procurement_requests pr $whereSql"
);
$total->execute($args);
$total = (int) $total->fetchColumn();
$offset = ($page - 1) * $per;

$sql = "SELECT pr.*, d.name AS department
      FROM procurement_requests pr
      LEFT JOIN departments d ON d.id=pr.department_id
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
