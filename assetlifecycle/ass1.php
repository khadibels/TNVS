<?php
// TNVS Asset Tracking — Professional Asset Lifecycle Module (PDO + prepared statements + CSRF + filters + CSV)
// Stages covered: Registration, Deployment, Active Tracking (metadata), Maintenance integration, Evaluation, Retirement

session_start();

// Role-based access
$accountType = $_SESSION['Account_type'] ?? null;
$loggedInEmail = $_SESSION['Email'] ?? null;
if (!$accountType) { header('Location: login.php'); exit; }
$isAdmin = ($accountType == '1');

// DB config
$DB_HOST = '127.0.0.1';
$DB_NAME = 'alms_db';
$DB_USER = 'root';
$DB_PASS = '';

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

// Ensure assets table exists and extend with TNVS lifecycle fields
$pdo->exec("CREATE TABLE IF NOT EXISTS assets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  status VARCHAR(50) NOT NULL,
  installed_on DATE,
  disposed_on DATE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$maybeCols = [
  "unique_id VARCHAR(64) UNIQUE",
  "asset_type VARCHAR(50)",
  "manufacturer VARCHAR(120)",
  "model VARCHAR(120)",
  "purchase_date DATE",
  "purchase_cost DECIMAL(12,2)",
  "department VARCHAR(120)",
  "driver VARCHAR(120)",
  "route VARCHAR(120)",
  "depot VARCHAR(120)",
  "deployment_date DATE",
  "retired_on DATE",
  "gps_imei VARCHAR(32)",
  "notes TEXT",
  "created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP",
  "updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
];
foreach ($maybeCols as $def) { try { $pdo->exec("ALTER TABLE assets ADD COLUMN IF NOT EXISTS $def"); } catch (Throwable $e) {} }

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function money_fmt($v){ return number_format((float)$v, 2); }

// CSRF
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(16)); }
$csrf = $_SESSION['csrf_token'];
function assert_csrf(){ if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) { http_response_code(400); exit('Invalid CSRF'); } }

// Filters
$fStatus = $_GET['status'] ?? '';
$fType = $_GET['type'] ?? '';
$fDept = $_GET['dept'] ?? '';
$fQ = trim($_GET['q'] ?? '');

// CRUD (admin-only mutations)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) { http_response_code(403); exit('Forbidden'); }
    $op = $_POST['op'] ?? '';

    if ($op === 'add') {
        assert_csrf();
        $name = trim($_POST['name'] ?? '');
        $unique_id = trim($_POST['unique_id'] ?? '');
        $asset_type = $_POST['asset_type'] ?? '';
        $manufacturer = trim($_POST['manufacturer'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $purchase_date = $_POST['purchase_date'] ?? null;
        $purchase_cost = $_POST['purchase_cost'] !== '' ? (float)$_POST['purchase_cost'] : null;
        $department = trim($_POST['department'] ?? '');
        $driver = trim($_POST['driver'] ?? '');
        $route = trim($_POST['route'] ?? '');
        $depot = trim($_POST['depot'] ?? '');
        $status = $_POST['status'] ?? 'Registered';
        $deployment_date = $_POST['deployment_date'] ?? null;
        $gps_imei = trim($_POST['gps_imei'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        if ($name !== '') {
            $stmt = $pdo->prepare("INSERT INTO assets (name, unique_id, asset_type, manufacturer, model, purchase_date, purchase_cost, department, driver, route, depot, status, deployment_date, installed_on, gps_imei, notes) VALUES (:name,:unique_id,:asset_type,:manufacturer,:model,:purchase_date,:purchase_cost,:department,:driver,:route,:depot,:status,:deployment_date,:installed_on,:gps_imei,:notes)");
            $stmt->execute([
                ':name'=>$name,
                ':unique_id'=>$unique_id ?: null,
                ':asset_type'=>$asset_type ?: null,
                ':manufacturer'=>$manufacturer ?: null,
                ':model'=>$model ?: null,
                ':purchase_date'=>$purchase_date ?: null,
                ':purchase_cost'=>$purchase_cost,
                ':department'=>$department ?: null,
                ':driver'=>$driver ?: null,
                ':route'=>$route ?: null,
                ':depot'=>$depot ?: null,
                ':status'=>$status,
                ':deployment_date'=>$deployment_date ?: null,
                ':installed_on'=>$deployment_date ?: null, // backward compatibility
                ':gps_imei'=>$gps_imei ?: null,
                ':notes'=>$notes ?: null,
            ]);
            $newId = (int)$pdo->lastInsertId();
            if ((isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH']=='XMLHttpRequest') || isset($_POST['ajax'])) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'id' => $newId,
                    'name' => $name,
                    'asset_type' => $asset_type
                ]);
                exit;
            } else {
                echo "<script>try{localStorage.setItem('assets_changed', Date.now().toString())}catch(e){}</script>";
                header('Location: ass1.php?highlight_id='.$newId); exit;
            }
        }
    }

    if ($op === 'update') {
        assert_csrf();
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $name = trim($_POST['name'] ?? '');
            $unique_id = trim($_POST['unique_id'] ?? '');
            $asset_type = $_POST['asset_type'] ?? '';
            $manufacturer = trim($_POST['manufacturer'] ?? '');
            $model = trim($_POST['model'] ?? '');
            $purchase_date = $_POST['purchase_date'] ?? null;
            $purchase_cost = $_POST['purchase_cost'] !== '' ? (float)$_POST['purchase_cost'] : null;
            $department = trim($_POST['department'] ?? '');
            $driver = trim($_POST['driver'] ?? '');
            $route = trim($_POST['route'] ?? '');
            $depot = trim($_POST['depot'] ?? '');
            $status = $_POST['status'] ?? 'Registered';
            $deployment_date = $_POST['deployment_date'] ?? null;
            $retired_on = $_POST['retired_on'] ?? null;
            $gps_imei = trim($_POST['gps_imei'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            $stmt = $pdo->prepare("UPDATE assets SET name=:name, unique_id=:unique_id, asset_type=:asset_type, manufacturer=:manufacturer, model=:model, purchase_date=:purchase_date, purchase_cost=:purchase_cost, department=:department, driver=:driver, route=:route, depot=:depot, status=:status, deployment_date=:deployment_date, installed_on=:installed_on, retired_on=:retired_on, disposed_on=:disposed_on, gps_imei=:gps_imei, notes=:notes WHERE id=:id");
            $stmt->execute([
                ':id'=>$id,
                ':name'=>$name,
                ':unique_id'=>$unique_id ?: null,
                ':asset_type'=>$asset_type ?: null,
                ':manufacturer'=>$manufacturer ?: null,
                ':model'=>$model ?: null,
                ':purchase_date'=>$purchase_date ?: null,
                ':purchase_cost'=>$purchase_cost,
                ':department'=>$department ?: null,
                ':driver'=>$driver ?: null,
                ':route'=>$route ?: null,
                ':depot'=>$depot ?: null,
                ':status'=>$status,
                ':deployment_date'=>$deployment_date ?: null,
                ':installed_on'=>$deployment_date ?: null,
                ':retired_on'=>$retired_on ?: null,
                ':disposed_on'=>$retired_on ?: null,
                ':gps_imei'=>$gps_imei ?: null,
                ':notes'=>$notes ?: null,
            ]);
            echo "<script>try{localStorage.setItem('assets_changed', Date.now().toString())}catch(e){}</script>";
            header('Location: ass1.php?highlight_id='.$id); exit;
        }
    }

    if ($op === 'transition') {
        assert_csrf();
        $id = (int)($_POST['id'] ?? 0);
        $to = $_POST['to'] ?? '';
        if ($id > 0 && in_array($to, ['Registered','Deployed','Active','In Maintenance','Retired','Disposed'], true)) {
            $fields = ['status' => $to];
            $today = date('Y-m-d');
            if ($to === 'Deployed') { $fields['deployment_date'] = $today; $fields['installed_on'] = $today; }
            if ($to === 'Retired') { $fields['retired_on'] = $today; }
            if ($to === 'Disposed') { $fields['disposed_on'] = $today; }
            $set = [];
            $params = [':id' => $id];
            foreach ($fields as $col => $val) { $set[] = "$col = :$col"; $params[":$col"] = $val; }
            $sql = "UPDATE assets SET ".implode(', ', $set)." WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            echo "<script>try{localStorage.setItem('assets_changed', Date.now().toString())}catch(e){}</script>";
            header('Location: ass1.php?highlight_id='.$id); exit;
        }
        header('Location: ass1.php'); exit;
    }

    if ($op === 'delete') {
        assert_csrf();
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM assets WHERE id=:id")->execute([':id'=>$id]);
            echo "<script>try{localStorage.setItem('assets_changed', Date.now().toString())}catch(e){}</script>";
        }
        header('Location: ass1.php'); exit;
    }
}

// Export CSV
if (($_GET['action'] ?? '') === 'export') {
    $params = [];
    $where = [];
    if ($fStatus !== '') { $where[] = 'status = :status'; $params[':status'] = $fStatus; }
    if ($fType !== '') { $where[] = 'asset_type = :type'; $params[':type'] = $fType; }
    if ($fDept !== '') { $where[] = 'department = :dept'; $params[':dept'] = $fDept; }
    if ($fQ !== '') { $where[] = '(name LIKE :q OR unique_id LIKE :q OR driver LIKE :q OR route LIKE :q)'; $params[':q'] = '%'.$fQ.'%'; }
    $w = $where ? ('WHERE '.implode(' AND ',$where)) : '';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=assets_'.date('Ymd_His').'.csv');
    $out=fopen('php://output','w');
    fputcsv($out, ['id','name','unique_id','asset_type','manufacturer','model','purchase_date','purchase_cost','department','driver','route','depot','status','deployment_date','retired_on','gps_imei','notes','created_at']);
    $stmt = $pdo->prepare("SELECT id,name,unique_id,asset_type,manufacturer,model,purchase_date,purchase_cost,department,driver,route,depot,status,deployment_date,retired_on,gps_imei,notes,created_at FROM assets $w ORDER BY id DESC");
    $stmt->execute($params);
    while ($r = $stmt->fetch()) { fputcsv($out, $r); }
    fclose($out); exit;
}

// Filters query
$params = [];
$where = [];
if ($fStatus !== '') { $where[] = 'status = :status'; $params[':status'] = $fStatus; }
if ($fType !== '') { $where[] = 'asset_type = :type'; $params[':type'] = $fType; }
if ($fDept !== '') { $where[] = 'department = :dept'; $params[':dept'] = $fDept; }
if ($fQ !== '') { $where[] = '(name LIKE :q OR unique_id LIKE :q OR driver LIKE :q OR route LIKE :q)'; $params[':q'] = '%'.$fQ.'%'; }
$w = $where ? ('WHERE '.implode(' AND ',$where)) : '';

// Fetch list
$sqlList = "SELECT * FROM assets $w ORDER BY id DESC LIMIT 1000";
$stmtList = $pdo->prepare($sqlList);
$stmtList->execute($params);
$assets = $stmtList->fetchAll();

// Stats
$stat = function($cond) use ($pdo){ $stmt=$pdo->query("SELECT COUNT(*) FROM assets WHERE $cond"); return (int)$stmt->fetchColumn(); };
$totalAssets = (int)$pdo->query("SELECT COUNT(*) FROM assets")->fetchColumn();
$registered = $stat("status='Registered'");
$deployed = $stat("status='Deployed'");
$active = $stat("status='Active'");
$inMaint = $stat("status='In Maintenance'");
$retired = $stat("status='Retired' OR status='Disposed'");
$purchaseSum = (float)$pdo->query("SELECT COALESCE(SUM(purchase_cost),0) FROM assets")->fetchColumn();

$statuses = ['Registered','Deployed','Active','In Maintenance','Retired','Disposed'];
$types = ['Vehicle','GPS Tracker','RFID Tag','Spare Part','Device','Other'];
$departments = ['Operations','Maintenance','Logistics','Compliance','IT','Other'];
$highlightId = isset($_GET['highlight_id']) ? (int)$_GET['highlight_id'] : 0;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>TNVS Asset Tracking — Asset Lifecycle</title>
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<style>
:root{
  --bg:#f5f7fb; --card:#fff; --accent:#0f62fe; --muted:#6b7280; --text:#111827;
  --success:#10b981; --danger:#ef4444; --warning:#f59e0b; --info:#3b82f6;
  --shadow: 0 10px 30px rgba(16,24,40,0.08);
}
*{box-sizing:border-box}
body{margin:0;font-family:Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial;background:linear-gradient(180deg,#f7f9fc 0%,var(--bg) 100%);color:var(--text);padding:22px}
.container{max-width:1260px;margin:0 auto}
.header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}
.btn{display:inline-flex;align-items:center;gap:8px;background:#6d28d9;color:#fff;border:0;padding:10px 14px;border-radius:10px;box-shadow:0 4px 12px rgba(16,24,40,0.06);cursor:pointer;font-weight:600;font-size:14px;text-decoration:none}
.btn.ghost{background:transparent;color:#6d28d9;border:1px solid rgba(150,150,255,.2)}
.card{background:var(--card);padding:16px;border-radius:12px;box-shadow:var(--shadow);border:1px solid rgba(16,24,40,0.03)}
.grid{display:grid;grid-template-columns:1fr 1fr 1fr 1fr 1fr;gap:12px}
@media (max-width:1200px){.grid{grid-template-columns:1fr 1fr 1fr}}
@media (max-width:760px){.grid{grid-template-columns:1fr 1fr}}
.stat{padding:12px;border-radius:10px;background:linear-gradient(180deg,#ffffff,#fbfdff);border:1px solid rgba(14,165,233,0.06)}
.stat .label{font-size:12px;color:var(--muted)}
.stat .number{font-size:20px;font-weight:700}
.input,.select,textarea{padding:10px 12px;border-radius:10px;border:1px solid #e6edf6;background:transparent;font-size:14px;color:var(--text)}
.table-wrap{overflow:auto;border-radius:10px;border:1px solid rgba(17,24,39,0.06)}
.table{width:100%;border-collapse:collapse;min-width:1100px}
.table thead th{text-align:left;padding:12px 14px;background:linear-gradient(180deg,#fbfdff,#f7f9fc);font-size:13px;color:var(--muted);border-bottom:1px solid rgba(15,23,42,0.06)}
.table tbody td{padding:12px 14px;border-bottom:1px solid rgba(15,23,42,0.06);font-size:14px;vertical-align:top}
.badge{display:inline-block;padding:6px 10px;border-radius:999px;font-weight:600;font-size:12px}
.s-registered{background:rgba(59,130,246,0.08);color:#2563eb}
.s-deployed{background:rgba(6,182,212,0.08);color:#0891b2}
.s-active{background:rgba(16,185,129,0.08);color:var(--success)}
.s-inmaintenance{background:rgba(245,158,11,0.12);color:var(--warning)}
.s-retired,.s-disposed{background:rgba(239,68,68,0.08);color:var(--danger)}
tr.highlight { box-shadow: inset 0 0 0 9999px rgba(255,221,87,0.28); }
.section{margin-top:14px}
</style>
</head>
<body>
<div class="container">
  <header class="header">
    <div style="display:flex;gap:8px;align-items:center">
      <a href="ALMS.php" class="btn ghost"><i class='bx bx-arrow-back'></i> Back</a>
      <a href="ass2.php" class="btn ghost"><i class='bx bx-pie-chart-alt-2'></i> Asset Report</a>
      <a href="repair.php" class="btn ghost"><i class='bx bx-wrench'></i> Repairs</a>
    </div>
    <div>
      <h2 style="margin:0;display:flex;align-items:center;gap:8px"><i class='bx bx-package'></i> TNVS Asset Tracking</h2>
      <div style="font-size:13px;color:var(--muted)">Registration • Deployment • Monitoring • Maintenance • Audit • Retirement</div>
    </div>
    <div>
      <a class="btn" href="?action=export&status=<?= h($fStatus) ?>&type=<?= h($fType) ?>&dept=<?= h($fDept) ?>&q=<?= urlencode($fQ) ?>"><i class='bx bx-download'></i> Export CSV</a>
    </div>
  </header>

  <section class="card">
    <div class="grid">
      <div class="stat"><div class="label">Total Assets</div><div class="number"><?= (int)$totalAssets ?></div></div>
      <div class="stat"><div class="label">Registered</div><div class="number"><?= (int)$registered ?></div></div>
      <div class="stat"><div class="label">Deployed</div><div class="number"><?= (int)$deployed ?></div></div>
      <div class="stat"><div class="label">Active</div><div class="number"><?= (int)$active ?></div></div>
      <div class="stat"><div class="label">In Maintenance</div><div class="number"><?= (int)$inMaint ?></div></div>
      <div class="stat"><div class="label">Retired/Disposed</div><div class="number"><?= (int)$retired ?></div></div>
      <div class="stat"><div class="label">Total Purchase Value</div><div class="number">₱<?= money_fmt($purchaseSum) ?></div></div>
    </div>
  </section>

  <section class="card section">
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <select id="f_status" class="select" onchange="applyFilters()">
        <option value="">All statuses</option>
        <?php foreach ($statuses as $s): ?>
          <option value="<?= h($s) ?>" <?= $fStatus===$s?'selected':'' ?>><?= h($s) ?></option>
        <?php endforeach; ?>
      </select>
      <select id="f_type" class="select" onchange="applyFilters()">
        <option value="">All types</option>
        <?php foreach ($types as $t): ?>
          <option value="<?= h($t) ?>" <?= $fType===$t?'selected':'' ?>><?= h($t) ?></option>
        <?php endforeach; ?>
      </select>
      <select id="f_dept" class="select" onchange="applyFilters()">
        <option value="">All departments</option>
        <?php foreach ($departments as $d): ?>
          <option value="<?= h($d) ?>" <?= $fDept===$d?'selected':'' ?>><?= h($d) ?></option>
        <?php endforeach; ?>
      </select>
      <input id="f_q" class="input" placeholder="Search name/ID/driver/route" value="<?= h($fQ) ?>">
      <button class="btn" onclick="applyFilters()"><i class='bx bx-filter'></i> Apply</button>
    </div>
  </section>

  <section class="card section">
    <?php if ($isAdmin): ?>
    <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start">
      <input type="hidden" name="op" value="add">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input class="input" name="name" placeholder="Asset Name" required>
      <input class="input" name="unique_id" placeholder="Unique ID (QR/RFID/Serial)">
      <select class="select" name="asset_type">
        <option value="">Type</option>
        <?php foreach ($types as $t): ?><option><?= h($t) ?></option><?php endforeach; ?>
      </select>
      <input class="input" name="manufacturer" placeholder="Manufacturer">
      <input class="input" name="model" placeholder="Model">
      <input class="input" type="date" name="purchase_date" title="Purchase date">
      <input class="input" type="number" step="0.01" name="purchase_cost" placeholder="Cost">
      <select class="select" name="department">
        <option value="">Department</option>
        <?php foreach ($departments as $d): ?><option><?= h($d) ?></option><?php endforeach; ?>
      </select>
      <input class="input" name="driver" placeholder="Assigned Driver">
      <input class="input" name="route" placeholder="Route">
      <input class="input" name="depot" placeholder="Depot">
      <select class="select" name="status">
        <?php foreach ($statuses as $s): ?><option <?= $s==='Registered'?'selected':'' ?>><?= h($s) ?></option><?php endforeach; ?>
      </select>
      <input class="input" type="date" name="deployment_date" title="Deployment date">
      <input class="input" name="gps_imei" placeholder="GPS IMEI">
      <input class="input" name="notes" placeholder="Notes" style="min-width:220px">
      <button class="btn" type="submit"><i class='bx bx-plus'></i> Add Asset</button>
    </form>
    <?php else: ?>
    <div style="padding:6px 0;color:#6b7280">Read-only access. Contact an administrator to add assets.</div>
    <?php endif; ?>
  </section>

  <section class="card section">
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Unique ID / Name</th>
            <th>Type / Model</th>
            <th>Assignment</th>
            <th>Status</th>
            <th>Purchase</th>
            <th>Deploy / Retire</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($assets as $a): ?>
          <tr class="<?= ($highlightId && $a['id']==$highlightId)?'highlight':'' ?>" id="asset-<?= (int)$a['id'] ?>">
            <td><?= (int)$a['id'] ?></td>
            <td>
              <div style="font-weight:600"><?= h($a['unique_id'] ?: '—') ?></div>
              <div><?= h($a['name']) ?></div>
            </td>
            <td>
              <div><?= h($a['asset_type'] ?: '—') ?></div>
              <div style="color:#6b7280;font-size:12px"><?= h(($a['manufacturer']? $a['manufacturer'].' ' : '').($a['model'] ?: '')) ?></div>
            </td>
            <td>
              <div><?= h($a['driver'] ?: 'Unassigned') ?></div>
              <div style="color:#6b7280;font-size:12px"><?= h($a['route'] ?: '—') ?> · <?= h($a['depot'] ?: '—') ?></div>
              <div style="margin-top:4px"><a class="btn ghost" href="repair.php?q=<?= urlencode($a['name']) ?>"><i class='bx bx-wrench'></i> Repairs</a></div>
            </td>
            <td>
              <?php $cls = 's-'.strtolower(str_replace(' ','',$a['status'])); ?>
              <span class="badge <?= h($cls) ?>"><?= h($a['status']) ?></span>
            </td>
            <td>
              <div>₱<?= money_fmt($a['purchase_cost']) ?></div>
              <div style="color:#6b7280;font-size:12px"><?= h($a['purchase_date']) ?></div>
            </td>
            <td>
              <div>Deploy: <?= h($a['deployment_date']) ?></div>
              <div>Retire: <?= h($a['retired_on']) ?></div>
            </td>
            <td>
              <?php $st = $a['status']; ?>
              <div style="display:flex;gap:6px;flex-wrap:wrap">
                <?php if ($isAdmin): ?>
                <?php if ($st==='Registered'): ?>
                  <form method="POST" style="display:inline">
                    <input type="hidden" name="op" value="transition">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <input type="hidden" name="to" value="Deployed">
                    <button class="btn"><i class='bx bx-rocket'></i> Deploy</button>
                  </form>
                <?php endif; ?>
                <?php if ($st==='Deployed'): ?>
                  <form method="POST" style="display:inline">
                    <input type="hidden" name="op" value="transition">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <input type="hidden" name="to" value="Active">
                    <button class="btn"><i class='bx bx-play-circle'></i> Activate</button>
                  </form>
                  <form method="POST" style="display:inline">
                    <input type="hidden" name="op" value="transition">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <input type="hidden" name="to" value="In Maintenance">
                    <button class="btn ghost"><i class='bx bx-wrench'></i> Maintenance</button>
                  </form>
                  <form method="POST" style="display:inline" onsubmit="return confirm('Retire this asset?')">
                    <input type="hidden" name="op" value="transition">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <input type="hidden" name="to" value="Retired">
                    <button class="btn ghost"><i class='bx bx-power-off'></i> Retire</button>
                  </form>
                <?php endif; ?>
                <?php if ($st==='Active'): ?>
                  <form method="POST" style="display:inline">
                    <input type="hidden" name="op" value="transition">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <input type="hidden" name="to" value="In Maintenance">
                    <button class="btn ghost"><i class='bx bx-wrench'></i> Maintenance</button>
                  </form>
                  <form method="POST" style="display:inline" onsubmit="return confirm('Retire this asset?')">
                    <input type="hidden" name="op" value="transition">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <input type="hidden" name="to" value="Retired">
                    <button class="btn ghost"><i class='bx bx-power-off'></i> Retire</button>
                  </form>
                <?php endif; ?>
                <?php if ($st==='In Maintenance'): ?>
                  <form method="POST" style="display:inline">
                    <input type="hidden" name="op" value="transition">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <input type="hidden" name="to" value="Active">
                    <button class="btn"><i class='bx bx-check-circle'></i> Back Active</button>
                  </form>
                  <form method="POST" style="display:inline" onsubmit="return confirm('Retire this asset?')">
                    <input type="hidden" name="op" value="transition">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <input type="hidden" name="to" value="Retired">
                    <button class="btn ghost"><i class='bx bx-power-off'></i> Retire</button>
                  </form>
                <?php endif; ?>
                <?php if ($st==='Retired'): ?>
                  <form method="POST" style="display:inline" onsubmit="return confirm('Mark this asset as disposed?')">
                    <input type="hidden" name="op" value="transition">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <input type="hidden" name="to" value="Disposed">
                    <button class="btn" style="background:var(--danger)"><i class='bx bx-trash-alt'></i> Dispose</button>
                  </form>
                <?php endif; ?>
              <?php else: ?>
                  <span style="color:#6b7280;font-size:12px">No actions available</span>
                <?php endif; ?>
              </div>
              <?php if ($isAdmin): ?>
              <div style="margin-top:6px">
                <button class="btn ghost" onclick="toggleEdit(<?= (int)$a['id'] ?>)"><i class='bx bx-edit-alt'></i> Edit</button>
                <form method="POST" style="display:inline" onsubmit="return confirm('Delete this asset?')">
                  <input type="hidden" name="op" value="delete">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                  <button class="btn" style="background:var(--danger)"><i class='bx bx-trash'></i> Delete</button>
                </form>
              </div>
              <?php endif; ?>
            </td>
          </tr>
          <tr id="edit-<?= (int)$a['id'] ?>" style="display:none;background:#eef4ff">
            <td colspan="8">
              <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start">
                <input type="hidden" name="op" value="update">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                <input class="input" name="name" value="<?= h($a['name']) ?>" placeholder="Asset Name" required>
                <input class="input" name="unique_id" value="<?= h($a['unique_id']) ?>" placeholder="Unique ID">
                <select class="select" name="asset_type">
                  <?php foreach ($types as $t): ?><option <?= ($a['asset_type']===$t?'selected':'') ?>><?= h($t) ?></option><?php endforeach; ?>
                </select>
                <input class="input" name="manufacturer" value="<?= h($a['manufacturer']) ?>" placeholder="Manufacturer">
                <input class="input" name="model" value="<?= h($a['model']) ?>" placeholder="Model">
                <input class="input" type="date" name="purchase_date" value="<?= h($a['purchase_date']) ?>" title="Purchase date">
                <input class="input" type="number" step="0.01" name="purchase_cost" value="<?= h($a['purchase_cost']) ?>" placeholder="Cost">
                <select class="select" name="department">
                  <?php foreach ($departments as $d): ?><option <?= ($a['department']===$d?'selected':'') ?>><?= h($d) ?></option><?php endforeach; ?>
                </select>
                <input class="input" name="driver" value="<?= h($a['driver']) ?>" placeholder="Assigned Driver">
                <input class="input" name="route" value="<?= h($a['route']) ?>" placeholder="Route">
                <input class="input" name="depot" value="<?= h($a['depot']) ?>" placeholder="Depot">
                <select class="select" name="status">
                  <?php foreach ($statuses as $s): ?><option <?= ($a['status']===$s?'selected':'') ?>><?= h($s) ?></option><?php endforeach; ?>
                </select>
                <input class="input" type="date" name="deployment_date" value="<?= h($a['deployment_date']) ?>" title="Deployment date">
                <input class="input" type="date" name="retired_on" value="<?= h($a['retired_on']) ?>" title="Retired on">
                <input class="input" name="gps_imei" value="<?= h($a['gps_imei']) ?>" placeholder="GPS IMEI">
                <input class="input" name="notes" value="<?= h($a['notes']) ?>" placeholder="Notes" style="min-width:220px">
                <button class="btn" type="submit"><i class='bx bx-save'></i> Save</button>
                <button class="btn ghost" type="button" onclick="toggleEdit(<?= (int)$a['id'] ?>)"><i class='bx bx-x'></i> Cancel</button>
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
function applyFilters(){
  const s = document.getElementById('f_status').value;
  const t = document.getElementById('f_type').value;
  const d = document.getElementById('f_dept').value;
  const q = document.getElementById('f_q').value.trim();
  const url = new URL(window.location.href);
  if (s) url.searchParams.set('status', s); else url.searchParams.delete('status');
  if (t) url.searchParams.set('type', t); else url.searchParams.delete('type');
  if (d) url.searchParams.set('dept', d); else url.searchParams.delete('dept');
  if (q) url.searchParams.set('q', q); else url.searchParams.delete('q');
  window.location.href = url.toString();
}
function toggleEdit(id){
  const el = document.getElementById('edit-'+id);
  if (!el) return; el.style.display = (el.style.display==='none'||!el.style.display) ? 'table-row' : 'none';
}
// If a highlight row is present, scroll it into view
(function(){ var el=document.querySelector('tr.highlight'); if(el){ el.scrollIntoView({behavior:'smooth', block:'center'}); }})();
</script>
</body>
</html>