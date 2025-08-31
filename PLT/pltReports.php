<?php
require_once __DIR__ . "/../includes/config.php";
require_once __DIR__ . "/../includes/auth.php";
require_login();

function table_exists(PDO $pdo, string $name): bool
{
    $s = $pdo->prepare("SHOW TABLES LIKE ?");
    $s->execute([$name]);
    return (bool) $s->fetchColumn();
}
function role()
{
    return $_SESSION["user"]["role"] ?? "Viewer";
}

$userName = $_SESSION["user"]["name"] ?? "Admin";
$userRole = $_SESSION["user"]["role"] ?? "System Admin";

$hasShip = table_exists($pdo, "plt_shipments");
$hasProj = table_exists($pdo, "plt_projects");
$ready = $hasShip;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Reports | PLT</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="../css/style.css" rel="stylesheet" />
  <link href="../css/modules.css" rel="stylesheet" />

  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <script src="../js/sidebar-toggle.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>

 
</head>
<body>
<div class="container-fluid p-0">
  <div class="row g-0">

    <!-- Sidebar -->
    <div class="sidebar d-flex flex-column">
      <div class="d-flex justify-content-center align-items-center mb-4 mt-3">
        <img src="../img/logo.png" id="logo" class="img-fluid me-2" style="height:55px" alt="Logo">
      </div>
      <h6 class="text-uppercase mb-2">PLT</h6>
      <nav class="nav flex-column px-2 mb-4">
        <a class="nav-link" href="./pltDashboard.php"><ion-icon name="home-outline"></ion-icon><span>Dashboard</span></a>
        <a class="nav-link" href="./projectTracking.php"><ion-icon name="briefcase-outline"></ion-icon><span>Project Tracking</span></a>
        <a class="nav-link" href="./shipmentTracker.php"><ion-icon name="trail-sign-outline"></ion-icon><span>Shipment Tracker</span></a>
        <a class="nav-link" href="./deliverySchedule.php"><ion-icon name="calendar-outline"></ion-icon><span>Delivery Schedule</span></a>
        <a class="nav-link active" href="./pltReports.php"><ion-icon name="file-tray-stacked-outline"></ion-icon><span>Reports</span></a>
      </nav>
      <div class="logout-section">
        <a class="nav-link text-danger" href="<?= defined("BASE_URL")
            ? BASE_URL
            : "" ?>/auth/logout.php">
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

      <?php if (!$ready): ?>
        <div class="alert alert-warning"><ion-icon name="warning-outline"></ion-icon> Reports need the <b>plt_shipments</b> table.</div>
      <?php endif; ?>

      <?php if ($ready): ?>
      <!-- Shipment Summary -->
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
                <option value="planned">Planned</option>
                <option value="picked">Picked</option>
                <option value="in_transit">In-Transit</option>
                <option value="delivered">Delivered</option>
                <option value="cancelled">Cancelled</option>
              </select>
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label">Vehicle</label>
              <input id="rVehicle" class="form-control" placeholder="e.g. Truck-12">
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

          <!-- KPI tiles -->
          <div class="row g-3 mb-3">
            <div class="col-6 col-md-3"><div class="card shadow-sm kpi-card h-100"><div class="card-body d-flex align-items-center gap-3">
              <div class="icon-wrap bg-primary-subtle"><ion-icon name="trail-sign-outline" style="font-size:20px"></ion-icon></div>
              <div><div class="text-muted small">Total</div><div id="kTotal" class="h4 m-0">—</div></div>
            </div></div></div>
            <div class="col-6 col-md-3"><div class="card shadow-sm kpi-card h-100"><div class="card-body d-flex align-items-center gap-3">
              <div class="icon-wrap bg-success-subtle"><ion-icon name="checkbox-outline" style="font-size:20px"></ion-icon></div>
              <div><div class="text-muted small">Delivered</div><div id="kDelivered" class="h4 m-0">—</div></div>
            </div></div></div>
            <div class="col-6 col-md-3"><div class="card shadow-sm kpi-card h-100"><div class="card-body d-flex align-items-center gap-3">
              <div class="icon-wrap bg-info-subtle"><ion-icon name="time-outline" style="font-size:20px"></ion-icon></div>
              <div><div class="text-muted small">On-time %</div><div id="kOnTime" class="h4 m-0">—</div></div>
            </div></div></div>
            <div class="col-6 col-md-3"><div class="card shadow-sm kpi-card h-100"><div class="card-body d-flex align-items-center gap-3">
              <div class="icon-wrap bg-warning-subtle"><ion-icon name="speedometer-outline" style="font-size:20px"></ion-icon></div>
              <div><div class="text-muted small">Avg Transit</div><div id="kTransit" class="h4 m-0">—</div></div>
            </div></div></div>
          </div>

          <!-- Charts -->
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
                    <h6 class="m-0 d-flex align-items-center gap-2"><ion-icon name="bus-outline"></ion-icon> Top Vehicles</h6>
                  </div>
                  <canvas id="chartVehicle"></canvas>
                </div>
              </div>
            </div>
          </div>

          <!-- Print helpers -->
          <script>
            let chartStatus, chartVehicle;
            Chart.defaults.font.family = getComputedStyle(document.body).fontFamily || 'system-ui';
            Chart.defaults.color = getComputedStyle(document.body).color || '#222';
            function updateChartsForPrint(disable = true) {
              [chartStatus, chartVehicle].forEach(ch => { if (!ch) return; ch.options.animation=!disable; ch.resize(); ch.update(disable?'none':undefined); });
            }
            window.addEventListener('beforeprint', () => updateChartsForPrint(true));
            window.addEventListener('afterprint',  () => updateChartsForPrint(false));
            document.getElementById('btnPrint')?.addEventListener('click', () => { updateChartsForPrint(true); setTimeout(()=>window.print(),150); }, {capture:true});
          </script>

          <!-- Tables -->
          <div class="row g-3 mt-1">
            <div class="col-md-6">
              <h6 class="mb-2 d-flex align-items-center gap-2"><ion-icon name="grid-outline"></ion-icon> By Status (table)</h6>
              <table class="table table-sm"><tbody id="tblStatus"></tbody></table>
            </div>
            <div class="col-md-6">
              <h6 class="mb-2 d-flex align-items-center gap-2"><ion-icon name="map-outline"></ion-icon> Top Lanes</h6>
              <table class="table table-sm">
                <thead><tr><th>Lane</th><th class="text-end">Total</th></tr></thead>
                <tbody id="tblLanes"></tbody>
              </table>
            </div>
          </div>

          <div class="mt-3">
            <h6 class="mb-2 d-flex align-items-center gap-2"><ion-icon name="alert-outline"></ion-icon> Most Overdue (not delivered)</h6>
            <table class="table table-sm">
              <thead><tr><th>Ref</th><th>Destination</th><th class="text-end">Days overdue</th></tr></thead>
              <tbody id="tblLate"></tbody>
            </table>
          </div>
        </div>
      </section>
      <?php endif; ?>

    </div><!-- /main -->
  </div><!-- /row -->
</div><!-- /container -->

<script>
  // sticky filters in URL
  function writeFiltersToQS() {
    const params = new URLSearchParams({
      from:   document.getElementById('rFrom')?.value || '',
      to:     document.getElementById('rTo')?.value || '',
      status: document.getElementById('rStatus')?.value || '',
      vehicle:(document.getElementById('rVehicle')?.value || '').trim()
    });
    history.replaceState(null, '', '?' + params.toString());
  }
  function prefillFromQS() {
    const u = new URLSearchParams(location.search);
    if (u.has('from'))    document.getElementById('rFrom').value    = u.get('from');
    if (u.has('to'))      document.getElementById('rTo').value      = u.get('to');
    if (u.has('status'))  document.getElementById('rStatus').value  = u.get('status');
    if (u.has('vehicle')) document.getElementById('rVehicle').value = u.get('vehicle');
  }
  document.getElementById('rptForm')?.addEventListener('submit', (e)=>{
    e.preventDefault(); writeFiltersToQS(); loadShipRpt();
  });
  document.addEventListener('DOMContentLoaded', ()=>{ if (!document.getElementById('shipRpt')) return; prefillFromQS(); loadShipRpt(); });

  async function loadShipRpt(){
    const params = new URLSearchParams({
      from: document.getElementById('rFrom')?.value || '',
      to:   document.getElementById('rTo')?.value || '',
      status: document.getElementById('rStatus')?.value || '',
      vehicle:(document.getElementById('rVehicle')?.value || '').trim()
    });
    const url = new URL('./api/plt_report_shipments.php?' + params.toString(), window.location.href);
    const res = await fetch(url, {credentials:'same-origin'});
    const raw = await res.text();
    if (!res.ok) { alert('Report failed: '+raw.slice(0,200)); console.error(raw); return; }
    let data; try { data = JSON.parse(raw); } catch { alert('Bad JSON'); console.error(raw); return; }

    // KPIs
    const t = data.totals || {};
    document.getElementById('kTotal').textContent     = t.total ?? 0;
    document.getElementById('kDelivered').textContent = t.delivered ?? 0;
    document.getElementById('kOnTime').textContent    = (t.on_time_rate != null ? t.on_time_rate+'%' : '—');
    document.getElementById('kTransit').textContent   = (t.avg_transit_days ?? '—');

    // status table
    const sb = data.status_breakdown || {};
    document.getElementById('tblStatus').innerHTML = Object.keys(sb).map(k=>`
      <tr><td>${k}</td><td class="text-end">${sb[k]}</td></tr>
    `).join('') || '<tr><td colspan="2" class="text-muted">No data</td></tr>';

    // lanes
    document.getElementById('tblLanes').innerHTML = (data.lanes||[]).slice(0,8).map(l=>`
      <tr><td>${l.lane}</td><td class="text-end">${l.total}</td></tr>
    `).join('') || '<tr><td colspan="2" class="text-muted">No data</td></tr>';

    // late
    document.getElementById('tblLate').innerHTML = (data.late||[]).map(r=>`
      <tr><td>${r.ref_no}</td><td>${r.dest||'—'}</td><td class="text-end">${r.days_overdue}</td></tr>
    `).join('') || '<tr><td colspan="3" class="text-muted">No data</td></tr>';

    // charts
    const stLabels = Object.keys(sb);
    const stVals   = stLabels.map(k => sb[k]);
    const ctxS = document.getElementById('chartStatus');
    if (ctxS) { if (window.chartStatus) window.chartStatus.destroy();
      window.chartStatus = new Chart(ctxS, {
        type:'doughnut',
        data:{ labels: stLabels, datasets:[{ data: stVals, borderWidth:1 }] },
        options:{ maintainAspectRatio:false, plugins:{ legend:{ position:'bottom' } } }
      });
    }

    const veh = (data.vehicles||[]).slice(0,8);
    const vLabels = veh.map(x=>x.vehicle || '—');
    const vVals   = veh.map(x=>x.total || 0);
    const ctxV = document.getElementById('chartVehicle');
    if (ctxV) { if (window.chartVehicle) window.chartVehicle.destroy();
      window.chartVehicle = new Chart(ctxV, {
        type:'bar',
        data:{ labels:vLabels, datasets:[{ label:'Shipments', data:vVals, borderWidth:1 }] },
        options:{ maintainAspectRatio:false, scales:{ y:{ beginAtZero:true, ticks:{ precision:0 } } }, plugins:{ legend:{ display:false } } }
      });
    }
  }

  // CSV download reuses current filters
  document.getElementById('dlCsv')?.addEventListener('click', () => {
    const u = new URLSearchParams(location.search);
    u.set('format','csv');
    window.open('./api/plt_report_shipments.php?' + u.toString(), '_blank');
  });
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
