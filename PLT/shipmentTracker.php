<?php
$inc = __DIR__ . "/../includes";
if (file_exists($inc . "/config.php")) {
    require_once $inc . "/config.php";
}
if (file_exists($inc . "/auth.php")) {
    require_once $inc . "/auth.php";
}
if (function_exists("require_login")) {
    require_login();
}

require_role(['admin', 'project_lead']);

$userName = "Admin";
$userRole = "System Admin";

$section = 'plt';
$active = 'tracker';

if (function_exists("current_user")) {
    $u = current_user();
    $userName = $u["name"] ?? $userName;
    $userRole = $u["role"] ?? $userRole;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Shipment Tracker | PLT</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="../css/style.css" rel="stylesheet" />
  <link href="../css/modules.css" rel="stylesheet" />
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <script src="../js/sidebar-toggle.js"></script>

</head>
<body>
<div class="container-fluid p-0">
  <div class="row g-0">

    <?php include __DIR__ . '/../includes/sidebar.php' ?>

    <!-- Main -->
    <div class="col main-content p-3 p-lg-4">

      <!-- Topbar -->
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-3">
          <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2" aria-label="Toggle sidebar">
            <ion-icon name="menu-outline"></ion-icon>
          </button>
          <h2 class="m-0">Shipment Tracker</h2>
        </div>
        <div class="d-flex align-items-center gap-2">
          <img src="../img/profile.jpg" class="rounded-circle" width="36" height="36" alt="">
          <div class="small">
            <strong><?= htmlspecialchars($userName) ?></strong><br/>
            <span class="text-muted"><?= htmlspecialchars($userRole) ?></span>
          </div>
        </div>
      </div>

      <!-- Filters -->
      <section class="card shadow-sm mb-3">
        <div class="card-body">
          <div class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
              <label class="form-label small text-muted">Search</label>
              <input id="fSearch" class="form-control" placeholder="Shipment No, Project, Origin/Destination…">
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
              <label class="form-label small text-muted">Sort</label>
              <select id="fSort" class="form-select">
                <option value="newest" selected>Newest</option>
                <option value="eta">ETA</option>
                <option value="schedule">Schedule Date</option>
              </select>
            </div>
            <div class="col-12 col-md-2 d-grid d-md-flex justify-content-md-end">
              <button id="btnFilter" class="btn btn-outline-primary me-md-2">
                <ion-icon name="search-outline"></ion-icon> Search
              </button>
              <button id="btnReset" class="btn btn-outline-secondary">Reset</button>
            </div>
          </div>
        </div>
      </section>

      <!-- List -->
      <section class="card shadow-sm">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">Shipments</h5>
            <button class="btn btn-violet" onclick="openNew()">
              <ion-icon name="add-circle-outline"></ion-icon> New Shipment
            </button>
          </div>

          <div class="table-responsive">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th>Shipment No</th>
                  <th>Project</th>
                  <th>Route</th>
                  <th>Vehicle / Driver</th>
                  <th>Schedule</th>
                  <th>ETA</th>
                  <th>Status</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody id="shipBody"><tr><td colspan="8" class="text-center py-4">Loading…</td></tr></tbody>
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

<!-- Modal -->
<div class="modal fade" id="mdlShip" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <form id="shipForm">
        <div class="modal-header">
          <h5 class="modal-title">Shipment</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" id="shipId">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Project</label>
              <select class="form-select" id="shipProject" name="project_id"></select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Shipment No</label>
              <input class="form-control" id="shipNo" name="shipment_no" maxlength="40" placeholder="Auto if empty">
            </div>
            <div class="col-md-4">
              <label class="form-label">Status</label>
              <select class="form-select" id="shipStatus" name="status">
                <option value="planned" selected>Planned</option>
                <option value="picked">Picked</option>
                <option value="in_transit">In-Transit</option>
                <option value="delivered">Delivered</option>
                <option value="cancelled">Cancelled</option>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label">Origin</label>
              <input class="form-control" id="shipOrigin" name="origin" maxlength="160">
            </div>
            <div class="col-md-6">
              <label class="form-label">Destination</label>
              <input class="form-control" id="shipDest" name="destination" maxlength="160">
            </div>

            <div class="col-md-3">
              <label class="form-label">Schedule Date</label>
              <input type="date" class="form-control" id="shipSched" name="schedule_date">
            </div>
            <div class="col-md-3">
              <label class="form-label">ETA</label>
              <input type="date" class="form-control" id="shipETA" name="eta_date">
            </div>
            <div class="col-md-3">
              <label class="form-label">Vehicle</label>
              <input class="form-control" id="shipVehicle" name="vehicle" maxlength="80">
            </div>
            <div class="col-md-3">
              <label class="form-label">Driver</label>
              <input class="form-control" id="shipDriver" name="driver" maxlength="80">
            </div>

            <div class="col-12">
              <label class="form-label">Notes</label>
              <textarea class="form-control" id="shipNotes" name="notes" rows="2"></textarea>
            </div>
          </div>
          <div class="alert alert-danger d-none mt-3" id="shipErr"></div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-outline-secondary" data-bs-dismiss="modal" type="button">Cancel</button>
          <button class="btn btn-primary" type="submit">Save Shipment</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Toasts -->
<div class="toast-container position-fixed top-0 end-0 p-3" id="toasts" style="z-index:1080"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const $ = (s, r=document)=>r.querySelector(s);
function esc(s){return String(s??'').replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m]));}

// ---------- Build API URLs RELATIVE to this page (bullet-proof) ----------
const API_BASE = new URL('./api/', window.location.href);
const api = {
  list:  new URL('plt_shipments_list.php', API_BASE).toString(),
  save:  new URL('plt_shipments_save.php', API_BASE).toString(),
  setSt: new URL('plt_shipments_set_status.php', API_BASE).toString(),
  proj:  new URL('plt_projects_select.php', API_BASE).toString()
};

async function fetchJSON(url, opts = {}) {
  const res = await fetch(url, {
    credentials: 'same-origin',
    headers: { 'Accept': 'application/json', ...(opts.headers||{}) },
    redirect: 'follow',
    ...opts
  });
  const text = await res.text();
  let data; try { data = JSON.parse(text); } catch {}
  if (!res.ok || !data) {
    const looksHTML = /^\s*<!doctype html/i.test(text);
    const hint = looksHTML ? 'Got HTML instead of JSON (likely wrong API path or a redirect by auth/CSRF).' : (text || res.statusText);
    throw new Error(hint);
  }
  if (data.error) throw new Error(data.error);
  return data;
}
function parseErr(e){ return e?.message || 'Request failed'; }
function toast(msg, variant='success', delay=2200){
  let wrap=document.getElementById('toasts');
  if(!wrap){wrap=document.createElement('div');wrap.id='toasts';wrap.className='toast-container position-fixed top-0 end-0 p-3';wrap.style.zIndex=1080;document.body.appendChild(wrap);}
  const el=document.createElement('div'); el.className=`toast text-bg-${variant} border-0`; el.role='status';
  el.innerHTML=`<div class="d-flex"><div class="toast-body">${msg}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
  wrap.appendChild(el); const t=new bootstrap.Toast(el,{delay}); t.show(); el.addEventListener('hidden.bs.toast',()=>el.remove());
}

// ---------- State ----------
let state = { page:1, perPage:10, search:'', status:'', sort:'newest' };
const mapSort=v=>v;

async function loadProjectsInto(sel){
  try{
    const rows = await fetchJSON(api.proj + '?active=1');
    sel.innerHTML = '<option value="">— Select Project —</option>' +
      rows.map(p=>`<option value="${p.id}">${esc(p.code||('PRJ-'+p.id))} — ${esc(p.name)}</option>`).join('');
  }catch{ sel.innerHTML = '<option value="">— Select Project —</option>'; }
}

function badgeStatus(s){
  const v=(s||'').toLowerCase();
  const map={planned:'secondary',picked:'info','in_transit':'primary',delivered:'success',cancelled:'danger'};
  const cls=map[v]||'secondary';
  const label={planned:'Planned',picked:'Picked','in_transit':'In-Transit',delivered:'Delivered',cancelled:'Cancelled'}[v]||s;
  return `<span class="badge bg-${cls}">${label}</span>`;
}

// ---------- List ----------
async function loadShipments(){
  const qs=new URLSearchParams({page:state.page,per_page:state.perPage,search:state.search,status:state.status,sort:state.sort});
  try{
    const {data,pagination}=await fetchJSON(api.list+'?'+qs.toString());
    const tbody=document.getElementById('shipBody');
    const fmt=v=>v?esc(v):'-';
    tbody.innerHTML = data.length ? data.map(r=>{
      const route=`<div class="route">${esc(r.origin||'-')} &rarr; ${esc(r.destination||'-')}</div>`;
      const vd=`${esc(r.vehicle||'-')}<br><span class="chip">${esc(r.driver||'')}</span>`;

      const canPicked     = r.status!=='picked'    && r.status!=='in_transit' && r.status!=='delivered' && r.status!=='cancelled';
      const canInTransit  = r.status!=='in_transit' && r.status!=='delivered' && r.status!=='cancelled';
      const canDelivered  = r.status!=='delivered' && r.status!=='cancelled';
      const canCancelled  = r.status!=='cancelled' && r.status!=='delivered';

      return `
        <tr>
          <td class="fw-semibold">${esc(r.shipment_no||('SHP-'+r.id))}</td>
          <td>${esc(r.project_name||'-')}</td>
          <td>${route}</td>
          <td>${vd}</td>
          <td>${fmt(r.schedule_date)}</td>
          <td>${fmt(r.eta_date)}</td>
          <td>${badgeStatus(r.status)}</td>
          <td class="text-end">
            <button class="btn btn-sm btn-outline-secondary me-1" onclick="openEdit(${r.id})">Edit</button>
            <div class="btn-group btn-group-sm">
              ${canPicked    ? `<button class="btn btn-outline-info"     onclick="setStatus(${r.id},'picked')">Picked</button>`:''}
              ${canInTransit ? `<button class="btn btn-outline-primary"  onclick="setStatus(${r.id},'in_transit')">In-Transit</button>`:''}
              ${canDelivered ? `<button class="btn btn-success"          onclick="setStatus(${r.id},'delivered')">Delivered</button>`:''}
              ${canCancelled ? `<button class="btn btn-outline-danger"   onclick="setStatus(${r.id},'cancelled')">Cancel</button>`:''}
            </div>
          </td>
        </tr>`;
    }).join('') : `<tr><td colspan="8" class="text-center py-4 text-muted">No shipments found.</td></tr>`;

    const {page,perPage,total}=pagination;
    const totalPages=Math.max(1,Math.ceil(total/perPage));
    document.getElementById('pageInfo').textContent=`Page ${page} of ${totalPages} • ${total} result(s)`;
    const pager=document.getElementById('pager'); pager.innerHTML='';
    const li=(p,l=p,d=false,a=false)=>`<li class="page-item ${d?'disabled':''} ${a?'active':''}"><a class="page-link" href="#" onclick="go(${p});return false;">${l}</a></li>`;
    pager.insertAdjacentHTML('beforeend', li(page-1,'&laquo;', page<=1));
    for(let p=Math.max(1,page-2); p<=Math.min(totalPages,page+2); p++) pager.insertAdjacentHTML('beforeend', li(p,p,false,p===page));
    pager.insertAdjacentHTML('beforeend', li(page+1,'&raquo;', page>=totalPages));
  }catch(e){
    const tbody=document.getElementById('shipBody');
    tbody.innerHTML = `<tr><td colspan="8" class="text-center py-4 text-danger">Error: ${esc(parseErr(e))}</td></tr>`;
    document.getElementById('pageInfo').textContent=''; document.getElementById('pager').innerHTML='';
  }
}
window.go=(p)=>{ if(!p||p<1) return; state.page=p; loadShipments(); };

document.getElementById('btnFilter').addEventListener('click', ()=>{
  state.page=1;
  state.search=$('#fSearch').value.trim();
  state.status=$('#fStatus').value;
  state.sort=mapSort($('#fSort').value);
  loadShipments();
});
document.getElementById('btnReset').addEventListener('click', ()=>{
  $('#fSearch').value=''; $('#fStatus').value=''; $('#fSort').value='newest';
  state={page:1,perPage:10,search:'',status:'',sort:'newest'};
  loadShipments();
});

// ---------- Modal ----------
async function openNew(){
  const m=bootstrap.Modal.getOrCreateInstance(document.getElementById('mdlShip'));
  const f=document.getElementById('shipForm');
  f.reset(); document.getElementById('shipErr').classList.add('d-none'); $('#shipId').value='';
  await loadProjectsInto(document.getElementById('shipProject'));
  $('#shipStatus').value='planned';
  m.show();
}
async function openEdit(id){
  const m=bootstrap.Modal.getOrCreateInstance(document.getElementById('mdlShip'));
  const f=document.getElementById('shipForm');
  f.reset(); document.getElementById('shipErr').classList.add('d-none');
  await loadProjectsInto(document.getElementById('shipProject'));
  try{
    const row = await fetchJSON(api.list+'?id='+id);
    const r = (row&&row.data&&row.data[0])?row.data[0]:row;
    $('#shipId').value      = r.id;
    $('#shipProject').value = r.project_id||'';
    $('#shipNo').value      = r.shipment_no||'';
    $('#shipStatus').value  = r.status||'planned';
    $('#shipOrigin').value  = r.origin||'';
    $('#shipDest').value    = r.destination||'';
    $('#shipSched').value   = r.schedule_date||'';
    $('#shipETA').value     = r.eta_date||'';
    $('#shipVehicle').value = r.vehicle||'';
    $('#shipDriver').value  = r.driver||'';
    $('#shipNotes').value   = r.notes||'';
    m.show();
  }catch(e){ alert(parseErr(e)); }
}

document.getElementById('shipForm').addEventListener('submit', async (ev)=>{
  ev.preventDefault();
  try{
    document.getElementById('shipErr').classList.add('d-none');
    const fd=new FormData(ev.target);
    await fetchJSON(api.save,{method:'POST',body:fd});
    bootstrap.Modal.getOrCreateInstance(document.getElementById('mdlShip')).hide();
    toast('Shipment saved'); loadShipments();
  }catch(e){
    const el=document.getElementById('shipErr'); el.textContent=parseErr(e); el.classList.remove('d-none');
  }
});

// ---------- Status buttons ----------
async function setStatus(id,status){
  if(!confirm('Set status to '+status.toUpperCase()+'?')) return;
  const body = new URLSearchParams({ id:String(id), status:String(status), _t: Date.now().toString() });
  try{
    await fetchJSON(api.setSt,{
      method:'POST',
      credentials:'same-origin',
      headers:{
        'Content-Type':'application/x-www-form-urlencoded',
        'Accept':'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body
    });
    toast('Status updated'); loadShipments();
  }catch(e){ alert(parseErr(e)); }
}

loadShipments();
</script>
</body>
</html>
