<?php
require_once __DIR__ . "/../includes/config.php";
require_once __DIR__ . "/../includes/auth.php";
require_login();

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$UPLOAD_DIR = __DIR__ . '/uploads/';
$UPLOAD_URL = 'uploads/';

// Ensure assets table exists (id, name, status, installed_on, disposed_on) and add audit columns
$pdo->exec("CREATE TABLE IF NOT EXISTS assets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  status VARCHAR(50) NOT NULL,
  installed_on DATE,
  disposed_on DATE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Try to add created_at / updated_at columns when missing (ignore if already exist)
try { $pdo->exec("ALTER TABLE assets ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE assets ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch (Throwable $e) {}

function jsonOut($v){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($v); exit; }
function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

// Helper: build WHERE from filters
function buildWhere(array $src, array &$params): string {
    $where = [];
    if (!empty($src['status'])) { $where[] = 'status = :status'; $params[':status'] = $src['status']; }
    if (!empty($src['q'])) { $where[] = 'name LIKE :q'; $params[':q'] = '%'.$src['q'].'%'; }
    if (!empty($src['from'])) { $where[] = 'installed_on >= :from'; $params[':from'] = $src['from']; }
    if (!empty($src['to'])) { $where[] = 'installed_on <= :to'; $params[':to'] = $src['to']; }
    return $where ? ('WHERE '.implode(' AND ', $where)) : '';
}

// Router for AJAX/CSV actions
if (isset($_GET['action'])) {
    $action = $_GET['action'];

    if ($action === 'overview') {
        $params = [];
        $where = buildWhere($_GET, $params);
        $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM assets $where");
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $stmt2 = $pdo->prepare("SELECT status, COUNT(*) AS cnt FROM assets $where GROUP BY status");
        $stmt2->execute($params);
        $byStatus = [];
        foreach ($stmt2 as $r) { $byStatus[$r['status']] = (int)$r['cnt']; }

        $qAging = 'SELECT installed_on, COALESCE(disposed_on, CURRENT_DATE()) AS end_date FROM assets ';
        $qAging .= $where ? ($where . ' AND installed_on IS NOT NULL') : 'WHERE installed_on IS NOT NULL';
        $stmt3 = $pdo->prepare($qAging);
        $stmt3->execute($params);
        $ages = [];
        foreach ($stmt3 as $r) {
            if ($r['installed_on']) {
                $start = new DateTime($r['installed_on']);
                $end = new DateTime($r['end_date']);
                $ages[] = (int)$start->diff($end)->format('%a');
            }
        }
        $ageStats = ['count'=>count($ages),'min'=>null,'max'=>null,'avg'=>null];
        if ($ages) { $ageStats['min']=min($ages); $ageStats['max']=max($ages); $ageStats['avg']=round(array_sum($ages)/count($ages)); }

        jsonOut(['success'=>true,'data'=>[
            'total'=>$total,
            'by_status'=>$byStatus,
            'age'=>$ageStats
        ]]);
    }

    if ($action === 'list') {
        $params = [];
        $where = buildWhere($_GET, $params);
        $sql = "SELECT id, name, status, installed_on, disposed_on FROM assets $where ORDER BY id DESC LIMIT 1000";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        jsonOut(['success'=>true,'data'=>$rows]);
    }

    if ($action === 'report') {
        // CSV export for current filters
        $params = [];
        $where = buildWhere($_GET, $params);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=asset_report_' . date('Ymd_His') . '.csv');
        $out = fopen('php://output','w');
        fputcsv($out, ['id','name','status','installed_on','disposed_on','age_days']);
        $stmt = $pdo->prepare("SELECT id, name, status, installed_on, disposed_on FROM assets $where ORDER BY id DESC");
        $stmt->execute($params);
        while ($r = $stmt->fetch()) {
            $age = '';
            if (!empty($r['installed_on'])) {
                $start = new DateTime($r['installed_on']);
                $end = new DateTime(!empty($r['disposed_on']) ? $r['disposed_on'] : date('Y-m-d'));
                $age = (int)$start->diff($end)->format('%a');
            }
            fputcsv($out, [$r['id'], $r['name'], $r['status'], $r['installed_on'], $r['disposed_on'], $age]);
        }
        fclose($out);
        exit;
    }

    if ($action === 'version') {
        // Monotonic version for change detection
        $version = 0; $count = 0;
        try {
            $stmt = $pdo->query("SELECT UNIX_TIMESTAMP(MAX(updated_at)) AS v, COUNT(*) AS c FROM assets");
            $row = $stmt->fetch();
            $version = (int)($row['v'] ?? 0);
            $count = (int)($row['c'] ?? 0);
        } catch (Throwable $e) {
            $stmt = $pdo->query("SELECT UNIX_TIMESTAMP(MAX(GREATEST(COALESCE(disposed_on, '1970-01-01'), COALESCE(installed_on,'1970-01-01')))) AS v, COUNT(*) AS c FROM assets");
            $row = $stmt->fetch();
            $version = (int)($row['v'] ?? 0);
            $count = (int)($row['c'] ?? 0);
        }
        jsonOut(['success'=>true, 'v'=>$version, 'c'=>$count]);
    }

    jsonOut(['success'=>false,'msg'=>'Unknown action']);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Asset Report | ALMS</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
<link href="../css/style.css" rel="stylesheet" />
<link href="../css/modules.css" rel="stylesheet" />

<script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
<script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
<script src="../js/sidebar-toggle.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>

<style>
  /* Keep typography identical to Repair Logs (Bootstrap defaults) */
  body { background: #f7f9fc; }

  /* KPI cards match Repair Logs */
  .kpi .card-body{display:flex;align-items:center;gap:.75rem}
  .kpi .icon-wrap{width:42px;height:42px;display:flex;align-items:center;justify-content:center;border-radius:12px}
  .stat .label{font-size:.875rem;color:#6b7280}
  .stat .number{font-weight:600}

  /* Filters row spacing */
  .filter-row .form-label{font-size:.8rem;color:#6b7280}

  /* Table highlight (used by other pages too) */
  tr.highlight { box-shadow: inset 0 0 0 9999px rgba(255,221,87,0.28); }

  /* Status badges for table */
  .status-installed{background:rgba(16,185,129,.1) !important;color:#0f766e}
  .status-inuse{background:rgba(59,130,246,.12) !important;color:#1d4ed8}
  .status-disposed{background:rgba(239,68,68,.12) !important;color:#b91c1c}
</style>
</head>
<body>
<div class="container-fluid p-0">
  <div class="row g-0">

    <!-- Sidebar (identical shell) -->
    <div class="sidebar d-flex flex-column">
      <div class="d-flex justify-content-center align-items-center mb-4 mt-3">
        <img src="../img/logo.png" id="logo" class="img-fluid me-2" style="height:55px" alt="Logo">
      </div>

      <h6 class="text-uppercase mb-2">Asset Lifecycle &amp; Maintenance</h6>

      <nav class="nav flex-column px-2 mb-4">
        <a class="nav-link" href="ALMS.php"><ion-icon name="home-outline"></ion-icon><span>Dashboard</span></a>
        <a class="nav-link" href="./assetTracker.php"><ion-icon name="cube-outline"></ion-icon><span>Asset Tracking</span></a>
        <a class="nav-link" href="./mainReq.php"><ion-icon name="layers-outline"></ion-icon><span>Maintenance Requests</span></a>
        <a class="nav-link" href="./repair.php"><ion-icon name="hammer-outline"></ion-icon><span>Repair Logs</span></a>
        <a class="nav-link active" href="./reports.php"><ion-icon name="file-tray-stacked-outline"></ion-icon><span>Reports</span></a>
        <a class="nav-link" href="./settings.php"><ion-icon name="settings-outline"></ion-icon><span>Settings</span></a>
      </nav>

      <div class="logout-section mt-auto">
        <a class="nav-link text-danger" href="<?= BASE_URL ?>/auth/logout.php">
          <ion-icon name="log-out-outline"></ion-icon> Logout
        </a>
      </div>
    </div>

    <!-- Main -->
    <div class="col main-content p-3 p-lg-4">

      <!-- Topbar (matches Repair Logs spacing/buttons) -->
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-3">
          <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2" aria-label="Toggle sidebar">
            <ion-icon name="menu-outline"></ion-icon>
          </button>
          <h2 class="m-0 d-flex align-items-center gap-2">
            <ion-icon name="pie-chart-outline"></ion-icon> Asset Report
          </h2>
        </div>
        <div class="d-flex gap-2">
          <button class="btn btn-violet" id="exportCsv"><ion-icon name="download-outline"></ion-icon> Export CSV</button>
          <button class="btn btn-outline-secondary" id="printBtn"><ion-icon name="print-outline"></ion-icon> Print</button>
        </div>
      </div>

      <!-- Stats + Chart (same card style) -->
      <section class="card shadow-sm mb-3">
        <div class="card-body">
          <div class="row g-3 align-items-center">
            <div class="col-12 col-lg-8">
              <div class="row g-3">
                <div class="col-6 col-md-4">
                  <div class="card kpi h-100 shadow-sm">
                    <div class="card-body">
                      <div class="icon-wrap bg-primary-subtle"><ion-icon name="albums-outline"></ion-icon></div>
                      <div class="stat">
                        <div class="label">Total</div>
                        <div id="statTotal" class="number h4 m-0">—</div>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="col-6 col-md-4">
                  <div class="card kpi h-100 shadow-sm">
                    <div class="card-body">
                      <div class="icon-wrap bg-info-subtle"><ion-icon name="hourglass-outline"></ion-icon></div>
                      <div class="stat">
                        <div class="label">Age (avg days)</div>
                        <div id="statAgeAvg" class="number h4 m-0">—</div>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="col-6 col-md-4">
                  <div class="card kpi h-100 shadow-sm">
                    <div class="card-body">
                      <div class="icon-wrap bg-warning-subtle"><ion-icon name="trending-up-outline"></ion-icon></div>
                      <div class="stat">
                        <div class="label">Oldest (days)</div>
                        <div id="statAgeMax" class="number h4 m-0">—</div>
                      </div>
                    </div>
                  </div>
                </div>
              </div><!-- /row -->
            </div>
            <div class="col-12 col-lg-4">
              <canvas id="statusChart" height="120"></canvas>
            </div>
          </div>
        </div>
      </section>

      <!-- Filters + Table -->
      <section class="card shadow-sm">
        <div class="card-body">
          <!-- Filters -->
          <div class="row g-2 align-items-end filter-row">
            <div class="col-12 col-md-3">
              <label class="form-label">Status</label>
              <select id="filterStatus" class="form-select">
                <option value="">All statuses</option>
                <option value="Installed">Installed</option>
                <option value="In Use">In Use</option>
                <option value="Disposed">Disposed</option>
              </select>
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label">Installed From</label>
              <input id="filterFrom" type="date" class="form-control" title="Installed from">
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label">Installed To</label>
              <input id="filterTo" type="date" class="form-control" title="Installed to">
            </div>
            <div class="col-12 col-md-3">
              <label class="form-label">Search</label>
              <input id="filterQ" class="form-control" placeholder="Search name">
            </div>
            <div class="col-12 col-md-2 d-grid d-md-flex justify-content-md-end">
              <button class="btn btn-outline-primary" id="applyFilters">
                <ion-icon name="filter-outline"></ion-icon> Apply
              </button>
            </div>
          </div>

          <!-- Table -->
          <div class="table-responsive mt-3">
            <table class="table align-middle" id="assetTable">
              <thead>
                <tr>
                  <th style="width:64px">ID</th>
                  <th>Name</th>
                  <th style="width:120px">Status</th>
                  <th style="width:140px">Installed On</th>
                  <th style="width:140px">Disposed On</th>
                  <th style="width:120px">Age (days)</th>
                </tr>
              </thead>
              <tbody></tbody>
            </table>
          </div>

          <div class="d-flex justify-content-between align-items-center mt-2">
            <div class="small text-muted">Showing up to 1000 rows</div>
          </div>
        </div>
      </section>

    </div><!-- /main -->
  </div><!-- /row -->
</div><!-- /container -->

<script>
async function api(action, data=null){
  const url = new URL(window.location.href);
  url.searchParams.set('action', action);
  const params = getFilters();
  Object.keys(params).forEach(k=>{ if(params[k]) url.searchParams.set(k, params[k]); else url.searchParams.delete(k); });
  if (data && typeof data === 'object') Object.keys(data).forEach(k=>url.searchParams.set(k,data[k]));
  const res = await fetch(url.toString());
  return res.json();
}

function getFilters(){
  return {
    status: document.getElementById('filterStatus').value,
    from: document.getElementById('filterFrom').value,
    to: document.getElementById('filterTo').value,
    q: document.getElementById('filterQ').value.trim()
  };
}

let statusChart = null;

async function loadOverview(){
  const r = await api('overview');
  if (!r.success) return;
  document.getElementById('statTotal').textContent = r.data.total ?? 0;
  document.getElementById('statAgeAvg').textContent = r.data.age && r.data.age.avg !== null ? r.data.age.avg : '—';
  document.getElementById('statAgeMax').textContent = r.data.age && r.data.age.max !== null ? r.data.age.max : '—';

  const labels = Object.keys(r.data.by_status || {});
  const values = Object.values(r.data.by_status || {});
  const colors = ['#10b981','#3b82f6','#ef4444','#f59e0b','#06b6d4'];
  const ctx = document.getElementById('statusChart').getContext('2d');
  if (statusChart) statusChart.destroy();
  statusChart = new Chart(ctx, {
    type: 'pie',
    data: { labels, datasets: [{ data: values, backgroundColor: colors.slice(0, values.length) }]},
    options: { plugins:{legend:{position:'bottom',labels:{boxWidth:10}}}, responsive:true, maintainAspectRatio:false }
  });
}

function ageDays(installed_on, disposed_on){
  if (!installed_on) return '';
  const s = new Date(installed_on);
  const e = disposed_on ? new Date(disposed_on) : new Date();
  const diff = Math.round((e - s) / (1000*60*60*24));
  return diff >= 0 ? diff : '';
}

function esc(s){ return String(s||'').replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

async function loadTable(){
  const r = await api('list');
  if (!r.success) return;
  const tb = document.querySelector('#assetTable tbody');
  tb.innerHTML = '';
  const highlightId = new URL(window.location.href).searchParams.get('highlight_id');
  r.data.forEach(row => {
    const tr = document.createElement('tr');
    if (highlightId && String(row.id) === String(highlightId)) tr.classList.add('highlight');
    const sClass = row.status === 'Installed' ? 'status-installed' : (row.status === 'Disposed' ? 'status-disposed' : 'status-inuse');
    tr.innerHTML = `
      <td>${row.id}</td>
      <td>${esc(row.name)}</td>
      <td><span class="badge ${sClass}">${esc(row.status)}</span></td>
      <td>${row.installed_on||''}</td>
      <td>${row.disposed_on||''}</td>
      <td>${ageDays(row.installed_on,row.disposed_on)}</td>
    `;
    tb.appendChild(tr);
  });
  const hi = document.querySelector('tr.highlight');
  if (hi) hi.scrollIntoView({behavior:'smooth', block:'center'});
}

// Filters
document.getElementById('applyFilters').addEventListener('click', ()=>{ loadOverview(); loadTable(); });

// Export CSV includes filters
function buildCsvUrl(){
  const url = new URL(window.location.href);
  url.searchParams.set('action','report');
  const f = getFilters();
  Object.keys(f).forEach(k=>{ if(f[k]) url.searchParams.set(k,f[k]); else url.searchParams.delete(k); });
  return url.toString();
}

document.getElementById('exportCsv').addEventListener('click', ()=>{ window.location.href = buildCsvUrl(); });

document.getElementById('printBtn').addEventListener('click', ()=>{ window.print(); });

// Real-time: poll server version and refresh when changed
let lastVersion = null, lastCount = null;
async function checkVersion(){
  try {
    const r = await api('version');
    if (!r.success) return;
    if (lastVersion === null) { lastVersion = r.v; lastCount = r.c; return; }
    if (r.v !== lastVersion || r.c !== lastCount) {
      lastVersion = r.v; lastCount = r.c; loadOverview(); loadTable();
    }
  } catch(e){ /* ignore */ }
}
setInterval(checkVersion, 10000); // 10s

// Cross-tab instant updates: listen to storage events (emitted by ass1.php after changes)
window.addEventListener('storage', function(e){
  if (e.key === 'assets_changed') { loadOverview(); loadTable(); }
});

(async function init(){
  await loadOverview();
  await loadTable();
  await checkVersion();
})();
</script>
</body>
</html>
