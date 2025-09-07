<?php
require_once __DIR__ . "/../includes/config.php";
require_once __DIR__ . "/../includes/auth.php";
require_role(["admin", "manager"]);

$user = current_user();
$userName = $user["name"] ?? "Guest";
$userRole = $user["role"] ?? "Unknown";
$isAdmin = $userRole === "admin";
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Settings | TNVS</title>

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
      <h6 class="text-uppercase mb-2">Smart Warehousing</h6>
      <nav class="nav flex-column px-2 mb-4">
        <a class="nav-link" href="./warehouseDashboard.php"><ion-icon name="home-outline"></ion-icon><span>Dashboard</span></a>
        <a class="nav-link" href="./inventory/inventoryTracking.php"><ion-icon name="cube-outline"></ion-icon><span>Track Inventory</span></a>
        <a class="nav-link" href="./stockmanagement/stockLevelManagement.php"><ion-icon name="layers-outline"></ion-icon><span>Stock Management</span></a>
        <a class="nav-link" href="./TrackShipment/shipmentTracking.php"><ion-icon name="paper-plane-outline"></ion-icon><span>Track Shipments</span></a>
        <a class="nav-link" href="./warehouseReports.php"><ion-icon name="file-tray-stacked-outline"></ion-icon><span>Reports</span></a>
        <a class="nav-link active" href="./warehouseSettings.php"><ion-icon name="settings-outline"></ion-icon><span>Settings</span></a>
      </nav>
      <div class="logout-section">
        <a class="nav-link text-danger" href="<?= BASE_URL ?>/auth/logout.php">
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
          <h2 class="m-0">Settings</h2>
        </div>
        <div class="d-flex align-items-center gap-2">
          <img src="../img/profile.jpg" class="rounded-circle" width="36" height="36" alt="">
          <div class="small">
            <strong><?= htmlspecialchars($userName) ?></strong><br/>
            <span class="text-muted"><?= htmlspecialchars($userRole) ?></span>
          </div>
        </div>
      </div>

      <!-- Tabs -->
      <ul class="nav nav-tabs settings-tab mb-3" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link active d-flex align-items-center" data-bs-toggle="tab" data-bs-target="#tabLoc" type="button" role="tab">
            <ion-icon name="navigate-outline"></ion-icon> Locations
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link d-flex align-items-center" data-bs-toggle="tab" data-bs-target="#tabCats" type="button" role="tab">
            <ion-icon name="pricetags-outline"></ion-icon> Categories
          </button>
        </li>
      </ul>

      <div class="tab-content">

        <!-- Locations -->
        <div class="tab-pane fade show active" id="tabLoc" role="tabpanel">
          <section class="card shadow-sm">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Warehouse Locations</h5>
                <?php if ($isAdmin): ?>
                <button class="btn btn-violet" data-bs-toggle="modal" data-bs-target="#mdlLoc">
                  <ion-icon name="add-circle-outline"></ion-icon> New Location
                </button>
                <?php endif; ?>
              </div>

              <div class="table-responsive">
                <table class="table align-middle">
                  <thead>
                    <tr><th>Code</th><th>Name</th><th>Address</th><?php if ($isAdmin): ?><th class="text-end">Actions</th><?php endif; ?></tr>
                  </thead>
                  <tbody id="locBody"><tr><td colspan="4" class="text-center text-muted py-4">Loading…</td></tr></tbody>
                </table>
              </div>
            </div>
          </section>
        </div>

        <!-- Categories -->
        <div class="tab-pane fade" id="tabCats" role="tabpanel">
          <section class="card shadow-sm">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Inventory Categories</h5>
                <button class="btn btn-violet" data-bs-toggle="modal" data-bs-target="#mdlCat">
                  <ion-icon name="add-circle-outline"></ion-icon> New Category
                </button>
              </div>

              <div class="table-responsive">
                <table class="table align-middle">
                  <thead>
                    <tr><th>Code</th><th>Name</th><th>Description</th><th>Status</th><th class="text-end">Actions</th></tr>
                  </thead>
                  <tbody id="catBody"><tr><td colspan="5" class="text-center text-muted py-4">Loading…</td></tr></tbody>
                </table>
              </div>
            </div>
          </section>
        </div>

      </div><!-- /tab-content -->

    </div><!-- /main -->
  </div>
</div>

<!-- Location Modal -->
<div class="modal fade" id="mdlLoc" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="locForm">
        <div class="modal-header">
          <h5 class="modal-title">Location</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body row g-3">
          <input type="hidden" name="id" id="locId">
          <div class="col-4">
            <label class="form-label">Code</label>
            <input class="form-control" name="code" id="locCode" required maxlength="32">
          </div>
          <div class="col-8">
            <label class="form-label">Name</label>
            <input class="form-control" name="name" id="locName" required maxlength="128">
          </div>
          <div class="col-12">
            <label class="form-label">Address (optional)</label>
            <input class="form-control" name="address" id="locAddr" maxlength="255">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" type="submit">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Category Modal -->
<div class="modal fade" id="mdlCat" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="catForm">
        <div class="modal-header">
          <h5 class="modal-title">Category</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body row g-3">
          <input type="hidden" name="id" id="catId">
          <div class="col-4">
            <label class="form-label">Code</label>
            <input class="form-control" name="code" id="catCode" maxlength="32" required>
          </div>
          <div class="col-8">
            <label class="form-label">Name</label>
            <input class="form-control" name="name" id="catName" maxlength="80" required>
          </div>
          <div class="col-12">
            <label class="form-label">Description (optional)</label>
            <input class="form-control" name="description" id="catDesc" maxlength="255">
          </div>
          <div class="col-12">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="catActive" name="active" value="1" checked>
              <label class="form-check-label" for="catActive">Active</label>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" type="submit">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Toasts -->
<div class="toast-container position-fixed top-0 end-0 p-3" id="toasts" style="z-index:1080"></div>

<script>
  // --- tiny toast helper
  function toast(msg, variant='success', delay=2200){
    const wrap = document.getElementById('toasts');
    const el = document.createElement('div');
    el.className = `toast text-bg-${variant} border-0`; el.role='status';
    el.innerHTML = `<div class="d-flex"><div class="toast-body">${msg}</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
    wrap.appendChild(el); const t=new bootstrap.Toast(el,{delay}); t.show();
    el.addEventListener('hidden.bs.toast', ()=> el.remove());
  }
  function cleanupBackdrops(){
    document.querySelectorAll('.modal-backdrop').forEach(el=>el.remove());
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('paddingRight');
  }
  document.addEventListener('hidden.bs.modal', cleanupBackdrops);

  // ------- Locations -------
  async function loadLocations(){
    const res = await fetch('./api/locations_list.php',{credentials:'same-origin'});
    const raw = await res.text(); if(!res.ok){ alert(raw); return; }
    const data = JSON.parse(raw);
    const canEdit = <?= $isAdmin ? "true" : "false" ?>;
    document.getElementById('locBody').innerHTML = data.map(r=>`
      <tr>
        <td class="fw-semibold">${r.code}</td>
        <td>${r.name}</td>
        <td>${r.address||''}</td>
        ${canEdit?`<td class="text-end">
          <button class="btn btn-sm btn-outline-primary" onclick="editLoc(${r.id})">Edit</button>
          <button class="btn btn-sm btn-outline-danger" onclick="delLoc(${r.id})">Delete</button>
        </td>`:''}
      </tr>
    `).join('') || '<tr><td colspan="4" class="text-center text-muted py-4">No locations yet.</td></tr>';
  }

  function editLoc(id){
    fetch('./api/locations_list.php?id='+id,{credentials:'same-origin'})
      .then(r=>r.json()).then(r=>{
        const x = r[0]; if(!x) return;
        document.getElementById('locId').value = x.id;
        document.getElementById('locCode').value = x.code;
        document.getElementById('locName').value = x.name;
        document.getElementById('locAddr').value = x.address||'';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('mdlLoc')).show();
      });
  }

  async function delLoc(id){
    if(!confirm('Delete this location?')) return;
    const res = await fetch('./api/locations_delete.php',{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`id=${encodeURIComponent(id)}`});
    const raw = await res.text();
    if(!res.ok){ alert(raw); return; }
    toast('Location deleted','success'); loadLocations();
  }

  document.getElementById('locForm')?.addEventListener('submit', async (e)=>{
    e.preventDefault();
    const btn = e.submitter; if(btn) btn.disabled = true;
    try{
      const res = await fetch('./api/locations_save.php',{method:'POST',credentials:'same-origin', body:new FormData(e.target)});
      const raw = await res.text(); if(!res.ok){ alert(raw); return; }
      bootstrap.Modal.getOrCreateInstance(document.getElementById('mdlLoc')).hide();
      toast('Saved','success'); loadLocations();
      e.target.reset();
    } finally { if(btn) btn.disabled=false; }
  });

  // ------- Categories -------
  async function loadCats(){
    const r = await fetch('./api/categories_list.php',{credentials:'same-origin'});
    const t = await r.text(); if(!r.ok){ alert(t); return; }
    const rows = JSON.parse(t);
    document.getElementById('catBody').innerHTML = rows.map(c=>`
      <tr>
        <td class="fw-semibold">${c.code}</td>
        <td>${c.name}</td>
        <td>${c.description||''}</td>
        <td>${c.active ? 'Active' : '<span class="text-muted">Inactive</span>'}</td>
        <td class="text-end">
          <button class="btn btn-sm btn-outline-primary" onclick="editCat(${c.id})">Edit</button>
          <button class="btn btn-sm btn-outline-danger" onclick="delCat(${c.id})">Delete</button>
        </td>
      </tr>`).join('') || '<tr><td colspan="5" class="text-center text-muted py-4">No categories yet.</td></tr>';
  }

  function editCat(id){
    fetch('./api/categories_list.php?id='+id,{credentials:'same-origin'}).then(r=>r.json()).then(a=>{
      const c=a[0]; if(!c) return;
      catId.value=c.id; catCode.value=c.code; catName.value=c.name;
      catDesc.value=c.description||''; catActive.checked=!!c.active;
      bootstrap.Modal.getOrCreateInstance(document.getElementById('mdlCat')).show();
    });
  }

  async function delCat(id){
    if(!confirm('Delete this category? If in use by items, you’ll get a warning.')) return;
    const r = await fetch('./api/categories_delete.php',{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'id='+encodeURIComponent(id)});
    const t = await r.text();
    if(!r.ok){ alert(t); return; }
    try{ const j=JSON.parse(t); if(j.ok) toast('Category deleted'); else alert(t); }catch{}
    loadCats();
  }

  document.getElementById('catForm')?.addEventListener('submit', async (e)=>{
    e.preventDefault();
    const fd = new FormData(e.target);
    if(!document.getElementById('catActive').checked) fd.set('active','0');
    const r = await fetch('./api/categories_save.php',{method:'POST',credentials:'same-origin',body:fd});
    const t = await r.text(); if(!r.ok){ alert(t); return; }
    bootstrap.Modal.getOrCreateInstance(document.getElementById('mdlCat')).hide();
    toast('Saved'); e.target.reset(); loadCats();
  });

  // init
  document.addEventListener('DOMContentLoaded', ()=>{
    loadLocations();
    loadCats();
  });
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
