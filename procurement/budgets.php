<?php
require_once __DIR__ . "/../includes/config.php";
require_once __DIR__ . "/../includes/auth.php";
require_once __DIR__ . "/../includes/db.php";

require_login();
require_role(['admin','procurement_officer']);

$pdo = db('proc') ?: db('wms');
if (!$pdo instanceof PDO) {
  http_response_code(500);
  if (defined('APP_DEBUG') && APP_DEBUG) {
    die('DB connection for "proc" (or fallback) is not available. Check includes/config.php credentials.');
  }
  die('Internal error');
}

$user     = current_user();
$userName = $user['name'] ?? 'Guest';
$userRole = $user['role'] ?? 'Unknown';

$section = 'procurement';
$active  = 'budgets';


$depts  = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$cats   = $pdo->query("SELECT DISTINCT category FROM logi_wms.inventory_items WHERE category IS NOT NULL AND category<>'' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
$years  = $pdo->query("SELECT DISTINCT fiscal_year FROM budgets ORDER BY fiscal_year DESC")->fetchAll(PDO::FETCH_COLUMN);
$catMap = $pdo->query("SELECT id, name FROM inventory_categories ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Budgets | Procurement (Admin)</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="../css/style.css" rel="stylesheet"/>
  <link href="../css/modules.css" rel="stylesheet"/>
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <script src="../js/sidebar-toggle.js"></script>
  <style>
    .stat{min-width:180px}
    .kpi-card .icon-wrap{width:42px;height:42px;display:flex;align-items:center;justify-content:center;border-radius:12px}
  </style>
</head>
<body>
<div class="container-fluid p-0">
  <div class="row g-0">

  <?php include __DIR__ . '/../includes/sidebar.php' ?>

    <!-- Main -->
    <div class="col main-content p-3 p-lg-4">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-3">
          <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2">
            <ion-icon name="menu-outline"></ion-icon>
          </button>
          <h2 class="m-0">Budgets</h2>
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
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0 d-flex align-items-center gap-2">
              <ion-icon name="wallet-outline"></ion-icon> Budget Lines
            </h5>
            <button class="btn btn-violet" id="btnNew">
              <ion-icon name="add-circle-outline"></ion-icon> New Budget
            </button>
          </div>

          <!-- Filters -->
          <div class="row g-2 mb-2">
            <div class="col-6 col-md-2">
              <select id="bYear" class="form-select">
                <option value="">All Years</option>
                <?php foreach ($years as $y): ?>
                  <option value="<?= (int)$y ?>"><?= (int)$y ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6 col-md-2">
              <select id="bMonth" class="form-select">
                <option value="">All Months</option>
                <?php for ($m=1;$m<=12;$m++): ?>
                  <option value="<?= $m ?>"><?= date("F", mktime(0,0,0,$m,1)) ?></option>
                <?php endfor; ?>
              </select>
            </div>
            <div class="col-6 col-md-3">
              <select id="bDept" class="form-select">
                <option value="">All Departments</option>
                <?php foreach ($depts as $d): ?>
                  <option value="<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6 col-md-3">
              <select id="bCat" class="form-select">
                <option value="">All Categories</option>
                <?php foreach ($cats as $c): ?>
                  <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-md-2 d-grid d-md-flex justify-content-md-end">
              <button class="btn btn-outline-primary me-md-2" id="bSearch">
                <ion-icon name="search-outline"></ion-icon> Search
              </button>
              <button class="btn btn-outline-secondary" id="bReset">Reset</button>
            </div>
          </div>

          <!-- Table -->
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
    </div> <!-- /Main -->
  </div>
</div>

<div class="toast-container position-fixed top-0 end-0 p-3" id="toasts" style="z-index:1080"></div>

<!-- Modal: New/Edit Budget -->
<div class="modal fade" id="mdlB" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" id="bForm">
      <div class="modal-header">
        <h5 class="modal-title">Budget</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
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
              <?php for ($m=1;$m<=12;$m++): ?>
                <option value="<?= $m ?>"><?= date("F", mktime(0,0,0,$m,1)) ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label">Department (optional)</label>
            <select class="form-select" name="department_id">
              <option value="">— Any —</option>
              <?php foreach ($depts as $d): ?>
                <option value="<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label">Category (optional)</label>
            <select class="form-select" name="category_id">
              <option value="">— Any —</option>
              <?php foreach ($catMap as $cid=>$cname): ?>
                <option value="<?= (int)$cid ?>"><?= htmlspecialchars($cname) ?></option>
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
function toast(msg,variant='success',delay=2200){
  let w=document.getElementById('toasts');
  if(!w){w=document.createElement('div');w.id='toasts';w.className='toast-container position-fixed top-0 end-0 p-3';w.style.zIndex=1080;document.body.appendChild(w);}
  const el=document.createElement('div');
  el.className=`toast text-bg-${variant} border-0`;
  el.innerHTML=`<div class="d-flex"><div class="toast-body">${msg}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
  w.appendChild(el);
  const t=new bootstrap.Toast(el,{delay});t.show();
  el.addEventListener('hidden.bs.toast',()=>el.remove());
};

const api = {
  budgets_list : './api/budgets_list.php',
  budgets_save : './api/budgets_save.php',
  budgets_del  : './api/budgets_delete.php'
};

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

document.addEventListener('DOMContentLoaded', ()=>{
  loadBudgets().catch(e=>alert(parseErr(e)));
});
</script>
</body>
</html>
