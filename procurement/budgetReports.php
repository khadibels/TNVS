<?php
// /procurement/budgetReports.php
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

// preload departments + categories for selects
$depts = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$cats  = $pdo->query("SELECT id, name FROM inventory_categories WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Budget & Reports | Procurement</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="../css/style.css" rel="stylesheet" />
  <link href="../css/modules.css" rel="stylesheet" />
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <script src="../js/sidebar-toggle.js"></script>
  <style>.stat{min-width:180px}</style>
</head>
<body>
<div class="container-fluid p-0">
  <div class="row g-0">

    <!-- Sidebar (Procurement) -->
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
        <a class="nav-link" href="./procurementRequests.php"><ion-icon name="clipboard-outline"></ion-icon><span>Procurement Requests</span></a>
        <a class="nav-link" href="./inventoryView.php"><ion-icon name="archive-outline"></ion-icon><span>Inventory Management</span></a>
        <a class="nav-link active" href="./budgetReports.php"><ion-icon name="analytics-outline"></ion-icon><span>Budget & Reports</span></a>
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
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-3">
          <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2"><ion-icon name="menu-outline"></ion-icon></button>
          <h2 class="m-0">Budget & Reports</h2>
        </div>
        <div class="d-flex align-items-center gap-2">
          <img src="../img/profile.jpg" class="rounded-circle" width="36" height="36" alt="">
          <div class="small">
            <strong><?= htmlspecialchars($userName) ?></strong><br/>
            <span class="text-muted"><?= htmlspecialchars($userRole) ?></span>
          </div>
        </div>
      </div>

      <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabBudgets" type="button" role="tab">Budgets</button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabReports" type="button" role="tab">Reports</button>
        </li>
      </ul>

      <div class="tab-content">
        <!-- Budgets -->
        <div class="tab-pane fade show active" id="tabBudgets" role="tabpanel">
          <section class="card shadow-sm mb-3">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Budget Lines</h5>
                <button class="btn btn-violet" id="btnNew"><ion-icon name="add-circle-outline"></ion-icon> New Budget</button>
              </div>
              <div class="row g-2 mb-2">
                <div class="col-6 col-md-2"><input id="bYear" class="form-control" placeholder="Year (e.g., 2025)"></div>
                <div class="col-6 col-md-2">
                  <select id="bMonth" class="form-select">
                    <option value="">All Months</option>
                    <?php for($m=1;$m<=12;$m++): ?>
                      <option value="<?= $m ?>"><?= date('F', mktime(0,0,0,$m,1)) ?></option>
                    <?php endfor; ?>
                  </select>
                </div>
                <div class="col-6 col-md-3">
                  <select id="bDept" class="form-select">
                    <option value="">All Departments</option>
                    <?php foreach($depts as $d): ?>
                      <option value="<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-6 col-md-3">
                  <select id="bCat" class="form-select">
                    <option value="">All Categories</option>
                    <?php foreach($cats as $c): ?>
                      <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-12 col-md-2 d-grid d-md-flex justify-content-md-end">
                  <button class="btn btn-outline-primary me-md-2" id="bSearch"><ion-icon name="search-outline"></ion-icon> Search</button>
                  <button class="btn btn-outline-secondary" id="bReset">Reset</button>
                </div>
              </div>

              <div class="table-responsive">
                <table class="table align-middle">
                  <thead>
                    <tr>
                      <th>Year</th><th>Month</th><th>Department</th><th>Category</th>
                      <th class="text-end">Amount</th><th>Notes</th><th class="text-end">Actions</th>
                    </tr>
                  </thead>
                  <tbody id="bBody"><tr><td colspan="7" class="text-center py-4">Loading…</td></tr></tbody>
                </table>
              </div>
              <div class="d-flex justify-content-between align-items-center mt-2">
                <div class="small text-muted" id="bPageInfo"></div>
                <nav><ul class="pagination pagination-sm mb-0" id="bPager"></ul></nav>
              </div>
            </div>
          </section>
        </div>

        <!-- Reports -->
        <div class="tab-pane fade" id="tabReports" role="tabpanel">
          <section class="card shadow-sm mb-3">
            <div class="card-body">
              <div class="row g-2 align-items-end">
                <div class="col-6 col-md-3"><label class="form-label">From</label><input id="rFrom" type="date" class="form-control"></div>
                <div class="col-6 col-md-3"><label class="form-label">To</label><input id="rTo" type="date" class="form-control"></div>
                <div class="col-6 col-md-3">
                  <label class="form-label">Department</label>
                  <select id="rDept" class="form-select">
                    <option value="">All</option>
                    <?php foreach($depts as $d): ?><option value="<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option><?php endforeach; ?>
                  </select>
                </div>
                <div class="col-6 col-md-3 d-grid d-md-flex justify-content-md-end">
                  <button class="btn btn-outline-primary me-md-2" id="rRun"><ion-icon name="search-outline"></ion-icon> Run</button>
                  <button class="btn btn-outline-secondary" id="rReset">Reset</button>
                </div>
              </div>
            </div>
          </section>

          <div class="row g-3">
            <div class="col-md-4">
              <div class="card shadow-sm stat p-3">
                <div class="small text-muted">Total PO Spend</div>
                <div class="h4 mb-0" id="rSpendTotal">0.00</div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="card shadow-sm stat p-3">
                <div class="small text-muted">PO Count</div>
                <div class="h4 mb-0" id="rPoCount">0</div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="card shadow-sm stat p-3">
                <div class="small text-muted">Avg PO Value</div>
                <div class="h4 mb-0" id="rPoAvg">0.00</div>
              </div>
            </div>
          </div>

          <section class="card shadow-sm mt-3">
            <div class="card-body">
              <h6>Spend by Supplier</h6>
              <div class="table-responsive">
                <table class="table table-sm">
                  <thead><tr><th>Supplier</th><th class="text-end">Total</th></tr></thead>
                  <tbody id="rBySupplier"></tbody>
                </table>
              </div>
              <hr>
              <h6>Spend by Category</h6>
              <div class="table-responsive">
                <table class="table table-sm">
                  <thead><tr><th>Category</th><th class="text-end">Total</th></tr></thead>
                  <tbody id="rByCategory"></tbody>
                </table>
              </div>
              <hr>
              <h6>Spend by Month</h6>
              <div class="table-responsive">
                <table class="table table-sm">
                  <thead><tr><th>Month</th><th class="text-end">Total</th></tr></thead>
                  <tbody id="rByMonth"></tbody>
                </table>
              </div>
            </div>
          </section>
        </div>
      </div>
    </div> <!-- /Main -->
  </div>
</div>

<div class="toast-container position-fixed top-0 end-0 p-3" id="toasts" style="z-index:1080"></div>

<!-- Modals: New/Edit Budget -->
<div class="modal fade" id="mdlB" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" id="bForm">
      <div class="modal-header"><h5 class="modal-title">Budget</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" name="id" id="bId">
        <div class="row g-3">
          <div class="col-6">
            <label class="form-label">Year</label>
            <input class="form-control" name="fiscal_year" id="bFiscal" required pattern="\d{4}">
          </div>
          <div class="col-6">
            <label class="form-label">Month (optional)</label>
            <select class="form-select" name="month" id="bMon">
              <option value="">— Whole Year —</option>
              <?php for($m=1;$m<=12;$m++): ?>
                <option value="<?= $m ?>"><?= date('F', mktime(0,0,0,$m,1)) ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label">Department (optional)</label>
            <select class="form-select" name="department_id" id="bDeptSel">
              <option value="">— Any —</option>
              <?php foreach($depts as $d): ?><option value="<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label">Category (optional)</label>
            <select class="form-select" name="category_id" id="bCatSel">
              <option value="">— Any —</option>
              <?php foreach($cats as $c): ?><option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label">Amount</label>
            <input class="form-control" type="number" step="0.01" min="0" name="amount" id="bAmt" required>
          </div>
          <div class="col-12">
            <label class="form-label">Notes</label>
            <input class="form-control" name="notes" id="bNotes" maxlength="255">
          </div>
        </div>
        <div class="alert alert-danger d-none mt-3" id="bErr"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" type="submit">Save</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const $ = (s,r=document)=>r.querySelector(s);
async function fetchJSON(url, opts={}){ const res = await fetch(url, opts); if(!res.ok) throw new Error(await res.text()||res.statusText); return res.json(); }
function toast(msg, variant='success', delay=2200){
  let wrap=document.getElementById('toasts');
  if(!wrap){wrap=document.createElement('div');wrap.id='toasts';wrap.className='toast-container position-fixed top-0 end-0 p-3';wrap.style.zIndex=1080;document.body.appendChild(wrap);}
  const el=document.createElement('div'); el.className=`toast text-bg-${variant} border-0`; el.innerHTML=`<div class="d-flex"><div class="toast-body">${msg}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
  wrap.appendChild(el); const t=new bootstrap.Toast(el,{delay}); t.show(); el.addEventListener('hidden.bs.toast',()=>el.remove());
}
function parseErr(e){ try{const j=JSON.parse(e.message); if(j.error) return j.error;}catch{} return e.message||'Request failed'; }

const api = {
  budgets_list : './api/budgets_list.php',
  budgets_save : './api/budgets_save.php',
  budgets_del  : './api/budgets_delete.php',
  rpt_summary  : './api/report_spend_summary.php'
};

/* Budgets list */
let bState = { page:1, year:'', month:'', dept:'', cat:'' };

async function loadBudgets(){
  const qs = new URLSearchParams({ page:bState.page, year:bState.year, month:bState.month, dept:bState.dept, cat:bState.cat });
  const { data, pagination } = await fetchJSON(api.budgets_list+'?'+qs.toString());

  const tbody = document.getElementById('bBody');
  const fmt = v=>Number(v??0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
  tbody.innerHTML = data.length ? data.map(r=>`
    <tr>
      <td>${r.fiscal_year}</td>
      <td>${r.month_name||'-'}</td>
      <td>${r.department||'-'}</td>
      <td>${r.category||'-'}</td>
      <td class="text-end">${fmt(r.amount)}</td>
      <td>${r.notes? r.notes.replace(/[&<>"]/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m])) : ''}</td>
      <td class="text-end">
        <button class="btn btn-sm btn-outline-secondary me-1" onclick="openEdit(${r.id})">Edit</button>
        <button class="btn btn-sm btn-outline-danger" onclick="delBudget(${r.id})">Delete</button>
      </td>
    </tr>
  `).join('') : `<tr><td colspan="7" class="text-center py-4 text-muted">No budgets.</td></tr>`;

  const { page, perPage, total } = pagination;
  const totalPages = Math.max(1, Math.ceil(total/perPage));
  document.getElementById('bPageInfo').textContent = `Page ${page} of ${totalPages} • ${total} result(s)`;
  const pager = document.getElementById('bPager'); pager.innerHTML='';
  const li=(p,l=p,d=false,a=false)=>`<li class="page-item ${d?'disabled':''} ${a?'active':''}">
    <a class="page-link" href="#" onclick="bGo(${p});return false;">${l}</a></li>`;
  pager.insertAdjacentHTML('beforeend', li(page-1,'&laquo;', page<=1));
  for(let p=Math.max(1,page-2); p<=Math.min(totalPages,page+2); p++) pager.insertAdjacentHTML('beforeend', li(p,p,false,p===page));
  pager.insertAdjacentHTML('beforeend', li(page+1,'&raquo;', page>=totalPages));
}
window.bGo=(p)=>{ if(!p||p<1) return; bState.page=p; loadBudgets().catch(e=>alert(parseErr(e))); };

document.getElementById('bSearch').addEventListener('click', ()=>{
  bState.page=1;
  bState.year = $('#bYear').value.trim();
  bState.month = $('#bMonth').value;
  bState.dept = $('#bDept').value;
  bState.cat = $('#bCat').value;
  loadBudgets().catch(e=>alert(parseErr(e)));
});
document.getElementById('bReset').addEventListener('click', ()=>{
  $('#bYear').value=''; $('#bMonth').value=''; $('#bDept').value=''; $('#bCat').value='';
  bState = { page:1, year:'', month:'', dept:'', cat:'' };
  loadBudgets().catch(e=>alert(parseErr(e)));
});

/* Budgets CRUD — FIXED to use form.elements[] */
document.getElementById('btnNew').addEventListener('click', ()=>{
  const f = document.getElementById('bForm');
  f.reset();
  f.elements['id'].value = '';                 // was f.bId
  document.getElementById('bErr').classList.add('d-none');
  bootstrap.Modal.getOrCreateInstance(document.getElementById('mdlB')).show();
});

window.openEdit = async (id)=>{
  const row = await fetchJSON(api.budgets_list+'?id='+encodeURIComponent(id));
  const f = document.getElementById('bForm');
  f.elements['id'].value             = row.id;
  f.elements['fiscal_year'].value    = row.fiscal_year;
  f.elements['month'].value          = row.month ?? '';
  f.elements['department_id'].value  = row.department_id ?? '';
  f.elements['category_id'].value    = row.category_id ?? '';
  f.elements['amount'].value         = row.amount;
  f.elements['notes'].value          = row.notes ?? '';
  document.getElementById('bErr').classList.add('d-none');
  bootstrap.Modal.getOrCreateInstance(document.getElementById('mdlB')).show();
};

document.getElementById('bForm').addEventListener('submit', async (ev)=>{
  ev.preventDefault();
  try{
    document.getElementById('bErr').classList.add('d-none');
    const fd = new FormData(ev.target);
    await fetchJSON(api.budgets_save, { method:'POST', body:fd });
    bootstrap.Modal.getInstance(document.getElementById('mdlB')).hide();
    toast('Budget saved'); loadBudgets();
  }catch(e){
    const el=document.getElementById('bErr'); el.textContent=parseErr(e); el.classList.remove('d-none');
  }
});

async function delBudget(id){
  if(!confirm('Delete budget line?')) return;
  await fetchJSON(api.budgets_del, {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'id='+encodeURIComponent(id)
  });
  toast('Deleted'); loadBudgets();
}

/* Reports */
async function runReports(){
  const qs = new URLSearchParams({ from: $('#rFrom').value, to: $('#rTo').value, dept: $('#rDept').value });
  const j = await fetchJSON(api.rpt_summary+'?'+qs.toString());

  const fmt = v=>Number(v??0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
  document.getElementById('rSpendTotal').textContent = fmt(j.summary.total_spend);
  document.getElementById('rPoCount').textContent   = j.summary.po_count;
  document.getElementById('rPoAvg').textContent     = fmt(j.summary.avg_po);

  const sup = document.getElementById('rBySupplier');
  sup.innerHTML = j.by_supplier.length ? j.by_supplier.map(r=>`<tr><td>${r.supplier||'-'}</td><td class="text-end">${fmt(r.total)}</td></tr>`).join('') : '<tr><td colspan="2" class="text-muted">No data</td></tr>';

  const cat = document.getElementById('rByCategory');
  cat.innerHTML = j.by_category.length ? j.by_category.map(r=>`<tr><td>${r.category||'-'}</td><td class="text-end">${fmt(r.total)}</td></tr>`).join('') : '<tr><td colspan="2" class="text-muted">No data</td></tr>';

  const mon = document.getElementById('rByMonth');
  mon.innerHTML = j.by_month.length ? j.by_month.map(r=>`<tr><td>${r.period}</td><td class="text-end">${fmt(r.total)}</td></tr>`).join('') : '<tr><td colspan="2" class="text-muted">No data</td></tr>';
}
document.getElementById('rRun').addEventListener('click', ()=>runReports().catch(e=>alert(parseErr(e))));
document.getElementById('rReset').addEventListener('click', ()=>{
  $('#rFrom').value=''; $('#rTo').value=''; $('#rDept').value='';
  runReports().catch(e=>alert(parseErr(e)));
});

/* Init */
loadBudgets().catch(e=>alert(parseErr(e)));
runReports().catch(e=>alert(parseErr(e)));
</script>

</body>
</html>
