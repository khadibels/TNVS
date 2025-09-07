<?php
require_once __DIR__ . "/../includes/config.php";
require_once __DIR__ . "/../includes/auth.php";
require_login();
require_role(['admin', 'asset_manager']);

$section = 'alms';
$active = 'repair';


if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* ------------------------------
   DB bootstrap (unchanged)
------------------------------ */
$pdo->exec("CREATE TABLE IF NOT EXISTS assets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  status VARCHAR(50) NOT NULL,
  installed_on DATE,
  disposed_on DATE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS repairs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  asset_id INT NOT NULL,
  repair_date DATE NOT NULL,
  description VARCHAR(255) NOT NULL,
  cost DECIMAL(10,2) NOT NULL,
  technician VARCHAR(120) NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'Reported',
  maintenance_type VARCHAR(80) NULL,
  tnvs_vehicle_plate VARCHAR(32) NULL,
  tnvs_provider VARCHAR(64) NULL,
  odometer_km INT NULL,
  downtime_hours DECIMAL(8,2) NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$maybeCols = [
  "technician VARCHAR(120) NULL",
  "status VARCHAR(40) NOT NULL DEFAULT 'Reported'",
  "maintenance_type VARCHAR(80) NULL",
  "tnvs_vehicle_plate VARCHAR(32) NULL",
  "tnvs_provider VARCHAR(64) NULL",
  "odometer_km INT NULL",
  "downtime_hours DECIMAL(8,2) NULL",
  "notes TEXT NULL",
  "created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP",
  "updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
];
foreach ($maybeCols as $def) { try { $pdo->exec("ALTER TABLE repairs ADD COLUMN IF NOT EXISTS $def"); } catch (Throwable $e) {} }

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function money_fmt($v){ return number_format((float)$v, 2); }

/* Keep asset status refresh logic */
function refresh_asset_status(PDO $pdo, int $assetId): void {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM repairs WHERE asset_id = :aid AND status IN ('Reported','Scheduled','In Progress')");
    $stmt->execute([':aid' => $assetId]);
    $open = (int)$stmt->fetchColumn();
    if ($open > 0) {
        $pdo->prepare("UPDATE assets SET status = 'In Maintenance' WHERE id = :aid")->execute([':aid' => $assetId]);
    } else {
        $pdo->prepare("UPDATE assets SET status = 'Active' WHERE id = :aid AND status = 'In Maintenance'")->execute([':aid' => $assetId]);
    }
}

/* CSRF */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];
function assert_csrf(){ if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) { http_response_code(400); exit('Invalid CSRF'); } }

/* Filters */
$filterStatus  = $_GET['status'] ?? '';
$filterQ       = trim($_GET['q'] ?? '');
$filterFrom    = $_GET['from'] ?? '';
$filterTo      = $_GET['to'] ?? '';
$filterAssetId = isset($_GET['asset_id']) ? (int)$_GET['asset_id'] : 0;

/* ------------------------------
   CRUD handlers (unchanged)
------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $op = $_POST['op'] ?? '';

    if ($op === 'add') {
        assert_csrf();
        $asset_id = (int)($_POST['asset_id'] ?? 0);
        $repair_date = $_POST['repair_date'] ?? '';
        $description = trim($_POST['description'] ?? '');
        $cost = (float)($_POST['cost'] ?? 0);
        $technician = trim($_POST['technician'] ?? '');
        $status = $_POST['status'] ?? 'Reported';
        $maintenance_type = trim($_POST['maintenance_type'] ?? '');
        $tnvs_vehicle_plate = trim($_POST['tnvs_vehicle_plate'] ?? '');
        $tnvs_provider = trim($_POST['tnvs_provider'] ?? '');
        $odometer_km = $_POST['odometer_km'] !== '' ? (int)$_POST['odometer_km'] : null;
        $downtime_hours = $_POST['downtime_hours'] !== '' ? (float)$_POST['downtime_hours'] : null;
        $notes = trim($_POST['notes'] ?? '');
        if ($asset_id > 0 && $repair_date && $description !== '') {
            $stmt = $pdo->prepare("INSERT INTO repairs (asset_id, repair_date, description, cost, technician, status, maintenance_type, tnvs_vehicle_plate, tnvs_provider, odometer_km, downtime_hours, notes) VALUES (:asset_id,:repair_date,:description,:cost,:technician,:status,:maintenance_type,:tnvs_vehicle_plate,:tnvs_provider,:odometer_km,:downtime_hours,:notes)");
            $stmt->execute([
                ':asset_id'=>$asset_id,
                ':repair_date'=>$repair_date,
                ':description'=>$description,
                ':cost'=>$cost,
                ':technician'=>$technician ?: null,
                ':status'=>$status,
                ':maintenance_type'=>$maintenance_type ?: null,
                ':tnvs_vehicle_plate'=>$tnvs_vehicle_plate ?: null,
                ':tnvs_provider'=>$tnvs_provider ?: null,
                ':odometer_km'=>$odometer_km,
                ':downtime_hours'=>$downtime_hours,
                ':notes'=>$notes ?: null,
            ]);
            refresh_asset_status($pdo, $asset_id);
            echo "<script>try{localStorage.setItem('repairs_changed', Date.now().toString())}catch(e){}</script>";
        }
        header('Location: repair.php' . ($filterAssetId? ('?asset_id='.$filterAssetId) : '')); exit;
    }

    if ($op === 'update') {
        assert_csrf();
        $id = (int)($_POST['id'] ?? 0);
        $asset_id = (int)($_POST['asset_id'] ?? 0);
        $repair_date = $_POST['repair_date'] ?? '';
        $description = trim($_POST['description'] ?? '');
        $cost = (float)($_POST['cost'] ?? 0);
        $technician = trim($_POST['technician'] ?? '');
        $status = $_POST['status'] ?? 'Reported';
        $maintenance_type = trim($_POST['maintenance_type'] ?? '');
        $tnvs_vehicle_plate = trim($_POST['tnvs_vehicle_plate'] ?? '');
        $tnvs_provider = trim($_POST['tnvs_provider'] ?? '');
        $odometer_km = $_POST['odometer_km'] !== '' ? (int)$_POST['odometer_km'] : null;
        $downtime_hours = $_POST['downtime_hours'] !== '' ? (float)$_POST['downtime_hours'] : null;
        $notes = trim($_POST['notes'] ?? '');
        if ($id > 0 && $asset_id > 0 && $repair_date && $description !== '') {
            $stmt = $pdo->prepare("UPDATE repairs SET asset_id=:asset_id, repair_date=:repair_date, description=:description, cost=:cost, technician=:technician, status=:status, maintenance_type=:maintenance_type, tnvs_vehicle_plate=:tnvs_vehicle_plate, tnvs_provider=:tnvs_provider, odometer_km=:odometer_km, downtime_hours=:downtime_hours, notes=:notes WHERE id=:id");
            $stmt->execute([
                ':id'=>$id,
                ':asset_id'=>$asset_id,
                ':repair_date'=>$repair_date,
                ':description'=>$description,
                ':cost'=>$cost,
                ':technician'=>$technician ?: null,
                ':status'=>$status,
                ':maintenance_type'=>$maintenance_type ?: null,
                ':tnvs_vehicle_plate'=>$tnvs_vehicle_plate ?: null,
                ':tnvs_provider'=>$tnvs_provider ?: null,
                ':odometer_km'=>$odometer_km,
                ':downtime_hours'=>$downtime_hours,
                ':notes'=>$notes ?: null,
            ]);
            refresh_asset_status($pdo, $asset_id);
            echo "<script>try{localStorage.setItem('repairs_changed', Date.now().toString())}catch(e){}</script>";
        }
        header('Location: repair.php' . ($filterAssetId? ('?asset_id='.$filterAssetId) : '')); exit;
    }

    if ($op === 'delete') {
        assert_csrf();
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $aidStmt = $pdo->prepare("SELECT asset_id FROM repairs WHERE id = :id");
            $aidStmt->execute([':id'=>$id]);
            $aid = (int)($aidStmt->fetchColumn());
            $stmt = $pdo->prepare("DELETE FROM repairs WHERE id=:id");
            $stmt->execute([':id'=>$id]);
            if ($aid > 0) { refresh_asset_status($pdo, $aid); }
            echo "<script>try{localStorage.setItem('repairs_changed', Date.now().toString())}catch(e){}</script>";
        }
        header('Location: repair.php' . ($filterAssetId? ('?asset_id='.$filterAssetId) : '')); exit;
    }
}

/* Export (unchanged) */
if (($_GET['action'] ?? '') === 'export') {
    $params = [];
    $where = [];
    if ($filterStatus !== '') { $where[] = 'r.status = :status'; $params[':status'] = $filterStatus; }
    if ($filterQ !== '') { $where[] = '(r.description LIKE :q OR EXISTS (SELECT 1 FROM assets a WHERE a.id = r.asset_id AND a.name LIKE :q) OR r.tnvs_vehicle_plate LIKE :q OR r.tnvs_provider LIKE :q)'; $params[':q'] = '%'.$filterQ.'%'; }
    if ($filterFrom !== '') { $where[] = 'r.repair_date >= :from'; $params[':from'] = $filterFrom; }
    if ($filterTo !== '') { $where[] = 'r.repair_date <= :to'; $params[':to'] = $filterTo; }
    if ($filterAssetId > 0) { $where[] = 'r.asset_id = :aid'; $params[':aid'] = $filterAssetId; }
    $w = $where ? ('WHERE '.implode(' AND ',$where)) : '';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=repairs_'.date('Ymd_His').'.csv');
    $out=fopen('php://output','w');
    fputcsv($out, ['id','asset_id','asset_name','repair_date','description','cost','technician','status','maintenance_type','tnvs_vehicle_plate','tnvs_provider','odometer_km','downtime_hours','notes','created_at']);
    $stmt = $pdo->prepare("SELECT r.*, a.name AS asset_name FROM repairs r LEFT JOIN assets a ON r.asset_id=a.id $w ORDER BY r.repair_date DESC, r.id DESC");
    $stmt->execute($params);
    while ($r = $stmt->fetch()) {
        fputcsv($out, [
            $r['id'],$r['asset_id'],$r['asset_name'],$r['repair_date'],$r['description'],$r['cost'],$r['technician'],$r['status'],$r['maintenance_type'],$r['tnvs_vehicle_plate'],$r['tnvs_provider'],$r['odometer_km'],$r['downtime_hours'],$r['notes'],$r['created_at']
        ]);
    }
    fclose($out);
    exit;
}

/* Data for UI (unchanged) */
$assets = $pdo->query("SELECT id, name FROM assets ORDER BY name ASC")->fetchAll();

$params = [];
$where = [];
if ($filterStatus !== '') { $where[] = 'r.status = :status'; $params[':status'] = $filterStatus; }
if ($filterQ !== '') { $where[] = '(r.description LIKE :q OR EXISTS (SELECT 1 FROM assets a WHERE a.id = r.asset_id AND a.name LIKE :q) OR r.tnvs_vehicle_plate LIKE :q OR r.tnvs_provider LIKE :q)'; $params[':q'] = '%'.$filterQ.'%'; }
if ($filterFrom !== '') { $where[] = 'r.repair_date >= :from'; $params[':from'] = $filterFrom; }
if ($filterTo !== '') { $where[] = 'r.repair_date <= :to'; $params[':to'] = $filterTo; }
if ($filterAssetId > 0) { $where[] = 'r.asset_id = :aid'; $params[':aid'] = $filterAssetId; }
$w = $where ? ('WHERE '.implode(' AND ',$where)) : '';

$sqlList = "SELECT r.*, a.name AS asset_name FROM repairs r LEFT JOIN assets a ON r.asset_id = a.id $w ORDER BY r.repair_date DESC, r.id DESC LIMIT 1000";
$stmtList = $pdo->prepare($sqlList);
$stmtList->execute($params);
$repairs = $stmtList->fetchAll();

$stmtTot = $pdo->prepare("SELECT COUNT(*) FROM repairs r $w");
$stmtTot->execute($params);
$totalRepairs = (int)$stmtTot->fetchColumn();

$stmtCost = $pdo->prepare("SELECT COALESCE(SUM(cost),0) FROM repairs r $w");
$stmtCost->execute($params);
$totalCost = (float)$stmtCost->fetchColumn();

$qOpen = 'SELECT COUNT(*) FROM repairs r ' . ($w ? ($w . " AND r.status <> 'Completed'") : "WHERE r.status <> 'Completed'");
$stmtOpen = $pdo->prepare($qOpen);
$stmtOpen->execute($params);
$openRepairs = (int)$stmtOpen->fetchColumn();

$stmtDown = $pdo->prepare("SELECT COALESCE(SUM(downtime_hours),0) FROM repairs r $w");
$stmtDown->execute($params);
$totalDowntime = (float)$stmtDown->fetchColumn();

$statuses = ['Reported','Scheduled','In Progress','Completed','Cancelled'];
$types    = ['Preventive','Corrective','Inspection','Parts Replacement','Calibration','Other'];

/* User info for topbar (like PLT ref) */
$userName = "User";
$userRole = "ALMS";
if (function_exists("current_user")) {
  $u = current_user();
  $userName = $u["name"] ?? $userName;
  $userRole = $u["role"] ?? $userRole;
}

/* Helper for bootstrap badge class */
function status_badge_class(string $s): string {
  $s = strtolower($s);
  return match ($s) {
    'reported','scheduled'   => 'secondary',
    'in progress'            => 'warning',
    'completed'              => 'success',
    'cancelled'              => 'danger',
    default                  => 'secondary',
  };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Repair Logs | ALMS</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="../css/style.css" rel="stylesheet" />
  <link href="../css/modules.css" rel="stylesheet" />

  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <script src="../js/sidebar-toggle.js"></script>

  <style>
    .kpi .card-body{display:flex;align-items:center;gap:.75rem}
    .kpi .icon-wrap{width:42px;height:42px;display:flex;align-items:center;justify-content:center;border-radius:12px}
    .filter-row .form-label{font-size:.8rem;color:#6b7280}
    .edit-row{background:#f7f9ff}
  </style>
</head>
<body>
<div class="container-fluid p-0">
  <div class="row g-0">

    <?php include __DIR__ . '/../includes/sidebar.php' ?>

    <!-- Main -->
    <div class="col main-content p-3 p-lg-4">

      <!-- Topbar -->
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-3">
          <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2" aria-label="Toggle sidebar">
            <ion-icon name="menu-outline"></ion-icon>
          </button>
          <h2 class="m-0"><ion-icon name="hammer-outline"></ion-icon> Repair &amp; Maintenance Logs</h2>
        </div>
        <div class="d-flex align-items-center gap-2">
          <img src="../img/profile.jpg" class="rounded-circle" width="36" height="36" alt="">
          <div class="small">
            <strong><?= h($userName) ?></strong><br/>
            <span class="text-muted"><?= h($userRole) ?></span>
          </div>
        </div>
      </div>

      <!-- KPI cards -->
      <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
          <div class="card shadow-sm kpi h-100">
            <div class="card-body">
              <div class="icon-wrap bg-primary-subtle"><ion-icon name="construct-outline"></ion-icon></div>
              <div>
                <div class="text-muted small">Total Repairs</div>
                <div class="h4 m-0"><?= (int)$totalRepairs ?></div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="card shadow-sm kpi h-100">
            <div class="card-body">
              <div class="icon-wrap bg-warning-subtle"><ion-icon name="time-outline"></ion-icon></div>
              <div>
                <div class="text-muted small">Open Repairs</div>
                <div class="h4 m-0"><?= (int)$openRepairs ?></div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="card shadow-sm kpi h-100">
            <div class="card-body">
              <div class="icon-wrap bg-success-subtle"><ion-icon name="card-outline"></ion-icon></div>
              <div>
                <div class="text-muted small">Total Cost</div>
                <div class="h4 m-0">₱<?= money_fmt($totalCost) ?></div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="card shadow-sm kpi h-100">
            <div class="card-body">
              <div class="icon-wrap bg-info-subtle"><ion-icon name="hourglass-outline"></ion-icon></div>
              <div>
                <div class="text-muted small">Downtime (h)</div>
                <div class="h4 m-0"><?= money_fmt($totalDowntime) ?></div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Filters + Export -->
      <div class="card shadow-sm mb-3">
        <div class="card-body filter-row">
          <div class="row g-2 align-items-end">
            <div class="col-12 col-md-2">
              <label class="form-label">Status</label>
              <select id="f_status" class="form-select">
                <option value="">All</option>
                <?php foreach ($statuses as $s): ?>
                  <option value="<?= h($s) ?>" <?= $filterStatus===$s?'selected':'' ?>><?= h($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label">From</label>
              <input id="f_from" type="date" class="form-control" value="<?= h($filterFrom) ?>">
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label">To</label>
              <input id="f_to" type="date" class="form-control" value="<?= h($filterTo) ?>">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label">Search</label>
              <input id="f_q" class="form-control" placeholder="Description / Asset / Plate / Provider" value="<?= h($filterQ) ?>">
            </div>
            <div class="col-12 col-md-2 d-grid d-md-flex justify-content-md-end">
              <button class="btn btn-outline-primary me-md-2" onclick="applyFilters()">
                <ion-icon name="filter-outline"></ion-icon> Apply
              </button>
              <a class="btn btn-violet" href="?action=export&status=<?= h($filterStatus) ?>&from=<?= h($filterFrom) ?>&to=<?= h($filterTo) ?>&q=<?= urlencode($filterQ) ?>">
                <ion-icon name="download-outline"></ion-icon> Export
              </a>
            </div>
          </div>
        </div>
      </div>

      <!-- Add repair -->
      <div class="card shadow-sm mb-3">
        <div class="card-body">
          <h6 class="mb-3">Add Repair</h6>
          <form method="POST" class="row g-2 align-items-end">
            <input type="hidden" name="op" value="add">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

            <div class="col-12 col-md-3">
              <label class="form-label">Asset</label>
              <select name="asset_id" class="form-select" required>
                <option value="">Select Asset</option>
                <?php foreach($assets as $a): ?>
                  <option value="<?= (int)$a['id'] ?>" <?= ($filterAssetId==$a['id'])?'selected':'' ?>><?= h($a['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-6 col-md-2">
              <label class="form-label">Date</label>
              <input type="date" name="repair_date" class="form-control" required>
            </div>

            <div class="col-6 col-md-2">
              <label class="form-label">Type</label>
              <input type="text" name="maintenance_type" class="form-control" placeholder="Preventive / …">
            </div>

            <div class="col-6 col-md-2">
              <label class="form-label">Technician</label>
              <input type="text" name="technician" class="form-control" placeholder="Tech/Shop">
            </div>

            <div class="col-6 col-md-2">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <?php foreach ($statuses as $s): ?>
                  <option <?= $s==='Reported'?'selected':'' ?>><?= h($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-6 col-md-2">
              <label class="form-label">Cost</label>
              <input type="number" step="0.01" name="cost" class="form-control" required>
            </div>

            <div class="col-6 col-md-2">
              <label class="form-label">Odometer (km)</label>
              <input type="number" name="odometer_km" class="form-control">
            </div>

            <div class="col-6 col-md-2">
              <label class="form-label">Downtime (h)</label>
              <input type="number" step="0.1" name="downtime_hours" class="form-control">
            </div>

            <div class="col-6 col-md-2">
              <label class="form-label">Vehicle Plate</label>
              <input type="text" name="tnvs_vehicle_plate" class="form-control" placeholder="ABC-1234">
            </div>

            <div class="col-6 col-md-2">
              <label class="form-label">Provider</label>
              <input type="text" name="tnvs_provider" class="form-control" placeholder="TNVS">
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">Description</label>
              <input type="text" name="description" class="form-control" placeholder="Issue / work done" required>
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">Notes</label>
              <textarea name="notes" rows="1" class="form-control" placeholder="(optional)"></textarea>
            </div>

            <div class="col-12 col-md-2 d-grid">
              <button class="btn btn-violet"><ion-icon name="add-circle-outline"></ion-icon> Add</button>
            </div>
          </form>
        </div>
      </div>

      <!-- Table -->
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h5 class="m-0">Repair Logs</h5>
          </div>

          <div class="table-responsive">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Asset</th>
                  <th>Plate / Provider</th>
                  <th>Date</th>
                  <th>Type</th>
                  <th>Technician</th>
                  <th>Status</th>
                  <th>Cost</th>
                  <th>Odometer</th>
                  <th>Downtime</th>
                  <th>Description</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($repairs as $r): ?>
                <tr>
                  <td><?= (int)$r['id'] ?></td>
                  <td><?= h($r['asset_name']) ?></td>
                  <td>
                    <?= h($r['tnvs_vehicle_plate']) ?><br>
                    <span class="text-muted small"><?= h($r['tnvs_provider']) ?></span>
                  </td>
                  <td><?= h($r['repair_date']) ?></td>
                  <td><?= h($r['maintenance_type']) ?></td>
                  <td><?= h($r['technician']) ?></td>
                  <td><span class="badge bg-<?= status_badge_class($r['status']) ?>"><?= h($r['status']) ?></span></td>
                  <td>₱<?= money_fmt($r['cost']) ?></td>
                  <td><?= h($r['odometer_km']) ?></td>
                  <td><?= h($r['downtime_hours']) ?></td>
                  <td><?= h($r['description']) ?></td>
                  <td class="text-end">
                    <button class="btn btn-sm btn-outline-secondary me-1" onclick="toggleEdit(<?= (int)$r['id'] ?>)">
                      <ion-icon name="create-outline"></ion-icon> Edit
                    </button>
                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this repair log?')">
                      <input type="hidden" name="op" value="delete">
                      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <button class="btn btn-sm btn-danger">
                        <ion-icon name="trash-outline"></ion-icon> Delete
                      </button>
                    </form>
                  </td>
                </tr>

                <!-- inline edit row -->
                <tr id="edit-<?= (int)$r['id'] ?>" class="edit-row" style="display:none">
                  <td colspan="12">
                    <form method="POST" class="row g-2 align-items-end">
                      <input type="hidden" name="op" value="update">
                      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">

                      <div class="col-12 col-md-3">
                        <label class="form-label">Asset</label>
                        <select name="asset_id" class="form-select" required>
                          <?php foreach($assets as $a): ?>
                            <option value="<?= (int)$a['id'] ?>" <?= $r['asset_id']==$a['id']?'selected':'' ?>><?= h($a['name']) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>

                      <div class="col-6 col-md-2">
                        <label class="form-label">Date</label>
                        <input type="date" name="repair_date" class="form-control" value="<?= h($r['repair_date']) ?>" required>
                      </div>

                      <div class="col-6 col-md-2">
                        <label class="form-label">Type</label>
                        <input type="text" name="maintenance_type" class="form-control" value="<?= h($r['maintenance_type']) ?>">
                      </div>

                      <div class="col-6 col-md-2">
                        <label class="form-label">Technician</label>
                        <input type="text" name="technician" class="form-control" value="<?= h($r['technician']) ?>">
                      </div>

                      <div class="col-6 col-md-2">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                          <?php foreach ($statuses as $s): ?>
                            <option <?= $r['status']===$s?'selected':'' ?>><?= h($s) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>

                      <div class="col-6 col-md-2">
                        <label class="form-label">Cost</label>
                        <input type="number" step="0.01" name="cost" class="form-control" value="<?= h($r['cost']) ?>" required>
                      </div>

                      <div class="col-6 col-md-2">
                        <label class="form-label">Odometer (km)</label>
                        <input type="number" name="odometer_km" class="form-control" value="<?= h($r['odometer_km']) ?>">
                      </div>

                      <div class="col-6 col-md-2">
                        <label class="form-label">Downtime (h)</label>
                        <input type="number" step="0.1" name="downtime_hours" class="form-control" value="<?= h($r['downtime_hours']) ?>">
                      </div>

                      <div class="col-6 col-md-2">
                        <label class="form-label">Vehicle Plate</label>
                        <input type="text" name="tnvs_vehicle_plate" class="form-control" value="<?= h($r['tnvs_vehicle_plate']) ?>">
                      </div>

                      <div class="col-6 col-md-2">
                        <label class="form-label">Provider</label>
                        <input type="text" name="tnvs_provider" class="form-control" value="<?= h($r['tnvs_provider']) ?>">
                      </div>

                      <div class="col-12 col-md-4">
                        <label class="form-label">Description</label>
                        <input type="text" name="description" class="form-control" value="<?= h($r['description']) ?>" required>
                      </div>

                      <div class="col-12 col-md-4">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" rows="1" class="form-control"><?= h($r['notes']) ?></textarea>
                      </div>

                      <div class="col-12 col-md-2 d-grid">
                        <button class="btn btn-primary"><ion-icon name="save-outline"></ion-icon> Save</button>
                      </div>
                      <div class="col-12 col-md-2 d-grid">
                        <button class="btn btn-outline-secondary" type="button" onclick="toggleEdit(<?= (int)$r['id'] ?>)">
                          <ion-icon name="close-outline"></ion-icon> Cancel
                        </button>
                      </div>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>

        </div>
      </div>

    </div><!-- /main-content -->
  </div><!-- /row -->
</div><!-- /container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function applyFilters(){
  const s = document.getElementById('f_status').value;
  const f = document.getElementById('f_from').value;
  const t = document.getElementById('f_to').value;
  const q = document.getElementById('f_q').value.trim();
  const url = new URL(window.location.href);
  if (s) url.searchParams.set('status', s); else url.searchParams.delete('status');
  if (f) url.searchParams.set('from', f); else url.searchParams.delete('from');
  if (t) url.searchParams.set('to', t); else url.searchParams.delete('to');
  if (q) url.searchParams.set('q', q); else url.searchParams.delete('q');
  window.location.href = url.toString();
}
function toggleEdit(id){
  const el = document.getElementById('edit-'+id);
  if (!el) return;
  el.style.display = (el.style.display==='none' || !el.style.display) ? 'table-row' : 'none';
}
window.addEventListener('storage', function(e){ if (e.key === 'repairs_changed') { window.location.reload(); } });
</script>
</body>
</html>
