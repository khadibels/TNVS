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

// -------------------- HELPERS --------------------
function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function money_fmt($v){ return number_format((float)$v, 2); }

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
</head>
<body>
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
            <h2 class="m-0 d-flex align-items-center gap-2">
              <ion-icon name="cube-outline"></ion-icon>
              <span>Asset Tracking</span>
            </h2>
          </div>
          <div class="d-flex align-items-center gap-2">
            <img src="../img/profile.jpg" class="rounded-circle" width="36" height="36" alt="">
            <div class="small">
              <strong><?= h($userName) ?></strong><br/>
              <span class="text-muted"><?= h($userRole) ?></span>
            </div>
          </div>
        </div>

        <!-- Error banner -->
        <?php if (!empty($_GET['err'])): ?>
          <div class="alert alert-danger"><?= h($_GET['err']) ?></div>
        <?php endif; ?>

        <!-- Actions -->
        <section class="mb-3">
          <div class="d-flex gap-2">
            <a class="btn btn-violet"
               href="?action=export&status=<?= h($fStatus) ?>&type=<?= h($fType) ?>&dept=<?= h($fDept) ?>&q=<?= urlencode($fQ) ?>">
              <ion-icon name="download-outline"></ion-icon> Export CSV
            </a>
          </div>
        </section>

        <!-- KPIs -->
        <section class="card shadow-sm mb-3">
          <div class="card-body">
            <div class="row g-2 g-md-3">
              <div class="col-6 col-md-2"><div class="stat"><div class="label">Total Assets</div><div class="number"><?= (int)$totalAssets ?></div></div></div>
              <div class="col-6 col-md-2"><div class="stat"><div class="label">Registered</div><div class="number"><?= (int)$registered ?></div></div></div>
              <div class="col-6 col-md-2"><div class="stat"><div class="label">Deployed</div><div class="number"><?= (int)$deployed ?></div></div></div>
              <div class="col-6 col-md-2"><div class="stat"><div class="label">Active</div><div class="number"><?= (int)$active ?></div></div></div>
              <div class="col-6 col-md-2"><div class="stat"><div class="label">In Maintenance</div><div class="number"><?= (int)$inMaint ?></div></div></div>
              <div class="col-6 col-md-2"><div class="stat"><div class="label">Retired/Disposed</div><div class="number"><?= (int)$retired ?></div></div></div>
              <div class="col-12 col-md-3"><div class="stat"><div class="label">Total Purchase Value</div><div class="number">₱<?= money_fmt($purchaseSum) ?></div></div></div>
            </div>
          </div>
        </section>

        <!-- Filters -->
        <section class="card shadow-sm mb-3">
          <div class="card-body">
            <form class="row g-2 align-items-end" onsubmit="event.preventDefault();applyFilters();">
              <div class="col-12 col-md-3">
                <label class="form-label">Search</label>
                <input id="f_q" class="form-control" placeholder="name / ID / driver / route" value="<?= h($fQ) ?>">
              </div>
              <div class="col-6 col-md-3">
                <label class="form-label">Status</label>
                <select id="f_status" class="form-select">
                  <option value="">All statuses</option>
                  <?php foreach ($statuses as $s): ?>
                    <option value="<?= h($s) ?>" <?= $fStatus===$s?'selected':'' ?>><?= h($s) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-6 col-md-3">
                <label class="form-label">Type</label>
                <select id="f_type" class="form-select">
                  <option value="">All types</option>
                  <?php foreach ($types as $t): ?>
                    <option value="<?= h($t) ?>" <?= $fType===$t?'selected':'' ?>><?= h($t) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-12 col-md-2">
                <label class="form-label">Department</label>
                <select id="f_dept" class="form-select">
                  <option value="">All departments</option>
                  <?php foreach ($departments as $d): ?>
                    <option value="<?= h($d) ?>" <?= $fDept===$d?'selected':'' ?>><?= h($d) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-12 col-md-1 d-grid">
                <button type="submit" class="btn btn-outline-secondary">Filter</button>
              </div>
            </form>
          </div>
        </section>

        <!-- Add Asset -->
        <section class="card shadow-sm mb-3">
          <div class="card-body">
            <?php if ($isAdmin): ?>
              <form method="POST" action="<?= h($_SERVER['PHP_SELF']) ?>" class="row g-2">
                <input type="hidden" name="op" value="add">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

                <div class="col-12 col-md-3"><input class="form-control" name="name" placeholder="Asset Name" required></div>
                <div class="col-12 col-md-3"><input class="form-control" name="unique_id" placeholder="Unique ID (QR/RFID/Serial)"></div>
                <div class="col-6 col-md-2">
                  <select class="form-select" name="asset_type">
                    <option value="">Type</option>
                    <?php foreach ($types as $t): ?><option><?= h($t) ?></option><?php endforeach; ?>
                  </select>
                </div>
                <div class="col-6 col-md-2"><input class="form-control" name="manufacturer" placeholder="Manufacturer"></div>
                <div class="col-6 col-md-2"><input class="form-control" name="model" placeholder="Model"></div>

                <div class="col-6 col-md-2"><input class="form-control" type="date" name="purchase_date" title="Purchase date"></div>
                <div class="col-6 col-md-2"><input class="form-control" type="number" step="0.01" name="purchase_cost" placeholder="Cost"></div>
                <div class="col-6 col-md-2">
                  <select class="form-select" name="department">
                    <option value="">Department</option>
                    <?php foreach ($departments as $d): ?><option><?= h($d) ?></option><?php endforeach; ?>
                  </select>
                </div>
                <div class="col-6 col-md-2"><input class="form-control" name="driver" placeholder="Assigned Driver"></div>
                <div class="col-6 col-md-2"><input class="form-control" name="route" placeholder="Route"></div>
                <div class="col-6 col-md-2"><input class="form-control" name="depot" placeholder="Depot"></div>
                <div class="col-6 col-md-2">
                  <select class="form-select" name="status">
                    <?php foreach ($statuses as $s): ?><option <?= $s==='Registered'?'selected':'' ?>><?= h($s) ?></option><?php endforeach; ?>
                  </select>
                </div>
                <div class="col-6 col-md-2"><input class="form-control" type="date" name="deployment_date" title="Deployment date"></div>
                <div class="col-6 col-md-2"><input class="form-control" name="gps_imei" placeholder="GPS IMEI"></div>
                <div class="col-12 col-md-4"><input class="form-control" name="notes" placeholder="Notes"></div>

                <div class="col-12 col-md-2 d-grid">
                  <button class="btn btn-violet" type="submit">
                    <ion-icon name="add-circle-outline"></ion-icon> Add Asset
                  </button>
                </div>
              </form>
            <?php else: ?>
              <div class="text-muted">Read-only access. Contact an administrator to add assets.</div>
            <?php endif; ?>
          </div>
        </section>

        <!-- Table -->
        <section class="card shadow-sm">
          <div class="card-body">
            <h5 class="mb-3">Assets</h5>
            <div class="table-responsive">
              <table class="table align-middle">
                <thead class="sticky-th">
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
                  <tr class="<?= ($highlightId && $a['id']===$highlightId)?'highlight':'' ?>" id="asset-<?= (int)$a['id'] ?>">
                    <td><?= (int)$a['id'] ?></td>
                    <td>
                      <div class="fw-semibold"><?= h($a['unique_id'] ?: '—') ?></div>
                      <div><?= h($a['name']) ?></div>
                    </td>
                    <td>
                      <div><?= h($a['asset_type'] ?: '—') ?></div>
                      <div class="text-muted small"><?= h(($a['manufacturer']? $a['manufacturer'].' ' : '').($a['model'] ?: '')) ?></div>
                    </td>
                    <td>
                      <div><?= h($a['driver'] ?: 'Unassigned') ?></div>
                      <div class="text-muted small"><?= h($a['route'] ?: '—') ?> · <?= h($a['depot'] ?: '—') ?></div>
                      <div class="mt-1">
                        <a class="btn btn-sm btn-outline-secondary" href="repair.php?q=<?= urlencode($a['name']) ?>">
                          <ion-icon name="construct-outline"></ion-icon> Repairs
                        </a>
                      </div>
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
                      <div class="d-flex flex-wrap gap-1">
                        <?php if ($isAdmin): ?>
                          <?php if ($st==='Registered'): ?>
                            <form method="POST" action="<?= h($_SERVER['PHP_SELF']) ?>" class="d-inline">
                              <input type="hidden" name="op" value="transition">
                              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                              <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                              <input type="hidden" name="to" value="Deployed">
                              <button class="btn btn-sm btn-violet"><ion-icon name="rocket-outline"></ion-icon> Deploy</button>
                            </form>
                          <?php endif; ?>
                          <?php if ($st==='Deployed'): ?>
                            <form method="POST" action="<?= h($_SERVER['PHP_SELF']) ?>" class="d-inline">
                              <input type="hidden" name="op" value="transition">
                              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                              <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                              <input type="hidden" name="to" value="Active">
                              <button class="btn btn-sm btn-violet"><ion-icon name="play-circle-outline"></ion-icon> Activate</button>
                            </form>
                            <form method="POST" action="<?= h($_SERVER['PHP_SELF']) ?>" class="d-inline">
                              <input type="hidden" name="op" value="transition">
                              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                              <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                              <input type="hidden" name="to" value="In Maintenance">
                              <button class="btn btn-sm btn-outline-secondary"><ion-icon name="construct-outline"></ion-icon> Maintenance</button>
                            </form>
                            <form method="POST" action="<?= h($_SERVER['PHP_SELF']) ?>" class="d-inline" onsubmit="return confirm('Retire this asset?')">
                              <input type="hidden" name="op" value="transition">
                              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                              <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                              <input type="hidden" name="to" value="Retired">
                              <button class="btn btn-sm btn-outline-secondary"><ion-icon name="power-outline"></ion-icon> Retire</button>
                            </form>
                          <?php endif; ?>
                          <?php if ($st==='Active'): ?>
                            <form method="POST" action="<?= h($_SERVER['PHP_SELF']) ?>" class="d-inline">
                              <input type="hidden" name="op" value="transition">
                              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                              <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                              <input type="hidden" name="to" value="In Maintenance">
                              <button class="btn btn-sm btn-outline-secondary"><ion-icon name="construct-outline"></ion-icon> Maintenance</button>
                            </form>
                            <form method="POST" action="<?= h($_SERVER['PHP_SELF']) ?>" class="d-inline" onsubmit="return confirm('Retire this asset?')">
                              <input type="hidden" name="op" value="transition">
                              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                              <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                              <input type="hidden" name="to" value="Retired">
                              <button class="btn btn-sm btn-outline-secondary"><ion-icon name="power-outline"></ion-icon> Retire</button>
                            </form>
                          <?php endif; ?>
                          <?php if ($st==='In Maintenance'): ?>
                            <form method="POST" action="<?= h($_SERVER['PHP_SELF']) ?>" class="d-inline">
                              <input type="hidden" name="op" value="transition">
                              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                              <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                              <input type="hidden" name="to" value="Active">
                              <button class="btn btn-sm btn-violet"><ion-icon name="checkmark-circle-outline"></ion-icon> Back Active</button>
                            </form>
                            <form method="POST" action="<?= h($_SERVER['PHP_SELF']) ?>" class="d-inline" onsubmit="return confirm('Retire this asset?')">
                              <input type="hidden" name="op" value="transition">
                              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                              <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                              <input type="hidden" name="to" value="Retired">
                              <button class="btn btn-sm btn-outline-secondary"><ion-icon name="power-outline"></ion-icon> Retire</button>
                            </form>
                          <?php endif; ?>
                          <?php if ($st==='Retired'): ?>
                            <form method="POST" action="<?= h($_SERVER['PHP_SELF']) ?>" class="d-inline" onsubmit="return confirm('Mark this asset as disposed?')">
                              <input type="hidden" name="op" value="transition">
                              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                              <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                              <input type="hidden" name="to" value="Disposed">
                              <button class="btn btn-sm btn-danger"><ion-icon name="trash-outline"></ion-icon> Dispose</button>
                            </form>
                          <?php endif; ?>
                        <?php else: ?>
                          <span class="text-muted small">No actions available</span>
                        <?php endif; ?>
                      </div>

                      <?php if ($isAdmin): ?>
                      <div class="mt-2 d-flex flex-wrap gap-1">
                        <button class="btn btn-sm btn-outline-secondary" onclick="toggleEdit(<?= (int)$a['id'] ?>)">
                          <ion-icon name="create-outline"></ion-icon> Edit
                        </button>
                        <form method="POST" action="<?= h($_SERVER['PHP_SELF']) ?>" class="d-inline" onsubmit="return confirm('Delete this asset?')">
                          <input type="hidden" name="op" value="delete">
                          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                          <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                          <button class="btn btn-sm btn-danger"><ion-icon name="trash-outline"></ion-icon> Delete</button>
                        </form>
                      </div>
                      <?php endif; ?>
                    </td>
                  </tr>
                  <tr id="edit-<?= (int)$a['id'] ?>" style="display:none;background:#eef4ff">
                    <td colspan="8">
                      <form method="POST" action="<?= h($_SERVER['PHP_SELF']) ?>" class="row g-2 align-items-start">
                        <input type="hidden" name="op" value="update">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">

                        <div class="col-12 col-md-3"><input class="form-control" name="name" value="<?= h($a['name']) ?>" placeholder="Asset Name" required></div>
                        <div class="col-12 col-md-3"><input class="form-control" name="unique_id" value="<?= h($a['unique_id']) ?>" placeholder="Unique ID"></div>
                        <div class="col-6 col-md-2">
                          <select class="form-select" name="asset_type">
                            <?php foreach ($types as $t): ?><option <?= ($a['asset_type']===$t?'selected':'') ?>><?= h($t) ?></option><?php endforeach; ?>
                          </select>
                        </div>
                        <div class="col-6 col-md-2"><input class="form-control" name="manufacturer" value="<?= h($a['manufacturer']) ?>" placeholder="Manufacturer"></div>
                        <div class="col-6 col-md-2"><input class="form-control" name="model" value="<?= h($a['model']) ?>" placeholder="Model"></div>
                        <div class="col-6 col-md-2"><input class="form-control" type="date" name="purchase_date" value="<?= h($a['purchase_date']) ?>" title="Purchase date"></div>
                        <div class="col-6 col-md-2"><input class="form-control" type="number" step="0.01" name="purchase_cost" value="<?= h($a['purchase_cost']) ?>" placeholder="Cost"></div>
                        <div class="col-6 col-md-2">
                          <select class="form-select" name="department">
                            <?php foreach ($departments as $d): ?><option <?= ($a['department']===$d?'selected':'') ?>><?= h($d) ?></option><?php endforeach; ?>
                          </select>
                        </div>
                        <div class="col-6 col-md-2"><input class="form-control" name="driver" value="<?= h($a['driver']) ?>" placeholder="Assigned Driver"></div>
                        <div class="col-6 col-md-2"><input class="form-control" name="route" value="<?= h($a['route']) ?>" placeholder="Route"></div>
                        <div class="col-6 col-md-2"><input class="form-control" name="depot" value="<?= h($a['depot']) ?>" placeholder="Depot"></div>
                        <div class="col-6 col-md-2">
                          <select class="form-select" name="status">
                            <?php foreach ($statuses as $s): ?><option <?= ($a['status']===$s?'selected':'') ?>><?= h($s) ?></option><?php endforeach; ?>
                          </select>
                        </div>
                        <div class="col-6 col-md-2"><input class="form-control" type="date" name="deployment_date" value="<?= h($a['deployment_date']) ?>" title="Deployment date"></div>
                        <div class="col-6 col-md-2"><input class="form-control" type="date" name="retired_on" value="<?= h($a['retired_on']) ?>" title="Retired on"></div>
                        <div class="col-6 col-md-2"><input class="form-control" name="gps_imei" value="<?= h($a['gps_imei']) ?>" placeholder="GPS IMEI"></div>
                        <div class="col-12 col-md-4"><input class="form-control" name="notes" value="<?= h($a['notes']) ?>" placeholder="Notes"></div>

                        <div class="col-12 col-md-2 d-grid">
                          <button class="btn btn-violet" type="submit">
                            <ion-icon name="save-outline"></ion-icon> Save
                          </button>
                        </div>
                        <div class="col-12 col-md-2 d-grid">
                          <button class="btn btn-outline-secondary" type="button" onclick="toggleEdit(<?= (int)$a['id'] ?>)">
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
        </section>

      </div><!-- /main -->
    </div>
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
      if (el.style.display==='table-row') {
        el.scrollIntoView({behavior:'smooth', block:'center'});
      }
    }
    
    (function(){ var el=document.querySelector('tr.highlight'); if(el){ el.scrollIntoView({behavior:'smooth', block:'center'}); }})();
  </script>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
