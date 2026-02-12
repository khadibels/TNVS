<?php
// -------------------- BOOTSTRAP (session/errors) --------------------
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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
$active  = 'assettracker';

$isAdmin = true;

// -------------------- DB: TABLE + COLUMNS --------------------
$pdo->exec("CREATE TABLE IF NOT EXISTS assets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  status VARCHAR(50) NOT NULL,
  installed_on DATE,
  disposed_on DATE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function col_missing(PDO $pdo, string $table, string $col): bool {
  $stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
  );
  $stmt->execute([$table, $col]);
  return $stmt->fetchColumn() == 0;
}
function safe_add_column(PDO $pdo, string $table, string $definition) {
  [$col] = explode(' ', $definition, 2);
  if (col_missing($pdo, $table, $col)) {
    $pdo->exec("ALTER TABLE `$table` ADD COLUMN $definition");
  }
}
foreach ([
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
] as $def) { safe_add_column($pdo, 'assets', $def); }

// Seed default purchased vehicles once (no duplicates).
$seedVehicles = [
  ['name' => 'Toyota Hiace', 'manufacturer' => 'Toyota', 'model' => 'Hiace'],
  ['name' => 'Isuzu Travis', 'manufacturer' => 'Isuzu', 'model' => 'Travis'],
];
$seedCheck = $pdo->prepare("SELECT id FROM assets WHERE LOWER(name)=LOWER(:name) LIMIT 1");
$seedInsert = $pdo->prepare(
  "INSERT INTO assets
   (name, asset_type, manufacturer, model, department, status, notes)
   VALUES
   (:name, 'Vehicle', :manufacturer, :model, 'Logistics', 'Registered', 'Seeded default purchased vehicle')"
);
foreach ($seedVehicles as $vehicle) {
  $seedCheck->execute([':name' => $vehicle['name']]);
  if (!$seedCheck->fetchColumn()) {
    $seedInsert->execute([
      ':name' => $vehicle['name'],
      ':manufacturer' => $vehicle['manufacturer'],
      ':model' => $vehicle['model'],
    ]);
  }
}

// -------------------- HELPERS --------------------
function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function money_fmt($v){ return number_format((float)$v, 2); }
function next_status_options(string $status): array {
  return match ($status) {
    'Registered' => ['Deployed'],
    'Deployed' => ['Active', 'In Maintenance', 'Retired'],
    'Active' => ['In Maintenance', 'Retired'],
    'In Maintenance' => ['Active', 'Retired'],
    'Retired' => ['Disposed'],
    default => [],
  };
}

// CSRF
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(16)); }
$csrf = $_SESSION['csrf_token'];
function assert_csrf(){
  if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
    http_response_code(400);
    exit('Invalid CSRF');
  }
}

// -------------------- FILTERS (GET) --------------------
$fStatus = $_GET['status'] ?? '';
$fType   = $_GET['type']   ?? '';
$fDept   = $_GET['dept']   ?? '';
$fQ      = trim($_GET['q'] ?? '');

// -------------------- CRUD (POST) --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!$isAdmin) { http_response_code(403); exit('Forbidden'); }
  $op = $_POST['op'] ?? '';

  if ($op === 'add') {
    assert_csrf();
    $name            = trim($_POST['name'] ?? '');
    $unique_id       = trim($_POST['unique_id'] ?? '');
    $asset_type      = $_POST['asset_type'] ?? '';
    $manufacturer    = trim($_POST['manufacturer'] ?? '');
    $model           = trim($_POST['model'] ?? '');
    $purchase_date   = $_POST['purchase_date'] ?: null;
    $purchase_cost   = ($_POST['purchase_cost'] !== '' ? (float)$_POST['purchase_cost'] : null);
    $department      = trim($_POST['department'] ?? '');
    $driver          = trim($_POST['driver'] ?? '');
    $route           = trim($_POST['route'] ?? '');
    $depot           = trim($_POST['depot'] ?? '');
    $status          = $_POST['status'] ?: 'Registered';
    $deployment_date = $_POST['deployment_date'] ?: null;
    $gps_imei        = trim($_POST['gps_imei'] ?? '');
    $notes           = trim($_POST['notes'] ?? '');

    if ($name === '') {
      header('Location: '.$_SERVER['PHP_SELF'].'?err='.urlencode('Name is required')); exit;
    }

    try {
      $stmt = $pdo->prepare(
        "INSERT INTO assets
         (name, unique_id, asset_type, manufacturer, model, purchase_date, purchase_cost, department, driver, route, depot, status, deployment_date, installed_on, gps_imei, notes)
         VALUES
         (:name,:unique_id,:asset_type,:manufacturer,:model,:purchase_date,:purchase_cost,:department,:driver,:route,:depot,:status,:deployment_date,:installed_on,:gps_imei,:notes)"
      );
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
        ':installed_on'=>$deployment_date ?: null,
        ':gps_imei'=>$gps_imei ?: null,
        ':notes'=>$notes ?: null,
      ]);
      $newId = (int)$pdo->lastInsertId();
      header('Location: '.$_SERVER['PHP_SELF'].'?highlight_id='.$newId); exit;

    } catch (PDOException $e) {
      $msg = ($e->getCode() === '23000') ? 'Duplicate Unique ID' : $e->getMessage();
      header('Location: '.$_SERVER['PHP_SELF'].'?err='.urlencode($msg)); exit;
    }
  }

  if ($op === 'update') {
    assert_csrf();
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
      $name            = trim($_POST['name'] ?? '');
      $unique_id       = trim($_POST['unique_id'] ?? '');
      $asset_type      = $_POST['asset_type'] ?? '';
      $manufacturer    = trim($_POST['manufacturer'] ?? '');
      $model           = trim($_POST['model'] ?? '');
      $purchase_date   = $_POST['purchase_date'] ?? null;
      $purchase_cost   = $_POST['purchase_cost'] !== '' ? (float)$_POST['purchase_cost'] : null;
      $department      = trim($_POST['department'] ?? '');
      $driver          = trim($_POST['driver'] ?? '');
      $route           = trim($_POST['route'] ?? '');
      $depot           = trim($_POST['depot'] ?? '');
      $status          = $_POST['status'] ?? 'Registered';
      $deployment_date = $_POST['deployment_date'] ?? null;
      $retired_on      = $_POST['retired_on'] ?? null;
      $gps_imei        = trim($_POST['gps_imei'] ?? '');
      $notes           = trim($_POST['notes'] ?? '');

      $stmt = $pdo->prepare(
        "UPDATE assets SET
          name=:name, unique_id=:unique_id, asset_type=:asset_type, manufacturer=:manufacturer, model=:model,
          purchase_date=:purchase_date, purchase_cost=:purchase_cost, department=:department, driver=:driver,
          route=:route, depot=:depot, status=:status, deployment_date=:deployment_date, installed_on=:installed_on,
          retired_on=:retired_on, disposed_on=:disposed_on, gps_imei=:gps_imei, notes=:notes
         WHERE id=:id"
      );
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
      header('Location: '.$_SERVER['PHP_SELF'].'?highlight_id='.$id); exit;
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
      if ($to === 'Retired')  { $fields['retired_on']      = $today; }
      if ($to === 'Disposed') { $fields['disposed_on']     = $today; }

      $set = []; $params = [':id' => $id];
      foreach ($fields as $col => $val) { $set[] = "$col = :$col"; $params[":$col"] = $val; }
      $sql = "UPDATE assets SET ".implode(', ', $set)." WHERE id = :id";
      $stmt = $pdo->prepare($sql);
      $stmt->execute($params);

      header('Location: '.$_SERVER['PHP_SELF'].'?highlight_id='.$id); exit;
    }
    header('Location: '.$_SERVER['PHP_SELF']); exit;
  }

  if ($op === 'delete') {
    assert_csrf();
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
      $pdo->prepare("DELETE FROM assets WHERE id=:id")->execute([':id'=>$id]);
    }
    header('Location: '.$_SERVER['PHP_SELF']); exit;
  }
}

// -------------------- EXPORT (GET) --------------------
if (($_GET['action'] ?? '') === 'export') {
  $params = []; $where = [];
  if ($fStatus !== '') { $where[] = 'status = :status';       $params[':status'] = $fStatus; }
  if ($fType   !== '') { $where[] = 'asset_type = :type';     $params[':type']   = $fType;   }
  if ($fDept   !== '') { $where[] = 'department = :dept';     $params[':dept']   = $fDept;   }
  if ($fQ      !== '') { $where[] = '(name LIKE :q OR unique_id LIKE :q OR driver LIKE :q OR route LIKE :q)'; $params[':q'] = '%'.$fQ.'%'; }
  $w = $where ? ('WHERE '.implode(' AND ', $where)) : '';

  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=assets_'.date('Ymd_His').'.csv');
  $out=fopen('php://output','w');
  fputcsv($out, ['id','name','unique_id','asset_type','manufacturer','model','purchase_date','purchase_cost','department','driver','route','depot','status','deployment_date','retired_on','gps_imei','notes','created_at']);
  $stmt = $pdo->prepare("SELECT id,name,unique_id,asset_type,manufacturer,model,purchase_date,purchase_cost,department,driver,route,depot,status,deployment_date,retired_on,gps_imei,notes,created_at FROM assets $w ORDER BY id DESC");
  $stmt->execute($params);
  while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) { fputcsv($out, $r); }
  fclose($out); exit;
}

// -------------------- LOAD LIST + STATS --------------------
$params = []; $where = [];
if ($fStatus !== '') { $where[] = 'status = :status';       $params[':status'] = $fStatus; }
if ($fType   !== '') { $where[] = 'asset_type = :type';     $params[':type']   = $fType;   }
if ($fDept   !== '') { $where[] = 'department = :dept';     $params[':dept']   = $fDept;   }
if ($fQ      !== '') { $where[] = '(name LIKE :q OR unique_id LIKE :q OR driver LIKE :q OR route LIKE :q)'; $params[':q'] = '%'.$fQ.'%'; }
$w = $where ? ('WHERE '.implode(' AND ',$where)) : '';

$sqlList = "SELECT * FROM assets $w ORDER BY id DESC LIMIT 1000";
$stmtList = $pdo->prepare($sqlList);
$stmtList->execute($params);
$assets = $stmtList->fetchAll(PDO::FETCH_ASSOC);

$stat = function($cond) use ($pdo){ $stmt=$pdo->query("SELECT COUNT(*) FROM assets WHERE $cond"); return (int)$stmt->fetchColumn(); };
$totalAssets = (int)$pdo->query("SELECT COUNT(*) FROM assets")->fetchColumn();
$registered  = $stat("status='Registered'");
$deployed    = $stat("status='Deployed'");
$active      = $stat("status='Active'");
$inMaint     = $stat("status='In Maintenance'");
$retired     = $stat("status='Retired' OR status='Disposed'");
$purchaseSum = (float)$pdo->query("SELECT COALESCE(SUM(purchase_cost),0) FROM assets")->fetchColumn();

$statuses    = ['Registered','Deployed','Active','In Maintenance','Retired','Disposed'];
$types       = ['Vehicle','GPS Tracker','RFID Tag','Spare Part','Device','Other'];
$departments = ['Operations','Maintenance','Logistics','Compliance','IT','Other'];
$highlightId = isset($_GET['highlight_id']) ? (int)$_GET['highlight_id'] : 0;

$userName = $_SESSION["user"]["name"] ?? "Nicole Malitao";
$userRole = $_SESSION["user"]["role"] ?? "Warehouse Manager";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Asset Tracking | TNVS</title>

  <!-- Bootstrap -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />

  <!-- Global & Modules CSS -->
  <link href="../css/style.css" rel="stylesheet" />
  <link href="../css/modules.css" rel="stylesheet" />

  <!-- Ionicons -->
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>

  <!-- Sidebar toggle -->
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
    .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.25rem; margin-bottom: 1.25rem; }
    .stat-card { background: #fff; border: 1px solid var(--slate-200); border-radius: 1rem; padding: 1.2rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05); transition: transform .2s; }
    .stat-card:hover { transform: translateY(-2px); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
    .stat-icon { width: 44px; height: 44px; border-radius: 12px; display:flex; align-items:center; justify-content:center; font-size: 1.3rem; margin-bottom: .8rem; }
    .card-table { border: 1px solid var(--slate-200); border-radius: 1rem; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.05); overflow: hidden; }
    .table-custom thead th {
      font-size: 0.75rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: var(--slate-600);
      background: var(--slate-50);
      border-bottom: 1px solid var(--slate-200);
      font-weight: 600;
      padding: 1rem 1.25rem;
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
    .asset-table th, .asset-table td { vertical-align: middle; }
    .asset-table th:last-child, .asset-table td:last-child { min-width: 280px; }
    .asset-actions { display: grid; gap: 0.45rem; }
    .asset-status-form { display: flex; gap: 0.4rem; align-items: center; }
    .asset-status-form .form-select { min-width: 140px; }
    .asset-manage { display: flex; gap: 0.4rem; flex-wrap: wrap; }
    .asset-meta { color: #667085; font-size: .86rem; }
    .f-mono { font-family: 'SF Mono', 'Segoe UI Mono', 'Roboto Mono', monospace; letter-spacing: -0.3px; }
    .filters-wrap .input-group-text { background:#fff; border-right:0; color:#94a3b8; }
    .filters-wrap .form-control.search-control { border-left:0; padding-left:0; }
  </style>
</head>
<body class="saas-page">
  <div class="container-fluid p-0">
    <div class="row g-0">

      <!-- Sidebar -->
      <?php include __DIR__ . '/../includes/sidebar.php'; ?>

      <!-- Force-activate the correct sidebar item even if sidebar.php doesn't read $active -->
      <script>
        (function() {
          const activeKey = <?= json_encode($active) ?>;
          const here = location.pathname.split('/').pop() || 'index.php';
          const sidebar = document.querySelector('.sidebar, #sidebar, nav.sidebar');
          if (!sidebar) return;

          // Clear existing .active marks
          sidebar.querySelectorAll('.active').forEach(el => el.classList.remove('active'));

          // 1) Try data-key match (if your sidebar uses it)
          let link = sidebar.querySelector(`[data-key="${activeKey}"]`);
          // 2) Fallback: match href to current file
          if (!link) {
            link = Array.from(sidebar.querySelectorAll('a.nav-link, a.list-group-item, a'))
              .find(a => (a.getAttribute('href') || '').split('/').pop() === here);
          }
          // 3) Fallback: match href containing the key
          if (!link) {
            link = Array.from(sidebar.querySelectorAll('a.nav-link, a.list-group-item, a'))
              .find(a => (a.getAttribute('href') || '').includes(activeKey));
          }

          if (link) {
            link.classList.add('active');
            const li = link.closest('li');
            if (li) li.classList.add('active');
            // open parent collapses if any
            const collapse = link.closest('.collapse');
            if (collapse && collapse.classList.contains('collapse')) {
              collapse.classList.add('show');
              const toggler = sidebar.querySelector(`[data-bs-target="#${collapse.id}"]`);
              if (toggler) toggler.setAttribute('aria-expanded', 'true');
            }
          }
        })();
      </script>

      <!-- Main Content -->
      <div class="col main-content p-3 p-lg-4">

        <!-- Topbar -->
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div class="d-flex align-items-center gap-3">
            <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2" aria-label="Toggle sidebar">
              <ion-icon name="menu-outline"></ion-icon>
            </button>
            <h2 class="m-0 d-flex align-items-center gap-2 page-title">
              <ion-icon name="cube-outline"></ion-icon>
              <span>Asset Tracking</span>
            </h2>
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
          <!-- Error banner -->
          <?php if (!empty($_GET['err'])): ?>
            <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm mb-3">
              <ion-icon name="alert-circle-outline" class="me-2"></ion-icon><?= h($_GET['err']) ?>
              <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
          <?php endif; ?>

          <!-- Action bar -->
          <section class="mb-3">
            <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
              <div class="d-flex gap-2">
                <a class="btn btn-violet d-flex align-items-center gap-2"
                   href="?action=export&status=<?= h($fStatus) ?>&type=<?= h($fType) ?>&dept=<?= h($fDept) ?>&q=<?= urlencode($fQ) ?>">
                  <ion-icon name="download-outline"></ion-icon> Export CSV
                </a>
                <?php if ($isAdmin): ?>
                  <button class="btn btn-white border shadow-sm d-flex align-items-center gap-2"
                          type="button" data-bs-toggle="modal" data-bs-target="#addAssetModal">
                    <ion-icon name="add-circle-outline"></ion-icon> Add Asset
                  </button>
                <?php endif; ?>
              </div>
            </div>
          </section>

        <!-- KPIs -->
        <section class="stats-row">
          <div class="stat-card">
            <div class="stat-icon bg-primary bg-opacity-10 text-primary"><ion-icon name="cube-outline"></ion-icon></div>
            <div class="text-label">Total Assets</div>
            <div class="fs-3 fw-bold text-dark mt-1"><?= (int)$totalAssets ?></div>
          </div>
          <div class="stat-card">
            <div class="stat-icon bg-secondary bg-opacity-10 text-secondary"><ion-icon name="bookmark-outline"></ion-icon></div>
            <div class="text-label">Registered</div>
            <div class="fs-3 fw-bold text-dark mt-1"><?= (int)$registered ?></div>
          </div>
          <div class="stat-card">
            <div class="stat-icon bg-info bg-opacity-10 text-info"><ion-icon name="send-outline"></ion-icon></div>
            <div class="text-label">Deployed</div>
            <div class="fs-3 fw-bold text-dark mt-1"><?= (int)$deployed ?></div>
          </div>
          <div class="stat-card">
            <div class="stat-icon bg-success bg-opacity-10 text-success"><ion-icon name="checkmark-circle-outline"></ion-icon></div>
            <div class="text-label">Active</div>
            <div class="fs-3 fw-bold text-dark mt-1"><?= (int)$active ?></div>
          </div>
          <div class="stat-card">
            <div class="stat-icon bg-warning bg-opacity-10 text-warning"><ion-icon name="construct-outline"></ion-icon></div>
            <div class="text-label">In Maintenance</div>
            <div class="fs-3 fw-bold text-dark mt-1"><?= (int)$inMaint ?></div>
          </div>
          <div class="stat-card">
            <div class="stat-icon bg-dark bg-opacity-10 text-dark"><ion-icon name="archive-outline"></ion-icon></div>
            <div class="text-label">Retired / Disposed</div>
            <div class="fs-3 fw-bold text-dark mt-1"><?= (int)$retired ?></div>
          </div>
          <div class="stat-card">
            <div class="stat-icon bg-primary bg-opacity-10 text-primary"><ion-icon name="cash-outline"></ion-icon></div>
            <div class="text-label">Total Purchase Value</div>
            <div class="fs-3 fw-bold text-dark mt-1">₱<?= money_fmt($purchaseSum) ?></div>
          </div>
        </section>

        <!-- Filters -->
        <section class="filters-wrap mb-3">
          <div class="d-flex flex-wrap gap-2 align-items-center">
            <div class="flex-grow-1" style="max-width: 320px;">
              <div class="input-group">
                <span class="input-group-text"><ion-icon name="search-outline"></ion-icon></span>
                <input id="f_q" class="form-control search-control" placeholder="Search name / ID / driver / route" value="<?= h($fQ) ?>">
              </div>
            </div>
            <select id="f_status" class="form-select" style="max-width: 200px;">
              <option value="">All statuses</option>
              <?php foreach ($statuses as $s): ?>
                <option value="<?= h($s) ?>" <?= $fStatus===$s?'selected':'' ?>><?= h($s) ?></option>
              <?php endforeach; ?>
            </select>
            <select id="f_type" class="form-select" style="max-width: 200px;">
              <option value="">All types</option>
              <?php foreach ($types as $t): ?>
                <option value="<?= h($t) ?>" <?= $fType===$t?'selected':'' ?>><?= h($t) ?></option>
              <?php endforeach; ?>
            </select>
            <select id="f_dept" class="form-select" style="max-width: 220px;">
              <option value="">All departments</option>
              <?php foreach ($departments as $d): ?>
                <option value="<?= h($d) ?>" <?= $fDept===$d?'selected':'' ?>><?= h($d) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="ms-auto d-flex align-items-center gap-3">
              <button type="button" id="btnFilter" class="btn btn-white border shadow-sm fw-medium px-3">Filter</button>
              <button type="button" id="btnReset" class="btn btn-link text-decoration-none text-muted p-0">Reset</button>
            </div>
          </div>
        </section>

        <!-- Table -->
        <section class="card-table">
            <div class="table-responsive">
              <table class="table table-custom mb-0 align-middle asset-table">
                <thead class="sticky-th">
                  <tr>
                    <th>ID</th>
                    <th>Unique ID / Name</th>
                    <th>Type / Model</th>
                    <th>Assignment</th>
                    <th>Status</th>
                    <th>Purchase</th>
                    <th>Deploy / Retire</th>
                    <th>Status / Actions</th>
                  </tr>
                </thead>
                <tbody>
                <?php if (!$assets): ?>
                  <tr>
                    <td colspan="8" class="text-center py-5 text-muted">No assets found.</td>
                  </tr>
                <?php else: foreach ($assets as $a): ?>
                  <tr class="<?= ($highlightId && $a['id']===$highlightId)?'highlight':'' ?>" id="asset-<?= (int)$a['id'] ?>">
                    <td class="f-mono fw-semibold"><?= (int)$a['id'] ?></td>
                    <td>
                      <div class="fw-semibold f-mono text-primary"><?= h($a['unique_id'] ?: '—') ?></div>
                      <div><?= h($a['name']) ?></div>
                    </td>
                    <td>
                      <div><?= h($a['asset_type'] ?: '—') ?></div>
                      <div class="text-muted small"><?= h(($a['manufacturer']? $a['manufacturer'].' ' : '').($a['model'] ?: '')) ?></div>
                    </td>
                    <td>
                      <div><?= h($a['driver'] ?: 'Unassigned') ?></div>
                      <div class="text-muted small"><?= h($a['route'] ?: '—') ?> · <?= h($a['depot'] ?: '—') ?></div>
                    </td>
                    <td>
                      <?php $cls = 's-'.strtolower(str_replace(' ','',$a['status'])); ?>
                      <span class="badge <?= h($cls) ?>"><?= h($a['status']) ?></span>
                    </td>
                    <td>
                      <div>₱<?= money_fmt($a['purchase_cost']) ?></div>
                      <div class="text-muted small"><?= h($a['purchase_date']) ?></div>
                    </td>
                    <td>
                      <div>Deploy: <?= h($a['deployment_date']) ?></div>
                      <div>Retire: <?= h($a['retired_on']) ?></div>
                    </td>
                    <td>
                      <?php $st = $a['status']; ?>
                      <div class="asset-actions">
                        <?php if ($isAdmin): ?>
                          <?php $nextOptions = next_status_options((string)$st); ?>
                          <form method="POST" action="<?= h($_SERVER['PHP_SELF']) ?>" class="asset-status-form"
                                onsubmit="const sel=this.querySelector('select[name=to]'); if(!sel || !sel.value){ return false; } if(sel.value==='Retired'){ return confirm('Retire this asset?'); } if(sel.value==='Disposed'){ return confirm('Mark this asset as disposed?'); } return true;">
                            <input type="hidden" name="op" value="transition">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                            <select class="form-select form-select-sm" name="to" <?= empty($nextOptions) ? 'disabled' : '' ?>>
                              <option value="">Change status...</option>
                              <?php foreach ($nextOptions as $opt): ?>
                                <option value="<?= h($opt) ?>"><?= h($opt) ?></option>
                              <?php endforeach; ?>
                            </select>
                            <button class="btn btn-sm btn-violet" type="submit" <?= empty($nextOptions) ? 'disabled' : '' ?>>
                              Apply
                            </button>
                          </form>
                          <div class="asset-manage">
                            <button class="btn btn-sm btn-outline-secondary js-edit-asset"
                                    type="button"
                                    data-id="<?= (int)$a['id'] ?>"
                                    data-name="<?= h($a['name']) ?>"
                                    data-unique_id="<?= h($a['unique_id']) ?>"
                                    data-asset_type="<?= h($a['asset_type']) ?>"
                                    data-manufacturer="<?= h($a['manufacturer']) ?>"
                                    data-model="<?= h($a['model']) ?>"
                                    data-purchase_date="<?= h($a['purchase_date']) ?>"
                                    data-purchase_cost="<?= h($a['purchase_cost']) ?>"
                                    data-department="<?= h($a['department']) ?>"
                                    data-driver="<?= h($a['driver']) ?>"
                                    data-route="<?= h($a['route']) ?>"
                                    data-depot="<?= h($a['depot']) ?>"
                                    data-status="<?= h($a['status']) ?>"
                                    data-deployment_date="<?= h($a['deployment_date']) ?>"
                                    data-retired_on="<?= h($a['retired_on']) ?>"
                                    data-gps_imei="<?= h($a['gps_imei']) ?>"
                                    data-notes="<?= h($a['notes']) ?>">
                              <ion-icon name="create-outline"></ion-icon> Edit
                            </button>
                            <a class="btn btn-sm btn-outline-secondary" href="repair.php">
                              <ion-icon name="construct-outline"></ion-icon> Repairs
                            </a>
                            <form method="POST" action="<?= h($_SERVER['PHP_SELF']) ?>" class="d-inline" onsubmit="return confirm('Delete this asset?')">
                              <input type="hidden" name="op" value="delete">
                              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                              <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                              <button class="btn btn-sm btn-danger"><ion-icon name="trash-outline"></ion-icon> Delete</button>
                            </form>
                          </div>
                        <?php else: ?>
                          <span class="text-muted small">No actions available</span>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
            <div class="d-flex justify-content-between align-items-center p-3 border-top bg-light bg-opacity-50">
              <div class="small text-muted">Showing <?= count($assets) ?> asset(s)</div>
            </div>
        </section>
        </div>

      </div><!-- /main -->
    </div>
  </div>

  <?php if ($isAdmin): ?>
  <div class="modal fade" id="addAssetModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
      <div class="modal-content">
        <form method="POST" action="<?= h($_SERVER['PHP_SELF']) ?>" class="modal-form">
          <input type="hidden" name="op" value="add">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <div class="modal-header">
            <h5 class="modal-title">Add Asset</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="row g-2">
              <div class="col-12 col-md-4"><input class="form-control" name="name" placeholder="Asset Name" required></div>
              <div class="col-12 col-md-4"><input class="form-control" name="unique_id" placeholder="Unique ID (QR/RFID/Serial)"></div>
              <div class="col-12 col-md-4">
                <select class="form-select" name="asset_type">
                  <option value="">Type</option>
                  <?php foreach ($types as $t): ?><option><?= h($t) ?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="col-6 col-md-3"><input class="form-control" name="manufacturer" placeholder="Manufacturer"></div>
              <div class="col-6 col-md-3"><input class="form-control" name="model" placeholder="Model"></div>
              <div class="col-6 col-md-3"><input class="form-control" type="date" name="purchase_date" title="Purchase date"></div>
              <div class="col-6 col-md-3"><input class="form-control" type="number" step="0.01" name="purchase_cost" placeholder="Cost"></div>
              <div class="col-6 col-md-3">
                <select class="form-select" name="department">
                  <option value="">Department</option>
                  <?php foreach ($departments as $d): ?><option><?= h($d) ?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="col-6 col-md-3"><input class="form-control" name="driver" placeholder="Assigned Driver"></div>
              <div class="col-6 col-md-3"><input class="form-control" name="route" placeholder="Route"></div>
              <div class="col-6 col-md-3"><input class="form-control" name="depot" placeholder="Depot"></div>
              <div class="col-6 col-md-3">
                <select class="form-select" name="status">
                  <?php foreach ($statuses as $s): ?><option <?= $s==='Registered'?'selected':'' ?>><?= h($s) ?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="col-6 col-md-3"><input class="form-control" type="date" name="deployment_date" title="Deployment date"></div>
              <div class="col-6 col-md-3"><input class="form-control" name="gps_imei" placeholder="GPS IMEI"></div>
              <div class="col-12"><input class="form-control" name="notes" placeholder="Notes"></div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button class="btn btn-violet" type="submit">Save Asset</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="editAssetModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
      <div class="modal-content">
        <form method="POST" action="<?= h($_SERVER['PHP_SELF']) ?>" id="editAssetForm">
          <input type="hidden" name="op" value="update">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="id" id="e_id">
          <div class="modal-header">
            <h5 class="modal-title">Edit Asset</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="row g-2">
              <div class="col-12 col-md-4"><input class="form-control" name="name" id="e_name" placeholder="Asset Name" required></div>
              <div class="col-12 col-md-4"><input class="form-control" name="unique_id" id="e_unique_id" placeholder="Unique ID"></div>
              <div class="col-12 col-md-4">
                <select class="form-select" name="asset_type" id="e_asset_type">
                  <option value="">Type</option>
                  <?php foreach ($types as $t): ?><option value="<?= h($t) ?>"><?= h($t) ?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="col-6 col-md-3"><input class="form-control" name="manufacturer" id="e_manufacturer" placeholder="Manufacturer"></div>
              <div class="col-6 col-md-3"><input class="form-control" name="model" id="e_model" placeholder="Model"></div>
              <div class="col-6 col-md-3"><input class="form-control" type="date" name="purchase_date" id="e_purchase_date" title="Purchase date"></div>
              <div class="col-6 col-md-3"><input class="form-control" type="number" step="0.01" name="purchase_cost" id="e_purchase_cost" placeholder="Cost"></div>
              <div class="col-6 col-md-3">
                <select class="form-select" name="department" id="e_department">
                  <option value="">Department</option>
                  <?php foreach ($departments as $d): ?><option value="<?= h($d) ?>"><?= h($d) ?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="col-6 col-md-3"><input class="form-control" name="driver" id="e_driver" placeholder="Assigned Driver"></div>
              <div class="col-6 col-md-3"><input class="form-control" name="route" id="e_route" placeholder="Route"></div>
              <div class="col-6 col-md-3"><input class="form-control" name="depot" id="e_depot" placeholder="Depot"></div>
              <div class="col-6 col-md-3">
                <select class="form-select" name="status" id="e_status">
                  <?php foreach ($statuses as $s): ?><option value="<?= h($s) ?>"><?= h($s) ?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="col-6 col-md-3"><input class="form-control" type="date" name="deployment_date" id="e_deployment_date" title="Deployment date"></div>
              <div class="col-6 col-md-3"><input class="form-control" type="date" name="retired_on" id="e_retired_on" title="Retired on"></div>
              <div class="col-6 col-md-3"><input class="form-control" name="gps_imei" id="e_gps_imei" placeholder="GPS IMEI"></div>
              <div class="col-12"><input class="form-control" name="notes" id="e_notes" placeholder="Notes"></div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button class="btn btn-violet" type="submit">Save Changes</button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
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
    const editModalEl = document.getElementById('editAssetModal');
    const editModal = editModalEl ? new bootstrap.Modal(editModalEl) : null;
    document.querySelectorAll('.js-edit-asset').forEach((btn) => {
      btn.addEventListener('click', () => {
        if (!editModal) return;
        const d = btn.dataset;
        const setVal = (id, v) => {
          const el = document.getElementById(id);
          if (el) el.value = v || '';
        };
        setVal('e_id', d.id);
        setVal('e_name', d.name);
        setVal('e_unique_id', d.unique_id);
        setVal('e_asset_type', d.asset_type);
        setVal('e_manufacturer', d.manufacturer);
        setVal('e_model', d.model);
        setVal('e_purchase_date', d.purchase_date);
        setVal('e_purchase_cost', d.purchase_cost);
        setVal('e_department', d.department);
        setVal('e_driver', d.driver);
        setVal('e_route', d.route);
        setVal('e_depot', d.depot);
        setVal('e_status', d.status);
        setVal('e_deployment_date', d.deployment_date);
        setVal('e_retired_on', d.retired_on);
        setVal('e_gps_imei', d.gps_imei);
        setVal('e_notes', d.notes);
        editModal.show();
      });
    });
    
    (function(){ var el=document.querySelector('tr.highlight'); if(el){ el.scrollIntoView({behavior:'smooth', block:'center'}); }})();
    document.getElementById('btnFilter')?.addEventListener('click', applyFilters);
    document.getElementById('btnReset')?.addEventListener('click', () => {
      const url = new URL(window.location.href);
      ['status','type','dept','q'].forEach((k)=>url.searchParams.delete(k));
      window.location.href = url.toString();
    });
  </script>

  <script src="../js/profile-dropdown.js"></script>
</body>
</html>
