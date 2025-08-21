<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login(); 

$catNames = $pdo->query("SELECT name FROM inventory_categories WHERE active=1 ORDER BY name")
               ->fetchAll(PDO::FETCH_COLUMN);
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
</head>
<body>
  <div class="container-fluid p-0">
    <div class="row g-0">

      <!-- Sidebar -->
      <div class="sidebar d-flex flex-column">
        <div class="d-flex justify-content-center align-items-center mb-4 mt-3">
          <img src="../../img/logo.png" id="logo" class="img-fluid me-2" style="height:55px" alt="Logo">
        </div>

        <h6 class="text-uppercase mb-2">Smart Warehousing</h6>

        <nav class="nav flex-column px-2 mb-4">
          <a class="nav-link" href="../warehouseDashboard.php"><ion-icon name="home-outline"></ion-icon><span>Dashboard</span></a>
          <a class="nav-link active" href="inventoryTracking.php"><ion-icon name="cube-outline"></ion-icon><span>Track Inventory</span></a>
          <a class="nav-link" href="../stockmanagement/stockLevelManagement.php"><ion-icon name="layers-outline"></ion-icon><span>Stock Management</span></a>
          <a class="nav-link" href="../TrackShipment/shipmentTracking.php"><ion-icon name="paper-plane-outline"></ion-icon><span>Track Shipments</span></a>
          <a class="nav-link" href="../warehouseReports.php"><ion-icon name="file-tray-stacked-outline"></ion-icon><span>Reports</span></a>
          <a class="nav-link" href="../warehouseSettings.php"><ion-icon name="settings-outline"></ion-icon><span>Settings</span></a>
        </nav>

        <!-- Logout -->
        <div class="logout-section">
          <a class="nav-link text-danger" href="<?= BASE_URL ?>/auth/logout.php">
            <ion-icon name="log-out-outline"></ion-icon> Logout
          </a>
        </div>
      </div>

      <!-- Main Content -->
      <div class="col main-content p-3 p-lg-4">

        <!-- Topbar -->
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div class="d-flex align-items-center gap-3">
            <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2" aria-label="Toggle sidebar">
              <ion-icon name="menu-outline"></ion-icon>
            </button>
            <h2 class="m-0">Inventory Tracking</h2>
          </div>

          <div class="d-flex align-items-center gap-2">
  <!-- New Add Item button -->
  <button class="btn btn-violet" data-bs-toggle="modal" data-bs-target="#addModal">
    <ion-icon name="add-outline"></ion-icon> Add Item
  </button>

  <!-- Profile info -->
  <img src="../../img/profile.jpg" class="rounded-circle" width="36" height="36" alt="">
  <div class="small">
    <strong>Nicole Malitao</strong><br/>
    <span class="text-muted">Warehouse Manager</span>
  </div>
</div>
</div>


        <!-- KPI Cards -->
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
          </div>
        </section>

        <div class="form-check ms-2">
  <input class="form-check-input" type="checkbox" id="fArchived">
  <label class="form-check-label" for="fArchived">Include archived</label>
</div>


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
                    <th class="text-end">Actions</th>
                  </tr>
                </thead>
                <tbody id="tblBody">
                  <tr><td colspan="7" class="text-center py-4">Loading…</td></tr>
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

  <!-- Add Modal -->
  <div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <form class="modal-content" id="formAdd">
        <div class="modal-header">
          <h5 class="modal-title">Add Inventory Item</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-6">
              <label class="form-label">SKU</label>
              <input name="sku" class="form-control" required>
            </div>
            <div class="col-6">
              <label class="form-label">Name</label>
              <input name="name" class="form-control" required>
            </div>
            <div class="col-12 col-md-6">
  <label class="form-label">Category</label>
  <select class="form-select" name="category" id="itemCat" required>
    <?php foreach ($catNames as $n): ?>
      <option value="<?= htmlspecialchars($n) ?>"><?= htmlspecialchars($n) ?></option>
    <?php endforeach; ?>
  </select>
  <?php if (!$catNames): ?>
    <div class="form-text text-danger">No categories yet. Add some in Settings → Categories.</div>
  <?php endif; ?>
</div>
            <div class="col-6">
              <label class="form-label">Location</label>
              <input name="location" class="form-control">
            </div>
            <div class="col-6">
  <label class="form-label">Stock</label>
  <div class="form-control-plaintext">Auto-calculated via Stock Management</div>
</div>

            <div class="col-6">
              <label class="form-label">Reorder Level</label>
              <input name="reorder_level" type="number" min="0" value="0" class="form-control" required>
            </div>
          </div>
          <div class="alert alert-danger d-none mt-3" id="addErr"></div>
        </div>
        <div class="modal-footer">
  <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
  <button class="btn btn-violet" type="submit"><ion-icon name="save-outline"></ion-icon> Save</button>
</div>

      </form>
    </div>
  </div>

  <!-- Edit Modal -->
  <div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <form class="modal-content" id="formEdit">
        <div class="modal-header">
          <h5 class="modal-title">Edit Item</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" />
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Name</label>
              <input name="name" class="form-control" required>
            </div>
            <div class="col-12 col-md-6">
  <label class="form-label">Category</label>
  <select class="form-select" name="category" id="itemCatEdit" required>
    <?php foreach ($catNames as $n): ?>
      <option value="<?= htmlspecialchars($n) ?>"><?= htmlspecialchars($n) ?></option>
    <?php endforeach; ?>
  </select>
  <?php if (!$catNames): ?>
    <div class="form-text text-danger">No categories yet. Add some in Settings → Categories.</div>
  <?php endif; ?>
</div>

            <div class="col-6">
              <label class="form-label">Location</label>
              <input name="location" class="form-control">
            </div>
           <div class="col-6">
  <label class="form-label">Stock</label>
  <div class="form-control-plaintext">Auto-calculated via Stock Management</div>
</div>

            <div class="col-6">
              <label class="form-label">Reorder Level</label>
              <input name="reorder_level" type="number" min="0" class="form-control" required>
            </div>
          </div>
          <div class="alert alert-danger d-none mt-3" id="editErr"></div>
        </div>
        <div class="modal-footer">
  <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
  <button class="btn btn-violet" type="submit"><ion-icon name="create-outline"></ion-icon> Update</button>
</div>

      </form>
    </div>
  </div>

     <!-- Delete Modal -->
  <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <form class="modal-content" id="formDelete">
        <div class="modal-header">
          <h5 class="modal-title">Archive Item</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" />
          <p class="mb-0">Are you sure you want to archive <span id="delName" class="fw-semibold"></span>?</p>
          <div class="alert alert-danger d-none mt-3" id="delErr"></div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">No</button>
          <button class="btn btn-warning" type="submit"><ion-icon name="archive-outline"></ion-icon> Yes, Archive</button>
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
            This will permanently delete <span id="delForeverName" class="fw-semibold"></span>.
            This cannot be undone.
          </p>
          <div class="alert alert-danger d-none mt-3" id="delForeverErr"></div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-danger" type="submit">
            <ion-icon name="trash-outline"></ion-icon> Delete
          </button>
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
        <p class="mb-0">Unarchive <span id="unarchName" class="fw-semibold"></span>?</p>
        <div class="alert alert-danger d-none mt-3" id="unarchErr"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-success" type="submit">
          <ion-icon name="arrow-up-circle-outline"></ion-icon> Yes, Unarchive
        </button>
      </div>
    </form>
  </div>
</div>


  <!-- JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    const api = {
      list:   'list_items.php',
      add:    'add_item.php',
      update: 'update_item.php',
      del:    'archive_item.php',
      unarchive: 'unarchive_item.php',
      delForever: 'delete_item.php' // hard delete :) 
    };

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
  }catch(e){
    showInlineErr('#unarchErr', e);
  }
});


    let state = { page:1, search:'', category:'', sort:'latest' };

    window.go = (p)=>{
      if (!p || p < 1) return;
      state.page = p;
      loadTable().catch(e=>alert(parseErr(e)));
    };

    const $ = (s, r=document)=>r.querySelector(s);

    async function fetchJSON(url, opts={}) {
      const res = await fetch(url, opts);
      if (!res.ok) throw new Error(await res.text() || res.statusText);
      return res.json();
    }
    function esc(s){return String(s??'').replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m]));}
    function jsonify(o){
      return JSON.stringify(o)
        .replace(/</g,'\\u003c')
        .replace(/'/g,'\\u0027');
    }
    function parseErr(e){ try{const j=JSON.parse(e.message); if(j.error) return j.error; if(j.errors) return j.errors.join(', ');}catch(_){} return e.message||'Request failed'; }
    function showInlineErr(sel, e){ const el=$(sel); el.textContent=parseErr(e); el.classList.remove('d-none'); }

    async function loadTable(){
      // Build the query ONCE (now includes the archived checkbox)
      const qs = new URLSearchParams({
        page: state.page,
        search: state.search,
        category: state.category,
        sort: state.sort,
        include_archived: document.getElementById('fArchived').checked ? '1' : ''
      });

      const { data, pagination } = await fetchJSON(api.list + '?' + qs.toString());

      // rows
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
          <td class="text-end">
  <button class="btn btn-sm btn-outline-secondary me-1" onclick='openEdit(${r.id}, ${jsonify(r)})'>
    <ion-icon name="create-outline"></ion-icon> Edit
  </button>

  ${r.archived ? `
    <button class="btn btn-sm btn-success me-1" onclick='openUnarchive(${r.id}, ${jsonify(r)})'>
      <ion-icon name="arrow-up-circle-outline"></ion-icon> Unarchive
    </button>
  ` : `
    <button class="btn btn-sm btn-outline-danger me-1" onclick='openDelete(${r.id}, ${jsonify(r)})'>
      <ion-icon name="archive-outline"></ion-icon> Archive
    </button>
  `}

  ${r.stock === 0 ? `
    <button class="btn btn-sm btn-danger" onclick='openDeleteForever(${r.id}, ${jsonify(r)})'>
      <ion-icon name="trash-outline"></ion-icon> Delete
    </button>` : ``}
</td>

        </tr>`).join('') : '<tr><td colspan="7" class="text-center py-4">No items found.</td></tr>';

      // kpis
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

    // filters
    $('#btnFilter').addEventListener('click', ()=>{
      state.page=1;
      state.search=$('#fSearch').value.trim();
      state.category=$('#fCategory').value;
      state.sort=$('#fSort').value;
      loadTable().catch(e=>alert(parseErr(e)));
    });
    $('#btnReset').addEventListener('click', ()=>{
      $('#fSearch').value=''; $('#fCategory').value=''; $('#fSort').value='latest';
      state={page:1,search:'',category:'',sort:'latest'};
      loadTable().catch(e=>alert(parseErr(e)));
    });

    // Include-archived toggle
    document.getElementById('fArchived').addEventListener('change', ()=>{
      state.page = 1;
      loadTable().catch(e=>alert(parseErr(e)));
    });

    // add
    $('#formAdd').addEventListener('submit', async (ev)=>{
      ev.preventDefault();
      try{
        $('#addErr').classList.add('d-none');
        await fetchJSON(api.add,{method:'POST',body:new FormData(ev.target)});
        bootstrap.Modal.getInstance(document.getElementById('addModal')).hide();
        ev.target.reset(); loadTable();
      }catch(e){ showInlineErr('#addErr', e); }
    });

    // edit
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

    // archive (soft delete)
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

    // hard delete (only when stock == 0)
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
      }catch(e){
        showInlineErr('#delForeverErr', e);
      }
    });

    // init
    loadTable().catch(e=>alert(parseErr(e)));
  </script>

</body>
</html>
