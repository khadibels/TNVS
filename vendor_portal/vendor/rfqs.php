<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_login();

$section = 'vendor';
$active  = 'rfqs';

$pdo = db('proc');
if (!$pdo instanceof PDO) { http_response_code(500); die('DB connection error'); }

$user       = current_user();
$vendorName = $user['company_name'] ?? ($user['name'] ?? 'Vendor');
$VENDOR_ID  = (int)($user['vendor_id'] ?? 0);
$BASE       = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';

function vendor_avatar_url(): string {
    $base = rtrim(BASE_URL, '/');
    $id   = (int)($_SESSION['user']['vendor_id'] ?? 0);
    if ($id <= 0) return $base . '/img/profile.jpg';
    $root = realpath(__DIR__ . '/../../');
    $uploadDir = $root . "/vendor_portal/vendor/uploads";
    foreach (["jpg","jpeg","png","webp"] as $ext) {
        $files = glob($uploadDir . "/vendor_{$id}_*.{$ext}");
        if ($files && file_exists($files[0])) {
            $rel = str_replace($root, '', $files[0]);
            return $base . $rel;
        }
    }
    return $base . '/img/profile.jpg';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>RFQs | Vendor Portal</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
<link href="<?= $BASE ?>/css/style.css" rel="stylesheet" />
<link href="<?= $BASE ?>/css/modules.css" rel="stylesheet" />
<script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
<script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
<script src="<?= $BASE ?>/js/sidebar-toggle.js"></script>
<style>
  body{background:#f6f7fb}
  .main-content{padding:1.25rem} @media(min-width:992px){.main-content{padding:2rem}}
  .card{border-radius:16px}
  .badge-status{border-radius:999px}
  .table thead th{font-size:.82rem;text-transform:uppercase}
  .modal-xl .modal-body{max-height:calc(100vh - 210px); overflow:auto}
  .rfq-row{cursor:pointer}
</style>
</head>
<body>
<div class="container-fluid p-0">
  <div class="row g-0">
    <?php include __DIR__ . '/../../includes/sidebar.php'; ?>
    <div class="col main-content">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-3">
          <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2" aria-label="Toggle sidebar">
            <ion-icon name="menu-outline"></ion-icon>
          </button>
          <h2 class="m-0">RFQs</h2>
        </div>
        <div class="d-flex align-items-center gap-2">
          <a href="<?= $BASE ?>/vendor_portal/vendor/notifications.php" class="btn btn-outline-secondary position-relative">
            <ion-icon name="notifications-outline" class="me-1"></ion-icon> Notifications
            <span id="notifCount" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none"></span>
          </a>
          <img src="<?= htmlspecialchars(vendor_avatar_url(), ENT_QUOTES) ?>" class="rounded-circle border" width="36" height="36" alt="">
          <div class="small text-end">
            <strong><?= htmlspecialchars($vendorName, ENT_QUOTES) ?></strong><br>
            <span class="text-muted">vendor</span>
          </div>
        </div>
      </div>

      <section class="card shadow-sm mb-3">
        <div class="card-body">
          <div class="row g-2 align-items-center">
            <div class="col-md-5"><input id="fSearch" class="form-control" placeholder="Search RFQ No or Title…"></div>
            <div class="col-md-3">
              <select id="fStatus" class="form-select">
                <option value="">All Status</option>
                <option value="sent">Sent</option>
                <option value="awarded">Awarded</option>
                <option value="closed">Closed</option>
                <option value="cancelled">Cancelled</option>
              </select>
            </div>
            <div class="col-md-2">
              <button class="btn btn-primary w-100" id="btnFilter">
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
                  <th>RFQ No</th><th>Title</th><th>Due</th>
                  <th class="text-center">My Quotes</th><th>Status</th><th class="text-end">Action</th>
                </tr>
              </thead>
              <tbody id="tblBody">
                <tr><td colspan="6" class="text-center py-5 text-muted">Loading…</td></tr>
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

<div class="modal fade" id="mdlRFQ" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title"><span id="mTitle">RFQ</span> <span id="mStatus" class="ms-2"></span></h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
      <div id="mBody"><div class="text-center text-muted py-5">Loading…</div></div>
      <hr class="my-4">
      <form id="quoteForm">
        <input type="hidden" name="rfq_id" id="qRfqId">
        <div class="row g-3">
          <div class="col-md-3"><label class="form-label">Lead time (days)</label><input class="form-control" name="lead_time_days" type="number" min="0"></div>
          <div class="col-md-3"><label class="form-label">Total <span class="text-danger">*</span></label><input class="form-control" name="total" type="number" step="0.01" min="0" required></div>
          <div class="col-md-6"><label class="form-label">Terms / Notes</label><input class="form-control" name="terms" maxlength="300"></div>
          <div class="col-12"><div class="small text-muted mb-2">Optional per-item pricing</div><div id="priceRows" class="row g-2"></div></div>
        </div>
        <div id="qErr" class="alert alert-danger mt-3 d-none"></div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
      <button class="btn btn-primary" id="btnSubmitQuote">Submit Quote</button>
    </div>
  </div></div>
</div>

<div class="toast-container position-fixed top-0 end-0 p-3" id="toasts" style="z-index:1080"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const VENDOR_ID = <?= (int)$VENDOR_ID ?>;
const BASE = '<?= $BASE ?>';
const API  = {
  list  : BASE + '/vendor_portal/vendor/api/rfqs_list.php',
  detail: BASE + '/vendor_portal/vendor/api/rfq_detail.php',
  quote : BASE + '/vendor_portal/vendor/api/quote_submit.php',
  notis : BASE + '/vendor_portal/vendor/api/notifications_list.php'
};

const $ = (s,r=document)=>r.querySelector(s);
const esc = s => String(s??'').replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m]));
const state = { page:1, per:10, search:'', status:'' };

/* Map backend status → vendor-facing badge.
   r.status already normalized by API: if awarded to someone else → "closed". */
function badge(statusRaw){
  const s=(statusRaw||'').toLowerCase();
  const label = (s==='sent') ? 'open' : s;   // vendor sees "open"
  const map   = { open:'bg-info text-dark', awarded:'bg-success', closed:'bg-secondary', cancelled:'bg-dark' };
  const cls   = map[label] || 'bg-primary';
  return `<span class="badge badge-status ${cls}">${esc(label)}</span>`;
}

async function fetchJSON(u, opts) {
  const r = await fetch(u, opts);
  const t = await r.text();
  let j; try { j = JSON.parse(t); } catch {}
  if (!r.ok) {
    const msg = (j && (j.error || j.message)) || t || r.statusText;
    throw new Error(msg);
  }
  return j ?? {};
}

async function loadRFQs(){
  try{
    const qs = new URLSearchParams({
      page: state.page, per: state.per,
      search: state.search, status: state.status
    });

    const resp = await fetchJSON(API.list + '?' + qs.toString());
    if (resp && resp.error) throw new Error(resp.error);

    const rows = Array.isArray(resp.data) ? resp.data : [];
    const pg   = resp.pagination || {};

    // Render table
    const tb = $('#tblBody');
    if (rows.length) {
      tb.innerHTML = rows.map(r => `
        <tr class="rfq-row">
          <td class="fw-semibold">${esc(r.rfq_no)}</td>
          <td>${esc(r.title)}</td>
          <td>${new Date(String(r.due_at).replace(' ', 'T')).toLocaleString()}</td>
          <td class="text-center">${r.my_quotes ?? 0}</td>
          <td>${badge(r.status)}</td>
          <td class="text-end">
            <button class="btn btn-sm btn-outline-secondary" data-view="${r.id}">
              <ion-icon name="eye-outline"></ion-icon> View
            </button>
          </td>
        </tr>`).join('');
    } else {
      tb.innerHTML = `<tr><td colspan="6" class="text-center py-5 text-muted">No RFQs.</td></tr>`;
    }

    // Pagination
    const page = Number(pg.page || 1), per = Number(pg.per || 10), total = Number(pg.total || 0);
    const pages = Math.max(1, Math.ceil(total/per));
    $('#pageInfo').textContent = `Page ${page} of ${pages} • ${total} result(s)`;

    const pager = $('#pager'); pager.innerHTML = '';
    const li=(p,l,pd=false,act=false)=>`<li class="page-item ${pd?'disabled':''} ${act?'active':''}">
        <a class="page-link" href="#" onclick="go(${p});return false;">${l}</a></li>`;
    pager.insertAdjacentHTML('beforeend', li(page-1,'«', page<=1));
    for (let p=Math.max(1,page-2); p<=Math.min(pages,page+2); p++) {
      pager.insertAdjacentHTML('beforeend', li(p, p, false, p===page));
    }
    pager.insertAdjacentHTML('beforeend', li(page+1,'»', page>=pages));
  }catch(e){
    $('#tblBody').innerHTML = `<tr><td colspan="6" class="text-danger text-center py-5">${esc(e.message)}</td></tr>`;
    console.error('RFQs load error:', e);
  }
}
window.go = p => { if (p<1) return; state.page=p; loadRFQs(); };

// Reliable click handler (only on the table body)
document.getElementById('tblBody').addEventListener('click', (e)=>{
  const btn = e.target.closest('button[data-view]');
  if (!btn) return;
  e.preventDefault();
  const id = Number(btn.getAttribute('data-view'));
  if (id) openRFQModal(id);
});

document.getElementById('btnFilter').addEventListener('click', ()=>{
  state.page=1;
  state.search=$('#fSearch').value.trim();
  state.status=$('#fStatus').value;
  loadRFQs();
});

async function openRFQModal(id){
  const m = new bootstrap.Modal(document.getElementById('mdlRFQ'));
  $('#mBody').innerHTML = `<div class="text-center text-muted py-5">Loading…</div>`;
  $('#qRfqId').value = id;
  $('#qErr').classList.add('d-none');

  try{
    const j = await fetchJSON(API.detail+'?id='+id);
    if (j.error) throw new Error(j.error);

    const rfq       = j.rfq;
    const items     = j.items || [];
    const my_quotes = j.my_quotes || [];
    if (!rfq) throw new Error('RFQ not found');

    // vendor-facing label for status
    const label = (String(rfq.status).toLowerCase()==='sent') ? 'open' : rfq.status;

    $('#mTitle').textContent = `RFQ ${rfq.rfq_no} — ${rfq.title}`;
    $('#mStatus').innerHTML  = badge(label);

    const itemsHTML = items.length ? `
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead><tr><th>#</th><th>Item</th><th>Specs</th><th class="text-end">Qty</th><th>UOM</th></tr></thead>
          <tbody>${items.map(r=>`<tr><td>${r.line_no}</td><td>${esc(r.item)}</td><td class="text-muted">${esc(r.specs||'')}</td><td class="text-end">${r.qty}</td><td>${esc(r.uom||'')}</td></tr>`).join('')}</tbody>
        </table>
      </div>` : `<div class="text-muted">No items.</div>`;

    const quotesHTML = my_quotes.length ? `
      <div class="table-responsive"><table class="table table-sm">
        <thead><tr><th>Submitted</th><th class="text-end">Total</th><th>Lead (d)</th><th>Terms</th></tr></thead>
        <tbody>${my_quotes.map(q=>`<tr>
          <td>${q.created_at ? new Date(String(q.created_at).replace(' ', 'T')).toLocaleString() : '-'}</td>
          <td class="text-end">${Number(q.total).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}</td>
          <td>${q.lead_time_days ?? '-'}</td>
          <td class="small">${esc(q.terms ?? '')}</td>
        </tr>`).join('')}</tbody>
      </table></div>` : `<div class="text-muted">No quotes yet.</div>`;

    $('#mBody').innerHTML = `
      <div class="row g-4">
        <div class="col-lg-7">
          <h6 class="fw-semibold">Details</h6>
          <dl class="row mb-0">
            <dt class="col-sm-3">Due</dt><dd class="col-sm-9">${new Date(String(rfq.due_at).replace(' ', 'T')).toLocaleString()}</dd>
            <dt class="col-sm-3">Currency</dt><dd class="col-sm-9">${esc(rfq.currency)}</dd>
            ${rfq.description ? `<dt class="col-sm-3">Description</dt><dd class="col-sm-9">${esc(rfq.description)}</dd>`:''}
          </dl>
          <hr class="my-3"><h6 class="fw-semibold">Items</h6>${itemsHTML}
        </div>
        <div class="col-lg-5">
          <h6 class="fw-semibold">My Quotes</h6>${quotesHTML}
        </div>
      </div>`;

    // build optional per-item inputs
    const priceWrap = $('#priceRows'); priceWrap.innerHTML='';
    items.forEach(r=>{
      const div = document.createElement('div'); div.className='col-12 col-md-6';
      div.innerHTML = `<label class="form-label small">Line ${r.line_no} — ${esc(r.item)}</label>
        <input class="form-control text-end" name="price[${r.line_no}]" form="quoteForm" type="number" step="0.0001" min="0" placeholder="Unit Price">`;
      priceWrap.appendChild(div);
    });

    // enable/disable submit on the UI (server still validates)
    const canQuote = String(rfq.status).toLowerCase() === 'sent';
    const btn = document.getElementById('btnSubmitQuote');
    btn.disabled = !canQuote;

    m.show();
  }catch(e){
    $('#mBody').innerHTML = `<div class="alert alert-danger">${esc(e.message)}</div>`;
    console.error('RFQ detail error:', e);
    (new bootstrap.Modal(document.getElementById('mdlRFQ'))).show();
  }
}

document.getElementById('btnSubmitQuote').addEventListener('click', async ()=>{
  const btn = document.getElementById('btnSubmitQuote');
  if (btn.disabled) return;
  btn.disabled = true; const prev = btn.innerHTML; btn.innerHTML = `<span class="spinner-border spinner-border-sm"></span> Submitting…`;
  $('#qErr').classList.add('d-none');
  try{
    const fd = new FormData(document.getElementById('quoteForm'));
    const j  = await fetchJSON(API.quote, { method:'POST', body: fd });
    if (j.error) throw new Error(j.error);
    bootstrap.Modal.getInstance(document.getElementById('mdlRFQ')).hide();
    const wrap = document.getElementById('toasts');
    const el = document.createElement('div');
    el.className='toast text-bg-success border-0 show';
    el.innerHTML=`<div class="d-flex"><div class="toast-body">Quote submitted!</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
    wrap.appendChild(el); setTimeout(()=>el.remove(),2200);
    loadRFQs();
  }catch(e){
    const el = $('#qErr'); el.textContent = e.message || 'Submit failed'; el.classList.remove('d-none');
    console.error('Quote submit error:', e);
  }finally{
    btn.disabled=false; btn.innerHTML=prev;
  }
});

// notifs (optional)
(async function refreshNotis(){
  try{
    const j = await fetchJSON(API.notis);
    const c = Number(j.unread||0);
    const el = document.getElementById('notifCount');
    el.textContent = c>99 ? '99+' : c;
    el.classList.toggle('d-none', c<=0);
  }catch(e){}
})();

// deep-link “#open={id}”
(function(){
  const hash = location.hash;
  if (hash.startsWith('#open=')) {
    const id = Number(hash.split('=')[1]);
    if (id) openRFQModal(id);
  }
})();

loadRFQs();
</script>

</body>
</html>
