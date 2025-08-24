<?php
// /procurement/purchaseOrders.php
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
  <title>Purchase Orders | Procurement</title>

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

    <!-- Sidebar -->
    <div class="sidebar d-flex flex-column">
      <div class="d-flex justify-content-center align-items-center mb-4 mt-3">
        <img src="../img/logo.png" id="logo" class="img-fluid me-2" style="height:55px" alt="Logo">
      </div>
      <h6 class="text-uppercase mb-2">Procurement</h6>
      <nav class="nav flex-column px-2 mb-4">
        <a class="nav-link" href="./procurementDashboard.php"><ion-icon name="home-outline"></ion-icon><span>Dashboard</span></a>
        <a class="nav-link" href="./supplierManagement.php"><ion-icon name="person-outline"></ion-icon><span>Supplier Management</span></a>
        <a class="nav-link" href="./rfqManagement.php"><ion-icon name="mail-open-outline"></ion-icon><span>RFQs & Sourcing</span></a>
        <a class="nav-link active" href="./purchaseOrders.php"><ion-icon name="document-text-outline"></ion-icon><span>Purchase Orders</span></a>
        <a class="nav-link" href="./procurementRequests.php"><ion-icon name="clipboard-outline"></ion-icon><span>Procurement Requests</span></a>
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

    <!-- Main -->
    <div class="col main-content p-3 p-lg-4">

      <!-- Topbar -->
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-3">
          <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2" aria-label="Toggle sidebar">
            <ion-icon name="menu-outline"></ion-icon>
          </button>
          <h2 class="m-0">Purchase Orders</h2>
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
          <div class="row g-2 align-items-center">
            <div class="col-12 col-md-4">
              <input id="fSearch" class="form-control" placeholder="Search PO No or Notes…">
            </div>
            <div class="col-6 col-md-3">
              <select id="fStatus" class="form-select">
                <option value="" selected>All Status</option>
                <option value="draft">Draft</option>
                <option value="approved">Approved</option>
                <option value="ordered">Ordered</option>
                <option value="received">Received</option>
                <option value="closed">Closed</option>
                <option value="cancelled">Cancelled</option>
              </select>
            </div>
            <div class="col-6 col-md-3">
              <select id="fSort" class="form-select">
                <option value="newest" selected>Newest</option>
                <option value="due">Expected Date</option>
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
            <h5 class="mb-0">Purchase Orders</h5>
            <button class="btn btn-violet" onclick="openAddPO()">
              <ion-icon name="add-circle-outline"></ion-icon> New PO
            </button>
          </div>

          <div class="table-responsive">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th>PO No</th>
                  <th>Supplier</th>
                  <th class="text-end">Total</th>
                  <th>Issue Date</th>
                  <th>Expected</th>
                  <th>Status</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody id="poBody"><tr><td colspan="7" class="text-center py-4">Loading…</td></tr></tbody>
            </table>
          </div>
        </div>
      </section>

      <div class="d-flex justify-content-between align-items-center mt-2">
        <div class="small text-muted" id="pageInfo"></div>
        <nav><ul class="pagination pagination-sm mb-0" id="pager"></ul></nav>
      </div>
    </div><!-- /main -->
  </div><!-- /row -->
</div><!-- /container -->

<!-- Create/Edit PO Modal -->
<div class="modal fade" id="mdlPO" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <form id="poForm">
        <div class="modal-header">
          <h5 class="modal-title">Purchase Order</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" id="poId">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Supplier</label>
              <select class="form-select" id="poSupplier" name="supplier_id" required></select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Issue Date</label>
              <input type="date" class="form-control" id="poIssue" name="issue_date">
            </div>
            <div class="col-md-3">
              <label class="form-label">Expected Date</label>
              <input type="date" class="form-control" id="poExpected" name="expected_date">
            </div>
            <div class="col-md-2">
              <label class="form-label">Status</label>
              <select class="form-select" id="poStatus" name="status">
                <option value="draft" selected>Draft</option>
                <option value="approved">Approved</option>
                <option value="ordered">Ordered</option>
                <option value="received">Received</option>
                <option value="closed">Closed</option>
                <option value="cancelled">Cancelled</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Notes</label>
              <textarea class="form-control" id="poNotes" name="notes" rows="2"></textarea>
            </div>
          </div>

          <hr class="my-4">

          <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0">Items</h6>
            <button type="button" class="btn btn-sm btn-outline-primary" onclick="addItemRow()">Add Item</button>
          </div>

          <div class="table-responsive">
            <table class="table table-sm align-middle table-items">
              <thead>
                <tr>
                  <th style="width:48%">Description</th>
                  <th style="width:14%" class="text-end">Qty</th>
                  <th style="width:18%" class="text-end">Unit Price</th>
                  <th style="width:18%" class="text-end">Line Total</th>
                  <th style="width:2%"></th>
                </tr>
              </thead>
              <tbody id="poItemsBody">
                <!-- rows inserted dynamically -->
              </tbody>
              <tfoot>
                <tr>
                  <th colspan="3" class="text-end">Grand Total</th>
                  <th class="text-end" id="poGrand">0.00</th>
                  <th></th>
                </tr>
              </tfoot>
            </table>
          </div>

          <div class="alert alert-danger d-none" id="poErr"></div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" type="submit">Save PO</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Toasts -->
<div class="toast-container position-fixed top-0 end-0 p-3" id="toasts" style="z-index:1080"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ===== helpers =====
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

// ===== endpoints =====
const api = {
  list  : './api/pos_list.php',
  save  : './api/pos_save.php',
  setSt : './api/pos_set_status.php',
  del   : './api/pos_delete.php',
  listSup: './api/suppliers_list.php',
  get    : './api/pos_get.php'
};

// ===== state & filters =====
let state = { page:1, perPage:10, search:'', status:'', sort:'newest' };
// read ?pr_id= from URL (optional)
const __urlParams = new URLSearchParams(location.search);
const __prFilter = __urlParams.get('pr_id');
if (__prFilter) state.pr_id = __prFilter;

const mapSort=(v)=>v;

// ===== list loader =====
async function loadPOs(){
  // build query string
  const qs = new URLSearchParams({
    page: state.page,
    per_page: state.perPage,
    search: state.search,
    status: state.status,
    sort: state.sort
  });
  if (state.pr_id) qs.set('pr_id', state.pr_id); // add PR filter if present

  // fetch
  const { data, pagination } = await fetchJSON(api.list + '?' + qs.toString());

  const tbody = document.getElementById('poBody');
  const fmtMoney = (v)=> Number(v ?? 0).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2});
  const badge = (s)=>{
    const v=(s||'').toLowerCase();
    const map={draft:'secondary',approved:'info',ordered:'primary',received:'success',closed:'dark',cancelled:'danger'};
    const cls = map[v] || 'secondary';
    return `<span class="badge bg-${cls}">${esc(s||'draft')}</span>`;
  };

  // render rows
  tbody.innerHTML = data.length ? data.map(r=>`
    <tr>
      <td class="fw-semibold">${esc(r.po_no)}</td>
      <td>
        ${esc(r.supplier_name ?? '-')}
        ${r.pr_no ? `<br><span class="badge bg-secondary">From ${esc(r.pr_no)}</span>` : ``}
      </td>
      <td class="text-end">${fmtMoney(r.total)}</td>
      <td>${esc(r.issue_date ?? '-')}</td>
      <td>${esc(r.expected_date ?? '-')}</td>
      <td>${badge(r.status)}</td>
      <td class="text-end">
        <button class="btn btn-sm btn-outline-secondary me-1" onclick='openEdit(${r.id})'>Edit</button>

        ${!['received','closed'].includes(String(r.status||'').toLowerCase())
          ? `
            <button class="btn btn-sm btn-outline-success me-1" onclick="quickReceiveSome(${r.id})">Receive</button>
            <button class="btn btn-sm btn-success me-1" onclick="quickReceiveAll(${r.id})">Receive All</button>
          `
          : ``}

        ${String(r.status||'').toLowerCase()==='draft'
          ? `<button class="btn btn-sm btn-outline-danger" onclick='deletePO(${r.id})'>Delete</button>`
          : ``}
      </td>
    </tr>
  `).join('') : `<tr><td colspan="7" class="text-center py-4 text-muted">No POs found.</td></tr>`;

  // pagination
  const { page, perPage, total } = pagination;
  const totalPages = Math.max(1, Math.ceil(total/perPage));
  document.getElementById('pageInfo').textContent = `Page ${page} of ${totalPages} • ${total} result(s)`;

  const pager = document.getElementById('pager'); pager.innerHTML='';
  const li=(p,l=p,d=false,a=false)=>`
    <li class="page-item ${d?'disabled':''} ${a?'active':''}">
      <a class="page-link" href="#" onclick="go(${p});return false;">${l}</a>
    </li>`;
  pager.insertAdjacentHTML('beforeend', li(page-1,'&laquo;', page<=1));
  for(let p=Math.max(1,page-2); p<=Math.min(totalPages,page+2); p++) pager.insertAdjacentHTML('beforeend', li(p,p,false,p===page));
  pager.insertAdjacentHTML('beforeend', li(page+1,'&raquo;', page>=totalPages));
}
window.go = (p)=>{ if(!p||p<1) return; state.page=p; loadPOs().catch(e=>alert(parseErr(e))); };

// filters
document.getElementById('btnFilter').addEventListener('click', ()=>{
  state.page=1;
  state.search=$('#fSearch').value.trim();
  state.status=$('#fStatus').value;
  state.sort=mapSort($('#fSort').value);
  loadPOs().catch(e=>alert(parseErr(e)));
});
document.getElementById('btnReset').addEventListener('click', ()=>{
  $('#fSearch').value=''; $('#fStatus').value=''; $('#fSort').value='newest';
  state={page:1,perPage:10,search:'',status:'',sort:'newest'};
  loadPOs().catch(e=>alert(parseErr(e)));
});

// ===== supplier select =====
async function loadSuppliers(selectEl){
  const resp = await fetchJSON(api.listSup + '?status=1&sort=name&select=1');
  const rows = Array.isArray(resp) ? resp : (resp.rows || []);
  selectEl.innerHTML = rows.map(s=>`<option value="${s.id}">${esc(s.code)} — ${esc(s.name)}</option>`).join('');
}

// ===== quick receive helpers =====
async function quickReceiveAll(poId){
  if(!confirm('Receive all remaining quantities for this PO?')) return;
  try{
    const j = await fetchJSON('./api/pos_get.php?id=' + poId);
    const form = new FormData();
    form.set('po_id', poId);
    (j.items || []).forEach(it=>{
      const remaining = Math.max(0, (parseFloat(it.qty)||0) - (parseFloat(it.qty_received)||0));
      if (remaining > 0) form.set(`items[${it.id}][qty]`, String(remaining));
    });
    const res = await fetchJSON('./api/pos_receive.php', { method:'POST', body: form });
    toast(`Received • ${res.lines_updated} line(s) • status: ${res.status}`);
    loadPOs();
  }catch(e){ alert(parseErr(e)); }
}

async function quickReceiveSome(poId){
  try{
    const j = await fetchJSON('./api/pos_get.php?id=' + poId);
    const form = new FormData();
    form.set('po_id', poId);
    for (const it of (j.items||[])) {
      const remaining = Math.max(0, (parseFloat(it.qty)||0) - (parseFloat(it.qty_received)||0));
      if (remaining <= 0) continue;
      const ans = prompt(`Receive "${it.descr}"\nOrdered: ${it.qty}\nReceived: ${it.qty_received}\nRemaining: ${remaining}\n\nEnter qty to receive now:`, remaining);
      if (ans===null) continue;
      const n = parseFloat(ans);
      if (!isNaN(n) && n>0) form.set(`items[${it.id}][qty]`, String(n));
    }
    const hasLines = Array.from(form.keys()).some(k=>k.startsWith('items['));
    if (!hasLines) { toast('No lines chosen', 'warning'); return; }
    const res = await fetchJSON('./api/pos_receive.php', { method:'POST', body: form });
    toast(`Received • ${res.lines_updated} line(s) • status: ${res.status}`);
    loadPOs();
  }catch(e){ alert(parseErr(e)); }
}

// ===== items grid =====
function addItemRow(row={descr:'', qty:'', price:''}){
  const tr=document.createElement('tr');
  tr.innerHTML=`
    <td><input class="form-control form-control-sm" name="items[descr][]" placeholder="Description" value="${esc(row.descr||'')}" required /></td>
    <td><input type="number" min="0" step="0.0001" class="form-control form-control-sm text-end" name="items[qty][]" value="${row.qty ?? ''}" oninput="recalcRow(this)" /></td>
    <td><input type="number" min="0" step="0.0001" class="form-control form-control-sm text-end" name="items[price][]" value="${row.price ?? ''}" oninput="recalcRow(this)" /></td>
    <td class="text-end"><span class="lineTotal">0.00</span></td>
    <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRow(this)"><ion-icon name="trash-outline"></ion-icon></button></td>
  `;
  document.getElementById('poItemsBody').appendChild(tr);
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
  document.querySelectorAll('#poItemsBody tr').forEach(tr=>{
    const qty=parseFloat(tr.querySelector('input[name="items[qty][]"]').value||'0');
    const price=parseFloat(tr.querySelector('input[name="items[price][]"]').value||'0');
    sum+= (qty*price)||0;
  });
  document.getElementById('poGrand').textContent = sum.toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2});
}

// ===== open modals =====
window.openAddPO = async ()=>{
  const m = bootstrap.Modal.getOrCreateInstance(document.getElementById('mdlPO')); m.show();
  const f = document.getElementById('poForm');
  f.reset(); $('#poId').value='';
  $('#poStatus').value='draft';
  $('#poItemsBody').innerHTML=''; addItemRow(); addItemRow();
  $('#poErr').classList.add('d-none');
  await loadSuppliers(document.getElementById('poSupplier'));
  recalcAll();
};

window.openEdit = async (id)=>{
  const m = bootstrap.Modal.getOrCreateInstance(document.getElementById('mdlPO'));
  m.show();
  const f = document.getElementById('poForm');
  f.reset();
  $('#poId').value = id;
  $('#poItemsBody').innerHTML = '';
  $('#poErr').classList.add('d-none');

  await loadSuppliers(document.getElementById('poSupplier'));

  try {
    const j = await fetchJSON(api.get + '?id=' + id);

    // fill header
    $('#poSupplier').value   = j.header.supplier_id;
    $('#poIssue').value      = j.header.order_date ?? '';
    $('#poExpected').value   = j.header.expected_date ?? '';
    $('#poStatus').value     = j.header.status ?? 'draft';
    $('#poNotes').value      = j.header.notes ?? '';

    // fill items
    (j.items || []).forEach(it=>{
      addItemRow({descr:it.descr, qty:it.qty, price:it.price});
    });
    if (!(j.items||[]).length) addItemRow();
    recalcAll();

  } catch(e) {
    alert(parseErr(e));
  }
};

// save PO
document.getElementById('poForm').addEventListener('submit', async (ev)=>{
  ev.preventDefault();
  const form = ev.target;

 
  document.querySelectorAll('#poItemsBody tr').forEach((tr, i)=>{
    const d  = tr.querySelector('input[name="items[descr][]"]');
    const q  = tr.querySelector('input[name="items[qty][]"]');
    const p  = tr.querySelector('input[name="items[price][]"]');
    const qv = parseFloat(q?.value || '0'), pv = parseFloat(p?.value || '0');
    if (d && d.value.trim()==='' && (qv>0 || pv>0)) d.value = `Item ${i+1}`;
  });

  if (!form.reportValidity()) return;

  try{
    $('#poErr').classList.add('d-none');
    const fd = new FormData(form);
    const j  = await fetchJSON(api.save, { method:'POST', body:fd });
    bootstrap.Modal.getOrCreateInstance(document.getElementById('mdlPO')).hide();
    toast('PO saved'); loadPOs();
  }catch(e){
    const el = document.getElementById('poErr'); el.textContent = parseErr(e); el.classList.remove('d-none');
  }
});


document.querySelectorAll('#poItemsBody tr').forEach(tr=>{
  const d = tr.querySelector('input[name="items[descr][]"]')?.value.trim();
  const q = parseFloat(tr.querySelector('input[name="items[qty][]"]')?.value || '0');
  const p = parseFloat(tr.querySelector('input[name="items[price][]"]')?.value || '0');
  if (!d && q===0 && p===0) tr.remove();
});

// delete PO
window.deletePO = async (id)=>{
  if(!confirm('Delete this PO? (Only drafts can be deleted)')) return;
  try{
    await fetchJSON(api.del, { method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:'id='+encodeURIComponent(id)
    });
    toast('PO deleted'); loadPOs();
  }catch(e){ alert(parseErr(e)); }
};

// ===== initial =====
loadPOs().catch(e=>alert(parseErr(e)));
</script>

</body>
</html>
