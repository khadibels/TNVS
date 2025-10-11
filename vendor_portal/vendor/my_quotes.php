<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_login();

$section = 'vendor';
$active  = 'quotes';

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
<title>My Quotes / Bids | Vendor Portal</title>
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
          <h2 class="m-0">My Quotes / Bids</h2>
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
            <div class="col-md-5"><input id="fSearch" class="form-control" placeholder="Search RFQ No / Title / Terms…"></div>
            <div class="col-md-3">
              <select id="fOutcome" class="form-select">
                <option value="">All Outcomes</option>
                <option value="pending">Pending</option>
                <option value="awarded_me">Awarded to Me</option>
                <option value="lost">Lost</option>
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
                  <th>RFQ No</th><th>Title</th><th class="text-end">Total</th>
                  <th>Submitted</th><th>Status</th><th>Outcome</th><th class="text-end">Action</th>
                </tr>
              </thead>
              <tbody id="tblBody">
                <tr><td colspan="7" class="text-center py-5 text-muted">Loading…</td></tr>
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

<!-- Detail modal -->
<div class="modal fade" id="mdlQuote" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title"><span id="mTitle">Quote</span> <span id="mStatus" class="ms-2"></span> <span id="mOutcome" class="ms-2"></span></h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body" id="mBody"><div class="text-center text-muted py-5">Loading…</div></div>
    <div class="modal-footer"><button class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button></div>
  </div></div>
</div>

<div class="toast-container position-fixed top-0 end-0 p-3" id="toasts" style="z-index:1080"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const VENDOR_ID = <?= (int)$VENDOR_ID ?>;
const BASE = '<?= $BASE ?>';
const API  = {
  list  : BASE + '/vendor_portal/vendor/api/my_quotes_list.php',
  detail: BASE + '/vendor_portal/vendor/api/my_quote_detail.php',
  notis : BASE + '/vendor_portal/vendor/api/notifications_list.php'
};

const $ = (s,r=document)=>r.querySelector(s);
const esc = s => String(s??'').replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m]));
const state = { page:1, per:10, search:'', outcome:'' };

function badge(s){
  const v=(s||'').toLowerCase();
  const map = { sent:'bg-info text-dark', awarded:'bg-success', closed:'bg-secondary', cancelled:'bg-dark', open:'bg-info text-dark' };
  return `<span class="badge badge-status ${map[v]||'bg-primary'}">${esc(s)}</span>`;
}
function outcomeBadge(v){
  const map = { awarded_me:'bg-success', lost:'bg-secondary', pending:'bg-warning text-dark' };
  const label = v==='awarded_me'?'awarded to me':v;
  return `<span class="badge badge-status ${map[v]||'bg-light text-dark'}">${esc(label)}</span>`;
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

async function loadQuotes(){
  try{
    const qs = new URLSearchParams({page:state.page, per:state.per, search:state.search, outcome:state.outcome});
    const resp = await fetchJSON(API.list + '?' + qs.toString());
    if (resp.error) throw new Error(resp.error);

    const rows = resp.data || [];
    const pg   = resp.pagination || {};

    const tb = $('#tblBody');
    if (rows.length) {
      tb.innerHTML = rows.map(r=>`
        <tr>
          <td class="fw-semibold">${esc(r.rfq_no)}</td>
          <td>${esc(r.title)}</td>
          <td class="text-end">${Number(r.total).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})} ${esc(r.currency)}</td>
          <td>${r.created_at ? new Date(String(r.created_at).replace(' ','T')).toLocaleString() : '-'}</td>
          <td>${badge(r.rfq_status_label)}</td>
          <td>${outcomeBadge(r.outcome)}</td>
          <td class="text-end">
            <button class="btn btn-sm btn-outline-secondary" data-view="${r.id}">
              <ion-icon name="eye-outline"></ion-icon> View
            </button>
          </td>
        </tr>`).join('');
    } else {
      tb.innerHTML = `<tr><td colspan="7" class="text-center py-5 text-muted">No quotes.</td></tr>`;
    }

    const page = Number(pg.page||1), per = Number(pg.per||10), total = Number(pg.total||0);
    const pages = Math.max(1, Math.ceil(total/per));
    $('#pageInfo').textContent = `Page ${page} of ${pages} • ${total} result(s)`;
    const pager = $('#pager'); pager.innerHTML='';
    const li=(p,l,d=false,a=false)=>`<li class="page-item ${d?'disabled':''} ${a?'active':''}"><a class="page-link" href="#" onclick="go(${p});return false;">${l}</a></li>`;
    pager.insertAdjacentHTML('beforeend', li(page-1,'«', page<=1));
    for (let p=Math.max(1,page-2); p<=Math.min(pages,page+2); p++) pager.insertAdjacentHTML('beforeend', li(p,p,false,p===page));
    pager.insertAdjacentHTML('beforeend', li(page+1,'»', page>=pages));
  }catch(e){
    $('#tblBody').innerHTML = `<tr><td colspan="7" class="text-danger text-center py-5">${esc(e.message)}</td></tr>`;
    console.error('Quotes load error:', e);
  }
}
window.go = p => { if(p<1) return; state.page=p; loadQuotes(); };

document.getElementById('btnFilter').addEventListener('click', ()=>{
  state.page=1; state.search=$('#fSearch').value.trim(); state.outcome=$('#fOutcome').value; loadQuotes();
});
document.getElementById('tblBody').addEventListener('click', (e)=>{
  const id = e.target.closest('[data-view]')?.getAttribute('data-view');
  if (id) openQuoteModal(Number(id));
});

async function openQuoteModal(id){
  const m = new bootstrap.Modal(document.getElementById('mdlQuote'));
  $('#mBody').innerHTML = `<div class="text-center text-muted py-5">Loading…</div>`;
  try{
    const j = await fetchJSON(API.detail+'?id='+id);
    if (j.error) throw new Error(j.error);

    const q   = j.quote;
    const rfq = j.rfq;
    const items = j.items || [];
    const lines = j.quote_items || [];
    const lineMap = Object.fromEntries(lines.map(x=>[String(x.line_no), Number(x.unit_price)]));

    // status label (vendor-facing)
    const rfqLabel = (String(rfq.status).toLowerCase()==='sent') ? 'open' : rfq.status;

    $('#mTitle').textContent = `RFQ ${rfq.rfq_no} — ${rfq.title}`;
    $('#mStatus').innerHTML  = badge(rfqLabel);
    $('#mOutcome').innerHTML = outcomeBadge(j.outcome);

    const itemsHTML = items.length ? `
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead><tr><th>#</th><th>Item</th><th>Specs</th><th class="text-end">Qty</th><th>UOM</th><th class="text-end">Unit Price</th></tr></thead>
          <tbody>${
            items.map(r=>{
              const up = lineMap[String(r.line_no)];
              return `<tr>
                <td>${r.line_no}</td><td>${esc(r.item)}</td>
                <td class="text-muted">${esc(r.specs||'')}</td>
                <td class="text-end">${r.qty}</td>
                <td>${esc(r.uom||'')}</td>
                <td class="text-end">${up!=null? Number(up).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:4}) : '-'}</td>
              </tr>`;
            }).join('')
          }</tbody>
        </table>
      </div>` : `<div class="text-muted">No items.</div>`;

    $('#mBody').innerHTML = `
      <div class="row g-4">
        <div class="col-lg-7">
          <h6 class="fw-semibold">RFQ Details</h6>
          <dl class="row mb-0">
            <dt class="col-sm-3">Due</dt><dd class="col-sm-9">${new Date(String(rfq.due_at).replace(' ','T')).toLocaleString()}</dd>
            <dt class="col-sm-3">Currency</dt><dd class="col-sm-9">${esc(rfq.currency)}</dd>
            ${rfq.description ? `<dt class="col-sm-3">Description</dt><dd class="col-sm-9">${esc(rfq.description)}</dd>`:''}
          </dl>
          <hr class="my-3"><h6 class="fw-semibold">Items</h6>${itemsHTML}
        </div>
        <div class="col-lg-5">
          <h6 class="fw-semibold">My Quote</h6>
          <dl class="row mb-0">
            <dt class="col-sm-5">Submitted</dt><dd class="col-sm-7">${q.created_at ? new Date(String(q.created_at).replace(' ','T')).toLocaleString() : '-'}</dd>
            <dt class="col-sm-5">Total</dt><dd class="col-sm-7">${Number(q.total).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})} ${esc(q.currency)}</dd>
            <dt class="col-sm-5">Lead time (days)</dt><dd class="col-sm-7">${q.lead_time_days ?? '-'}</dd>
            <dt class="col-sm-5">Terms / Notes</dt><dd class="col-sm-7">${esc(q.terms ?? '')}</dd>
          </dl>
        </div>
      </div>`;
    m.show();
  }catch(e){
    $('#mBody').innerHTML = `<div class="alert alert-danger">${esc(e.message)}</div>`;
    console.error('Quote detail error:', e);
    m.show();
  }
}

// notifs
(async function refreshNotis(){
  try{
    const j = await fetchJSON(API.notis);
    const c = Number(j.unread||0);
    const el = document.getElementById('notifCount');
    el.textContent = c>99 ? '99+' : c;
    el.classList.toggle('d-none', c<=0);
  }catch(e){}
})();

// initial
loadQuotes();
</script>
</body>
</html>
