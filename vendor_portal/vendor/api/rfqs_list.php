<?php
require_once __DIR__ . "/../../../includes/config.php";
require_once __DIR__ . "/../../../includes/db.php";
require_once __DIR__ . "/../../../includes/auth.php";

require_login();
$u = current_user();
$vendorId = (int)($u['vendor_id'] ?? 0);
if ($vendorId <= 0) { http_response_code(403); echo json_encode(['error'=>'No vendor id']); exit; }

header('Content-Type: application/json; charset=utf-8');
$pdo = db('proc');
if (!$pdo instanceof PDO) { http_response_code(500); echo json_encode(['error'=>'DB error']); exit; }
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
  $page = max(1, (int)($_GET['page'] ?? 1));
  $per  = min(50, max(5, (int)($_GET['per'] ?? 10)));
  $off  = ($page-1)*$per;
  $search = trim($_GET['search'] ?? '');
  $status = trim($_GET['status'] ?? '');

  $where = ["rs.vendor_id = :vid"];
  $args  = [':vid'=>$vendorId];

  if ($status !== '') { $where[] = "r.status = :st"; $args[':st']=$status; }
  if ($search !== '') {
    $where[] = "(r.rfq_no LIKE :q OR r.title LIKE :q)";
    $args[':q'] = "%$search%";
  }
  $whereSql = $where ? ('WHERE '.implode(' AND ',$where)) : '';

  $sql = "
    SELECT
      r.id, r.rfq_no, r.title, r.due_at, r.currency, r.status,
      r.awarded_vendor_id,
      rs.status AS vendor_status,
      (SELECT COUNT(*) FROM quotes q WHERE q.rfq_id=r.id AND q.vendor_id=rs.vendor_id) AS my_quotes
    FROM rfq_suppliers rs
    JOIN rfqs r ON r.id = rs.rfq_id
    $whereSql
    ORDER BY r.due_at ASC, r.id DESC
    LIMIT $per OFFSET $off";
  $st = $pdo->prepare($sql); $st->execute($args);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  foreach ($rows as &$r) {
    $stt = strtolower((string)$r['status']);
    $aw  = (int)($r['awarded_vendor_id'] ?? 0);
    if ($stt === 'awarded' && $aw && $aw !== $vendorId) {
      $r['status'] = 'closed';
    }
  }

  $ct = $pdo->prepare("SELECT COUNT(*) FROM rfq_suppliers rs JOIN rfqs r ON r.id=rs.rfq_id $whereSql");
  $ct->execute($args); $total = (int)$ct->fetchColumn();

  echo json_encode([
    'data' => $rows,
    'pagination' => [
      'page' => $page, 'per' => $per, 'total' => $total, 'pages' => (int)ceil($total/$per)
    ]
  ]);
} catch (Throwable $e) {
  http_response_code(400); echo json_encode(['error'=>$e->getMessage()]);
}
