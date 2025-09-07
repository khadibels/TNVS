<?php
$inc = __DIR__ . "/../includes";
if (file_exists($inc . "/config.php")) {
    require_once $inc . "/config.php";
}
if (file_exists($inc . "/auth.php")) {
    require_once $inc . "/auth.php";
}
if (function_exists("require_login")) {
    require_login();
}

require_role(['admin', 'proc_officer']);

$userName = "Procurement User";
$userRole = "Procurement";

$section = 'procurement';
$active = 'suppliers';

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
  <title>Supplier Management | Procurement</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="../css/style.css" rel="stylesheet" />
  <link href="../css/modules.css" rel="stylesheet" />
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <script src="../js/sidebar-toggle.js"></script>
  <style>
    .kpi-card .icon-wrap{width:42px;height:42px;display:flex;align-items:center;justify-content:center;border-radius:12px}
  </style>
</head>
<body>
<div class="container-fluid p-0">
  <div class="row g-0">

    <?php include __DIR__ . '/../includes/sidebar.php' ?>

    <!-- Main -->
    <div class="col main-content p-3 p-lg-4">

      <!-- Topbar -->
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-3">
          <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2" aria-label="Toggle sidebar">
            <ion-icon name="menu-outline"></ion-icon>
          </button>
          <h2 class="m-0">Supplier Management</h2>
        </div>
        <div class="d-flex align-items-center gap-2">
          <img src="../img/profile.jpg" class="rounded-circle" width="36" height="36" alt="">
          <div class="small">
            <strong><?= htmlspecialchars($userName) ?></strong><br/>
            <span class="text-muted"><?= htmlspecialchars($userRole) ?></span>
          </div>
        </div>
      </div>

      <!-- Filters / Actions -->
      <section class="card shadow-sm mb-3">
        <div class="card-body">
          <div class="row g-2 align-items-center">
            <div class="col-12 col-md-4">
              <input id="fSearch" class="form-control" placeholder="Search code, name, contact…">
            </div>
            <div class="col-6 col-md-3">
              <select id="fStatus" class="form-select">
                <option value="">All Status</option>
                <option value="1" selected>Active</option>
                <option value="0">Inactive</option>
              </select>
            </div>
            <div class="col-6 col-md-3">
              <select id="fSort" class="form-select">
                <option value="name">Name (A–Z)</option>
                <option value="new">Newest</option>
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
            <h5 class="mb-0">Suppliers</h5>
            <button class="btn btn-violet" data-bs-toggle="modal" data-bs-target="#mdlSup">
              <ion-icon name="add-circle-outline"></ion-icon> New Supplier
            </button>
          </div>

          <div class="table-responsive">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th>Code</th><th>Name</th><th>Contact</th><th>Email</th><th>Phone</th>
                  <th>Rating</th><th>Lead Time (d)</th><th>Status</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody id="supBody"><tr><td colspan="9" class="text-center py-4">Loading…</td></tr></tbody>
            </table>

            <!-- Info + per-page -->
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mt-2">
              <div id="supInfo" class="text-muted small mb-2 mb-md-0">Loading…</div>
              <div class="d-flex align-items-center gap-2">
                <label class="form-label me-2 mb-0 small">Show</label>
                <select id="perPage" class="form-select form-select-sm" style="width:auto">
                  <option value="10" selected>10</option>
                  <option value="25">25</option>
                  <option value="50">50</option>
                  <option value="100">100</option>
                </select>
                <span class="small ms-1">per page</span>
              </div>
            </div>

            <!-- Pager -->
            <nav class="mt-2">
              <ul id="supPager" class="pagination pagination-sm justify-content-center mb-0"></ul>
            </nav>

          </div>
        </div>
      </section>

    </div><!-- /main -->
  </div><!-- /row -->
</div><!-- /container -->

<!-- Add/Edit Modal -->
<div class="modal fade" id="mdlSup" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form id="supForm">
        <div class="modal-header">
          <h5 class="modal-title">Supplier</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <input type="hidden" name="id" id="supId">
            <div class="col-12 col-md-4">
              <label class="form-label">Code</label>
              <input class="form-control" name="code" id="supCode" maxlength="32" required>
            </div>
            <div class="col-12 col-md-8">
              <label class="form-label">Name</label>
              <input class="form-control" name="name" id="supName" maxlength="120" required>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Contact Person</label>
              <input class="form-control" name="contact_person" id="supContact" maxlength="120">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Email</label>
              <input type="email" class="form-control" name="email" id="supEmail" maxlength="120">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Phone</label>
              <input class="form-control" name="phone" id="supPhone" maxlength="50">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Payment Terms</label>
              <input class="form-control" name="payment_terms" id="supTerms" maxlength="60">
            </div>
            <div class="col-12">
              <label class="form-label">Address</label>
              <input class="form-control" name="address" id="supAddr" maxlength="255">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label">Rating (1–5)</label>
              <input type="number" class="form-control" name="rating" id="supRating" min="1" max="5">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label">Lead Time (days)</label>
              <input type="number" class="form-control" name="lead_time_days" id="supLead" min="0" value="0">
            </div>
            <div class="col-12">
              <label class="form-label">Notes</label>
              <textarea class="form-control" name="notes" id="supNotes" rows="2"></textarea>
            </div>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="supActive" name="is_active" value="1" checked>
                <label class="form-check-label" for="supActive">Active</label>
              </div>
            </div>
          </div>
          <div class="alert alert-danger d-none mt-3" id="supErr"></div>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Helpers
  function toast(msg, variant='success', delay=2200){
    const wrap = document.getElementById('toasts');
    const el = document.createElement('div');
    el.className = `toast text-bg-${variant} border-0`; el.role='status';
    el.innerHTML = `<div class="d-flex"><div class="toast-body">${msg}</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
    wrap.appendChild(el); const t=new bootstrap.Toast(el,{delay}); t.show();
    el.addEventListener('hidden.bs.toast', ()=> el.remove());
  }
  const $ = (s, r=document)=>r.querySelector(s);
  function esc(s){return String(s??'').replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m]));}
  function parseErr(e){ try{const j=JSON.parse(e.message); if(j.error) return j.error; if(j.errors) return j.errors.join(', ');}catch(_){} return e.message||'Request failed'; }
  function showErr(sel, e){ const el=$(sel); el.textContent=parseErr(e); el.classList.remove('d-none'); }
  async function fetchJSON(url, opts={}) {
    const res = await fetch(url, opts);
    if (!res.ok) throw new Error(await res.text() || res.statusText);
    return res.json();
  }

  const api = {
    list: './api/suppliers_list.php',
    save: './api/suppliers_save.php',
    del : './api/suppliers_delete.php'
  };

  let state = { search:'', status:'1', sort:'name', page:1, per:10 };

  // URL <-> state
  function readUrlIntoState(){
    const url = new URL(location.href);
    state.search = url.searchParams.get('search') ?? state.search;
    state.status = url.searchParams.get('status') ?? state.status;
    state.sort   = url.searchParams.get('sort')   ?? state.sort;
    state.page   = parseInt(url.searchParams.get('page') ?? state.page, 10) || 1;
    state.per    = parseInt(url.searchParams.get('per')  ?? state.per, 10) || 10;

    const fS  = document.getElementById('fSearch');
    const fSt = document.getElementById('fStatus');
    const fSo = document.getElementById('fSort');
    const fPer= document.getElementById('perPage');
    if (fS)  fS.value  = state.search;
    if (fSt) fSt.value = state.status;
    if (fSo) fSo.value = state.sort;
    if (fPer) fPer.value = String(state.per);
  }
  function syncUrl(){
    const url = new URL(location.href);
    url.searchParams.set('search', state.search);
    url.searchParams.set('status', state.status);
    url.searchParams.set('sort',   state.sort);
    url.searchParams.set('page',   String(state.page));
    url.searchParams.set('per',    String(state.per));
    history.replaceState(null, '', url.toString());
  }
  window.addEventListener('popstate', ()=>{
    readUrlIntoState();
    loadSuppliers().catch(e=>alert(parseErr(e)));
  });

  function formatInfo(page, per, total){
    const from = total ? (per*(page-1) + 1) : 0;
    const to   = Math.min(total, per*page);
    return `Showing ${from}–${to} of ${total}`;
  }

  function buildPager(page, pages){
    const ul = document.getElementById('supPager');
    ul.innerHTML = '';

    const add = (label, targetPage, {disabled=false, active=false, title=''}={})=>{
      const li = document.createElement('li');
      li.className = `page-item ${disabled?'disabled':''} ${active?'active':''}`;
      const a = document.createElement('a');
      a.className = 'page-link';
      a.href = '#';
      if (title) a.title = title;
      a.textContent = label;
      a.onclick = (e)=>{ 
        e.preventDefault();
        if (disabled || active) return;
        state.page = targetPage;
        syncUrl();
        loadSuppliers().catch(e=>alert(parseErr(e)));
      };
      li.appendChild(a);
      ul.appendChild(li);
    };

    // Prev
    add('«', Math.max(1, page-1), {disabled: page<=1, title:'Previous page'});

    // Number window
    const windowSize = 2;
    const start = Math.max(1, page - windowSize);
    const end   = Math.min(pages, page + windowSize);

    if (start > 1) add('1', 1, {active: page===1});
    if (start > 2) {
      const li = document.createElement('li');
      li.className = 'page-item disabled';
      li.innerHTML = '<span class="page-link">…</span>';
      ul.appendChild(li);
    }

    for (let i=start; i<=end; i++) add(String(i), i, {active: i===page});

    if (end < pages-1) {
      const li = document.createElement('li');
      li.className = 'page-item disabled';
      li.innerHTML = '<span class="page-link">…</span>';
      ul.appendChild(li);
    }
    if (end < pages) add(String(pages), pages, {active: pages===page});

    // Next
    add('»', Math.min(pages, page+1), {disabled: page>=pages, title:'Next page'});
  }

  // Fetch + render
  async function loadSuppliers(){
    const qs = new URLSearchParams({
      search: state.search,
      status: state.status,
      sort:   state.sort,
      page:   String(state.page),
      per:    String(state.per)
    });

    const { rows, total, page, pages, per } = await fetchJSON(api.list + '?' + qs.toString());

    const body = document.getElementById('supBody');
    body.innerHTML = rows.length ? rows.map(s=>`
      <tr>
        <td class="fw-semibold">${esc(s.code)}</td>
        <td>${esc(s.name)}</td>
        <td>${esc(s.contact_person||'')}</td>
        <td>${esc(s.email||'')}</td>
        <td>${esc(s.phone||'')}</td>
        <td>${s.rating ?? ''}</td>
        <td>${s.lead_time_days ?? 0}</td>
        <td>${s.is_active ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'}</td>
        <td class="text-end">
          <button class="btn btn-sm btn-outline-primary me-1" onclick='editSup(${s.id}, ${JSON.stringify(s).replace(/</g,"\\u003c")})'>Edit</button>
          ${s.is_active
            ? `<button class="btn btn-sm btn-outline-danger" onclick="deactivateSup(${s.id})">Deactivate</button>`
            : `<button class="btn btn-sm btn-success" onclick="activateSup(${s.id})">Activate</button>`}
        </td>
      </tr>
    `).join('') : '<tr><td colspan="9" class="text-center py-4 text-muted">No suppliers found.</td></tr>';

    document.getElementById('supInfo').textContent = `${formatInfo(page, per, total)} · Page ${page} of ${pages}`;
    buildPager(page, pages);
  }

  // Filters
  document.getElementById('btnFilter').addEventListener('click', ()=>{
    state.search = document.getElementById('fSearch').value.trim();
    state.status = document.getElementById('fStatus').value;
    state.sort   = document.getElementById('fSort').value;
    state.page   = 1;
    syncUrl();
    loadSuppliers().catch(e=>alert(parseErr(e)));
  });

  document.getElementById('btnReset').addEventListener('click', ()=>{
    document.getElementById('fSearch').value='';
    document.getElementById('fStatus').value='1';
    document.getElementById('fSort').value='name';
    document.getElementById('perPage').value='10';
    state = { search:'', status:'1', sort:'name', page:1, per:10 };
    syncUrl();
    loadSuppliers().catch(e=>alert(parseErr(e)));
  });

  // Per-page selector
  document.getElementById('perPage').addEventListener('change', ()=>{
    state.per = parseInt(document.getElementById('perPage').value, 10) || 10;
    state.page = 1;
    syncUrl();
    loadSuppliers().catch(e=>alert(parseErr(e)));
  });

  // Add/Edit
  window.editSup = (id,row)=>{
    const m = new bootstrap.Modal(document.getElementById('mdlSup'));
    m.show();
    const f = document.getElementById('supForm');
    f.reset(); $('#supErr').classList.add('d-none');
    if (row) {
      $('#supId').value = id;
      $('#supCode').value = row.code;
      $('#supName').value = row.name;
      $('#supContact').value = row.contact_person||'';
      $('#supEmail').value = row.email||'';
      $('#supPhone').value = row.phone||'';
      $('#supAddr').value = row.address||'';
      $('#supTerms').value = row.payment_terms||'';
      $('#supRating').value = row.rating ?? '';
      $('#supLead').value = row.lead_time_days ?? 0;
      $('#supNotes').value = row.notes||'';
      $('#supActive').checked = !!row.is_active;
    } else {
      $('#supActive').checked = true;
    }
  };
  document.getElementById('supForm').addEventListener('submit', async (ev)=>{
    ev.preventDefault();
    try{
      $('#supErr').classList.add('d-none');
      const fd = new FormData(ev.target);
      if(!$('#supActive').checked) fd.set('is_active','0');
      const r = await fetchJSON(api.save, { method:'POST', body:fd });
      bootstrap.Modal.getInstance(document.getElementById('mdlSup')).hide();
      toast('Saved');
      loadSuppliers();
    }catch(e){ showErr('#supErr',e); }
  });

  // Activate/Deactivate
  async function deactivateSup(id){
    if(!confirm('Deactivate this supplier?')) return;
    await fetchJSON(api.del, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'id='+encodeURIComponent(id)+'&active=0' });
    toast('Supplier deactivated'); loadSuppliers();
  }
  async function activateSup(id){
    await fetchJSON(api.del, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'id='+encodeURIComponent(id)+'&active=1' });
    toast('Supplier activated'); loadSuppliers();
  }
  window.deactivateSup = deactivateSup;
  window.activateSup   = activateSup;

  // init
  readUrlIntoState();
  loadSuppliers().catch(e=>alert(parseErr(e)));
</script>
</body>
</html>
