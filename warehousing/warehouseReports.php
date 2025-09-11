<?php
require_once __DIR__ . "/../includes/config.php";
require_once __DIR__ . "/../includes/auth.php";
require_once __DIR__ . "/../includes/db.php";
require_login();
require_role(['admin', 'manager']);

$wms  = db('wms');
$pdo  = $wms;

/* ---- helpers ---- */
function table_exists(PDO $pdo, string $name): bool
{
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$name]);
    return (bool) $stmt->fetchColumn();
}
function qs(array $overrides = []): string
{
    $qs = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) {
            unset($qs[$k]);
        } else {
            $qs[$k] = $v;
        }
    }
    return "?" . http_build_query($qs);
}

/* ---- user ---- */
$userName = $_SESSION["user"]["name"] ?? "Nicole Malitao";
$userRole = $_SESSION["user"]["role"] ?? "Warehouse Manager";

/* ---- readiness ---- */
$hasItems = table_exists($pdo, "inventory_items");
$hasLevels = table_exists($pdo, "stock_levels");
$hasTx = table_exists($pdo, "stock_transactions");
$hasShip = table_exists($pdo, "shipments");
$hasLoc = table_exists($pdo, "warehouse_locations");

$invReady = $hasItems && $hasLevels;
$txReady = $hasTx && $hasItems;
$shipReady = $hasShip && $hasLoc;

/* ---- Inventory Snapshot (server-side) ---- */
$invTotals = [
    "items" => 0,
    "total_qty" => 0,
    "low_count" => 0,
    "oos_count" => 0,
];
$lowStock = [];
if ($invReady) {
    $stmt = $pdo->query("
    SELECT
      COUNT(*) AS items,
      COALESCE(SUM(COALESCE(t.total_qty,0)),0) AS total_qty,
      SUM(CASE WHEN COALESCE(t.total_qty,0) <= i.reorder_level THEN 1 ELSE 0 END) AS low_count,
      SUM(CASE WHEN COALESCE(t.total_qty,0) = 0 THEN 1 ELSE 0 END) AS oos_count
    FROM inventory_items i
    LEFT JOIN (
      SELECT item_id, SUM(qty) AS total_qty
      FROM stock_levels GROUP BY item_id
    ) t ON t.item_id = i.id
    WHERE i.archived = 0
  ");
    $invTotals = $stmt->fetch(PDO::FETCH_ASSOC) ?: $invTotals;

    $stmt2 = $pdo->query("
    SELECT i.sku, i.name, i.reorder_level, COALESCE(t.total_qty,0) AS total_qty
    FROM inventory_items i
    LEFT JOIN (SELECT item_id, SUM(qty) AS total_qty FROM stock_levels GROUP BY item_id) t
      ON t.item_id = i.id
    WHERE i.archived = 0
    ORDER BY total_qty ASC, i.name ASC
    LIMIT 10
  ");
    $lowStock = $stmt2->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* ---- Stock Activity (server-side) ---- */
$validDate = fn($s) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
$saFrom = $validDate($_GET["sa_from"] ?? "")
    ? $_GET["sa_from"]
    : date("Y-m-d", strtotime("-30 days"));
$saTo = $validDate($_GET["sa_to"] ?? "") ? $_GET["sa_to"] : date("Y-m-d");

$sa = ["total" => 0, "moved_qty" => 0, "in" => 0, "out" => 0, "transfer" => 0];
$topMoved = [];
if ($txReady) {
    $s = $pdo->prepare("
    SELECT action, COUNT(*) AS cnt, COALESCE(SUM(qty),0) AS sum_qty
    FROM stock_transactions
    WHERE created_at BETWEEN CONCAT(:f,' 00:00:00') AND CONCAT(:t,' 23:59:59')
    GROUP BY action
  ");
    $s->execute([":f" => $saFrom, ":t" => $saTo]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sa["total"] += (int) $r["cnt"];
        $sa["moved_qty"] += (int) $r["sum_qty"];
        $act = strtolower($r["action"]);
        if ($act === "in") {
            $sa["in"] = (int) $r["cnt"];
        } elseif ($act === "out") {
            $sa["out"] = (int) $r["cnt"];
        } elseif ($act === "transfer") {
            $sa["transfer"] = (int) $r["cnt"];
        }
    }

    $s2 = $pdo->prepare("
    SELECT ii.sku, ii.name, SUM(st.qty) AS qty
    FROM stock_transactions st
    JOIN inventory_items ii ON ii.id = st.item_id
    WHERE st.created_at BETWEEN CONCAT(:f,' 00:00:00') AND CONCAT(:t,' 23:59:59')
    GROUP BY st.item_id
    ORDER BY qty DESC
    LIMIT 10
  ");
    $s2->execute([":f" => $saFrom, ":t" => $saTo]);
    $topMoved = $s2->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Reports | TNVS</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="../css/style.css" rel="stylesheet" />
  <link href="../css/modules.css" rel="stylesheet" />

  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <script src="../js/sidebar-toggle.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>

  <style>
    .kpi-card .icon-wrap { width:42px; height:42px; display:flex; align-items:center; justify-content:center; border-radius:12px; }
    .chart-card canvas { width:100% !important; height:300px !important; }
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
    <a class="nav-link" href="./warehouseDashboard.php">
      <ion-icon name="home-outline"></ion-icon><span>Dashboard</span>
    </a>
    <a class="nav-link" href="./inventory/inventoryTracking.php">
      <ion-icon name="cube-outline"></ion-icon><span>Track Inventory</span>
    </a>
    <a class="nav-link" href="./stockmanagement/stockLevelManagement.php">
      <ion-icon name="layers-outline"></ion-icon><span>Stock Management</span>
    </a>
    <a class="nav-link" href="./TrackShipment/shipmentTracking.php">
      <ion-icon name="paper-plane-outline"></ion-icon><span>Track Shipments</span>
    </a>
    <a class="nav-link active" href="./warehouseReports.php">
      <ion-icon name="file-tray-stacked-outline"></ion-icon><span>Reports</span>
    </a>
    <a class="nav-link" href="./warehouseSettings.php">
      <ion-icon name="settings-outline"></ion-icon><span>Settings</span>
    </a>
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
      <h2 class="m-0">Reports</h2>
    </div>
    <div class="d-flex align-items-center gap-2">
      <img src="../img/profile.jpg" class="rounded-circle" width="36" height="36" alt="">
      <div class="small">
        <strong><?= htmlspecialchars($userName) ?></strong><br/>
        <span class="text-muted"><?= htmlspecialchars($userRole) ?></span>
      </div>
    </div>
  </div>

  <!-- Alerts for missing tables -->
  <?php if (!$invReady): ?>
    <div class="alert alert-warning"><ion-icon name="warning-outline"></ion-icon> Inventory snapshot needs <b>inventory_items</b> + <b>stock_levels</b>.</div>
  <?php endif; ?>
  <?php if (!$txReady): ?>
    <div class="alert alert-warning"><ion-icon name="warning-outline"></ion-icon> Stock activity needs <b>stock_transactions</b> (+ <b>inventory_items</b>).</div>
  <?php endif; ?>
  <?php if (!$shipReady): ?>
    <div class="alert alert-warning"><ion-icon name="warning-outline"></ion-icon> Shipment report needs <b>shipments</b> + <b>warehouse_locations</b>.</div>
  <?php endif; ?>

  <!-- Inventory Snapshot -->
  <?php if ($invReady): ?>
  <section class="card shadow-sm mb-3">
    <div class="card-body">
      <h5 class="mb-3 d-flex align-items-center gap-2"><ion-icon name="analytics-outline"></ion-icon> Inventory Snapshot</h5>

      <!-- Colored KPI tiles -->
      <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
          <div class="card shadow-sm kpi-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
              <div class="icon-wrap bg-primary-subtle"><ion-icon name="pricetag-outline" style="font-size:20px"></ion-icon></div>
              <div><div class="text-muted small">SKUs</div><div class="h4 m-0"><?= (int) $invTotals[
                  "items"
              ] ?></div></div>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="card shadow-sm kpi-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
              <div class="icon-wrap bg-success-subtle"><ion-icon name="cube-outline" style="font-size:20px"></ion-icon></div>
              <div><div class="text-muted small">Total Qty</div><div class="h4 m-0"><?= (int) $invTotals[
                  "total_qty"
              ] ?></div></div>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="card shadow-sm kpi-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
              <div class="icon-wrap bg-warning-subtle"><ion-icon name="alert-circle-outline" style="font-size:20px"></ion-icon></div>
              <div><div class="text-muted small">Low Stock</div><div class="h4 m-0"><?= (int) $invTotals[
                  "low_count"
              ] ?></div></div>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="card shadow-sm kpi-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
              <div class="icon-wrap bg-danger-subtle"><ion-icon name="close-circle-outline" style="font-size:20px"></ion-icon></div>
              <div><div class="text-muted small">Out of Stock</div><div class="h4 m-0"><?= (int) $invTotals[
                  "oos_count"
              ] ?></div></div>
            </div>
          </div>
        </div>
      </div>

      <h6 class="mb-2 d-flex align-items-center gap-2"><ion-icon name="trending-down-outline"></ion-icon> Lowest Stock (Top 10)</h6>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead><tr><th>SKU</th><th>Name</th><th class="text-end">Qty</th><th class="text-end">Reorder</th></tr></thead>
          <tbody>
            <?php if (!$lowStock): ?>
              <tr><td colspan="4" class="text-center text-muted py-3">No data</td></tr>
            <?php else:foreach ($lowStock as $r): ?>
              <tr>
                <td><?= htmlspecialchars($r["sku"]) ?></td>
                <td><?= htmlspecialchars($r["name"]) ?></td>
                <td class="text-end"><?= (int) $r["total_qty"] ?></td>
                <td class="text-end"><?= (int) $r["reorder_level"] ?></td>
              </tr>
            <?php endforeach;endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <!-- Stock Activity -->
  <?php if ($txReady): ?>
  <section class="card shadow-sm mb-3">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="mb-0 d-flex align-items-center gap-2"><ion-icon name="pulse-outline"></ion-icon> Stock Activity</h5>
        <form method="get" class="row g-2 align-items-center">
          <div class="col-6 col-md-auto"><input type="date" class="form-control form-control-sm" name="sa_from" value="<?= htmlspecialchars(
              $saFrom
          ) ?>"></div>
          <div class="col-6 col-md-auto"><input type="date" class="form-control form-control-sm" name="sa_to" value="<?= htmlspecialchars(
              $saTo
          ) ?>"></div>
          <div class="col-12 col-md-auto">
            <button class="btn btn-sm btn-outline-secondary" type="submit"><ion-icon name="filter-outline"></ion-icon> Apply</button>
          </div>
        </form>
      </div>

      <!-- Colored KPI tiles -->
      <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
          <div class="card shadow-sm kpi-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <div class="icon-wrap bg-primary-subtle"><ion-icon name="receipt-outline" style="font-size:20px"></ion-icon></div>
            <div><div class="text-muted small">Transactions</div><div class="h4 m-0"><?= (int) $sa[
                "total"
            ] ?></div></div>
          </div></div>
        </div>
        <div class="col-6 col-md-3">
          <div class="card shadow-sm kpi-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <div class="icon-wrap bg-info-subtle"><ion-icon name="swap-vertical-outline" style="font-size:20px"></ion-icon></div>
            <div><div class="text-muted small">Qty Moved</div><div class="h4 m-0"><?= (int) $sa[
                "moved_qty"
            ] ?></div></div>
          </div></div>
        </div>
        <div class="col-6 col-md-3">
          <div class="card shadow-sm kpi-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <div class="icon-wrap bg-success-subtle"><ion-icon name="download-outline" style="font-size:20px"></ion-icon></div>
            <div><div class="text-muted small">IN</div><div class="h4 m-0"><?= (int) $sa[
                "in"
            ] ?></div></div>
          </div></div>
        </div>
        <div class="col-6 col-md-3">
          <div class="card shadow-sm kpi-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <div class="icon-wrap bg-danger-subtle"><ion-icon name="exit-outline" style="font-size:20px"></ion-icon></div>
            <div><div class="text-muted small">OUT</div><div class="h4 m-0"><?= (int) $sa[
                "out"
            ] ?></div></div>
          </div></div>
        </div>
      </div>

      <h6 class="mb-2 d-flex align-items-center gap-2"><ion-icon name="list-outline"></ion-icon> Top Moved Items</h6>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead><tr><th>SKU</th><th>Name</th><th class="text-end">Qty Moved</th></tr></thead>
          <tbody>
            <?php if (!$topMoved): ?>
              <tr><td colspan="3" class="text-center text-muted py-3">No data</td></tr>
            <?php else:foreach ($topMoved as $t): ?>
              <tr><td><?= htmlspecialchars(
                  $t["sku"]
              ) ?></td><td><?= htmlspecialchars(
    $t["name"]
) ?></td><td class="text-end"><?= (int) $t["qty"] ?></td></tr>
            <?php endforeach;endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <!-- Shipment Summary (API via JS) -->
  <?php if ($shipReady): ?>
  <section class="card shadow-sm mb-4" id="shipRpt">
    <div class="card-body">
      <h5 class="mb-3 d-flex align-items-center gap-2"><ion-icon name="paper-plane-outline"></ion-icon> Shipment Summary</h5>

      <form id="rptForm" class="row g-2 align-items-end mb-3">
  <div class="col-6 col-md-2">
    <label class="form-label">From</label>
    <input type="date" class="form-control" id="rFrom">
  </div>
  <div class="col-6 col-md-2">
    <label class="form-label">To</label>
    <input type="date" class="form-control" id="rTo">
  </div>
  <div class="col-6 col-md-2">
    <label class="form-label">Status</label>
    <select id="rStatus" class="form-select">
      <option value="">All</option>
      <option>Draft</option><option>Ready</option><option>Dispatched</option>
      <option>In Transit</option><option>Delivered</option>
      <option>Delayed</option><option>Cancelled</option><option>Returned</option>
    </select>
  </div>
  <div class="col-6 col-md-2">
    <label class="form-label">Carrier</label>
    <input id="rCarrier" class="form-control" placeholder="example: LBC">
  </div>

  <div class="col-12 col-md-4 d-grid d-md-flex gap-2">
    <button class="btn btn-violet" type="submit">
      <ion-icon name="play-circle-outline"></ion-icon> Run
    </button>
    <button type="button" id="dlCsv" class="btn btn-outline-secondary">
      <ion-icon name="download-outline"></ion-icon> CSV
    </button>
    <button type="button" id="btnPrint" class="btn btn-outline-secondary">
      <ion-icon name="print-outline"></ion-icon> Print
    </button>
  </div>
</form>


      <!-- Colored KPI tiles for shipments -->
      <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
          <div class="card shadow-sm kpi-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <div class="icon-wrap bg-primary-subtle"><ion-icon name="trail-sign-outline" style="font-size:20px"></ion-icon></div>
            <div><div class="text-muted small">Total</div><div id="kTotal" class="h4 m-0">—</div></div>
          </div></div>
        </div>
        <div class="col-6 col-md-3">
          <div class="card shadow-sm kpi-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <div class="icon-wrap bg-success-subtle"><ion-icon name="checkbox-outline" style="font-size:20px"></ion-icon></div>
            <div><div class="text-muted small">Delivered</div><div id="kDelivered" class="h4 m-0">—</div></div>
          </div></div>
        </div>
        <div class="col-6 col-md-3">
          <div class="card shadow-sm kpi-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <div class="icon-wrap bg-info-subtle"><ion-icon name="time-outline" style="font-size:20px"></ion-icon></div>
            <div><div class="text-muted small">On-time %</div><div id="kOnTime" class="h4 m-0">—</div></div>
          </div></div>
        </div>
        <div class="col-6 col-md-3">
          <div class="card shadow-sm kpi-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <div class="icon-wrap bg-warning-subtle"><ion-icon name="speedometer-outline" style="font-size:20px"></ion-icon></div>
            <div><div class="text-muted small">Avg Transit</div><div id="kTransit" class="h4 m-0">—</div></div>
          </div></div>
        </div>
      </div>

      <!-- Charts row -->
      <div class="row g-3">
        <div class="col-12 col-lg-6">
          <div class="card shadow-sm chart-card h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="m-0 d-flex align-items-center gap-2"><ion-icon name="grid-outline"></ion-icon> By Status</h6>
              </div>
              <canvas id="chartStatus"></canvas>
            </div>
          </div>
        </div>
        <div class="col-12 col-lg-6">
          <div class="card shadow-sm chart-card h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="m-0 d-flex align-items-center gap-2"><ion-icon name="rocket-outline"></ion-icon> Top Carriers</h6>
              </div>
              <canvas id="chartCarrier"></canvas>
            </div>
          </div>
        </div>
      </div>

      <script>
 

  // When user opens the print dialog via our button or Ctrl+P, it prints :P
  window.addEventListener('beforeprint', () => updateChartsForPrint(true));
  window.addEventListener('afterprint',  () => updateChartsForPrint(false));

  
  document.getElementById('btnPrint')?.addEventListener('click', (e) => {
    updateChartsForPrint(true);
    // slight timeout makes sure canvases reflow before dialog shows
    setTimeout(() => window.print(), 150);
  }, { capture: true });
</script>


      <!-- Tables -->
      <div class="row g-3 mt-1">
        <div class="col-md-6">
          <h6 class="mb-2 d-flex align-items-center gap-2"><ion-icon name="grid-outline"></ion-icon> By Status (table)</h6>
          <table class="table table-sm"><tbody id="tblStatus"></tbody></table>
        </div>
        <div class="col-md-6">
          <h6 class="mb-2 d-flex align-items-center gap-2"><ion-icon name="rocket-outline"></ion-icon> Top Lanes</h6>
          <table class="table table-sm">
            <thead><tr><th>Lane</th><th class="text-end">Total</th></tr></thead>
            <tbody id="tblLanes"></tbody>
          </table>
        </div>
      </div>

      <div class="mt-3">
        <h6 class="mb-2 d-flex align-items-center gap-2"><ion-icon name="alert-outline"></ion-icon> Most Overdue (not delivered)</h6>
        <table class="table table-sm">
          <thead><tr><th>Ref</th><th>Dest</th><th class="text-end">Days overdue</th></tr></thead>
          <tbody id="tblLate"></tbody>
        </table>
      </div>
    </div>
  </section>
  <?php endif; ?>

</div><!-- /main -->
</div>
</div>

<script>
  // Chart.js defaults to match your UI
  Chart.defaults.font.family = getComputedStyle(document.body).fontFamily || 'system-ui';
  Chart.defaults.color = getComputedStyle(document.body).color || '#222';

  let chartStatus, chartCarrier;

   // Make charts static for print
  function updateChartsForPrint(disable = true) {
    [chartStatus, chartCarrier].forEach(ch => {
      if (!ch) return;
      ch.options.animation = !disable;
      // 'none' update mode = no animation in Chart.js v4
      ch.resize();
      ch.update(disable ? 'none' : undefined);
    });
  }

 // write current filters to the URL
function writeFiltersToQS() {
  const params = new URLSearchParams({
    from:   document.getElementById('rFrom')?.value || '',
    to:     document.getElementById('rTo')?.value || '',
    status: document.getElementById('rStatus')?.value || '',
    carrier:(document.getElementById('rCarrier')?.value || '').trim()
  });
  history.replaceState(null, '', '?' + params.toString());
}

// read filters from URL into the form on load
function prefillFromQS() {
  const u = new URLSearchParams(location.search);
  if (u.has('from'))   document.getElementById('rFrom').value   = u.get('from');
  if (u.has('to'))     document.getElementById('rTo').value     = u.get('to');
  if (u.has('status')) document.getElementById('rStatus').value = u.get('status');
  if (u.has('carrier'))document.getElementById('rCarrier').value= u.get('carrier');
}

// submit: save filters to URL, then run report
document.getElementById('rptForm')?.addEventListener('submit', (e)=>{
  e.preventDefault();
  writeFiltersToQS();
  loadShipRpt();
});

// on page load: prefill filters from URL, then run report
document.addEventListener('DOMContentLoaded', ()=>{
  if (!document.getElementById('shipRpt')) return;
  prefillFromQS();
  loadShipRpt();
});


  async function loadShipRpt(){
    const params = new URLSearchParams({
      from: document.getElementById('rFrom')?.value || '',
      to:   document.getElementById('rTo')?.value || '',
      status: document.getElementById('rStatus')?.value || '',
      carrier: (document.getElementById('rCarrier')?.value || '').trim()
    });
    const res = await fetch('./TrackShipment/api/report_shipments.php?'+params.toString(), {credentials:'same-origin'});
    const raw = await res.text();
    if (!res.ok) { alert('Report failed: '+raw.slice(0,200)); console.error(raw); return; }
    let data; try { data = JSON.parse(raw); } catch { alert('Bad JSON'); console.error(raw); return; }

    // KPIs
    const t = data.totals || {};
    document.getElementById('kTotal').textContent     = t.total ?? 0;
    document.getElementById('kDelivered').textContent = t.delivered ?? 0;
    document.getElementById('kOnTime').textContent    = ((t.on_time_rate ?? 0)) + '%';
    document.getElementById('kTransit').textContent   = (t.avg_transit_days ?? '—');

    // Status table
    const sb = data.status_breakdown || {};
    document.getElementById('tblStatus').innerHTML = Object.keys(sb).map(k=>`
      <tr><td>${k}</td><td class="text-end">${sb[k]}</td></tr>
    `).join('') || '<tr><td colspan="2" class="text-muted">No data</td></tr>';

    // Carriers table (reuse for chart)
    const carriers = (data.carriers || []).slice(0,8);
    // Lanes table
    document.getElementById('tblLanes').innerHTML = (data.lanes||[]).slice(0,8).map(l=>`
      <tr><td>${l.lane}</td><td class="text-end">${l.total}</td></tr>
    `).join('') || '<tr><td colspan="2" class="text-muted">No data</td></tr>';

    // Late table
    document.getElementById('tblLate').innerHTML = (data.late||[]).map(r=>`
      <tr><td>${r.ref_no}</td><td>${r.dest||'—'}</td><td class="text-end">${r.days_overdue}</td></tr>
    `).join('') || '<tr><td colspan="3" class="text-muted">No data</td></tr>';

    // ---- Charts ----
    // Status doughnut
    const stLabels = Object.keys(sb);
    const stVals   = stLabels.map(k => sb[k]);
    const ctxS = document.getElementById('chartStatus');
    if (ctxS) {
      if (chartStatus) chartStatus.destroy();
      chartStatus = new Chart(ctxS, {
        type: 'doughnut',
        data: { labels: stLabels, datasets: [{ data: stVals, borderWidth: 1 }] },
        options: { maintainAspectRatio:false, plugins:{ legend:{ position:'bottom' } } }
      });
    }

    // Carrier bar
    const cLabels = carriers.map(c=>c.carrier || '—');
    const cVals   = carriers.map(c=>c.total || 0);
    const ctxC = document.getElementById('chartCarrier');
    if (ctxC) {
      if (chartCarrier) chartCarrier.destroy();
      chartCarrier = new Chart(ctxC, {
        type: 'bar',
        data: { labels: cLabels, datasets: [{ label:'Shipments', data: cVals, borderWidth:1 }] },
        options: {
          maintainAspectRatio:false,
          scales:{ y:{ beginAtZero:true, ticks:{ precision:0 } } },
          plugins:{ legend:{ display:false } }
        }
      });
    }
  }

 
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
  // CSV download: reuse current URL filters (sticky)
  document.getElementById('dlCsv')?.addEventListener('click', () => {
    const u = new URLSearchParams(location.search);
    u.set('format','csv');
    window.open('./TrackShipment/api/report_shipments.php?' + u.toString(), '_blank');
  });


</script>



</body>
</html>
