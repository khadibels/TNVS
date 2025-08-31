<?php
// Repair Logs — Enhanced TNVS-related module (PDO + prepared statements + CSRF + filters + CSV)
// Compatible with existing assets table

session_start();

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

// Ensure assets table exists (minimal)
$pdo->exec("CREATE TABLE IF NOT EXISTS assets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  status VARCHAR(50) NOT NULL,
  installed_on DATE,
  disposed_on DATE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Ensure repairs table exists (with TNVS fields)
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

// Attempt to add missing columns (ignore if exist)
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

// Update asset.status based on open repairs
function refresh_asset_status(PDO $pdo, int $assetId): void {
    // Open statuses are those not Completed and not Cancelled
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM repairs WHERE asset_id = :aid AND status IN ('Reported','Scheduled','In Progress')");
    $stmt->execute([':aid' => $assetId]);
    $open = (int)$stmt->fetchColumn();
    if ($open > 0) {
        $pdo->prepare("UPDATE assets SET status = 'In Maintenance' WHERE id = :aid")->execute([':aid' => $assetId]);
    } else {
        // If currently in maintenance and no open repairs, consider asset Active
        $pdo->prepare("UPDATE assets SET status = 'Active' WHERE id = :aid AND status = 'In Maintenance'")->execute([':aid' => $assetId]);
    }
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];
function assert_csrf(){ if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) { http_response_code(400); exit('Invalid CSRF'); } }

// Filters for list/export
$filterStatus = $_GET['status'] ?? '';
$filterQ = trim($_GET['q'] ?? '');
$filterFrom = $_GET['from'] ?? '';
$filterTo = $_GET['to'] ?? '';
$filterAssetId = isset($_GET['asset_id']) ? (int)$_GET['asset_id'] : 0;

// CRUD handlers
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
            // Update asset status based on open repairs
            refresh_asset_status($pdo, $asset_id);
            // Optional: broadcast to other tabs
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
            // Update asset status after change
            refresh_asset_status($pdo, $asset_id);
            echo "<script>try{localStorage.setItem('repairs_changed', Date.now().toString())}catch(e){}</script>";
        }
        header('Location: repair.php' . ($filterAssetId? ('?asset_id='.$filterAssetId) : '')); exit;
    }

    if ($op === 'delete') {
        assert_csrf();
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            // Get asset_id for status refresh
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

// Export CSV (respects filters)
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

// Fetch assets for dropdown
$assets = $pdo->query("SELECT id, name FROM assets ORDER BY name ASC")->fetchAll();

// Build filter query
$params = [];
$where = [];
if ($filterStatus !== '') { $where[] = 'r.status = :status'; $params[':status'] = $filterStatus; }
if ($filterQ !== '') { $where[] = '(r.description LIKE :q OR EXISTS (SELECT 1 FROM assets a WHERE a.id = r.asset_id AND a.name LIKE :q) OR r.tnvs_vehicle_plate LIKE :q OR r.tnvs_provider LIKE :q)'; $params[':q'] = '%'.$filterQ.'%'; }
if ($filterFrom !== '') { $where[] = 'r.repair_date >= :from'; $params[':from'] = $filterFrom; }
if ($filterTo !== '') { $where[] = 'r.repair_date <= :to'; $params[':to'] = $filterTo; }
if ($filterAssetId > 0) { $where[] = 'r.asset_id = :aid'; $params[':aid'] = $filterAssetId; }
$w = $where ? ('WHERE '.implode(' AND ',$where)) : '';

// Fetch repairs list (limited)
$sqlList = "SELECT r.*, a.name AS asset_name FROM repairs r LEFT JOIN assets a ON r.asset_id = a.id $w ORDER BY r.repair_date DESC, r.id DESC LIMIT 1000";
$stmtList = $pdo->prepare($sqlList);
$stmtList->execute($params);
$repairs = $stmtList->fetchAll();

// Summary stats
$stmtTot = $pdo->prepare("SELECT COUNT(*) FROM repairs r $w");
$stmtTot->execute($params);
$totalRepairs = (int)$stmtTot->fetchColumn();

$stmtCost = $pdo->prepare("SELECT COALESCE(SUM(cost),0) FROM repairs r $w");
$stmtCost->execute($params);
$totalCost = (float)$stmtCost->fetchColumn();

$stmtOpen = $pdo->prepare("SELECT COUNT(*) FROM repairs r $w AND r.status <> 'Completed'");
// ensure valid SQL when no where
$qOpen = 'SELECT COUNT(*) FROM repairs r ' . ($w ? ($w . " AND r.status <> 'Completed'") : "WHERE r.status <> 'Completed'");
$stmtOpen = $pdo->prepare($qOpen);
$stmtOpen->execute($params);
$openRepairs = (int)$stmtOpen->fetchColumn();

$stmtDown = $pdo->prepare("SELECT COALESCE(SUM(downtime_hours),0) FROM repairs r $w");
$stmtDown->execute($params);
$totalDowntime = (float)$stmtDown->fetchColumn();

$statuses = ['Reported','Scheduled','In Progress','Completed','Cancelled'];
$types = ['Preventive','Corrective','Inspection','Parts Replacement','Calibration','Other'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>TNVS Repair & Maintenance Logs</title>
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<style>
:root{
  --bg: #f5f7fb; --card:#fff; --accent:#0f62fe; --muted:#6b7280; --text:#111827;
  --success:#10b981; --danger:#ef4444; --warning:#f59e0b;
  --shadow: 0 10px 30px rgba(16,24,40,0.08);
}
*{box-sizing:border-box}
body{margin:0;font-family:Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial;background:linear-gradient(180deg,#f7f9fc 0%,var(--bg) 100%);color:var(--text);padding:22px}
.container{max-width:1240px;margin:0 auto}
.header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px}
.btn{display:inline-flex;align-items:center;gap:8px;background:#6d28d9;color:#fff;border:0;padding:10px 14px;border-radius:10px;box-shadow:0 4px 12px rgba(16,24,40,0.06);cursor:pointer;font-weight:600;font-size:14px;text-decoration:none}
.btn.ghost{background:transparent;color:#6d28d9;border:1px solid rgba(15,98,254,0.2)}
.card{background:var(--card);padding:16px;border-radius:12px;box-shadow:var(--shadow);border:1px solid rgba(16,24,40,0.03)}
.grid{display:grid;grid-template-columns:1fr 380px;gap:16px}
@media (max-width:1100px){.grid{grid-template-columns:1fr}}
.stats{display:flex;gap:12px;flex-wrap:wrap}
.stat{flex:1;min-width:160px;padding:12px;border-radius:10px;background:linear-gradient(180deg,#ffffff,#fbfdff);border:1px solid rgba(14,165,233,0.06)}
.stat .label{font-size:12px;color:var(--muted)}
.stat .number{font-size:20px;font-weight:700}
.input,.select,textarea{padding:10px 12px;border-radius:10px;border:1px solid #e6edf6;background:transparent;font-size:14px;color:var(--text)}
.form-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.table-wrap{overflow:auto;border-radius:10px;border:1px solid rgba(17,24,39,0.06)}
.table{width:100%;border-collapse:collapse;min-width:1040px}
.table thead th{text-align:left;padding:12px 14px;background:linear-gradient(180deg,#fbfdff,#f7f9fc);font-size:13px;color:var(--muted);border-bottom:1px solid rgba(15,23,42,0.06)}
.table tbody td{padding:12px 14px;border-bottom:1px solid rgba(15,23,42,0.06);font-size:14px;vertical-align:top}
.badge{display:inline-block;padding:6px 10px;border-radius:999px;font-weight:600;font-size:12px}
.s-reported{background:rgba(59,130,246,0.08);color:#2563eb}
.s-scheduled{background:rgba(59,130,246,0.08);color:#2563eb}
.s-inprogress{background:rgba(245,158,11,0.12);color:var(--warning)}
.s-completed{background:rgba(16,185,129,0.08);color:var(--success)}
.s-cancelled{background:rgba(239,68,68,0.08);color:var(--danger)}
.edit-row{background:#eef4ff}
</style>
</head>
<body>
<div class="container">
  <header class="header">
    <div style="display:flex;gap:8px;align-items:center">
      <a href="ALMS.php" class="btn ghost"><i class='bx bx-arrow-back'></i> Back</a>
      <a href="ass1.php" class="btn ghost"><i class='bx bx-package'></i> Assets</a>
      <a href="ass2.php" class="btn ghost"><i class='bx bx-pie-chart-alt-2'></i> Asset Report</a>
    </div>
    <div>
      <h2 style="margin:0;display:flex;align-items:center;gap:8px"><i class='bx bx-wrench'></i> TNVS Repair & Maintenance</h2>
      <div style="font-size:13px;color:var(--muted)">Logs • Cost • Downtime • Provider • Plate</div>
    </div>
    <div>
      <a class="btn" href="?action=export&status=<?= h($filterStatus) ?>&from=<?= h($filterFrom) ?>&to=<?= h($filterTo) ?>&q=<?= urlencode($filterQ) ?>"><i class='bx bx-download'></i> Export CSV</a>
    </div>
  </header>

  <main class="grid">
    <section>
      <div class="card">
        <div class="stats">
          <div class="stat"><div class="label">Total Repairs</div><div class="number"><?= (int)$totalRepairs ?></div></div>
          <div class="stat"><div class="label">Open Repairs</div><div class="number"><?= (int)$openRepairs ?></div></div>
          <div class="stat"><div class="label">Total Cost</div><div class="number">₱<?= money_fmt($totalCost) ?></div></div>
          <div class="stat"><div class="label">Total Downtime (h)</div><div class="number"><?= money_fmt($totalDowntime) ?></div></div>
        </div>
      </div>

      <div class="card" style="margin-top:14px">
        <div class="form-row">
          <select id="f_status" class="select" onchange="applyFilters()">
            <option value="">All statuses</option>
            <?php foreach ($statuses as $s): ?>
              <option value="<?= h($s) ?>" <?= $filterStatus===$s?'selected':'' ?>><?= h($s) ?></option>
            <?php endforeach; ?>
          </select>
          <input id="f_from" type="date" class="input" value="<?= h($filterFrom) ?>">
          <input id="f_to" type="date" class="input" value="<?= h($filterTo) ?>">
          <input id="f_q" class="input" placeholder="Search desc/asset/plate/provider" value="<?= h($filterQ) ?>">
          <button class="btn" onclick="applyFilters()"><i class='bx bx-filter'></i> Apply</button>
        </div>
      </div>

      <div class="card" style="margin-top:14px">
        <form method="POST" class="form-row" style="gap:8px;align-items:flex-start">
          <input type="hidden" name="op" value="add">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <select name="asset_id" class="select" required>
            <option value="">Select Asset</option>
            <?php foreach($assets as $a): ?>
              <option value="<?= (int)$a['id'] ?>" <?= (isset($filterAssetId) && $filterAssetId==$a['id'])?'selected':'' ?>><?php echo h($a['name']); ?></option>
            <?php endforeach; ?>
          </select>
          <input type="date" name="repair_date" class="input" required>
          <input type="text" name="maintenance_type" class="input" placeholder="Type (e.g., Preventive)">
          <input type="text" name="technician" class="input" placeholder="Technician">
          <select name="status" class="select">
            <?php foreach ($statuses as $s): ?><option <?= $s==='Reported'?'selected':'' ?>><?= h($s) ?></option><?php endforeach; ?>
          </select>
          <input type="number" step="0.01" name="cost" class="input" placeholder="Cost" required>
          <input type="number" name="odometer_km" class="input" placeholder="Odometer (km)">
          <input type="number" step="0.1" name="downtime_hours" class="input" placeholder="Downtime (h)">
          <input type="text" name="tnvs_vehicle_plate" class="input" placeholder="Vehicle Plate">
          <input type="text" name="tnvs_provider" class="input" placeholder="Provider (TNVS)">
          <input type="text" name="description" class="input" placeholder="Description" style="min-width:220px" required>
          <textarea name="notes" rows="1" class="input" placeholder="Notes" style="min-width:180px"></textarea>
          <button class="btn" type="submit"><i class='bx bx-plus'></i> Add Repair</button>
        </form>
      </div>

      <div class="card" style="margin-top:14px">
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>ID</th>
                <th>Asset</th>
                <th>Plate/Provider</th>
                <th>Date</th>
                <th>Type</th>
                <th>Technician</th>
                <th>Status</th>
                <th>Cost</th>
                <th>Odometer</th>
                <th>Downtime (h)</th>
                <th>Description</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($repairs as $r): ?>
              <tr>
                <td><?= (int)$r['id'] ?></td>
                <td><?= h($r['asset_name']) ?></td>
                <td><?= h($r['tnvs_vehicle_plate']) ?><br><span style="color:#6b7280;font-size:12px"><?= h($r['tnvs_provider']) ?></span></td>
                <td><?= h($r['repair_date']) ?></td>
                <td><?= h($r['maintenance_type']) ?></td>
                <td><?= h($r['technician']) ?></td>
                <td>
                  <?php $c = strtolower(str_replace(' ','',$r['status'])); ?>
                  <span class="badge <?= 's-'.($c) ?>"><?= h($r['status']) ?></span>
                </td>
                <td>₱<?= money_fmt($r['cost']) ?></td>
                <td><?= h($r['odometer_km']) ?></td>
                <td><?= h($r['downtime_hours']) ?></td>
                <td><?= h($r['description']) ?></td>
                <td>
                  <button class="btn ghost" onclick="toggleEdit(<?= (int)$r['id'] ?>)"><i class='bx bx-edit-alt'></i> Edit</button>
                  <form method="POST" style="display:inline" onsubmit="return confirm('Delete this repair log?')">
                    <input type="hidden" name="op" value="delete">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn" style="background:var(--danger)"><i class='bx bx-trash'></i> Delete</button>
                  </form>
                </td>
              </tr>
              <tr id="edit-<?= (int)$r['id'] ?>" class="edit-row" style="display:none">
                <td colspan="12">
                  <form method="POST" class="form-row" style="gap:8px;align-items:flex-start">
                    <input type="hidden" name="op" value="update">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <select name="asset_id" class="select" required>
                      <?php foreach($assets as $a): ?>
                        <option value="<?= (int)$a['id'] ?>" <?= $r['asset_id']==$a['id']?'selected':'' ?>><?= h($a['name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <input type="date" name="repair_date" class="input" value="<?= h($r['repair_date']) ?>" required>
                    <input type="text" name="maintenance_type" class="input" value="<?= h($r['maintenance_type']) ?>" placeholder="Type">
                    <input type="text" name="technician" class="input" value="<?= h($r['technician']) ?>" placeholder="Technician">
                    <select name="status" class="select">
                      <?php foreach ($statuses as $s): ?><option <?= $r['status']===$s?'selected':'' ?>><?= h($s) ?></option><?php endforeach; ?>
                    </select>
                    <input type="number" step="0.01" name="cost" class="input" value="<?= h($r['cost']) ?>" placeholder="Cost" required>
                    <input type="number" name="odometer_km" class="input" value="<?= h($r['odometer_km']) ?>" placeholder="Odometer (km)">
                    <input type="number" step="0.1" name="downtime_hours" class="input" value="<?= h($r['downtime_hours']) ?>" placeholder="Downtime (h)">
                    <input type="text" name="tnvs_vehicle_plate" class="input" value="<?= h($r['tnvs_vehicle_plate']) ?>" placeholder="Vehicle Plate">
                    <input type="text" name="tnvs_provider" class="input" value="<?= h($r['tnvs_provider']) ?>" placeholder="Provider (TNVS)">
                    <input type="text" name="description" class="input" value="<?= h($r['description']) ?>" placeholder="Description" style="min-width:220px" required>
                    <textarea name="notes" rows="1" class="input" placeholder="Notes" style="min-width:180px"><?= h($r['notes']) ?></textarea>
                    <button class="btn" type="submit"><i class='bx bx-save'></i> Save</button>
                    <button class="btn ghost" type="button" onclick="toggleEdit(<?= (int)$r['id'] ?>)"><i class='bx bx-x'></i> Cancel</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <aside>
      <div class="card">
        <div style="font-size:13px;color:var(--muted);margin-bottom:6px">TNVS Notes</div>
        <div style="font-size:13px;color:#374151;line-height:1.6">
          Use the plate and provider fields to relate repairs to your TNVS fleet. Track downtime and cost per incident and export for compliance reporting.
        </div>
        <div style="margin-top:10px;font-size:13px;color:var(--muted)">
          Status guide:
          <ul style="margin:6px 0 0 18px;padding:0">
            <li>Reported: Issue logged by driver/ops</li>
            <li>Scheduled: Job scheduled with technician or service center</li>
            <li>In Progress: Work underway</li>
            <li>Completed: Work finished and asset available</li>
            <li>Cancelled: Cancelled or duplicate</li>
          </ul>
        </div>
      </div>
    </aside>
  </main>
</div>

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
  if (!el) return; el.style.display = (el.style.display==='none'||!el.style.display) ? 'table-row' : 'none';
}
// Optional: auto-refresh when repairs change in another tab
window.addEventListener('storage', function(e){ if (e.key === 'repairs_changed') { window.location.reload(); } });
</script>
</body>
</html>
