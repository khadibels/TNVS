<?php
require_once __DIR__ . "/../includes/config.php";
require_once __DIR__ . "/../includes/auth.php";
require_login();
require_role(['admin', 'asset_manager']);
require_once __DIR__ . "/../includes/db.php";

$pdo = db('alms');
if (!$pdo) {  
  http_response_code(500);
  exit('Database connect failed for ALMS. Check DB name/user/pass in config.php and CyberPanel privileges.');
}

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
    :root {
      --slate-50: #f8fafc;
      --slate-100: #f1f5f9;
      --slate-200: #e2e8f0;
      --slate-600: #475569;
      --slate-800: #1e293b;
    }
    body { background-color: var(--slate-50); }
    .text-label {
      font-size: 0.7rem;
      text-transform: uppercase;
      letter-spacing: 0.6px;
      font-weight: 700;
      color: #94a3b8;
      margin-bottom: 2px;
    }
    .stats-row {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
      gap: 1.25rem;
      margin-bottom: 1.25rem;
    }
    .stat-card {
      background: #fff;
      border: 1px solid var(--slate-200);
      border-radius: 1rem;
      padding: 1.2rem;
      box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }
    .stat-icon {
      width: 44px;
      height: 44px;
      border-radius: 12px;
      display:flex;
      align-items:center;
      justify-content:center;
      font-size: 1.3rem;
      margin-bottom: .8rem;
    }
    .card-table {
      border: 1px solid var(--slate-200);
      border-radius: 1rem;
      background: #fff;
      box-shadow: 0 1px 3px rgba(0,0,0,0.05);
      overflow: hidden;
    }
    .table-custom thead th {
      font-size: 0.75rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: var(--slate-600);
      background: var(--slate-50);
      border-bottom: 1px solid var(--slate-200);
      font-weight: 600;
      padding: 1rem 1.25rem;
      white-space: nowrap;
    }
    .table-custom tbody td {
      padding: 0.95rem 1.25rem;
      border-bottom: 1px solid var(--slate-100);
      font-size: 0.95rem;
      color: var(--slate-800);
      vertical-align: middle;
    }
    .table-custom tbody tr:last-child td { border-bottom: none; }
    .table-custom tbody tr:hover td { background-color: #f8fafc; }
    .filters-wrap .form-label { font-size: 0.8rem; color: #64748b; margin-bottom: 4px; }
    .edit-row{ background:#f7f9ff; }
    .add-form { border: 1px solid var(--slate-200); border-radius: 1rem; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
  </style>
</head>
<body class="saas-page">
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
          <h2 class="m-0 d-flex align-items-center gap-2 page-title"><ion-icon name="hammer-outline"></ion-icon> Repair &amp; Maintenance Logs</h2>
        </div>
        <div class="profile-menu" data-profile-menu>
          <button class="profile-trigger" type="button" data-profile-trigger aria-expanded="false" aria-haspopup="true">
            <img src="../img/profile.jpg" class="rounded-circle" width="36" height="36" alt="">
            <div class="profile-text">
              <div class="profile-name"><?= h($userName) ?></div>
              <div class="profile-role"><?= h($userRole) ?></div>
            </div>
            <ion-icon class="profile-caret" name="chevron-down-outline"></ion-icon>
          </button>
          <div class="profile-dropdown" data-profile-dropdown role="menu">
            <a href="<?= u('auth/logout.php') ?>" role="menuitem">Sign out</a>
          </div>
        </div>
      </div>

      <div class="px-4 pb-5">
      <!-- KPI cards -->
      <section class="stats-row">
        <div class="stat-card">
          <div class="stat-icon bg-primary bg-opacity-10 text-primary"><ion-icon name="construct-outline"></ion-icon></div>
          <div class="text-label">Total Repairs</div>
          <div class="fs-3 fw-bold text-dark mt-1"><?= (int)$totalRepairs ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon bg-warning bg-opacity-10 text-warning"><ion-icon name="time-outline"></ion-icon></div>
          <div class="text-label">Open Repairs</div>
          <div class="fs-3 fw-bold text-dark mt-1"><?= (int)$openRepairs ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon bg-success bg-opacity-10 text-success"><ion-icon name="card-outline"></ion-icon></div>
          <div class="text-label">Total Cost</div>
          <div class="fs-3 fw-bold text-dark mt-1">₱<?= money_fmt($totalCost) ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon bg-info bg-opacity-10 text-info"><ion-icon name="hourglass-outline"></ion-icon></div>
          <div class="text-label">Downtime (h)</div>
          <div class="fs-3 fw-bold text-dark mt-1"><?= money_fmt($totalDowntime) ?></div>
        </div>
      </section>

      <!-- Filters + Export -->
      <section class="filters-wrap mb-3">
          <div class="d-flex flex-wrap gap-2 align-items-end">
            <div style="min-width:180px;">
              <label class="form-label">Status</label>
              <select id="f_status" class="form-select">
                <option value="">All</option>
                <?php foreach ($statuses as $s): ?>
                  <option value="<?= h($s) ?>" <?= $filterStatus===$s?'selected':'' ?>><?= h($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div style="min-width:160px;">
              <label class="form-label">From</label>
              <input id="f_from" type="date" class="form-control" value="<?= h($filterFrom) ?>">
            </div>
            <div style="min-width:160px;">
              <label class="form-label">To</label>
              <input id="f_to" type="date" class="form-control" value="<?= h($filterTo) ?>">
            </div>
            <div class="flex-grow-1" style="min-width:260px;">
              <label class="form-label">Search</label>
              <input id="f_q" class="form-control" placeholder="Description / Asset / Plate / Provider" value="<?= h($filterQ) ?>">
            </div>
            <button class="btn btn-white border shadow-sm fw-medium px-3" onclick="applyFilters()">
              Filter
            </button>
            <a class="btn btn-link text-decoration-none text-muted p-0" href="repair.php">Reset</a>
            <div class="ms-auto d-flex align-items-center gap-2">
              <button class="btn btn-primary d-flex align-items-center gap-2" type="button" data-bs-toggle="modal" data-bs-target="#addRepairModal">
                <ion-icon name="add-circle-outline"></ion-icon><span>Add Repair</span>
              </button>
              <a class="btn btn-violet" href="?action=export&status=<?= h($filterStatus) ?>&from=<?= h($filterFrom) ?>&to=<?= h($filterTo) ?>&q=<?= urlencode($filterQ) ?>">
                <ion-icon name="download-outline"></ion-icon> Export
              </a>
            </div>  
          </div>
      </section>

      <!-- Table -->
      <section class="card-table">
          <div class="d-flex justify-content-between align-items-center p-3 border-bottom bg-light bg-opacity-50">
            <h5 class="m-0 fw-semibold">Repair Logs</h5>
            <span class="small text-muted"><?= count($repairs) ?> records</span>
          </div>

          <div class="table-responsive">
            <table class="table table-custom mb-0 align-middle">
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
              <?php if (!$repairs): ?>
                <tr>
                  <td colspan="12" class="text-center py-4 text-muted">No repair logs found.</td>
                </tr>
              <?php endif; ?>
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
                    <button
                      class="btn btn-sm btn-outline-secondary me-1"
                      type="button"
                      data-id="<?= (int)$r['id'] ?>"
                      data-asset-id="<?= (int)$r['asset_id'] ?>"
                      data-repair-date="<?= h($r['repair_date']) ?>"
                      data-maintenance-type="<?= h($r['maintenance_type']) ?>"
                      data-technician="<?= h($r['technician']) ?>"
                      data-status="<?= h($r['status']) ?>"
                      data-cost="<?= h($r['cost']) ?>"
                      data-odometer-km="<?= h($r['odometer_km']) ?>"
                      data-downtime-hours="<?= h($r['downtime_hours']) ?>"
                      data-tnvs-vehicle-plate="<?= h($r['tnvs_vehicle_plate']) ?>"
                      data-tnvs-provider="<?= h($r['tnvs_provider']) ?>"
                      data-description="<?= h($r['description']) ?>"
                      data-notes="<?= h($r['notes']) ?>"
                      onclick="openRepairEditor(this)">
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
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
      </section>
      </div>

    </div><!-- /main-content -->
  </div><!-- /row -->
</div><!-- /container -->

<!-- Add Repair Modal -->
<div id="addRepairModal" class="modal fade" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form method="POST">
        <div class="modal-header">
          <h5 class="modal-title">Add Repair Log</h5>
          <button class="btn-close" data-bs-dismiss="modal" type="button"></button>
        </div>
        <div class="modal-body row g-2">
          <input type="hidden" name="op" value="add">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

          <div class="col-12 col-md-4">
            <label class="form-label text-label">Asset</label>
            <select name="asset_id" class="form-select" required>
              <option value="">Select Asset</option>
              <?php foreach($assets as $a): ?>
                <option value="<?= (int)$a['id'] ?>" <?= ($filterAssetId==$a['id'])?'selected':'' ?>><?= h($a['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6 col-md-4">
            <label class="form-label text-label">Date</label>
            <input type="date" name="repair_date" class="form-control" required>
          </div>
          <div class="col-6 col-md-4">
            <label class="form-label text-label">Type</label>
            <input type="text" name="maintenance_type" class="form-control" placeholder="Preventive / Corrective">
          </div>
          <div class="col-6 col-md-4">
            <label class="form-label text-label">Technician</label>
            <input type="text" name="technician" class="form-control" placeholder="Tech/Shop">
          </div>
          <div class="col-6 col-md-4">
            <label class="form-label text-label">Status</label>
            <select name="status" class="form-select">
              <?php foreach ($statuses as $s): ?>
                <option <?= $s==='Reported'?'selected':'' ?>><?= h($s) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6 col-md-4">
            <label class="form-label text-label">Cost</label>
            <input type="number" step="0.01" name="cost" class="form-control" required>
          </div>
          <div class="col-6 col-md-4">
            <label class="form-label text-label">Odometer (km)</label>
            <input type="number" name="odometer_km" class="form-control">
          </div>
          <div class="col-6 col-md-4">
            <label class="form-label text-label">Downtime (h)</label>
            <input type="number" step="0.1" name="downtime_hours" class="form-control">
          </div>
          <div class="col-6 col-md-4">
            <label class="form-label text-label">Vehicle Plate</label>
            <input type="text" name="tnvs_vehicle_plate" class="form-control" placeholder="ABC-1234">
          </div>
          <div class="col-6 col-md-4">
            <label class="form-label text-label">Provider</label>
            <input type="text" name="tnvs_provider" class="form-control" placeholder="TNVS">
          </div>
          <div class="col-12">
            <label class="form-label text-label">Description</label>
            <input type="text" name="description" class="form-control" placeholder="Issue / work done" required>
          </div>
          <div class="col-12">
            <label class="form-label text-label">Notes</label>
            <textarea name="notes" rows="2" class="form-control" placeholder="(optional)"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Close</button>
          <button class="btn btn-violet" type="submit"><ion-icon name="add-circle-outline"></ion-icon> Save Repair</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Repair Modal -->
<div id="editRepairModal" class="modal fade" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form method="POST" id="editRepairForm">
        <div class="modal-header">
          <h5 class="modal-title">Update Repair <span id="editRepairIdLabel" class="text-muted"></span></h5>
          <button class="btn-close" data-bs-dismiss="modal" type="button"></button>
        </div>
        <div class="modal-body row g-2">
          <input type="hidden" name="op" value="update">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="id" id="edit_id">

          <div class="col-12 col-md-4">
            <label class="form-label text-label">Asset</label>
            <select name="asset_id" id="edit_asset_id" class="form-select" required>
              <?php foreach($assets as $a): ?>
                <option value="<?= (int)$a['id'] ?>"><?= h($a['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6 col-md-4">
            <label class="form-label text-label">Date</label>
            <input type="date" name="repair_date" id="edit_repair_date" class="form-control" required>
          </div>
          <div class="col-6 col-md-4">
            <label class="form-label text-label">Type</label>
            <input type="text" name="maintenance_type" id="edit_maintenance_type" class="form-control">
          </div>
          <div class="col-6 col-md-4">
            <label class="form-label text-label">Technician</label>
            <input type="text" name="technician" id="edit_technician" class="form-control">
          </div>
          <div class="col-6 col-md-4">
            <label class="form-label text-label">Status</label>
            <select name="status" id="edit_status" class="form-select">
              <?php foreach ($statuses as $s): ?>
                <option><?= h($s) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6 col-md-4">
            <label class="form-label text-label">Cost</label>
            <input type="number" step="0.01" name="cost" id="edit_cost" class="form-control" required>
          </div>
          <div class="col-6 col-md-4">
            <label class="form-label text-label">Odometer (km)</label>
            <input type="number" name="odometer_km" id="edit_odometer_km" class="form-control">
          </div>
          <div class="col-6 col-md-4">
            <label class="form-label text-label">Downtime (h)</label>
            <input type="number" step="0.1" name="downtime_hours" id="edit_downtime_hours" class="form-control">
          </div>
          <div class="col-6 col-md-4">
            <label class="form-label text-label">Vehicle Plate</label>
            <input type="text" name="tnvs_vehicle_plate" id="edit_tnvs_vehicle_plate" class="form-control">
          </div>
          <div class="col-6 col-md-4">
            <label class="form-label text-label">Provider</label>
            <input type="text" name="tnvs_provider" id="edit_tnvs_provider" class="form-control">
          </div>
          <div class="col-12">
            <label class="form-label text-label">Description</label>
            <input type="text" name="description" id="edit_description" class="form-control" required>
          </div>
          <div class="col-12">
            <label class="form-label text-label">Notes</label>
            <textarea name="notes" id="edit_notes" rows="2" class="form-control"></textarea>
          </div>
        </div>
        <div class="modal-footer d-flex justify-content-between">
          <button class="btn btn-danger" type="button" onclick="submitRepairDelete()"><ion-icon name="trash-outline"></ion-icon> Delete</button>
          <div class="d-flex gap-2">
            <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Close</button>
            <button class="btn btn-violet" type="submit"><ion-icon name="save-outline"></ion-icon> Save Changes</button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<form method="POST" id="deleteRepairForm" class="d-none" onsubmit="return confirm('Delete this repair log?')">
  <input type="hidden" name="op" value="delete">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="id" id="delete_id">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="../js/profile-dropdown.js"></script>
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
function openRepairEditor(btn){
  const d = btn.dataset;
  document.getElementById('editRepairIdLabel').textContent = '#' + (d.id || '');
  document.getElementById('edit_id').value = d.id || '';
  document.getElementById('delete_id').value = d.id || '';
  document.getElementById('edit_asset_id').value = d.assetId || '';
  document.getElementById('edit_repair_date').value = d.repairDate || '';
  document.getElementById('edit_maintenance_type').value = d.maintenanceType || '';
  document.getElementById('edit_technician').value = d.technician || '';
  document.getElementById('edit_status').value = d.status || 'Reported';
  document.getElementById('edit_cost').value = d.cost || 0;
  document.getElementById('edit_odometer_km').value = d.odometerKm || '';
  document.getElementById('edit_downtime_hours').value = d.downtimeHours || '';
  document.getElementById('edit_tnvs_vehicle_plate').value = d.tnvsVehiclePlate || '';
  document.getElementById('edit_tnvs_provider').value = d.tnvsProvider || '';
  document.getElementById('edit_description').value = d.description || '';
  document.getElementById('edit_notes').value = d.notes || '';
  new bootstrap.Modal(document.getElementById('editRepairModal')).show();
}
function submitRepairDelete(){
  document.getElementById('deleteRepairForm').submit();
}
window.addEventListener('storage', function(e){ if (e.key === 'repairs_changed') { window.location.reload(); } });
</script>
</body>
</html>
