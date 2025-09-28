<?php
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';

header('Content-Type: application/json');

require_login();
require_role(['admin','vendor_manager']);

$pdo = db('proc');
if (!$pdo instanceof PDO) { http_response_code(500); echo json_encode(['error'=>'DB error']); exit; }

$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$sort   = $_GET['sort'] ?? 'new';
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = min(100, max(1, (int)($_GET['per'] ?? 10)));

$where = []; $params = [];
if ($search !== '') {
  $where[] = "(company_name LIKE :q OR contact_person LIKE :q OR email LIKE :q OR phone LIKE :q OR address LIKE :q)";
  $params[':q'] = "%{$search}%";
}
if ($status !== '') { $where[] = "status = :status"; $params[':status'] = $status; }
$whereSql = $where ? ('WHERE '.implode(' AND ',$where)) : '';

$order = ($sort==='name') ? 'ORDER BY company_name ASC'
        : (columnExists($pdo,'vendors','created_at') ? 'ORDER BY created_at DESC' : 'ORDER BY id DESC');

$offset = ($page-1)*$per;

$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM vendors {$whereSql}");
$stmtCount->execute($params);
$total = (int)$stmtCount->fetchColumn();

$sql = "SELECT id, company_name, contact_person, email, phone, address, status, profile_photo
        FROM vendors {$whereSql} {$order} LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $k=>$v) $stmt->bindValue($k,$v,PDO::PARAM_STR);
$stmt->bindValue(':limit',$per,PDO::PARAM_INT);
$stmt->bindValue(':offset',$offset,PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$base = rtrim(defined('BASE_URL') ? BASE_URL : '', '/');
$uploadWebPath = $base . '/vendor_portal/vendor/uploads/';

$out = array_map(function($r) use ($uploadWebPath) {
  $name = trim((string)($r['profile_photo'] ?? ''));
  $photo_url = $name !== '' ? $uploadWebPath . rawurlencode(basename($name)) : null;
  return [
    'id'=>(int)$r['id'],
    'company_name'=>$r['company_name'] ?? '',
    'contact_person'=>$r['contact_person'] ?? '',
    'email'=>$r['email'] ?? '',
    'phone'=>$r['phone'] ?? '',
    'address'=>$r['address'] ?? '',
    'status'=>$r['status'] ?? 'Pending',
    'photo_url'=>$photo_url
  ];
}, $rows);

$pages = (int)ceil($total / max(1,$per));

echo json_encode(['rows'=>$out,'total'=>$total,'page'=>$page,'pages'=>$pages,'per'=>$per]);

function columnExists(PDO $pdo,string $table,string $col): bool {
  $q=$pdo->query("SHOW COLUMNS FROM `$table` LIKE ".$pdo->quote($col));
  return (bool)$q->fetch(PDO::FETCH_ASSOC);
}
