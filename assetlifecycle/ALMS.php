<?php
require_once __DIR__ . "/../includes/config.php";
require_once __DIR__ . "/../includes/auth.php";
require_once __DIR__ . "/../includes/db.php";
require_login();
require_role(['admin','asset_manager']);

$section = 'alms';
$active = 'dashboard';

$pdo = db('alms');

/* ---- Guards ---- */
function table_exists(PDO $pdo, string $name): bool {
    try {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM information_schema.TABLES 
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
        );
        $stmt->execute([$name]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) { return false; }
}
function fetch_val(PDO $pdo, string $sql, array $params = [], $fallback=0) {
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v!==false ? $v : $fallback;
    } catch (Throwable $e) { return $fallback; }
}

$hasAssets = table_exists($pdo,'assets');
$hasReqs   = table_exists($pdo,'maintenance_requests');
$hasReps   = table_exists($pdo,'repairs');

/* ---- KPIs ---- */
$totalAssets   = $hasAssets ? (int) fetch_val($pdo,"SELECT COUNT(*) FROM assets") : 0;
$activeAssets  = $hasAssets ? (int) fetch_val($pdo,"SELECT COUNT(*) FROM assets WHERE status='Active'") : 0;
$maintAssets   = $hasAssets ? (int) fetch_val($pdo,"SELECT COUNT(*) FROM assets WHERE status='In Maintenance'") : 0;
$retiredAssets = $hasAssets ? (int) fetch_val($pdo,"SELECT COUNT(*) FROM assets WHERE status IN ('Retired','Disposed')") : 0;

$totalReqs  = $hasReqs ? (int) fetch_val($pdo,"SELECT COUNT(*) FROM maintenance_requests") : 0;
$pendingReq = $hasReqs ? (int) fetch_val($pdo,"SELECT COUNT(*) FROM maintenance_requests WHERE status='Pending'") : 0;
$inProgReq  = $hasReqs ? (int) fetch_val($pdo,"SELECT COUNT(*) FROM maintenance_requests WHERE status='In Progress'") : 0;
$doneReq    = $hasReqs ? (int) fetch_val($pdo,"SELECT COUNT(*) FROM maintenance_requests WHERE status='Completed'") : 0;

$totalRepairs    = $hasReps ? (int) fetch_val($pdo,"SELECT COUNT(*) FROM repairs") : 0;
$openRepairs     = $hasReps ? (int) fetch_val($pdo,"SELECT COUNT(*) FROM repairs WHERE status <> 'Completed'") : 0;
$totalRepairCost = $hasReps ? (float) fetch_val($pdo,"SELECT COALESCE(SUM(cost),0) FROM repairs") : 0;

/* ---- Chart: Assets by Status ---- */
$assetStatusLabels = ["Active","In Maintenance","Retired/Disposed"];
$assetStatusData   = [$activeAssets,$maintAssets,$retiredAssets];

/* ---- Chart: Requests by Status ---- */
$reqLabels = ["Pending","In Progress","Completed"];
$reqData   = [$pendingReq,$inProgReq,$doneReq];

/* ---- Chart: Monthly Repair Costs ---- */
$repMonths=[]; $repCosts=[];
if($hasReps){
    $sql="SELECT DATE_FORMAT(created_at,'%Y-%m') ym, COALESCE(SUM(cost),0) c
          FROM repairs GROUP BY ym ORDER BY ym DESC LIMIT 6";
    try{
        $rows=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $rows=array_reverse($rows);
        foreach($rows as $r){
            $repMonths[]=$r['ym'];
            $repCosts[]=(float)$r['c'];
        }
    }catch(Throwable $e){}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>ALMS Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="../css/style.css" rel="stylesheet"/>
  <link href="../css/modules.css" rel="stylesheet"/>
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <script src="../js/sidebar-toggle.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
  <style>
    .kpi-card .icon-wrap{width:42px;height:42px;display:flex;align-items:center;justify-content:center;border-radius:12px}
    .chart-card canvas{width:100%!important;height:320px!important}
  </style>
</head>
<body>
<div class="container-fluid p-0">
  <div class="row g-0">

    <?php include __DIR__ . '/../includes/sidebar.php' ?>

    <!-- Main -->
    <div class="col main-content p-3 p-lg-4">
      <h2 class="mb-3">Asset Lifecycle Dashboard</h2>

      <!-- KPI Rows -->
      <div class="row g-3 mb-3">
        <div class="col-6 col-md-3"><div class="card shadow-sm kpi-card h-100"><div class="card-body d-flex align-items-center gap-3"><div class="icon-wrap bg-primary-subtle"><ion-icon name="cube-outline"></ion-icon></div><div><div class="text-muted small">Total Assets</div><div class="h4 m-0"><?= $totalAssets ?></div></div></div></div></div>
        <div class="col-6 col-md-3"><div class="card shadow-sm kpi-card h-100"><div class="card-body d-flex align-items-center gap-3"><div class="icon-wrap bg-success-subtle"><ion-icon name="checkmark-done-outline"></ion-icon></div><div><div class="text-muted small">Active</div><div class="h4 m-0"><?= $activeAssets ?></div></div></div></div></div>
        <div class="col-6 col-md-3"><div class="card shadow-sm kpi-card h-100"><div class="card-body d-flex align-items-center gap-3"><div class="icon-wrap bg-warning-subtle"><ion-icon name="build-outline"></ion-icon></div><div><div class="text-muted small">Maintenance</div><div class="h4 m-0"><?= $maintAssets ?></div></div></div></div></div>
        <div class="col-6 col-md-3"><div class="card shadow-sm kpi-card h-100"><div class="card-body d-flex align-items-center gap-3"><div class="icon-wrap bg-secondary-subtle"><ion-icon name="archive-outline"></ion-icon></div><div><div class="text-muted small">Retired</div><div class="h4 m-0"><?= $retiredAssets ?></div></div></div></div></div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-6 col-md-3"><div class="card shadow-sm kpi-card h-100"><div class="card-body d-flex align-items-center gap-3"><div class="icon-wrap bg-info-subtle"><ion-icon name="layers-outline"></ion-icon></div><div><div class="text-muted small">Total Requests</div><div class="h4 m-0"><?= $totalReqs ?></div></div></div></div></div>
        <div class="col-6 col-md-3"><div class="card shadow-sm kpi-card h-100"><div class="card-body d-flex align-items-center gap-3"><div class="icon-wrap bg-danger-subtle"><ion-icon name="time-outline"></ion-icon></div><div><div class="text-muted small">Pending</div><div class="h4 m-0"><?= $pendingReq ?></div></div></div></div></div>
        <div class="col-6 col-md-3"><div class="card shadow-sm kpi-card h-100"><div class="card-body d-flex align-items-center gap-3"><div class="icon-wrap bg-warning-subtle"><ion-icon name="sync-outline"></ion-icon></div><div><div class="text-muted small">In Progress</div><div class="h4 m-0"><?= $inProgReq ?></div></div></div></div></div>
        <div class="col-6 col-md-3"><div class="card shadow-sm kpi-card h-100"><div class="card-body d-flex align-items-center gap-3"><div class="icon-wrap bg-success-subtle"><ion-icon name="checkmark-done-outline"></ion-icon></div><div><div class="text-muted small">Completed</div><div class="h4 m-0"><?= $doneReq ?></div></div></div></div></div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-6 col-md-4"><div class="card shadow-sm kpi-card h-100"><div class="card-body d-flex align-items-center gap-3"><div class="icon-wrap bg-primary-subtle"><ion-icon name="hammer-outline"></ion-icon></div><div><div class="text-muted small">Repairs</div><div class="h4 m-0"><?= $totalRepairs ?></div></div></div></div></div>
        <div class="col-6 col-md-4"><div class="card shadow-sm kpi-card h-100"><div class="card-body d-flex align-items-center gap-3"><div class="icon-wrap bg-warning-subtle"><ion-icon name="alert-circle-outline"></ion-icon></div><div><div class="text-muted small">Open Repairs</div><div class="h4 m-0"><?= $openRepairs ?></div></div></div></div></div>
        <div class="col-6 col-md-4"><div class="card shadow-sm kpi-card h-100"><div class="card-body d-flex align-items-center gap-3"><div class="icon-wrap bg-success-subtle"><ion-icon name="cash-outline"></ion-icon></div><div><div class="text-muted small">Repair Cost</div><div class="h4 m-0">₱<?= number_format($totalRepairCost,2) ?></div></div></div></div></div>
      </div>

      <!-- Charts -->
      <div class="row g-3 mb-3">
        <div class="col-lg-6"><div class="card shadow-sm chart-card h-100"><div class="card-body"><h5>Assets by Status</h5><canvas id="assetChart"></canvas></div></div></div>
        <div class="col-lg-6"><div class="card shadow-sm chart-card h-100"><div class="card-body"><h5>Requests by Status</h5><canvas id="reqChart"></canvas></div></div></div>
      </div>
      <div class="row g-3 mb-3">
        <div class="col-lg-12"><div class="card shadow-sm chart-card h-100"><div class="card-body"><h5>Repair Costs (Monthly)</h5><canvas id="repChart"></canvas></div></div></div>
      </div>

    </div><!-- /main -->
  </div>
</div>

<script>
const assetLabels = <?= json_encode($assetStatusLabels) ?>;
const assetData   = <?= json_encode($assetStatusData) ?>;
const reqLabels   = <?= json_encode($reqLabels) ?>;
const reqData     = <?= json_encode($reqData) ?>;
const repMonths   = <?= json_encode($repMonths) ?>;
const repCosts    = <?= json_encode($repCosts) ?>;

Chart.defaults.font.family = getComputedStyle(document.body).fontFamily || 'system-ui';
Chart.defaults.color = getComputedStyle(document.body).color || '#222';

new Chart(document.getElementById('assetChart'), {
  type:'doughnut',
  data:{labels:assetLabels,datasets:[{data:assetData}]},
  options:{plugins:{legend:{position:'bottom'}},maintainAspectRatio:false}
});
new Chart(document.getElementById('reqChart'), {
  type:'bar',
  data:{labels:reqLabels,datasets:[{label:'Requests',data:reqData}]},
  options:{scales:{y:{beginAtZero:true}},maintainAspectRatio:false,plugins:{legend:{display:false}}}
});
new Chart(document.getElementById('repChart'), {
  type:'line',
  data:{labels:repMonths,datasets:[{label:'Cost',data:repCosts,tension:.3,fill:false}]},
  options:{scales:{y:{beginAtZero:true}},maintainAspectRatio:false}
});
</script>
</body>
</html>
