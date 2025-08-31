<?php
// ass2.php — Asset Report for Asset Tracking (ass1.php)
// Real-time ready: summary stats, chart, filters, table view, CSV export, version polling, cross-tab updates

// DB CONFIG
$DB_HOST = '127.0.0.1';
$DB_NAME = 'alms_db';
$DB_USER = 'root';
$DB_PASS = '';

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'DB connection failed';
    exit;
}

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
        // Return a monotonic version so clients can detect changes without fetching full data
        // Prefer updated_at if exists; otherwise fallback to max(installed_on/disposed_on) and count
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
<title>Asset Report — Asset Tracking</title>
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root{
  --bg: #f5f7fb;
  --card: #ffffff;
  --accent: #0f62fe;
  --muted: #6b7280;
  --text: #111827;
  --shadow-lg: 0 10px 30px rgba(16,24,40,0.08);
  --shadow-sm: 0 4px 12px rgba(16,24,40,0.06);
  --success: #10b981; --danger: #ef4444; --warning: #f59e0b;
}
*{box-sizing:border-box}
html,body{height:100%}
body{margin:0;font-family:Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial;background:linear-gradient(180deg,#f7f9fc 0%,var(--bg) 100%);color:var(--text);-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale;padding:24px}
.container{max-width:1200px;margin:0 auto}
.header{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:18px}
.btn{display:inline-flex;align-items:center;gap:8px;background:#6d28d9;color:#fff;border:0;padding:10px 14px;border-radius:10px;box-shadow:var(--shadow-sm);cursor:pointer;font-weight:600;font-size:14px;text-decoration:none}
.btn.ghost{background:transparent;color:#6d28d9;border:1px solid rgba(15,98,254,0.2)}
.card{background:var(--card);padding:16px;border-radius:12px;box-shadow:var(--shadow-lg)}
.grid{display:grid;grid-template-columns:1fr 360px;gap:16px}
@media (max-width:1000px){.grid{grid-template-columns:1fr}}
.stats{display:flex;gap:12px;flex-wrap:wrap}
.stat{flex:1;min-width:140px;padding:12px;border-radius:10px;background:linear-gradient(180deg,#ffffff,#fbfdff);border:1px solid rgba(14,165,233,0.06)}
.stat .label{font-size:12px;color:var(--muted)}
.stat .number{font-size:20px;font-weight:700}
.input, .select{padding:10px 12px;border-radius:10px;border:1px solid #e6edf6;background:transparent;font-size:14px;color:var(--text)}
.table-wrap{overflow:auto;border-radius:10px;border:1px solid rgba(17,24,39,0.06)}
.table{width:100%;border-collapse:collapse;min-width:820px}
.table thead th{text-align:left;padding:12px 14px;background:linear-gradient(180deg,#fbfdff,#f7f9fc);font-size:13px;color:var(--muted);border-bottom:1px solid rgba(15,23,42,0.06)}
.table tbody td{padding:12px 14px;border-bottom:1px solid rgba(15,23,42,0.06);font-size:14px}
.badge{display:inline-block;padding:6px 10px;border-radius:999px;font-weight:600;font-size:12px}
.status-installed{background:rgba(16,185,129,0.08);color:var(--success)}
.status-inuse{background:rgba(59,130,246,0.08);color:#2563eb}
.status-disposed{background:rgba(239,68,68,0.08);color:var(--danger)}
tr.highlight { box-shadow: inset 0 0 0 9999px rgba(255,221,87,0.28); }
</style>
</head>
<body>
<div class="container">
  <header class="header">
    <div style="display:flex;gap:8px;align-items:center">
      <a href="ALMS.php" class="btn ghost"><i class='bx bx-arrow-back'></i> Back</a>
      <a href="ass1.php" class="btn ghost"><i class='bx bx-package'></i> Assets</a>
    </div>
    <div>
      <h2 style="margin:0;display:flex;align-items:center;gap:8px"><i class='bx bx-pie-chart-alt-2'></i> Asset Report</h2>
      <div style="font-size:13px;color:var(--muted)">Summary • Filters • CSV Export</div>
    </div>
    <div style="display:flex;gap:8px;align-items:center">
      <button class="btn" id="exportCsv"><i class='bx bx-download'></i> Export CSV</button>
      <button class="btn ghost" id="printBtn"><i class='bx bx-printer'></i> Print</button>
    </div>
  </header>

  <main class="grid">
    <section>
      <div class="card">
        <div style="display:flex;justify-content:space-between;gap:18px;align-items:center;flex-wrap:wrap">
          <div style="flex:1;min-width:320px">
            <div class="stats">
              <div class="stat"><div class="label">Total</div><div id="statTotal" class="number">—</div></div>
              <div class="stat"><div class="label">Age (avg days)</div><div id="statAgeAvg" class="number">—</div></div>
              <div class="stat"><div class="label">Oldest (days)</div><div id="statAgeMax" class="number">—</div></div>
            </div>
          </div>
          <div style="min-width:260px">
            <canvas id="statusChart" height="90"></canvas>
          </div>
        </div>
      </div>

      <div class="card" style="margin-top:14px">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <select id="filterStatus" class="select">
              <option value="">All statuses</option>
              <option value="Installed">Installed</option>
              <option value="In Use">In Use</option>
              <option value="Disposed">Disposed</option>
            </select>
            <input id="filterFrom" type="date" class="input" title="Installed from">
            <input id="filterTo" type="date" class="input" title="Installed to">
            <input id="filterQ" class="input" placeholder="Search name">
            <button class="btn" id="applyFilters"><i class='bx bx-filter'></i> Apply</button>
          </div>
          <div style="font-size:12px;color:var(--muted)">Showing up to 1000 rows</div>
        </div>

        <div class="table-wrap" style="margin-top:10px">
          <table class="table" id="assetTable">
            <thead>
              <tr>
                <th style="width:64px">ID</th>
                <th>Name</th>
                <th style="width:120px">Status</th>
                <th style="width:140px">Installed On</th>
                <th style="width:140px">Disposed On</th>
                <th style="width:100px">Age (days)</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </section>

    <aside>
      <div class="card">
        <div style="font-size:13px;color:var(--muted);margin-bottom:6px">Tips</div>
        <ul style="margin:0 0 0 18px;padding:0;color:#374151;font-size:13px;line-height:1.6">
          <li>Use filters to restrict the report and then export CSV.</li>
          <li>Age is computed from Installed On to Disposed On (or today).</li>
          <li>The report auto-refreshes when assets change.</li>
        </ul>
      </div>
    </aside>
  </main>
</div>

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
