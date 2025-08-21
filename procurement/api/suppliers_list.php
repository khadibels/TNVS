<?php
// procurement/api/suppliers_list.php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

try {
  $search = trim($_GET['search'] ?? '');
  $status = $_GET['status'] ?? '';      // '' | '1' | '0'
  $sort   = $_GET['sort']   ?? 'name';  // 'name' | 'new'
  $page   = max(1, (int)($_GET['page'] ?? 1));
  $per    = max(1, min(100, (int)($_GET['per'] ?? 10)));
  $offset = ($page - 1) * $per;
  $selectMode = isset($_GET['select']); // when present, return plain array of rows

  $where = [];
  $args  = [];

  if ($search !== '') {
    $where[] = "(code LIKE ? OR name LIKE ? OR contact_person LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $like = '%' . $search . '%';
    array_push($args, $like, $like, $like, $like, $like);
  }
  if ($status !== '' && ($status === '0' || $status === '1')) {
    $where[] = "is_active = ?";
    $args[]  = (int)$status;
  }
  $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

  $orderBy = ($sort === 'new') ? 'id DESC' : 'name ASC';

  // Count
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM suppliers $whereSql");
  $stmt->execute($args);
  $total = (int)$stmt->fetchColumn();

  $pages = max(1, (int)ceil($total / $per));
  if ($page > $pages) { $page = $pages; $offset = ($page - 1) * $per; }

  // Data (inline ints for LIMIT/OFFSET to avoid mixed placeholders)
  $limit = (int)$per;
  $off   = (int)$offset;
  $sqlData = "SELECT id, code, name, contact_person, email, phone, address,
                     payment_terms, rating, lead_time_days, notes, is_active
              FROM suppliers
              $whereSql
              ORDER BY $orderBy
              LIMIT $limit OFFSET $off";
  $stmt = $pdo->prepare($sqlData);
  $stmt->execute($args);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  if ($selectMode) {
    echo json_encode($rows);
  } else {
    echo json_encode([
      'rows'  => $rows,
      'total' => $total,
      'page'  => $page,
      'pages' => $pages,
      'per'   => $per
    ]);
  }
  exit;
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()]);
  exit;
}
