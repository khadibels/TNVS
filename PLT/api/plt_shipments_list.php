<?php
declare(strict_types=1);
$inc = __DIR__ . '/../../includes';
if (file_exists($inc . '/config.php')) require_once $inc . '/config.php';
if (file_exists($inc . '/auth.php'))  require_once $inc . '/auth.php';
if (function_exists('require_login')) require_login();
header('Content-Type: application/json; charset=utf-8');

try {
  if (!isset($pdo)) throw new Exception('DB missing');

  // single
  if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $st = $pdo->prepare("SELECT s.*, p.name AS project_name, p.code AS project_code
                         FROM plt_shipments s
                         LEFT JOIN plt_projects p ON p.id=s.project_id
                         WHERE s.id=?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    echo json_encode(['data'=>[$row],'pagination'=>['page'=>1,'perPage'=>1,'total'=>$row?1:0]]); exit;
  }

  $page=max(1,(int)($_GET['page']??1));
  $per =min(100,max(1,(int)($_GET['per_page']??10)));
  $off =($page-1)*$per;
  $search=trim((string)($_GET['search']??''));
  $status=trim((string)($_GET['status']??''));
  $sort=($_GET['sort']??'newest');

  $where=[]; $args=[];
  if ($search!==''){
    $where[]="(s.shipment_no LIKE ? OR p.name LIKE ? OR s.origin LIKE ? OR s.destination LIKE ?)";
    array_push($args,"%$search%","%$search%","%$search%","%$search%");
  }
  if ($status!==''){ $where[]="s.status=?"; $args[]=$status; }
  $whereSql = $where ? ('WHERE '.implode(' AND ',$where)) : '';
  $orderSql = "ORDER BY s.id DESC";
  if ($sort==='eta') $orderSql = "ORDER BY s.eta_date IS NULL, s.eta_date ASC, s.id DESC";
  if ($sort==='schedule') $orderSql = "ORDER BY s.schedule_date IS NULL, s.schedule_date ASC, s.id DESC";

  $cnt = $pdo->prepare("SELECT COUNT(*) FROM plt_shipments s LEFT JOIN plt_projects p ON p.id=s.project_id $whereSql");
  $cnt->execute($args);
  $total=(int)$cnt->fetchColumn();

  $st = $pdo->prepare("SELECT s.*, p.name AS project_name, p.code AS project_code
                       FROM plt_shipments s
                       LEFT JOIN plt_projects p ON p.id=s.project_id
                       $whereSql $orderSql LIMIT $per OFFSET $off");
  $st->execute($args);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode(['data'=>$rows,'pagination'=>['page'=>$page,'perPage'=>$per,'total'=>$total]]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['error'=>$e->getMessage()]);
}
