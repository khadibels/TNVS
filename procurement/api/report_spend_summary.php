<?php
require_once __DIR__ . "/../../includes/config.php";
require_once __DIR__ . "/../../includes/auth.php";
require_once __DIR__ . "/../../includes/db.php";

require_role(['admin','procurement_officer'], 'json');
header('Content-Type: application/json; charset=utf-8');

$pdo = db('proc') ?: db('wms');
if (!$pdo instanceof PDO) { http_response_code(500); echo json_encode(['ok'=>false,'err'=>'DB not available']); exit; }

function first_col(PDO $pdo, string $t, array $cands): ?string {
  $q = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
  $q->execute([$t]);
  $cols = array_column($q->fetchAll(PDO::FETCH_ASSOC),'COLUMN_NAME');
  foreach ($cands as $c) if (in_array($c,$cols,true)) return $c;
  return null;
}

$from = $_GET["from"] ?? "";
$to   = $_GET["to"]   ?? "";
$dept = $_GET["dept"] ?? "";
$cat  = $_GET["cat"]  ?? "";

$where = ["po.status IN ('ordered','partially_received','received','closed')"];
$p = [];
if ($from!==""){ $where[]="po.order_date >= ?"; $p[]=$from; }
if ($to  !==""){ $where[]="po.order_date <= ?"; $p[]=$to; }
if ($dept!==""){ $where[]="pr.department_id = ?"; $p[]=(int)$dept; }
$sqlWhere = "WHERE ".implode(" AND ", $where);

try{
  $st=$pdo->prepare("SELECT COUNT(*) AS po_count, COALESCE(SUM(po.total),0) AS total_spend,
                     CASE WHEN COUNT(*)=0 THEN 0 ELSE SUM(po.total)/COUNT(*) END AS avg_po
                     FROM purchase_orders po
                     LEFT JOIN procurement_requests pr ON pr.id=po.pr_id
                     $sqlWhere");
  $st->execute($p);
  $summary = $st->fetch(PDO::FETCH_ASSOC) ?: ["po_count"=>0,"total_spend"=>0,"avg_po"=>0];

  $st=$pdo->prepare("SELECT COALESCE(s.name,'-') AS supplier, COALESCE(SUM(po.total),0) AS total
                     FROM purchase_orders po
                     LEFT JOIN suppliers s ON s.id=po.supplier_id
                     LEFT JOIN procurement_requests pr ON pr.id=po.pr_id
                     $sqlWhere
                     GROUP BY supplier
                     ORDER BY total DESC
                     LIMIT 20");
  $st->execute($p);
  $bySupplier = $st->fetchAll(PDO::FETCH_ASSOC);

  $itemsTable="purchase_order_items";
  $qtyCol1 = first_col($pdo,$itemsTable,["qty"]);
  $qtyCol2 = first_col($pdo,$itemsTable,["qty_ordered"]);
  $priceCol1 = first_col($pdo,$itemsTable,["price"]);
  $priceCol2 = first_col($pdo,$itemsTable,["unit_cost"]);
  $itemLink = first_col($pdo,$itemsTable,["item_id"]);

  $lineExpr=null;
  if($qtyCol1 && $priceCol1)      $lineExpr="poi.$qtyCol1 * poi.$priceCol1";
  elseif($qtyCol2 && $priceCol2)  $lineExpr="poi.$qtyCol2 * poi.$priceCol2";
  elseif($qtyCol1 && $priceCol2)  $lineExpr="poi.$qtyCol1 * poi.$priceCol2";
  elseif($qtyCol2 && $priceCol1)  $lineExpr="poi.$qtyCol2 * poi.$priceCol1";

  $byCategory=[];
  if($itemLink && $lineExpr){
    $p2=$p;
    $catSql = $cat!=="" ? " AND ii.category = ? " : "";
    if($cat!=="") $p2[]=$cat;

    $sql="
      SELECT COALESCE(ii.category,'-') AS category, COALESCE(SUM($lineExpr),0) AS total
      FROM purchase_orders po
      JOIN $itemsTable poi ON poi.po_id=po.id
      LEFT JOIN `logi_wms`.`inventory_items` ii ON ii.id = poi.$itemLink
      LEFT JOIN procurement_requests pr ON pr.id=po.pr_id
      $sqlWhere
      $catSql
      GROUP BY category
      ORDER BY total DESC
      LIMIT 20";
    $st=$pdo->prepare($sql);
    $st->execute($p2);
    $byCategory=$st->fetchAll(PDO::FETCH_ASSOC);
  }

  $st=$pdo->prepare("SELECT DATE_FORMAT(po.order_date,'%Y-%m') AS period, COALESCE(SUM(po.total),0) AS total
                     FROM purchase_orders po
                     LEFT JOIN procurement_requests pr ON pr.id=po.pr_id
                     $sqlWhere
                     GROUP BY period
                     ORDER BY period ASC");
  $st->execute($p);
  $byMonth=$st->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode([
    "summary"=>["po_count"=>(int)$summary["po_count"],"total_spend"=>(float)$summary["total_spend"],"avg_po"=>(float)$summary["avg_po"]],
    "by_supplier"=>$bySupplier,
    "by_category"=>$byCategory,
    "by_month"=>$byMonth
  ]);
}catch(Throwable $e){
  http_response_code(500);
  echo json_encode(["error"=>"server_error: ".$e->getMessage()]);
}
