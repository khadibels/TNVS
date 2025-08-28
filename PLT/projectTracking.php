<?php
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
  <title>Project Tracking | PLT</title>

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

    <!-- Sidebar -->
    <div class="sidebar d-flex flex-column">
      <div class="d-flex justify-content-center align-items-center mb-4 mt-3">
        <img src="../img/logo.png" id="logo" class="img-fluid me-2" style="height:55px" alt="Logo">
      </div>
      <h6 class="text-uppercase mb-2">PLT</h6>
      <nav class="nav flex-column px-2 mb-4">
        <a class="nav-link" href="./pltDashboard.php"><ion-icon name="home-outline"></ion-icon><span>Dashboard</span></a>
        <a class="nav-link" href="./shipmentTracker.php"><ion-icon name="trail-sign-outline"></ion-icon><span>Shipment Tracker</span></a>
        <a class="nav-link active" href="./projectTracking.php"><ion-icon name="briefcase-outline"></ion-icon><span>Project Tracking</span></a>
        <a class="nav-link" href="./deliverySchedule.php"><ion-icon name="calendar-outline"></ion-icon><span>Delivery Schedule</span></a>
        <a class="nav-link" href="./pltReports.php"><ion-icon name="analytics-outline"></ion-icon><span>Reports</span></a>
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
          <h2 class="m-0">Project Tracking</h2>
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
              <input id="fSearch" class="form-control" placeholder="Code, Name, Scope…">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label small text-muted">Status</label>
              <select id="fStatus" class="form-select">
                <option value="">All</option>
                <option value="planned">Planned</option>
                <option value="ongoing">Ongoing</option>
                <option value="completed">Completed</option>
                <option value="delayed">Delayed</option>
                <option value="closed">Closed</option>
              </select>
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label small text-muted">Sort</label>
              <select id="fSort" class="form-select">
                <option value="newest" selected>Newest</option>
                <option value="name">Name (A–Z)</option>
                <option value="deadline">Deadline</option>
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
            <h5 class="mb-0">Projects</h5>
            <button class="btn btn-violet" onclick="openNew()">
              <ion-icon name="add-circle-outline"></ion-icon> New Project
            </button>
          </div>

          <div class="table-responsive">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th>Code</th>
                  <th>Name / Scope</th>
                  <th>Owner</th>
                  <th>Timeline</th>
                  <th>Milestones</th>
                  <th>Status</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody id="projBody"><tr><td colspan="7" class="text-center py-4">Loading…</td></tr></tbody>
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

<!-- Project Modal -->
<div class="modal fade" id="mdlProj" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <form id="projForm">
        <div class="modal-header">
          <h5 class="modal-title">Project</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" id="projId">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Code</label>
              <input class="form-control" id="projCode" name="code" maxlength="40" placeholder="Auto if empty">
            </div>
            <div class="col-md-8">
              <label class="form-label">Name</label>
              <input class="form-control" id="projName" name="name" maxlength="160" required>
            </div>
            <div class="col-12">
              <label class="form-label">Scope / Description</label>
              <textarea class="form-control" id="projScope" name="scope" rows="2"></textarea>
            </div>
            <div class="col-md-3">
              <label class="form-label">Start</label>
              <input type="date" class="form-control" id="projStart" name="start_date">
            </div>
            <div class="col-md-3">
              <label class="form-label">Deadline</label>
              <input type="date" class="form-control" id="projDeadline" name="deadline_date">
            </div>
            <div class="col-md-3">
              <label class="form-label">Status</label>
              <select class="form-select" id="projStatus" name="status">
                <option value="planned" selected>Planned</option>
                <option value="ongoing">Ongoing</option>
                <option value="completed">Completed</option>
                <option value="delayed">Delayed</option>
                <option value="closed">Closed</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Project Lead</label>
              <input class="form-control" id="projOwner" name="owner_name" maxlength="120" placeholder="(optional)">
            </div>
          </div>

          <hr class="my-4">

          <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0">Milestones</h6>
            <button type="button" class="btn btn-sm btn-outline-primary" onclick="addMsRow()">Add Milestone</button>
          </div>

          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead>
                <tr>
                  <th style="width:40%">Title</th>
                  <th style="width:20%">Due</th>
                  <th style="width:20%">Status</th>
                  <th style="width:18%">Owner</th>
                  <th style="width:2%"></th>
                </tr>
              </thead>
              <tbody id="msBody"></tbody>
            </table>
          </div>

          <div class="alert alert-danger d-none mt-2" id="projErr"></div>
          <div class="alert alert-warning d-none mt-2" id="projWarn"></div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-outline-secondary" data-bs-dismiss="modal" type="button">Close</button>
          <button class="btn btn-primary" id="btnSaveProj" type="submit">Save Project</button>
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

// strict fetch
async function fetchJSON(url, opts = {}) {
  const res = await fetch(url, opts);
  const text = await res.text();
  let data; try { data = JSON.parse(text); } catch {}
  if (!res.ok || (data && data.error)) throw new Error((data && data.error) ? data.error : (text || res.statusText));
  if (data === undefined) throw new Error(text || 'Non-JSON response');
  return data;
}
function parseErr(e){ try{const j=JSON.parse(e.message); if(j.error) return j.error;}catch{} return e.message||'Request failed'; }
function toast(msg, variant='success', delay=2200){
  let wrap=document.getElementById('toasts');
  if(!wrap){wrap=document.createElement('div');wrap.id='toasts';wrap.className='toast-container position-fixed top-0 end-0 p-3';wrap.style.zIndex=1080;document.body.appendChild(wrap);}
  const el=document.createElement('div'); el.className=`toast text-bg-${variant} border-0`; el.role='status';
  el.innerHTML=`<div class="d-flex"><div class="toast-body">${msg}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
  wrap.appendChild(el); const t=new bootstrap.Toast(el,{delay}); t.show(); el.addEventListener('hidden.bs.toast',()=>el.remove());
}

const api = {
  list: './api/plt_projects_list.php',
  save: './api/plt_projects_save.php',
  setSt:'./api/plt_projects_set_status.php',
  msList:'./api/plt_milestones_list.php',
  close :'./api/plt_projects_close.php'
};

let state = { page:1, perPage:10, search:'', status:'', sort:'newest' };
const mapSort=v=>v;

// ---------- list ----------
function badge(s){
  const v=(s||'').toLowerCase();
  const map={planned:'secondary',ongoing:'primary',completed:'info',delayed:'warning',closed:'dark'};
  const cls=map[v]||'secondary'; const lbl=v.charAt(0).toUpperCase()+v.slice(1);
  return `<span class="badge bg-${cls}">${lbl}</span>`;
}
function span(m){ return m?`<span class="chip">${esc(m)}</span>`:''; }

async function loadProjects(){
  const qs=new URLSearchParams({page:state.page,per_page:state.perPage,search:state.search,status:state.status,sort:state.sort});
  try{
    const {data,pagination}=await fetchJSON(api.list+'?'+qs.toString());
    const tbody=document.getElementById('projBody');
    tbody.innerHTML = data.length ? data.map(r=>{
      const tl = `${esc(r.start_date||'-')} → ${esc(r.deadline_date||'-')}`;
      const ms = r.milestone_summary ? r.milestone_summary.split('|').map(span).join(' ') : '';
      const st = String(r.status||'').toLowerCase();
      const isFinal = (st==='closed' || st==='completed');

      return `
        <tr>
          <td class="fw-semibold">${esc(r.code||('PRJ-'+r.id))}</td>
          <td><div>${esc(r.name)}</div><div class="text-muted small">${esc(r.scope||'')}</div></td>
          <td>${esc(r.owner_name||'-')}</td>
          <td>${tl}</td>
          <td>${ms||'-'}</td>
          <td>${badge(r.status)}</td>
          <td class="text-end">
            <button class="btn btn-sm btn-outline-secondary me-1" onclick="openEdit(${r.id})">Edit</button>
            ${st!=='closed'
              ? `<button class="btn btn-sm btn-success me-1" onclick="tryClose(${r.id})">Close Project</button>` : ``}
            ${!isFinal && st!=='delayed'
              ? `<button class="btn btn-sm btn-outline-warning me-1" onclick="quickStatus(${r.id},'delayed')">Mark Delayed</button>`:``}
            ${!isFinal && st!=='ongoing'
              ? `<button class="btn btn-sm btn-primary" onclick="quickStatus(${r.id},'ongoing')">Mark Ongoing</button>`:``}
          </td>
        </tr>`;
    }).join('') : `<tr><td colspan="7" class="text-center py-4 text-muted">No projects found.</td></tr>`;

    const {page,perPage,total}=pagination;
    const totalPages=Math.max(1,Math.ceil(total/perPage));
    document.getElementById('pageInfo').textContent=`Page ${page} of ${totalPages} • ${total} result(s)`;
    const pager=document.getElementById('pager'); pager.innerHTML='';
    const li=(p,l=p,d=false,a=false)=>`<li class="page-item ${d?'disabled':''} ${a?'active':''}"><a class="page-link" href="#" onclick="go(${p});return false;">${l}</a></li>`;
    pager.insertAdjacentHTML('beforeend', li(page-1,'&laquo;', page<=1));
    for(let p=Math.max(1,page-2); p<=Math.min(totalPages,page+2); p++) pager.insertAdjacentHTML('beforeend', li(p,p,false,p===page));
    pager.insertAdjacentHTML('beforeend', li(page+1,'&raquo;', page>=totalPages));
  }catch(e){
    const tbody=document.getElementById('projBody');
    tbody.innerHTML = `<tr><td colspan="7" class="text-center py-4 text-danger">Error loading projects: ${esc(parseErr(e))}</td></tr>`;
    document.getElementById('pageInfo').textContent='';
    document.getElementById('pager').innerHTML='';
  }
}
window.go=(p)=>{ if(!p||p<1) return; state.page=p; loadProjects(); };

// ---------- filters ----------
document.getElementById('btnFilter').addEventListener('click', ()=>{
  state.page=1;
  state.search=$('#fSearch').value.trim();
  state.status=$('#fStatus').value;
  state.sort=mapSort($('#fSort').value);
  loadProjects();
});
document.getElementById('btnReset').addEventListener('click', ()=>{
  $('#fSearch').value=''; $('#fStatus').value=''; $('#fSort').value='newest';
  state={page:1,perPage:10,search:'',status:'',sort:'newest'};
  loadProjects();
});

// ---------- modal + milestones ----------
function addMsRow(ms={title:'', due_date:'', status:'pending', owner:''}){
  const tr=document.createElement('tr');
  tr.innerHTML=`
    <td><input class="form-control form-control-sm" name="ms[title][]" value="${esc(ms.title||'')}" placeholder="Milestone" required></td>
    <td><input type="date" class="form-control form-control-sm" name="ms[due_date][]" value="${ms.due_date||''}"></td>
    <td>
      <select class="form-select form-select-sm" name="ms[status][]">
        <option value="pending" ${ms.status==='pending'?'selected':''}>Pending</option>
        <option value="ongoing" ${ms.status==='ongoing'?'selected':''}>Ongoing</option>
        <option value="done" ${ms.status==='done'?'selected':''}>Done</option>
        <option value="delayed" ${ms.status==='delayed'?'selected':''}>Delayed</option>
      </select>
    </td>
    <td><input class="form-control form-control-sm" name="ms[owner][]" value="${esc(ms.owner||'')}"></td>
    <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><ion-icon name="trash-outline"></ion-icon></button></td>
  `;
  document.getElementById('msBody').appendChild(tr);
}

function setFormLocked(lock, reason=''){
  const form = document.getElementById('projForm');
  form.dataset.locked = lock ? '1' : '';
  const inputs = form.querySelectorAll('input, select, textarea');
  inputs.forEach(el=>{
    // keep hidden id and allow closing the modal
    if (el.id === 'projId') return;
    el.disabled = !!lock;
  });
  const saveBtn = document.getElementById('btnSaveProj');
  saveBtn.disabled = !!lock;
  const warn = document.getElementById('projWarn');
  if (lock){
    warn.textContent = reason || 'This project is already CLOSED and cannot be edited.';
    warn.classList.remove('d-none');
  } else {
    warn.classList.add('d-none');
    warn.textContent = '';
  }
}

async function openNew(){
  const m=bootstrap.Modal.getOrCreateInstance(document.getElementById('mdlProj'));
  const f=document.getElementById('projForm'); f.reset();
  document.getElementById('projErr').classList.add('d-none');
  document.getElementById('projWarn').classList.add('d-none');
  document.getElementById('projId').value='';
  document.getElementById('msBody').innerHTML=''; addMsRow(); addMsRow();
  document.getElementById('projStatus').value='planned';
  setFormLocked(false);
  m.show();
}

async function openEdit(id){
  const m=bootstrap.Modal.getOrCreateInstance(document.getElementById('mdlProj'));
  const f=document.getElementById('projForm'); f.reset();
  document.getElementById('projErr').classList.add('d-none');
  document.getElementById('projWarn').classList.add('d-none');
  document.getElementById('projId').value=id;
  document.getElementById('msBody').innerHTML='';
  try{
    const j = await fetchJSON(api.list+'?id='+id);
    const r = (j&&j.data&&j.data[0])?j.data[0]:j;
    document.getElementById('projCode').value=r.code||'';
    document.getElementById('projName').value=r.name||'';
    document.getElementById('projScope').value=r.scope||'';
    document.getElementById('projStart').value=r.start_date||'';
    document.getElementById('projDeadline').value=r.deadline_date||'';
    document.getElementById('projStatus').value=r.status||'planned';
    document.getElementById('projOwner').value=r.owner_name||'';

    try{
      const ms=await fetchJSON(api.msList+'?project_id='+id);
      (ms||[]).forEach(x=>addMsRow(x));
      if(!(ms||[]).length) addMsRow();
    }catch{ addMsRow(); }

    const st = String(r.status||'').toLowerCase();
    if (st==='closed'){
      setFormLocked(true, 'This project is already CLOSED and cannot be edited.');
    } else {
      setFormLocked(false);
    }

    m.show();
  }catch(e){
    const el=document.getElementById('projErr'); el.textContent=parseErr(e); el.classList.remove('d-none');
  }
}

document.getElementById('projForm').addEventListener('submit', async (ev)=>{
  ev.preventDefault();
  const form = document.getElementById('projForm');
  if (form.dataset.locked === '1'){
    const el=document.getElementById('projErr');
    el.textContent='This project is already CLOSED and cannot be edited.';
    el.classList.remove('d-none');
    return;
  }
  try{
    document.getElementById('projErr').classList.add('d-none');
    const fd=new FormData(ev.target);
    const msRows=[...document.querySelectorAll('#msBody tr')].map(tr=>{
      const get=n=>tr.querySelector(`[name="${n}"]`)||tr.querySelector(`[name="${n}[]"]`);
      return {
        title:(get('ms[title][]')?.value||'').trim(),
        due_date:get('ms[due_date][]')?.value||null,
        status:get('ms[status][]')?.value||'pending',
        owner:(get('ms[owner][]')?.value||'').trim()
      };
    }).filter(x=>x.title);
    fd.set('milestones_json', JSON.stringify(msRows));

    await fetchJSON(api.save,{method:'POST',body:fd});
    bootstrap.Modal.getOrCreateInstance(document.getElementById('mdlProj')).hide();
    toast('Project saved');
    loadProjects();
  }catch(e){
    const el=document.getElementById('projErr'); el.textContent=parseErr(e); el.classList.remove('d-none');
  }
});

async function quickStatus(id,status){
  if(!confirm('Set status to '+status.toUpperCase()+'?')) return;
  const body=new URLSearchParams({id:String(id),status:String(status)});
  try{
    await fetchJSON(api.setSt,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body});
    toast('Status updated'); loadProjects();
  }catch(e){ alert(parseErr(e)); }
}

async function tryClose(id){
  if(!confirm('Close this project? System will verify required docs and delivered shipments.')) return;
  try{
    const r = await fetchJSON(api.close+'?id='+id);
    if(r.ok){ toast('Project closed'); loadProjects(); }
    else { alert(r.error||'Cannot close project'); }
  }catch(e){ alert(parseErr(e)); }
}

loadProjects();
</script>
</body>
</html>
