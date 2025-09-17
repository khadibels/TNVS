<?php
declare(strict_types=1);

$inc = __DIR__ . "/../includes";
require_once $inc . "/config.php";
require_once $inc . "/auth.php";
require_once $inc . "/db.php";

require_login();
require_role(['admin','procurement_officer']);

$section = "procurement";
$active = "dashboard";

/* ---------- DB ---------- */
$pdoProc = db('proc') ?: db('wms');
if (!$pdoProc instanceof PDO) {
  http_response_code(500);
  if (defined('APP_DEBUG') && APP_DEBUG) {
    die('DB connection for "proc" (or fallback) is not available. Check includes/config.php credentials.');
  }
  die('Internal error');
}
$pdoWms = db('wms');

/* ---------- helpers ---------- */
function table_exists(PDO $pdo=null, string $table=''): bool {
  if (!$pdo) return false;
  try {
    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
  } catch(Throwable $e) { return false; }
}
function scalar(PDO $pdo, string $sql, array $bind=[]): int {
  try {
    $st = $pdo->prepare($sql);
    $st->execute($bind);
    return (int)$st->fetchColumn();
  } catch(Throwable $e) { return 0; }
}

/* ---------- current user ---------- */
$user = function_exists('current_user') ? current_user() : [];
$userName = $user['name'] ?? 'Procurement User';
$userRole = $user['role'] ?? 'Procurement';

/* ---------- KPIs (safe if tables missing) ---------- */
$hasPR   = table_exists($pdoProc, 'procurement_requests');
$hasPO   = table_exists($pdoProc, 'purchase_orders');
$hasRFQ  = table_exists($pdoProc, 'rfqs');
$hasSupp = table_exists($pdoProc, 'suppliers');
$hasBudg = table_exists($pdoProc, 'budgets');

$pendingPRs   = $hasPR   ? scalar($pdoProc, "SELECT COUNT(*) FROM procurement_requests WHERE status='submitted'") : 0;
$totalPRs     = $hasPR   ? scalar($pdoProc, "SELECT COUNT(*) FROM procurement_requests") : 0;

$openPOs      = $hasPO   ? scalar($pdoProc, "SELECT COUNT(*) FROM purchase_orders WHERE status IN ('draft','approved','ordered')") : 0;
$totalPOs     = $hasPO   ? scalar($pdoProc, "SELECT COUNT(*) FROM purchase_orders") : 0;

$openRFQs     = $hasRFQ  ? scalar($pdoProc, "SELECT COUNT(*) FROM rfqs WHERE status IN ('draft','sent')") : 0;
$totalRFQs    = $hasRFQ  ? scalar($pdoProc, "SELECT COUNT(*) FROM rfqs") : 0;

$totalSuppliers = $hasSupp ? scalar($pdoProc, "SELECT COUNT(*) FROM suppliers WHERE is_active=1") : 0;
$totalBudgets   = $hasBudg ? scalar($pdoProc, "SELECT COUNT(*) FROM budgets") : 0;

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Dashboard | Procurement</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="../css/style.css" rel="stylesheet" />
  <link href="../css/modules.css" rel="stylesheet" />
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <script src="../js/sidebar-toggle.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
  <style>
    .kpi-card .icon-wrap{width:42px;height:42px;display:flex;align-items:center;justify-content:center;border-radius:12px}
    .kpi-value{font-size:1.6rem;font-weight:700}
    .table-sm td, .table-sm th { vertical-align: middle; }
    .chart-card canvas{width:100%!important;height:300px!important}
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
          <h2 class="m-0">Procurement Dashboard</h2>
        </div>
        <div class="d-flex align-items-center gap-2">
          <img src="../img/profile.jpg" class="rounded-circle" width="36" height="36" alt="">
          <div class="small">
            <strong><?= htmlspecialchars($userName) ?></strong><br/>
            <span class="text-muted"><?= htmlspecialchars($userRole) ?></span>
          </div>
        </div>
      </div>

      <!-- KPI row -->
      <div class="row g-3">
        <div class="col-12 col-md-6 col-xl-2">
          <div class="card shadow-sm kpi-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
              <div class="icon-wrap bg-info-subtle"><ion-icon name="clipboard-outline"></ion-icon></div>
              <div>
                <div class="text-muted small">Pending PRs</div>
                <div class="kpi-value"><?= number_format($pendingPRs) ?></div>
                <div class="small text-muted">of <?= number_format($totalPRs) ?></div>
              </div>
            </div>
            <a class="stretched-link" href="./procurementRequests.php"></a>
          </div>
        </div>
        <div class="col-12 col-md-6 col-xl-2">
          <div class="card shadow-sm kpi-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
              <div class="icon-wrap bg-primary-subtle"><ion-icon name="document-text-outline"></ion-icon></div>
              <div>
                <div class="text-muted small">Open POs</div>
                <div class="kpi-value"><?= number_format($openPOs) ?></div>
                <div class="small text-muted">of <?= number_format($totalPOs) ?></div>
              </div>
            </div>
            <a class="stretched-link" href="./purchaseOrders.php"></a>
          </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
          <div class="card shadow-sm kpi-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
              <div class="icon-wrap bg-warning-subtle"><ion-icon name="mail-open-outline"></ion-icon></div>
              <div>
                <div class="text-muted small">Open RFQs</div>
                <div class="kpi-value"><?= number_format($openRFQs) ?></div>
                <div class="small text-muted">of <?= number_format($totalRFQs) ?></div>
              </div>
            </div>
            <a class="stretched-link" href="./rfqManagement.php"></a>
          </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
          <div class="card shadow-sm kpi-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
              <div class="icon-wrap bg-success-subtle"><ion-icon name="business-outline"></ion-icon></div>
              <div>
                <div class="text-muted small">Active Suppliers</div>
                <div class="kpi-value"><?= number_format($totalSuppliers) ?></div>
                <div class="small text-muted">&nbsp;</div>
              </div>
            </div>
            <a class="stretched-link" href="./supplierManagement.php"></a>
          </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
          <div class="card shadow-sm kpi-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
              <div class="icon-wrap bg-secondary-subtle"><ion-icon name="wallet-outline"></ion-icon></div>
              <div>
                <div class="text-muted small">Budget Lines</div>
                <div class="kpi-value"><?= number_format($totalBudgets) ?></div>
                <div class="small text-muted">&nbsp;</div>
              </div>
            </div>
            <a class="stretched-link" href="./budgetReports.php#tabBudgets"></a>
          </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
          <div class="card shadow-sm kpi-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
              <div class="icon-wrap bg-dark-subtle"><ion-icon name="analytics-outline"></ion-icon></div>
              <div>
                <div class="text-muted small">Spend (This FY)</div>
                <div class="kpi-value" id="kSpendFY">0.00</div>
                <div class="small text-muted" id="kSpendFYLbl">&nbsp;</div>
              </div>
            </div>
            <a class="stretched-link" href="./budgetReports.php#tabReports"></a>
          </div>
        </div>
      </div>

      <!-- Charts -->
      <div class="row g-3 mt-1">
        <div class="col-12 col-lg-7">
          <div class="card shadow-sm chart-card h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="m-0 d-flex align-items-center gap-2"><ion-icon name="trending-up-outline"></ion-icon> Spend (Last 12 Months)</h6>
                <a href="./budgetReports.php#tabReports" class="btn btn-sm btn-outline-secondary">View Reports</a>
              </div>
              <canvas id="chSpend12"></canvas>
            </div>
          </div>
        </div>
        <div class="col-12 col-lg-5">
          <div class="card shadow-sm chart-card h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="m-0 d-flex align-items-center gap-2"><ion-icon name="pricetags-outline"></ion-icon> Spend by Category (YTD)</h6>
              </div>
              <canvas id="chCatYTD"></canvas>
            </div>
          </div>
        </div>
      </div>

      <!-- Recent activity -->
      <div class="row g-3 mt-1">
        <div class="col-12 col-xl-6">
          <div class="card shadow-sm h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="m-0"><ion-icon name="clipboard-outline"></ion-icon> Latest Procurement Requests</h6>
                <a href="./procurementRequests.php" class="btn btn-sm btn-outline-secondary">All</a>
              </div>
              <div class="table-responsive">
                <table class="table table-sm">
                  <thead><tr><th>PR No</th><th>Title</th><th>Status</th><th class="text-end">Est. Total</th></tr></thead>
                  <tbody id="tblPRs"><tr><td colspan="4" class="text-center py-3">Loading…</td></tr></tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <div class="col-12 col-xl-6">
          <div class="card shadow-sm h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="m-0"><ion-icon name="document-text-outline"></ion-icon> Latest Purchase Orders</h6>
                <a href="./purchaseOrders.php" class="btn btn-sm btn-outline-secondary">All</a>
              </div>
              <div class="table-responsive">
                <table class="table table-sm">
                  <thead><tr><th>PO No</th><th>Supplier</th><th>Status</th><th class="text-end">Total</th></tr></thead>
                  <tbody id="tblPOs"><tr><td colspan="4" class="text-center py-3">Loading…</td></tr></tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <div class="col-12 col-xl-6">
          <div class="card shadow-sm h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="m-0"><ion-icon name="mail-open-outline"></ion-icon> Latest RFQs</h6>
                <a href="./rfqManagement.php" class="btn btn-sm btn-outline-secondary">All</a>
              </div>
              <div class="table-responsive">
                <table class="table table-sm">
                  <thead><tr><th>RFQ No</th><th>Title</th><th>Status</th><th>Due</th></tr></thead>
                  <tbody id="tblRFQs"><tr><td colspan="4" class="text-center py-3">Loading…</td></tr></tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

      </div><!-- /row -->

    </div><!-- /main -->
  </div><!-- /row -->
</div><!-- /container -->

<!-- Toasts -->
<div class="toast-container position-fixed top-0 end-0 p-3" id="toasts" style="z-index:1080"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ===== Helpers ===== */
const $ = (s, r=document)=>r.querySelector(s);
async function fetchJSON(url, opts={}){ const res = await fetch(url, opts); if(!res.ok) throw new Error(await res.text()||res.statusText); return res.json(); }
function esc(s){return String(s??'').replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m]));}
function toast(msg, variant='success', delay=2200){
  let wrap=document.getElementById('toasts');
  if(!wrap){wrap=document.createElement('div');wrap.id='toasts';wrap.className='toast-container position-fixed top-0 end-0 p-3';wrap.style.zIndex=1080;document.body.appendChild(wrap);}
  const el=document.createElement('div'); el.className=`toast text-bg-${variant} border-0`; el.role='status';
  el.innerHTML=`<div class="d-flex"><div class="toast-body">${msg}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
  wrap.appendChild(el); const t=new bootstrap.Toast(el,{delay}); t.show(); el.addEventListener('hidden.bs.toast',()=>el.remove());
}
const fmtMoney=(v)=> Number(v ?? 0).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2});

/* ===== Charts ===== */
let chSpend12, chCatYTD;

async function loadSpend12(){
  // Use your existing reports API; when no filters are provided it should default to all-time.
  // We'll compute last 12 months client-side from by_month.
  try{
    const sum = await fetchJSON('./api/report_spend_summary.php'); // expects { by_month: [{period:'YYYY-MM', total:...}], by_category:... }
    const now = new Date();
    const labels=[], valuesMap=new Map();
    (sum.by_month||[]).forEach(r=>valuesMap.set(r.period, +r.total||0));

    // build last 12 month labels YYYY-MM
    for(let i=11;i>=0;i--){
      const d = new Date(now.getFullYear(), now.getMonth()-i, 1);
      const ym = d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0');
      labels.push(ym);
    }
    const data = labels.map(k=>valuesMap.get(k)||0);

    if(chSpend12) chSpend12.destroy();
    chSpend12 = new Chart($('#chSpend12'), {
      type:'line',
      data:{ labels, datasets:[{ label:'PO Spend', data, borderWidth:2, tension:0.25, fill:false }] },
      options:{ maintainAspectRatio:false, plugins:{ legend:{ display:false } }, scales:{ y:{ beginAtZero:true } } }
    });

    // Spend FY KPI (sum of this FY labels)
    const fyYear = (new Date()).getFullYear();
    const fyData = labels.filter(l=>l.startsWith(String(fyYear))).map(k=>valuesMap.get(k)||0);
    const fySpend = fyData.reduce((a,b)=>a+b,0);
    $('#kSpendFY').textContent = fmtMoney(fySpend);
    $('#kSpendFYLbl').textContent = 'FY ' + fyYear;
  }catch(e){
    $('#kSpendFY').textContent='0.00';
    $('#kSpendFYLbl').textContent='FY';
  }
}

async function loadCategoryYTD(){
  try{
    // Rough YTD: from Jan 1 to today
    const today=new Date();
    const from = today.getFullYear()+'-01-01';
    const to   = today.toISOString().slice(0,10);
    const qs = new URLSearchParams({ from, to });
    const sum = await fetchJSON('./api/report_spend_summary.php?'+qs.toString()); // expects by_category
    const cats = (sum.by_category||[]);
    const labels=cats.map(r=>r.category||'—');
    const data  = cats.map(r=>+r.total||0);

    if(chCatYTD) chCatYTD.destroy();
    chCatYTD = new Chart($('#chCatYTD'), {
      type:'doughnut',
      data:{ labels, datasets:[{ data, borderWidth:1 }] },
      options:{ maintainAspectRatio:false, plugins:{ legend:{ position:'bottom' } } }
    });
  }catch(e){}
}

/* ===== Recent tables ===== */
async function loadRecentPRs(){
  try{
    const qs = new URLSearchParams({ page:1, per_page:5, sort:'newest' });
    const { data } = await fetchJSON('./api/pr_list.php?'+qs.toString());
    const badge=(s)=>{
      const v=(s||'').toLowerCase();
      const map={draft:'secondary',submitted:'info',approved:'primary',rejected:'danger',fulfilled:'success',cancelled:'dark'};
      const cls = map[v] || 'secondary';
      return `<span class="badge bg-${cls}">${esc(s||'draft')}</span>`;
    };
    $('#tblPRs').innerHTML = data.length ? data.map(r=>`
      <tr>
        <td class="fw-semibold">${esc(r.pr_no)}</td>
        <td class="text-truncate" title="${esc(r.title||'')}">${esc(r.title||'-')}</td>
        <td>${badge(r.status)}</td>
        <td class="text-end">${fmtMoney(r.estimated_total)}</td>
      </tr>
    `).join('') : `<tr><td colspan="4" class="text-center py-3 text-muted">No PRs found.</td></tr>`;
  }catch(e){
    $('#tblPRs').innerHTML = `<tr><td colspan="4" class="text-danger text-center py-3">Failed to load</td></tr>`;
  }
}
async function loadRecentPOs(){
  try{
    const qs = new URLSearchParams({ page:1, per_page:5, sort:'newest' });
    const { data } = await fetchJSON('./api/pos_list.php?'+qs.toString());
    const badge=(s)=>{
      const v=(s||'').toLowerCase();
      const map={draft:'secondary',approved:'info',ordered:'primary',received:'success',closed:'dark',cancelled:'danger'};
      const cls = map[v] || 'secondary';
      return `<span class="badge bg-${cls}">${esc(s||'draft')}</span>`;
    };
    $('#tblPOs').innerHTML = data.length ? data.map(r=>`
      <tr>
        <td class="fw-semibold">${esc(r.po_no)}</td>
        <td class="text-truncate" title="${esc(r.supplier_name||'')}">${esc(r.supplier_name||'-')}</td>
        <td>${badge(r.status)}</td>
        <td class="text-end">${fmtMoney(r.total)}</td>
      </tr>
    `).join('') : `<tr><td colspan="4" class="text-center py-3 text-muted">No POs found.</td></tr>`;
  }catch(e){
    $('#tblPOs').innerHTML = `<tr><td colspan="4" class="text-danger text-center py-3">Failed to load</td></tr>`;
  }
}
async function loadRecentRFQs(){
  try{
    const qs = new URLSearchParams({ page:1, per_page:5, sort:'newest' });
    const { data } = await fetchJSON('./api/rfqs_list.php?'+qs.toString());
    const badge=(s)=>{
      const v=(s||'').toLowerCase();
      if(v==='draft') return '<span class="badge bg-secondary">Draft</span>';
      if(v==='sent') return '<span class="badge bg-info text-dark">Sent</span>';
      if(v==='awarded') return '<span class="badge bg-success">Awarded</span>';
      if(v==='closed') return '<span class="badge bg-dark">Closed</span>';
      if(v==='cancelled') return '<span class="badge bg-danger">Cancelled</span>';
      return esc(s||'');
    };
    $('#tblRFQs').innerHTML = data.length ? data.map(r=>`
      <tr>
        <td class="fw-semibold">${esc(r.rfq_no)}</td>
        <td class="text-truncate" title="${esc(r.title||'')}">${esc(r.title||'-')}</td>
        <td>${badge(r.status)}</td>
        <td>${esc(r.due_date||'-')}</td>
      </tr>
    `).join('') : `<tr><td colspan="4" class="text-center py-3 text-muted">No RFQs found.</td></tr>`;
  }catch(e){
    $('#tblRFQs').innerHTML = `<tr><td colspan="4" class="text-danger text-center py-3">Failed to load</td></tr>`;
  }
}

/* ===== Init ===== */
document.addEventListener('DOMContentLoaded', ()=>{
  loadSpend12().catch(()=>{});
  loadCategoryYTD().catch(()=>{});
  loadRecentPRs().catch(()=>{});
  loadRecentPOs().catch(()=>{});
  loadRecentRFQs().catch(()=>{});
});
</script>
</body>
</html>
