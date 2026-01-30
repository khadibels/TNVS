<?php
// File: procurement/quoteEvaluation.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
require_role(['admin','procurement_officer']);

$pdo = db('proc');
if (!$pdo instanceof PDO) { http_response_code(500); die('DB error'); }
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$section = 'procurement';
$active  = 'po_quotes';

$rfq_id = (int)($_GET['rfq_id'] ?? 0);

/* ---------- preload RFQs (left list) ---------- */
// Only fetch active or recently closed ones to keep it snappy
$rfqs = [];
try {
  $rfqs = $pdo->query("
    SELECT r.id, r.rfq_no, r.title, r.due_at, r.currency, r.status,
           (SELECT COUNT(*) FROM quotes q WHERE q.rfq_id=r.id) AS quotes_count
    FROM rfqs r
    ORDER BY r.id DESC
    LIMIT 100
  ")->fetchAll(PDO::FETCH_ASSOC);

  // Auto-select first if none picked
  if ($rfq_id === 0 && !empty($rfqs)) {
      $rfq_id = (int)$rfqs[0]['id'];
  }
} catch (Throwable $e) {}

$user     = current_user();
$userName = $user['name'] ?? 'Procurement User';
$userRole = $user['role'] ?? 'Procurement';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Quote Evaluation & Award | Procurement</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="../css/style.css" rel="stylesheet"/>
  <link href="../css/modules.css" rel="stylesheet"/>
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
    body { background-color: #f8fafc; }
    
    /* Typography & Utilities */
    .f-mono { font-family: 'SF Mono', 'Segoe UI Mono', 'Roboto Mono', monospace; letter-spacing: -0.5px; }
    .text-label { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.6px; font-weight: 700; color: #94a3b8; margin-bottom: 2px; }
    .text-value { font-size: 0.95rem; font-weight: 500; color: var(--slate-800); }
    .card { border: 1px solid var(--slate-200); box-shadow: 0 1px 3px 0 rgb(0 0 0 / 0.05); border-radius: 0.75rem; background: #fff; margin-bottom: 1.5rem; }
    .card-header { background: transparent; border-bottom: 1px solid var(--slate-200); padding: 1rem 1.25rem; }
    .card-title { font-size: 0.95rem; font-weight: 700; color: var(--slate-800); margin: 0; display: flex; align-items: center; gap: 0.5rem; }
    
    /* Custom Table */
    .table-custom thead th { 
      font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.5px; 
      color: var(--slate-600); background: var(--slate-50); 
      border-bottom: 1px solid var(--slate-200); font-weight: 600; padding: 0.75rem 1rem;
    }
    .table-custom tbody td { 
      padding: 0.75rem 1rem; border-bottom: 1px solid var(--slate-100); 
      font-size: 0.9rem; color: var(--slate-800); vertical-align: middle;
    }
    .table-custom tr:last-child td { border-bottom: none; }
    
    /* Layout */
    .rfq-list-item { border-left: 3px solid transparent; transition: all 0.2s; padding: 1rem; }
    .rfq-list-item:hover { background: var(--slate-50); }
    .rfq-list-item.active { background: #eff6ff; border-left-color: var(--primary-600); }
    .rfq-list-item .title { font-weight: 600; color: var(--slate-800); margin-bottom: 2px; }
    
    .sticky-footer {
      position: fixed; bottom: 0; right: 0; left: 0;
      background: white; border-top: 1px solid var(--slate-200);
      padding: 1rem 2rem; z-index: 900;
      box-shadow: 0 -4px 20px -5px rgba(0,0,0,0.05);
      display: flex; align-items: center; justify-content: flex-end; gap: 1rem;
      transition: margin-left 0.3s ease;
    }
    @media(min-width: 1000px) { .sticky-footer { margin-left: 260px; } }
    
    /* Badges */
    .badge-modern { padding: 4px 10px; border-radius: 999px; font-weight: 600; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.5px; }
    .badge-modern.open { background: #e0f2fe; color: #0284c7; }
    .badge-modern.closed { background: #f1f5f9; color: #475569; }
    .badge-modern.awarded { background: #dcfce7; color: #16a34a; }
  </style>
</head>
<body class="saas-page">
<div class="container-fluid p-0">
  <div class="row g-0">

    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="col main-content">

      <!-- Topbar -->
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-3">
          <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2">
            <ion-icon name="menu-outline"></ion-icon>
          </button>
          <h2 class="m-0 d-flex align-items-center gap-2 page-title">
            <ion-icon name="pricetags-outline"></ion-icon>Quote Evaluation & Award
          </h2>
        </div>
        
        <div class="profile-menu" data-profile-menu>
          <button class="profile-trigger" type="button" data-profile-trigger>
            <img src="../img/profile.jpg" class="rounded-circle" width="36" height="36" alt="">
            <div class="profile-text">
              <div class="profile-name"><?= h($userName) ?></div>
              <div class="profile-role"><?= h($userRole) ?></div>
            </div>
            <ion-icon class="profile-caret" name="chevron-down-outline"></ion-icon>
          </button>
          <div class="profile-dropdown" data-profile-dropdown role="menu">
             <a href="<?= u('auth/logout.php') ?>" role="menuitem">Sign out</a>
          </div>
        </div>
      </div>

      <div class="row g-4 pb-5 mb-5 ps-2">
        <!-- LEFT: RFQ Picker -->
        <div class="col-lg-4 col-xxl-3">
          <div class="card h-100 border-0 shadow-sm" style="max-height: calc(100vh - 120px); overflow: hidden; display: flex; flex-direction: column;">
            <div class="p-3 border-bottom">
              <div class="input-group input-group-sm">
                <span class="input-group-text bg-light border-end-0"><ion-icon name="search-outline"></ion-icon></span>
                <input class="form-control bg-light border-start-0" id="rfqSearch" placeholder="Search RFQ #...">
              </div>
            </div>
            <div class="overflow-auto flex-grow-1" id="rfqList">
              <?php if (!$rfqs): ?>
                <div class="p-4 text-center text-muted small">No active RFQs found.</div>
              <?php else: foreach ($rfqs as $r): 
                  $activeClass = ($rfq_id === (int)$r['id']) ? ' active' : '';
                  $badgeCls = match(strtolower($r['status']??'')){ 'awarded'=>'awarded', 'closed'=>'closed', default=>'open' };
              ?>
                <div onclick="window.location.href='?rfq_id=<?= (int)$r['id'] ?>'" 
                     class="rfq-list-item cursor-pointer<?= $activeClass ?>">
                  <div class="d-flex justify-content-between align-items-start mb-1">
                    <span class="badge-modern <?= $badgeCls ?>"><?= h($r['status']) ?></span>
                    <span class="small text-muted"><?= $r['due_at'] ? date('M d', strtotime($r['due_at'])) : '' ?></span>
                  </div>
                  <div class="title text-truncate"><?= h($r['rfq_no']) ?></div>
                  <div class="small text-muted text-truncate"><?= h($r['title']) ?></div>
                  <div class="mt-2 d-flex align-items-center gap-1 small text-muted">
                    <ion-icon name="documents-outline"></ion-icon> <?= (int)$r['quotes_count'] ?> Quotes
                  </div>
                </div>
              <?php endforeach; endif; ?>
            </div>
          </div>
        </div>

        <!-- RIGHT: Evaluation Detail -->
        <div class="col-lg-8 col-xxl-9">
          <!-- Container for dynamic content -->
          <div id="evalContainer">
            <?php if ($rfq_id <= 0): ?>
              <div class="text-center py-5 text-muted">
                <ion-icon name="arrow-back-outline" class="fs-1 mb-2"></ion-icon>
                <p>Select a Request for Quotation to start evaluation.</p>
              </div>
            <?php else: ?>
              <div class="text-center py-5 text-muted">Loading details...</div>
            <?php endif; ?>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- Sticky Footer Actions -->
<div id="actionFooter" class="sticky-footer d-none">
  <div class="me-auto">
    <div id="footerMsg" class="text-muted small fw-medium"></div>
  </div>
  <button id="btnAwardLines" class="btn btn-white border shadow-sm text-dark fw-medium">
    <ion-icon name="list-outline" class="me-1"></ion-icon> Award Selected Items
  </button>
  <button id="btnAwardOverall" class="btn btn-primary fw-medium px-4">
    <ion-icon name="trophy-outline" class="me-1"></ion-icon> Award to Supplier
  </button>
</div>

<!-- Toasts -->
<div class="toast-container position-fixed top-0 end-0 p-3" id="toasts" style="z-index:1100"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="../js/profile-dropdown.js"></script>
<script>
const $ = (s,r=document)=>r.querySelector(s);
const $$ = (s,r=document)=>Array.from(r.querySelectorAll(s));
const esc = s => String(s??'').replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m]));
const rfqId = Number(new URLSearchParams(location.search).get('rfq_id') || 0);

async function fetchJSON(u, opts){
  const res = await fetch(u, opts);
  const txt = await res.text();
  try{ 
    const j=JSON.parse(txt); 
    if(!res.ok||j.error) throw new Error(j.error||txt);
    return j;
  }catch(e){ throw new Error(e.message||txt); }
}

function toast(msg, variant='success'){
  const el=document.createElement('div');
  el.className=`toast align-items-center text-bg-${variant} border-0 shadow`;
  el.innerHTML=`<div class="d-flex"><div class="toast-body">${esc(msg)}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
  $('#toasts').appendChild(el);
  new bootstrap.Toast(el).show();
  el.addEventListener('hidden.bs.toast',()=>el.remove());
}

/* ================= RENDER LOGIC ================= */

function renderHeader(rfq) {
  const statusColors = { awarded:'success', closed:'secondary', open:'info', cancelled:'danger' };
  const stColor = statusColors[(rfq.status||'').toLowerCase()] || 'primary';
  
  return `
    <div class="card mb-4">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start mb-3">
          <div>
            <div class="d-flex align-items-center gap-2 mb-1">
              <span class="badge bg-${stColor} bg-opacity-10 text-${stColor} px-3 py-2 rounded-pill fw-bold text-uppercase" style="font-size:0.75rem; letter-spacing:0.5px">
                ${esc(rfq.status)}
              </span>
              <span class="text-muted small">Created ${rfq.created_at ? rfq.created_at.split(' ')[0] : '-'}</span>
            </div>
            <h4 class="fw-bold text-dark mb-1">${esc(rfq.rfq_no)}</h4>
            <p class="text-secondary mb-0">${esc(rfq.title)}</p>
          </div>
          <div class="text-end">
            <div class="text-label">Target Currency</div>
            <div class="text-value fs-5">${esc(rfq.currency)}</div>
          </div>
        </div>
        <div class="d-flex gap-5 border-top pt-3">
          <div>
            <div class="text-label">Submission Deadline</div>
            <div class="text-value d-flex align-items-center gap-1">
              <ion-icon name="calendar-outline"></ion-icon>
              ${rfq.due_at ? new Date(rfq.due_at).toLocaleString() : 'No Limit'}
            </div>
          </div>
          <div>
            <div class="text-label">Awarded On</div>
            <div class="text-value text-muted">
              ${rfq.awarded_at ? new Date(rfq.awarded_at).toLocaleString() : '—'}
            </div>
          </div>
        </div>
      </div>
    </div>
  `;
}

function renderQuotesSummary(quotes, rfq) {
  if (!quotes || !quotes.length) return `<div class="alert alert-info border-0 shadow-sm"><ion-icon name="information-circle-outline"></ion-icon> No quotes received yet.</div>`;
  
  const isAwarded = rfq.status.toLowerCase() === 'awarded';
  const winnerId = Number(rfq.awarded_vendor_id || 0);

  const rows = quotes.map(q => {
    const isWin = (Number(q.vendor_id) === winnerId);
    if(isAwarded && !isWin) return ''; // Option: hide losers if awarded? Or show dimmed? Let's show dimmed.
    const rowClass = (isAwarded && !isWin) ? 'opacity-50' : '';
    const badge = isWin ? `<span class="badge bg-success ms-2"><ion-icon name="checkmark-circle"></ion-icon> WINNER</span>` : '';
    
    return `
      <tr class="${rowClass} align-middle">
        <td width="50" class="text-center">
          <input type="radio" name="win_vendor" class="form-check-input" value="${q.vendor_id}" 
            ${isWin?'checked':''} ${isAwarded?'disabled':''} style="width:1.2em; height:1.2em;">
        </td>
        <td>
          <div class="fw-bold text-dark">${esc(q.supplier_name)} ${badge}</div>
          <div class="small text-muted">Submitted: ${q.created_at}</div>
        </td>
        <td class="text-end f-mono fs-6 fw-bold text-dark">
          ${Number(q.total).toLocaleString(undefined,{minimumFractionDigits:2})}
        </td>
        <td><span class="badge bg-light text-dark border">${esc(q.terms || 'N/A')}</span></td>
        <td class="text-end">
           <button class="btn btn-sm btn-link text-decoration-none" onclick="viewQuote(${q.id})">View</button>
        </td>
      </tr>
    `;
  }).join('');

  return `
    <div class="card mb-4">
      <div class="card-header">
        <h5 class="card-title"><ion-icon name="people-outline"></ion-icon> Supplier Quotes</h5>
      </div>
      <div class="table-responsive">
        <table class="table table-custom table-hover mb-0">
          <thead>
            <tr>
              <th class="text-center">Pick</th>
              <th>Supplier</th>
              <th class="text-end">Grand Total</th>
              <th>Payment Terms</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>${rows}</tbody>
        </table>
      </div>
    </div>
  `;
}

function renderMatrix(items, quotes, matrix, rfq) {
  if (!quotes.length || !items.length) return '';

  const ths = quotes.map(q => `<th class="text-end" style="min-width:140px">${esc(q.supplier_name)}</th>`).join('');
  
  const rows = items.map(it => {
    const tds = quotes.map(q => {
       const val = (matrix[q.id] && matrix[q.id][it.line_no]);
       const price = val ? Number(val) : null;
       return `<td class="text-end f-mono">${price ? price.toLocaleString(undefined,{minimumFractionDigits:2}) : '<span class="text-muted">-</span>'}</td>`;
    }).join('');
    
    return `
      <tr>
        <td width="40">
           <div class="form-check">
             <input class="form-check-input line-check" type="checkbox" value="${it.id}" ${rfq.status==='awarded'?'disabled':''}>
           </div>
        </td>
        <td width="50" class="text-muted fw-bold">#${it.line_no}</td>
        <td>
          <div class="fw-bold text-dark">${esc(it.item)}</div>
          <div class="small text-muted text-truncate" style="max-width:200px">${esc(it.specs)}</div>
        </td>
        <td class="text-center"><span class="badge bg-light text-dark border">${Number(it.qty).toLocaleString()} ${esc(it.uom)}</span></td>
        ${tds}
      </tr>
    `;
  }).join('');

  return `
    <div class="card mb-4">
      <div class="card-header d-flex justify-content-between">
        <h5 class="card-title"><ion-icon name="grid-outline"></ion-icon> Price Comparison Matrix</h5>
        <div class="form-check small m-0">
          <input class="form-check-input" type="checkbox" id="toggleSpecs">
          <label class="form-check-label text-muted" for="toggleSpecs">Show Full Specs</label>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table table-custom table-hover mb-0" id="matrixTable">
          <thead>
            <tr>
              <th><input type="checkbox" class="form-check-input" onclick="toggleAllLines(this)"></th>
              <th>Line</th>
              <th>Item Description</th>
              <th class="text-center">Qty</th>
              ${ths}
            </tr>
          </thead>
          <tbody>${rows}</tbody>
        </table>
      </div>
    </div>
  `;
}

/* ================= LOADING ================= */

async function loadEval() {
  const container = $('#evalContainer');
  const footer = $('#actionFooter');
  
  if (!rfqId) return; // Already handled by static HTML

  try {
    const data = await fetchJSON('./api/quote_eval_detail.php?rfq_id='+rfqId);
    
    // Unpack
    const { rfq, items=[], quotes=[], matrix={} } = data;
    
    const html = `
      ${renderHeader(rfq)}
      ${renderQuotesSummary(quotes, rfq)}
      ${renderMatrix(items, quotes, matrix, rfq)}
    `;
    
    container.innerHTML = html;
    
    // Footer state
    const isAwarded = (rfq.status.toLowerCase() === 'awarded');
    footer.classList.remove('d-none');
    
    if (isAwarded) {
      $('#btnAwardLines').disabled = true;
      $('#btnAwardOverall').disabled = true;
      $('#btnAwardOverall').innerHTML = `<ion-icon name="checkmark-done-circle"></ion-icon> Awarded`;
      $('#footerMsg').innerHTML = `This RFQ was awarded on <b>${new Date(rfq.awarded_at).toLocaleDateString()}</b>.`;
    } else {
      $('#btnAwardLines').disabled = false;
      $('#btnAwardOverall').disabled = false;
      $('#footerMsg').textContent = 'Select a supplier above to proceed with awarding.';
    }

  } catch (e) {
    container.innerHTML = `<div class="alert alert-danger shadow-sm">Failed to load: ${esc(e.message)}</div>`;
  }
}

document.addEventListener('DOMContentLoaded', loadEval);

/* ================= INTERACTION ================= */

window.toggleAllLines = (cb) => {
  $$('.line-check').forEach(el => el.checked = cb.checked);
};

// Search filter
$('#rfqSearch').addEventListener('input', (e) => {
  const term = e.target.value.toLowerCase();
  $$('.rfq-list-item').forEach(el => {
    const txt = el.innerText.toLowerCase();
    el.style.display = txt.includes(term) ? '' : 'none';
  });
});

/* ================= AWARDING ================= */

function getSelectedVendor() {
  const r = document.querySelector('input[name="win_vendor"]:checked');
  return r ? r.value : null;
}

async function doAward(mode, vendorId, lines=[]) {
  if(!confirm('Are you sure you want to proceed with this award? This action cannot be undone efficiently.')) return;
  
  const fd = new FormData();
  fd.append('rfq_id', rfqId);
  fd.append('vendor_id', vendorId);
  fd.append('mode', mode);
  lines.forEach(id => fd.append('lines[]', id));
  
  try {
    const res = await fetchJSON('./api/award_quote.php', { method:'POST', body: fd });
    toast('Award processed successfully!');
    loadEval(); // Reload to show locked state
  } catch(e) {
    toast(e.message, 'danger');
  }
}

$('#btnAwardOverall').addEventListener('click', () => {
  const vid = getSelectedVendor();
  if(!vid) return toast('Please select a winning supplier from the "Supplier Quotes" table.', 'warning');
  doAward('overall', vid);
});

$('#btnAwardLines').addEventListener('click', () => {
  const vid = getSelectedVendor();
  if(!vid) return toast('Please select a supplier to award these lines to.', 'warning');
  
  const lines = $$('.line-check:checked').map(c => c.value);
  if(!lines.length) return toast('Please check at least one line item in the Matrix table.', 'warning');
  
  doAward('lines', vid, lines);
});

</script>
</body>
</html>
