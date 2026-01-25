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
$rfqs = [];
try {
  $rfqs = $pdo->query("
    SELECT r.id, r.rfq_no, r.title, r.due_at, r.currency, r.status,
           (SELECT COUNT(*) FROM quotes q WHERE q.rfq_id=r.id) AS quotes_count
    FROM rfqs r
    ORDER BY r.id DESC
    LIMIT 200
  ")->fetchAll(PDO::FETCH_ASSOC);
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
    .table thead th{font-size:.82rem;text-transform:uppercase}
    .eval-table input{max-width:110px}
    .sticky-actions{position:sticky;bottom:0;background:#fff;border-top:1px solid var(--bs-border-color);padding:1rem;border-radius:0 0 1rem 1rem}
    .badge-pill{border-radius:999px}
    .winner-badge{font-size:.7rem}
    .dimmed{opacity:.6}
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
          <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2" aria-label="Toggle sidebar">
            <ion-icon name="menu-outline"></ion-icon>
          </button>
          <h2 class="m-0 d-flex align-items-center gap-2 page-title">
            <ion-icon name="pricetags-outline"></ion-icon> Quote Evaluation &amp; Award
          </h2>
        </div>
        <div class="profile-menu" data-profile-menu>
          <button class="profile-trigger" type="button" data-profile-trigger aria-expanded="false" aria-haspopup="true">
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

      <div class="row g-3">
        <!-- RFQ picker -->
        <div class="col-lg-4">
          <section class="card shadow-sm h-100">
            <div class="card-body">
              <h6 class="fw-semibold mb-3">Select a Quotation Request</h6>
              <input class="form-control form-control-sm mb-2" id="rfqSearch" placeholder="Search quotation no / title…">
              <div class="list-group" id="rfqList" style="max-height: 60vh; overflow:auto">
                <?php if (!$rfqs): ?>
                  <div class="text-muted small">No quotation requests yet.</div>
                <?php else: foreach ($rfqs as $r): ?>
                  <a href="?rfq_id=<?= (int)$r['id'] ?>"
                     class="list-group-item list-group-item-action d-flex justify-content-between align-items-center<?= $rfq_id===(int)$r['id']?' active':'' ?>">
                    <div>
                      <div class="fw-semibold"><?= h($r['rfq_no']) ?></div>
                      <div class="small text-muted text-truncate" style="max-width: 18rem"><?= h($r['title']) ?></div>
                    </div>
                    <span class="badge bg-secondary rounded-pill"><?= (int)$r['quotes_count'] ?></span>
                  </a>
                <?php endforeach; endif; ?>
              </div>
            </div>
          </section>
        </div>

        <!-- Evaluation panel -->
        <div class="col-lg-8">
          <section class="card shadow-sm h-100">
            <div class="card-body">
              <div id="evalWrap">
                <?php if ($rfq_id<=0): ?>
                  <div class="text-muted">Pick an RFQ on the left to start evaluating quotes.</div>
                <?php else: ?>
                  <div class="text-muted">Loading…</div>
                <?php endif; ?>
              </div>
              <div class="alert alert-danger d-none mt-3" id="evalErr"></div>
            </div>

            <?php if ($rfq_id>0): ?>
            <div class="sticky-actions d-flex flex-wrap gap-2 justify-content-between">
              <div class="d-flex gap-2">
                <button class="btn btn-outline-secondary" id="btnSaveEval" disabled>
                  <ion-icon name="save-outline"></ion-icon> Save Scores/Notes
                </button>
              </div>
              <div class="d-flex gap-2">
                <button class="btn btn-warning" id="btnAwardLines">
                  <ion-icon name="git-branch-outline"></ion-icon> Award Selected Lines
                </button>
                <button class="btn btn-success" id="btnAwardOverall">
                  <ion-icon name="trophy-outline"></ion-icon> Award Overall
                </button>
              </div>
            </div>
            <?php endif; ?>
          </section>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- toasts -->
<div class="toast-container position-fixed top-0 end-0 p-3" id="toasts" style="z-index:1080"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="../js/profile-dropdown.js"></script>
<script>
const $ = (s,r=document)=>r.querySelector(s);
const $$ = (s,r=document)=>Array.from(r.querySelectorAll(s));
const esc = s => String(s??'').replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m]));

async function fetchJSON(u, opts){
  const res = await fetch(u, opts);
  const txt = await res.text();
  let j; try{ j = JSON.parse(txt); }catch{}
  if (!res.ok || (j && j.error)) throw new Error((j && j.error) || txt || res.statusText);
  return j || {};
}

const rfqId = Number(new URLSearchParams(location.search).get('rfq_id') || 0);

function toast(msg, variant='success', delay=2200){
  const wrap = $('#toasts');
  const el=document.createElement('div');
  el.className=`toast text-bg-${variant} border-0`;
  el.innerHTML=`<div class="d-flex"><div class="toast-body">${esc(msg)}</div>
    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
  wrap.appendChild(el); new bootstrap.Toast(el,{delay}).show();
  el.addEventListener('hidden.bs.toast',()=>el.remove());
}

/* ===== Load evaluation ===== */
async function loadEval(){
  const wrap = $('#evalWrap'), err = $('#evalErr');
  err.classList.add('d-none'); err.textContent='';
  if (!rfqId){ wrap.innerHTML='<div class="text-muted">Pick an RFQ on the left to start.</div>'; return; }

  wrap.innerHTML='<div class="text-muted">Loading…</div>';
  try{
    const j = await fetchJSON('./api/quote_eval_detail.php?rfq_id='+rfqId);
    const { rfq, items=[], quotes=[], matrix={} } = j;

    const statusBadge = (s)=>{
      const v=(s||'').toLowerCase();
      const map={open:'bg-info text-dark', sent:'bg-info text-dark', awarded:'bg-success', closed:'bg-secondary', cancelled:'bg-danger'};
      return `<span class="badge ${map[v]||'bg-secondary'} badge-pill">${esc(s||'')}</span>`;
    };

    const header = `
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div>
          <div class="fw-semibold">${esc(rfq.rfq_no)} — ${esc(rfq.title||'')}</div>
          <div class="small text-muted">
            Status: <strong>${esc(rfq.status||'open')}</strong>
            ${rfq.awarded_at ? ` • Awarded: ${new Date(rfq.awarded_at.replace(' ','T')).toLocaleString()}` : ''}
            • Due: ${rfq.due_at ? new Date(rfq.due_at.replace(' ','T')).toLocaleString() : '-'}
          </div>
        </div>
        <div class="text-end">
          ${statusBadge(rfq.status||'')}
          <div class="small mt-1">Currency: <strong>${esc(rfq.currency||'')}</strong></div>
        </div>
      </div>`;

    const itemsTbl = items.length ? `
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-3">
          <thead><tr><th>#</th><th>Item</th><th>Specs</th><th class="text-end">Qty</th><th>UOM</th></tr></thead>
          <tbody>${items.map(r=>`
            <tr><td>${r.line_no}</td><td>${esc(r.item)}</td><td class="text-muted">${esc(r.specs||'')}</td>
            <td class="text-end">${Number(r.qty).toLocaleString()}</td><td>${esc(r.uom||'')}</td></tr>`).join('')}
          </tbody>
        </table>
      </div>` : `<div class="text-muted">No items.</div>`;

    const quotesTbl = quotes.length ? `
      <div class="table-responsive">
        <table class="table table-sm align-middle eval-table">
          <thead>
            <tr>
              <th>Select</th>
              <th>Supplier</th>
              <th class="text-end">Total</th>
              <th>Terms</th>
              <th>Submitted</th>
            </tr>
          </thead>
          <tbody>
            ${quotes.map(q=>{
              const isWinner = (Number(rfq.awarded_vendor_id||0) === Number(q.vendor_id||0));
              return `
              <tr class="quote-row ${rfq.status==='awarded' && !isWinner ? 'dimmed':''}" data-quote-id="${q.id}" data-vendor-id="${q.vendor_id}">
                <td style="width:70px">
                  <input type="radio" name="win_vendor" value="${q.vendor_id}" ${isWinner?'checked':''} ${rfq.status==='awarded'?'disabled':''}>
                </td>
                <td class="fw-semibold">
                  ${esc(q.supplier_name)}
                  ${isWinner ? ' <span class="badge bg-success winner-badge">Awarded</span>' : ''}
                </td>
                <td class="text-end">${Number(q.total || 0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}</td>
                <td class="small">${esc(q.terms||'')}</td>
                <td class="small">${esc(q.created_at||'')}</td>
              </tr>`;}).join('')}
          </tbody>
        </table>
      </div>` : `<div class="text-muted">No quotes submitted yet.</div>`;

    const matrixTbl = (quotes.length && items.length) ? `
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead>
            <tr>
              <th style="width:60px">Line</th>
              <th>Item</th>
              ${quotes.map(q=>`<th class="text-end">${esc(q.supplier_name)}</th>`).join('')}
            </tr>
          </thead>
          <tbody>
            ${items.map(it=>`
              <tr>
                <td>
                  <div class="form-check">
                    <input class="form-check-input line-check" type="checkbox" value="${it.id}" ${rfq.status==='awarded'?'disabled':''}>
                    <label class="form-check-label">${it.line_no}</label>
                  </div>
                </td>
                <td class="text-truncate" title="${esc(it.item)}">${esc(it.item)}</td>
                ${quotes.map(q=>{
                  const price = (matrix[q.id] && matrix[q.id][it.line_no] != null) ? Number(matrix[q.id][it.line_no]) : null;
                  return `<td class="text-end">${price!=null ? price.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:4}) : '—'}</td>`;
                }).join('')}
              </tr>`).join('')}
          </tbody>
        </table>
      </div>` : '';

    $('#evalWrap').innerHTML = header + `<hr class="my-3">` + itemsTbl + `<hr class="my-3">` + quotesTbl +
      (matrixTbl ? (`<hr class="my-3"><h6 class="fw-semibold">Per-Line Unit Prices</h6>` + matrixTbl) : '');

    // Disable award buttons if already awarded
    const awarded = (String(rfq.status||'').toLowerCase()==='awarded');
    $('#btnAwardOverall')?.toggleAttribute('disabled', awarded);
    $('#btnAwardLines')?.toggleAttribute('disabled', awarded);
    if (awarded) {
      $('#btnAwardOverall').classList.add('btn-outline-success');
      $('#btnAwardLines').classList.add('btn-outline-warning');
    }
  } catch (e) {
    console.error(e);
    $('#evalErr').textContent = e.message || 'Failed to load';
    $('#evalErr').classList.remove('d-none');
    $('#evalWrap').innerHTML = `<div class="text-danger">Error loading RFQ: ${esc(e.message||'')}</div>`;
  }
}

document.addEventListener('DOMContentLoaded', loadEval);

/* ===== helpers & award calls ===== */
function getSelectedVendorId(){
  const r = document.querySelector('input[name="win_vendor"]:checked');
  return r ? Number(r.value) : 0;
}
function getSelectedLineIds(){
  return Array.from(document.querySelectorAll('.line-check:checked')).map(x => Number(x.value));
}

async function award(mode, vendorId, lines=[]){
  if (!rfqId) return;
  const fd = new FormData();
  fd.append('rfq_id', rfqId);
  fd.append('vendor_id', vendorId);
  fd.append('mode', mode);
  if (mode === 'lines') lines.forEach(id => fd.append('lines[]', id));
  try{
    const j = await fetchJSON('./api/award_quote.php', { method:'POST', body: fd });
    toast(j.message || 'Award saved', 'success');
    await loadEval();
  }catch(e){
    toast(e.message || 'Failed to award', 'danger');
  }
}

$('#btnAwardOverall')?.addEventListener('click', ()=>{
  const vendorId = getSelectedVendorId();
  if (!vendorId) { toast('Please select a supplier to award.', 'warning'); return; }
  award('overall', vendorId);
});
$('#btnAwardLines')?.addEventListener('click', ()=>{
  const vendorId = getSelectedVendorId();
  if (!vendorId) { toast('Select a supplier (radio) first.', 'warning'); return; }
  const lines = getSelectedLineIds();
  if (!lines.length) { toast('Select at least one line to award.', 'warning'); return; }
  award('lines', vendorId, lines);
});

/* quick client-side filter */
$('#rfqSearch')?.addEventListener('input', e=>{
  const q = e.target.value.toLowerCase();
  $$('#rfqList a').forEach(a=>{
    const t = a.innerText.toLowerCase();
    a.style.display = t.includes(q) ? '' : 'none';
  });
});
</script>
</body>
</html>
