<?php
require_once __DIR__ . "/../includes/config.php";
require_once __DIR__ . "/../includes/auth.php";
if (file_exists(__DIR__ . "/../includes/db.php")) {
    require_once __DIR__ . "/../includes/db.php";
}
require_login();
require_role(['admin', 'document_controller']);

$section = 'docs';
$active  = 'documents';

if (function_exists('db')) {
    $pdo = db('docs');  
    $procPdo = db('proc');
    $pltPdo  = db('plt');
} else {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=logi_docs;charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

$section = 'docs';
$active = 'documents';


// Ensure minimal assets table exists (for linking docs to assets)
$pdo->exec("CREATE TABLE IF NOT EXISTS assets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  status VARCHAR(50) NOT NULL,
  installed_on DATE,
  disposed_on DATE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Ensure document tables exist
$pdo->exec("CREATE TABLE IF NOT EXISTS documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  doc_type VARCHAR(60) NOT NULL,
  doc_code VARCHAR(80) NULL,
  asset_id INT NULL,
  trip_ref VARCHAR(80) NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'Draft',
  issue_date DATE NULL,
  expiration_date DATE NULL,
  version INT NOT NULL DEFAULT 1,
  file_path VARCHAR(255) NULL,
  tags TEXT NULL,
  created_by VARCHAR(120) NULL,
  verified_by VARCHAR(120) NULL,
  approved_by VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS document_versions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  document_id INT NOT NULL,
  version INT NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  notes VARCHAR(255) NULL,
  uploaded_by VARCHAR(120) NULL,
  uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX(document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS document_activity (
  id INT AUTO_INCREMENT PRIMARY KEY,
  document_id INT NOT NULL,
  actor VARCHAR(120) NULL,
  action VARCHAR(40) NOT NULL,
  details TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX(document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function table_exists_in_schema(PDO $pdo, string $schema, string $table): bool {
    try {
        $st = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ? LIMIT 1");
        $st->execute([$schema, $table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}
function first_value(array $row, array $keys, $default = null) {
    foreach ($keys as $k) {
        if (array_key_exists($k, $row) && $row[$k] !== null && $row[$k] !== '') return $row[$k];
    }
    return $default;
}

// CSRF token
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(16)); }
$csrf = $_SESSION['csrf_token'];
function assert_csrf(){ if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) { http_response_code(400); exit('Invalid CSRF'); } }

// Simple actor helper (replace with authenticated user later)
function actor(){ return $_SESSION['user_email'] ?? 'admin'; }

// File upload handling
$uploadRoot = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'docs';
if (!is_dir($uploadRoot)) @mkdir($uploadRoot, 0777, true);

function safe_filename($name){ return preg_replace('/[^a-zA-Z0-9._-]+/','_', $name); }
function allowed_file($name){ $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION)); return in_array($ext, ['pdf','png','jpg','jpeg','doc','docx','xls','xlsx']); }

// Activity logger
function log_activity($pdo, $document_id, $action, $details=''){
  $stmt = $pdo->prepare("INSERT INTO document_activity (document_id,actor,action,details) VALUES (:d,:a,:ac,:de)");
  $stmt->execute([':d'=>$document_id, ':a'=>actor(), ':ac'=>$action, ':de'=>$details?:null]);
}

// Actions: export, download
if (($_GET['action'] ?? '') === 'export') {
    // Export current filtered docs to CSV
    $params=[]; $where=[];
    $status = $_GET['status'] ?? ''; $type = $_GET['type'] ?? ''; $q = trim($_GET['q'] ?? ''); $exp = $_GET['exp'] ?? '';
    if ($status !== '') { $where[]='d.status=:st'; $params[':st']=$status; }
    if ($type !== '') { $where[]='d.doc_type=:tp'; $params[':tp']=$type; }
    if ($q !== '') { $where[]='(d.title LIKE :q OR d.doc_code LIKE :q OR a.name LIKE :q OR d.trip_ref LIKE :q)'; $params[':q']='%'.$q.'%'; }
    if ($exp === 'soon') { $where[]='d.expiration_date IS NOT NULL AND d.expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)'; }
    if ($exp === 'expired') { $where[]='d.expiration_date IS NOT NULL AND d.expiration_date < CURDATE()'; }
    $w = $where ? ('WHERE '.implode(' AND ',$where)) : '';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=documents_'.date('Ymd_His').'.csv');
    $out=fopen('php://output','w');
    fputcsv($out, ['id','title','doc_type','doc_code','asset_id','asset_name','trip_ref','status','issue_date','expiration_date','version','file_path','tags','created_by','verified_by','approved_by','created_at']);
    $stmt = $pdo->prepare("SELECT d.*, a.name AS asset_name FROM documents d LEFT JOIN assets a ON d.asset_id=a.id $w ORDER BY d.id DESC");
    $stmt->execute($params);
    while ($r = $stmt->fetch()) {
        fputcsv($out, [$r['id'],$r['title'],$r['doc_type'],$r['doc_code'],$r['asset_id'],$r['asset_name'],$r['trip_ref'],$r['status'],$r['issue_date'],$r['expiration_date'],$r['version'],$r['file_path'],$r['tags'],$r['created_by'],$r['verified_by'],$r['approved_by'],$r['created_at']]);
    }
    fclose($out); exit;
}

if (($_GET['action'] ?? '') === 'download') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT file_path, title FROM documents WHERE id=:id");
        $stmt->execute([':id'=>$id]);
        $r = $stmt->fetch();
        if ($r && $r['file_path']) {
            $filepath = $uploadRoot . DIRECTORY_SEPARATOR . basename($r['file_path']);
            if (is_file($filepath)) {
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="'.safe_filename($r['title']).'_' . basename($r['file_path']).'"');
                header('Content-Length: ' . filesize($filepath));
                readfile($filepath);
                log_activity($pdo, $id, 'downloaded', basename($r['file_path']));
                exit;
            }
        }
    }
    http_response_code(404); echo 'File not found'; exit;
}

// Handle POST (create, update, approve, verify, reject, delete, new_version)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $op = $_POST['op'] ?? '';

    if ($op === 'create') {
        assert_csrf();
        $title = trim($_POST['title'] ?? '');
        $doc_type = $_POST['doc_type'] ?? '';
        $doc_code = trim($_POST['doc_code'] ?? '');
        $asset_id = (int)($_POST['asset_id'] ?? 0);
        $trip_ref = trim($_POST['trip_ref'] ?? '');
        $issue_date = $_POST['issue_date'] ?? null;
        $expiration_date = $_POST['expiration_date'] ?? null;
        $tags = trim($_POST['tags'] ?? '');
        $created_by = actor();
        if ($title !== '' && $doc_type !== '') {
            $stmt = $pdo->prepare("INSERT INTO documents (title,doc_type,doc_code,asset_id,trip_ref,status,issue_date,expiration_date,version,file_path,tags,created_by) VALUES (:title,:doc_type,:doc_code,:asset_id,:trip_ref,'Submitted',:issue_date,:expiration_date,1,NULL,:tags,:created_by)");
            $stmt->execute([
                ':title'=>$title, ':doc_type'=>$doc_type, ':doc_code'=>$doc_code ?: null, ':asset_id'=>$asset_id ?: null,
                ':trip_ref'=>$trip_ref ?: null, ':issue_date'=>$issue_date ?: null, ':expiration_date'=>$expiration_date ?: null,
                ':tags'=>$tags ?: null, ':created_by'=>$created_by
            ]);
            $docId = (int)$pdo->lastInsertId();
            // File upload optional
            if (!empty($_FILES['file']['name'])) {
                if (!allowed_file($_FILES['file']['name'])) { header('Location: doc.php?err=Invalid%20file%20type'); exit; }
                $clean = safe_filename($_FILES['file']['name']);
                $dest = $docId.'_v1_'.$clean;
                move_uploaded_file($_FILES['file']['tmp_name'], $uploadRoot.DIRECTORY_SEPARATOR.$dest);
                $pdo->prepare("UPDATE documents SET file_path=:fp WHERE id=:id")->execute([':fp'=>$dest, ':id'=>$docId]);
                $pdo->prepare("INSERT INTO document_versions (document_id,version,file_path,uploaded_by) VALUES (:d,1,:fp,:u)")
                    ->execute([':d'=>$docId, ':fp'=>$dest, ':u'=>$created_by]);
                log_activity($pdo, $docId, 'uploaded', $dest);
            }
            log_activity($pdo, $docId, 'created', $doc_type.' '.$doc_code);
            echo "<script>try{localStorage.setItem('docs_changed', Date.now().toString())}catch(e){}</script>";
        }
        header('Location: document.php'); exit;
    }

    if ($op === 'update') {
        assert_csrf();
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $title = trim($_POST['title'] ?? '');
            $doc_type = $_POST['doc_type'] ?? '';
            $doc_code = trim($_POST['doc_code'] ?? '');
            $asset_id = (int)($_POST['asset_id'] ?? 0);
            $trip_ref = trim($_POST['trip_ref'] ?? '');
            $issue_date = $_POST['issue_date'] ?? null;
            $expiration_date = $_POST['expiration_date'] ?? null;
            $tags = trim($_POST['tags'] ?? '');
            $status = $_POST['status'] ?? 'Submitted';
            $stmt = $pdo->prepare("UPDATE documents SET title=:title,doc_type=:doc_type,doc_code=:doc_code,asset_id=:asset_id,trip_ref=:trip_ref,issue_date=:issue_date,expiration_date=:expiration_date,tags=:tags,status=:status WHERE id=:id");
            $stmt->execute([
                ':id'=>$id, ':title'=>$title, ':doc_type'=>$doc_type, ':doc_code'=>$doc_code ?: null, ':asset_id'=>$asset_id ?: null,
                ':trip_ref'=>$trip_ref ?: null, ':issue_date'=>$issue_date ?: null, ':expiration_date'=>$expiration_date ?: null,
                ':tags'=>$tags ?: null, ':status'=>$status
            ]);
            log_activity($pdo, $id, 'updated');
            echo "<script>try{localStorage.setItem('docs_changed', Date.now().toString())}catch(e){}</script>";
        }
        header('Location: document.php'); exit;
    }

    if ($op === 'new_version') {
        assert_csrf();
        $id = (int)($_POST['id'] ?? 0);
        $notes = trim($_POST['vnotes'] ?? '');
        if ($id > 0 && !empty($_FILES['vfile']['name'])) {
            if (!allowed_file($_FILES['vfile']['name'])) { header('Location: document.php?err=Invalid%20file%20type'); exit; }
            $stm = $pdo->prepare("SELECT version FROM documents WHERE id=:id"); $stm->execute([':id'=>$id]); $v = (int)($stm->fetchColumn() ?: 1);
            $newV = $v+1;
            $clean = safe_filename($_FILES['vfile']['name']);
            $dest = $id.'_v'.$newV.'_'.$clean;
            move_uploaded_file($_FILES['vfile']['tmp_name'], $uploadRoot.DIRECTORY_SEPARATOR.$dest);
            $pdo->prepare("UPDATE documents SET version=:v, file_path=:fp WHERE id=:id")->execute([':v'=>$newV, ':fp'=>$dest, ':id'=>$id]);
            $pdo->prepare("INSERT INTO document_versions (document_id,version,file_path,notes,uploaded_by) VALUES (:d,:v,:fp,:n,:u)")
                ->execute([':d'=>$id, ':v'=>$newV, ':fp'=>$dest, ':n'=>$notes?:null, ':u'=>actor()]);
            log_activity($pdo, $id, 'uploaded', 'v'.$newV.' '.$dest);
            echo "<script>try{localStorage.setItem('docs_changed', Date.now().toString())}catch(e){}</script>";
        }
        header('Location: document.php'); exit;
    }

    if (in_array($op, ['verify','approve','reject'], true)) {
        assert_csrf();
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            if ($op==='verify') {
                $pdo->prepare("UPDATE documents SET status='Verified', verified_by=:u WHERE id=:id")->execute([':u'=>actor(), ':id'=>$id]);
                log_activity($pdo, $id, 'verified');
            }
            if ($op==='approve') {
                $pdo->prepare("UPDATE documents SET status='Approved', approved_by=:u WHERE id=:id")->execute([':u'=>actor(), ':id'=>$id]);
                log_activity($pdo, $id, 'approved');
            }
            if ($op==='reject') {
                $pdo->prepare("UPDATE documents SET status='Rejected' WHERE id=:id")->execute([':id'=>$id]);
                log_activity($pdo, $id, 'rejected');
            }
            echo "<script>try{localStorage.setItem('docs_changed', Date.now().toString())}catch(e){}</script>";
        }
        header('Location: document.php'); exit;
    }

    if ($op === 'delete') {
        assert_csrf();
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            // Delete records; leave files on disk to avoid accidental loss
            $pdo->prepare("DELETE FROM document_versions WHERE document_id=:id")->execute([':id'=>$id]);
            $pdo->prepare("DELETE FROM document_activity WHERE document_id=:id")->execute([':id'=>$id]);
            $pdo->prepare("DELETE FROM documents WHERE id=:id")->execute([':id'=>$id]);
            log_activity($pdo, $id, 'deleted');
            echo "<script>try{localStorage.setItem('docs_changed', Date.now().toString())}catch(e){}</script>";
        }
        header('Location: document.php'); exit;
    }
}

// Filters and list
$filterStatus = $_GET['status'] ?? '';
$filterType = $_GET['type'] ?? '';
$filterQ = trim($_GET['q'] ?? '');
$filterExp = $_GET['exp'] ?? ''; // soon, expired
$docsStmt = $pdo->query("SELECT d.*, a.name AS asset_name, DATEDIFF(d.expiration_date, CURDATE()) AS days_to_expiry FROM documents d LEFT JOIN assets a ON d.asset_id=a.id ORDER BY d.updated_at DESC, d.id DESC LIMIT 1000");
$docsLocal = $docsStmt ? ($docsStmt->fetchAll() ?: []) : [];
$allDocs = [];
foreach ($docsLocal as $d) {
    $d['source_module'] = 'Document Tracking';
    $d['is_external'] = 0;
    $d['external_url'] = null;
    $allDocs[] = $d;
}

if (($procPdo ?? null) instanceof PDO && defined('DB_PROC_NAME') && table_exists_in_schema($procPdo, DB_PROC_NAME, 'vendor_documents')) {
    $hasVendors = table_exists_in_schema($procPdo, DB_PROC_NAME, 'vendors');
    $sql = "SELECT vd.*, " . ($hasVendors ? "v.company_name AS vendor_name " : "NULL AS vendor_name ") .
           "FROM vendor_documents vd " . ($hasVendors ? "LEFT JOIN vendors v ON v.id = vd.vendor_id " : "") .
           "ORDER BY vd.created_at DESC, vd.id DESC LIMIT 1000";
    $rows = $procPdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $r) {
        $status = ucfirst(strtolower((string)($r['status'] ?? 'submitted')));
        $createdAt = (string)first_value($r, ['reviewed_at', 'created_at'], date('Y-m-d H:i:s'));
        $title = trim((string)first_value($r, ['vendor_name'], 'Vendor') . ' - ' . (string)first_value($r, ['doc_type'], 'Document'));
        $allDocs[] = [
            'id' => (int)($r['id'] ?? 0),
            'title' => $title,
            'doc_type' => (string)first_value($r, ['doc_type'], 'Vendor Document'),
            'doc_code' => (string)first_value($r, ['category'], ''),
            'asset_id' => null,
            'asset_name' => (string)first_value($r, ['vendor_name'], 'Vendor'),
            'trip_ref' => null,
            'status' => $status,
            'issue_date' => substr((string)first_value($r, ['created_at'], ''), 0, 10),
            'expiration_date' => null,
            'version' => 1,
            'file_path' => (string)first_value($r, ['file_path'], ''),
            'tags' => (string)first_value($r, ['category'], ''),
            'created_by' => 'vendor_portal',
            'verified_by' => null,
            'approved_by' => null,
            'created_at' => (string)first_value($r, ['created_at'], $createdAt),
            'updated_at' => $createdAt,
            'days_to_expiry' => null,
            'source_module' => 'Vendor Portal',
            'is_external' => 1,
            'external_url' => (string)first_value($r, ['url'], ''),
        ];
    }
}

if (($pltPdo ?? null) instanceof PDO && defined('DB_PLT_NAME') && table_exists_in_schema($pltPdo, DB_PLT_NAME, 'plt_documents')) {
    try {
        $rows = $pltPdo->query("SELECT * FROM plt_documents ORDER BY id DESC LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $createdAt = (string)first_value($r, ['updated_at', 'created_at', 'uploaded_at'], date('Y-m-d H:i:s'));
            $title = (string)first_value($r, ['title', 'name', 'doc_name', 'doc_type'], 'PLT Document');
            $allDocs[] = [
                'id' => (int)($r['id'] ?? 0),
                'title' => $title,
                'doc_type' => (string)first_value($r, ['doc_type', 'type', 'document_type'], 'Project Document'),
                'doc_code' => (string)first_value($r, ['doc_code', 'code', 'reference_no', 'ref_no'], ''),
                'asset_id' => null,
                'asset_name' => null,
                'trip_ref' => (string)first_value($r, ['shipment_no', 'shipment_id', 'project_id'], ''),
                'status' => (string)first_value($r, ['status'], 'Submitted'),
                'issue_date' => substr((string)first_value($r, ['issue_date', 'created_at', 'uploaded_at'], ''), 0, 10),
                'expiration_date' => (string)first_value($r, ['expiration_date', 'expiry_date'], ''),
                'version' => (int)first_value($r, ['version'], 1),
                'file_path' => (string)first_value($r, ['file_path', 'path'], ''),
                'tags' => '',
                'created_by' => 'plt',
                'verified_by' => null,
                'approved_by' => null,
                'created_at' => (string)first_value($r, ['created_at', 'uploaded_at'], $createdAt),
                'updated_at' => $createdAt,
                'days_to_expiry' => null,
                'source_module' => 'PLT',
                'is_external' => 1,
                'external_url' => (string)first_value($r, ['url'], ''),
            ];
        }
    } catch (Throwable $e) {
        // ignore PLT document fetch errors
    }
}

$docs = array_values(array_filter($allDocs, function(array $d) use ($filterStatus, $filterType, $filterQ, $filterExp): bool {
    $status = strtolower(trim((string)($d['status'] ?? '')));
    $type = strtolower(trim((string)($d['doc_type'] ?? '')));
    $qhay = strtolower(
        (string)($d['title'] ?? '') . ' ' .
        (string)($d['doc_code'] ?? '') . ' ' .
        (string)($d['asset_name'] ?? '') . ' ' .
        (string)($d['trip_ref'] ?? '') . ' ' .
        (string)($d['source_module'] ?? '')
    );

    if ($filterStatus !== '' && $status !== strtolower(trim($filterStatus))) return false;
    if ($filterType !== '' && $type !== strtolower(trim($filterType))) return false;
    if ($filterQ !== '' && strpos($qhay, strtolower($filterQ)) === false) return false;

    $exp = (string)($d['expiration_date'] ?? '');
    if ($filterExp === 'expired') {
        if ($exp === '' || strtotime($exp) >= strtotime(date('Y-m-d'))) return false;
    }
    if ($filterExp === 'soon') {
        if ($exp === '') return false;
        $days = (int)floor((strtotime($exp) - strtotime(date('Y-m-d'))) / 86400);
        if ($days < 0 || $days > 30) return false;
    }
    return true;
}));

usort($docs, function(array $a, array $b): int {
    $at = strtotime((string)($a['updated_at'] ?? $a['created_at'] ?? '1970-01-01'));
    $bt = strtotime((string)($b['updated_at'] ?? $b['created_at'] ?? '1970-01-01'));
    return $bt <=> $at;
});

// Stats (global across all fetched sources)
$totalDocs = count($allDocs);
$pending = 0;
$expiringSoon = 0;
$expired = 0;
foreach ($allDocs as $d) {
    $s = strtolower(trim((string)($d['status'] ?? '')));
    if (in_array($s, ['draft', 'submitted', 'verified', 'pending'], true)) $pending++;
    $exp = (string)($d['expiration_date'] ?? '');
    if ($exp !== '') {
        $days = (int)floor((strtotime($exp) - strtotime(date('Y-m-d'))) / 86400);
        if ($days < 0) $expired++;
        if ($days >= 0 && $days <= 30) $expiringSoon++;
    }
}

$docTypes = ['Transport Manifest','Delivery Receipt','Vehicle Registration','Driver ID','Permit','Insurance','Other'];
$statuses = ['Draft','Submitted','Verified','Approved','Rejected','Archived'];
foreach ($allDocs as $d) {
    $t = trim((string)($d['doc_type'] ?? ''));
    if ($t !== '' && !in_array($t, $docTypes, true)) $docTypes[] = $t;
    $s = trim((string)($d['status'] ?? ''));
    if ($s !== '' && !in_array($s, $statuses, true)) $statuses[] = $s;
}

// Fetch assets for linking
$assets = $pdo->query("SELECT id, name FROM assets ORDER BY name ASC")->fetchAll();

// Topbar profile (optional fallbacks)
$userName = $_SESSION["user"]["name"] ?? "Nicole Malitao";
$userRole = $_SESSION["user"]["role"] ?? "Document Controller";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Document Tracking | TNVS</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="../css/style.css" rel="stylesheet" />
  <link href="../css/modules.css" rel="stylesheet" />

  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <script src="../js/sidebar-toggle.js"></script>

  <style>
    :root {
      --slate-50: #f8fafc;
      --slate-100: #f1f5f9;
      --slate-200: #e2e8f0;
      --slate-600: #475569;
      --slate-800: #1e293b;
    }
    body { background-color: var(--slate-50); }
    .text-label { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.6px; font-weight: 700; color: #94a3b8; margin-bottom: 2px; }
    .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1.25rem; }
    .stat-card { background: white; border: 1px solid var(--slate-200); border-radius: 1rem; padding: 1.15rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    .stat-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.35rem; margin-bottom: .75rem; }
    .card-table { border: 1px solid var(--slate-200); border-radius: 1rem; background: white; box-shadow: 0 1px 3px rgba(0,0,0,0.05); overflow: hidden; }
    .table-custom thead th { font-size: .75rem; text-transform: uppercase; letter-spacing: .5px; color: var(--slate-600); background: var(--slate-50); border-bottom: 1px solid var(--slate-200); font-weight: 600; padding: 1rem 1.1rem; white-space: nowrap; }
    .table-custom tbody td { padding: 1rem 1.1rem; border-bottom: 1px solid var(--slate-100); font-size: .95rem; color: var(--slate-800); vertical-align: top; }
    .table-custom tr:hover td { background-color: #f8fafc; }
    .toolbar-card { border: 1px solid var(--slate-200); border-radius: 1rem; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.05); }
    .btn-tight { white-space: nowrap; }
    /* extra badges for statuses */
    .badge.s-draft{background:#e5e7eb;color:#374151}
    .badge.s-submitted{background:#dbeafe;color:#1d4ed8}
    .badge.s-verified{background:#fef3c7;color:#92400e}
    .badge.s-approved{background:#dcfce7;color:#065f46}
    .badge.s-rejected{background:#fee2e2;color:#991b1b}
    .badge.s-archived{background:#f3f4f6;color:#374151}
    .alert-chip{display:inline-block;padding:2px 8px;border-radius:999px;font-size:.75rem}
    .a-expired{background:#fee2e2;color:#991b1b}
    .a-soon{background:#fef3c7;color:#92400e}
    .form-inline-gap > * { margin-right:.5rem; margin-bottom:.5rem; }
    .form-inline-gap > *:last-child{ margin-right:0; }
    .source-pill { display:inline-block; padding: 2px 8px; border-radius: 999px; font-size: .74rem; background:#eef2ff; color:#3730a3; }
  </style>
</head>
<body class="saas-page">
  <div class="container-fluid p-0">
    <div class="row g-0">

      <?php include __DIR__ . '/../includes/sidebar.php' ?>

      <!-- Main Content -->
      <div class="col main-content p-3 p-lg-4">
        <!-- Topbar -->
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div class="d-flex align-items-center gap-3">
            <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2" aria-label="Toggle sidebar">
              <ion-icon name="menu-outline"></ion-icon>
            </button>
            <h2 class="m-0 d-flex align-items-center gap-2 page-title">
              <ion-icon name="document-text-outline"></ion-icon> Document Tracking
            </h2>
          </div>
          <div class="profile-menu" data-profile-menu>
            <button class="profile-trigger" type="button" data-profile-trigger aria-expanded="false" aria-haspopup="true">
              <img src="../img/profile.jpg" class="rounded-circle" width="36" height="36" alt="">
              <div class="profile-text">
                <div class="profile-name"><?= htmlspecialchars($userName) ?></div>
                <div class="profile-role"><?= htmlspecialchars($userRole) ?></div>
              </div>
              <ion-icon class="profile-caret" name="chevron-down-outline"></ion-icon>
            </button>
            <div class="profile-dropdown" data-profile-dropdown role="menu">
              <a href="<?= u('auth/logout.php') ?>" role="menuitem">Sign out</a>
            </div>
          </div>
        </div>

        <!-- KPI Cards -->
        <div class="stats-row">
          <div class="col-6 col-md-3">
            <div class="stat-card h-100">
              <div class="d-flex align-items-center gap-3">
                <div class="rounded-3 p-2 bg-primary-subtle">
                  <ion-icon name="documents-outline" style="font-size:20px"></ion-icon>
                </div>
                <div>
                  <div class="text-muted small">Total Documents</div>
                  <div class="h4 m-0"><?= (int)$totalDocs ?></div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="stat-card h-100">
              <div class="d-flex align-items-center gap-3">
                <div class="rounded-3 p-2 bg-warning-subtle">
                  <ion-icon name="time-outline" style="font-size:20px"></ion-icon>
                </div>
                <div>
                  <div class="text-muted small">Pending</div>
                  <div class="h4 m-0"><?= (int)$pending ?></div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="stat-card h-100">
              <div class="d-flex align-items-center gap-3">
                <div class="rounded-3 p-2 bg-info-subtle">
                  <ion-icon name="alert-circle-outline" style="font-size:20px"></ion-icon>
                </div>
                <div>
                  <div class="text-muted small">Expiring (30d)</div>
                  <div class="h4 m-0"><?= (int)$expiringSoon ?></div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="stat-card h-100">
              <div class="d-flex align-items-center gap-3">
                <div class="rounded-3 p-2 bg-danger-subtle">
                  <ion-icon name="skull-outline" style="font-size:20px"></ion-icon>
                </div>
                <div>
                  <div class="text-muted small">Expired</div>
                  <div class="h4 m-0"><?= (int)$expired ?></div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Filters & Export -->
        <section class="toolbar-card mb-3">
          <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
              <div class="col-12 col-md-3">
                <label class="form-label small text-muted">Status</label>
                <select name="status" class="form-select">
                  <option value="">All statuses</option>
                  <?php foreach ($statuses as $s): ?>
                    <option value="<?= h($s) ?>" <?= $filterStatus===$s?'selected':'' ?>><?= h($s) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-12 col-md-3">
                <label class="form-label small text-muted">Type</label>
                <select name="type" class="form-select">
                  <option value="">All types</option>
                  <?php foreach ($docTypes as $t): ?>
                    <option value="<?= h($t) ?>" <?= $filterType===$t?'selected':'' ?>><?= h($t) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-12 col-md-2">
                <label class="form-label small text-muted">Expiry</label>
                <select name="exp" class="form-select">
                  <option value="">Any</option>
                  <option value="soon" <?= $filterExp==='soon'?'selected':'' ?>>Expiring (30d)</option>
                  <option value="expired" <?= $filterExp==='expired'?'selected':'' ?>>Expired</option>
                </select>
              </div>
              <div class="col-12 col-md-3">
                <label class="form-label small text-muted">Search</label>
                <input name="q" class="form-control" placeholder="title / code / asset / trip" value="<?= h($filterQ) ?>">
              </div>
              <div class="col-12 col-md-1 d-grid">
                <button class="btn btn-primary" type="submit"><ion-icon name="search-outline"></ion-icon></button>
              </div>
            </form>

            <div class="d-flex justify-content-end mt-2">
              <a class="btn btn-outline-secondary btn-sm"
                 href="?action=export&status=<?= h($filterStatus) ?>&type=<?= h($filterType) ?>&q=<?= urlencode($filterQ) ?>&exp=<?= h($filterExp) ?>">
                <ion-icon name="download-outline"></ion-icon> Export CSV
              </a>
            </div>
          </div>
        </section>

        <!-- Create New Document -->
        <section class="toolbar-card mb-3">
          <div class="card-body">
            <h5 class="mb-3">Add Document</h5>
            <form method="POST" enctype="multipart/form-data" class="row g-2">
              <input type="hidden" name="op" value="create">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

              <div class="col-12 col-md-4">
                <label class="form-label small text-muted">Title</label>
                <input class="form-control" name="title" placeholder="Title" required>
              </div>

              <div class="col-12 col-md-3">
                <label class="form-label small text-muted">Type</label>
                <select name="doc_type" class="form-select" required>
                  <option value="">Select…</option>
                  <?php foreach ($docTypes as $t): ?><option><?= h($t) ?></option><?php endforeach; ?>
                </select>
              </div>

              <div class="col-12 col-md-2">
                <label class="form-label small text-muted">Document Code</label>
                <input class="form-control" name="doc_code" placeholder="Code">
              </div>

              <div class="col-12 col-md-3">
                <label class="form-label small text-muted">Attach Asset</label>
                <select name="asset_id" class="form-select">
                  <option value="">None</option>
                  <?php foreach ($assets as $a): ?><option value="<?= (int)$a['id'] ?>"><?= h($a['name']) ?></option><?php endforeach; ?>
                </select>
              </div>

              <div class="col-12 col-md-2">
                <label class="form-label small text-muted">Trip Ref</label>
                <input class="form-control" name="trip_ref" placeholder="Trip Ref">
              </div>

              <div class="col-6 col-md-2">
                <label class="form-label small text-muted">Issue Date</label>
                <input type="date" class="form-control" name="issue_date">
              </div>

              <div class="col-6 col-md-2">
                <label class="form-label small text-muted">Expiration Date</label>
                <input type="date" class="form-control" name="expiration_date">
              </div>

              <div class="col-12 col-md-3">
                <label class="form-label small text-muted">Tags</label>
                <input class="form-control" name="tags" placeholder="comma,separated">
              </div>

              <div class="col-12 col-md-3">
                <label class="form-label small text-muted">File</label>
                <input type="file" name="file" class="form-control" accept=".pdf,.png,.jpg,.jpeg,.doc,.docx,.xls,.xlsx">
              </div>

              <div class="col-12 col-md-2 d-grid">
                <label class="form-label small text-muted">&nbsp;</label>
                <button class="btn btn-violet"><ion-icon name="add-circle-outline"></ion-icon> Add</button>
              </div>
            </form>
          </div>
        </section>

        <!-- Documents Table -->
        <section class="card-table">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h5 class="mb-0">Documents</h5>
              <span class="text-muted small"><?= count($docs) ?> row(s)</span>
            </div>

            <div class="table-responsive">
              <table class="table table-custom align-middle mb-0">
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>Source</th>
                    <th>Title / Code / Tags</th>
                    <th>Type</th>
                    <th>Linked</th>
                    <th>Status / Version</th>
                    <th>Issue / Expiry</th>
                    <th>File</th>
                    <th class="text-end">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!count($docs)): ?>
                    <tr><td colspan="9" class="text-center py-4 text-muted">No documents found.</td></tr>
                  <?php endif; ?>

                  <?php foreach ($docs as $d): ?>
                  <tr>
                    <td class="text-muted">#<?= (int)$d['id'] ?></td>
                    <td><span class="source-pill"><?= h($d['source_module'] ?? 'Document Tracking') ?></span></td>

                    <td>
                      <div class="fw-semibold"><?= h($d['title']) ?></div>
                      <div class="small text-muted">Code: <?= h($d['doc_code'] ?: '—') ?></div>
                      <div class="small text-muted">Tags: <?= h($d['tags'] ?: '—') ?></div>
                    </td>

                    <td><?= h($d['doc_type']) ?></td>

                    <td>
                      <div><?= h($d['asset_name'] ?: '—') ?></div>
                      <div class="small text-muted">Trip: <?= h($d['trip_ref'] ?: '—') ?></div>
                    </td>

                    <td>
                      <?php $cls='s-'.strtolower($d['status']); ?>
                      <div><span class="badge <?= h($cls) ?>"><?= h($d['status']) ?></span></div>
                      <div class="small text-muted">v<?= (int)$d['version'] ?></div>
                    </td>

                    <td>
                      <div><?= h($d['issue_date'] ?: '—') ?></div>
                      <div>
                        <?= h($d['expiration_date'] ?: '—') ?>
                        <?php if (!empty($d['expiration_date'])): ?>
                          <?php if ((int)$d['days_to_expiry'] < 0): ?>
                            <span class="alert-chip a-expired ms-1">Expired</span>
                          <?php endif; ?>
                          <?php if ((int)$d['days_to_expiry'] >= 0 && (int)$d['days_to_expiry'] <= 30): ?>
                            <span class="alert-chip a-soon ms-1">Soon</span>
                          <?php endif; ?>
                        <?php endif; ?>
                      </div>
                    </td>

                    <td>
                      <?php if (!empty($d['is_external']) && !empty($d['external_url'])): ?>
                        <a class="btn btn-sm btn-outline-secondary btn-tight" href="<?= h($d['external_url']) ?>" target="_blank" rel="noopener">
                          <ion-icon name="link-outline"></ion-icon> Open Link
                        </a>
                      <?php elseif ($d['file_path'] && empty($d['is_external'])): ?>
                        <a class="btn btn-sm btn-outline-secondary" href="?action=download&id=<?= (int)$d['id'] ?>">
                          <ion-icon name="download-outline"></ion-icon> Download
                        </a>
                      <?php elseif ($d['file_path'] && !empty($d['is_external'])): ?>
                        <span class="text-muted"><?= h(basename((string)$d['file_path'])) ?></span>
                      <?php else: ?>
                        <span class="text-muted">No file</span>
                      <?php endif; ?>
                    </td>

                    <td class="text-end">
                      <?php if (!empty($d['is_external'])): ?>
                        <span class="text-muted small">Read-only (source module)</span>
                      <?php else: ?>
                      <button class="btn btn-sm btn-outline-primary me-1" onclick="toggleEdit(<?= (int)$d['id'] ?>)">
                        <ion-icon name="create-outline"></ion-icon> Edit
                      </button>

                      <?php if ($d['status']==='Submitted'): ?>
                        <form method="POST" class="d-inline">
                          <input type="hidden" name="op" value="verify">
                          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                          <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                          <button class="btn btn-sm btn-outline-secondary me-1">
                            <ion-icon name="shield-checkmark-outline"></ion-icon> Verify
                          </button>
                        </form>
                      <?php endif; ?>

                      <?php if ($d['status']==='Submitted' || $d['status']==='Verified'): ?>
                        <form method="POST" class="d-inline">
                          <input type="hidden" name="op" value="approve">
                          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                          <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                          <button class="btn btn-sm btn-success me-1">
                            <ion-icon name="checkmark-circle-outline"></ion-icon> Approve
                          </button>
                        </form>
                      <?php endif; ?>

                      <?php if ($d['status']!=='Approved'): ?>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Reject this document?')">
                          <input type="hidden" name="op" value="reject">
                          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                          <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                          <button class="btn btn-sm btn-outline-danger me-1">
                            <ion-icon name="close-circle-outline"></ion-icon> Reject
                          </button>
                        </form>
                      <?php endif; ?>

                      <form method="POST" enctype="multipart/form-data" class="d-inline align-middle">
                        <input type="hidden" name="op" value="new_version">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                        <input type="file" name="vfile" accept=".pdf,.png,.jpg,.jpeg,.doc,.docx,.xls,.xlsx" class="form-control form-control-sm d-inline-block mb-1" style="max-width:200px">
                        <input name="vnotes" class="form-control form-control-sm d-inline-block mb-1" placeholder="Version notes" style="max-width:160px">
                        <button class="btn btn-sm btn-outline-dark me-1"><ion-icon name="cloud-upload-outline"></ion-icon> Upload v+</button>
                      </form>

                      <form method="POST" class="d-inline" onsubmit="return confirm('Delete this document?')">
                        <input type="hidden" name="op" value="delete">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger">
                          <ion-icon name="trash-outline"></ion-icon> Delete
                        </button>
                      </form>
                      <?php endif; ?>
                    </td>
                  </tr>

                  <!-- Inline Edit Row -->
                  <?php if (empty($d['is_external'])): ?>
                  <tr id="edit-<?= (int)$d['id'] ?>" style="display:none;background:#f6f8ff">
                    <td colspan="9">
                      <form method="POST" class="row g-2 align-items-end">
                        <input type="hidden" name="op" value="update">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">

                        <div class="col-12 col-md-4">
                          <label class="form-label small text-muted">Title</label>
                          <input class="form-control" name="title" value="<?= h($d['title']) ?>" required>
                        </div>

                        <div class="col-12 col-md-3">
                          <label class="form-label small text-muted">Type</label>
                          <select name="doc_type" class="form-select" required>
                            <?php foreach ($docTypes as $t): ?><option <?= ($d['doc_type']===$t?'selected':'') ?>><?= h($t) ?></option><?php endforeach; ?>
                          </select>
                        </div>

                        <div class="col-12 col-md-2">
                          <label class="form-label small text-muted">Document Code</label>
                          <input class="form-control" name="doc_code" value="<?= h($d['doc_code']) ?>">
                        </div>

                        <div class="col-12 col-md-3">
                          <label class="form-label small text-muted">Attach Asset</label>
                          <select name="asset_id" class="form-select">
                            <option value="">None</option>
                            <?php foreach ($assets as $a): ?>
                              <option value="<?= (int)$a['id'] ?>" <?= $d['asset_id']==$a['id']?'selected':'' ?>><?= h($a['name']) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>

                        <div class="col-6 col-md-2">
                          <label class="form-label small text-muted">Trip Ref</label>
                          <input class="form-control" name="trip_ref" value="<?= h($d['trip_ref']) ?>">
                        </div>

                        <div class="col-6 col-md-2">
                          <label class="form-label small text-muted">Issue Date</label>
                          <input type="date" class="form-control" name="issue_date" value="<?= h($d['issue_date']) ?>">
                        </div>

                        <div class="col-6 col-md-2">
                          <label class="form-label small text-muted">Expiration Date</label>
                          <input type="date" class="form-control" name="expiration_date" value="<?= h($d['expiration_date']) ?>">
                        </div>

                        <div class="col-12 col-md-3">
                          <label class="form-label small text-muted">Tags</label>
                          <input class="form-control" name="tags" value="<?= h($d['tags']) ?>">
                        </div>

                        <div class="col-12 col-md-2">
                          <label class="form-label small text-muted">Status</label>
                          <select name="status" class="form-select">
                            <?php foreach ($statuses as $s): ?><option <?= ($d['status']===$s?'selected':'') ?>><?= h($s) ?></option><?php endforeach; ?>
                          </select>
                        </div>

                        <div class="col-12 col-md-3 d-flex gap-2">
                          <button class="btn btn-primary"><ion-icon name="save-outline"></ion-icon> Save</button>
                          <button class="btn btn-outline-secondary" type="button" onclick="toggleEdit(<?= (int)$d['id'] ?>)"><ion-icon name="close-outline"></ion-icon> Cancel</button>
                        </div>
                      </form>
                    </td>
                  </tr>
                  <?php endif; ?>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

          </div>
        </section>

      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="../js/profile-dropdown.js"></script>
  <script>
    function toggleEdit(id){
      const el = document.getElementById('edit-'+id);
      if(!el) return;
      el.style.display = (el.style.display==='none' || !el.style.display) ? 'table-row' : 'none';
    }
    // Auto-refresh on changes from other tabs
    window.addEventListener('storage', function(e){ if (e.key==='docs_changed') { window.location.reload(); }});
  </script>
</body>
</html>
