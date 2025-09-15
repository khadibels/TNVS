<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

require_login();
require_role(['admin']);

header('Content-Type: application/json; charset=utf-8');

// connect to AUTH DB (users table lives here)
$pdo = db('auth');
if (!$pdo) { http_response_code(500); echo json_encode(['error'=>'DB_CONNECT_FAILED']); exit; }

$q        = isset($_GET['q']) ? trim($_GET['q']) : '';
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = max(1, min(100, (int)($_GET['perPage'] ?? 10)));
$offset   = ($page - 1) * $perPage;

$allowed = [
  'admin','manager','warehouse_staff','procurement_officer',
  'asset_manager','document_controller','project_lead','viewer'
];

$where = '';
$args  = [];
if ($q !== '') {
  $where = "WHERE (name LIKE ? OR email LIKE ?)";
  $like  = "%$q%";
  $args[] = $like; $args[] = $like;
}

$stCount = $pdo->prepare("SELECT COUNT(*) FROM users $where");
$stCount->execute($args);
$total = (int)$stCount->fetchColumn();

$sqlData = "SELECT id, name, email, role FROM users $where ORDER BY name ASC LIMIT ? OFFSET ?";
$stData  = $pdo->prepare($sqlData);
$bindIdx = 1;
foreach ($args as $a) { $stData->bindValue($bindIdx++, $a, PDO::PARAM_STR); }
$stData->bindValue($bindIdx++, $perPage, PDO::PARAM_INT);
$stData->bindValue($bindIdx++, $offset,  PDO::PARAM_INT);
$stData->execute();
$rows = $stData->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
  'data' => $rows,
  'roles' => array_map(fn($r)=>['name'=>$r], $allowed),
  'pagination' => ['page'=>$page,'perPage'=>$perPage,'total'=>$total]
]);
