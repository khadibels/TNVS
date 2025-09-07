<?php
require_once __DIR__ . "/../includes/config.php";
require_once __DIR__ . "/../includes/auth.php";
require_login();

$section = 'docs';
$active  = 'dashboard';

try {
  $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",$DB_USER,$DB_PASS,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
  ]);
} catch(Throwable $e){
  http_response_code(500); echo "DB connection failed"; exit;
}

function h($s){ return htmlspecialchars($s??'',ENT_QUOTES,'UTF-8'); }

/* -----------------------------
   FILTERS (date range optional)
------------------------------*/
$fFrom = $_GET['from'] ?? '';
$fTo   = $_GET['to']   ?? '';
$params=[]; $where=[];
if($fFrom!==''){ $where[] = "trip_date >= :from"; $params[':from']=$fFrom; }
if($fTo!==''){   $where[] = "trip_date <= :to";   $params[':to']=$fTo; }
$LWHERE = $where ? ('WHERE '.implode(' AND ',$where)) : '';

/* -----------------------------
   LOGISTICS KPIs
------------------------------*/
$kpiStmt = $pdo->prepare("
  SELECT
    COUNT(*)                          AS trips,
    COALESCE(SUM(distance_km),0)      AS km,
    COALESCE(SUM(fuel_liters),0)      AS liters,
    COALESCE(SUM(fuel_cost),0)        AS fuel_cost,
    COALESCE(SUM(deliveries_planned),0)    AS dp,
    COALESCE(SUM(deliveries_completed),0)  AS dc,
    COALESCE(SUM(on_time),0)               AS ot,
    COALESCE(SUM(delays),0)                AS delays
  FROM logistics_records
  $LWHERE
");
$kpiStmt->execute($params);
$kpi = $kpiStmt->fetch() ?: ['trips'=>0,'km'=>0,'liters'=>0,'fuel_cost'=>0,'dp'=>0,'dc'=>0,'ot'=>0,'delays'=>0];

$km_per_l   = ($kpi['liters']>0) ? round($kpi['km']/$kpi['liters'],2) : 0;
$completion = ($kpi['dp']>0) ? round(($kpi['dc']/$kpi['dp'])*100,1) : 0;
$ontime     = ($kpi['dc']>0) ? round(($kpi['ot']/$kpi['dc'])*100,1) : 0;

/* -----------------------------
   LOGISTICS CHART DATA
------------------------------*/
$tripsSeriesStmt = $pdo->prepare("
  SELECT trip_date AS d, COUNT(*) AS c
  FROM logistics_records
  $LWHERE
  GROUP BY trip_date
  ORDER BY trip_date
");
$tripsSeriesStmt->execute($params);
$tripsSeries = $tripsSeriesStmt->fetchAll();

$statusSeriesStmt = $pdo->prepare("
  SELECT validation_status AS s, COUNT(*) AS c
  FROM logistics_records
  $LWHERE
  GROUP BY validation_status
");
$statusSeriesStmt->execute($params);
$statusSeries = $statusSeriesStmt->fetchAll();

$delivAggStmt = $pdo->prepare("
  SELECT COALESCE(SUM(deliveries_planned),0) AS dp, COALESCE(SUM(deliveries_completed),0) AS dc
  FROM logistics_records
  $LWHERE
");
$delivAggStmt->execute($params);
$delivAgg = $delivAggStmt->fetch() ?: ['dp'=>0,'dc'=>0];

/* -----------------------------
   DOCUMENTS KPIs
------------------------------*/
$totalDocs = (int)$pdo->query("SELECT COUNT(*) FROM documents")->fetchColumn();
$pending   = (int)$pdo->query("SELECT COUNT(*) FROM documents WHERE status IN ('Draft','Submitted','Verified')")->fetchColumn();
$expSoon   = (int)$pdo->query("SELECT COUNT(*) FROM documents WHERE expiration_date IS NOT NULL AND expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
$expired   = (int)$pdo->query("SELECT COUNT(*) FROM documents WHERE expiration_date IS NOT NULL AND expiration_date < CURDATE()")->fetchColumn();

/* Documents charts */
$docsByStatus = $pdo->query("SELECT status s, COUNT(*) c FROM documents GROUP BY status")->fetchAll();
$docsOverTime = $pdo->query("SELECT DATE(created_at) d, COUNT(*) c FROM documents GROUP BY DATE(created_at) ORDER BY DATE(created_at)")->fetchAll();

/* -----------------------------
   RECENT TABLES (10 each)
------------------------------*/
$recentTrips = $pdo->query("
  SELECT r.id, r.trip_ref, r.driver_name, r.trip_date, r.origin, r.destination, r.distance_km, r.fuel_liters,
         r.deliveries_completed, r.deliveries_planned, r.validation_status,
         a.name AS asset_name
  FROM logistics_records r
  LEFT JOIN assets a ON a.id = r.asset_id
  ORDER BY r.trip_date DESC, r.id DESC
  LIMIT 10
")->fetchAll();

$recentDocs = $pdo->query("
  SELECT d.id, d.title, d.doc_type, d.doc_code, d.version, d.status, d.issue_date, d.expiration_date,
         a.name AS asset_name
  FROM documents d
  LEFT JOIN assets a ON a.id = d.asset_id
  ORDER BY d.updated_at DESC, d.id DESC
  LIMIT 10
")->fetchAll();

/* Topbar profile */
$userName = $_SESSION["user"]["name"] ?? "Nicole Malitao";
$userRole = $_SESSION["user"]["role"] ?? "Operations";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>TNVS | Dashboard</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="../css/style.css" rel="stylesheet" />
  <link href="../css/modules.css" rel="stylesheet" />
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1"></script>
  <script src="../js/sidebar-toggle.js"></script>

  <style>
    .badge.s-pending{background:#dbeafe;color:#1d4ed8}
    .badge.s-validated{background:#dcfce7;color:#065f46}
    .badge.s-rejected{background:#fee2e2;color:#991b1b}
    .badge.s-draft{background:#e5e7eb;color:#374151}
    .badge.s-submitted{background:#dbeafe;color:#1d4ed8}
    .badge.s-verified{background:#fef3c7;color:#92400e}
    .badge.s-approved{background:#dcfce7;color:#065f46}
    .badge.s-archived{background:#f3f4f6;color:#374151}
    .levels-scroll, .tx-scroll { max-height: 60vh; }
    .quick-btns .btn { padding: .25rem .5rem; font-size: .8rem; }
    .card-kpi .icon { width:38px; height:38px; display:flex; align-items:center; justify-content:center; border-radius:.6rem; }
  </style>
</head>
<body>
<div class="container-fluid p-0">
  <div class="row g-0">

    <?php include __DIR__ . '/../includes/sidebar.php' ?>

    <!-- Main Content -->
    <div class="col main-content p-3 p-lg-4">

      <!-- Topbar -->
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-3">
          <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2" aria-label="Toggle sidebar">
            <ion-icon name="menu-outline"></ion-icon>
          </button>
          <h2 class="m-0 d-flex align-items-center gap-2">
            <ion-icon name="speedometer-outline"></ion-icon> Dashboard
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

      <!-- Filters -->
      <section class="card shadow-sm mb-3">
        <div class="card-body">
          <form method="get" class="row g-2 align-items-end" id="filterForm">
            <div class="col-6 col-md-3">
              <label class="form-label small text-muted">From (Logistics)</label>
              <input type="date" name="from" id="fromDate" class="form-control" value="<?= h($fFrom) ?>">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label small text-muted">To (Logistics)</label>
              <input type="date" name="to" id="toDate" class="form-control" value="<?= h($fTo) ?>">
            </div>
            <div class="col-12 col-md-2 d-grid">
              <label class="form-label small text-muted">&nbsp;</label>
              <button class="btn btn-primary"><ion-icon name="funnel-outline"></ion-icon> Apply</button>
            </div>
            <div class="col-12 col-md-4 d-flex justify-content-end align-items-center quick-btns gap-2">
              <button type="button" class="btn btn-outline-secondary btn-sm" onclick="quickRange('today')">Today</button>
              <button type="button" class="btn btn-outline-secondary btn-sm" onclick="quickRange('week')">This Week</button>
              <button type="button" class="btn btn-outline-secondary btn-sm" onclick="quickRange('month')">This Month</button>
              <button type="button" class="btn btn-outline-secondary btn-sm" onclick="quickRange('quarter')">This Quarter</button>
            </div>
          </form>
        </div>
      </section>

      <!-- KPI Rows -->
      <div class="row g-3 mb-3">
        <!-- Logistics KPIs -->
        <div class="col-12 col-xl-12">
          <div class="row g-3">
            <div class="col-6 col-md-3">
              <div class="card shadow-sm h-100 card-kpi">
                <div class="card-body d-flex align-items-center gap-3">
                  <div class="icon bg-primary-subtle"><ion-icon name="trail-sign-outline"></ion-icon></div>
                  <div><div class="text-muted small">Trips</div><div class="h4 m-0"><?= (int)$kpi['trips'] ?></div></div>
                </div>
              </div>
            </div>
            <div class="col-6 col-md-3">
              <div class="card shadow-sm h-100 card-kpi">
                <div class="card-body d-flex align-items-center gap-3">
                  <div class="icon bg-info-subtle"><ion-icon name="map-outline"></ion-icon></div>
                  <div><div class="text-muted small">Distance (km)</div><div class="h4 m-0"><?= number_format((float)$kpi['km'],2) ?></div></div>
                </div>
              </div>
            </div>
            <div class="col-6 col-md-3">
              <div class="card shadow-sm h-100 card-kpi">
                <div class="card-body d-flex align-items-center gap-3">
                  <div class="icon bg-warning-subtle"><ion-icon name="speedometer-outline"></ion-icon></div>
                  <div><div class="text-muted small">Avg km/L</div><div class="h4 m-0"><?= number_format((float)$km_per_l,2) ?></div></div>
                </div>
              </div>
            </div>
            <div class="col-6 col-md-3">
              <div class="card shadow-sm h-100 card-kpi">
                <div class="card-body d-flex align-items-center gap-3">
                  <div class="icon bg-danger-subtle"><ion-icon name="flame-outline"></ion-icon></div>
                  <div><div class="text-muted small">Fuel (L)</div><div class="h4 m-0"><?= number_format((float)$kpi['liters'],2) ?></div></div>
                </div>
              </div>
            </div>
          </div>
        </div>
      
        <div class="row g-3 mb-3">

        <!-- Document KPIs -->
        <div class="col-12 col-xl-12">
          <div class="row g-3">
            <div class="col-6 col-md-6">
              <div class="card shadow-sm h-100 card-kpi">
                <div class="card-body d-flex align-items-center gap-3">
                  <div class="icon bg-primary-subtle"><ion-icon name="documents-outline"></ion-icon></div>
                  <div><div class="text-muted small">Total Docs</div><div class="h4 m-0"><?= (int)$totalDocs ?></div></div>
                </div>
              </div>
            </div>
            <div class="col-6 col-md-6">
              <div class="card shadow-sm h-100 card-kpi">
                <div class="card-body d-flex align-items-center gap-3">
                  <div class="icon bg-info-subtle"><ion-icon name="alert-circle-outline"></ion-icon></div>
                  <div><div class="text-muted small">Expiring 30d</div><div class="h4 m-0"><?= (int)$expSoon ?></div></div>
                </div>
              </div>
            </div>
            

      </div>
      <div class="row g-3 mb-3">

      <!-- Charts -->
      <div class="row g-3 mb-3">
        <div class="col-12 col-lg-6">
          <div class="card shadow-sm">
            <div class="card-body">
              <h6 class="mb-2">Trips Over Time</h6>
              <canvas id="chTrips"></canvas>
            </div>
          </div>
        </div>

        

        <div class="col-12 col-lg-6">
          <div class="card shadow-sm">
            <div class="card-body">
              <h6 class="mb-2">Deliveries (Planned vs Completed)</h6>
              <canvas id="chDeliveries"></canvas>
            </div>
          </div>
        </div>
      </div>

      <div class="col-12 col-lg-5">
          <div class="card shadow-sm">
            <div class="card-body">
              <h6 class="mb-2">Logistics Status</h6>
              <canvas id="chStatus"></canvas>
            </div>
          </div>
        </div>

      <div class="row g-3 mb-3">
        <div class="col-12 col-lg-7">
          <div class="card shadow-sm">
            <div class="card-body">
              <h6 class="mb-2">Documents Created Over Time</h6>
              <canvas id="chDocsTime"></canvas>
            </div>
          </div>
        </div>
        <div class="col-12 col-lg-5">
          <div class="card shadow-sm">
            <div class="card-body">
              <h6 class="mb-2">Documents by Status</h6>
              <canvas id="chDocsStatus"></canvas>
            </div>
          </div>
        </div>
      </div>

      <!-- Recent Activity -->
      <div class="row g-3">
        <div class="col-12 col-xl-6">
          <div class="card shadow-sm">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h5 class="m-0">Recent Trips</h5>
                <a href="logistic.php" class="btn btn-sm btn-outline-secondary">View all</a>
              </div>
              <div class="table-responsive">
                <table class="table align-middle">
                  <thead><tr>
                    <th>#</th><th>Trip / Asset</th><th>Driver / Date</th><th>Route</th><th>Perf.</th><th>Fuel</th><th>Status</th>
                  </tr></thead>
                  <tbody>
                  <?php if(!$recentTrips): ?>
                    <tr><td colspan="7" class="text-center text-muted py-3">No trips yet.</td></tr>
                  <?php else: foreach($recentTrips as $t): ?>
                    <tr>
                      <td class="text-muted">#<?= (int)$t['id'] ?></td>
                      <td><div class="fw-semibold"><?= h($t['trip_ref'] ?: '—') ?></div><div class="small text-muted"><?= h($t['asset_name'] ?: '—') ?></div></td>
                      <td><div><?= h($t['driver_name'] ?: '—') ?></div><div class="small text-muted"><?= h($t['trip_date']) ?></div></td>
                      <td><?= h($t['origin'] ?: '—') ?> → <?= h($t['destination'] ?: '—') ?></td>
                      <td class="small">Done/Plan: <?= (int)$t['deliveries_completed'] ?>/<?= (int)$t['deliveries_planned'] ?></td>
                      <td class="small"><?= number_format((float)$t['fuel_liters'],2) ?> L</td>
                      <td><span class="badge <?= 's-'.strtolower($t['validation_status']) ?>"><?= h($t['validation_status']) ?></span></td>
                    </tr>
                  <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <div class="col-12 col-xl-6">
          <div class="card shadow-sm">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h5 class="m-0">Recent Documents</h5>
                <a href="document.php" class="btn btn-sm btn-outline-secondary">View all</a>
              </div>
              <div class="table-responsive">
                <table class="table align-middle">
                  <thead><tr>
                    <th>#</th><th>Title / Type</th><th>Linked</th><th>Version</th><th>Issue / Expiry</th><th>Status</th>
                  </tr></thead>
                  <tbody>
                  <?php if(!$recentDocs): ?>
                    <tr><td colspan="6" class="text-center text-muted py-3">No documents yet.</td></tr>
                  <?php else: foreach($recentDocs as $d): ?>
                    <tr>
                      <td class="text-muted">#<?= (int)$d['id'] ?></td>
                      <td><div class="fw-semibold"><?= h($d['title']) ?></div><div class="small text-muted"><?= h($d['doc_type']) ?><?= $d['doc_code']? ' · '.h($d['doc_code']) : '' ?></div></td>
                      <td class="small"><?= h($d['asset_name'] ?: '—') ?></td>
                      <td>v<?= (int)$d['version'] ?></td>
                      <td class="small"><?= h($d['issue_date'] ?: '—') ?><br><?= h($d['expiration_date'] ?: '—') ?></td>
                      <td><span class="badge <?= 's-'.strtolower($d['status']) ?>"><?= h($d['status']) ?></span></td>
                    </tr>
                  <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

      </div>

    </div>
  </div>
</div>

<script>
const tripsSeries = <?= json_encode($tripsSeries) ?>;
const statusSeries = <?= json_encode($statusSeries) ?>;
const delivAgg = <?= json_encode($delivAgg) ?>;
const docsStatus = <?= json_encode($docsByStatus) ?>;
const docsOver = <?= json_encode($docsOverTime) ?>;

function lineChart(el, labels, data, label){
  return new Chart(el, {
    type:'line',
    data:{ labels, datasets:[{ label, data, borderColor:'#4f46e5', backgroundColor:'rgba(79,70,229,.15)', tension:.3, fill:true }]},
    options:{ plugins:{legend:{display:false}}, scales:{x:{grid:{display:false}}, y:{beginAtZero:true}}}
  });
}
function doughnut(el, labels, data, colors){
  return new Chart(el, {
    type:'doughnut',
    data:{ labels, datasets:[{ data, backgroundColor: colors }]},
    options:{ plugins:{legend:{position:'bottom'}}}
  });
}
function bar(el, labels, data){
  return new Chart(el, {
    type:'bar',
    data:{ labels, datasets:[{ data, backgroundColor:'#0ea5e9' }]},
    options:{ plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}}}
  });
}

// Trips over time
lineChart(document.getElementById('chTrips'),
  tripsSeries.map(x=>x.d),
  tripsSeries.map(x=>Number(x.c)||0),
  'Trips'
);

// Logistics status
doughnut(document.getElementById('chStatus'),
  statusSeries.map(x=>x.s),
  statusSeries.map(x=>Number(x.c)||0),
  ['#4f46e5','#22c55e','#ef4444','#f59e0b','#06b6d4','#a78bfa']
);

// Deliveries planned vs completed
bar(document.getElementById('chDeliveries'),
  ['Planned','Completed'],
  [Number(delivAgg.dp)||0, Number(delivAgg.dc)||0]
);

// Docs created over time
lineChart(document.getElementById('chDocsTime'),
  docsOver.map(x=>x.d),
  docsOver.map(x=>Number(x.c)||0),
  'Documents'
);

// Docs by status
doughnut(document.getElementById('chDocsStatus'),
  docsStatus.map(x=>x.s),
  docsStatus.map(x=>Number(x.c)||0),
  ['#4f46e5','#22c55e','#ef4444','#f59e0b','#06b6d4','#a78bfa']
);

/* Quick date helpers */
function fmt(d){ const z=n=>String(n).padStart(2,'0'); return `${d.getFullYear()}-${z(d.getMonth()+1)}-${z(d.getDate())}`; }
function getMonday(d){ d=new Date(d); const day=d.getDay(); const diff=(day===0?-6:1)-day; d.setDate(d.getDate()+diff); return d; }
function quickRange(type){
  const from=document.getElementById('fromDate');
  const to=document.getElementById('toDate');
  const now=new Date(); let a,b;
  if(type==='today'){a=new Date(now); b=new Date(now);}
  if(type==='week'){a=getMonday(now); b=new Date(a); b.setDate(a.getDate()+6);}
  if(type==='month'){a=new Date(now.getFullYear(), now.getMonth(), 1); b=new Date(now.getFullYear(), now.getMonth()+1, 0);}
  if(type==='quarter'){const q=Math.floor(now.getMonth()/3); a=new Date(now.getFullYear(), q*3, 1); b=new Date(now.getFullYear(), q*3+3, 0);}
  from.value=fmt(a); to.value=fmt(b); document.getElementById('filterForm').submit();
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
