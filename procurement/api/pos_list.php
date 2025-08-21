<?php
// ./api/pos_list.php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

function bad($m,$c=400){ http_response_code($c); echo json_encode(['error'=>$m]); exit; }

try {
  $page   = max(1, (int)($_GET['page'] ?? 1));
  $per    = max(1, min(100, (int)($_GET['per_page'] ?? 10)));
  $search = trim($_GET['search'] ?? '');
  $status = trim($_GET['status'] ?? '');
  $sort   = $_GET['sort'] ?? 'newest';

  $where = [];
  $args  = [];

  if ($search !== '') {
    $where[] = '(po.po_no LIKE ? OR po.notes LIKE ?)';
    $like = '%'.$search.'%';
    $args[] = $like; $args[] = $like;
  }
  if ($status !== '') {
    $where[] = 'po.status = ?';
    $args[]  = $status;
  }
  $whereSql = $where ? 'WHERE '.implode(' AND ', $where) : '';

  $order = 'po.id DESC';
  if ($sort === 'due') $order = 'po.expected_date IS NULL, po.expected_date ASC, po.id DESC';

  $st = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders po $whereSql");
  $st->execute($args);
  $total = (int)$st->fetchColumn();

  $offset = ($page-1)*$per;

  $sql = "
    SELECT po.id,
           po.po_no,
           po.total,
           po.order_date   AS issue_date,   -- map for UI
           po.expected_date,
           po.status,
           s.name AS supplier_name
    FROM purchase_orders po
    LEFT JOIN suppliers s ON s.id = po.supplier_id
    $whereSql
    ORDER BY $order
    LIMIT $per OFFSET $offset
  ";
  $st = $pdo->prepare($sql);
  $st->execute($args);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode([
    'data' => $rows,
    'pagination' => ['page'=>$page, 'perPage'=>$per, 'total'=>$total]
  ]);
} catch (Throwable $e) {
  bad('server_error: '.$e->getMessage(), 500);
}
