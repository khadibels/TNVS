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

// Ensure minimal assets table exists (for linking)
$pdo->exec("CREATE TABLE IF NOT EXISTS assets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  status VARCHAR(50) NOT NULL,
  installed_on DATE,
  disposed_on DATE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Ensure logistics tables exist (shared with logrecord.php)
$pdo->exec("CREATE TABLE IF NOT EXISTS logistics_records (
  id INT AUTO_INCREMENT PRIMARY KEY,
  trip_ref VARCHAR(80) NULL,
  asset_id INT NULL,
  driver_name VARCHAR(120) NULL,
  trip_date DATE NOT NULL,
  shift_start DATETIME NULL,
  shift_end DATETIME NULL,
  origin VARCHAR(120) NULL,
  destination VARCHAR(120) NULL,
  distance_km DECIMAL(10,2) NULL,
  fuel_liters DECIMAL(10,2) NULL,
  fuel_cost DECIMAL(12,2) NULL,
  deliveries_planned INT NULL,
  deliveries_completed INT NULL,
  on_time INT NULL,
  delays INT NULL,
  customer_signed TINYINT(1) NULL,
  validation_status VARCHAR(30) NOT NULL DEFAULT 'Pending',
  validation_notes VARCHAR(255) NULL,
  gps_log_path VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  archived_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS logistics_activity (
  id INT AUTO_INCREMENT PRIMARY KEY,
  record_id INT NOT NULL,
  actor VARCHAR(120) NULL,
  action VARCHAR(40) NOT NULL,
  details TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX(record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function num($v){ return $v!==null && $v!=='' ? (0+$v) : null; }

// CSRF
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(16)); }
$csrf = $_SESSION['csrf_token'];
function assert_csrf(){ if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) { http_response_code(400); exit('Invalid CSRF'); } }

function actor(){ return $_SESSION['user_email'] ?? 'admin'; }

// Uploads (GPS logs)
$uploadRoot = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($uploadRoot)) @mkdir($uploadRoot, 0777, true);
function safe_filename($name){ return preg_replace('/[^a-zA-Z0-9._-]+/','_', $name); }
function allowed_gps($name){ $ext=strtolower(pathinfo($name, PATHINFO_EXTENSION)); return in_array($ext,['csv','gpx','json','kml']); }

function log_activity($pdo, $record_id, $action, $details=''){
  $stmt=$pdo->prepare("INSERT INTO logistics_activity (record_id,actor,action,details) VALUES (:r,:a,:ac,:de)");
  $stmt->execute([':r'=>$record_id, ':a'=>actor(), ':ac'=>$action, ':de'=>$details?:null]);
}

// CSV export
if (($_GET['action'] ?? '') === 'export') {
    $driver = trim($_GET['driver'] ?? '');
    $asset = (int)($_GET['asset_id'] ?? 0);
    $status = $_GET['status'] ?? '';
    $from = $_GET['from'] ?? '';
    $to = $_GET['to'] ?? '';
    $arch = $_GET['arch'] ?? '0';
    $params=[]; $where=[];
    if ($driver !== '') { $where[]='r.driver_name LIKE :dr'; $params[':dr']='%'.$driver.'%'; }
    if ($asset > 0) { $where[]='r.asset_id = :aid'; $params[':aid']=$asset; }
    if ($status !== '') { $where[]='r.validation_status = :st'; $params[':st']=$status; }
    if ($from !== '') { $where[]='r.trip_date >= :from'; $params[':from']=$from; }
    if ($to !== '') { $where[]='r.trip_date <= :to'; $params[':to']=$to; }
    if ($arch !== '1') { $where[]='r.archived_at IS NULL'; }
    $w = $where ? ('WHERE '.implode(' AND ',$where)) : '';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=logistics_'.date('Ymd_His').'.csv');
    $out=fopen('php://output','w');
    fputcsv($out, ['id','trip_ref','asset_id','asset_name','driver_name','trip_date','shift_start','shift_end','origin','destination','distance_km','fuel_liters','fuel_cost','deliveries_planned','deliveries_completed','on_time','delays','customer_signed','validation_status','validation_notes','gps_log_path','created_at']);
    $stmt = $pdo->prepare("SELECT r.*, a.name AS asset_name FROM logistics_records r LEFT JOIN assets a ON r.asset_id=a.id $w ORDER BY r.trip_date DESC, r.id DESC");
    $stmt->execute($params);
    while ($r=$stmt->fetch()) {
        fputcsv($out, [$r['id'],$r['trip_ref'],$r['asset_id'],$r['asset_name'],$r['driver_name'],$r['trip_date'],$r['shift_start'],$r['shift_end'],$r['origin'],$r['destination'],$r['distance_km'],$r['fuel_liters'],$r['fuel_cost'],$r['deliveries_planned'],$r['deliveries_completed'],$r['on_time'],$r['delays'],$r['customer_signed'],$r['validation_status'],$r['validation_notes'],$r['gps_log_path'],$r['created_at']]);
    }
    fclose($out); exit;
}

// Download GPS log
if (($_GET['action'] ?? '') === 'download_gps') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id>0) {
        $stmt=$pdo->prepare("SELECT gps_log_path FROM logistics_records WHERE id=:id");
        $stmt->execute([':id'=>$id]);
        $path=$stmt->fetchColumn();
        if ($path) {
            $file=$uploadRoot.DIRECTORY_SEPARATOR.basename($path);
            if (is_file($file)) {
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="'.basename($file).'"');
                header('Content-Length: '.filesize($file));
                readfile($file);
                exit;
            }
        }
    }
    http_response_code(404); echo 'GPS log not found'; exit;
}

// POST handlers (add/update/validate/reject/archive/delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $op = $_POST['op'] ?? '';

    if ($op === 'add') {
        assert_csrf();
        $trip_ref = trim($_POST['trip_ref'] ?? '');
        $asset_id = (int)($_POST['asset_id'] ?? 0);
        $driver_name = trim($_POST['driver_name'] ?? '');
        $trip_date = $_POST['trip_date'] ?? '';
        $shift_start = $_POST['shift_start'] ?? null;
        $shift_end = $_POST['shift_end'] ?? null;
        $origin = trim($_POST['origin'] ?? '');
        $destination = trim($_POST['destination'] ?? '');
        $distance_km = num($_POST['distance_km'] ?? '');
        $fuel_liters = num($_POST['fuel_liters'] ?? '');
        $fuel_cost = num($_POST['fuel_cost'] ?? '');
        $deliv_plan = $_POST['deliveries_planned']!=='' ? (int)$_POST['deliveries_planned'] : null;
        $deliv_done = $_POST['deliveries_completed']!=='' ? (int)$_POST['deliveries_completed'] : null;
        $on_time = $_POST['on_time']!=='' ? (int)$_POST['on_time'] : null;
        $delays = $_POST['delays']!=='' ? (int)$_POST['delays'] : null;
        $signed = isset($_POST['customer_signed']) ? 1 : 0;
        if ($trip_date) {
            $stmt=$pdo->prepare("INSERT INTO logistics_records (trip_ref,asset_id,driver_name,trip_date,shift_start,shift_end,origin,destination,distance_km,fuel_liters,fuel_cost,deliveries_planned,deliveries_completed,on_time,delays,customer_signed) VALUES (:trip_ref,:asset_id,:driver,:trip_date,:shift_start,:shift_end,:origin,:destination,:distance_km,:fuel_liters,:fuel_cost,:dp,:dc,:ot,:dl,:signed)");
            $stmt->execute([
                ':trip_ref'=>$trip_ref?:null,
                ':asset_id'=>$asset_id?:null,
                ':driver'=>$driver_name?:null,
                ':trip_date'=>$trip_date,
                ':shift_start'=>$shift_start?:null,
                ':shift_end'=>$shift_end?:null,
                ':origin'=>$origin?:null,
                ':destination'=>$destination?:null,
                ':distance_km'=>$distance_km,
                ':fuel_liters'=>$fuel_liters,
                ':fuel_cost'=>$fuel_cost,
                ':dp'=>$deliv_plan,
                ':dc'=>$deliv_done,
                ':ot'=>$on_time,
                ':dl'=>$delays,
                ':signed'=>$signed
            ]);
            $id = (int)$pdo->lastInsertId();
            if (!empty($_FILES['gps']['name'])) {
                if (allowed_gps($_FILES['gps']['name'])) {
                    $clean = safe_filename($_FILES['gps']['name']);
                    $dest = $id.'_'.$clean;
                    move_uploaded_file($_FILES['gps']['tmp_name'], $uploadRoot.DIRECTORY_SEPARATOR.$dest);
                    $pdo->prepare("UPDATE logistics_records SET gps_log_path=:p WHERE id=:id")->execute([':p'=>$dest, ':id'=>$id]);
                }
            }
            log_activity($pdo, $id, 'created', $trip_ref);
            echo "<script>try{localStorage.setItem('logrec_changed', Date.now().toString())}catch(e){}</script>";
        }
        header('Location: logisticsrecord.php'); exit;
    }

    if ($op === 'update') {
        assert_csrf();
        $id = (int)($_POST['id'] ?? 0);
        if ($id>0) {
            $trip_ref = trim($_POST['trip_ref'] ?? '');
            $asset_id = (int)($_POST['asset_id'] ?? 0);
            $driver_name = trim($_POST['driver_name'] ?? '');
            $trip_date = $_POST['trip_date'] ?? '';
            $shift_start = $_POST['shift_start'] ?? null;
            $shift_end = $_POST['shift_end'] ?? null;
            $origin = trim($_POST['origin'] ?? '');
            $destination = trim($_POST['destination'] ?? '');
            $distance_km = num($_POST['distance_km'] ?? '');
            $fuel_liters = num($_POST['fuel_liters'] ?? '');
            $fuel_cost = num($_POST['fuel_cost'] ?? '');
            $deliv_plan = $_POST['deliveries_planned']!=='' ? (int)$_POST['deliveries_planned'] : null;
            $deliv_done = $_POST['deliveries_completed']!=='' ? (int)$_POST['deliveries_completed'] : null;
            $on_time = $_POST['on_time']!=='' ? (int)$_POST['on_time'] : null;
            $delays = $_POST['delays']!=='' ? (int)$_POST['delays'] : null;
            $signed = isset($_POST['customer_signed']) ? 1 : 0;
            $stmt=$pdo->prepare("UPDATE logistics_records SET trip_ref=:trip_ref,asset_id=:asset_id,driver_name=:driver,trip_date=:trip_date,shift_start=:shift_start,shift_end=:shift_end,origin=:origin,destination=:destination,distance_km=:distance_km,fuel_liters=:fuel_liters,fuel_cost=:fuel_cost,deliveries_planned=:dp,deliveries_completed=:dc,on_time=:ot,delays=:dl,customer_signed=:signed WHERE id=:id");
            $stmt->execute([
                ':id'=>$id,
                ':trip_ref'=>$trip_ref?:null,
                ':asset_id'=>$asset_id?:null,
                ':driver'=>$driver_name?:null,
                ':trip_date'=>$trip_date,
                ':shift_start'=>$shift_start?:null,
                ':shift_end'=>$shift_end?:null,
                ':origin'=>$origin?:null,
                ':destination'=>$destination?:null,
                ':distance_km'=>$distance_km,
                ':fuel_liters'=>$fuel_liters,
                ':fuel_cost'=>$fuel_cost,
                ':dp'=>$deliv_plan,
                ':dc'=>$deliv_done,
                ':ot'=>$on_time,
                ':dl'=>$delays,
                ':signed'=>$signed
            ]);
            if (!empty($_FILES['gps']['name'])) {
                if (allowed_gps($_FILES['gps']['name'])) {
                    $clean = safe_filename($_FILES['gps']['name']);
                    $dest = $id.'_'.$clean;
                    move_uploaded_file($_FILES['gps']['tmp_name'], $uploadRoot.DIRECTORY_SEPARATOR.$dest);
                    $pdo->prepare("UPDATE logistics_records SET gps_log_path=:p WHERE id=:id")->execute([':p'=>$dest, ':id'=>$id]);
                }
            }
            log_activity($pdo, $id, 'updated');
            echo "<script>try{localStorage.setItem('logrec_changed', Date.now().toString())}catch(e){}</script>";
        }
        header('Location: logisticsrecord.php'); exit;
    }

    if ($op === 'validate' || $op === 'reject') {
        assert_csrf();
        $id = (int)($_POST['id'] ?? 0);
        $notes = trim($_POST['validation_notes'] ?? '');
        if ($id>0) {
            $status = $op==='validate' ? 'Validated' : 'Rejected';
            $stmt=$pdo->prepare("UPDATE logistics_records SET validation_status=:st, validation_notes=:no WHERE id=:id");
            $stmt->execute([':st'=>$status, ':no'=>$notes?:null, ':id'=>$id]);
            log_activity($pdo, $id, strtolower($status), $notes);
            echo "<script>try{localStorage.setItem('logrec_changed', Date.now().toString())}catch(e){}</script>";
        }
        header('Location: logisticsrecord.php'); exit;
    }

    if ($op === 'archive' || $op === 'unarchive') {
        assert_csrf();
        $id = (int)($_POST['id'] ?? 0);
        if ($id>0) {
            if ($op==='archive') {
                $pdo->prepare("UPDATE logistics_records SET archived_at=NOW() WHERE id=:id")->execute([':id'=>$id]);
                log_activity($pdo, $id, 'archived');
            } else {
                $pdo->prepare("UPDATE logistics_records SET archived_at=NULL WHERE id=:id")->execute([':id'=>$id]);
                log_activity($pdo, $id, 'unarchived');
            }
            echo "<script>try{localStorage.setItem('logrec_changed', Date.now().toString())}catch(e){}</script>";
        }
        header('Location: logisticsrecord.php'); exit;
    }

    if ($op === 'delete') {
        assert_csrf();
        $id = (int)($_POST['id'] ?? 0);
        if ($id>0) {
            $pdo->prepare("DELETE FROM logistics_activity WHERE record_id=:id")->execute([':id'=>$id]);
            $pdo->prepare("DELETE FROM logistics_records WHERE id=:id")->execute([':id'=>$id]);
            echo "<script>try{localStorage.setItem('logrec_changed', Date.now().toString())}catch(e){}</script>";
        }
        header('Location: logisticsrecord.php'); exit;
    }
}

// Filters & list
$fDriver = trim($_GET['driver'] ?? '');
$fAsset = (int)($_GET['asset_id'] ?? 0);
$fStatus = $_GET['status'] ?? '';
$fFrom = $_GET['from'] ?? '';
$fTo = $_GET['to'] ?? '';
$fArch = $_GET['arch'] ?? '0';

$params=[]; $where=[];
if ($fDriver !== '') { $where[]='r.driver_name LIKE :dr'; $params[':dr']='%'.$fDriver.'%'; }
if ($fAsset > 0) { $where[]='r.asset_id = :aid'; $params[':aid']=$fAsset; }
if ($fStatus !== '') { $where[]='r.validation_status = :st'; $params[':st']=$fStatus; }
if ($fFrom !== '') { $where[]='r.trip_date >= :from'; $params[':from']=$fFrom; }
if ($fTo !== '') { $where[]='r.trip_date <= :to'; $params[':to']=$fTo; }
if ($fArch !== '1') { $where[]='r.archived_at IS NULL'; }
$w = $where ? ('WHERE '.implode(' AND ',$where)) : '';

// Fetch records
$sqlList = "SELECT r.*, a.name AS asset_name FROM logistics_records r LEFT JOIN assets a ON r.asset_id=a.id $w ORDER BY r.trip_date DESC, r.id DESC LIMIT 1000";
$stmtList = $pdo->prepare($sqlList);
$stmtList->execute($params);
$rows = $stmtList->fetchAll();

// KPIs for current filter window
$stmtKPIs = $pdo->prepare("SELECT 
  COUNT(*) AS trips,
  COALESCE(SUM(distance_km),0) AS km,
  COALESCE(SUM(fuel_liters),0) AS liters,
  COALESCE(SUM(fuel_cost),0) AS fuel_cost,
  COALESCE(SUM(deliveries_planned),0) AS dp,
  COALESCE(SUM(deliveries_completed),0) AS dc,
  COALESCE(SUM(on_time),0) AS ot,
  COALESCE(SUM(delays),0) AS delays
 FROM logistics_records r $w");
$stmtKPIs->execute($params);
$kpi = $stmtKPIs->fetch() ?: ['trips'=>0,'km'=>0,'liters'=>0,'fuel_cost'=>0,'dp'=>0,'dc'=>0,'ot'=>0,'delays'=>0];
$km_per_l = ($kpi['liters']>0) ? round($kpi['km']/$kpi['liters'],2) : 0;
$completion = ($kpi['dp']>0) ? round(($kpi['dc']/$kpi['dp'])*100,1) : 0;
$ontime = ($kpi['dc']>0) ? round(($kpi['ot']/$kpi['dc'])*100,1) : 0;

// For select options
$assets = $pdo->query("SELECT id,name FROM assets ORDER BY name ASC")->fetchAll();
$statuses = ['Pending','Validated','Rejected'];

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>TNVS Logistics Records</title>
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
.grid{display:grid;grid-template-columns:repeat(6,1fr);gap:12px}
@media (max-width:1200px){.grid{grid-template-columns:repeat(3,1fr)}}
@media (max-width:720px){.grid{grid-template-columns:repeat(2,1fr)}}
.stat{padding:12px;border-radius:10px;background:linear-gradient(180deg,#ffffff,#fbfdff);border:1px solid rgba(14,165,233,0.06)}
.stat .label{font-size:12px;color:var(--muted)}
.stat .number{font-size:20px;font-weight:700}
.form-row{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start}
.input,.select,textarea{padding:10px 12px;border-radius:10px;border:1px solid #e6edf6;background:transparent;font-size:14px;color:var(--text)}
.table-wrap{overflow:auto;border-radius:10px;border:1px solid rgba(17,24,39,0.06)}
.table{width:100%;border-collapse:collapse;min-width:1200px}
.table thead th{text-align:left;padding:12px 14px;background:linear-gradient(180deg,#fbfdff,#f7f9fc);font-size:13px;color:var(--muted);border-bottom:1px solid rgba(15,23,42,0.06)}
.table tbody td{padding:12px 14px;border-bottom:1px solid rgba(15,23,42,0.06);font-size:14px;vertical-align:top}
.badge{display:inline-block;padding:6px 10px;border-radius:999px;font-weight:600;font-size:12px}
.s-pending{background:rgba(59,130,246,0.08);color:#2563eb}
.s-validated{background:rgba(16,185,129,0.12);color:var(--success)}
.s-rejected{background:rgba(239,68,68,0.12);color:var(--danger)}
.quick{display:flex;gap:6px;flex-wrap:wrap}
.quick .btn.ghost{padding:6px 10px;font-size:12px}
</style>
</head>
<body>
<div class="container">
  <header class="header">
    <div style="display:flex;gap:8px;align-items:center">
      <a href="DTLR.php" class="btn ghost"><i class='bx bx-arrow-back'></i> Back</a>
      <a href="document.php" class="btn ghost"><i class='bx bx-file-blank'></i> Document Tracking</a>
    </div>
    <div>
      <h2 style="margin:0;display:flex;align-items:center;gap:8px"><i class='bx bx-list-check'></i> TNVS Logistics Records</h2>
      <div style="font-size:13px;color:var(--muted)">Data Capture • Validation • KPIs • Reports • Archiving • Audit</div>
    </div>
    <div>
      <a class="btn" href="?action=export&driver=<?= h($fDriver) ?>&asset_id=<?= (int)$fAsset ?>&status=<?= h($fStatus) ?>&from=<?= h($fFrom) ?>&to=<?= h($fTo) ?>&arch=<?= h($fArch) ?>"><i class='bx bx-download'></i> Export CSV</a>
    </div>
  </header>

  <section class="card">
    <div class="grid">
      <div class="stat"><div class="label">Trips</div><div class="number"><?= (int)$kpi['trips'] ?></div></div>
      <div class="stat"><div class="label">Distance (km)</div><div class="number"><?= number_format((float)$kpi['km'],2) ?></div></div>
      <div class="stat"><div class="label">Fuel (L)</div><div class="number"><?= number_format((float)$kpi['liters'],2) ?></div></div>
      <div class="stat"><div class="label">Fuel Cost</div><div class="number">₱<?= number_format((float)$kpi['fuel_cost'],2) ?></div></div>
      <div class="stat"><div class="label">Avg km/L</div><div class="number"><?= number_format((float)$km_per_l,2) ?></div></div>
      <div class="stat"><div class="label">Completion %</div><div class="number"><?= number_format((float)$completion,1) ?>%</div></div>
      <div class="stat"><div class="label">On-time %</div><div class="number"><?= number_format((float)$ontime,1) ?>%</div></div>
      <div class="stat"><div class="label">Delays</div><div class="number"><?= (int)$kpi['delays'] ?></div></div>
    </div>
  </section>

  <section class="card" style="margin-top:14px">
    <form method="get" class="form-row" id="filterForm">
      <input name="driver" class="input" placeholder="Driver" value="<?= h($fDriver) ?>">
      <select name="asset_id" class="select">
        <option value="0">All Assets</option>
        <?php foreach ($assets as $a): ?><option value="<?= (int)$a['id'] ?>" <?= $fAsset===$a['id']?'selected':'' ?>><?= h($a['name']) ?></option><?php endforeach; ?>
      </select>
      <select name="status" class="select">
        <option value="">All statuses</option>
        <?php foreach ($statuses as $s): ?><option value="<?= h($s) ?>" <?= $fStatus===$s?'selected':'' ?>><?= h($s) ?></option><?php endforeach; ?>
      </select>
      <input type="date" name="from" class="input" id="fromDate" value="<?= h($fFrom) ?>">
      <input type="date" name="to" class="input" id="toDate" value="<?= h($fTo) ?>">
      <label style="display:inline-flex;align-items:center;gap:6px;color:#374151"><input type="checkbox" name="arch" value="1" <?= $fArch==='1'?'checked':'' ?>> Include archived</label>
      <button class="btn" type="submit"><i class='bx bx-filter'></i> Apply</button>
    </form>
    <div class="quick" style="margin-top:8px">
      <button class="btn ghost" onclick="quickRange('today')">Today</button>
      <button class="btn ghost" onclick="quickRange('week')">This Week</button>
      <button class="btn ghost" onclick="quickRange('month')">This Month</button>
      <button class="btn ghost" onclick="quickRange('quarter')">This Quarter</button>
    </div>
  </section>

  <section class="card" style="margin-top:14px">
    <form method="POST" enctype="multipart/form-data" class="form-row" style="gap:8px;align-items:flex-start">
      <input type="hidden" name="op" value="add">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input class="input" name="trip_ref" placeholder="Trip Ref">
      <select name="asset_id" class="select">
        <option value="">Asset</option>
        <?php foreach ($assets as $a): ?><option value="<?= (int)$a['id'] ?>"><?= h($a['name']) ?></option><?php endforeach; ?>
      </select>
      <input class="input" name="driver_name" placeholder="Driver">
      <input type="date" class="input" name="trip_date" required>
      <input type="datetime-local" class="input" name="shift_start" title="Shift Start">
      <input type="datetime-local" class="input" name="shift_end" title="Shift End">
      <input class="input" name="origin" placeholder="Origin">
      <input class="input" name="destination" placeholder="Destination">
      <input type="number" step="0.01" class="input" name="distance_km" placeholder="Km">
      <input type="number" step="0.01" class="input" name="fuel_liters" placeholder="Fuel L">
      <input type="number" step="0.01" class="input" name="fuel_cost" placeholder="Fuel Cost">
      <input type="number" class="input" name="deliveries_planned" placeholder="Planned">
      <input type="number" class="input" name="deliveries_completed" placeholder="Completed">
      <input type="number" class="input" name="on_time" placeholder="On-time">
      <input type="number" class="input" name="delays" placeholder="Delays">
      <label style="display:inline-flex;align-items:center;gap:6px;color:#374151"><input type="checkbox" name="customer_signed"> Customer signed</label>
      <input type="file" name="gps" class="input" accept=".csv,.gpx,.json,.kml">
      <button class="btn" type="submit"><i class='bx bx-plus'></i> Add Record</button>
    </form>
  </section>

  <section class="card" style="margin-top:14px">
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Trip / Asset</th>
            <th>Driver / Date</th>
            <th>Shift</th>
            <th>Route</th>
            <th>Performance</th>
            <th>Fuel</th>
            <th>Status</th>
            <th>GPS</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= (int)$r['id'] ?></td>
            <td>
              <div style="font-weight:600"><?= h($r['trip_ref'] ?: '—') ?></div>
              <div style="color:#6b7280;font-size:12px">Asset: <?= h($r['asset_name'] ?: '—') ?></div>
            </td>
            <td>
              <div><?= h($r['driver_name'] ?: '—') ?></div>
              <div style="color:#6b7280;font-size:12px">Date: <?= h($r['trip_date']) ?></div>
            </td>
            <td>
              <div><?= h($r['shift_start'] ?: '—') ?> → <?= h($r['shift_end'] ?: '—') ?></div>
            </td>
            <td>
              <div><?= h($r['origin'] ?: '—') ?> → <?= h($r['destination'] ?: '—') ?></div>
            </td>
            <td>
              <div>Completed/Planned: <?= (int)$r['deliveries_completed'] ?>/<?= (int)$r['deliveries_planned'] ?></div>
              <div style="color:#6b7280;font-size:12px">On-time: <?= (int)$r['on_time'] ?> · Delays: <?= (int)$r['delays'] ?></div>
              <div style="color:#6b7280;font-size:12px">Distance: <?= number_format((float)$r['distance_km'],2) ?> km</div>
            </td>
            <td>
              <div><?= number_format((float)$r['fuel_liters'],2) ?> L</div>
              <div>₱<?= number_format((float)$r['fuel_cost'],2) ?></div>
            </td>
            <td>
              <?php $cls='s-'.strtolower($r['validation_status']); ?>
              <span class="badge <?= h($cls) ?>"><?= h($r['validation_status']) ?></span>
              <?php if ($r['archived_at']): ?><div style="color:#6b7280;font-size:12px">Archived</div><?php endif; ?>
            </td>
            <td>
              <?php if ($r['gps_log_path']): ?>
                <a class="btn ghost" href="?action=download_gps&id=<?= (int)$r['id'] ?>"><i class='bx bx-download'></i> GPS</a>
              <?php else: ?><span style="color:#6b7280">—</span><?php endif; ?>
            </td>
            <td>
              <button class="btn ghost" onclick="toggleEdit(<?= (int)$r['id'] ?>)"><i class='bx bx-edit-alt'></i> Edit</button>
              <form method="POST" style="display:inline">
                <input type="hidden" name="op" value="validate"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input class="input" name="validation_notes" placeholder="Notes" style="max-width:120px">
                <button class="btn"><i class='bx bx-check'></i> Validate</button>
              </form>
              <form method="POST" style="display:inline" onsubmit="return confirm('Reject this record?')">
                <input type="hidden" name="op" value="reject"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn" style="background:var(--danger)"><i class='bx bx-x'></i> Reject</button>
              </form>
              <?php if (!$r['archived_at']): ?>
              <form method="POST" style="display:inline" onsubmit="return confirm('Archive this record?')">
                <input type="hidden" name="op" value="archive"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn"><i class='bx bx-archive'></i> Archive</button>
              </form>
              <?php else: ?>
              <form method="POST" style="display:inline">
                <input type="hidden" name="op" value="unarchive"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn"><i class='bx bx-archive-out'></i> Unarchive</button>
              </form>
              <?php endif; ?>
                          </td>
          </tr>
          <tr id="edit-<?= (int)$r['id'] ?>" style="display:none;background:#eef4ff">
            <td colspan="10">
              <form method="POST" enctype="multipart/form-data" class="form-row" style="gap:8px;align-items:flex-start">
                <input type="hidden" name="op" value="update">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input class="input" name="trip_ref" value="<?= h($r['trip_ref']) ?>" placeholder="Trip Ref">
                <select name="asset_id" class="select">
                  <option value="">Asset</option>
                  <?php foreach ($assets as $a): ?><option value="<?= (int)$a['id'] ?>" <?= $r['asset_id']==$a['id']?'selected':'' ?>><?= h($a['name']) ?></option><?php endforeach; ?>
                </select>
                <input class="input" name="driver_name" value="<?= h($r['driver_name']) ?>" placeholder="Driver">
                <input type="date" class="input" name="trip_date" value="<?= h($r['trip_date']) ?>">
                <input type="datetime-local" class="input" name="shift_start" value="<?= h(str_replace(' ','T',$r['shift_start'])) ?>">
                <input type="datetime-local" class="input" name="shift_end" value="<?= h(str_replace(' ','T',$r['shift_end'])) ?>">
                <input class="input" name="origin" value="<?= h($r['origin']) ?>" placeholder="Origin">
                <input class="input" name="destination" value="<?= h($r['destination']) ?>" placeholder="Destination">
                <input type="number" step="0.01" class="input" name="distance_km" value="<?= h($r['distance_km']) ?>" placeholder="Km">
                <input type="number" step="0.01" class="input" name="fuel_liters" value="<?= h($r['fuel_liters']) ?>" placeholder="Fuel L">
                <input type="number" step="0.01" class="input" name="fuel_cost" value="<?= h($r['fuel_cost']) ?>" placeholder="Fuel Cost">
                <input type="number" class="input" name="deliveries_planned" value="<?= h($r['deliveries_planned']) ?>" placeholder="Planned">
                <input type="number" class="input" name="deliveries_completed" value="<?= h($r['deliveries_completed']) ?>" placeholder="Completed">
                <input type="number" class="input" name="on_time" value="<?= h($r['on_time']) ?>" placeholder="On-time">
                <input type="number" class="input" name="delays" value="<?= h($r['delays']) ?>" placeholder="Delays">
                <label style="display:inline-flex;align-items:center;gap:6px;color:#374151"><input type="checkbox" name="customer_signed" <?= $r['customer_signed']? 'checked':'' ?>> Customer signed</label>
                <input type="file" name="gps" class="input" accept=".csv,.gpx,.json,.kml">
                <button class="btn" type="submit"><i class='bx bx-save'></i> Save</button>
                <button class="btn ghost" type="button" onclick="toggleEdit(<?= (int)$r['id'] ?>)"><i class='bx bx-x'></i> Cancel</button>
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
function fmt(d){ const z=n=>String(n).padStart(2,'0'); return `${d.getFullYear()}-${z(d.getMonth()+1)}-${z(d.getDate())}`; }
function getMonday(d){ d=new Date(d); const day=d.getDay(); const diff=(day===0?-6:1)-day; d.setDate(d.getDate()+diff); return d; }
function quickRange(type){ const from=document.getElementById('fromDate'); const to=document.getElementById('toDate'); const now=new Date(); let a,b; if(type==='today'){a=new Date(now); b=new Date(now);} if(type==='week'){a=getMonday(now); b=new Date(a); b.setDate(a.getDate()+6);} if(type==='month'){a=new Date(now.getFullYear(), now.getMonth(), 1); b=new Date(now.getFullYear(), now.getMonth()+1, 0);} if(type==='quarter'){const q=Math.floor(now.getMonth()/3); a=new Date(now.getFullYear(), q*3, 1); b=new Date(now.getFullYear(), q*3+3, 0);} from.value=fmt(a); to.value=fmt(b); document.getElementById('filterForm').submit(); }
// Auto-refresh when another tab updates records
window.addEventListener('storage', function(e){ if (e.key==='logrec_changed') { window.location.reload(); }});
</script>
</body>
</html>
