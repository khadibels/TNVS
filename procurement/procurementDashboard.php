<?php
// If your procurement system also uses the same includes, this will work.
// If not, you can remove these two requires.
$inc = __DIR__ . '/../includes';
if (file_exists($inc . '/config.php')) require_once $inc . '/config.php';
if (file_exists($inc . '/auth.php'))  require_once $inc . '/auth.php';

// Optional login guard (remove if you don't want auth here yet)
if (function_exists('require_login')) require_login();

/* ---------- Helpers (safe even without DB) ---------- */
function table_exists(PDO $pdo = null, string $name = ''): bool {
  if (!$pdo || !$name) return false;
  try { $s = $pdo->prepare("SHOW TABLES LIKE ?"); $s->execute([$name]); return (bool)$s->fetchColumn(); }
  catch (Throwable $e) { return false; }
}
function column_exists(PDO $pdo = null, string $table = '', string $col = ''): bool {
  if (!$pdo || !$table || !$col) return false;
  try { $s = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?"); $s->execute([$col]); return (bool)$s->fetchColumn(); }
  catch (Throwable $e) { return false; }
}
function fetch_val(PDO $pdo = null, string $sql = '', array $params = [], $fallback = 0) {
  if (!$pdo || !$sql) return $fallback;
  try { $st = $pdo->prepare($sql); $st->execute($params); $v = $st->fetchColumn(); return $v !== false ? $v : $fallback; }
  catch (Throwable $e) { return $fallback; }
}

/* ---------- Detect tables (ok if they don't exist yet) ---------- */
$hasDB  = isset($pdo) && $pdo instanceof PDO;
$hasSup = $hasDB && table_exists($pdo, 'suppliers');
$hasPO  = $hasDB && table_exists($pdo, 'purchase_orders');
$hasPOI = $hasDB && table_exists($pdo, 'purchase_order_items');
$hasRFQ = $hasDB && table_exists($pdo, 'rfqs');
$hasPR  = $hasDB && table_exists($pdo, 'procurement_requests');

/* ---------- KPIs ---------- */
$activeSuppliers = $hasSup
  ? (int) fetch_val($pdo, "SELECT COUNT(*) FROM suppliers WHERE IFNULL(is_active,1)=1", [], 0)
  : 0;

$openRFQs = $hasRFQ
  ? (int) fetch_val($pdo, "SELECT COUNT(*) FROM rfqs WHERE status IN ('open','sent','pending')", [], 0)
  : 0;

$openPOs = $hasPO
  ? (int) fetch_val($pdo, "SELECT COUNT(*) FROM purchase_orders WHERE status IN ('draft','ordered','partially_received')", [], 0)
  : 0;

$pendingPRs = $hasPR
  ? (int) fetch_val($pdo, "SELECT COUNT(*) FROM procurement_requests WHERE status IN ('pending','for_approval','approved')", [], 0)
  : 0;

/* Spend this month — try PO items first, fall back to PO.total_amount if present */
$spendThisMonth = 0.0;
if ($hasPO && $hasPOI) {
  $spendThisMonth = (float) fetch_val(
    $pdo,
    "SELECT COALESCE(SUM(poi.qty_ordered * poi.unit_cost),0)
     FROM purchase_orders p
     JOIN purchase_order_items poi ON poi.po_id = p.id
     WHERE DATE_FORMAT(p.order_date,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')",
    [],
    0.0
  );
} elseif ($hasPO && column_exists($pdo, 'purchase_orders', 'total_amount')) {
  $spendThisMonth = (float) fetch_val(
    $pdo,
    "SELECT COALESCE(SUM(total_amount),0)
     FROM purchase_orders
     WHERE DATE_FORMAT(order_date,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')",
    [],
    0.0
  );
}

/* ---------- Charts ---------- */
/* PO Status breakdown */
$poStatusLabels = ['draft','ordered','partially_received','received','cancelled'];
$poStatusData   = [0,0,0,0,0];
if ($hasPO) {
  try {
    $st = $pdo->query("SELECT status, COUNT(*) c FROM purchase_orders GROUP BY status");
    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $map[$r['status']] = (int)$r['c'];
    foreach ($poStatusLabels as $i=>$s) $poStatusData[$i] = $map[$s] ?? 0;
  } catch (Throwable $e) { /* keep zeros */ }
}

/* Monthly spend (last 6 months) — PO items preferred */
$monLabels = []; $monAmounts = [];
if ($hasPO) {
  $tz = new DateTimeZone('Asia/Manila');
  $first = new DateTime('first day of this month', $tz);
  $buckets = [];
  for ($i=5; $i>=0; $i--) {
    $d = (clone $first)->modify("-$i months");
    $key = $d->format('Y-m');
    $monLabels[] = $d->format('M Y');
    $buckets[$key] = 0.0;
  }
  try {
    if ($hasPOI) {
      $sql = "SELECT DATE_FORMAT(p.order_date,'%Y-%m') ym,
                     SUM(poi.qty_ordered * poi.unit_cost) amt
              FROM purchase_orders p
              JOIN purchase_order_items poi ON poi.po_id=p.id
              WHERE p.order_date >= DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL 5 MONTH)
              GROUP BY DATE_FORMAT(p.order_date,'%Y-%m')";
    } elseif (column_exists($pdo,'purchase_orders','total_amount')) {
      $sql = "SELECT DATE_FORMAT(order_date,'%Y-%m') ym,
                     SUM(total_amount) amt
              FROM purchase_orders
              WHERE order_date >= DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL 5 MONTH)
              GROUP BY DATE_FORMAT(order_date,'%Y-%m')";
    } else {
      $sql = null;
    }
    if ($sql) {
      $st = $pdo->query($sql);
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ym = $r['ym']; $amt = (float)$r['amt'];
        if (isset($buckets[$ym])) $buckets[$ym] = $amt;
      }
    }
  } catch (Throwable $e) { /* leave zeros */ }
  foreach ($buckets as $amt) $monAmounts[] = (float)$amt;
}

/* Top Suppliers (last 90 days) */
$topSupLabels = []; $topSupAmts = [];
if ($hasSup && $hasPO) {
  try {
    if ($hasPOI) {
      $sql = "SELECT s.name, SUM(poi.qty_ordered * poi.unit_cost) amt
              FROM suppliers s
              JOIN purchase_orders p ON p.supplier_id=s.id
              JOIN purchase_order_items poi ON poi.po_id=p.id
              WHERE p.order_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
              GROUP BY s.id
              ORDER BY amt DESC LIMIT 6";
    } elseif (column_exists($pdo,'purchase_orders','total_amount')) {
      $sql = "SELECT s.name, SUM(p.total_amount) amt
              FROM suppliers s
              JOIN purchase_orders p ON p.supplier_id=s.id
              WHERE p.order_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
              GROUP BY s.id
              ORDER BY amt DESC LIMIT 6";
    } else { $sql = null; }
    if ($sql) {
      $st = $pdo->query($sql);
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $topSupLabels[]=$r['name']; $topSupAmts[]=(float)$r['amt']; }
    }
  } catch (Throwable $e) { /* empty */ }
}

/* ---------- User display (optional) ---------- */
$userName = 'Procurement User'; $userRole = 'Procurement';
if (function_exists('current_user')) {
  $u = current_user();
  $userName = $u['name'] ?? $userName;
  $userRole = $u['role'] ?? $userRole;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Procurement Dashboard | TNVS</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="../css/style.css" rel="stylesheet" />
  <link href="../css/modules.css" rel="stylesheet" />
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <script src="../js/sidebar-toggle.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
  <style>
    .kpi-card .icon-wrap { width:42px; height:42px; display:flex; align-items:center; justify-content:center; border-radius:12px; }
    .chart-card canvas { width:100% !important; height:320px !important; }
  </style>
</head>
<body>
  <div class="container-fluid p-0">
    <div class="row g-0">

      <!-- Sidebar (Procurement only, unchanged items) -->
      <div class="sidebar d-flex flex-column">
        <div class="d-flex justify-content-center align-items-center mb-4 mt-3">
          <img src="../img/logo.png" id="logo" class="img-fluid me-2" style="height:55px" alt="Logo">
        </div>

        <h6 class="text-uppercase mb-2">Procurement</h6>
        <nav class="nav flex-column px-2 mb-4">
          <a class="nav-link active" href="./procurementDashboard.php"><ion-icon name="home-outline"></ion-icon><span>Dashboard</span></a>
          <a class="nav-link" href="./supplierManagement.php"><ion-icon name="person-outline"></ion-icon><span>Supplier Management</span></a>
          <a class="nav-link" href="./rfqManagement.php"><ion-icon name="mail-open-outline"></ion-icon><span>RFQs & Sourcing</span></a>
          <a class="nav-link" href="./purchaseOrders.php"><ion-icon name="document-text-outline"></ion-icon><span>Purchase Orders</span></a>
          <a class="nav-link" href="./procurementRequests.php"><ion-icon name="clipboard-outline"></ion-icon><span>Procurement Requests</span></a>
          <a class="nav-link" href="./inventory.php"><ion-icon name="archive-outline"></ion-icon><span>Inventory Management</span></a>
          <a class="nav-link" href="./budgetReports.php"><ion-icon name="analytics-outline"></ion-icon><span>Budget & Reports</span></a>
          <a class="nav-link" href="./settings.php"><ion-icon name="settings-outline"></ion-icon><span>Settings</span></a>
        </nav>

        <div class="logout-section">
          <a class="nav-link text-danger" href="<?= defined('BASE_URL') ? BASE_URL : '#' ?>/auth/logout.php">
            <ion-icon name="log-out-outline"></ion-icon> Logout
          </a>
        </div>
      </div>

      <!-- Main -->
      <div class="col main-content p-3 p-lg-4">
        <!-- Topbar -->
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div class="d-flex align-items-center gap-3">
            <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2" aria-label="Toggle sidebar">
              <ion-icon name="menu-outline"></ion-icon>
            </button>
            <h2 class="m-0">Procurement Dashboard</h2>
          </div>
          <div class="d-flex align-items-center gap-2">
            <img src="../img/profile.jpg" class="rounded-circle" width="36" height="36" alt="">
            <div class="small">
              <strong><?= htmlspecialchars($userName) ?></strong><br/>
              <span class="text-muted"><?= htmlspecialchars($userRole) ?></span>
            </div>
          </div>
        </div>

        <!-- KPI Row -->
        <div class="row g-3 mb-3">
          <div class="col-6 col-md-3">
            <div class="card shadow-sm kpi-card h-100">
              <div class="card-body d-flex align-items-center gap-3">
                <div class="icon-wrap bg-primary-subtle"><ion-icon name="people-outline" style="font-size:20px"></ion-icon></div>
                <div>
                  <div class="text-muted small">Active Suppliers</div>
                  <div class="h4 m-0"><?= number_format($activeSuppliers) ?></div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="card shadow-sm kpi-card h-100">
              <div class="card-body d-flex align-items-center gap-3">
                <div class="icon-wrap bg-info-subtle"><ion-icon name="mail-open-outline" style="font-size:20px"></ion-icon></div>
                <div>
                  <div class="text-muted small">Open RFQs</div>
                  <div class="h4 m-0"><?= number_format($openRFQs) ?></div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="card shadow-sm kpi-card h-100">
              <div class="card-body d-flex align-items-center gap-3">
                <div class="icon-wrap bg-warning-subtle"><ion-icon name="document-text-outline" style="font-size:20px"></ion-icon></div>
                <div>
                  <div class="text-muted small">Open POs</div>
                  <div class="h4 m-0"><?= number_format($openPOs) ?></div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="card shadow-sm kpi-card h-100">
              <div class="card-body d-flex align-items-center gap-3">
                <div class="icon-wrap bg-success-subtle"><ion-icon name="cash-outline" style="font-size:20px"></ion-icon></div>
                <div>
                  <div class="text-muted small">Spend (This Month)</div>
                  <div class="h4 m-0">₱<?= number_format($spendThisMonth, 2) ?></div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Charts -->
        <div class="row g-3 mb-3">
          <div class="col-12 col-lg-6">
            <div class="card shadow-sm chart-card h-100">
              <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <h5 class="card-title m-0">PO Status</h5>
                  <ion-icon name="pie-chart-outline"></ion-icon>
                </div>
                <canvas id="poStatus"></canvas>
                <?php if (!$hasPO): ?>
                  <div class="text-muted small mt-2">Tip: create a <code>purchase_orders</code> table with a <code>status</code> column.</div>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <div class="col-12 col-lg-6">
            <div class="card shadow-sm chart-card h-100">
              <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <h5 class="card-title m-0">Monthly Spend</h5>
                  <ion-icon name="stats-chart-outline"></ion-icon>
                </div>
                <canvas id="poMonthly"></canvas>
                <?php if (!$hasPO): ?>
                  <div class="text-muted small mt-2">Tip: add POs to see month-to-month trends.</div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>

        <div class="row g-3">
          <div class="col-12">
            <div class="card shadow-sm chart-card h-100">
              <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <h5 class="card-title m-0">Top Suppliers (90 days)</h5>
                  <ion-icon name="ribbon-outline"></ion-icon>
                </div>
                <canvas id="topSup"></canvas>
                <?php if (!$hasSup || !$hasPO): ?>
                  <div class="text-muted small mt-2">Tip: add <code>suppliers</code> and POs to populate this.</div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>

      </div><!-- /main -->
    </div><!-- /row -->
  </div><!-- /container -->

<script>
  // Pass PHP data to JS
  const poStatusLabels = <?= json_encode($poStatusLabels) ?>;
  const poStatusData   = <?= json_encode($poStatusData) ?>;

  const monLabels  = <?= json_encode($monLabels) ?>;
  const monAmounts = <?= json_encode($monAmounts) ?>;

  const topSupLabels = <?= json_encode($topSupLabels) ?>;
  const topSupAmts   = <?= json_encode($topSupAmts) ?>;

  // Match your UI’s font/colors
  Chart.defaults.font.family = getComputedStyle(document.body).fontFamily || 'system-ui';
  Chart.defaults.color = getComputedStyle(document.body).color || '#222';

  new Chart(document.getElementById('poStatus'), {
    type: 'doughnut',
    data: { labels: poStatusLabels, datasets: [{ data: poStatusData, borderWidth:1 }] },
    options: { maintainAspectRatio:false, plugins:{ legend:{ position:'bottom' } } }
  });

  new Chart(document.getElementById('poMonthly'), {
    type: 'bar',
    data: { labels: monLabels, datasets: [{ label:'Spend', data: monAmounts, borderWidth:1 }] },
    options: { maintainAspectRatio:false, scales:{ y:{ beginAtZero:true, ticks:{ precision:0 } } }, plugins:{ legend:{ display:false } } }
  });

  new Chart(document.getElementById('topSup'), {
    type: 'bar',
    data: { labels: topSupLabels, datasets: [{ label:'Amount', data: topSupAmts, borderWidth:1 }] },
    options: { maintainAspectRatio:false, indexAxis:'y', scales:{ x:{ beginAtZero:true, ticks:{ precision:0 } } }, plugins:{ legend:{ display:false } } }
  });
</script>
</body>
</html>
