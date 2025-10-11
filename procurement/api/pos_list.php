<?php
// procurement/api/pos_list.php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

try {
  require_login(); require_role(['admin','procurement_officer','manager']);

  $pdo = db('proc'); if (!$pdo instanceof PDO) throw new Exception('DB error');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $page   = max(1, (int)($_GET['page'] ?? 1));
  $per    = min(100, max(1, (int)($_GET['per'] ?? 10)));
  $search = trim((string)($_GET['search'] ?? ''));
  $status = trim((string)($_GET['status'] ?? ''));

  $w = []; $p = [];
  if ($search !== '') {
    $w[] = "(p.po_no LIKE ? OR r.rfq_no LIKE ? OR r.title LIKE ? OR v.company_name LIKE ?)";
    $like = "%$search%";
    array_push($p, $like, $like, $like, $like);
  }
  if ($status !== '') { $w[] = "p.status=?"; $p[] = $status; }
  $where = $w ? ('WHERE '.implode(' AND ',$w)) : '';

  $count = $pdo->prepare("
    SELECT COUNT(*)
    FROM pos p
    LEFT JOIN rfqs r ON r.id=p.rfq_id
    LEFT JOIN vendors v ON v.id=p.vendor_id
    $where
  ");
  $count->execute($p);
  $total = (int)$count->fetchColumn();

  $off = ($page-1)*$per;

  $list = $pdo->prepare("
    SELECT p.id, p.po_no, p.status, p.currency, p.total, p.issued_at, p.created_at,
           r.rfq_no, r.title,
           v.company_name AS vendor_name
    FROM pos p
    LEFT JOIN rfqs r ON r.id=p.rfq_id
    LEFT JOIN vendors v ON v.id=p.vendor_id
    $where
    ORDER BY p.id DESC
    LIMIT $per OFFSET $off
  ");
  $list->execute($p);
  $rows = $list->fetchAll(PDO::FETCH_ASSOC) ?: [];

  echo json_encode([
    'data' => $rows,
    'pagination' => [
      'page'  => $page,
      'per'   => $per,
      'total' => $total
    ]
  ]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['error'=>$e->getMessage()]);
}
