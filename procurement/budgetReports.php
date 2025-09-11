<?php
declare(strict_types=1);

$inc = __DIR__ . "/../includes";
require_once $inc . "/config.php";
require_once $inc . "/auth.php";
require_once $inc . "/db.php";

require_login();
require_role(['admin','proc_officer']);

// always connect to Warehousing DB (since reports pull from inventory/PO)
$pdo = db('wms');

$user = current_user();
$userName = $user['name'] ?? 'Procurement User';
$userRole = $user['role'] ?? 'Procurement';

// pull filter options from warehousing tables
$depts  = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$cats   = $pdo->query("SELECT DISTINCT category FROM inventory_items WHERE category IS NOT NULL AND category<>'' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
$years  = $pdo->query("SELECT DISTINCT fiscal_year FROM budgets ORDER BY fiscal_year DESC")->fetchAll(PDO::FETCH_COLUMN);
$catMap = $pdo->query("SELECT id, name FROM inventory_categories ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Budget & Reports | Procurement</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="../css/style.css" rel="stylesheet"/>
  <link href="../css/modules.css" rel="stylesheet"/>
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <script src="../js/sidebar-toggle.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
  <style>
    .stat{min-width:180px}
    .kpi-card .icon-wrap{width:42px;height:42px;display:flex;align-items:center;justify-content:center;border-radius:12px}
    .chart-card canvas{width:100%!important;height:300px!important}
  </style>
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
        <a class="nav-link text-danger" href="<?= defined("BASE_URL")
            ? BASE_URL
            : "#" ?>/auth/logout.php">
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
                <h5 class="mb-0 d-flex align-items-center gap-2"><ion-icon name="wallet-outline"></ion-icon> Budget Lines</h5>
                <button class="btn btn-violet" id="btnNew"><ion-icon name="add-circle-outline"></ion-icon> New Budget</button>
              </div>
              <div class="row g-2 mb-2">
                <div class="col-6 col-md-2">
                  <select id="bYear" class="form-select">
                    <option value="">All Years</option>
                    <?php foreach (
                        $years
                        as $y
                    ): ?><option value="<?= (int) $y ?>"><?= (int) $y ?></option><?php endforeach; ?>
                  </select>
                </div>
                <div class="col-6 col-md-2">
                  <select id="bMonth" class="form-select">
                    <option value="">All Months</option>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                      <option value="<?= $m ?>"><?= date(
    "F",
    mktime(0, 0, 0, $m, 1)
) ?></option>
                    <?php endfor; ?>
                  </select>
                </div>
                <div class="col-6 col-md-3">
                  <select id="bDept" class="form-select">
                    <option value="">All Departments</option>
                    <?php foreach ($depts as $d): ?>
                      <option value="<?= (int) $d[
                          "id"
                      ] ?>"><?= htmlspecialchars($d["name"]) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-6 col-md-3">
                  <select id="bCat" class="form-select">
                    <option value="">All Categories</option>
                    <?php foreach ($cats as $c): ?>
                      <option value="<?= htmlspecialchars(
                          $c
                      ) ?>"><?= htmlspecialchars($c) ?></option>
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
              <form class="row g-2 align-items-end" id="rFilter">
                <div class="col-6 col-md-2">
                  <label class="form-label">From</label>
                  <input id="rFrom" type="date" class="form-control">
                </div>
                <div class="col-6 col-md-2">
                  <label class="form-label">To</label>
                  <input id="rTo" type="date" class="form-control">
                </div>
                <div class="col-6 col-md-3">
                  <label class="form-label">Department</label>
                  <select id="rDept" class="form-select">
                    <option value="">All</option>
                    <?php foreach ($depts as $d): ?><option value="<?= (int) $d[
    "id"
] ?>"><?= htmlspecialchars($d["name"]) ?></option><?php endforeach; ?>
                  </select>
                </div>
                <div class="col-6 col-md-3">
                  <label class="form-label">Category</label>
                  <select id="rCat" class="form-select">
                    <option value="">All</option>
                    <?php foreach (
                        $cats
                        as $c
                    ): ?><option value="<?= htmlspecialchars(
    $c
) ?>"><?= htmlspecialchars($c) ?></option><?php endforeach; ?>
                  </select>
                </div>
                <div class="col-12 col-md-2 d-grid d-md-flex gap-2 justify-content-md-end">
                  <button class="btn btn-violet" id="rRun" type="button"><ion-icon name="play-circle-outline"></ion-icon> Run</button>
                  <button class="btn btn-outline-secondary" id="rReset" type="button">Reset</button>
                  <button class="btn btn-outline-secondary" id="rCsv" type="button"><ion-icon name="download-outline"></ion-icon> CSV</button>
                  <button class="btn btn-outline-secondary" id="rPrint" type="button"><ion-icon name="print-outline"></ion-icon> Print</button>
                </div>
              </form>
            </div>
          </section>

          <!-- KPI row (Smart Warehousing style) -->
          <div class="row g-3">
            <div class="col-6 col-md-3">
              <div class="card shadow-sm kpi-card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                  <div class="icon-wrap bg-primary-subtle"><ion-icon name="wallet-outline" style="font-size:20px"></ion-icon></div>
                  <div><div class="text-muted small">Allocated Budget</div><div class="h4 m-0" id="kBudget">0.00</div></div>
                </div>
              </div>
            </div>
            <div class="col-6 col-md-3">
              <div class="card shadow-sm kpi-card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                  <div class="icon-wrap bg-success-subtle"><ion-icon name="card-outline" style="font-size:20px"></ion-icon></div>
                  <div><div class="text-muted small">Actual Spend (PO)</div><div class="h4 m-0" id="kSpend">0.00</div></div>
                </div>
              </div>
            </div>
            <div class="col-6 col-md-3">
              <div class="card shadow-sm kpi-card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                  <div class="icon-wrap bg-info-subtle"><ion-icon name="cash-outline" style="font-size:20px"></ion-icon></div>
                  <div><div class="text-muted small">Remaining</div><div class="h4 m-0" id="kRemain">0.00</div></div>
                </div>
              </div>
            </div>
            <div class="col-6 col-md-3">
              <div class="card shadow-sm kpi-card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                  <div class="icon-wrap bg-warning-subtle"><ion-icon name="speedometer-outline" style="font-size:20px"></ion-icon></div>
                  <div><div class="text-muted small">Utilization %</div><div class="h4 m-0" id="kUtil">0%</div></div>
                </div>
              </div>
            </div>
          </div>

          <!-- Charts row -->
          <div class="row g-3 mt-1">
            <div class="col-12 col-lg-6">
              <div class="card shadow-sm chart-card h-100">
                <div class="card-body">
                  <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="m-0 d-flex align-items-center gap-2"><ion-icon name="business-outline"></ion-icon> Top Suppliers (Spend)</h6>
                  </div>
                  <canvas id="chSuppliers"></canvas>
                </div>
              </div>
            </div>
            <div class="col-12 col-lg-6">
              <div class="card shadow-sm chart-card h-100">
                <div class="card-body">
                  <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="m-0 d-flex align-items-center gap-2"><ion-icon name="pricetags-outline"></ion-icon> Spend by Category</h6>
                  </div>
                  <canvas id="chCategories"></canvas>
                </div>
              </div>
            </div>
          </div>

          <!-- Tables -->
          <section class="card shadow-sm mt-3">
            <div class="card-body">
              <div class="row g-3">
                <div class="col-12 col-lg-6">
                  <h6 class="mb-2 d-flex align-items-center gap-2"><ion-icon name="business-outline"></ion-icon> Spend by Supplier</h6>
                  <div class="table-responsive">
                    <table class="table table-sm">
                      <thead><tr><th>Supplier</th><th class="text-end">Total</th></tr></thead>
                      <tbody id="rBySupplier"></tbody>
                    </table>
                  </div>
                </div>
                <div class="col-12 col-lg-6">
                  <h6 class="mb-2 d-flex align-items-center gap-2"><ion-icon name="pricetags-outline"></ion-icon> Spend by Category</h6>
                  <div class="table-responsive">
                    <table class="table table-sm">
                      <thead><tr><th>Category</th><th class="text-end">Total</th></tr></thead>
                      <tbody id="rByCategory"></tbody>
                    </table>
                  </div>
                </div>
              </div>

              <hr>

              <div class="row g-3">
                <div class="col-12">
                  <h6 class="mb-2 d-flex align-items-center gap-2"><ion-icon name="calendar-outline"></ion-icon> Spend by Month</h6>
                  <div class="table-responsive">
                    <table class="table table-sm">
                      <thead><tr><th>Month</th><th class="text-end">Total</th></tr></thead>
                      <tbody id="rByMonth"></tbody>
                    </table>
                  </div>
                </div>
              </div>

              <hr>

              <div class="row g-3">
                <div class="col-12 col-lg-6">
                  <h6 class="mb-2 d-flex align-items-center gap-2"><ion-icon name="pie-chart-outline"></ion-icon> Budget vs Spend (Dept)</h6>
                  <div class="table-responsive">
                    <table class="table table-sm">
                      <thead><tr><th>Department</th><th class="text-end">Budget</th><th class="text-end">Spend</th><th class="text-end">Util %</th></tr></thead>
                      <tbody id="rDeptUtil"></tbody>
                    </table>
                  </div>
                </div>
                <div class="col-12 col-lg-6">
                  <h6 class="mb-2 d-flex align-items-center gap-2"><ion-icon name="pie-chart-outline"></ion-icon> Budget vs Spend (Category)</h6>
                  <div class="table-responsive">
                    <table class="table table-sm">
                      <thead><tr><th>Category</th><th class="text-end">Budget</th><th class="text-end">Spend</th><th class="text-end">Util %</th></tr></thead>
                      <tbody id="rCatUtil"></tbody>
                    </table>
                  </div>
                </div>
              </div>

            </div>
          </section>
        </div>
      </div>
    </div> <!-- /Main -->
  </div>
</div>

<div class="toast-container position-fixed top-0 end-0 p-3" id="toasts" style="z-index:1080"></div>

<!-- Modal: New/Edit Budget -->
<div class="modal fade" id="mdlB" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" id="bForm">
      <div class="modal-header"><h5 class="modal-title">Budget</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" name="id" id="bId">
        <div class="row g-3">
          <div class="col-6">
            <label class="form-label">Year</label>
            <input class="form-control" name="fiscal_year" required pattern="\d{4}">
          </div>
          <div class="col-6">
            <label class="form-label">Month (optional)</label>
            <select class="form-select" name="month">
              <option value="">— Whole Year —</option>
              <?php for (
                  $m = 1;
                  $m <= 12;
                  $m++
              ): ?><option value="<?= $m ?>"><?= date(
    "F",
    mktime(0, 0, 0, $m, 1)
) ?></option><?php endfor; ?>
            </select>
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label">Department (optional)</label>
            <select class="form-select" name="department_id">
              <option value="">— Any —</option>
              <?php foreach ($depts as $d): ?><option value="<?= (int) $d[
    "id"
] ?>"><?= htmlspecialchars($d["name"]) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label">Category (optional)</label>
            <select class="form-select" name="category_id">
              <option value="">— Any —</option>
              <?php foreach ($catMap as $cid => $cname): ?>
                <option value="<?= (int) $cid ?>"><?= htmlspecialchars(
    $cname
) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label">Amount</label>
            <input class="form-control" type="number" step="0.01" min="0" name="amount" required>
          </div>
          <div class="col-12">
            <label class="form-label">Notes</label>
            <input class="form-control" name="notes" maxlength="255">
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
async function fetchJSON(u,o={}){const r=await fetch(u,o);if(!r.ok)throw new Error(await r.text()||r.statusText);return r.json();}
function parseErr(e){try{const j=JSON.parse(e.message);if(j.error)return j.error;}catch{}return e.message||'Request failed';}
function toast(msg,variant='success',delay=2200){let w=document.getElementById('toasts');if(!w){w=document.createElement('div');w.id='toasts';w.className='toast-container position-fixed top-0 end-0 p-3';w.style.zIndex=1080;document.body.appendChild(w);}const el=document.createElement('div');el.className=`toast text-bg-${variant} border-0`;el.innerHTML=`<div class="d-flex"><div class="toast-body">${msg}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;w.appendChild(el);const t=new bootstrap.Toast(el,{delay});t.show();el.addEventListener('hidden.bs.toast',()=>el.remove());}

const api = {
  budgets_list : './api/budgets_list.php',
  budgets_save : './api/budgets_save.php',
  budgets_del  : './api/budgets_delete.php',
  rpt_summary  : './api/report_spend_summary.php',
  rpt_progress : './api/report_budget_progress.php'
};

/* ---------------- Budgets list ---------------- */
let bState = { page:1, year:'', month:'', dept:'', cat:'' };

async function loadBudgets(){
  const qs = new URLSearchParams({ page:bState.page, year:bState.year, month:bState.month, dept:bState.dept, cat:bState.cat });
  const { data, pagination } = await fetchJSON(api.budgets_list+'?'+qs.toString());
  const fmt = v=>Number(v??0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
  const tbody = document.getElementById('bBody');
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
  bState.year = $('#bYear').value || '';
  bState.month = $('#bMonth').value || '';
  bState.dept = $('#bDept').value || '';
  bState.cat = $('#bCat').value || '';
  loadBudgets().catch(e=>alert(parseErr(e)));
});
document.getElementById('bReset').addEventListener('click', ()=>{
  $('#bYear').value=''; $('#bMonth').value=''; $('#bDept').value=''; $('#bCat').value='';
  bState = { page:1, year:'', month:'', dept:'', cat:'' };
  loadBudgets().catch(e=>alert(parseErr(e)));
});

/* Budgets CRUD */
document.getElementById('btnNew').addEventListener('click', ()=>{
  const f = document.getElementById('bForm');
  f.reset();
  f.elements['id'].value = '';
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

/* ------------- Reports (Smart Warehousing style) ------------- */
let chSuppliers, chCategories;

function writeFiltersToQS(){
  const params = new URLSearchParams({
    from:$('#rFrom')?.value || '',
    to:$('#rTo')?.value || '',
    dept:$('#rDept')?.value || '',
    cat:$('#rCat')?.value || ''
  });
  history.replaceState(null,'','?'+params.toString());
}
function prefillFromQS(){
  const u = new URLSearchParams(location.search);
  if (u.has('from')) $('#rFrom').value = u.get('from');
  if (u.has('to'))   $('#rTo').value   = u.get('to');
  if (u.has('dept')) $('#rDept').value = u.get('dept');
  if (u.has('cat'))  $('#rCat').value  = u.get('cat');
}

function disableChartAnimation(disable=true){
  [chSuppliers, chCategories].forEach(ch=>{
    if(!ch) return;
    ch.options.animation = !disable;
    ch.resize();
    ch.update(disable ? 'none' : undefined);
  });
}

document.getElementById('rPrint').addEventListener('click', ()=>{
  disableChartAnimation(true);
  setTimeout(()=>window.print(), 120);
}, {capture:true});

document.getElementById('rReset').addEventListener('click', ()=>{
  $('#rFrom').value=''; $('#rTo').value=''; $('#rDept').value=''; $('#rCat').value='';
  writeFiltersToQS();
  runReports().catch(e=>alert(parseErr(e)));
});
document.getElementById('rRun').addEventListener('click', ()=>{
  writeFiltersToQS();
  runReports().catch(e=>alert(parseErr(e)));
});

function fmt(v){ return Number(v??0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}); }

async function runReports(){
  const qs = new URLSearchParams({ from:$('#rFrom').value, to:$('#rTo').value, dept:$('#rDept').value, cat:$('#rCat').value });
  const sum  = await fetchJSON(api.rpt_summary +'?'+qs.toString());
  const prog = await fetchJSON(api.rpt_progress+'?'+qs.toString());

  // KPIs
  document.getElementById('kBudget').textContent = fmt(prog.totals?.budget || 0);
  document.getElementById('kSpend').textContent  = fmt(prog.totals?.spend  || 0);
  const remain = (prog.totals?.budget || 0) - (prog.totals?.spend || 0);
  document.getElementById('kRemain').textContent = fmt(remain);
  document.getElementById('kUtil').textContent   = ((prog.totals?.utilization || 0)) + '%';

  // Tables
  const sup = document.getElementById('rBySupplier');
  sup.innerHTML = (sum.by_supplier||[]).map(r=>`<tr><td>${r.supplier||'-'}</td><td class="text-end">${fmt(r.total)}</td></tr>`).join('') || '<tr><td colspan="2" class="text-muted">No data</td></tr>';

  const cat = document.getElementById('rByCategory');
  cat.innerHTML = (sum.by_category||[]).map(r=>`<tr><td>${r.category||'-'}</td><td class="text-end">${fmt(r.total)}</td></tr>`).join('') || '<tr><td colspan="2" class="text-muted">No data</td></tr>';

  const mon = document.getElementById('rByMonth');
  mon.innerHTML = (sum.by_month||[]).map(r=>`<tr><td>${r.period}</td><td class="text-end">${fmt(r.total)}</td></tr>`).join('') || '<tr><td colspan="2" class="text-muted">No data</td></tr>';

  const dTbl = document.getElementById('rDeptUtil');
  dTbl.innerHTML = (prog.by_dept||[]).map(r=>{
    const util = r.budget>0 ? Math.round((r.spend/r.budget)*100) : 0;
    return `<tr><td>${r.department||'-'}</td><td class="text-end">${fmt(r.budget)}</td><td class="text-end">${fmt(r.spend)}</td><td class="text-end">${util}%</td></tr>`;
  }).join('') || '<tr><td colspan="4" class="text-muted">No data</td></tr>';

  const cTbl = document.getElementById('rCatUtil');
  cTbl.innerHTML = (prog.by_cat||[]).map(r=>{
    const util = r.budget>0 ? Math.round((r.spend/r.budget)*100) : 0;
    return `<tr><td>${r.category||'-'}</td><td class="text-end">${fmt(r.budget)}</td><td class="text-end">${fmt(r.spend)}</td><td class="text-end">${util}%</td></tr>`;
  }).join('') || '<tr><td colspan="4" class="text-muted">No data</td></tr>';

  // Charts (top 8 suppliers; categories doughnut)
  const sTop = (sum.by_supplier||[]).slice(0,8);
  const sLabels = sTop.map(r=>r.supplier || '—');
  const sVals   = sTop.map(r=>+r.total||0);
  if (chSuppliers) chSuppliers.destroy();
  chSuppliers = new Chart(document.getElementById('chSuppliers'), {
    type:'bar',
    data:{ labels:sLabels, datasets:[{ label:'Spend', data:sVals, borderWidth:1 }] },
    options:{ maintainAspectRatio:false, scales:{ y:{ beginAtZero:true, ticks:{ precision:0 } } }, plugins:{ legend:{ display:false } } }
  });

  const cTop = (sum.by_category||[]);
  const cLabels = cTop.map(r=>r.category || '—');
  const cVals   = cTop.map(r=>+r.total||0);
  if (chCategories) chCategories.destroy();
  chCategories = new Chart(document.getElementById('chCategories'), {
    type:'doughnut',
    data:{ labels:cLabels, datasets:[{ data:cVals, borderWidth:1 }] },
    options:{ maintainAspectRatio:false, plugins:{ legend:{ position:'bottom' } } }
  });
}

// CSV (client-side)
document.getElementById('rCsv').addEventListener('click', async ()=>{
  try{
    const qs = new URLSearchParams({ from:$('#rFrom').value, to:$('#rTo').value, dept:$('#rDept').value, cat:$('#rCat').value });
    const sum  = await fetchJSON(api.rpt_summary +'?'+qs.toString());
    const prog = await fetchJSON(api.rpt_progress+'?'+qs.toString());
    const lines = [];
    lines.push('Section,Label,Value');
    lines.push(`Totals,Budget,${prog.totals?.budget||0}`);
    lines.push(`Totals,Spend,${prog.totals?.spend||0}`);
    const remain = (prog.totals?.budget||0) - (prog.totals?.spend||0);
    lines.push(`Totals,Remaining,${remain}`);
    lines.push(`Totals,Utilization %,${prog.totals?.utilization||0}`);

    lines.push('');
    lines.push('Spend by Supplier,Supplier,Total');
    (sum.by_supplier||[]).forEach(r=>lines.push(`Supplier,${(r.supplier||'-').replace(/,/g,' ')},${r.total||0}`));

    lines.push('');
    lines.push('Spend by Category,Category,Total');
    (sum.by_category||[]).forEach(r=>lines.push(`Category,${(r.category||'-').replace(/,/g,' ')},${r.total||0}`));

    lines.push('');
    lines.push('Spend by Month,Month,Total');
    (sum.by_month||[]).forEach(r=>lines.push(`Month,${r.period},${r.total||0}`));

    lines.push('');
    lines.push('Dept Utilization,Department,Budget,Spend,Util %');
    (prog.by_dept||[]).forEach(r=>{
      const util = r.budget>0 ? Math.round((r.spend/r.budget)*100) : 0;
      lines.push(`Dept,${(r.department||'-').replace(/,/g,' ')},${r.budget||0},${r.spend||0},${util}`);
    });

    lines.push('');
    lines.push('Category Utilization,Category,Budget,Spend,Util %');
    (prog.by_cat||[]).forEach(r=>{
      const util = r.budget>0 ? Math.round((r.spend/r.budget)*100) : 0;
      lines.push(`Cat,${(r.category||'-').replace(/,/g,' ')},${r.budget||0},${r.spend||0},${util}`);
    });

    const blob = new Blob([lines.join('\n')], {type:'text/csv;charset=utf-8;'});
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = 'procurement_budget_reports.csv';
    document.body.appendChild(a); a.click(); a.remove();
    URL.revokeObjectURL(url);
  }catch(e){ alert(parseErr(e)); }
});

document.addEventListener('DOMContentLoaded', ()=>{
  prefillFromQS();
  loadBudgets().catch(e=>alert(parseErr(e)));
  runReports().catch(e=>alert(parseErr(e)));
});
</script>
</body>
</html>
