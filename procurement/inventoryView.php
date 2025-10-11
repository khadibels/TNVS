<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();
require_role(['admin', 'procurement_officer']);

$pdo = db('wms');
$catNames = $pdo->query("SELECT name FROM inventory_categories WHERE active=1 ORDER BY name")
               ->fetchAll(PDO::FETCH_COLUMN);


$section = 'procurement';
$active = 'po_inventory';

$userName = "Procurement User";
$userRole = "Procurement";
if (function_exists('current_user')) {
  $u = current_user();
  $userName = $u['name'] ?? $userName;
  $userRole = $u['role'] ?? $userRole;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Inventory Management View | Procurement</title>

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
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-3">
          <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2" aria-label="Toggle sidebar">
            <ion-icon name="menu-outline"></ion-icon>
          </button>
          <h2 class="m-0">Inventory (View Only)</h2>
        </div>
        <div class="d-flex align-items-center gap-2">
          <img src="../img/profile.jpg" class="rounded-circle" width="36" height="36" alt="">
          <div class="small">
            <strong><?= htmlspecialchars($userName) ?></strong><br/>
            <span class="text-muted"><?= htmlspecialchars($userRole) ?></span>
          </div>
        </div>
      </div>

      <!-- KPIs -->
      <section class="stats-cards mb-3">
        <div class="stats-card">
          <div class="icon"><ion-icon name="cube-outline"></ion-icon></div>
          <div class="label">Total Items</div>
          <div class="value" id="kpiTotal">0</div>
        </div>
        <div class="stats-card">
          <div class="icon"><ion-icon name="alert-circle-outline"></ion-icon></div>
          <div class="label">Low Stock</div>
          <div class="value" id="kpiLow">0</div>
        </div>
        <div class="stats-card">
          <div class="icon"><ion-icon name="layers-outline"></ion-icon></div>
          <div class="label">Raw / Packaging</div>
          <div class="value"><span id="kpiRaw">0</span> / <span id="kpiPack">0</span></div>
        </div>
      </section>

      <!-- Filters -->
      <section class="card shadow-sm mb-3">
        <div class="card-body">
          <div class="row g-2 align-items-center">
            <div class="col-12 col-md-4">
              <input id="fSearch" class="form-control" placeholder="Search SKU or Name…">
            </div>
            <div class="col-6 col-md-3">
              <select id="fCategory" class="form-select">
                <option value="">All Categories</option>
                <?php foreach ($catNames as $n): ?>
                  <option value="<?= htmlspecialchars($n) ?>"><?= htmlspecialchars($n) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6 col-md-3">
              <select id="fSort" class="form-select">
                <option value="latest">Latest First</option>
                <option value="name">Name (A–Z)</option>
                <option value="stock">Stock (Low→High)</option>
              </select>
            </div>
            <div class="col-12 col-md-2 d-grid d-md-flex justify-content-md-end">
              <button id="btnFilter" class="btn btn-outline-primary me-md-2">
                <ion-icon name="search-outline"></ion-icon> Search
              </button>
              <button id="btnReset" class="btn btn-outline-secondary">Reset</button>
            </div>
          </div>
          <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" id="fArchived">
            <label class="form-check-label" for="fArchived">Include archived</label>
          </div>
        </div>
      </section>

      <!-- Table -->
      <section class="card shadow-sm">
        <div class="card-body">
          <div class="table-responsive">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th>SKU</th>
                  <th>Name</th>
                  <th>Category</th>
                  <th class="text-end">Stock</th>
                  <th class="text-end">Reorder</th>
                  <th>Location</th>
                </tr>
              </thead>
              <tbody id="tblBody">
                <tr><td colspan="6" class="text-center py-4">Loading…</td></tr>
              </tbody>
            </table>
          </div>

          <div class="d-flex justify-content-between align-items-center mt-2">
            <div class="small text-muted" id="pageInfo"></div>
            <nav><ul class="pagination pagination-sm mb-0" id="pager"></ul></nav>
          </div>
        </div>
      </section>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // reuse the warehousing list endpoint (read-only)
 const api = {
  list: '<?= rtrim(defined("BASE_URL") ? BASE_URL : "", "/") ?>/warehousing/inventory/list_items.php'
};


  const $ = (s, r=document)=>r.querySelector(s);
  let state = { page:1, search:'', category:'', sort:'latest' };

  async function fetchJSON(url, opts={}) {
    const res = await fetch(url, opts);
    if (!res.ok) throw new Error(await res.text() || res.statusText);
    return res.json();
  }
  function esc(s){return String(s??'').replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m]));}
  function parseErr(e){ try{const j=JSON.parse(e.message); if(j.error) return j.error;}catch{} return e.message||'Request failed'; }

  async function loadTable(){
    const qs = new URLSearchParams({
      page: state.page,
      search: state.search,
      category: state.category,
      sort: state.sort,
      include_archived: $('#fArchived').checked ? '1' : ''
    });

    const { data, pagination } = await fetchJSON(api.list + '?' + qs.toString());

    // rows (no actions column)
    const tbody = $('#tblBody');
    tbody.innerHTML = data.length ? data.map(r=>`
      <tr>
        <td>${esc(r.sku)}</td>
        <td>${esc(r.name)}</td>
        <td>${esc(r.category)}</td>
        <td class="text-end">${r.stock}</td>
        <td class="text-end">
          ${r.reorder_level}${r.stock <= r.reorder_level ? ' <span class="badge bg-warning ms-1">Low</span>' : ''}
        </td>
        <td>${r.location ? esc(r.location) : '-'}</td>
      </tr>
    `).join('') : '<tr><td colspan="6" class="text-center py-4">No items found.</td></tr>';

    // KPIs
    $('#kpiTotal').textContent = pagination.total;
    $('#kpiLow').textContent   = data.filter(x => x.stock <= x.reorder_level).length;
    $('#kpiRaw').textContent   = data.filter(x => x.category === 'Raw').length;
    $('#kpiPack').textContent  = data.filter(x => x.category === 'Packaging').length;

    // pager
    const { page, perPage, total } = pagination;
    const totalPages = Math.max(1, Math.ceil(total/perPage));
    $('#pageInfo').textContent = `Page ${page} of ${totalPages} • ${total} result(s)`;
    const pager = $('#pager'); pager.innerHTML = '';
    const li = (p,l=p,d=false,a=false)=>`
      <li class="page-item ${d?'disabled':''} ${a?'active':''}">
        <a class="page-link" href="#" onclick="go(${p});return false;">${l}</a>
      </li>`;
    pager.insertAdjacentHTML('beforeend', li(page-1,'&laquo;', page<=1));
    for(let p=Math.max(1,page-2); p<=Math.min(totalPages,page+2); p++){
      pager.insertAdjacentHTML('beforeend', li(p,p,false,p===page));
    }
    pager.insertAdjacentHTML('beforeend', li(page+1,'&raquo;', page>=totalPages));
  }

  window.go = (p)=>{ if(!p||p<1) return; state.page=p; loadTable().catch(e=>alert(parseErr(e))); };

  // filters
  $('#btnFilter').addEventListener('click', ()=>{
    state.page=1;
    state.search=$('#fSearch').value.trim();
    state.category=$('#fCategory').value;
    state.sort=$('#fSort').value;
    loadTable().catch(e=>alert(parseErr(e)));
  });
  $('#btnReset').addEventListener('click', ()=>{
    $('#fSearch').value=''; $('#fCategory').value=''; $('#fSort').value='latest'; $('#fArchived').checked=false;
    state={page:1,search:'',category:'',sort:'latest'};
    loadTable().catch(e=>alert(parseErr(e)));
  });
  $('#fArchived').addEventListener('change', ()=>{
    state.page=1; loadTable().catch(e=>alert(parseErr(e)));
  });

  loadTable().catch(e=>alert(parseErr(e)));
</script>
</body>
</html>
