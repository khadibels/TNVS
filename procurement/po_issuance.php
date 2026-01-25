<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();
require_role(['admin','procurement_officer','manager']);

$section = 'procurement';
$active  = 'po_pos';
$BASE = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';

$user     = current_user();
$userName = $user['name'] ?? 'User';
$userRole = $user['role'] ?? '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Purchase Order Issuance | Procurement</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
<link href="<?= $BASE ?>/css/style.css" rel="stylesheet" />
<link href="<?= $BASE ?>/css/modules.css" rel="stylesheet" />
<script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
<script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
<script src="<?= $BASE ?>/js/sidebar-toggle.js"></script>
</head>
<body class="saas-page">
<div class="container-fluid p-0">
  <div class="row g-0">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="col main-content">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-3">
          <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2" aria-label="Toggle sidebar">
            <ion-icon name="menu-outline"></ion-icon>
          </button>
          <h2 class="m-0 d-flex align-items-center gap-2 page-title">
            <ion-icon name="file-tray-full-outline"></ion-icon>Purchase Order Issuance
          </h2>
        </div>
        <div class="profile-menu" data-profile-menu>
          <button class="profile-trigger" type="button" data-profile-trigger aria-expanded="false" aria-haspopup="true">
            <img src="<?= $BASE ?>/img/profile.jpg" class="rounded-circle" width="36" height="36" alt="">
            <div class="profile-text">
              <div class="profile-name"><?= htmlspecialchars($userName, ENT_QUOTES) ?></div>
              <div class="profile-role"><?= htmlspecialchars($userRole, ENT_QUOTES) ?></div>
            </div>
            <ion-icon class="profile-caret" name="chevron-down-outline"></ion-icon>
          </button>
          <div class="profile-dropdown" data-profile-dropdown role="menu">
            <a href="<?= u('auth/logout.php') ?>" role="menuitem">Sign out</a>
          </div>
        </div>
      </div>

      <section class="card shadow-sm mb-3">
        <div class="card-body">
          <div class="row g-2 align-items-center">
            <div class="col-md-5"><input id="fSearch" class="form-control" placeholder="Search Purchase Order No / Quotation No / Title…"></div>
            <div class="col-md-3">
              <select id="fStatus" class="form-select">
                <option value="">All Status</option>
                <option value="draft">Draft</option>
                <option value="issued">Issued</option>
                <option value="acknowledged">Acknowledged</option>
                <option value="closed">Closed</option>
                <option value="cancelled">Cancelled</option>
              </select>
            </div>
            <div class="col-md-2">
              <button class="btn btn-outline-secondary w-100" id="btnFilter">
                <ion-icon name="search-outline"></ion-icon> Filter
              </button>
            </div>
          </div>
        </div>
      </section>

      <section class="card shadow-sm">
        <div class="card-body">
          <div class="table-responsive">
            <table class="table align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Purchase Order No</th><th>Quotation No</th><th>Title</th>
                  <th>Vendor</th><th class="text-end">Total</th><th>Status</th><th>Issued</th><th class="text-end">Action</th>
                </tr>
              </thead>
              <tbody id="tblBody">
                <tr><td colspan="8" class="text-center py-5 text-muted">Loading…</td></tr>
              </tbody>
            </table>
          </div>
          <div class="d-flex justify-content-between align-items-center mt-3">
            <div class="small text-muted" id="pageInfo"></div>
            <nav><ul class="pagination pagination-sm mb-0" id="pager"></ul></nav>
          </div>
        </div>
      </section>
    </div>
  </div>
</div>

<div class="modal fade" id="mdlPO" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title"><span id="mTitle">Purchase Order</span> <span id="mStatus" class="ms-2"></span></h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body" id="mBody"><div class="text-center text-muted py-5">Loading…</div></div>
    <div class="modal-footer">
      <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
      <button class="btn btn-primary" id="btnIssue">Issue Purchase Order</button>
    </div>
  </div></div>
</div>

<div class="toast-container position-fixed top-0 end-0 p-3" id="toasts" style="z-index:1080"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= $BASE ?>/js/profile-dropdown.js"></script>
<script>
const API = {
  list  : './api/pos_list.php',
  detail: './api/po_detail.php',
  issue : './api/po_issue.php'
};

const $ = (s,r=document)=>r.querySelector(s);
const esc = s => String(s??'').replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m]));
const state = { page:1, per:10, search:'', status:'' };

function badge(s){
  const v=(s||'').toLowerCase();
  const map = { draft:'bg-secondary', issued:'bg-info text-dark', acknowledged:'bg-primary', closed:'bg-success', cancelled:'bg-dark' };
  return `<span class="badge badge-status ${map[v]||'bg-light text-dark'}">${esc(s)}</span>`;
}

async function fetchJSON(u, opts){
  const r = await fetch(u, opts);
  const t = await r.text();
  let j; try{ j=JSON.parse(t);}catch{}
  if(!r.ok){ throw new Error((j&&(j.error||j.message))||t||r.statusText); }
  return j ?? {};
}

async function loadPOs(){
  try{
    const qs = new URLSearchParams({page:state.page, per:state.per, search:state.search, status:state.status});
    const j = await fetchJSON(API.list + '?' + qs.toString());
    const rows = j.data || [], pg = j.pagination || {};
    const tb = $('#tblBody');

    if(rows.length){
      tb.innerHTML = rows.map(r=>`
        <tr>
          <td class="fw-semibold">${esc(r.po_no)}</td>
          <td>${esc(r.rfq_no)}</td>
          <td>${esc(r.title)}</td>
          <td>${esc(r.vendor_name || '')}</td>
          <td class="text-end">${Number(r.total).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})} ${esc(r.currency)}</td>
          <td>${badge(r.status)}</td>
          <td>${r.issued_at ? new Date(String(r.issued_at).replace(' ','T')).toLocaleString() : '-'}</td>
          <td class="text-end">
            <button class="btn btn-sm btn-outline-secondary" data-view="${r.id}">
              <ion-icon name="eye-outline"></ion-icon> View
            </button>
          </td>
        </tr>`).join('');
    }else{
      tb.innerHTML = `<tr><td colspan="8" class="text-center py-5 text-muted">No POs.</td></tr>`;
    }

    const page=Number(pg.page||1), per=Number(pg.per||10), total=Number(pg.total||0), pages=Math.max(1,Math.ceil(total/per));
    $('#pageInfo').textContent = `Page ${page} of ${pages} • ${total} result(s)`;
    const pager = $('#pager'); pager.innerHTML='';
    const li=(p,l,d=false,a=false)=>`<li class="page-item ${d?'disabled':''} ${a?'active':''}">
      <a class="page-link" href="#" onclick="go(${p});return false;">${l}</a></li>`;
    pager.insertAdjacentHTML('beforeend', li(page-1,'«', page<=1));
    for(let p=Math.max(1,page-2); p<=Math.min(pages,page+2); p++) pager.insertAdjacentHTML('beforeend', li(p,p,false,p===page));
    pager.insertAdjacentHTML('beforeend', li(page+1,'»', page>=pages));
  }catch(e){
    $('#tblBody').innerHTML = `<tr><td colspan="8" class="text-danger text-center py-5">${esc(e.message)}</td></tr>`;
  }
}
window.go = p=>{ if(p<1) return; state.page=p; loadPOs(); };

document.getElementById('btnFilter').addEventListener('click', ()=>{
  state.page=1; state.search=$('#fSearch').value.trim(); state.status=$('#fStatus').value; loadPOs();
});

document.getElementById('tblBody').addEventListener('click', (e)=>{
  const id = e.target.closest('[data-view]')?.getAttribute('data-view');
  if(id) openPOModal(Number(id));
});

async function openPOModal(id){
  const m = new bootstrap.Modal(document.getElementById('mdlPO'));
  $('#mBody').innerHTML = `<div class="text-center text-muted py-5">Loading…</div>`;
  $('#btnIssue').dataset.id = id;

  try{
    const j = await fetchJSON(API.detail + '?id=' + id);
    const po = j.po, items = j.items || [];

    $('#mTitle').textContent = `${po.po_no} — ${po.rfq_no}${po.title ? ' — '+po.title : ''}`;
    $('#mStatus').innerHTML = badge(po.status);

    const itemsHTML = items.length ? `
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead><tr><th>#</th><th>Item</th><th>Specs</th><th class="text-end">Qty</th><th>UOM</th><th class="text-end">Unit</th><th class="text-end">Line</th></tr></thead>
          <tbody>${items.map(r=>`
            <tr>
              <td>${r.line_no}</td><td>${esc(r.item)}</td>
              <td class="text-muted">${esc(r.specs||'')}</td>
              <td class="text-end">${r.qty}</td>
              <td>${esc(r.uom||'')}</td>
              <td class="text-end">${Number(r.unit_price).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:4})}</td>
              <td class="text-end">${Number(r.line_total).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}</td>
            </tr>`).join('')}
          </tbody>
        </table>
      </div>` : `<div class="text-muted">No items.</div>`;

    $('#mBody').innerHTML = `
      <div class="row g-4">
        <div class="col-lg-7">
          <h6 class="fw-semibold">PO Details</h6>
          <dl class="row mb-0">
            <dt class="col-sm-4">Vendor</dt><dd class="col-sm-8">${esc(po.vendor_name||'')}</dd>
            <dt class="col-sm-4">Total</dt><dd class="col-sm-8">${Number(po.total).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})} ${esc(po.currency)}</dd>
            <dt class="col-sm-4">Status</dt><dd class="col-sm-8">${esc(po.status)}</dd>
            <dt class="col-sm-4">Issued At</dt><dd class="col-sm-8">${po.issued_at ? new Date(String(po.issued_at).replace(' ','T')).toLocaleString() : '-'}</dd>
          </dl>
          <hr class="my-3"><h6 class="fw-semibold">Items</h6>${itemsHTML}
        </div>
        <div class="col-lg-5">
          <h6 class="fw-semibold">Notes / Terms</h6>
          <div class="small text-muted mb-1">Terms</div>
          <input id="mTerms" class="form-control form-control-sm" value="${esc(po.terms||'')}">
          <div class="small text-muted mt-3 mb-1">Notes</div>
          <textarea id="mNotes" class="form-control form-control-sm" rows="5">${esc(po.notes||'')}</textarea>
        </div>
      </div>`;

    $('#btnIssue').disabled = String(po.status).toLowerCase() !== 'draft';
    m.show();
  }catch(e){
    $('#mBody').innerHTML = `<div class="alert alert-danger">${esc(e.message)}</div>`;
    m.show();
  }
}

document.getElementById('btnIssue').addEventListener('click', async ()=>{
  const id = Number(document.getElementById('btnIssue').dataset.id || 0);
  if(!id) return;
  try{
    const fd = new FormData();
    fd.append('id', id);
    fd.append('terms', ($('#mTerms')?.value ?? '').trim());
    fd.append('notes', ($('#mNotes')?.value ?? '').trim());
    await fetchJSON(API.issue, {method:'POST', body:fd});
    toast('PO issued', 'success');
    bootstrap.Modal.getInstance(document.getElementById('mdlPO')).hide();
    loadPOs();
  }catch(e){
    toast(e.message||'Issue failed', 'danger');
  }
});

function toast(msg, type='success'){
  const wrap = document.getElementById('toasts');
  const el = document.createElement('div');
  el.className='toast align-items-center text-bg-'+type+' border-0 show';
  el.role='alert'; el.innerHTML = `
    <div class="d-flex">
      <div class="toast-body">${esc(msg)}</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>`;
  wrap.appendChild(el); setTimeout(()=>el.remove(), 2300);
}

loadPOs();
</script>
</body>
</html>
