<?php
require_once __DIR__ . "/../includes/config.php";
require_once __DIR__ . "/../includes/auth.php";
require_login();

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'DB connection failed';
    exit;
}

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
        header('Location: doc.php'); exit;
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
        header('Location: doc.php'); exit;
    }

    if ($op === 'new_version') {
        assert_csrf();
        $id = (int)($_POST['id'] ?? 0);
        $notes = trim($_POST['vnotes'] ?? '');
        if ($id > 0 && !empty($_FILES['vfile']['name'])) {
            if (!allowed_file($_FILES['vfile']['name'])) { header('Location: doc.php?err=Invalid%20file%20type'); exit; }
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
        header('Location: doc.php'); exit;
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
        header('Location: doc.php'); exit;
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
        header('Location: doc.php'); exit;
    }
}

// Filters and list
$filterStatus = $_GET['status'] ?? '';
$filterType = $_GET['type'] ?? '';
$filterQ = trim($_GET['q'] ?? '');
$filterExp = $_GET['exp'] ?? ''; // soon, expired
$params=[]; $where=[];
if ($filterStatus !== '') { $where[]='d.status = :st'; $params[':st']=$filterStatus; }
if ($filterType !== '') { $where[]='d.doc_type = :tp'; $params[':tp']=$filterType; }
if ($filterQ !== '') { $where[]='(d.title LIKE :q OR d.doc_code LIKE :q OR a.name LIKE :q OR d.trip_ref LIKE :q)'; $params[':q']='%'.$filterQ.'%'; }
if ($filterExp === 'soon') { $where[]='d.expiration_date IS NOT NULL AND d.expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)'; }
if ($filterExp === 'expired') { $where[]='d.expiration_date IS NOT NULL AND d.expiration_date < CURDATE()'; }
$w = $where ? ('WHERE '.implode(' AND ',$where)) : '';

$docsStmt = $pdo->prepare("SELECT d.*, a.name AS asset_name, DATEDIFF(d.expiration_date, CURDATE()) AS days_to_expiry FROM documents d LEFT JOIN assets a ON d.asset_id=a.id $w ORDER BY d.updated_at DESC, d.id DESC LIMIT 1000");
$docsStmt->execute($params);
$docs = $docsStmt->fetchAll();

// Stats
$totalDocs = (int)$pdo->query("SELECT COUNT(*) FROM documents")->fetchColumn();
$pending = (int)$pdo->query("SELECT COUNT(*) FROM documents WHERE status IN ('Draft','Submitted','Verified')")->fetchColumn();
$expiringSoon = (int)$pdo->query("SELECT COUNT(*) FROM documents WHERE expiration_date IS NOT NULL AND expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND expiration_date >= CURDATE()")->fetchColumn();
$expired = (int)$pdo->query("SELECT COUNT(*) FROM documents WHERE expiration_date IS NOT NULL AND expiration_date < CURDATE() ")->fetchColumn();

$docTypes = ['Transport Manifest','Delivery Receipt','Vehicle Registration','Driver ID','Permit','Insurance','Other'];
$statuses = ['Draft','Submitted','Verified','Approved','Rejected','Archived'];

// Fetch assets for linking
$assets = $pdo->query("SELECT id, name FROM assets ORDER BY name ASC")->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>TNVS Document Tracking</title>
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<style>
:root{ --bg:#f5f7fb; --card:#fff; --accent:#0f62fe; --muted:#6b7280; --text:#111827; --danger:#ef4444; --success:#10b981; --warning:#f59e0b; }
*{box-sizing:border-box}
body{margin:0;font-family:Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial;background:linear-gradient(180deg,#f7f9fc 0%,var(--bg) 100%);color:var(--text);padding:22px}
.container{max-width:1260px;margin:0 auto}
.header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}
.btn{display:inline-flex;align-items:center;gap:8px;background:var(--accent);color:#fff;border:0;padding:10px 14px;border-radius:10px;box-shadow:0 4px 12px rgba(16,24,40,0.06);cursor:pointer;font-weight:600;font-size:14px;text-decoration:none}
.btn.ghost{background:transparent;color:var(--accent);border:1px solid rgba(15,98,254,0.2)}
.card{background:var(--card);padding:16px;border-radius:12px;box-shadow:0 10px 30px rgba(16,24,40,0.08);border:1px solid rgba(16,24,40,0.03)}
.grid{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:12px}
@media (max-width:1000px){.grid{grid-template-columns:1fr 1fr}}
.stat{padding:12px;border-radius:10px;background:linear-gradient(180deg,#ffffff,#fbfdff);border:1px solid rgba(14,165,233,0.06)}
.stat .label{font-size:12px;color:var(--muted)}
.stat .number{font-size:20px;font-weight:700}
.form-row{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start}
.input,.select,textarea{padding:10px 12px;border-radius:10px;border:1px solid #e6edf6;background:transparent;font-size:14px;color:var(--text)}
.table-wrap{overflow:auto;border-radius:10px;border:1px solid rgba(17,24,39,0.06)}
.table{width:100%;border-collapse:collapse;min-width:1100px}
.table thead th{text-align:left;padding:12px 14px;background:linear-gradient(180deg,#fbfdff,#f7f9fc);font-size:13px;color:var(--muted);border-bottom:1px solid rgba(15,23,42,0.06)}
.table tbody td{padding:12px 14px;border-bottom:1px solid rgba(15,23,42,0.06);font-size:14px;vertical-align:top}
.badge{display:inline-block;padding:6px 10px;border-radius:999px;font-weight:600;font-size:12px}
.s-draft{background:rgba(107,114,128,0.1);color:#6b7280}
.s-submitted{background:rgba(59,130,246,0.08);color:#2563eb}
.s-verified{background:rgba(234,179,8,0.14);color:var(--warning)}
.s-approved{background:rgba(16,185,129,0.12);color:var(--success)}
.s-rejected{background:rgba(239,68,68,0.12);color:var(--danger)}
.s-archived{background:rgba(17,24,39,0.08);color:#111827}
.alert{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px}
.a-expired{background:rgba(239,68,68,0.12);color:var(--danger)}
.a-soon{background:rgba(245,158,11,0.12);color:var(--warning)}
</style>
</head>
<body>
<div class="container">
  <header class="header">
    <div style="display:flex;gap:8px;align-items:center">
      <a href="DTLR.php" class="btn ghost"><i class='bx bx-arrow-back'></i> Back</a>
      <a href="logistic.php" class="btn ghost"><i class='bx bx-package'></i> logistics Record</a>
    
    </div>
    <div>
      <h2 style="margin:0;display:flex;align-items:center;gap:8px"><i class='bx bx-file-blank'></i> TNVS Document Tracking</h2>
      <div style="font-size:13px;color:var(--muted)">Creation • Verification • Indexing • Storage • Activity • Retrieval • Expiry</div>
    </div>
    <div>
      <a class="btn" href="?action=export&status=<?= h($filterStatus) ?>&type=<?= h($filterType) ?>&q=<?= urlencode($filterQ) ?>&exp=<?= h($filterExp) ?>"><i class='bx bx-download'></i> Export CSV</a>
    </div>
  </header>

  <section class="card">
    <div class="grid">
      <div class="stat"><div class="label">Total Documents</div><div class="number"><?= (int)$totalDocs ?></div></div>
      <div class="stat"><div class="label">Pending (Draft/Submitted/Verified)</div><div class="number"><?= (int)$pending ?></div></div>
      <div class="stat"><div class="label">Expiring in 30 days</div><div class="number"><?= (int)$expiringSoon ?></div></div>
      <div class="stat"><div class="label">Expired</div><div class="number"><?= (int)$expired ?></div></div>
    </div>
  </section>

  <section class="card" style="margin-top:14px">
    <form method="get" class="form-row">
      <select name="status" class="select">
        <option value="">All statuses</option>
        <?php foreach ($statuses as $s): ?><option value="<?= h($s) ?>" <?= $filterStatus===$s?'selected':'' ?>><?= h($s) ?></option><?php endforeach; ?>
      </select>
      <select name="type" class="select">
        <option value="">All types</option>
        <?php foreach ($docTypes as $t): ?><option value="<?= h($t) ?>" <?= $filterType===$t?'selected':'' ?>><?= h($t) ?></option><?php endforeach; ?>
      </select>
      <select name="exp" class="select">
        <option value="">Expiry: Any</option>
        <option value="soon" <?= $filterExp==='soon'?'selected':'' ?>>Expiring (30d)</option>
        <option value="expired" <?= $filterExp==='expired'?'selected':'' ?>>Expired</option>
      </select>
      <input name="q" class="input" placeholder="Search title/code/asset/trip" value="<?= h($filterQ) ?>">
      <button class="btn" type="submit"><i class='bx bx-filter'></i> Apply</button>
    </form>
  </section>

  <section class="card" style="margin-top:14px">
    <form method="POST" enctype="multipart/form-data" class="form-row" style="gap:8px;align-items:flex-start">
      <input type="hidden" name="op" value="create">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input class="input" name="title" placeholder="Title" required>
      <select name="doc_type" class="select" required>
        <option value="">Type</option>
        <?php foreach ($docTypes as $t): ?><option><?= h($t) ?></option><?php endforeach; ?>
      </select>
      <input class="input" name="doc_code" placeholder="Document Code">
      <select name="asset_id" class="select">
        <option value="">Attach Asset</option>
        <?php foreach ($assets as $a): ?><option value="<?= (int)$a['id'] ?>"><?= h($a['name']) ?></option><?php endforeach; ?>
      </select>
      <input class="input" name="trip_ref" placeholder="Trip Ref">
      <input type="date" class="input" name="issue_date" title="Issue date">
      <input type="date" class="input" name="expiration_date" title="Expiration date">
      <input class="input" name="tags" placeholder="Tags (comma-separated)">
      <input type="file" name="file" class="input" accept=".pdf,.png,.jpg,.jpeg,.doc,.docx,.xls,.xlsx">
      <button class="btn" type="submit"><i class='bx bx-plus'></i> Add Document</button>
    </form>
  </section>

  <section class="card" style="margin-top:14px">
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Title / Code</th>
            <th>Type</th>
            <th>Linked</th>
            <th>Status / Version</th>
            <th>Issue / Expiry</th>
            <th>File</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($docs as $d): ?>
          <tr>
            <td><?= (int)$d['id'] ?></td>
            <td>
              <div style="font-weight:600"><?= h($d['title']) ?></div>
              <div style="color:#6b7280;font-size:12px">Code: <?= h($d['doc_code'] ?: '—') ?></div>
              <div style="color:#6b7280;font-size:12px">Tags: <?= h($d['tags'] ?: '—') ?></div>
            </td>
            <td><?= h($d['doc_type']) ?></td>
            <td>
              <div><?= h($d['asset_name'] ?: '—') ?></div>
              <div style="color:#6b7280;font-size:12px">Trip: <?= h($d['trip_ref'] ?: '—') ?></div>
            </td>
            <td>
              <?php $cls='s-'.strtolower($d['status']); ?>
              <div><span class="badge <?= h($cls) ?>"><?= h($d['status']) ?></span></div>
              <div style="color:#6b7280;font-size:12px">v<?= (int)$d['version'] ?></div>
            </td>
            <td>
              <div><?= h($d['issue_date'] ?: '—') ?></div>
              <div><?= h($d['expiration_date'] ?: '—') ?>
              <?php if (!empty($d['expiration_date'])): ?>
                <?php if ((int)$d['days_to_expiry'] < 0): ?><span class="alert a-expired">Expired</span><?php endif; ?>
                <?php if ((int)$d['days_to_expiry'] >= 0 && (int)$d['days_to_expiry'] <= 30): ?><span class="alert a-soon">Soon</span><?php endif; ?>
              <?php endif; ?>
              </div>
            </td>
            <td>
              <?php if ($d['file_path']): ?>
                <a class="btn ghost" href="?action=download&id=<?= (int)$d['id'] ?>"><i class='bx bx-download'></i> Download</a>
              <?php else: ?>
                <span style="color:#6b7280">No file</span>
              <?php endif; ?>
            </td>
            <td>
              <button class="btn ghost" onclick="toggleEdit(<?= (int)$d['id'] ?>)"><i class='bx bx-edit-alt'></i> Edit</button>
              <?php if ($d['status']==='Submitted' || $d['status']==='Verified'): ?>
                <form method="POST" style="display:inline">
                  <input type="hidden" name="op" value="approve"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                  <button class="btn"><i class='bx bx-check'></i> Approve</button>
                </form>
              <?php endif; ?>
              <?php if ($d['status']==='Submitted'): ?>
                <form method="POST" style="display:inline">
                  <input type="hidden" name="op" value="verify"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                  <button class="btn"><i class='bx bx-shield-quarter'></i> Verify</button>
                </form>
              <?php endif; ?>
              <?php if ($d['status']!=='Approved'): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('Reject this document?')">
                  <input type="hidden" name="op" value="reject"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                  <button class="btn" style="background:var(--danger)"><i class='bx bx-x'></i> Reject</button>
                </form>
              <?php endif; ?>
              <form method="POST" enctype="multipart/form-data" style="display:inline">
                <input type="hidden" name="op" value="new_version"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <input type="file" name="vfile" accept=".pdf,.png,.jpg,.jpeg,.doc,.docx,.xls,.xlsx" class="input" style="max-width:200px">
                <input name="vnotes" class="input" placeholder="Version notes" style="max-width:160px">
                <button class="btn"><i class='bx bx-upload'></i> Upload v+</button>
              </form>
              <form method="POST" style="display:inline" onsubmit="return confirm('Delete this document?')">
                <input type="hidden" name="op" value="delete"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <button class="btn" style="background:var(--danger)"><i class='bx bx-trash'></i> Delete</button>
              </form>
            </td>
          </tr>
          <tr id="edit-<?= (int)$d['id'] ?>" style="display:none;background:#eef4ff">
            <td colspan="8">
              <form method="POST" class="form-row" style="gap:8px;align-items:flex-start">
                <input type="hidden" name="op" value="update">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <input class="input" name="title" value="<?= h($d['title']) ?>" placeholder="Title" required>
                <select name="doc_type" class="select" required>
                  <?php foreach ($docTypes as $t): ?><option <?= ($d['doc_type']===$t?'selected':'') ?>><?= h($t) ?></option><?php endforeach; ?>
                </select>
                <input class="input" name="doc_code" value="<?= h($d['doc_code']) ?>" placeholder="Document Code">
                <select name="asset_id" class="select">
                  <option value="">Attach Asset</option>
                  <?php foreach ($assets as $a): ?><option value="<?= (int)$a['id'] ?>" <?= $d['asset_id']==$a['id']?'selected':'' ?>><?= h($a['name']) ?></option><?php endforeach; ?>
                </select>
                <input class="input" name="trip_ref" value="<?= h($d['trip_ref']) ?>" placeholder="Trip Ref">
                <input type="date" class="input" name="issue_date" value="<?= h($d['issue_date']) ?>" title="Issue date">
                <input type="date" class="input" name="expiration_date" value="<?= h($d['expiration_date']) ?>" title="Expiration date">
                <input class="input" name="tags" value="<?= h($d['tags']) ?>" placeholder="Tags">
                <select name="status" class="select">
                  <?php foreach ($statuses as $s): ?><option <?= ($d['status']===$s?'selected':'') ?>><?= h($s) ?></option><?php endforeach; ?>
                </select>
                <button class="btn" type="submit"><i class='bx bx-save'></i> Save</button>
                <button class="btn ghost" type="button" onclick="toggleEdit(<?= (int)$d['id'] ?>)"><i class='bx bx-x'></i> Cancel</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>

<script>
function toggleEdit(id){ const el=document.getElementById('edit-'+id); if(!el) return; el.style.display=(el.style.display==='none'||!el.style.display)?'table-row':'none'; }
// Auto-refresh on changes from other tabs
window.addEventListener('storage', function(e){ if (e.key==='docs_changed') { window.location.reload(); }});
</script>
</body>
</html>

