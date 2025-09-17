<?php
require_once __DIR__ . "/../../includes/config.php";
require_once __DIR__ . "/../../includes/auth.php";
require_once __DIR__ . "/../../includes/db.php";

require_role(['admin','procurement_officer'], 'json');
header('Content-Type: application/json; charset=utf-8');

$pdoProc = db('proc');
$pdoWms  = db('wms');
if (!$pdoProc instanceof PDO || !$pdoWms instanceof PDO) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'DB not available']);
  exit;
}

function qcol(PDO $pdo, string $sql, array $p = []) {
  $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn();
}

/* ---------- Single row fetch ---------- */
if (isset($_GET['id']) && $_GET['id'] !== '') {
  $id = (int)$_GET['id'];
  $st = $pdoProc->prepare("SELECT * FROM budgets WHERE id=?");
  $st->execute([$id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) { http_response_code(404); echo json_encode(['error'=>'not_found']); exit; }
  echo json_encode($row);
  exit;
}

/* ---------- Filters & paging ---------- */
$year  = trim((string)($_GET['year']  ?? ''));
$month = trim((string)($_GET['month'] ?? ''));
$dept  = trim((string)($_GET['dept']  ?? ''));
$cat   = trim((string)($_GET['cat']   ?? ''));
$page  = max(1, (int)($_GET['page'] ?? 1));
$per   = 10;

/* Load category map once from WMS */
$catStmt = $pdoWms->query("SELECT id, name FROM inventory_categories ORDER BY name");
$catRows = $catStmt->fetchAll(PDO::FETCH_ASSOC);
$catIdToName = [];
$catNameToIds = [];
foreach ($catRows as $c) {
  $catIdToName[(int)$c['id']] = $c['name'];
  $catNameToIds[$c['name']][] = (int)$c['id'];
}

/* Build WHERE for procurement.budgets only */
$where = [];
$params = [];

/* Basic filters */
if ($year !== '')  { $where[] = "b.fiscal_year = ?"; $params[] = $year; }
if ($month !== '') { $where[] = "b.month = ?";       $params[] = $month; }
if ($dept !== '')  { $where[] = "b.department_id = ?"; $params[] = $dept; }

/* Category filter by NAME -> IDs */
if ($cat !== '') {
  $ids = $catNameToIds[$cat] ?? [];
  if (!$ids) {
    // No matching category name in WMS -> return empty result quickly
    echo json_encode([
      'data' => [],
      'pagination' => ['page'=>$page, 'perPage'=>$per, 'total'=>0],
    ]);
    exit;
  }
  $in = implode(',', array_fill(0, count($ids), '?'));
  $where[] = "b.category_id IN ($in)";
  $params = array_merge($params, $ids);
}

$sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* Count */
$total = (int) qcol($pdoProc, "SELECT COUNT(*) FROM budgets b $sqlWhere", $params);

/* Page */
$offset = ($page - 1) * $per;

/* Rows (procurement only), join departments in the same DB */
$sql = "
  SELECT
    b.*,
    d.name AS department,
    CASE
      WHEN b.month IS NULL OR b.month = 0 THEN NULL
      ELSE DATE_FORMAT(DATE_ADD('2000-01-01', INTERVAL b.month-1 MONTH), '%M')
    END AS month_name
  FROM budgets b
  LEFT JOIN departments d ON d.id = b.department_id
  $sqlWhere
  ORDER BY b.fiscal_year DESC, COALESCE(b.month,0) DESC, b.id DESC
  LIMIT $per OFFSET $offset
";
$st = $pdoProc->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as &$r) {
  $cid = isset($r['category_id']) ? (int)$r['category_id'] : null;
  $r['category'] = $cid && isset($catIdToName[$cid]) ? $catIdToName[$cid] : null;
}

echo json_encode([
  'data' => $rows,
  'pagination' => ['page'=>$page, 'perPage'=>$per, 'total'=>$total],
]);
