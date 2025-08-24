<?php
$inc = __DIR__ . "/../includes";
if (file_exists($inc . "/config.php")) require_once $inc . "/config.php";
if (file_exists($inc . "/auth.php"))   require_once $inc . "/auth.php";
if (function_exists("require_login"))  require_login();

$userName = "Procurement User";
$userRole = "Procurement";
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
  <title>Procurement Requests | Procurement</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="../css/style.css" rel="stylesheet" />
  <link href="../css/modules.css" rel="stylesheet" />
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <script src="../js/sidebar-toggle.js"></script>
  <style>
    .table-items td { vertical-align: middle; }
    .table-items input { text-align: right; }
  </style>
</head>
<body>
<div class="container-fluid p-0">
  <div class="row g-0">
    <div class="sidebar d-flex flex-column">
      <div class="d-flex justify-content-center align-items-center mb-4 mt-3">
        <img src="../img/logo.png" id="logo" class="img-fluid me-2" style="height:55px" alt="Logo">
      </div>
      <h6 class="text-uppercase mb-2">Procurement</h6>
      <nav class="nav flex-column px-2 mb-4">
        <a class="nav-link" href="./procurementDashboard.php"><ion-icon name="home-outline"></ion-icon><span>Dashboard</span></a>
        <a class="nav-link" href="./supplierManagement.php"><ion-icon name="person-outline"></ion-icon><span>Supplier Management</span></a>
        <a class="nav-link" href="./rfqManagement.php"><ion-icon name="mail-open-outline"></ion-icon><span>RFQs & Sourcing</span></a>
        <a class="nav-link" href="./purchaseOrders.php"><ion-icon name="document-text-outline"></ion-icon><span>Purchase Orders</span></a>
        <a class="nav-link active" href="./procurementRequests.php"><ion-icon name="clipboard-outline"></ion-icon><span>Procurement Requests</span></a>
        <a class="nav-link" href="./inventoryView.php"><ion-icon name="archive-outline"></ion-icon><span>Inventory Management</span></a>
        <a class="nav-link" href="./budgetReports.php"><ion-icon name="analytics-outline"></ion-icon><span>Budget & Reports</span></a>
        <a class="nav-link" href="./settings.php"><ion-icon name="settings-outline"></ion-icon><span>Settings</span></a>
      </nav>
      <div class="logout-section">
        <a class="nav-link text-danger" href="<?= defined('BASE_URL') ? BASE_URL : '#' ?>/auth/logout.php">
          <ion-icon name="log-out-outline"></ion-icon> Logout
        </a>
      </div>
    </div>

    <div class="col main-content p-3 p-lg-4">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-3">
          <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2" aria-label="Toggle sidebar">
            <ion-icon name="menu-outline"></ion-icon>
          </button>
          <h2 class="m-0">Procurement Requests</h2>
        </div>
        <div class="d-flex align-items-center gap-2">
          <img src="../img/profile.jpg" class="rounded-circle" width="36" height="36" alt="">
          <div class="small">
            <strong><?= htmlspecialchars($userName) ?></strong><br/>
            <span class="text-muted"><?= htmlspecialchars($userRole) ?></span>
          </div>
        </div>
      </div>

      <section class="card shadow-sm mb-3">
        <div class="card-body">
          <div class="row g-2 align-items-center">
            <div class="col-12 col-md-4">
              <input id="fSearch" class="form-control" placeholder="Search PR No, Title, or Requestor…">
            </div>
            <div class="col-6 col-md-3">
              <select id="fStatus" class="form-select">
                <option value="" selected>All Status</option>
                <option value="draft">Draft</option>
                <option value="submitted">Submitted</option>
                <option value="approved">Approved</option>
                <option value="rejected">Rejected</option>
                <option value="fulfilled">Fulfilled</option>
                <option value="cancelled">Cancelled</option>
              </select>
            </div>
            <div class="col-6 col-md-3">
              <select id="fSort" class="form-select">
                <option value="newest" selected>Newest</option>
                <option value="needed">Needed By</option>
                <option value="title">Title (A–Z)</option>
              </select>
            </div>
            <div class="col-12 col-md-2 d-grid d-md-flex justify-content-md-end">
              <button id="btnFilter" class="btn btn-outline-primary me-md-2" type="button">
                <ion-icon name="search-outline"></ion-icon> Search
              </button>
              <button id="btnReset" class="btn btn-outline-secondary" type="button">Reset</button>
            </div>
          </div>
        </div>
      </section>

      <section class="card shadow-sm">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">Internal Requests</h5>
            <button class="btn btn-violet" type="button" id="btnNewPR">
              <ion-icon name="add-circle-outline"></ion-icon> New Request
            </button>
          </div>

          <div class="table-responsive">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th>PR No</th>
                  <th>Title</th>
                  <th>Requestor</th>
                  <th>Dept</th>
                  <th>Needed By</th>
                  <th class="text-end">Est. Total</th>
                  <th>Status</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody id="prBody"><tr><td colspan="8" class="text-center py-4">Loading…</td></tr></tbody>
            </table>
          </div>
        </div>
      </section>

      <div class="d-flex justify-content-between align-items-center mt-2">
        <div class="small text-muted" id="pageInfo"></div>
        <nav><ul class="pagination pagination-sm mb-0" id="pager"></ul></nav>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="mdlPR" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <form id="prForm">
        <div class="modal-header">
          <h5 class="modal-title">Procurement Request</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">
          <input type="hidden" name="id" id="prId">
          <div class="row g-3">
            <div class="col-lg-6">
              <label class="form-label">Title</label>
              <input class="form-control" name="title" id="prTitle" required maxlength="160">
            </div>
            <div class="col-lg-3">
              <label class="form-label">Needed By</label>
              <input type="date" class="form-control" name="needed_by" id="prNeeded">
            </div>
            <div class="col-lg-3">
              <label class="form-label">Priority</label>
              <select class="form-select" name="priority" id="prPriority">
                <option value="normal" selected>Normal</option>
                <option value="high">High</option>
                <option value="urgent">Urgent</option>
              </select>
            </div>
            <div class="col-lg-4">
              <label class="form-label">Requestor</label>
              <input class="form-control" name="requestor" id="prRequestor" placeholder="e.g., Jane D.">
            </div>
            <div class="col-lg-4">
              <label class="form-label">Department</label>
              <select class="form-select" name="department_id" id="prDept"></select>
            </div>
            <div class="col-lg-4">
              <label class="form-label">Status</label>
              <select class="form-select" name="status" id="prStatus">
                <option value="draft" selected>Draft</option>
                <option value="submitted">Submitted</option>
                <option value="approved">Approved</option>
                <option value="rejected">Rejected</option>
                <option value="fulfilled">Fulfilled</option>
                <option value="cancelled">Cancelled</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Justification / Notes</label>
              <textarea class="form-control" name="notes" id="prNotes" rows="2"></textarea>
            </div>
          </div>

          <hr class="my-4">

          <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0">Requested Items</h6>
            <button type="button" class="btn btn-sm btn-outline-primary" id="btnAddItem">Add Item</button>
          </div>

          <div class="table-responsive">
            <table class="table table-sm align-middle table-items">
              <thead>
                <tr>
                  <th style="width:48%">Description</th>
                  <th style="width:14%" class="text-end">Qty</th>
                  <th style="width:18%" class="text-end">Est. Unit Cost</th>
                  <th style="width:18%" class="text-end">Line Total</th>
                  <th style="width:2%"></th>
                </tr>
              </thead>
              <tbody id="prItemsBody"></tbody>
              <tfoot>
                <tr>
                  <th colspan="3" class="text-end">Estimated Total</th>
                  <th class="text-end" id="prGrand">0.00</th>
                  <th></th>
                </tr>
              </tfoot>
            </table>
          </div>

          <div class="alert alert-danger d-none" id="prErr"></div>
          <div class="alert alert-warning d-none" id="prWarn"></div>
        </div>

        <div class="modal-footer">
          <button class="btn btn-outline-secondary" data-bs-dismiss="modal" type="button">Cancel</button>
          <button class="btn btn-primary" type="submit">Save Request</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="toast-container position-fixed top-0 end-0 p-3" id="toasts" style="z-index:1080"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const $ = (s, r=document)=>r.querySelector(s);
async function fetchJSON(url, opts={}){ const res = await fetch(url, opts); if(!res.ok) throw new Error(await res.text()||res.statusText); return res.json(); }
function esc(s){return String(s??'').replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m]));}
function parseErr(e){ try{const j=JSON.parse(e.message); if(j.error) return j.error;}catch{} return e.message||'Request failed'; }
function toast(msg, variant='success', delay=2200){
  let wrap=document.getElementById('toasts');
  if(!wrap){wrap=document.createElement('div');wrap.id='toasts';wrap.className='toast-container position-fixed top-0 end-0 p-3';wrap.style.zIndex=1080;document.body.appendChild(wrap);}
  const el=document.createElement('div'); el.className=`toast text-bg-${variant} border-0`; el.role='status';
  el.innerHTML=`<div class="d-flex"><div class="toast-body">${msg}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
  wrap.appendChild(el); const t=new bootstrap.Toast(el,{delay}); t.show(); el.addEventListener('hidden.bs.toast',()=>el.remove());
}

const api = {
  list   : './api/pr_list.php',
  get    : './api/pr_get.php',
  save   : './api/pr_save.php',
  setSt  : './api/pr_set_status.php',
  convert: './api/pr_convert_to_po.php',
  del    : './api/pr_delete.php',
  depts  : './api/departments_list.php'
};

let state = { page:1, perPage:10, search:'', status:'', sort:'newest' };
const mapSort = v=>v;

async function loadPRs(){
  const qs = new URLSearchParams({ page:state.page, per_page:state.perPage, search:state.search, status:state.status, sort:state.sort });
  const { data, pagination } = await fetchJSON(api.list+'?'+qs.toString());

  const tbody = document.getElementById('prBody');
  const fmtMoney=(v)=> Number(v ?? 0).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2});
  const badge=(s)=>{
    const v=(s||'').toLowerCase();
    const map={draft:'secondary',submitted:'info',approved:'primary',rejected:'danger',fulfilled:'success',cancelled:'dark'};
    const cls = map[v] || 'secondary';
    return `<span class="badge bg-${cls}">${esc(s||'draft')}</span>`;
  };

  tbody.innerHTML = data.length ? data.map(r=>`
    <tr>
      <td class="fw-semibold">${esc(r.pr_no)}</td>
      <td>${esc(r.title ?? '-')}</td>
      <td>${esc(r.requestor ?? '-')}</td>
      <td>${esc(r.department ?? '-')}</td>
      <td>${esc(r.needed_by ?? '-')}</td>
      <td class="text-end">${fmtMoney(r.estimated_total)}</td>
      <td>${badge(r.status)}</td>
      <td class="text-end">
        <button type="button" class="btn btn-sm btn-outline-secondary me-1" data-action="edit"    data-id="${r.id}">Edit</button>
        ${String(r.status||'').toLowerCase()==='draft' ? `
          <button type="button" class="btn btn-sm btn-primary me-1" data-action="submit"  data-id="${r.id}">Submit</button>` : ``}
        ${String(r.status||'').toLowerCase()==='submitted' ? `
          <button type="button" class="btn btn-sm btn-success me-1" data-action="approve" data-id="${r.id}">Approve</button>
          <button type="button" class="btn btn-sm btn-outline-danger me-1" data-action="reject"  data-id="${r.id}">Reject</button>` : ``}
        ${String(r.status||'').toLowerCase()==='approved' ? `
          <button type="button" class="btn btn-sm btn-violet me-1" data-action="convert" data-id="${r.id}">Convert to PO</button>` : ``}
        ${['approved','submitted'].includes(String(r.status||'').toLowerCase()) ? `
          <button type="button" class="btn btn-sm btn-outline-dark me-1" data-action="cancel"  data-id="${r.id}">Cancel</button>` : ``}
        ${String(r.status||'').toLowerCase()==='draft' ? `
          <button type="button" class="btn btn-sm btn-outline-danger" data-action="delete" data-id="${r.id}">Delete</button>` : ``}
      </td>
    </tr>
  `).join('') : `<tr><td colspan="8" class="text-center py-4 text-muted">No requests found.</td></tr>`;

  const { page, perPage, total } = pagination;
  const totalPages=Math.max(1, Math.ceil(total/perPage));
  document.getElementById('pageInfo').textContent=`Page ${page} of ${totalPages} • ${total} result(s)`;

  const pager=document.getElementById('pager'); pager.innerHTML='';
  const li=(p,l=p,d=false,a=false)=>`
    <li class="page-item ${d?'disabled':''} ${a?'active':''}">
      <a class="page-link" href="#" onclick="go(${p});return false;">${l}</a>
    </li>`;
  pager.insertAdjacentHTML('beforeend', li(page-1,'&laquo;', page<=1));
  for(let p=Math.max(1,page-2); p<=Math.min(totalPages,page+2); p++) pager.insertAdjacentHTML('beforeend', li(p,p,false,p===page));
  pager.insertAdjacentHTML('beforeend', li(page+1,'&raquo;', page>=totalPages));
}
window.go=(p)=>{ if(!p||p<1) return; state.page=p; loadPRs().catch(e=>alert(parseErr(e))); };

document.getElementById('btnFilter').addEventListener('click', ()=>{
  state.page=1;
  state.search=$('#fSearch').value.trim();
  state.status=$('#fStatus').value;
  state.sort=mapSort($('#fSort').value);
  loadPRs().catch(e=>alert(parseErr(e)));
});
document.getElementById('btnReset').addEventListener('click', ()=>{
  $('#fSearch').value=''; $('#fStatus').value=''; $('#fSort').value='newest';
  state={page:1,perPage:10,search:'',status:'',sort:'newest'};
  loadPRs().catch(e=>alert(parseErr(e)));
});

async function loadDepartmentsInto(selectEl){
  try{
    const resp = await fetchJSON(api.depts+'?sort=name&select=1');
    const rows = Array.isArray(resp) ? resp : (resp.rows || []);
    selectEl.innerHTML = '<option value="">— Select —</option>' +
      rows.map(d=>`<option value="${d.id}">${esc(d.name)}</option>`).join('');
  }catch{
    selectEl.innerHTML = '<option value="">— Department —</option>';
  }
}

function addItemRow(row={descr:'', qty:'', price:''}){
  const tr=document.createElement('tr');
  tr.innerHTML=`
    <td><input class="form-control form-control-sm" name="items[descr][]" placeholder="Description" value="${esc(row.descr||'')}" required /></td>
    <td><input type="number" min="0" step="0.0001" class="form-control form-control-sm text-end" name="items[qty][]" value="${row.qty ?? ''}" oninput="recalcRow(this)" /></td>
    <td><input type="number" min="0" step="0.0001" class="form-control form-control-sm text-end" name="items[price][]" value="${row.price ?? ''}" oninput="recalcRow(this)" /></td>
    <td class="text-end"><span class="lineTotal">0.00</span></td>
    <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRow(this)"><ion-icon name="trash-outline"></ion-icon></button></td>
  `;
  document.getElementById('prItemsBody').appendChild(tr);
}
function removeRow(btn){ const tr=btn.closest('tr'); tr?.remove(); recalcAll(); }
function recalcRow(inp){
  const tr = inp.closest('tr');
  const qty = parseFloat(tr.querySelector('input[name="items[qty][]"]').value||'0');
  const price = parseFloat(tr.querySelector('input[name="items[price][]"]').value||'0');
  const lt = (qty*price)||0;
  tr.querySelector('.lineTotal').textContent = lt.toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2});
  recalcAll();
}
function recalcAll(){
  let sum=0;
  document.querySelectorAll('#prItemsBody tr').forEach(tr=>{
    const qty=parseFloat(tr.querySelector('input[name="items[qty][]"]').value||'0');
    const price=parseFloat(tr.querySelector('input[name="items[price][]"]').value||'0');
    sum+= (qty*price)||0;
  });
  document.getElementById('prGrand').textContent = sum.toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2});
}

async function openAddPR(){
  const m = bootstrap.Modal.getOrCreateInstance(document.getElementById('mdlPR'));
  const f = document.getElementById('prForm');
  f.reset(); $('#prId').value='';
  $('#prStatus').value='draft';
  $('#prItemsBody').innerHTML=''; addItemRow(); addItemRow();
  $('#prErr').classList.add('d-none');
  $('#prWarn').classList.add('d-none');
  await loadDepartmentsInto(document.getElementById('prDept'));
  recalcAll();
  m.show();
}

async function openEdit(id){
  const m = bootstrap.Modal.getOrCreateInstance(document.getElementById('mdlPR'));
  const f = document.getElementById('prForm');
  f.reset();
  $('#prId').value = id;
  $('#prItemsBody').innerHTML = '';
  $('#prErr').classList.add('d-none');
  $('#prWarn').classList.add('d-none');

  await loadDepartmentsInto(document.getElementById('prDept'));

  try {
    const j = await fetchJSON(api.get + '?id=' + id);
    $('#prTitle').value     = j.header.title ?? '';
    $('#prNeeded').value    = j.header.needed_by ?? '';
    $('#prPriority').value  = j.header.priority ?? 'normal';
    $('#prRequestor').value = j.header.requestor ?? '';
    $('#prDept').value      = j.header.department_id ?? '';
    $('#prStatus').value    = j.header.status ?? 'draft';
    $('#prNotes').value     = j.header.notes ?? '';
    (j.items || []).forEach(it=> addItemRow({descr:it.descr, qty:it.qty, price:it.price}));
    if (!(j.items||[]).length) addItemRow();
    recalcAll();

    const status = String(j.header.status || 'draft').toLowerCase();
    if (['fulfilled','cancelled'].includes(status)) {
      const warnEl = document.getElementById('prWarn');
      warnEl.textContent = `This request is already ${status.toUpperCase()} and should not be modified.`;
      warnEl.classList.remove('d-none');
    }
    m.show();
  } catch(e) {
    alert(parseErr(e));
  }
}

document.getElementById('btnNewPR').addEventListener('click', openAddPR);
document.getElementById('btnAddItem').addEventListener('click', ()=>addItemRow());

document.getElementById('prForm').addEventListener('submit', async (ev)=>{
  const status = document.getElementById('prStatus').value.toLowerCase();
  if (['fulfilled','cancelled'].includes(status)) {
    ev.preventDefault();
    alert('This request is already ' + status.toUpperCase() + ' and cannot be modified.');
    return;
  }

  ev.preventDefault();
  const form = ev.target;

  document.querySelectorAll('#prItemsBody tr').forEach((tr, i)=>{
    const d  = tr.querySelector('input[name="items[descr][]"]');
    const q  = tr.querySelector('input[name="items[qty][]"]');
    const p  = tr.querySelector('input[name="items[price][]"]');
    const qv = parseFloat(q?.value || '0'), pv = parseFloat(p?.value || '0');
    if (d && d.value.trim()==='' && (qv>0 || pv>0)) d.value = `Item ${i+1}`;
  });
  if (!form.reportValidity()) return;

  try{
    $('#prErr').classList.add('d-none');
    const fd = new FormData(form);
    await fetchJSON(api.save, { method:'POST', body:fd });
    bootstrap.Modal.getOrCreateInstance(document.getElementById('mdlPR')).hide();
    toast('Request saved');
    loadPRs();
  }catch(e){
    const el = document.getElementById('prErr'); el.textContent = parseErr(e); el.classList.remove('d-none');
  }
});

document.getElementById('prBody').addEventListener('click', async (e)=>{
  const btn = e.target.closest('button[data-action]');
  if (!btn) return;
  const id  = btn.dataset.id;
  const act = btn.dataset.action;

  try {
    if (act==='edit')    return openEdit(id);
    if (act==='submit')  return setStatus(id,'submitted');
    if (act==='approve') return setStatus(id,'approved');
    if (act==='reject')  return setStatus(id,'rejected');
    if (act==='cancel')  return setStatus(id,'cancelled');
    if (act==='convert') return convertToPO(id);
    if (act==='delete')  return deletePR(id);
  } catch (err) {
    alert(parseErr(err));
  }
});

async function setStatus(id, status){
  if (!confirm(`Set status to ${status.toUpperCase()}?`)) return;
  const fd = new URLSearchParams(); fd.set('id', id); fd.set('status', status);
  await fetchJSON(api.setSt, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:fd });
  toast('Status updated'); loadPRs();
}

async function convertToPO(id){
  if (!confirm('Create a Purchase Order from this request?')) return;
  try {
    const res = await fetch(api.convert, {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: 'id=' + encodeURIComponent(id),
      credentials: 'same-origin'
    });
    const raw = await res.text();
    let json; try { json = JSON.parse(raw); } catch { throw new Error('Non-JSON response from server: ' + raw.slice(0,120)); }
    if (!res.ok || json.error) throw new Error(json.error || (res.status + ' ' + res.statusText));
    toast(`PO ${json.po_no || json.po_number || '#?'} created from PR`);
    window.location.href = './purchaseOrders.php?pr_id=' + encodeURIComponent(id);
  } catch (e) { alert(parseErr(e)); }
}

async function deletePR(id){
  if(!confirm('Delete this request? (Only drafts can be deleted)')) return;
  await fetchJSON(api.del, {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'id='+encodeURIComponent(id)
  });
  toast('Request deleted'); loadPRs();
}

loadPRs().catch(e=>alert(parseErr(e)));
</script>
</body>
</html>
