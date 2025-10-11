<?php
declare(strict_types=1);

ini_set('display_errors', '0');  
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';

try {
  if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']); exit;
  }
  $role = strtolower($_SESSION['user']['role'] ?? '');
  if (!in_array($role, ['admin','vendor_manager'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']); exit;
  }

  // ---- DB ----
  $pdo = db('proc');
  if (!$pdo instanceof PDO) { throw new RuntimeException('DB error'); }
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // ---- Helpers ----
  $colExists = function(PDO $pdo,string $table,string $col): bool {
    $q=$pdo->query("SHOW COLUMNS FROM `$table` LIKE ".$pdo->quote($col));
    return (bool)$q->fetch(PDO::FETCH_ASSOC);
  };

  $id     = (int)($_GET['id'] ?? 0);
  $search = trim((string)($_GET['search'] ?? ''));
  $status = trim((string)($_GET['status'] ?? ''));
  $sort   = (string)($_GET['sort'] ?? 'new');
  $page   = max(1, (int)($_GET['page'] ?? 1));
  $per    = min(100, max(1, (int)($_GET['per'] ?? 10)));

  $base          = rtrim(defined('BASE_URL') ? BASE_URL : '', '/');
  $uploadWebPath = $base . '/vendor_portal/vendor/uploads/';
  $photoWebPath  = $uploadWebPath;

  // ===== Single vendor =====
  if ($id > 0) {
    $hasCats     = $colExists($pdo,'vendors','categories');
    $hasRevAt    = $colExists($pdo,'vendors','reviewed_at');
    $hasRevNote  = $colExists($pdo,'vendors','review_note');
    $hasCreated  = $colExists($pdo,'vendors','created_at');

    $cols = [
      'id','company_name','contact_person','email','phone','address','status','profile_photo',
      'dti_doc','bir_doc','permit_doc','bank_doc','catalog_doc'
    ];
    if ($hasCats)    $cols[] = 'categories';
    if ($hasRevAt)   $cols[] = 'reviewed_at';
    if ($hasRevNote) $cols[] = 'review_note';
    if ($hasCreated) $cols[] = 'created_at';

    $sql = "SELECT ".implode(',', $cols)." FROM vendors WHERE id = :id LIMIT 1";
    $st  = $pdo->prepare($sql);
    $st->execute([':id'=>$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);

    if (!$r) { echo json_encode(['rows'=>[], 'total'=>0]); exit; }

    $photo_name = trim((string)($r['profile_photo'] ?? ''));
    $photo_url  = $photo_name !== '' ? $photoWebPath . rawurlencode(basename($photo_name)) : null;

    $docUrl = function($name) use ($uploadWebPath){
      $name = trim((string)$name);
      return $name !== '' ? $uploadWebPath . rawurlencode(basename($name)) : null;
    };

    $out = [
      'id'             => (int)$r['id'],
      'company_name'   => $r['company_name'] ?? '',
      'contact_person' => $r['contact_person'] ?? '',
      'email'          => $r['email'] ?? '',
      'phone'          => $r['phone'] ?? '',
      'address'        => $r['address'] ?? '',
      'status'         => strtolower((string)($r['status'] ?? '')),
      'categories'     => $r['categories'] ?? '',
      'photo_url'      => $photo_url,
      'files' => [
        'dti'     => ['name'=>$r['dti_doc']     ?? null, 'url'=> $docUrl($r['dti_doc']     ?? null)],
        'bir'     => ['name'=>$r['bir_doc']     ?? null, 'url'=> $docUrl($r['bir_doc']     ?? null)],
        'permit'  => ['name'=>$r['permit_doc']  ?? null, 'url'=> $docUrl($r['permit_doc']  ?? null)],
        'bank'    => ['name'=>$r['bank_doc']    ?? null, 'url'=> $docUrl($r['bank_doc']    ?? null)],
        'catalog' => ['name'=>$r['catalog_doc'] ?? null, 'url'=> $docUrl($r['catalog_doc'] ?? null)],
      ],
      'reviewed_at' => $r['reviewed_at'] ?? null,
      'review_note'=> $r['review_note'] ?? null,
      'created_at'  => $r['created_at']  ?? null,
    ];

    echo json_encode(['rows'=>[$out], 'total'=>1], JSON_UNESCAPED_SLASHES);
    exit;
  }

  // ===== List (paginate) =====
  $where = []; $params = [];
  if ($search !== '') {
    $where[] = "(company_name LIKE :q OR contact_person LIKE :q OR email LIKE :q OR phone LIKE :q OR address LIKE :q)";
    $params[':q'] = "%{$search}%";
  }
  if ($status !== '') { $where[] = "status = :status"; $params[':status'] = $status; }
  $whereSql = $where ? ('WHERE '.implode(' AND ',$where)) : '';

  $hasCreated = $colExists($pdo,'vendors','created_at');
  $order = ($sort==='name') ? 'ORDER BY company_name ASC'
                            : ($hasCreated ? 'ORDER BY created_at DESC' : 'ORDER BY id DESC');

  $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM vendors {$whereSql}");
  $stmtCount->execute($params);
  $total = (int)$stmtCount->fetchColumn();

  $offset = ($page-1)*$per;
  $sql = "SELECT id, company_name, contact_person, email, phone, address, status, profile_photo
          FROM vendors {$whereSql} {$order} LIMIT :lim OFFSET :off";
  $stmt = $pdo->prepare($sql);
  foreach ($params as $k=>$v){ $stmt->bindValue($k,$v,PDO::PARAM_STR); }
  $stmt->bindValue(':lim',$per,PDO::PARAM_INT);
  $stmt->bindValue(':off',$offset,PDO::PARAM_INT);
  $stmt->execute();
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $out = array_map(function($r) use ($photoWebPath){
    $name = trim((string)($r['profile_photo'] ?? ''));
    $photo_url = $name !== '' ? $photoWebPath . rawurlencode(basename($name)) : null;
    return [
      'id'             => (int)$r['id'],
      'company_name'   => $r['company_name'] ?? '',
      'contact_person' => $r['contact_person'] ?? '',
      'email'          => $r['email'] ?? '',
      'phone'          => $r['phone'] ?? '',
      'address'        => $r['address'] ?? '',
      'status'         => strtolower((string)($r['status'] ?? 'pending')),
      'photo_url'      => $photo_url
    ];
  }, $rows);

  $pages = (int)ceil($total / max(1,$per));
  echo json_encode(['rows'=>$out,'total'=>$total,'page'=>$page,'pages'=>$pages,'per'=>$per], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()]);
}
