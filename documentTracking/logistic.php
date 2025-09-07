<?php
require_once __DIR__ . "/../includes/config.php";
require_once __DIR__ . "/../includes/auth.php";
require_login();

$section = 'docs';
$active = 'logistics';

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
        header('Location: logistic.php'); exit;
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
        header('Location: logistic.php'); exit;
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
        header('Location: logistic.php'); exit;
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
        header('Location: logistic.php'); exit;
    }

    if ($op === 'delete') {
        assert_csrf();
        $id = (int)($_POST['id'] ?? 0);
        if ($id>0) {
            $pdo->prepare("DELETE FROM logistics_activity WHERE record_id=:id")->execute([':id'=>$id]);
            $pdo->prepare("DELETE FROM logistics_records WHERE id=:id")->execute([':id'=>$id]);
            echo "<script>try{localStorage.setItem('logrec_changed', Date.now().toString())}catch(e){}</script>";
        }
        header('Location: logistic.php'); exit;
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

// Topbar profile (optional)
$userName = $_SESSION["user"]["name"] ?? "Nicole Malitao";
$userRole = $_SESSION["user"]["role"] ?? "Logistics Coordinator";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Logistics Records | TNVS</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="../css/style.css" rel="stylesheet" />
  <link href="../css/modules.css" rel="stylesheet" />

  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <script src="../js/sidebar-toggle.js"></script>

  <style>
    /* Status chips */
    .badge.s-pending{background:#dbeafe;color:#1d4ed8}
    .badge.s-validated{background:#dcfce7;color:#065f46}
    .badge.s-rejected{background:#fee2e2;color:#991b1b}
    .levels-scroll, .tx-scroll { max-height: 60vh; }
    .quick-btns .btn { padding: .25rem .5rem; font-size: .8rem; }
  </style>
</head>
<body>
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
            <h2 class="m-0 d-flex align-items-center gap-2">
              <ion-icon name="cube-outline"></ion-icon> Logistics Records
            </h2>
          </div>
          <div class="d-flex align-items-center gap-2">
            <img src="../img/profile.jpg" class="rounded-circle" width="36" height="36" alt="">
            <div class="small">
              <strong><?= htmlspecialchars($userName) ?></strong><br/>
              <span class="text-muted"><?= htmlspecialchars($userRole) ?></span>
            </div>
          </div>
        </div>

        <!-- KPI Cards -->
        <div class="row g-3 mb-3">
          <div class="col-6 col-md-3">
            <div class="card shadow-sm h-100">
              <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-3 p-2 bg-primary-subtle"><ion-icon name="trail-sign-outline" style="font-size:20px"></ion-icon></div>
                <div><div class="text-muted small">Trips</div><div class="h4 m-0"><?= (int)$kpi['trips'] ?></div></div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="card shadow-sm h-100">
              <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-3 p-2 bg-info-subtle"><ion-icon name="map-outline" style="font-size:20px"></ion-icon></div>
                <div><div class="text-muted small">Distance (km)</div><div class="h4 m-0"><?= number_format((float)$kpi['km'],2) ?></div></div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="card shadow-sm h-100">
              <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-3 p-2 bg-warning-subtle"><ion-icon name="speedometer-outline" style="font-size:20px"></ion-icon></div>
                <div><div class="text-muted small">Avg km/L</div><div class="h4 m-0"><?= number_format((float)$km_per_l,2) ?></div></div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="card shadow-sm h-100">
              <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-3 p-2 bg-danger-subtle"><ion-icon name="flame-outline" style="font-size:20px"></ion-icon></div>
                <div><div class="text-muted small">Fuel (L)</div><div class="h4 m-0"><?= number_format((float)$kpi['liters'],2) ?></div></div>
              </div>
            </div>
          </div>

          <div class="col-6 col-md-3">
            <div class="card shadow-sm h-100">
              <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-3 p-2 bg-secondary-subtle"><ion-icon name="cash-outline" style="font-size:20px"></ion-icon></div>
                <div><div class="text-muted small">Fuel Cost</div><div class="h4 m-0">₱<?= number_format((float)$kpi['fuel_cost'],2) ?></div></div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="card shadow-sm h-100">
              <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-3 p-2 bg-success-subtle"><ion-icon name="checkmark-done-outline" style="font-size:20px"></ion-icon></div>
                <div><div class="text-muted small">Completion %</div><div class="h4 m-0"><?= number_format((float)$completion,1) ?>%</div></div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="card shadow-sm h-100">
              <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-3 p-2 bg-teal-100"><ion-icon name="time-outline" style="font-size:20px"></ion-icon></div>
                <div><div class="text-muted small">On-time %</div><div class="h4 m-0"><?= number_format((float)$ontime,1) ?>%</div></div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="card shadow-sm h-100">
              <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-3 p-2 bg-danger-subtle"><ion-icon name="warning-outline" style="font-size:20px"></ion-icon></div>
                <div><div class="text-muted small">Delays</div><div class="h4 m-0"><?= (int)$kpi['delays'] ?></div></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Filters & Export -->
        <section class="card shadow-sm mb-3">
          <div class="card-body">
            <form method="get" class="row g-2 align-items-end" id="filterForm">
              <div class="col-12 col-md-2">
                <label class="form-label small text-muted">Driver</label>
                <input name="driver" class="form-control" placeholder="Driver" value="<?= h($fDriver) ?>">
              </div>
              <div class="col-12 col-md-3">
                <label class="form-label small text-muted">Asset</label>
                <select name="asset_id" class="form-select">
                  <option value="0">All Assets</option>
                  <?php foreach ($assets as $a): ?>
                    <option value="<?= (int)$a['id'] ?>" <?= $fAsset===$a['id']?'selected':'' ?>><?= h($a['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-12 col-md-2">
                <label class="form-label small text-muted">Status</label>
                <select name="status" class="form-select">
                  <option value="">All statuses</option>
                  <?php foreach ($statuses as $s): ?>
                    <option value="<?= h($s) ?>" <?= $fStatus===$s?'selected':'' ?>><?= h($s) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-6 col-md-2">
                <label class="form-label small text-muted">From</label>
                <input type="date" name="from" class="form-control" id="fromDate" value="<?= h($fFrom) ?>">
              </div>
              <div class="col-6 col-md-2">
                <label class="form-label small text-muted">To</label>
                <input type="date" name="to" class="form-control" id="toDate" value="<?= h($fTo) ?>">
              </div>
              <div class="col-12 col-md-1 d-grid">
                <label class="form-label small text-muted">&nbsp;</label>
                <button class="btn btn-primary" type="submit"><ion-icon name="funnel-outline"></ion-icon></button>
              </div>
              <div class="col-12">
                <div class="form-check mt-1">
                  <input class="form-check-input" type="checkbox" name="arch" value="1" id="archivedChk" <?= $fArch==='1'?'checked':'' ?>>
                  <label class="form-check-label small" for="archivedChk">Include archived</label>
                </div>
              </div>
            </form>

            <div class="d-flex justify-content-between align-items-center mt-2">
              <div class="quick-btns d-flex gap-2">
                <button class="btn btn-sm btn-outline-secondary" onclick="quickRange('today')">Today</button>
                <button class="btn btn-sm btn-outline-secondary" onclick="quickRange('week')">This Week</button>
                <button class="btn btn-sm btn-outline-secondary" onclick="quickRange('month')">This Month</button>
                <button class="btn btn-sm btn-outline-secondary" onclick="quickRange('quarter')">This Quarter</button>
              </div>
              <a class="btn btn-outline-secondary btn-sm"
                 href="?action=export&driver=<?= h($fDriver) ?>&asset_id=<?= (int)$fAsset ?>&status=<?= h($fStatus) ?>&from=<?= h($fFrom) ?>&to=<?= h($fTo) ?>&arch=<?= h($fArch) ?>">
                <ion-icon name="download-outline"></ion-icon> Export CSV
              </a>
            </div>
          </div>
        </section>

        <!-- Add Record -->
        <section class="card shadow-sm mb-3">
          <div class="card-body">
            <h5 class="mb-3">Add Logistics Record</h5>
            <form method="POST" enctype="multipart/form-data" class="row g-2">
              <input type="hidden" name="op" value="add">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

              <div class="col-12 col-md-2">
                <label class="form-label small text-muted">Trip Ref</label>
                <input class="form-control" name="trip_ref" placeholder="Trip Ref">
              </div>
              <div class="col-12 col-md-3">
                <label class="form-label small text-muted">Asset</label>
                <select name="asset_id" class="form-select">
                  <option value="">Asset</option>
                  <?php foreach ($assets as $a): ?>
                    <option value="<?= (int)$a['id'] ?>"><?= h($a['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-12 col-md-3">
                <label class="form-label small text-muted">Driver</label>
                <input class="form-control" name="driver_name" placeholder="Driver">
              </div>
              <div class="col-6 col-md-2">
                <label class="form-label small text-muted">Trip Date</label>
                <input type="date" class="form-control" name="trip_date" required>
              </div>
              <div class="col-6 col-md-2">
                <label class="form-label small text-muted">Shift Start</label>
                <input type="datetime-local" class="form-control" name="shift_start">
              </div>
              <div class="col-6 col-md-2">
                <label class="form-label small text-muted">Shift End</label>
                <input type="datetime-local" class="form-control" name="shift_end">
              </div>
              <div class="col-6 col-md-2">
                <label class="form-label small text-muted">Origin</label>
                <input class="form-control" name="origin" placeholder="Origin">
              </div>
              <div class="col-6 col-md-2">
                <label class="form-label small text-muted">Destination</label>
                <input class="form-control" name="destination" placeholder="Destination">
              </div>
              <div class="col-6 col-md-2">
                <label class="form-label small text-muted">Distance (km)</label>
                <input type="number" step="0.01" class="form-control" name="distance_km" placeholder="Km">
              </div>
              <div class="col-6 col-md-2">
                <label class="form-label small text-muted">Fuel (L)</label>
                <input type="number" step="0.01" class="form-control" name="fuel_liters" placeholder="Fuel L">
              </div>
              <div class="col-6 col-md-2">
                <label class="form-label small text-muted">Fuel Cost</label>
                <input type="number" step="0.01" class="form-control" name="fuel_cost" placeholder="Fuel Cost">
              </div>
              <div class="col-6 col-md-2">
                <label class="form-label small text-muted">Planned</label>
                <input type="number" class="form-control" name="deliveries_planned" placeholder="Planned">
              </div>
              <div class="col-6 col-md-2">
                <label class="form-label small text-muted">Completed</label>
                <input type="number" class="form-control" name="deliveries_completed" placeholder="Completed">
              </div>
              <div class="col-6 col-md-2">
                <label class="form-label small text-muted">On-time</label>
                <input type="number" class="form-control" name="on_time" placeholder="On-time">
              </div>
              <div class="col-6 col-md-2">
                <label class="form-label small text-muted">Delays</label>
                <input type="number" class="form-control" name="delays" placeholder="Delays">
              </div>
              <div class="col-12 col-md-3">
                <label class="form-label small text-muted">Customer Signed</label>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="customer_signed" id="custSigned">
                  <label class="form-check-label" for="custSigned">Yes</label>
                </div>
              </div>
              <div class="col-12 col-md-3">
                <label class="form-label small text-muted">GPS Log</label>
                <input type="file" name="gps" class="form-control" accept=".csv,.gpx,.json,.kml">
              </div>
              <div class="col-12 col-md-2 d-grid">
                <label class="form-label small text-muted">&nbsp;</label>
                <button class="btn btn-violet"><ion-icon name="add-circle-outline"></ion-icon> Add Record</button>
              </div>
            </form>
          </div>
        </section>

        <!-- Records Table -->
        <section class="card shadow-sm">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h5 class="mb-0">Records</h5>
              <span class="text-muted small"><?= count($rows) ?> row(s)</span>
            </div>

            <div class="table-responsive">
              <table class="table align-middle">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Trip / Asset</th>
                    <th>Driver / Date</th>
                    <th>Shift</th>
                    <th>Route</th>
                    <th>Performance</th>
                    <th>Fuel</th>
                    <th>Status</th>
                    <th>GPS</th>
                    <th class="text-end">Actions</th>
                  </tr>
                </thead>
                <tbody>
                <?php if (!count($rows)): ?>
                  <tr><td colspan="10" class="text-center py-4 text-muted">No logistics records.</td></tr>
                <?php endif; ?>

                <?php foreach ($rows as $r): ?>
                  <tr>
                    <td class="text-muted">#<?= (int)$r['id'] ?></td>
                    <td>
                      <div class="fw-semibold"><?= h($r['trip_ref'] ?: '—') ?></div>
                      <div class="small text-muted">Asset: <?= h($r['asset_name'] ?: '—') ?></div>
                    </td>
                    <td>
                      <div><?= h($r['driver_name'] ?: '—') ?></div>
                      <div class="small text-muted">Date: <?= h($r['trip_date']) ?></div>
                    </td>
                    <td><?= h($r['shift_start'] ?: '—') ?> → <?= h($r['shift_end'] ?: '—') ?></td>
                    <td><?= h($r['origin'] ?: '—') ?> → <?= h($r['destination'] ?: '—') ?></td>
                    <td>
                      <div>Completed/Planned: <?= (int)$r['deliveries_completed'] ?>/<?= (int)$r['deliveries_planned'] ?></div>
                      <div class="small text-muted">On-time: <?= (int)$r['on_time'] ?> · Delays: <?= (int)$r['delays'] ?></div>
                      <div class="small text-muted">Distance: <?= number_format((float)$r['distance_km'],2) ?> km</div>
                    </td>
                    <td>
                      <div><?= number_format((float)$r['fuel_liters'],2) ?> L</div>
                      <div>₱<?= number_format((float)$r['fuel_cost'],2) ?></div>
                    </td>
                    <td>
                      <?php $cls='s-'.strtolower($r['validation_status']); ?>
                      <span class="badge <?= h($cls) ?>"><?= h($r['validation_status']) ?></span>
                      <?php if ($r['archived_at']): ?><div class="small text-muted">Archived</div><?php endif; ?>
                    </td>
                    <td>
                      <?php if ($r['gps_log_path']): ?>
                        <a class="btn btn-sm btn-outline-secondary" href="?action=download_gps&id=<?= (int)$r['id'] ?>">
                          <ion-icon name="download-outline"></ion-icon> GPS
                        </a>
                      <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td class="text-end">
                      <button class="btn btn-sm btn-outline-primary me-1" onclick="toggleEdit(<?= (int)$r['id'] ?>)">
                        <ion-icon name="create-outline"></ion-icon> Edit
                      </button>

                      <form method="POST" class="d-inline">
                        <input type="hidden" name="op" value="validate">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <input class="form-control form-control-sm d-inline-block mb-1" name="validation_notes" placeholder="Notes" style="max-width:140px">
                        <button class="btn btn-sm btn-success me-1"><ion-icon name="checkmark-circle-outline"></ion-icon> Validate</button>
                      </form>

                      <form method="POST" class="d-inline" onsubmit="return confirm('Reject this record?')">
                        <input type="hidden" name="op" value="reject">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger me-1"><ion-icon name="close-circle-outline"></ion-icon> Reject</button>
                      </form>

                      <?php if (!$r['archived_at']): ?>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Archive this record?')">
                          <input type="hidden" name="op" value="archive">
                          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                          <button class="btn btn-sm btn-outline-secondary me-1"><ion-icon name="archive-outline"></ion-icon> Archive</button>
                        </form>
                      <?php else: ?>
                        <form method="POST" class="d-inline">
                          <input type="hidden" name="op" value="unarchive">
                          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                          <button class="btn btn-sm btn-outline-secondary me-1"><ion-icon name="archive-outline"></ion-icon> Unarchive</button>
                        </form>
                      <?php endif; ?>

                      <form method="POST" class="d-inline" onsubmit="return confirm('Delete this record?')">
                        <input type="hidden" name="op" value="delete">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger"><ion-icon name="trash-outline"></ion-icon> Delete</button>
                      </form>
                    </td>
                  </tr>

                  <!-- Inline Edit Row -->
                  <tr id="edit-<?= (int)$r['id'] ?>" style="display:none;background:#f6f8ff">
                    <td colspan="10">
                      <form method="POST" enctype="multipart/form-data" class="row g-2 align-items-end">
                        <input type="hidden" name="op" value="update">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">

                        <div class="col-12 col-md-2">
                          <label class="form-label small text-muted">Trip Ref</label>
                          <input class="form-control" name="trip_ref" value="<?= h($r['trip_ref']) ?>">
                        </div>
                        <div class="col-12 col-md-3">
                          <label class="form-label small text-muted">Asset</label>
                          <select name="asset_id" class="form-select">
                            <option value="">Asset</option>
                            <?php foreach ($assets as $a): ?>
                              <option value="<?= (int)$a['id'] ?>" <?= $r['asset_id']==$a['id']?'selected':'' ?>><?= h($a['name']) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <div class="col-12 col-md-3">
                          <label class="form-label small text-muted">Driver</label>
                          <input class="form-control" name="driver_name" value="<?= h($r['driver_name']) ?>">
                        </div>
                        <div class="col-6 col-md-2">
                          <label class="form-label small text-muted">Trip Date</label>
                          <input type="date" class="form-control" name="trip_date" value="<?= h($r['trip_date']) ?>">
                        </div>
                        <div class="col-6 col-md-2">
                          <label class="form-label small text-muted">Shift Start</label>
                          <input type="datetime-local" class="form-control" name="shift_start" value="<?= h(str_replace(' ','T',$r['shift_start'])) ?>">
                        </div>
                        <div class="col-6 col-md-2">
                          <label class="form-label small text-muted">Shift End</label>
                          <input type="datetime-local" class="form-control" name="shift_end" value="<?= h(str_replace(' ','T',$r['shift_end'])) ?>">
                        </div>
                        <div class="col-6 col-md-2">
                          <label class="form-label small text-muted">Origin</label>
                          <input class="form-control" name="origin" value="<?= h($r['origin']) ?>">
                        </div>
                        <div class="col-6 col-md-2">
                          <label class="form-label small text-muted">Destination</label>
                          <input class="form-control" name="destination" value="<?= h($r['destination']) ?>">
                        </div>
                        <div class="col-6 col-md-2">
                          <label class="form-label small text-muted">Distance (km)</label>
                          <input type="number" step="0.01" class="form-control" name="distance_km" value="<?= h($r['distance_km']) ?>">
                        </div>
                        <div class="col-6 col-md-2">
                          <label class="form-label small text-muted">Fuel (L)</label>
                          <input type="number" step="0.01" class="form-control" name="fuel_liters" value="<?= h($r['fuel_liters']) ?>">
                        </div>
                        <div class="col-6 col-md-2">
                          <label class="form-label small text-muted">Fuel Cost</label>
                          <input type="number" step="0.01" class="form-control" name="fuel_cost" value="<?= h($r['fuel_cost']) ?>">
                        </div>
                        <div class="col-6 col-md-2">
                          <label class="form-label small text-muted">Planned</label>
                          <input type="number" class="form-control" name="deliveries_planned" value="<?= h($r['deliveries_planned']) ?>">
                        </div>
                        <div class="col-6 col-md-2">
                          <label class="form-label small text-muted">Completed</label>
                          <input type="number" class="form-control" name="deliveries_completed" value="<?= h($r['deliveries_completed']) ?>">
                        </div>
                        <div class="col-6 col-md-2">
                          <label class="form-label small text-muted">On-time</label>
                          <input type="number" class="form-control" name="on_time" value="<?= h($r['on_time']) ?>">
                        </div>
                        <div class="col-6 col-md-2">
                          <label class="form-label small text-muted">Delays</label>
                          <input type="number" class="form-control" name="delays" value="<?= h($r['delays']) ?>">
                        </div>
                        <div class="col-12 col-md-3">
                          <label class="form-label small text-muted">Customer Signed</label>
                          <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="customer_signed" id="custSigned<?= (int)$r['id'] ?>" <?= $r['customer_signed']? 'checked':'' ?>>
                            <label class="form-check-label" for="custSigned<?= (int)$r['id'] ?>">Yes</label>
                          </div>
                        </div>
                        <div class="col-12 col-md-3">
                          <label class="form-label small text-muted">GPS Log</label>
                          <input type="file" name="gps" class="form-control" accept=".csv,.gpx,.json,.kml">
                        </div>

                        <div class="col-12 col-md-3 d-flex gap-2">
                          <button class="btn btn-primary"><ion-icon name="save-outline"></ion-icon> Save</button>
                          <button class="btn btn-outline-secondary" type="button" onclick="toggleEdit(<?= (int)$r['id'] ?>)"><ion-icon name="close-outline"></ion-icon> Cancel</button>
                        </div>
                      </form>
                    </td>
                  </tr>
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
  <script>
    function toggleEdit(id){
      const el=document.getElementById('edit-'+id);
      if(!el) return;
      el.style.display=(el.style.display==='none'||!el.style.display)?'table-row':'none';
    }
    function fmt(d){ const z=n=>String(n).padStart(2,'0'); return `${d.getFullYear()}-${z(d.getMonth()+1)}-${z(d.getDate())}`; }
    function getMonday(d){ d=new Date(d); const day=d.getDay(); const diff=(day===0?-6:1)-day; d.setDate(d.getDate()+diff); return d; }
    function quickRange(type){
      const from=document.getElementById('fromDate');
      const to=document.getElementById('toDate');
      const now=new Date(); let a,b;
      if(type==='today'){a=new Date(now); b=new Date(now);}
      if(type==='week'){a=getMonday(now); b=new Date(a); b.setDate(a.getDate()+6);}
      if(type==='month'){a=new Date(now.getFullYear(), now.getMonth(), 1); b=new Date(now.getFullYear(), now.getMonth()+1, 0);}
      if(type==='quarter'){const q=Math.floor(now.getMonth()/3); a=new Date(now.getFullYear(), q*3, 1); b=new Date(now.getFullYear(), q*3+3, 0);}
      from.value=fmt(a); to.value=fmt(b); document.getElementById('filterForm').submit();
    }
    // Auto-refresh when another tab updates records
    window.addEventListener('storage', function(e){ if (e.key==='logrec_changed') { window.location.reload(); }});
  </script>
</body>
</html>
