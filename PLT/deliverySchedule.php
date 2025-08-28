<?php
// PLT/deliverySchedule.php
$inc = __DIR__ . '/../includes';
if (file_exists($inc . '/config.php')) require_once $inc . '/config.php';
if (file_exists($inc . '/auth.php'))  require_once $inc . '/auth.php';
if (function_exists('require_login')) require_login();

$userName = 'Admin'; $userRole = 'System Admin';
if (function_exists('current_user')) {
  $u = current_user(); $userName = $u['name'] ?? $userName; $userRole = $u['role'] ?? $userRole;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Delivery Schedule | PLT</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="../css/style.css" rel="stylesheet" />
  <link href="../css/modules.css" rel="stylesheet" />
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <script src="../js/sidebar-toggle.js"></script>
  <style>
    .chip{font-size:.75rem;padding:.15rem .5rem;border-radius:.5rem;background:#f3f3f3}
    .route{font-size:.9rem;color:#555}
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
        <a class="nav-link" href="./pltDashboard.php"><ion-icon name="home-outline"></ion-icon><span>Dashboard</span></a>
        <a class="nav-link" href="./shipmentTracker.php"><ion-icon name="trail-sign-outline"></ion-icon><span>Shipment Tracker</span></a>
        <a class="nav-link" href="./projectTracking.php"><ion-icon name="briefcase-outline"></ion-icon><span>Project Tracking</span></a>
        <a class="nav-link active" href="./deliverySchedule.php"><ion-icon name="calendar-outline"></ion-icon><span>Delivery Schedule</span></a>
        <a class="nav-link" href="./pltReports.php"><ion-icon name="analytics-outline"></ion-icon><span>Reports</span></a>
        <a class="nav-link" href="./pltSettings.php"><ion-icon name="settings-outline"></ion-icon><span>Settings</span></a>
      </nav>
      <div class="logout-section">
        <a class="nav-link text-danger" href="<?= defined('BASE_URL') ? BASE_URL : '#' ?>/auth/logout.php">
          <ion-icon name="log-out-outline"></ion-icon> Logout
        </a>
      </div>
    </div>

    <!-- Main -->
    <div class="col main-content p-3 p-lg-4">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="m-0">Delivery Schedule</h2>
        <div class="d-flex align-items-center gap-2">
          <img src="../img/profile.jpg" class="rounded-circle" width="36" height="36" alt="">
          <div class="small">
            <strong><?= htmlspecialchars($userName) ?></strong><br/>
            <span class="text-muted"><?= htmlspecialchars($userRole) ?></span>
          </div>
        </div>
      </div>

      <!-- Stat cards -->
      <div class="row g-3 mb-3">
        <div class="col-6 col-md-3"><div class="card shadow-sm"><div class="card-body">
          <div class="text-muted small">Today</div><div class="fs-3 fw-bold" id="kToday">0</div>
        </div></div></div>
        <div class="col-6 col-md-3"><div class="card shadow-sm"><div class="card-body">
          <div class="text-muted small">Tomorrow</div><div class="fs-3 fw-bold" id="kTomorrow">0</div>
        </div></div></div>
        <div class="col-6 col-md-3"><div class="card shadow-sm"><div class="card-body">
          <div class="text-muted small">This Week</div><div class="fs-3 fw-bold" id="kWeek">0</div>
        </div></div></div>
        <div class="col-6 col-md-3"><div class="card shadow-sm"><div class="card-body">
          <div class="text-muted small">Delivered (7d)</div><div class="fs-3 fw-bold" id="kDel7">0</div>
        </div></div></div>
      </div>

      <!-- Filters -->
      <section class="card shadow-sm mb-3">
        <div class="card-body">
          <div class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
              <label class="form-label small text-muted">From</label>
              <input type="date" id="fFrom" class="form-control">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label small text-muted">To</label>
              <input type="date" id="fTo" class="form-control">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label small text-muted">Status</label>
              <select id="fStatus" class="form-select">
                <option value="">All</option>
                <option value="planned">Planned</option>
                <option value="picked">Picked</option>
                <option value="in_transit">In-Transit</option>
                <option value="delivered">Delivered</option>
                <option value="cancelled">Cancelled</option>
              </select>
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label small text-muted">Search</label>
              <input id="fSearch" class="form-control" placeholder="Shipment No, Project, Origin/Destination">
            </div>
            <div class="col-12 col-md-3">
              <label class="form-label small text-muted">Sort</label>
              <select id="fSort" class="form-select">
                <option value="schedule" selected>Schedule Date</option>
                <option value="eta">ETA</option>
                <option value="newest">Newest</option>
              </select>
            </div>
            <div class="col-12 col-md-9 d-grid d-md-flex justify-content-md-end">
              <button id="btnApply" class="btn btn-outline-primary me-md-2">
                <ion-icon name="search-outline"></ion-icon> Apply
              </button>
              <button id="btnReset" class="btn btn-outline-secondary">Reset</button>
            </div>
          </div>
        </div>
      </section>

      <!-- List -->
      <section class="card shadow-sm">
        <div class="card-body">
          <h5 class="mb-3">Scheduled Deliveries</h5>
          <div class="table-responsive">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th>Schedule</th>
                  <th>Shipment No</th>
                  <th>Project</th>
                  <th>Route</th>
                  <th>Vehicle / Driver</th>
                  <th>ETA</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody id="tblBody"><tr><td colspan="7" class="text-center py-4">Loading…</td></tr></tbody>
            </table>
          </div>
          <div class="d-flex justify-content-between align-items-center mt-2">
            <div class="small text-muted" id="pageInfo"></div>
            <nav><ul class="pagination pagination-sm mb-0" id="pager"></ul></nav>
          </div>
        </div>
      </section>
    </div><!-- /main -->
  </div><!-- /row -->
</div><!-- /container -->

<div class="toast-container position-fixed top-0 end-0 p-3" id="toasts" style="z-index:1080"></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const $ = (s, r=document)=>r.querySelector(s);
function esc(s){return String(s??'').replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m]));}
function toast(msg, variant='success', delay=2200){
  let wrap=document.getElementById('toasts');
  if(!wrap){wrap=document.createElement('div');wrap.id='toasts';wrap.className='toast-container position-fixed top-0 end-0 p-3';wrap.style.zIndex=1080;document.body.appendChild(wrap);}
  const el=document.createElement('div'); el.className=`toast text-bg-${variant} border-0`; el.role='status';
  el.innerHTML=`<div class="d-flex"><div class="toast-body">${msg}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
  wrap.appendChild(el); const t=new bootstrap.Toast(el,{delay}); t.show(); el.addEventListener('hidden.bs.toast',()=>el.remove());
}

// Browser-resolved base to avoid any case/path issues
const API_BASE = new URL('./api/', window.location.href);
const API = {
  list:  new URL('plt_schedule_list.php',  API_BASE).toString(),
  stats: new URL('plt_schedule_stats.php', API_BASE).toString(),
};

async function fetchJSON(url, opts = {}){
  const res = await fetch(url, {
    credentials:'same-origin',
    headers:{ 'Accept':'application/json', ...(opts.headers||{}) },
    ...opts
  });
  const text = await res.text();
  let data; try { data = JSON.parse(text); } catch {}
  if (!res.ok || !data) {
    const looksHTML = /^\s*<!doctype html|<html/i.test(text);
    throw new Error((looksHTML ? 'Got HTML instead of JSON (wrong API path or redirect)' : (text || res.statusText)) + ' • URL: ' + url + ' • HTTP ' + res.status);
  }
  if (data.error) throw new Error(data.error);
  return data;
}

let state = { page:1, perPage:10, search:'', status:'', sort:'schedule', from:'', to:'' };

function badgeStatus(s){
  const v=(s||'').toLowerCase();
  const map={planned:'secondary',picked:'info','in_transit':'primary',delivered:'success',cancelled:'danger'};
  const cls=map[v]||'secondary';
  const label={planned:'Planned',picked:'Picked','in_transit':'In-Transit',delivered:'Delivered',cancelled:'Cancelled'}[v]||s;
  return `<span class="badge bg-${cls}">${label}</span>`;
}

async function loadStats(){
  try{
    const r = await fetchJSON(API.stats);
    document.getElementById('kToday').textContent    = r.today ?? 0;
    document.getElementById('kTomorrow').textContent = r.tomorrow ?? 0;
    document.getElementById('kWeek').textContent     = r.week ?? 0;
    document.getElementById('kDel7').textContent     = r.delivered7 ?? 0;
  }catch(e){ /* ignore */ }
}

async function loadList(){
  const qs = new URLSearchParams({
    page:state.page, per_page:state.perPage, search:state.search, status:state.status,
    sort:state.sort, date_from:state.from, date_to:state.to
  });
  try{
    const {data, pagination} = await fetchJSON(API.list + '?' + qs.toString());
    const tbody = document.getElementById('tblBody');
    const fmt=v=>v?esc(v):'-';
    tbody.innerHTML = data.length ? data.map(r=>{
      const route = `<div class="route">${esc(r.origin||'-')} → ${esc(r.destination||'-')}</div>`;
      const vd = `${esc(r.vehicle||'-')}<br><span class="chip">${esc(r.driver||'')}</span>`;
      return `
        <tr>
          <td>${fmt(r.schedule_date)}</td>
          <td class="fw-semibold">${esc(r.shipment_no||('SHP-'+r.id))}</td>
          <td>${esc(r.project_name||'-')}</td>
          <td>${route}</td>
          <td>${vd}</td>
          <td>${fmt(r.eta_date)}</td>
          <td>${badgeStatus(r.status)}</td>
        </tr>`;
    }).join('') : `<tr><td colspan="7" class="text-center py-4 text-muted">No scheduled deliveries.</td></tr>`;

    const {page,perPage,total}=pagination;
    const totalPages=Math.max(1,Math.ceil(total/perPage));
    document.getElementById('pageInfo').textContent=`Page ${page} of ${totalPages} • ${total} result(s)`;
    const pager=document.getElementById('pager'); pager.innerHTML='';
    const li=(p,l=p,d=false,a=false)=>`<li class="page-item ${d?'disabled':''} ${a?'active':''}"><a class="page-link" href="#" onclick="go(${p});return false;">${l}</a></li>`;
    pager.insertAdjacentHTML('beforeend', li(page-1,'&laquo;', page<=1));
    for(let p=Math.max(1,page-2); p<=Math.min(totalPages,page+2); p++) pager.insertAdjacentHTML('beforeend', li(p,p,false,p===page));
    pager.insertAdjacentHTML('beforeend', li(page+1,'&raquo;', page>=totalPages));
  }catch(e){
    document.getElementById('tblBody').innerHTML =
      `<tr><td colspan="7" class="text-center py-4 text-danger">Error: ${esc(e.message||'Failed')}</td></tr>`;
    document.getElementById('pageInfo').textContent=''; document.getElementById('pager').innerHTML='';
  }
}
window.go=(p)=>{ if(!p||p<1) return; state.page=p; loadList(); };

document.getElementById('btnApply').addEventListener('click', ()=>{
  state.page=1;
  state.from = document.getElementById('fFrom').value || '';
  state.to   = document.getElementById('fTo').value || '';
  state.status=document.getElementById('fStatus').value;
  state.search=document.getElementById('fSearch').value.trim();
  state.sort=document.getElementById('fSort').value;
  loadList();
});
document.getElementById('btnReset').addEventListener('click', ()=>{
  document.getElementById('fFrom').value=''; document.getElementById('fTo').value='';
  document.getElementById('fStatus').value=''; document.getElementById('fSearch').value='';
  document.getElementById('fSort').value='schedule';
  state={page:1,perPage:10,search:'',status:'',sort:'schedule',from:'',to:''};
  loadList();
});

loadStats();
loadList();
</script>
</body>
</html>
