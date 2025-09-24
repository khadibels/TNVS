<?php
require_once __DIR__ . "/../includes/config.php";
require_once __DIR__ . "/../includes/auth.php";
require_once __DIR__ . "/../includes/db.php";

require_login();
require_role(['admin','procurement_officer']);

$pdo = db('proc');
if (!$pdo instanceof PDO) { http_response_code(500); die("DB error"); }

$user     = current_user();
$userName = $user['name'] ?? 'Guest';
$userRole = $user['role'] ?? 'Unknown';

$section = 'procurement';
$active  = 'suppliers';


$BASE = rtrim(defined('BASE_URL') ? BASE_URL : '', '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Supplier Management | TNVS</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="../css/style.css" rel="stylesheet" />
  <link href="../css/modules.css" rel="stylesheet" />
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <script src="../js/sidebar-toggle.js"></script>


  <style>
    :root {
      --brand-primary: #6532C9;
      --brand-primary-rgb: 101, 50, 201;
      --brand-deep: #4311A5;
      --text-dark: #2b2349;
      --text-body: #494562;
      --text-muted: #6f6c80;
      --bg-light: #f9f8fe;
      --border-color: #e8e4f5;
      --shadow-color: rgba(67, 17, 165, 0.05);
      --shadow-color-hover: rgba(67, 17, 165, 0.12);
    }
    body { 
      font-family: 'Inter', sans-serif; 
      color: var(--text-body); 
    }

    /* Layout & Headers */
    .main-content { padding: 1.5rem; }
    @media (min-width: 992px) { .main-content { padding: 2rem; } }

    .page-header { 
      margin-bottom: 2.5rem; 
      border-bottom: 1px solid var(--border-color); 
      padding-bottom: 1.5rem; 
    }
    .page-header h1 { 
      font-weight: 700; 
      color: var(--brand-primary); 
    }
    .section-title { 
      font-weight: 600;
      font-size: 1.1rem;
      margin-bottom: 1.5rem; 
      display:flex; 
      align-items:center; 
      gap:.6rem; 
      color: var(--text-dark); 
      padding-bottom: 0.5rem;
      border-bottom: 1px solid var(--border-color);
    }
    .section-title ion-icon {
      color: var(--brand-primary);
    }

    /* Vendor Card */
    .vendor-card { 
      background:#fff; 
      border:1px solid var(--border-color); 
      border-radius:16px; 
      box-shadow: 0 4px 10px var(--shadow-color), 0 2px 4px var(--shadow-color); 
      transition: transform .2s ease, box-shadow .2s ease; 
    }
    .vendor-card:hover { 
      transform: translateY(-5px); 
      box-shadow: 0 10px 25px var(--shadow-color-hover); 
    }
    .vendor-card .card-body { padding:1.5rem; }
    .vendor-card .profile-pic { 
      width:80px; height:80px; 
      border-radius:50%; 
      object-fit:cover; 
      border:4px solid #fff; 
      box-shadow:0 4px 8px rgba(0,0,0,.1); 
    }
    .company-name { font-weight:600; color:var(--text-dark); font-size: 1.1rem;}
    .contact-person { font-weight:500; color:var(--brand-primary); }
    .detail-item { 
      display:flex; 
      align-items:center; 
      gap:.75rem; 
      color:var(--text-muted); 
      font-size:.9rem; 
    }
    .detail-item ion-icon { font-size:1.1rem; color:var(--brand-primary); flex-shrink:0; }

    /* Approved Suppliers Table */
    .table-wrapper { 
      background:#fff; 
      border-radius:16px; 
      border:1px solid var(--border-color); 
      box-shadow:0 4px 12px var(--shadow-color); 
      padding:1rem 0; 
    }
    .table { border-collapse: separate; border-spacing: 0; }
    .table thead th { 
      font-weight:600; 
      text-transform:uppercase; 
      letter-spacing:.5px; 
      font-size:.7rem; 
      color:var(--text-muted); 
      border: none;
      padding: 1rem 1.5rem;
    }
    .table tbody tr { transition: background-color 0.2s ease; }
    .table tbody tr:hover { background: var(--bg-light); }
    .table tbody td {
        padding: 1rem 1.5rem;
        vertical-align: middle;
        border-top: 1px solid var(--border-color);
    }
    .supplier-profile { display:flex; align-items:center; gap:1rem; }
    .supplier-profile img { width:48px; height:48px; border-radius:50%; object-fit:cover; }
    .company-info .name { font-weight:600; color: var(--text-dark); }
    .company-info .person { font-size:.9rem; color:var(--text-muted); }
    
    /* Buttons */
    .btn { border-radius: 8px; font-weight: 500; }
    .btn-brand { 
        background: linear-gradient(135deg, var(--brand-primary), var(--brand-deep)); 
        border: none; 
        color: white !important;
    }
    .btn-brand:hover { background-color: #5128a1; }
    .btn-success { background-color: #10b981; border-color: #10b981; }
    .btn-success:hover { background-color: #059669; border-color: #059669; }
    .btn-danger { background-color: #ef4444; border-color: #ef4444; }
    .btn-danger:hover { background-color: #dc2626; border-color: #dc2626; }

    /* Badges */
    .badge {
        padding: 0.4em 0.8em;
        font-weight: 500;
        font-size: 0.75rem;
    }
    .badge-approved { background-color: #d1fae5; color: #065f46; }
    .badge-pending  { background-color: #fef3c7; color: #92400e; }
    .badge-rejected { background-color: #fee2e2; color: #991b1b; }
    
    /* Modal */
    .modal-content { border-radius: 16px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
    .modal-header { border-bottom-color: var(--border-color); }
    .modal-footer { background-color: var(--bg-light); border-top-color: var(--border-color); }
  </style>
</head>
<body>
<div class="container-fluid p-0">
  <div class="row g-0">

    <?php include __DIR__ . '/../includes/sidebar.php' ?>

    <div class="col main-content">

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

      <!-- Pending Approvals -->
      <section id="pending-approvals" class="mb-5">
        <h2 class="h5 section-title">
          <ion-icon name="hourglass-outline"></ion-icon>
          Pending Approvals <span id="pendingCount" class="badge bg-secondary-subtle text-secondary-emphasis rounded-pill ms-1">0</span>
        </h2>
        <div id="pendingGrid" class="row g-4"></div>
        <div class="d-flex justify-content-center mt-3" id="pendingMoreWrap" style="display:none;">
          <button class="btn btn-outline-secondary btn-sm" id="btnMorePending">Load more</button>
        </div>
      </section>

      <!-- Approved Suppliers -->
      <section id="approved-suppliers">
        <h2 class="h5 section-title">
          <ion-icon name="list-outline"></ion-icon> Approved Suppliers
        </h2>

        <div class="table-wrapper">
          <div class="table-responsive">
            <table class="table align-middle mb-0">
              <thead>
                <tr>
                  <th>Supplier</th>
                  <th>Contact</th>
                  <th>Status</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody id="approvedBody">
                <tr><td colspan="4" class="text-center py-5 text-muted">
                    <div class="spinner-border spinner-border-sm text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <span class="ms-2">Loading Vendors...</span>
                </td></tr>
              </tbody>
            </table>
          </div>
          <div class="d-flex justify-content-center mt-3 py-2" id="approvedMoreWrap" style="display:none;">
            <button class="btn btn-outline-secondary btn-sm" id="btnMoreApproved">Load more</button>
          </div>
        </div>
      </section>

    </div>
  </div>
</div>

<!-- Decision Modal -->
<div class="modal fade" id="mdlDecision" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="decisionForm">
        <div class="modal-header">
          <h5 class="modal-title" id="decisionTitle">Approve Vendor</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" id="decId">
          <input type="hidden" name="action" id="decAction">
          <div id="reasonWrap" class="d-none mb-3">
            <label class="form-label" for="decReason">Reason (optional)</label>
            <textarea class="form-control" name="reason" id="decReason" rows="3" placeholder="Tell the vendor why this was rejected/suspended"></textarea>
          </div>
          <div class="alert alert-light small m-0">
            <ion-icon name="mail-outline"></ion-icon>
            The vendor will see the new status and reason (if provided) the next time they sign in.
          </div>
          <div id="decErr" class="alert alert-danger d-none mt-3"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary btn-brand" type="submit">Confirm Action</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Toasts -->
<div class="toast-container position-fixed top-0 end-0 p-3" id="toasts" style="z-index:1080"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // ===== helpers =====
  function toast(msg, variant='success', delay=2200){
    const wrap = document.getElementById('toasts');
    const el = document.createElement('div');
    el.className = `toast align-items-center text-bg-${variant} border-0`; el.role='alert';
    el.innerHTML = `<div class="d-flex"><div class="toast-body">${msg}</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
    wrap.appendChild(el); const t=new bootstrap.Toast(el,{delay}); t.show();
    el.addEventListener('hidden.bs.toast', ()=> el.remove());
  }
  const $ = (s, r=document)=>r.querySelector(s);
  function esc(s){return String(s??'').replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m]));}
  function parseErr(e){ try{const j=JSON.parse(e.message); if(j.error) return j.error;}catch(_){} return e.message||'Request failed'; }

  const api = {
    list   : './api/vendors_list.php',
    update : './api/vendors_update_status.php'
  };

  // paging state + dedupe
  const state = {
    pending:  { page: 1, per: 6,  loading: false },
    approved: { page: 1, per: 10, loading: false }
  };
  const seen = { pending: new Set(), approved: new Set() };

  const PLACEHOLDER = '<?= $BASE ?>/img/default_vendor.png';

  // ===== fetchers =====
  async function fetchVendors({status, page, per, sort='new', search=''}) {
    const qs = new URLSearchParams({ status, page:String(page), per:String(per), sort, search });
    const res = await fetch(api.list + '?' + qs.toString());
    if (!res.ok) throw new Error(await res.text() || res.statusText);
    return res.json();
  }

  // ===== renderers =====
  function vendorCard(v){
    const img = v.photo_url || PLACEHOLDER;
    return `
      <div class="col-md-6 col-xl-4">
        <div class="card vendor-card h-100">
          <div class="card-body d-flex flex-column">
            <div class="text-center mb-3">
              <img
                src="${esc(img)}"
                alt="Vendor Profile"
                class="profile-pic"
                width="80" height="80"
                loading="lazy" decoding="async" fetchpriority="low"
                onerror="this.onerror=null;this.src='${esc(PLACEHOLDER)}'">
            </div>
            <div class="text-center">
              <h3 class="h6 company-name mb-0">${esc(v.company_name)}</h3>
              <p class="contact-person mb-3">${esc(v.contact_person || '')}</p>
            </div>

            <div class="d-flex flex-column gap-2 mb-4">
              <div class="detail-item"><ion-icon name="mail-outline"></ion-icon><span>${esc(v.email || '')}</span></div>
              <div class="detail-item"><ion-icon name="call-outline"></ion-icon><span>${esc(v.phone || '')}</span></div>
              <div class="detail-item"><ion-icon name="location-outline"></ion-icon><span>${esc(v.address || '')}</span></div>
            </div>

            <div class="card-footer bg-transparent border-0 mt-auto p-0">
              <div class="d-grid gap-2 d-sm-flex">
                <button class="btn btn-success flex-grow-1" onclick="openDecision(${v.id}, 'approve', 'Approve Vendor')">
                  <ion-icon name="checkmark-outline"></ion-icon> Approve
                </button>
                <button class="btn btn-danger flex-grow-1" onclick="openDecision(${v.id}, 'reject', 'Reject Vendor')">
                  <ion-icon name="close-outline"></ion-icon> Deny
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>`;
  }

  function approvedRow(v){
    const img = v.photo_url || PLACEHOLDER;
    const badge = `<span class="badge rounded-pill badge-approved">Active</span>`;
    return `
      <tr>
        <td>
          <div class="supplier-profile">
            <img
              src="${esc(img)}"
              alt="Supplier Logo"
              width="48" height="48"
              loading="lazy" decoding="async" fetchpriority="low"
              onerror="this.onerror=null;this.src='${esc(PLACEHOLDER)}'">
            <div class="company-info">
              <div class="name">${esc(v.company_name)}</div>
              <div class="person">${esc(v.contact_person || '')}</div>
            </div>
          </div>
        </td>
        <td>
          <div>${esc(v.email || '')}</div>
          <div class="small text-muted">${esc(v.phone || '')}</div>
        </td>
        <td>${badge}</td>
        <td class="text-end">
          <div class="btn-group">
            <button class="btn btn-sm btn-outline-secondary" disabled title="Coming Soon">View Profile</button>
            <button class="btn btn-sm btn-success" disabled>
              <ion-icon name="checkmark-circle-outline"></ion-icon> Active
            </button>
          </div>
        </td>
      </tr>`;
  }

  // ===== loaders =====
  async function loadPending(reset=false){
    if (state.pending.loading) return;
    state.pending.loading = true;
    try {
      if (reset) {
        state.pending.page = 1;
        seen.pending.clear();
        $('#pendingGrid').innerHTML = '';
      }
      const data = await fetchVendors({ status:'Pending', page: state.pending.page, per: state.pending.per });
      $('#pendingCount').textContent = data.total;

      if (state.pending.page === 1 && data.total === 0) {
        $('#pendingGrid').innerHTML = `<div class="col-12 text-center text-muted py-4">No pending vendors to review.</div>`;
        $('#pendingMoreWrap').style.display = 'none';
        return;
      }

      const newRows = data.rows.filter(r => !seen.pending.has(r.id));
      newRows.forEach(r => seen.pending.add(r.id));
      if (newRows.length) $('#pendingGrid').insertAdjacentHTML('beforeend', newRows.map(vendorCard).join(''));

      const morePages = state.pending.page < data.pages;
      const showMore  = morePages && newRows.length > 0;
      $('#pendingMoreWrap').style.display = showMore ? '' : 'none';
      if (showMore) state.pending.page += 1;
    } catch (e) {
      toast(parseErr(e), 'danger', 3200);
    } finally {
      state.pending.loading = false;
    }
  }

  async function loadApproved(reset=false){
    if (state.approved.loading) return;
    state.approved.loading = true;
    try {
      if (reset) {
        state.approved.page = 1;
        seen.approved.clear();
        $('#approvedBody').innerHTML = '';
      }
      const data = await fetchVendors({ status:'Approved', page: state.approved.page, per: state.approved.per });

      if (state.approved.page === 1 && data.total === 0) {
        $('#approvedBody').innerHTML = `<tr><td colspan="4" class="text-center py-5 text-muted">No approved vendors yet.</td></tr>`;
        $('#approvedMoreWrap').style.display = 'none';
        return;
      }

      const newRows = data.rows.filter(r => !seen.approved.has(r.id));
      newRows.forEach(r => seen.approved.add(r.id));

      if (newRows.length) {
        const html = newRows.map(approvedRow).join('');
        if (state.approved.page === 1) {
            $('#approvedBody').innerHTML = html;
        } else {
            $('#approvedBody').insertAdjacentHTML('beforeend', html);
        }
      }

      const morePages = state.approved.page < data.pages;
      const showMore  = morePages && newRows.length > 0;
      $('#approvedMoreWrap').style.display = showMore ? '' : 'none';
      if (showMore) state.approved.page += 1;
    } catch (e) {
      toast(parseErr(e), 'danger', 3200);
    } finally {
      state.approved.loading = false;
    }
  }

  // decisions
  window.openDecision = (id, action, title) => {
    document.getElementById('decId').value = id;
    document.getElementById('decAction').value = action;
    document.getElementById('decisionTitle').textContent = title || 'Confirm';
    document.getElementById('decReason').value = '';
    document.getElementById('reasonWrap').classList.toggle('d-none', action!=='reject');
    document.getElementById('decErr').classList.add('d-none');
    new bootstrap.Modal(document.getElementById('mdlDecision')).show();
  };

  document.getElementById('decisionForm').addEventListener('submit', async (ev)=>{
    ev.preventDefault();
    const btn = ev.submitter;
    btn.disabled = true;
    btn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...`;
    
    try {
      const fd = new FormData(ev.target);
      const res = await fetch(api.update, { method:'POST', body: fd });
      if (!res.ok) throw new Error(await res.text() || res.statusText);
      const j = await res.json();
      bootstrap.Modal.getInstance(document.getElementById('mdlDecision')).hide();
      toast(j.message || 'Updated', 'success');
      await Promise.all([loadPending(true), loadApproved(true)]);
    } catch (e) {
      const el = document.getElementById('decErr');
      el.textContent = parseErr(e);
      el.classList.remove('d-none');
    } finally {
        btn.disabled = false;
        btn.innerHTML = 'Confirm Action';
    }
  });

  // load more buttons
  document.getElementById('btnMorePending').addEventListener('click', ()=> loadPending(false));
  document.getElementById('btnMoreApproved').addEventListener('click', ()=> loadApproved(false));

  // init
  (async ()=> { await Promise.all([loadPending(true), loadApproved(true)]); })();
</script>
</body> 
</html>
