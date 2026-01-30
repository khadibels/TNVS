<?php
require_once __DIR__ . "/../../includes/config.php";
require_once __DIR__ . "/../../includes/auth.php";
require_once __DIR__ . "/../../includes/db.php";
require_login();
require_role(['admin', 'manager']);

$section = "warehousing";
$active = "inventory";

$wms  = db('wms');
$pdo  = $wms;

$userName = $_SESSION["user"]["name"] ?? "Nicole Malitao";
$userRole = $_SESSION["user"]["role"] ?? "Warehouse Manager";

/* ---- data ---- */
$catNames = $pdo
    ->query(
        "SELECT name FROM inventory_categories WHERE active=1 ORDER BY name"
    )
    ->fetchAll(PDO::FETCH_COLUMN);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Inventory Tracking | TNVS</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="../../css/style.css" rel="stylesheet" />
  <link href="../../css/modules.css" rel="stylesheet" />

  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <script src="../../js/sidebar-toggle.js"></script>

  <style>
    :root {
      --slate-50: #f8fafc;
      --slate-100: #f1f5f9;
      --slate-200: #e2e8f0;
      --slate-600: #475569;
      --slate-800: #1e293b;
      --primary-600: #4f46e5;
    }
    body { background-color: var(--slate-50); }
    
    /* Typography & Utilities */
    .f-mono { font-family: 'SF Mono', 'Segoe UI Mono', 'Roboto Mono', monospace; letter-spacing: -0.5px; }
    .text-label { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.6px; font-weight: 700; color: #94a3b8; margin-bottom: 2px; }
    
    /* Stats Cards */
    .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
    .stat-card { background: white; border: 1px solid var(--slate-200); border-radius: 1rem; padding: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05); display: flex; flex-direction: column; justify-content: space-between; height: 100%; transition: transform 0.2s; }
    .stat-card:hover { transform: translateY(-2px); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
    .stat-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; margin-bottom: 1rem; }
    
    /* Custom Table */
    .card-table { border: 1px solid var(--slate-200); border-radius: 1rem; background: white; box-shadow: 0 1px 3px rgba(0,0,0,0.05); overflow: hidden; }
    .table-custom thead th { 
      font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; 
      color: var(--slate-600); background: var(--slate-50); 
      border-bottom: 1px solid var(--slate-200); font-weight: 600; padding: 1rem 1.5rem;
    }
    .table-custom tbody td { 
      padding: 1rem 1.5rem; border-bottom: 1px solid var(--slate-100); 
      font-size: 0.95rem; color: var(--slate-800); vertical-align: middle;
    }
    .table-custom tr:last-child td { border-bottom: none; }
    .table-custom tr:hover td { background-color: #f8fafc; }
    
    /* Badges & Actions */
    .badge-stock { padding: 5px 10px; border-radius: 6px; font-weight: 600; font-size: 0.75rem; }
    .badge-stock.low { background: #fee2e2; color: #991b1b; }
    .badge-stock.ok { background: #dcfce7; color: #166534; }
    .badge-cat { background: #e0f2fe; color: #075985; border-radius: 99px; padding: 4px 10px; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }

    .btn-icon { width: 32px; height: 32px; padding: 0; display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; }
  </style>
</head>
<body class="saas-page">
  <div class="container-fluid p-0">
    <div class="row g-0">

     <?php include __DIR__ . '/../../includes/sidebar.php'; ?>

      <!-- Main Content -->
      <div class="col main-content p-3 p-lg-4">

        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="d-flex align-items-center gap-3">
              <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2">
                  <ion-icon name="menu-outline"></ion-icon>
              </button>
              <h2 class="m-0 d-flex align-items-center gap-2 page-title">
                  <ion-icon name="cube-outline"></ion-icon>Inventory Tracking
              </h2>
            </div>
            
            <div class="profile-menu" data-profile-menu>
                <button class="profile-trigger" type="button" data-profile-trigger>
                <img src="../../img/profile.jpg" class="rounded-circle" width="36" height="36" alt="">
                <div class="profile-text">
                    <div class="profile-name"><?= h($userName) ?></div>
                    <div class="profile-role"><?= h($userRole) ?></div>
                </div>
                <ion-icon class="profile-caret" name="chevron-down-outline"></ion-icon>
                </button>
                <div class="profile-dropdown" data-profile-dropdown role="menu">
                    <a href="<?= u('auth/logout.php') ?>" role="menuitem">Sign out</a>
                </div>
            </div>
        </div>

        <div>
            <!-- KPI Cards -->
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                        <ion-icon name="cube-outline"></ion-icon>
                    </div>
                    <div>
                        <div class="text-label">Total SKUs</div>
                        <div class="fs-3 fw-bold text-dark mt-1" id="kpiTotal">0</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon bg-danger bg-opacity-10 text-danger">
                        <ion-icon name="alert-circle-outline"></ion-icon>
                    </div>
                    <div>
                        <div class="text-label">Low Stock Items</div>
                        <div class="fs-3 fw-bold text-dark mt-1" id="kpiLow">0</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon bg-success bg-opacity-10 text-success">
                        <ion-icon name="layers-outline"></ion-icon>
                    </div>
                    <div>
                        <div class="text-label">Categories (Raw / Pack)</div>
                        <div class="fs-3 fw-bold text-dark mt-1">
                            <span id="kpiRaw">0</span> <span class="text-muted fw-normal fs-6">/</span> <span id="kpiPack">0</span>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon bg-info bg-opacity-10 text-info">
                        <ion-icon name="trending-up-outline"></ion-icon>
                    </div>
                    <div>
                        <div class="text-label">Stock Value</div>
                        <div class="fs-3 fw-bold text-dark mt-1">—</div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
                <div class="flex-grow-1" style="max-width: 320px;">
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0 text-muted"><ion-icon name="search-outline"></ion-icon></span>
                        <input id="fSearch" class="form-control border-start-0 ps-0" placeholder="Search SKU, Name...">
                    </div>
                </div>
                <select id="fCategory" class="form-select" style="max-width: 200px;">
                    <option value="">All Categories</option>
                    <?php foreach ($catNames as $n): ?>
                    <option value="<?= h($n) ?>"><?= h($n) ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="fSort" class="form-select" style="max-width: 180px;">
                    <option value="latest">Latest First</option>
                    <option value="name">Name (A–Z)</option>
                    <option value="stock">Stock (Asc)</option>
                </select>
                
                <div class="ms-auto d-flex align-items-center gap-3">
                    <div class="form-check m-0">
                        <input class="form-check-input" type="checkbox" id="fArchived">
                        <label class="form-check-label small text-muted" for="fArchived">Show Archived</label>
                    </div>
                    <button id="btnFilter" class="btn btn-white border shadow-sm fw-medium px-3">Filter</button>
                    <button id="btnReset" class="btn btn-link text-decoration-none text-muted p-0">Reset</button>
                    <button class="btn btn-primary d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#addModal">
                        <ion-icon name="add-circle-outline"></ion-icon> <span>Add Item</span>
                    </button>
                </div>
            </div>

            <!-- Table -->
            <div class="card-table">
                <div class="table-responsive">
                    <table class="table table-custom mb-0 align-middle">
                        <thead>
                            <tr>
                                <th style="width:120px">SKU</th>
                                <th>Item Name</th>
                                <th>Category</th>
                                <th class="text-end" style="width:120px">In Stock</th>
                                <th class="text-end" style="width:120px">Reorder Pt</th>
                                <th>Location</th>
                                <th class="text-end" style="width:140px">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="tblBody">
                            <tr><td colspan="7" class="text-center py-5 text-muted">Loading inventory data...</td></tr>
                        </tbody>
                    </table>
                </div>
                <!-- Pagination -->
                <div class="d-flex justify-content-between align-items-center p-3 border-top bg-light bg-opacity-50">
                    <div class="small text-muted" id="pageInfo"></div>
                    <nav><ul class="pagination pagination-sm mb-0 shadow-sm" id="pager"></ul></nav>
                </div>
            </div>
        </div>

      </div>
    </div>
  </div>

  <!-- Add Modal -->
  <div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <form class="modal-content border-0 shadow" id="formAdd">
        <div class="modal-header border-bottom-0 pb-0">
          <h5 class="modal-title fw-bold">Add Inventory Item</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body p-4">
          <div class="row g-3">
            <div class="col-6">
              <label class="form-label text-label">SKU</label>
              <input name="sku" class="form-control" required placeholder="Ex: ITEM-001">
            </div>
            <div class="col-6">
              <label class="form-label text-label">Name</label>
              <input name="name" class="form-control" required placeholder="Item Name">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label text-label">Category</label>
              <select class="form-select" name="category" id="itemCat" required>
                <?php foreach ($catNames as $n): ?>
                  <option value="<?= h($n) ?>"><?= h($n) ?></option>
                <?php endforeach; ?>
              </select>
              <?php if (!$catNames): ?>
                <div class="form-text text-danger">No categories configured.</div>
              <?php endif; ?>
            </div>
            <div class="col-6">
              <label class="form-label text-label">Location</label>
              <input name="location" class="form-control" placeholder="Aisle/Bin">
            </div>
            <div class="col-6">
              <label class="form-label text-label">Reorder Level</label>
              <input name="reorder_level" type="number" min="0" value="0" class="form-control" required>
            </div>
            <div class="col-6">
              <label class="form-label text-label">Stock</label>
              <div class="form-control-plaintext text-muted small">Managed via Stock Mgmt</div>
            </div>
          </div>
          <div class="alert alert-danger d-none mt-3" id="addErr"></div>
        </div>
        <div class="modal-footer border-top-0 pt-0 pb-4 pe-4">
          <button class="btn btn-text text-muted" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary px-4" type="submit">Save Item</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Edit Modal -->
  <div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <form class="modal-content border-0 shadow" id="formEdit">
        <div class="modal-header border-bottom-0 pb-0">
          <h5 class="modal-title fw-bold">Edit Item</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body p-4">
          <input type="hidden" name="id" />
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label text-label">Name</label>
              <input name="name" class="form-control" required>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label text-label">Category</label>
              <select class="form-select" name="category" id="itemCatEdit" required>
                <?php foreach ($catNames as $n): ?>
                  <option value="<?= h($n) ?>"><?= h($n) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label text-label">Location</label>
              <input name="location" class="form-control">
            </div>
            <div class="col-6">
              <label class="form-label text-label">Reorder Level</label>
              <input name="reorder_level" type="number" min="0" class="form-control" required>
            </div>
             <div class="col-6">
              <label class="form-label text-label">Stock</label>
              <div class="form-control-plaintext text-muted small">Managed via Stock Mgmt</div>
            </div>
          </div>
          <div class="alert alert-danger d-none mt-3" id="editErr"></div>
        </div>
        <div class="modal-footer border-top-0 pt-0 pb-4 pe-4">
          <button class="btn btn-text text-muted" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary px-4" type="submit">Update Changes</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Archive Modal -->
  <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <form class="modal-content" id="formDelete">
        <div class="modal-header">
          <h5 class="modal-title">Archive Item</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" />
          <p class="mb-0">Are you sure you want to archive <span id="delName" class="fw-bold"></span>?</p>
          <div class="alert alert-danger d-none mt-3" id="delErr"></div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-warning" type="submit">Archive</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Delete Permanently Modal -->
  <div class="modal fade" id="deleteForeverModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <form class="modal-content" id="formDeleteForever">
        <div class="modal-header">
          <h5 class="modal-title text-danger">Delete Item Permanently</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" />
          <p class="mb-0">
            Permanently delete <span id="delForeverName" class="fw-bold"></span>?
            <br><span class="text-danger small">This action cannot be undone.</span>
          </p>
          <div class="alert alert-danger d-none mt-3" id="delForeverErr"></div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-danger" type="submit">Delete Forever</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Unarchive Modal -->
  <div class="modal fade" id="unarchiveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <form class="modal-content" id="formUnarchive">
        <div class="modal-header">
          <h5 class="modal-title">Unarchive Item</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" />
          <p class="mb-0">Restore <span id="unarchName" class="fw-bold"></span> to active list?</p>
          <div class="alert alert-danger d-none mt-3" id="unarchErr"></div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-success" type="submit">Unarchive</button>
        </div>
      </form>
    </div>
  </div>

  <!-- JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="../../js/profile-dropdown.js"></script>
  <script>
    const api = {
      list:   'list_items.php',
      add:    'add_item.php',
      update: 'update_item.php',
      del:    'archive_item.php',
      unarchive: 'unarchive_item.php',
      delForever: 'delete_item.php'
    };

    let state = { page:1, search:'', category:'', sort:'latest' };
    window.go = (p)=>{ if(!p || p<1) return; state.page=p; loadTable(); };
    const $ = (s, r=document)=>r.querySelector(s);
    
    async function fetchJSON(url, opts={}) {
      const res = await fetch(url, opts);
      if (!res.ok) throw new Error(await res.text() || res.statusText);
      return res.json();
    }
    function esc(s){return String(s??'').replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m]));}
    function jsonify(o){ return JSON.stringify(o).replace(/</g,'\\u003c').replace(/'/g,'\\u0027'); }
    function parseErr(e){ try{const j=JSON.parse(e.message); if(j.error) return j.error; if(j.errors) return j.errors.join(', ');}catch(_){} return e.message||'Request failed'; }
    function showInlineErr(sel, e){ const el=$(sel); el.textContent=parseErr(e); el.classList.remove('d-none'); }

    async function loadTable(){
      try {
        const qs = new URLSearchParams({
            page: state.page,
            search: state.search,
            category: state.category,
            sort: state.sort,
            include_archived: document.getElementById('fArchived').checked ? '1' : ''
        });
        
        const { data, pagination } = await fetchJSON(api.list + '?' + qs.toString());
        const tbody = $('#tblBody');
        
        tbody.innerHTML = data.length ? data.map(r => {
            const isLow = r.stock <= r.reorder_level;
            const stockBadge = isLow 
             ? `<span class="badge-stock low">Low: ${r.stock}</span>`
             : `<span class="badge-stock ok">${r.stock}</span>`;
            
            return `
            <tr>
                <td class="f-mono fw-semibold text-primary">${esc(r.sku)}</td>
                <td class="fw-medium text-dark">${esc(r.name)}</td>
                <td><span class="badge-cat">${esc(r.category)}</span></td>
                <td class="text-end f-mono">${stockBadge}</td>
                <td class="text-end f-mono text-muted">${r.reorder_level}</td>
                <td><span class="text-muted small">${r.location ? esc(r.location) : '—'}</span></td>
                <td class="text-end">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light border" type="button" data-bs-toggle="dropdown">
                            <ion-icon name="ellipsis-horizontal"></ion-icon>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                            <li><a class="dropdown-item" href="#" onclick='openEdit(${r.id}, ${jsonify(r)})'><ion-icon name="create-outline" class="me-2"></ion-icon>Edit</a></li>
                            ${r.archived ? `
                                <li><a class="dropdown-item text-success" href="#" onclick='openUnarchive(${r.id}, ${jsonify(r)})'><ion-icon name="arrow-up-circle-outline" class="me-2"></ion-icon>Unarchive</a></li>
                            ` : `
                                <li><a class="dropdown-item text-warning" href="#" onclick='openDelete(${r.id}, ${jsonify(r)})'><ion-icon name="archive-outline" class="me-2"></ion-icon>Archive</a></li>
                            `}
                            ${r.stock === 0 ? `
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="#" onclick='openDeleteForever(${r.id}, ${jsonify(r)})'><ion-icon name="trash-outline" class="me-2"></ion-icon>Delete Forever</a></li>
                            ` : ``}
                        </ul>
                    </div>
                </td>
            </tr>`;
        }).join('') : `<tr><td colspan="7" class="text-center py-5 text-muted">Thinking... No items found.</td></tr>`;

        // Update KPIs
        $('#kpiTotal').textContent = pagination.total;
        $('#kpiLow').textContent = data.filter(x => x.stock <= x.reorder_level).length;
        $('#kpiRaw').textContent = data.filter(x => x.category === 'Raw').length;
        $('#kpiPack').textContent = data.filter(x => x.category === 'Packaging').length;

        // Pager
        const { page, perPage, total } = pagination;
        const totalPages = Math.max(1, Math.ceil(total/perPage));
        $('#pageInfo').textContent = `Showing ${data.length} of ${total} items`;
        
        const pager = $('#pager'); pager.innerHTML = '';
        const li = (p,l=p,d=false,a=false)=>`
            <li class="page-item ${d?'disabled':''} ${a?'active':''}">
            <a class="page-link" href="#" onclick="go(${p});return false;">${l}</a>
            </li>`;
        
        if(totalPages > 1) {
            pager.insertAdjacentHTML('beforeend', li(page-1,'&laquo;', page<=1));
            for(let p=Math.max(1,page-2); p<=Math.min(totalPages,page+2); p++){
                pager.insertAdjacentHTML('beforeend', li(p,p,false,p===page));
            }
            pager.insertAdjacentHTML('beforeend', li(page+1,'&raquo;', page>=totalPages));
        }
      } catch (e) {
          console.error(e);
          $('#tblBody').innerHTML = `<tr><td colspan="7" class="text-center py-4 text-danger">Error: ${esc(parseErr(e))}</td></tr>`;
      }
    }

    // Event Listeners
    $('#btnFilter').addEventListener('click', ()=>{
      state.page=1;
      state.search=$('#fSearch').value.trim();
      state.category=$('#fCategory').value;
      state.sort=$('#fSort').value;
      loadTable();
    });
    $('#btnReset').addEventListener('click', ()=>{
      $('#fSearch').value=''; $('#fCategory').value=''; $('#fSort').value='latest';
      state={page:1,search:'',category:'',sort:'latest'};
      loadTable();
    });
    $('#fArchived').addEventListener('change', ()=>{ state.page = 1; loadTable(); });

    // UNARCHIVE
    window.openUnarchive = (id, row) => {
      const m = new bootstrap.Modal('#unarchiveModal'); m.show();
      const f = document.getElementById('formUnarchive');
      f.elements['id'].value = id;
      document.getElementById('unarchName').textContent = `${row.sku} — ${row.name}`;
      document.getElementById('unarchErr').classList.add('d-none');
    };
    document.getElementById('formUnarchive').addEventListener('submit', async (ev)=>{
      ev.preventDefault();
      try{
        document.getElementById('unarchErr').classList.add('d-none');
        await fetchJSON(api.unarchive, { method:'POST', body:new FormData(ev.target) });
        bootstrap.Modal.getInstance(document.getElementById('unarchiveModal')).hide();
        loadTable();
      }catch(e){ showInlineErr('#unarchErr', e); }
    });

    // ADD
    document.getElementById('formAdd').addEventListener('submit', async (ev)=>{
      ev.preventDefault();
      try{
        document.getElementById('addErr').classList.add('d-none');
        await fetchJSON(api.add, { method:'POST', body:new FormData(ev.target) });
        ev.target.reset();
        bootstrap.Modal.getInstance(document.getElementById('addModal')).hide();
        loadTable();
      }catch(e){ showInlineErr('#addErr', e); }
    });

    // EDIT
    window.openEdit=(id,row)=>{
      const m=new bootstrap.Modal('#editModal'); m.show();
      const f=document.getElementById('formEdit');
      f.elements['id'].value=id;
      f.elements['name'].value=row.name;
      f.elements['category'].value=row.category;
      f.elements['location'].value=row.location||'';
      f.elements['reorder_level'].value=row.reorder_level;
      document.getElementById('editErr').classList.add('d-none');
    };
    document.getElementById('formEdit').addEventListener('submit', async (ev)=>{
      ev.preventDefault();
      try{
        document.getElementById('editErr').classList.add('d-none');
        await fetchJSON(api.update,{method:'POST',body:new FormData(ev.target)});
        bootstrap.Modal.getInstance(document.getElementById('editModal')).hide();
        loadTable();
      }catch(e){ showInlineErr('#editErr', e); }
    });

    // ARCHIVE (Soft Delete)
    window.openDelete=(id,row)=>{
      const m=new bootstrap.Modal('#deleteModal'); m.show();
      const f=document.getElementById('formDelete');
      f.elements['id'].value=id;
      document.getElementById('delName').textContent=`${row.sku} — ${row.name}`;
      document.getElementById('delErr').classList.add('d-none');
    };
    document.getElementById('formDelete').addEventListener('submit', async (ev)=>{
      ev.preventDefault();
      try{
        document.getElementById('delErr').classList.add('d-none');
        await fetchJSON(api.del,{method:'POST',body:new FormData(ev.target)});
        bootstrap.Modal.getInstance(document.getElementById('deleteModal')).hide();
        loadTable();
      }catch(e){ showInlineErr('#delErr', e); }
    });

    // DELETE FOREVER
    window.openDeleteForever = (id, row) => {
      const m = new bootstrap.Modal('#deleteForeverModal'); m.show();
      const f = document.getElementById('formDeleteForever');
      f.elements['id'].value = id;
      document.getElementById('delForeverName').textContent = `${row.sku} — ${row.name}`;
      document.getElementById('delForeverErr').classList.add('d-none');
    };
    document.getElementById('formDeleteForever').addEventListener('submit', async (ev)=>{
      ev.preventDefault();
      try{
        document.getElementById('delForeverErr').classList.add('d-none');
        await fetchJSON(api.delForever, { method:'POST', body:new FormData(ev.target) });
        bootstrap.Modal.getInstance(document.getElementById('deleteForeverModal')).hide();
        loadTable();
      }catch(e){ showInlineErr('#delForeverErr', e); }
    });

    // Init
    loadTable();
  </script>
</body>
</html>
