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
$active = 'rfqs';
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
  <title>RFQs & Sourcing | Procurement</title>
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
          <h2 class="m-0">RFQs &amp; Sourcing</h2>
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
              <input id="fSearch" class="form-control" placeholder="Search RFQ No or Title…">
            </div>
            <div class="col-6 col-md-3">
              <select id="fStatus" class="form-select">
                <option value="" selected>All Status</option>
                <option value="draft">Draft</option>
                <option value="sent">Sent</option>
                <option value="awarded">Awarded</option>
                <option value="closed">Closed</option>
                <option value="cancelled">Cancelled</option>
              </select>
            </div>
            <div class="col-6 col-md-3">
              <select id="fSort" class="form-select">
                <option value="new" selected>Newest</option>
                <option value="title">Title (A–Z)</option>
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
            <h5 class="mb-0">Requests for Quotation</h5>
            <button class="btn btn-violet" onclick="openAddRFQ()">
              <ion-icon name="add-circle-outline"></ion-icon> New RFQ
            </button>
          </div>

          <div class="table-responsive">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th>RFQ No</th><th>Title</th><th>Due Date</th>
                  <th>Invited</th><th>Quoted</th><th>Status</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody id="rfqBody"><tr><td colspan="7" class="text-center py-4">Loading…</td></tr></tbody>
            </table>
          </div>
        </div>
      </section>

      <div class="d-flex justify-content-between align-items-center mt-2">
        <div class="small text-muted" id="pageInfo"></div>
        <nav><ul class="pagination pagination-sm mb-0" id="pager"></ul></nav>
      </div>
    </div>
  </div>
</div>

<!-- Add/Edit RFQ Modal -->
<div class="modal fade" id="mdlRFQ" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form id="rfqForm">
        <div class="modal-header">
          <h5 class="modal-title">RFQ</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" id="rfqId">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Title</label>
              <input class="form-control" name="title" id="rfqTitle" required maxlength="160">
            </div>
            <div class="col-6">
              <label class="form-label">Due Date</label>
              <input type="date" class="form-control" name="due_date" id="rfqDue">
            </div>
            <div class="col-6">
              <label class="form-label">Status</label>
              <select class="form-select" name="status" id="rfqStatus">
                <option value="draft" selected>Draft</option>
                <option value="sent">Sent</option>
                <option value="awarded">Awarded</option>
                <option value="closed">Closed</option>
                <option value="cancelled">Cancelled</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Invite Suppliers (Ctrl/Cmd + click)</label>
              <select class="form-select" id="rfqSuppliers" name="suppliers[]" multiple size="6"></select>
              <div class="form-text">Only Active suppliers are listed.</div>
            </div>
            <div class="col-12">
              <label class="form-label">Notes</label>
              <textarea class="form-control" name="notes" id="rfqNotes" rows="2"></textarea>
            </div>
          </div>
          <div class="alert alert-danger d-none mt-3" id="rfqErr"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" type="submit">Save</button>

        </div>
      </form>
    </div>
  </div>
</div>

<!-- Award Modal -->
<div class="modal fade" id="mdlAward" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="awardForm">
        <div class="modal-header">
          <h5 class="modal-title">Award RFQ</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="rfq_id" id="awardRfqId">
          <div class="mb-2">
            <div class="small text-muted">Choose the winning supplier</div>
            <select class="form-select" id="awardSupplier" name="supplier_id" required></select>
          </div>
          <div class="alert alert-danger d-none" id="awardErr"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-success" type="submit">Award &amp; Create PO</button>

        </div>
      </form>
    </div>
  </div>
</div>

<!-- Quotes Modal -->
<div class="modal fade" id="mdlQuotes" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Supplier Quotes</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="table-responsive">
          <table class="table align-middle">
            <thead>
              <tr>
                <th>Supplier</th>
                <th class="text-end">Total</th>
                <th>Lead (d)</th>
                <th>Rating</th>
                <th>Submitted</th>
                <th class="text-end">Action</th>
              </tr>
            </thead>
            <tbody id="quotesBody">
              <tr><td colspan="6" class="text-center py-4">Loading…</td></tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <!-- the "Add sample quote" button will be injected here -->
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Invite Links Modal -->
<div class="modal fade" id="mdlLinks" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Supplier Invite Links</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead>
              <tr>
                <th>Supplier</th>
                <th>Email</th>
                <th>Link</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody id="linksBody">
              <tr><td colspan="4" class="text-center py-3">Generating…</td></tr>
            </tbody>
          </table>
        </div>
        <div class="small text-muted">Tip: Click “Email” to open your mail app with the link prefilled, or “Copy” to put the link on your clipboard.</div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>


<!-- Toasts -->
<div class="toast-container position-fixed top-0 end-0 p-3" id="toasts" style="z-index:1080"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
// ===== Helpers =====
const $ = (s, r=document)=>r.querySelector(s);
async function fetchJSON(url, opts={}) {
  const res = await fetch(url, opts);
  if (!res.ok) throw new Error(await res.text() || res.statusText);
  return res.json();
}
function esc(s){return String(s??'').replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m]));}
function parseErr(e){ try{const j=JSON.parse(e.message); if(j.error) return j.error; if(j.errors) return j.errors.join(', ');}catch(_){} return e.message||'Request failed'; }

// ===== API endpoints =====
const api = {
  list   : './api/rfqs_list.php',
  save   : './api/rfqs_save.php',
  award  : './api/rfqs_award.php',
  status : './api/rfqs_set_status.php',
  listSup: './api/suppliers_list.php',
  delete : './api/rfqs_delete.php',
  quotes : './api/quotes_list.php'
};


// Delete RFQ (drafts only by default)
async function deleteRFQ(id, { force=false } = {}) {
  if (!id) return;
  const msg = force
    ? 'Hard delete this RFQ and its related quotes/attachments?'
    : 'Delete this RFQ? (Only DRAFT RFQs without quotes can be deleted)';
  if (!confirm(msg)) return;

  try {
    const body = new URLSearchParams();
    body.set('id', String(id));
    if (force) body.set('force', '1');

    await fetch(api.delete, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    });

    toast('RFQ deleted');
    loadRFQs();
  } catch (e) {
    alert(parseErr(e));
  }
}



// ===== State =====
let state = { page: 1, perPage: 10, search: '', status: '', sort: 'newest' };
const mapSort = v => v==='new' ? 'newest' : v;

// pagination helper
window.go = (p) => { if (!p || p<1) return; state.page=p; loadRFQs().catch(e=>alert(parseErr(e))); };

// badge helper
function statusBadge(s){
  const v=(s||'').toLowerCase();
  if(v==='draft') return '<span class="badge bg-secondary">Draft</span>';
  if(v==='sent') return '<span class="badge bg-info text-dark">Sent</span>';
  if(v==='awarded') return '<span class="badge bg-success">Awarded</span>';
  if(v==='closed') return '<span class="badge bg-dark">Closed</span>';
  if(v==='cancelled') return '<span class="badge bg-danger">Cancelled</span>';
  return esc(s||'');
}

// ===== Load RFQs =====
async function loadRFQs(){
  const qs = new URLSearchParams({
    page:state.page, per_page:state.perPage, search:state.search, status:state.status, sort:state.sort
  });
  const { data, pagination } = await fetchJSON(api.list+'?'+qs.toString());

  const body = document.getElementById('rfqBody');
  body.innerHTML = data.length ? data.map(r => `
    <tr>
      <td class="fw-semibold">${esc(r.rfq_no)}</td>
      <td>${esc(r.title)}</td>
      <td>${r.due_date ? esc(r.due_date) : '-'}</td>
      <td>${r.invited_count ?? 0}</td>
      <td>${r.quoted_count ?? 0}</td>
      <td>${statusBadge(r.status)}</td>
      <td class="text-end">
        <button class="btn btn-sm btn-outline-secondary me-1"
                onclick='openEdit(${r.id}, ${JSON.stringify(r).replace(/</g,"\\u003c")})'>Edit</button>

        ${((r.status||'').toLowerCase() === 'draft')
          ? `<button class="btn btn-sm btn-primary me-1"
                     onclick='quickStatus(${r.id}, ${JSON.stringify(r).replace(/</g,"\\u003c")}, "sent")'>Mark Sent</button>` : ``}

        ${(((r.status||'').toLowerCase() === 'draft' || (r.status||'').toLowerCase() === 'sent'))
          ? `<button class="btn btn-sm btn-outline-primary me-1" onclick="sendInvites(${r.id})">Send RFQ</button>` : ``}

        <button type="button" class="btn btn-sm btn-outline-secondary me-1"
                data-open-quotes data-rfq-id="${r.id}" data-rfq-status="${(r.status||'').toLowerCase()}">
          Quotes
        </button>

        ${(((r.status||'').toLowerCase() !== 'awarded'))
          ? `<button class="btn btn-sm btn-success" onclick='openAward(${r.id})'>Award</button>` : ``}

        ${(((r.status||'').toLowerCase() === 'draft'))
          ? `<button class="btn btn-sm btn-outline-danger ms-1" onclick='deleteRFQ(${r.id})'>Delete</button>` : ``}
      </td>
    </tr>
  `).join('') : `<tr><td colspan="7" class="text-center py-4 text-muted">No RFQs found.</td></tr>`;

  const { page, perPage, total } = pagination;
  const totalPages = Math.max(1, Math.ceil(total / perPage));
  document.getElementById('pageInfo').textContent = `Page ${page} of ${totalPages} • ${total} result(s)`;

  const pager = document.getElementById('pager'); pager.innerHTML = '';
  const li = (p,l=p,d=false,a=false)=>`
    <li class="page-item ${d?'disabled':''} ${a?'active':''}">
      <a class="page-link" href="#" onclick="go(${p});return false;">${l}</a>
    </li>`;
  pager.insertAdjacentHTML('beforeend', li(page-1,'&laquo;', page<=1));
  for (let p = Math.max(1, page-2); p <= Math.min(totalPages, page+2); p++){
    pager.insertAdjacentHTML('beforeend', li(p, p, false, p===page));
  }
  pager.insertAdjacentHTML('beforeend', li(page+1,'&raquo;', page>=totalPages));
}

// filters
document.getElementById('btnFilter').addEventListener('click', ()=>{
  state.page=1;
  state.search=$('#fSearch').value.trim();
  state.status=$('#fStatus').value;
  state.sort=mapSort($('#fSort').value);
  loadRFQs().catch(e=>alert(parseErr(e)));
});
document.getElementById('btnReset').addEventListener('click', ()=>{
  $('#fSearch').value=''; $('#fStatus').value=''; $('#fSort').value='new';
  state={ page:1, perPage:10, search:'', status:'', sort:'newest' };
  loadRFQs().catch(e=>alert(parseErr(e)));
});

// initial
loadRFQs().catch(e=>alert(parseErr(e)));
</script>

<script>
// --- toast helper ---
function toast(msg, variant='success', delay=2200){
  let wrap = document.getElementById('toasts');
  if (!wrap) {
    wrap = document.createElement('div');
    wrap.id='toasts';
    wrap.className='toast-container position-fixed top-0 end-0 p-3';
    wrap.style.zIndex=1080;
    document.body.appendChild(wrap);
  }
  const el = document.createElement('div');
  el.className = `toast text-bg-${variant} border-0`; el.role='status';
  el.innerHTML = `<div class="d-flex"><div class="toast-body">${msg}</div>
    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
  wrap.appendChild(el); const t=new bootstrap.Toast(el,{delay}); t.show();
  el.addEventListener('hidden.bs.toast', ()=> el.remove());
}

// --- supplier list into select ---
async function loadActiveSuppliersInto(selectEl){
  const resp = await fetchJSON('./api/suppliers_list.php?status=1&sort=name&select=1');
  const list = Array.isArray(resp) ? resp : (resp.rows || []);
  selectEl.innerHTML = list.map(s=>`<option value="${s.id}">${esc(s.code)} — ${esc(s.name)}</option>`).join('');
}

// --- Send invites
window.sendInvites = async (id)=>{
  if (!confirm('Generate invite links for this RFQ?')) return;

  const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('mdlLinks'));
  const body  = document.getElementById('linksBody');
  body.innerHTML = `<tr><td colspan="4" class="text-center py-3">Generating…</td></tr>`;
  modal.show();

  try{
    const res = await fetchJSON('./api/rfqs_send.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:'rfq_id='+encodeURIComponent(id)
    });

    const esc = s => String(s||'').replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m]));
    const rows = Array.isArray(res.links) ? res.links : [];

    if (!rows.length) {
      body.innerHTML = `<tr><td colspan="4" class="text-center py-3 text-muted">No recipients found for this RFQ.</td></tr>`;
      return;
    }

    body.innerHTML = rows.map(r => `
      <tr>
        <td>${esc(r.name)}</td>
        <td>${esc(r.email)}</td>
        <td class="text-truncate" style="max-width:360px">
          <a href="${esc(r.link)}" target="_blank" rel="noopener">${esc(r.link)}</a>
        </td>
        <td class="text-end">
          <button class="btn btn-sm btn-outline-primary" data-copy="${esc(r.link)}">Copy</button>
          <a class="btn btn-sm btn-outline-secondary" href="${r.mailto}" target="_blank" rel="noopener">Email</a>
        </td>
      </tr>
    `).join('');
    loadRFQs();
    toast(`Links ready for ${rows.length} supplier(s)`);
  }catch(e){
    body.innerHTML = `<tr><td colspan="4" class="text-danger py-3 text-center">${esc(parseErr(e))}</td></tr>`;
  }
};


document.addEventListener('click', async (e)=>{
  const btn = e.target.closest('button[data-copy]');
  if (!btn) return;
  try{
    await navigator.clipboard.writeText(btn.getAttribute('data-copy'));
    toast('Link copied');
  }catch{
    alert('Copy failed');
  }
});

// --- New RFQ modal ---
window.openAddRFQ = async ()=>{
  const m = bootstrap.Modal.getOrCreateInstance(document.getElementById('mdlRFQ')); m.show();
  const f = document.getElementById('rfqForm');
  f.reset(); $('#rfqId').value=''; $('#rfqStatus').value='draft'; $('#rfqErr')?.classList.add('d-none');
  await loadActiveSuppliersInto($('#rfqSuppliers'));
};

// --- Edit RFQ modal ---
window.openEdit = async (id,row)=>{
  const m = bootstrap.Modal.getOrCreateInstance(document.getElementById('mdlRFQ')); m.show();
  const f = document.getElementById('rfqForm'); f.reset(); $('#rfqErr')?.classList.add('d-none');
  $('#rfqId').value=id; $('#rfqTitle').value=row.title||''; $('#rfqDue').value=row.due_date||'';
  $('#rfqStatus').value=row.status||'draft'; $('#rfqNotes').value=row.notes||'';
  await loadActiveSuppliersInto($('#rfqSuppliers'));
  try {
    const ids = await fetchJSON('./api/rfqs_recipients.php?rfq_id=' + id);
    const invited = new Set(ids.map(Number));
    for (const o of Array.from($('#rfqSuppliers').options)) o.selected = invited.has(Number(o.value));
  } catch {}
};

// --- Save RFQ ---
document.getElementById('rfqForm').addEventListener('submit', async (ev)=>{
  ev.preventDefault();
  try{
    $('#rfqErr').classList.add('d-none');
    const fd = new FormData(ev.target);
    Array.from($('#rfqSuppliers').selectedOptions).forEach(opt => fd.append('suppliers[]', opt.value));
    const j = await fetchJSON('./api/rfqs_save.php', { method:'POST', body:fd });
    bootstrap.Modal.getOrCreateInstance($('#mdlRFQ')).hide();
    toast(`RFQ saved${j.invited_count ? ' • invited: '+j.invited_count : ''}`);
    loadRFQs();
  }catch(e){
    const el = $('#rfqErr'); el.textContent = parseErr(e); el.classList.remove('d-none');
  }
});

// --- Quick status change (via save endpoint) ---
window.quickStatus = async (id,row,newStatus)=>{
  if (!confirm(`Change status to ${newStatus.toUpperCase()}?`)) return;
  const fd = new FormData();
  fd.set('id', id);
  fd.set('title', row.title||'');
  if (row.due_date) fd.set('due_date', row.due_date);
  if (row.notes)    fd.set('notes', row.notes);
  fd.set('status', newStatus);
  try{
    await fetchJSON('./api/rfqs_save.php', { method:'POST', body:fd });
    toast('Status updated'); loadRFQs();
  }catch(e){ alert(parseErr(e)); }
};

// --- Award flow ---
window.openAward = async (rfqId)=>{
  const m = bootstrap.Modal.getOrCreateInstance(document.getElementById('mdlAward')); m.show();
  $('#awardErr')?.classList.add('d-none'); $('#awardRfqId').value = rfqId;
  const sel = $('#awardSupplier'); sel.innerHTML = '<option value="">Loading…</option>';
  try{
    const resp = await fetchJSON('./api/suppliers_list.php?status=1&sort=name&select=1');
    const rows = Array.isArray(resp) ? resp : (resp.rows || []);
    sel.innerHTML = '<option value="">— Select supplier —</option>' +
      rows.map(s=>`<option value="${s.id}">${esc(s.code)} — ${esc(s.name)}</option>`).join('');
  }catch{ sel.innerHTML = '<option value="">(failed to load suppliers)</option>'; }
};
document.getElementById('awardForm').addEventListener('submit', async (ev)=>{
  ev.preventDefault();
  try{
    $('#awardErr').classList.add('d-none');
    const fd = new FormData(ev.target);
    const j = await fetchJSON('./api/rfqs_award.php', { method:'POST', body:fd });
    bootstrap.Modal.getOrCreateInstance($('#mdlAward')).hide();
    toast(`Awarded. PO ${j.po_number} created.`); loadRFQs();
  }catch(e){
    const el = $('#awardErr'); el.textContent = parseErr(e); el.classList.remove('d-none');
  }
});



document.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-open-quotes]');
  if (!btn) return;
  e.preventDefault();
  const id = Number(btn.getAttribute('data-rfq-id'));
  const rfqStatus = (btn.getAttribute('data-rfq-status') || '').toLowerCase();
  if (Number.isFinite(id)) openQuotes(id, rfqStatus);
});

// SINGLE definition of openQuotes (no duplicates!)
window.openQuotes = async (rfqId, rfqStatus='') => {
  const modalEl = document.getElementById('mdlQuotes');
  const m = bootstrap.Modal.getOrCreateInstance(modalEl);
  const tbody = document.getElementById('quotesBody');
  tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4">Loading…</td></tr>`;
  m.show();

 

  try {
    const rows = await fetchJSON('./api/quotes_list.php?rfq_id=' + encodeURIComponent(rfqId));
    const canAward = rfqStatus !== 'awarded';
    const fmtMoney = v => (v==null ? '-' : Number(v).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2}));
    const safe = v => esc(v ?? '-');

    tbody.innerHTML = rows.length ? rows.map(q => `
      <tr>
        <td>${safe(q.supplier_name)}</td>
        <td class="text-end">${fmtMoney(q.total)}</td>
        <td>${q.lead_time_days ?? '-'}</td>
        <td>${q.rating ?? '-'}</td>
        <td>${safe(q.submitted_at)}</td>
        <td class="text-end">
          ${canAward ? `
            <button type="button" class="btn btn-sm btn-success"
                    data-award-quote
                    data-rfq-id="${rfqId}"
                    data-supplier-id="${q.supplier_id}">
              Award
            </button>` : `
            <button type="button" class="btn btn-sm btn-outline-secondary" disabled>
              Awarded
            </button>`}
        </td>
      </tr>
    `).join('') : `<tr><td colspan="6" class="text-center py-4 text-muted">No quotes submitted yet.</td></tr>`;
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="6" class="text-danger py-4 text-center">${esc(parseErr(e))}</td></tr>`;
  }
};

// Award directly from Quotes modal
document.addEventListener('click', async (e) => {
  const btn = e.target.closest('[data-award-quote]');
  if (!btn) return;
  e.preventDefault();
  const rfqId = Number(btn.dataset.rfqId);
  const supplierId = Number(btn.dataset.supplierId);
  if (!Number.isFinite(rfqId) || !Number.isFinite(supplierId)) return;
  if (!confirm('Award this RFQ to the selected supplier?')) return;

  try {
    const fd = new FormData();
    fd.set('rfq_id', rfqId);
    fd.set('supplier_id', supplierId);
    const j = await fetchJSON('./api/rfqs_award.php', { method:'POST', body: fd });
    bootstrap.Modal.getOrCreateInstance(document.getElementById('mdlQuotes')).hide();
    toast(`Awarded. PO ${j.po_number} created.`); loadRFQs();
  } catch (e2) { alert(parseErr(e2)); }
});
</script>
</body>
</html>
