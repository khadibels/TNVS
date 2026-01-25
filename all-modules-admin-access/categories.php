<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

require_login();
require_role(['admin']);

$active = 'warehouse_categories';

$userName = 'Admin';
$userRole = 'System Admin';
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
  <title>Inventory Categories | TNVS</title>
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

    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="col main-content p-3 p-lg-4">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-3">
          <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2" aria-label="Toggle sidebar">
            <ion-icon name="menu-outline"></ion-icon>
          </button>
          <h2 class="m-0">Inventory Categories</h2>
        </div>
        <div class="d-flex align-items-center gap-2">
          <img src="../img/profile.jpg" class="rounded-circle" width="36" height="36" alt="">
          <div class="small">
            <strong><?= htmlspecialchars($userName) ?></strong><br/>
            <span class="text-muted"><?= htmlspecialchars($userRole) ?></span>
          </div>
        </div>
      </div>

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
              <tbody id="catBody">
                <tr><td colspan="5" class="text-center text-muted py-4">Loading…</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>
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

<div class="toast-container position-fixed top-0 end-0 p-3" id="toasts" style="z-index:1080"></div>

<script>
  function toast(msg, variant='success', delay=2200){
    const wrap = document.getElementById('toasts');
    const el = document.createElement('div');
    el.className = `toast text-bg-${variant} border-0`; el.role='status';
    el.innerHTML = `<div class="d-flex"><div class="toast-body">${msg}</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
    wrap.appendChild(el); const t=new bootstrap.Toast(el,{delay}); t.show();
    el.addEventListener('hidden.bs.toast', ()=> el.remove());
  }

  async function loadCats(){
    const r = await fetch('../warehousing/api/categories_list.php',{credentials:'same-origin'});
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
    fetch('../warehousing/api/categories_list.php?id='+id,{credentials:'same-origin'})
      .then(r=>r.json()).then(a=>{
        const c=a[0]; if(!c) return;
        catId.value=c.id; catCode.value=c.code; catName.value=c.name;
        catDesc.value=c.description||''; catActive.checked=!!c.active;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('mdlCat')).show();
      });
  }

  async function delCat(id){
    if(!confirm('Delete this category? If in use by items, you’ll get a warning.')) return;
    const r = await fetch('../warehousing/api/categories_delete.php',{method:'POST',credentials:'same-origin',
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
    const r = await fetch('../warehousing/api/categories_save.php',{method:'POST',credentials:'same-origin',body:fd});
    const t = await r.text(); if(!r.ok){ alert(t); return; }
    bootstrap.Modal.getOrCreateInstance(document.getElementById('mdlCat')).hide();
    toast('Saved'); e.target.reset(); loadCats();
  });

  document.addEventListener('DOMContentLoaded', loadCats);
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
