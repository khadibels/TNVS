<?php
// warehousing/TrackShipment/api/list_shipments.php
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_login();
header('Content-Type: application/json');

$page = max(1, (int)($_GET['page'] ?? 1));
$per  = min(100, max(1, (int)($_GET['per'] ?? 25)));
$off  = ($page - 1) * $per;

$q      = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$fromIn = $_GET['from'] ?? '';
$toIn   = $_GET['to']   ?? '';

$validDate = function($s){
  return is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
};
$from = $validDate($fromIn) ? $fromIn : null;
$to   = $validDate($toIn)   ? $toIn   : null;

$w = []; $p = [];
if ($status !== '') { $w[] = 's.status = ?'; $p[] = $status; }
if ($q !== '') {
  // search by ref, carrier, location name or code
  $w[] = '(s.ref_no LIKE ? OR s.carrier LIKE ? OR o.name LIKE ? OR d.name LIKE ? OR o.code LIKE ? OR d.code LIKE ?)';
  $p[] = "%$q%"; $p[] = "%$q%"; $p[] = "%$q%"; $p[] = "%$q%"; $p[] = "%$q%"; $p[] = "%$q%";
}
if ($from !== null) { $w[] = 's.expected_delivery >= ?'; $p[] = $from; }
if ($to   !== null) { $w[] = 's.expected_delivery <= ?'; $p[] = $to; }
$where = $w ? 'WHERE ' . implode(' AND ', $w) : '';

try {
  // total count
  $countSql = "
    SELECT COUNT(*)
    FROM shipments s
    LEFT JOIN warehouse_locations o ON o.id = s.origin_id
    LEFT JOIN warehouse_locations d ON d.id = s.destination_id
    $where
  ";
  $c = $pdo->prepare($countSql);
  $c->execute($p);
  $total = (int)$c->fetchColumn();

  // rows — IMPORTANT: use ONLY positional placeholders
  $sql = "
    SELECT s.id, s.ref_no, s.status, s.carrier,
           DATE_FORMAT(s.expected_delivery, '%Y-%m-%d') AS eta,
           COALESCE(CONCAT(o.code,' - ',o.name), '—') AS origin,
           COALESCE(CONCAT(d.code,' - ',d.name), '—') AS destination
    FROM shipments s
    LEFT JOIN warehouse_locations o ON o.id = s.origin_id
    LEFT JOIN warehouse_locations d ON d.id = s.destination_id
    $where
    ORDER BY s.id DESC
    LIMIT ? OFFSET ?
  ";
  $st = $pdo->prepare($sql);

  // bind WHERE params first, then limit/offset (all positional)
  $i = 1;
  foreach ($p as $v) {
    $st->bindValue($i++, $v);
  }
  $st->bindValue($i++, (int)$per, PDO::PARAM_INT);
  $st->bindValue($i++, (int)$off, PDO::PARAM_INT);

  $st->execute();
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode([
    'rows' => $rows, 'total' => $total,
    'page' => $page, 'per' => $per,
    'total_pages' => max(1, (int)ceil($total / $per))
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false, 'err'=>'Query failed', 'detail'=>$e->getMessage()]);
}
