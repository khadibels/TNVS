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
  $search  = trim($_GET['search'] ?? '');
  $outcome = trim($_GET['outcome'] ?? ''); // '', pending, awarded_me, lost

  $where = ["q.vendor_id = :vid"];
  $args  = [':vid'=>$vendorId];

  if ($search !== '') {
    $where[] = "(r.rfq_no LIKE :q OR r.title LIKE :q OR q.terms LIKE :q)";
    $args[':q'] = "%$search%";
  }

  // Base query: one row per quote (latest first)
  $whereSql = $where ? ('WHERE '.implode(' AND ',$where)) : '';
  $sql = "
    SELECT
      q.id, q.rfq_id, q.vendor_id, q.total, q.currency, q.terms, q.lead_time_days, q.created_at,
      r.rfq_no, r.title, r.status AS rfq_status, r.awarded_vendor_id, r.due_at
    FROM quotes q
    JOIN rfqs r ON r.id = q.rfq_id
    $whereSql
    ORDER BY q.created_at DESC
    LIMIT $per OFFSET $off";
  $st = $pdo->prepare($sql); $st->execute($args);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  // Compute vendor-facing rfq_status_label + outcome
  $out = [];
  foreach ($rows as $r) {
    $rfqStatus = strtolower((string)$r['rfq_status']);
    $aw        = (int)($r['awarded_vendor_id'] ?? 0);

    // display label for RFQ status to vendor
    $rfq_status_label = ($rfqStatus === 'sent') ? 'open' : $r['rfq_status'];

    // outcome: awarded to me, lost, or pending
    $outcomeCalc = 'pending';
    if ($rfqStatus === 'awarded') {
      $outcomeCalc = ($aw === $vendorId) ? 'awarded_me' : 'lost';
    } elseif ($rfqStatus === 'closed' || $rfqStatus === 'cancelled') {
      $outcomeCalc = 'lost';
    }

    $row = $r;
    $row['rfq_status_label'] = $rfq_status_label;
    $row['outcome'] = $outcomeCalc;
    $out[] = $row;
  }

  if ($outcome !== '') {
    $out = array_values(array_filter($out, fn($x)=>$x['outcome']===$outcome));
  }

  // total count (without pagination but with vendor filter + search + outcome)
  $ctWhere = $where;
  $ctArgs  = $args;
  $ctSql = "SELECT COUNT(*) FROM quotes q JOIN rfqs r ON r.id=q.rfq_id ".($ctWhere?('WHERE '.implode(' AND ',$ctWhere)):'');
  if ($search !== '') { /* already applied above */ }
  $stc = $pdo->prepare($ctSql); $stc->execute($ctArgs);
  $totalBase = (int)$stc->fetchColumn();

  $total = ($outcome==='') ? $totalBase : count($out);

  echo json_encode([
    'data' => $out,
    'pagination' => [
      'page' => $page, 'per' => $per, 'total' => $total, 'pages' => (int)ceil($total/$per)
    ]
  ]);
} catch (Throwable $e) {
  http_response_code(400); echo json_encode(['error'=>$e->getMessage()]);
}
