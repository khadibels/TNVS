<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();



/* ---- DB guards & helpers ---- */
function table_exists(PDO $pdo, string $name): bool {
  $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
  $stmt->execute([$name]);
  return (bool)$stmt->fetchColumn();
}
function fetch_val(PDO $pdo, string $sql, array $params = [], $fallback = 0) {
  try { $st = $pdo->prepare($sql); $st->execute($params); $v = $st->fetchColumn(); return $v !== false ? $v : $fallback; }
  catch (Throwable $e) { return $fallback; }
}

$hasItems = table_exists($pdo, "inventory_items");
$hasLvl   = table_exists($pdo, "stock_levels");
$hasTx    = table_exists($pdo, "stock_transactions");
$hasLoc   = table_exists($pdo, "warehouse_locations");
$hasShip  = table_exists($pdo, "shipments");

/* ---- KPI cards ---- */
$totalSkus = $hasItems ? (int) fetch_val($pdo, "SELECT COUNT(*) FROM inventory_items WHERE archived = 0", [], 0) : 0;

$totalUnits = ($hasLvl)
  ? (int) fetch_val($pdo, "SELECT COALESCE(SUM(qty),0) FROM stock_levels", [], 0)
  : 0;

$locationsCount = $hasLoc ? (int) fetch_val($pdo, "SELECT COUNT(*) FROM warehouse_locations", [], 0) : 0;

$lowStockCount = 0;
if ($hasItems) {
  // Works whether or not stock_levels exists
  if ($hasLvl) {
    $sql = "SELECT COUNT(*) FROM (
              SELECT i.id, i.reorder_level, COALESCE(SUM(l.qty),0) total
              FROM inventory_items i
              LEFT JOIN stock_levels l ON l.item_id = i.id
              WHERE i.archived = 0
              GROUP BY i.id, i.reorder_level
              HAVING i.reorder_level > 0 AND COALESCE(SUM(l.qty),0) <= i.reorder_level
            ) x";
    $lowStockCount = (int) fetch_val($pdo, $sql, [], 0);
  } else {
    // Fallback if no stock_levels table yet
    $lowStockCount = 0;
  }
}

/* ---- Chart data: On-hand by Category ---- */
$catLabels = ["Raw","Packaging","Finished"];
$catData = [0,0,0];
if ($hasLvl && $hasItems) {
  $sql = "SELECT i.category, COALESCE(SUM(l.qty),0) qty
          FROM stock_levels l
          JOIN inventory_items i ON i.id = l.item_id
          WHERE i.archived = 0
          GROUP BY i.category";
  try {
    $st = $pdo->query($sql);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $tmp = [];
    foreach ($rows as $r) { $tmp[$r['category']] = (int)$r['qty']; }
    foreach ($catLabels as $idx => $label) { $catData[$idx] = $tmp[$label] ?? 0; }
  } catch (Throwable $e) { /* keep zeros */ }
}

/* ---- Chart data: 30-day Movements (Incoming vs Outgoing) ---- */
$trendLabels = [];
$incomingData = [];
$outgoingData = [];
// Prepare last 30 days buckets
$map = [];
$tz = new DateTimeZone('Asia/Manila');
$today = new DateTime('today', $tz);
for ($i=29; $i>=0; $i--) {
  $d = clone $today; $d->modify("-$i day");
  $key = $d->format('Y-m-d');
  $map[$key] = ['in'=>0,'out'=>0];
}
if ($hasTx) {
  $sql = "SELECT DATE(created_at) d,
                 SUM(CASE WHEN qty>0 THEN qty ELSE 0 END) incoming,
                 SUM(CASE WHEN qty<0 THEN -qty ELSE 0 END) outgoing
          FROM stock_transactions
          WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
          GROUP BY DATE(created_at)
          ORDER BY DATE(created_at)";
  try {
    $st = $pdo->query($sql);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $k = $r['d'];
      if (isset($map[$k])) {
        $map[$k]['in']  = (int)$r['incoming'];
        $map[$k]['out'] = (int)$r['outgoing'];
      }
    }
  } catch (Throwable $e) { /* keep zeros */ }
}
foreach ($map as $key => $io) {
  $d = DateTime::createFromFormat('Y-m-d', $key, $tz);
  $trendLabels[]  = $d ? $d->format('M j') : $key;
  $incomingData[] = $io['in'];
  $outgoingData[] = $io['out'];
}

/* ---- Chart data: On-hand by Location (top N + Others) ---- */
$locLabels = [];
$locData = [];
if ($hasLvl && $hasLoc) {
  $sql = "SELECT w.name label, COALESCE(SUM(l.qty),0) qty
          FROM stock_levels l
          JOIN warehouse_locations w ON w.id = l.location_id
          GROUP BY w.id
          HAVING COALESCE(SUM(l.qty),0) > 0
          ORDER BY qty DESC";
  try {
    $st = $pdo->query($sql);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $top = 6; $sumTop = 0; $sumOthers = 0;
    foreach ($rows as $i => $r) {
      if ($i < $top) { $locLabels[] = $r['label']; $locData[] = (int)$r['qty']; $sumTop += (int)$r['qty']; }
      else { $sumOthers += (int)$r['qty']; }
    }
    if ($sumOthers > 0) { $locLabels[] = "Others"; $locData[] = $sumOthers; }
  } catch (Throwable $e) { /* empty */ }
}

/* ---- Chart data: Shipment Status (if table exists) ---- */
$shipLabels = ["In Transit","Delivered","Delayed"];
$shipData   = [0,0,0];
if ($hasShip) {
  $statusMap = [];
  try {
    $st = $pdo->query("SELECT status, COUNT(*) c FROM shipments GROUP BY status");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $statusMap[$r['status']] = (int)$r['c'];
    }
    foreach ($shipLabels as $i=>$s) { $shipData[$i] = $statusMap[$s] ?? 0; }
  } catch (Throwable $e) { /* keep zeros */ }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>SW Dashboard | TNVS</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="../css/style.css" rel="stylesheet" />
  <link href="../css/modules.css" rel="stylesheet" />
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <script src="../js/sidebar-toggle.js"></script>
  <!-- Charts -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
  <style>
    .kpi-card .icon-wrap { width:42px; height:42px; display:flex; align-items:center; justify-content:center; border-radius:12px; }
    .chart-card canvas { width:100% !important; height:320px !important; }
  </style>
</head>
<body>
  <div class="container-fluid p-0">
    <div class="row g-0">

      <!-- Sidebar -->
      <div class="sidebar d-flex flex-column">
        <div class="d-flex justify-content-center align-items-center mb-4 mt-3">
          <img src="../img/logo.png" id="logo" class="img-fluid me-2" style="height:55px" alt="Logo">
        </div>
        <h6 class="text-uppercase mb-2">Smart Warehousing</h6>
        <nav class="nav flex-column px-2 mb-4">
          <a class="nav-link active" href="warehouseDashboard.php"><ion-icon name="home-outline"></ion-icon><span>Dashboard</span></a>
          <a class="nav-link" href="inventory/inventoryTracking.php"><ion-icon name="cube-outline"></ion-icon><span>Track Inventory</span></a>
          <a class="nav-link" href="stockmanagement/stockLevelManagement.php"><ion-icon name="layers-outline"></ion-icon><span>Stock Management</span></a>
          <a class="nav-link" href="TrackShipment/shipmentTracking.php"><ion-icon name="paper-plane-outline"></ion-icon><span>Track Shipments</span></a>
          <a class="nav-link" href="warehouseReports.php"><ion-icon name="file-tray-stacked-outline"></ion-icon><span>Reports</span></a>
          <a class="nav-link" href="warehouseSettings.php"><ion-icon name="settings-outline"></ion-icon><span>Settings</span></a>
        </nav>
        <div class="logout-section">
          <a class="nav-link text-danger" href="<?= BASE_URL ?>/auth/logout.php">
            <ion-icon name="log-out-outline"></ion-icon> Logout
          </a>
        </div>
      </div>

      <!-- Main Content -->
      <div class="col main-content p-3 p-lg-4">
        <!-- Topbar -->
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div class="d-flex align-items-center gap-3">
            <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2" aria-label="Toggle sidebar">
              <ion-icon name="menu-outline"></ion-icon>
            </button>
            <h2 class="m-0">Dashboard</h2>
          </div>
          <div class="d-flex align-items-center gap-2">
            <img src="../img/profile.jpg" class="rounded-circle" width="36" height="36" alt="">
            <div class="small">
              <strong>Nicole Malitao</strong><br/>
              <span class="text-muted">Warehouse Manager</span>
            </div>
          </div>
        </div>

        <!-- KPI Row -->
        <div class="row g-3 mb-3">
          <div class="col-6 col-md-3">
            <div class="card shadow-sm kpi-card h-100">
              <div class="card-body d-flex align-items-center gap-3">
                <div class="icon-wrap bg-primary-subtle">
                  <ion-icon name="cube-outline" style="font-size:20px"></ion-icon>
                </div>
                <div>
                  <div class="text-muted small">Total SKUs</div>
                  <div class="h4 m-0"><?= number_format($totalSkus) ?></div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="card shadow-sm kpi-card h-100">
              <div class="card-body d-flex align-items-center gap-3">
                <div class="icon-wrap bg-success-subtle">
                  <ion-icon name="layers-outline" style="font-size:20px"></ion-icon>
                </div>
                <div>
                  <div class="text-muted small">On-hand Units</div>
                  <div class="h4 m-0"><?= number_format($totalUnits) ?></div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="card shadow-sm kpi-card h-100">
              <div class="card-body d-flex align-items-center gap-3">
                <div class="icon-wrap bg-warning-subtle">
                  <ion-icon name="alert-circle-outline" style="font-size:20px"></ion-icon>
                </div>
                <div>
                  <div class="text-muted small">Low Stock</div>
                  <div class="h4 m-0"><?= number_format($lowStockCount) ?></div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="card shadow-sm kpi-card h-100">
              <div class="card-body d-flex align-items-center gap-3">
                <div class="icon-wrap bg-info-subtle">
                  <ion-icon name="business-outline" style="font-size:20px"></ion-icon>
                </div>
                <div>
                  <div class="text-muted small">Locations</div>
                  <div class="h4 m-0"><?= number_format($locationsCount) ?></div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Charts Row 1 -->
        <div class="row g-3 mb-3">
          <div class="col-12 col-lg-6">
            <div class="card shadow-sm chart-card h-100">
              <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <h5 class="card-title m-0">On-hand by Category</h5>
                  <ion-icon name="stats-chart-outline"></ion-icon>
                </div>
                <canvas id="catChart"></canvas>
              </div>
            </div>
          </div>
          <div class="col-12 col-lg-6">
            <div class="card shadow-sm chart-card h-100">
              <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <h5 class="card-title m-0">30-day Movements</h5>
                  <ion-icon name="trending-up-outline"></ion-icon>
                </div>
                <canvas id="trendChart"></canvas>
              </div>
            </div>
          </div>
        </div>

        <!-- Charts Row 2 -->
        <div class="row g-3">
          <div class="col-12 col-lg-6">
            <div class="card shadow-sm chart-card h-100">
              <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <h5 class="card-title m-0">On-hand by Location</h5>
                  <ion-icon name="navigate-outline"></ion-icon>
                </div>
                <canvas id="locChart"></canvas>
              </div>
            </div>
          </div>
          <div class="col-12 col-lg-6">
            <div class="card shadow-sm chart-card h-100">
              <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <h5 class="card-title m-0">Shipment Status</h5>
                  <ion-icon name="paper-plane-outline"></ion-icon>
                </div>
                <canvas id="shipChart"></canvas>
                <?php if (!$hasShip): ?>
                  <div class="text-muted small mt-2">Tip: create a <code>shipments</code> table with a <code>status</code> column (e.g., In Transit / Delivered / Delayed) to populate this.</div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>

      </div><!-- /main-content -->
    </div><!-- /row -->
  </div><!-- /container -->

<script>
  // Pass PHP data to JS
  const catLabels   = <?= json_encode($catLabels) ?>;
  const catData     = <?= json_encode($catData) ?>;

  const trendLabels = <?= json_encode($trendLabels) ?>;
  const incoming    = <?= json_encode($incomingData) ?>;
  const outgoing    = <?= json_encode($outgoingData) ?>;

  const locLabels   = <?= json_encode($locLabels) ?>;
  const locData     = <?= json_encode($locData) ?>;

  const shipLabels  = <?= json_encode($shipLabels) ?>;
  const shipData    = <?= json_encode($shipData) ?>;

  // Global chart defaults to better match your UI
  Chart.defaults.font.family = getComputedStyle(document.body).fontFamily || 'system-ui';
  Chart.defaults.color = getComputedStyle(document.body).color || '#222';

  // On-hand by Category (bar)
  new Chart(document.getElementById('catChart'), {
    type: 'bar',
    data: { labels: catLabels, datasets: [{ label: 'Units', data: catData, borderWidth: 1 }] },
    options: {
      maintainAspectRatio: false,
      scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
      plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false } }
    }
  });

  // 30-day Movements (line, 2 series)
  new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
      labels: trendLabels,
      datasets: [
        { label: 'Incoming', data: incoming, tension: .3, fill: false, borderWidth: 2, pointRadius: 0 },
        { label: 'Outgoing', data: outgoing, tension: .3, fill: false, borderWidth: 2, pointRadius: 0 }
      ]
    },
    options: {
      maintainAspectRatio: false,
      scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
      plugins: { tooltip: { mode: 'index', intersect: false } }
    }
  });

  // On-hand by Location (doughnut)
  new Chart(document.getElementById('locChart'), {
    type: 'doughnut',
    data: { labels: locLabels, datasets: [{ data: locData, borderWidth: 1 }] },
    options: {
      maintainAspectRatio: false,
      plugins: { legend: { position: 'bottom' } }
    }
  });

  // Shipment Status (doughnut)
  new Chart(document.getElementById('shipChart'), {
    type: 'doughnut',
    data: { labels: shipLabels, datasets: [{ data: shipData, borderWidth: 1 }] },
    options: {
      maintainAspectRatio: false,
      plugins: { legend: { position: 'bottom' } }
    }
  });
</script>
</body>
</html>
