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

    /* Custom Table & Card */
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
    
    .f-mono { font-family: 'SF Mono', 'Segoe UI Mono', 'Roboto Mono', monospace; letter-spacing: -0.5px; }

    /* Badges */
    .badge-status { padding: 4px 10px; border-radius: 999px; font-weight: 600; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.5px; border: 1px solid transparent; }
    .badge-status.active { background: #dcfce7; color: #166534; border-color: #bbf7d0; }
    .badge-status.inactive { background: #f1f5f9; color: #475569; border-color: #e2e8f0; }

    /* Actions */
    .btn-action { width: 32px; height: 32px; padding: 0; display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; transition: all 0.2s; color: var(--slate-600); }
    .btn-action:hover { background: var(--slate-100); color: var(--slate-800); }
    .btn-action.edit:hover { background: #eff6ff; color: var(--primary-600); }
    .btn-action.delete:hover { background: #fef2f2; color: #dc2626; }
  </style>
</head>
<body class="saas-page">
<div class="container-fluid p-0">
  <div class="row g-0">

    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="col main-content p-3 p-lg-4">
      
      <!-- Header -->
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-3">
          <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2">
            <ion-icon name="menu-outline"></ion-icon>
          </button>
          <h2 class="m-0 d-flex align-items-center gap-2 page-title">
            <ion-icon name="pricetags-outline"></ion-icon>Inventory Categories
          </h2>
        </div>
        
        <div class="d-flex align-items-center gap-3">
           <button class="btn btn-primary d-flex align-items-center gap-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#mdlCat">
             <ion-icon name="add-circle-outline" class="fs-5"></ion-icon>
             <span>New Category</span>
           </button>

           <div class="profile-menu" data-profile-menu>
            <button class="profile-trigger" type="button" data-profile-trigger>
            <img src="../img/profile.jpg" class="rounded-circle shadow-sm" width="36" height="36" alt="">
            <div class="profile-text">
                <div class="profile-name"><?= htmlspecialchars($userName) ?></div>
                <div class="profile-role"><?= htmlspecialchars($userRole) ?></div>
            </div>
            <ion-icon class="profile-caret" name="chevron-down-outline"></ion-icon>
            </button>
            <div class="profile-dropdown" data-profile-dropdown role="menu">
                <a href="<?= u('auth/logout.php') ?>" role="menuitem">Sign out</a>
            </div>
          </div>
        </div>
      </div>

      <div class="px-4 pb-5">
        <div class="card-table">
          <div class="table-responsive">
            <table class="table table-custom mb-0 align-middle">
              <thead>
                <tr>
                  <th style="width:140px">Code</th>
                  <th>Name</th>
                  <th>Description</th>
                  <th style="width:120px">Status</th>
                  <th class="text-end" style="width:120px">Actions</th>
                </tr>
              </thead>
              <tbody id="catBody">
                <tr><td colspan="5" class="text-center text-muted py-5">Loading…</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- Category Modal -->
<div class="modal fade" id="mdlCat" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <form id="catForm">
        <div class="modal-header border-bottom-0 pb-0">
          <div>
             <h5 class="modal-title fw-bold">Inventory Category</h5>
             <p class="text-muted small mb-0">Create or edit category details</p>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body row g-3">
          <input type="hidden" name="id" id="catId">
          <div class="col-4">
            <label class="form-label text-muted small fw-bold">CODE</label>
            <input class="form-control f-mono" name="code" id="catCode" maxlength="32" required placeholder="e.g. RAW-MAT">
          </div>
          <div class="col-8">
            <label class="form-label text-muted small fw-bold">NAME</label>
            <input class="form-control" name="name" id="catName" maxlength="80" required placeholder="Raw Materials">
          </div>
          <div class="col-12">
            <label class="form-label text-muted small fw-bold">DESCRIPTION</label>
            <textarea class="form-control" name="description" id="catDesc" maxlength="255" rows="2" placeholder="Optional details..."></textarea>
          </div>
          <div class="col-12">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="catActive" name="active" value="1" checked>
              <label class="form-check-label" for="catActive">Active Status</label>
            </div>
          </div>
        </div>
        <div class="modal-footer border-top-0 pt-0">
          <button type="button" class="btn btn-light text-muted" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary px-4" type="submit">Save Category</button>
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
    el.className = `toast text-bg-${variant} border-0 shadow`; el.role='status';
    el.innerHTML = `<div class="d-flex"><div class="toast-body px-3 py-2 fs-6">${msg}</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
    wrap.appendChild(el); const t=new bootstrap.Toast(el,{delay}); t.show();
    el.addEventListener('hidden.bs.toast', ()=> el.remove());
  }

  async function loadCats(){
    try {
      const r = await fetch('../warehousing/api/categories_list.php',{credentials:'same-origin'});
      if(!r.ok) throw new Error(await r.text());
      const rows = await r.json();
      
      const html = rows.map(c=>`
        <tr>
          <td class="f-mono fw-semibold text-primary">${c.code}</td>
          <td class="fw-medium text-dark">${c.name}</td>
          <td class="text-muted small">${c.description||'—'}</td>
          <td>
            ${c.active 
              ? '<span class="badge-status active">Active</span>' 
              : '<span class="badge-status inactive">Inactive</span>'}
          </td>
          <td class="text-end">
            <button class="btn-action edit" onclick="editCat(${c.id})" title="Edit"><ion-icon name="create-outline"></ion-icon></button>
            <button class="btn-action delete" onclick="delCat(${c.id})" title="Remove"><ion-icon name="trash-outline"></ion-icon></button>
          </td>
        </tr>`).join('');
        
      document.getElementById('catBody').innerHTML = html || '<tr><td colspan="5" class="text-center text-muted py-5">No categories found.</td></tr>';
    } catch(e) {
      document.getElementById('catBody').innerHTML = `<tr><td colspan="5" class="text-center text-danger py-5">Error loading categories: ${e.message}</td></tr>`;
    }
  }

  function editCat(id){
    fetch('../warehousing/api/categories_list.php?id='+id,{credentials:'same-origin'})
      .then(r=>r.json()).then(a=>{
        const c=a[0]; if(!c) return;
        catId.value=c.id; catCode.value=c.code; catName.value=c.name;
        catDesc.value=c.description||''; catActive.checked=!!Number(c.active);
        bootstrap.Modal.getOrCreateInstance(document.getElementById('mdlCat')).show();
      });
  }

  async function delCat(id){
    if(!confirm('Are you sure you want to delete this category?')) return;
    try {
      const r = await fetch('../warehousing/api/categories_delete.php',{method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'id='+encodeURIComponent(id)});
      const t = await r.text();
      if(!r.ok) throw new Error(t);
      try{ 
        const j=JSON.parse(t); 
        if(j.ok) toast('Category deleted successfully'); 
        else throw new Error(j.error||t); 
      }catch(e){ throw e; }
      loadCats();
    } catch(e) {
      alert(e.message);
    }
  }

  document.getElementById('catForm')?.addEventListener('submit', async (e)=>{
    e.preventDefault();
    const fd = new FormData(e.target);
    if(!document.getElementById('catActive').checked) fd.set('active','0');
    
    try {
      const r = await fetch('../warehousing/api/categories_save.php',{method:'POST',credentials:'same-origin',body:fd});
      const t = await r.text(); 
      if(!r.ok) throw new Error(t);
      
      bootstrap.Modal.getOrCreateInstance(document.getElementById('mdlCat')).hide();
      toast('Category saved successfully'); 
      e.target.reset(); 
      loadCats();
    } catch(e) {
      alert(e.message);
    }
  });

  document.addEventListener('DOMContentLoaded', loadCats);
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="../js/profile-dropdown.js"></script>
</body>
</html>

