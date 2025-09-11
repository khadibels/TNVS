<?php
require_once __DIR__ . "/../../includes/config.php";
require_once __DIR__ . "/../../includes/auth.php";
require_once __DIR__ . "/../../includes/db.php";
require_login();
require_role(['admin', 'manager']);
header("Content-Type: application/json");

$pdo = db('wms');

$page = max(1, (int) ($_GET["page"] ?? 1));
$perPage = 10;
$search = trim($_GET["search"] ?? "");
$category = trim($_GET["category"] ?? "");
$sort = $_GET["sort"] ?? "latest";

$include_archived = ($_GET["include_archived"] ?? "") === "1";

$where = [];
$params = [];
if (!$include_archived) {
    $where[] = "ii.archived = 0";
}
if ($search !== "") {
    $where[] = "(ii.sku LIKE :s OR ii.name LIKE :s)";
    $params[":s"] = "%" . $search . "%";
}
if ($category !== "") {
    $where[] = "ii.category = :c";
    $params[":c"] = $category;
}
$whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

switch ($sort) {
    case "name":
        $orderBy = "ii.name ASC";
        break;
    case "stock":
        $orderBy = "stock ASC, ii.name ASC";
        break;
    default:
        $orderBy = "ii.id DESC";
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory_items ii $whereSql");
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$offset = ($page - 1) * $perPage;

$sql = "
SELECT
  ii.id, ii.sku, ii.name, ii.category, ii.reorder_level, ii.archived,
  COALESCE(agg.stock, 0) AS stock,
  CASE
    WHEN COALESCE(agg.loc_count,0) = 0  THEN COALESCE(ii.location, '-')
    WHEN agg.loc_count = 1               THEN agg.one_loc_name
    ELSE 'Multiple'
  END AS location
FROM inventory_items ii
LEFT JOIN (
  -- aggregate to per-location balances, keep only locations with qty > 0,
  -- then count how many positive locations and grab the single name if just one
  SELECT
    loc.item_id,
    SUM(loc.qty)                          AS stock,
    COUNT(*)                               AS loc_count,
    MAX(loc.location_name)                 AS one_loc_name
  FROM (
    SELECT
      sl.item_id,
      sl.location_id,
      SUM(sl.qty)                          AS qty,
      MAX(wl.name)                         AS location_name
    FROM stock_levels sl
    LEFT JOIN warehouse_locations wl ON wl.id = sl.location_id
    GROUP BY sl.item_id, sl.location_id
    HAVING SUM(sl.qty) > 0                 -- *** only positive balances ***
  ) loc
  GROUP BY loc.item_id
) agg ON agg.item_id = ii.id
$whereSql
ORDER BY $orderBy
LIMIT :lim OFFSET :off
";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(":lim", $perPage, PDO::PARAM_INT);
$stmt->bindValue(":off", $offset, PDO::PARAM_INT);
$stmt->execute();

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(
    [
        "data" => array_map(function ($r) {
            return [
                "id" => (int) $r["id"],
                "sku" => $r["sku"],
                "name" => $r["name"],
                "category" => $r["category"],
                "stock" => (int) $r["stock"],
                "reorder_level" => (int) $r["reorder_level"],
                "location" => $r["location"],
                "archived" => (int) $r["archived"],
            ];
        }, $rows),
        "pagination" => [
            "page" => $page,
            "perPage" => $perPage,
            "total" => $total,
        ],
    ],
    JSON_UNESCAPED_UNICODE
);
