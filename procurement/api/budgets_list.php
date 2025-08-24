<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

function qcol(PDO $pdo, string $sql, array $p=[]){
  $st=$pdo->prepare($sql); $st->execute($p); return $st->fetchColumn();
}

if (isset($_GET['id']) && $_GET['id']!=='') {
  $id=(int)$_GET['id'];
  $st=$pdo->prepare("SELECT * FROM budgets WHERE id=?");
  $st->execute([$id]);
  $r=$st->fetch(PDO::FETCH_ASSOC);
  if (!$r){ http_response_code(404); echo json_encode(['error'=>'not_found']); exit; }
  echo json_encode($r); exit;
}

$year = trim((string)($_GET['year'] ?? ''));
$month= trim((string)($_GET['month'] ?? ''));
$dept = trim((string)($_GET['dept'] ?? ''));
$cat  = trim((string)($_GET['cat'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = 10;

$where=[]; $p=[];
if ($year!==''){ $where[]='b.fiscal_year=?'; $p[]=$year; }
if ($month!==''){ $where[]='b.month=?';       $p[]=$month; }
if ($dept!==''){ $where[]='b.department_id=?';$p[]=$dept; }
if ($cat!==''){  $where[]='c.name=?';         $p[]=$cat; } // filter by category text
$sqlWhere = $where ? 'WHERE '.implode(' AND ',$where) : '';

$total = (int)qcol($pdo, "SELECT COUNT(*) FROM budgets b LEFT JOIN inventory_categories c ON c.id=b.category_id $sqlWhere", $p);

$sql = "SELECT
          b.*,
          d.name AS department,
          c.name AS category,
          CASE WHEN b.month IS NULL THEN NULL ELSE DATE_FORMAT(STR_TO_DATE(b.month, '%m'), '%M') END AS month_name
        FROM budgets b
        LEFT JOIN departments d ON d.id=b.department_id
        LEFT JOIN inventory_categories c ON c.id=b.category_id
        $sqlWhere
        ORDER BY b.fiscal_year DESC, COALESCE(b.month,0) DESC, b.id DESC
        LIMIT $per OFFSET ".(($page-1)*$per);

$st=$pdo->prepare($sql);
$st->execute($p);
$rows=$st->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
  'data'=>$rows,
  'pagination'=>['page'=>$page,'perPage'=>$per,'total'=>$total]
]);
