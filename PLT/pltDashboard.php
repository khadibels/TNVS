<?php
$inc = __DIR__ . "/../includes";
if (file_exists($inc . "/config.php")) require_once $inc . "/config.php";
if (file_exists($inc . "/auth.php"))   require_once $inc . "/auth.php";
if (file_exists($inc . "/db.php"))     require_once $inc . "/db.php";

if (function_exists("require_login")) require_login();
require_role(['admin', 'project_lead']);

$pdo = db('plt');
if (!$pdo) {
  http_response_code(500);
  exit('DB connection failed (plt). Check includes/config.php creds for DB_PLT_*.');
}


$userName = "Admin";
$userRole = "System Admin";
if (function_exists("current_user")) {
    $u = current_user();
    $userName = $u["name"] ?? $userName;
    $userRole = $u["role"] ?? $userRole;
}

// ----- helpers -----
function qcol(PDO $pdo, string $sql, array $bind = [], $default = 0)
{
    $st = $pdo->prepare($sql);
    $st->execute($bind);
    $v = $st->fetchColumn();
    return $v === false ? $default : $v;
}
function qall(PDO $pdo, string $sql, array $bind = [])
{
    $st = $pdo->prepare($sql);
    $st->execute($bind);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// ----- KPI queries -----
$kToday = qcol(
    $pdo,
    "SELECT COUNT(*) FROM plt_shipments WHERE schedule_date = CURDATE()"
);
$kWeek = qcol(
    $pdo,
    "SELECT COUNT(*) FROM plt_shipments WHERE schedule_date >= CURDATE() AND schedule_date < DATE_ADD(CURDATE(), INTERVAL 7 DAY)"
);
$kDel7 = qcol(
    $pdo,
    "SELECT COUNT(*) FROM plt_shipments WHERE status='delivered' AND COALESCE(delivered_at, eta_date, schedule_date) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)"
);
$kProj = qcol(
    $pdo,
    "SELECT COUNT(*) FROM plt_projects WHERE status IN('planned','ongoing','delayed')"
);
$kVeh = qcol(
    $pdo,
    "SELECT COUNT(DISTINCT TRIM(vehicle)) FROM plt_shipments WHERE TRIM(COALESCE(vehicle,'')) <> ''"
);
$kDrv = qcol(
    $pdo,
    "SELECT COUNT(DISTINCT TRIM(driver))  FROM plt_shipments WHERE TRIM(COALESCE(driver ,'')) <> ''"
);

// ----- charts data -----
// Status breakdown (last 30 days, by schedule_date)
$rowsStatus = qall(
    $pdo,
    "
  SELECT LOWER(status) AS status, COUNT(*) AS total
  FROM plt_shipments
  WHERE schedule_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
  GROUP BY LOWER(status)
  ORDER BY total DESC
"
);
// Shipments per day (last 14 days)
$rowsDaily = qall(
    $pdo,
    "
  SELECT DATE(schedule_date) d, COUNT(*) total
  FROM plt_shipments
  WHERE schedule_date >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
  GROUP BY DATE(schedule_date)
  ORDER BY d ASC
"
);

// ----- tables -----
// Upcoming deliveries (next 7 days)
$upcoming = qall(
    $pdo,
    "
  SELECT s.id, s.shipment_no, s.origin, s.destination, s.schedule_date, s.status, s.vehicle, s.driver,
         p.name AS project_name
  FROM plt_shipments s
  LEFT JOIN plt_projects p ON p.id = s.project_id
  WHERE s.schedule_date >= CURDATE() AND s.schedule_date < DATE_ADD(CURDATE(), INTERVAL 7 DAY)
  ORDER BY s.schedule_date ASC, s.id ASC
  LIMIT 10
"
);
// Recent shipments (latest 10)
$recent = qall(
    $pdo,
    "
  SELECT s.id, s.shipment_no, s.origin, s.destination, s.schedule_date, s.eta_date, s.status,
         p.name AS project_name
  FROM plt_shipments s
  LEFT JOIN plt_projects p ON p.id = s.project_id
  ORDER BY s.id DESC
  LIMIT 10
"
);
// Projects at risk (delayed & not closed)
$atRisk = qall(
    $pdo,
    "
  SELECT id, code, name, owner_name, deadline_date
  FROM plt_projects
  WHERE status='delayed'
  ORDER BY COALESCE(deadline_date, '9999-12-31') ASC, id DESC
  LIMIT 10
"
);

// Prep JSON for charts
$stLabels = array_map(fn($r) => $r["status"], $rowsStatus);
$stValues = array_map(fn($r) => (int) $r["total"], $rowsStatus);

$dailyLabels = [];
$dailyValues = [];
// Ensure 14-day continuous axis (fill zeros)
$start = new DateTime(date("Y-m-d", strtotime("-13 days")));
for ($i = 0; $i < 14; $i++) {
    $day = clone $start;
    $day->modify("+$i day");
    $lbl = $day->format("Y-m-d");
    $dailyLabels[] = $lbl;
    $match = array_values(array_filter($rowsDaily, fn($r) => $r["d"] === $lbl));
    $dailyValues[] = $match ? (int) $match[0]["total"] : 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Dashboard | PLT</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="../css/style.css" rel="stylesheet" />
  <link href="../css/modules.css" rel="stylesheet" />
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <script src="../js/sidebar-toggle.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
  <style>
    .kpi .icon-wrap{width:42px;height:42px;display:flex;align-items:center;justify-content:center;border-radius:12px}
    .route{font-size:.85rem;color:#666}
    .chart-card canvas{width:100%!important;height:300px!important}
    .badge-status{font-weight:500}
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
      <h6 class="text-uppercase mb-2">PLT</h6>
      <nav class="nav flex-column px-2 mb-4">
        <a class="nav-link active" href="./pltDashboard.php"><ion-icon name="home-outline"></ion-icon><span>Dashboard</span></a>
        <a class="nav-link" href="./projectTracking.php"><ion-icon name="briefcase-outline"></ion-icon><span>Project Tracking</span></a>
        <a class="nav-link" href="./shipmentTracker.php"><ion-icon name="trail-sign-outline"></ion-icon><span>Shipment Tracker</span></a>
        <a class="nav-link" href="./deliverySchedule.php"><ion-icon name="calendar-outline"></ion-icon><span>Delivery Schedule</span></a>
        <a class="nav-link" href="./pltReports.php"><ion-icon name="file-tray-stacked-outline"></ion-icon><span>Reports</span></a>
      </nav>
      <div class="logout-section">
        <a class="nav-link text-danger" href="<?= defined("BASE_URL")
            ? BASE_URL
            : "#" ?>/auth/logout.php">
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
          <h2 class="m-0">Dashboard</h2>
        </div>
        <div class="d-flex align-items-center gap-2">
          <img src="../img/profile.jpg" class="rounded-circle" width="36" height="36" alt="">
          <div class="small">
            <strong><?= htmlspecialchars($userName) ?></strong><br/>
            <span class="text-muted"><?= htmlspecialchars($userRole) ?></span>
          </div>
        </div>
      </div>

      <!-- KPIs -->
      <div class="row g-3 mb-3">
        <div class="col-6 col-md-3"><div class="card shadow-sm kpi h-100"><div class="card-body d-flex gap-3 align-items-center">
          <div class="icon-wrap bg-primary-subtle"><ion-icon name="calendar-outline"></ion-icon></div>
          <div><div class="text-muted small">Deliveries Today</div><div class="h4 m-0"><?= (int) $kToday ?></div></div>
        </div></div></div>
        <div class="col-6 col-md-3"><div class="card shadow-sm kpi h-100"><div class="card-body d-flex gap-3 align-items-center">
          <div class="icon-wrap bg-info-subtle"><ion-icon name="calendar-number-outline"></ion-icon></div>
          <div><div class="text-muted small">Next 7 Days</div><div class="h4 m-0"><?= (int) $kWeek ?></div></div>
        </div></div></div>
        <div class="col-6 col-md-3"><div class="card shadow-sm kpi h-100"><div class="card-body d-flex gap-3 align-items-center">
          <div class="icon-wrap bg-success-subtle"><ion-icon name="checkmark-done-outline"></ion-icon></div>
          <div><div class="text-muted small">Delivered (7d)</div><div class="h4 m-0"><?= (int) $kDel7 ?></div></div>
        </div></div></div>
        <div class="col-6 col-md-3"><div class="card shadow-sm kpi h-100"><div class="card-body d-flex gap-3 align-items-center">
          <div class="icon-wrap bg-violet-subtle"><ion-icon name="briefcase-outline"></ion-icon></div>
          <div><div class="text-muted small">Active Projects</div><div class="h4 m-0"><?= (int) $kProj ?></div></div>
        </div></div></div>
        <div class="col-6 col-md-3"><div class="card shadow-sm kpi h-100"><div class="card-body d-flex gap-3 align-items-center">
          <div class="icon-wrap bg-warning-subtle"><ion-icon name="bus-outline"></ion-icon></div>
          <div><div class="text-muted small">Vehicles (distinct)</div><div class="h4 m-0"><?= (int) $kVeh ?></div></div>
        </div></div></div>
        <div class="col-6 col-md-3"><div class="card shadow-sm kpi h-100"><div class="card-body d-flex gap-3 align-items-center">
          <div class="icon-wrap bg-secondary-subtle"><ion-icon name="person-outline"></ion-icon></div>
          <div><div class="text-muted small">Drivers (distinct)</div><div class="h4 m-0"><?= (int) $kDrv ?></div></div>
        </div></div></div>
      </div>

      <!-- Charts -->
      <div class="row g-3 mb-3">
        <div class="col-12 col-lg-6">
          <div class="card shadow-sm chart-card h-100">
            <div class="card-body">
              <h6 class="mb-2 d-flex align-items-center gap-2"><ion-icon name="pie-chart-outline"></ion-icon> Status (last 30d)</h6>
              <canvas id="cStatus"></canvas>
            </div>
          </div>
        </div>
        <div class="col-12 col-lg-6">
          <div class="card shadow-sm chart-card h-100">
            <div class="card-body">
              <h6 class="mb-2 d-flex align-items-center gap-2"><ion-icon name="bar-chart-outline"></ion-icon> Shipments per Day (last 14d)</h6>
              <canvas id="cDaily"></canvas>
            </div>
          </div>
        </div>
      </div>

      <!-- Tables -->
      <div class="row g-3">
        <div class="col-12 col-lg-6">
          <div class="card shadow-sm h-100">
            <div class="card-body">
              <h6 class="mb-2 d-flex align-items-center gap-2"><ion-icon name="calendar-outline"></ion-icon> Upcoming Deliveries (7d)</h6>
              <div class="table-responsive">
                <table class="table table-sm align-middle">
                  <thead><tr><th>Schedule</th><th>Shipment</th><th>Project</th><th>Route</th><th>Status</th></tr></thead>
                  <tbody>
                    <?php if (!$upcoming): ?>
                      <tr><td colspan="5" class="text-muted text-center py-3">No upcoming deliveries</td></tr>
                    <?php else:foreach ($upcoming as $r): ?>
                      <tr>
                        <td><?= htmlspecialchars($r["schedule_date"]) ?></td>
                        <td class="fw-semibold"><?= htmlspecialchars(
                            $r["shipment_no"] ?: "SHP-" . $r["id"]
                        ) ?></td>
                        <td><?= htmlspecialchars(
                            $r["project_name"] ?: "-"
                        ) ?></td>
                        <td class="route"><?= htmlspecialchars(
                            $r["origin"] ?: "-"
                        ) ?> → <?= htmlspecialchars(
                            $r["destination"] ?: "-"
                        ) ?></td>
                        <td><span class="badge bg-secondary badge-status"><?= htmlspecialchars(
                            ucfirst(str_replace("_", " ", $r["status"]))
                        ) ?></span></td>
                      </tr>
                    <?php endforeach;endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <div class="col-12 col-lg-6">
          <div class="card shadow-sm h-100">
            <div class="card-body">
              <h6 class="mb-2 d-flex align-items-center gap-2"><ion-icon name="time-outline"></ion-icon> Recent Shipments</h6>
              <div class="table-responsive">
                <table class="table table-sm align-middle">
                  <thead><tr><th>Shipment</th><th>Project</th><th>Schedule</th><th>ETA</th><th>Status</th></tr></thead>
                  <tbody>
                    <?php if (!$recent): ?>
                      <tr><td colspan="5" class="text-muted text-center py-3">No data</td></tr>
                    <?php else:foreach ($recent as $r): ?>
                      <tr>
                        <td class="fw-semibold"><?= htmlspecialchars(
                            $r["shipment_no"] ?: "SHP-" . $r["id"]
                        ) ?></td>
                        <td><?= htmlspecialchars(
                            $r["project_name"] ?: "-"
                        ) ?></td>
                        <td><?= htmlspecialchars(
                            $r["schedule_date"] ?: "-"
                        ) ?></td>
                        <td><?= htmlspecialchars($r["eta_date"] ?: "-") ?></td>
                        <td><span class="badge bg-secondary badge-status"><?= htmlspecialchars(
                            ucfirst(str_replace("_", " ", $r["status"]))
                        ) ?></span></td>
                      </tr>
                    <?php endforeach;endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <div class="col-12">
          <div class="card shadow-sm">
            <div class="card-body">
              <h6 class="mb-2 d-flex align-items-center gap-2"><ion-icon name="alert-circle-outline"></ion-icon> Projects at Risk</h6>
              <div class="table-responsive">
                <table class="table table-sm align-middle">
                  <thead><tr><th>Code</th><th>Name</th><th>Owner</th><th>Deadline</th></tr></thead>
                  <tbody>
                    <?php if (!$atRisk): ?>
                      <tr><td colspan="4" class="text-muted text-center py-3">No delayed projects</td></tr>
                    <?php else:foreach ($atRisk as $p): ?>
                      <tr>
                        <td class="fw-semibold"><?= htmlspecialchars(
                            $p["code"] ?: "PRJ-" . $p["id"]
                        ) ?></td>
                        <td><?= htmlspecialchars($p["name"]) ?></td>
                        <td><?= htmlspecialchars(
                            $p["owner_name"] ?: "—"
                        ) ?></td>
                        <td><?= htmlspecialchars(
                            $p["deadline_date"] ?: "—"
                        ) ?></td>
                      </tr>
                    <?php endforeach;endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

      </div><!-- /tables -->
    </div><!-- /main -->
  </div><!-- /row -->
</div><!-- /container -->

<script>
  // Chart.js styling matches your UI font/colors
  Chart.defaults.font.family = getComputedStyle(document.body).fontFamily || 'system-ui';
  Chart.defaults.color = getComputedStyle(document.body).color || '#222';

  const statusLabels = <?= json_encode($stLabels) ?>;
  const statusValues = <?= json_encode($stValues) ?>;
  const dailyLabels  = <?= json_encode($dailyLabels) ?>;
  const dailyValues  = <?= json_encode($dailyValues) ?>;

  const ctxS = document.getElementById('cStatus');
  if (ctxS) {
    new Chart(ctxS, {
      type: 'doughnut',
      data: { labels: statusLabels, datasets: [{ data: statusValues, borderWidth: 1 }] },
      options: { maintainAspectRatio:false, plugins:{ legend:{ position:'bottom' } } }
    });
  }

  const ctxD = document.getElementById('cDaily');
  if (ctxD) {
    new Chart(ctxD, {
      type: 'bar',
      data: { labels: dailyLabels, datasets: [{ label: 'Shipments', data: dailyValues, borderWidth:1 }] },
      options: {
        maintainAspectRatio:false,
        scales:{ y:{ beginAtZero:true, ticks:{ precision:0 } } },
        plugins:{ legend:{ display:false } }
      }
    });
  }
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
