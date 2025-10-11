<?php
// File: procurement/rfqManagement.php
require_once __DIR__ . "/../includes/config.php";
require_once __DIR__ . "/../includes/auth.php";
require_once __DIR__ . "/../includes/db.php";

require_login();
require_role(['admin','procurement_officer','vendor_manager']);

$pdo = db('proc');
if (!$pdo instanceof PDO) { http_response_code(500); die("DB error"); }
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$user      = current_user();
$userId    = (int)($user['id'] ?? 0);
$userName  = $user['name'] ?? 'Guest';
$userRole  = $user['role'] ?? 'Unknown';
$section   = 'procurement';
$active    = 'po_rfq';

/* ---------- AJAX: RFQ detail for modal ---------- */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'rfq_detail') {
  header('Content-Type: application/json; charset=utf-8');
  try {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) throw new Exception("Invalid RFQ id");

    $st = $pdo->prepare("SELECT * FROM rfqs WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $rfq = $st->fetch(PDO::FETCH_ASSOC);
    if (!$rfq) throw new Exception("RFQ not found");

    $it = $pdo->prepare("SELECT * FROM rfq_items WHERE rfq_id=? ORDER BY line_no ASC, id ASC");
    $it->execute([$id]);
    $items = $it->fetchAll(PDO::FETCH_ASSOC);

    $sp = $pdo->prepare("
      SELECT rs.vendor_id, rs.status,
             v.company_name, v.contact_person, v.email, v.phone
      FROM rfq_suppliers rs
      JOIN vendors v ON v.id = rs.vendor_id
      WHERE rs.rfq_id = ?
      ORDER BY v.company_name
    ");
    $sp->execute([$id]);
    $suppliers = $sp->fetchAll(PDO::FETCH_ASSOC);

    $quotes = [];
    try {
      $qs = $pdo->prepare("
        SELECT q.id, q.vendor_id, q.total, q.currency, q.terms, q.created_at,
               v.company_name AS supplier_name
        FROM quotes q
        JOIN vendors v ON v.id = q.vendor_id
        WHERE q.rfq_id = ?
        ORDER BY q.created_at DESC
      ");
      $qs->execute([$id]);
      $quotes = $qs->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    echo json_encode(['rfq'=>$rfq,'items'=>$items,'suppliers'=>$suppliers,'quotes'=>$quotes]);
  } catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
  }
  exit;
}

/* ---------- AJAX: Create RFQ (modal submit) ---------- */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'create_rfq' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json; charset=utf-8');
  try {
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $currency    = strtoupper(trim($_POST['currency'] ?? 'PHP'));
    $due_raw     = trim($_POST['due_at'] ?? '');

    $due_at = null;
    if ($due_raw !== '') {
      $due_raw = str_replace('T', ' ', $due_raw);
      $t = strtotime($due_raw);
      if ($t !== false) $due_at = date('Y-m-d H:i:s', $t);
    }

    $item_names   = $_POST['item']  ?? [];
    $item_specs   = $_POST['specs'] ?? [];
    $item_qty     = $_POST['qty']   ?? [];
    $item_uom     = $_POST['uom']   ?? [];
    $supplier_ids = array_map('intval', $_POST['supplier_ids'] ?? []);

    $clean_items = [];
    $count = max(count($item_names), count($item_specs), count($item_qty), count($item_uom));
    for ($i=0; $i<$count; $i++) {
      $nm = trim($item_names[$i] ?? '');
      if ($nm === '') continue;
      $sp = trim($item_specs[$i] ?? '');
      $qt = (float)($item_qty[$i] ?? 0);
      if ($qt <= 0) $qt = 1;
      $um = trim($item_uom[$i] ?? 'unit');
      $clean_items[] = ['item'=>$nm,'specs'=>$sp,'qty'=>$qt,'uom'=>$um];
    }

    if ($title === '') throw new Exception("Title is required.");
    if (!$due_at)      throw new Exception("A valid Due Date/Time is required.");
    if (empty($clean_items))  throw new Exception("Add at least one RFQ item.");
    if (empty($supplier_ids)) throw new Exception("Select at least one supplier to invite.");

    $pdo->beginTransaction();

    // RFQ number
    $prefix = 'RFQ-' . date('Ymd') . '-';
    $st = $pdo->prepare("SELECT rfq_no FROM rfqs WHERE rfq_no LIKE ? ORDER BY rfq_no DESC LIMIT 1");
    $st->execute([$prefix.'%']);
    $last = $st->fetchColumn();
    $seq  = 1;
    if ($last) { $n = (int)substr($last, -4); $seq = $n + 1; }
    $rfq_no = $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

    // Insert RFQ header
    $ins = $pdo->prepare("
      INSERT INTO rfqs (rfq_no,title,description,due_at,currency,status,created_by)
      VALUES (?,?,?,?,?,'sent',?)
    ");
    $ins->execute([$rfq_no,$title,$description,$due_at,$currency,$userId]);
    $rfq_id = (int)$pdo->lastInsertId();

    // Items
    $line = 1;
    $ii = $pdo->prepare("INSERT INTO rfq_items (rfq_id,line_no,item,specs,qty,uom) VALUES (?,?,?,?,?,?)");
    foreach ($clean_items as $row) {
      $ii->execute([$rfq_id,$line++,$row['item'],$row['specs'],$row['qty'],$row['uom']]);
    }

    // Invite suppliers
    $ri = $pdo->prepare("INSERT INTO rfq_suppliers (rfq_id,vendor_id,status) VALUES (?,?, 'invited')");
    foreach ($supplier_ids as $vid) {
      $ri->execute([$rfq_id, $vid]);
    }

    // ===== Notifications
    $ni = $pdo->prepare("
      INSERT INTO vendor_notifications (vendor_id, title, body, rfq_id, type, is_read, created_at)
      VALUES (?, ?, ?, ?, 'rfq_invite', 0, NOW())
    ");
    $notifTitle = "New RFQ: {$rfq_no}";
    $dueTxt     = $due_at ? date('M d, Y g:i A', strtotime($due_at)) : '';
    $notifBody  = trim("You have been invited to quote on \"{$title}\"." . ($dueTxt ? " Due: {$dueTxt}." : ''));

    foreach ($supplier_ids as $vid) {
      $ni->execute([$vid, $notifTitle, $notifBody, $rfq_id]);
    }
    // ===== End Notifications

    $pdo->commit();
    echo json_encode(['ok'=>1,'id'=>$rfq_id,'rfq_no'=>$rfq_no,'message'=>'RFQ created']);
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['error'=>$e->getMessage()]);
  }
  exit;
}

/* ---------- Load vendors ---------- */
$vendors = [];
try {
  $q = $pdo->query("SELECT id, company_name, contact_person, email FROM vendors WHERE status='approved' ORDER BY company_name ASC");
  $vendors = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

/* ---------- Load RFQs ---------- */
$rfqs = [];
try {
  $sql = "
    SELECT
      r.id, r.rfq_no, r.title, r.due_at, r.currency, r.status,
      (SELECT COUNT(*) FROM rfq_suppliers rs WHERE rs.rfq_id = r.id) AS invited_count,
      (SELECT COUNT(*) FROM quotes q WHERE q.rfq_id = r.id) AS quoted_count
    FROM rfqs r
    ORDER BY r.id DESC
    LIMIT 200
  ";
  $rfqs = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function badge($status){
  $s = strtolower((string)$status);
  $map = [
    'pending'=>'bg-warning text-dark','sent'=>'bg-info text-dark','open'=>'bg-info text-dark',
    'closed'=>'bg-secondary','cancelled'=>'bg-dark','awarded'=>'bg-success',
    'draft'=>'bg-secondary','declined'=>'bg-danger',
  ];
  $cls = $map[$s] ?? 'bg-primary';
  return '<span class="badge rounded-pill '.$cls.'">'.ucfirst(h($status)).'</span>';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>RFQ Management | TNVS</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="../css/style.css" rel="stylesheet" />
  <link href="../css/modules.css" rel="stylesheet" />
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <script src="../js/sidebar-toggle.js"></script>

  <style>
    .main-content{padding:1.25rem} @media(min-width:992px){.main-content{padding:2rem}}
    .card{border-radius:16px}
    .btn-violet{--bs-btn-color:#fff;--bs-btn-bg:#6f42c1;--bs-btn-border-color:#6f42c1;--bs-btn-hover-color:#fff;--bs-btn-hover-bg:#5b37b1;--bs-btn-hover-border-color:#5532a6}
    .table thead th{font-weight:600;font-size:.85rem;text-transform:uppercase;color:var(--bs-secondary-color)}
    .metric{border:1px solid var(--bs-border-color);border-radius:14px;padding:1rem 1.25rem;background:#fff}
    .metric .label{font-size:.8rem;color:var(--bs-secondary-color);text-transform:uppercase}
    .metric .value{font-weight:700;font-size:1.25rem}

    /* ====== ROCK-SOLID SCROLLABLE CREATE MODAL ====== */
    :root{
      /* viewport share for the modal content; tweak if you like */
      --rfq-modal-vh: 92vh;
      --rfq-modal-header: 64px; /* approx header height incl. padding */
      --rfq-modal-footer: 64px; /* approx footer height incl. padding */
    }
    @media (max-width: 576px){
      :root{ --rfq-modal-vh: 96vh; }
    }
    #mdlCreateRFQ .modal-dialog{ max-width: 920px; }
    #mdlCreateRFQ .modal-content{
      height: var(--rfq-modal-vh);
      display:flex; flex-direction:column;
    }
    #mdlCreateRFQ .modal-body{
      height: calc(var(--rfq-modal-vh) - var(--rfq-modal-header) - var(--rfq-modal-footer));
      overflow-y: auto; /* THE important bit */
      overscroll-behavior: contain;
    }

    /* Keep Detail modal scrollable as well */
    .modal-dialog.modal-dialog-scrollable{ height: calc(100vh - 2rem); }
    .modal-dialog-scrollable .modal-content{ max-height: 100%; display:flex; flex-direction:column; }
    .modal-dialog-scrollable .modal-body{ overflow-y:auto; }

    /* Inputs inside item rows */
    .item-row .form-control{min-width:110px}
    .vendor-list{max-height:260px;overflow:auto;border:1px solid var(--bs-border-color);border-radius:.5rem;padding:.5rem .75rem}
  </style>
</head>
<body>
<div class="container-fluid p-0">
  <div class="row g-0">

    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="col main-content">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <div class="d-flex align-items-center gap-3">
          <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2" aria-label="Toggle sidebar">
            <ion-icon name="menu-outline"></ion-icon>
          </button>
          <h2 class="m-0 d-flex align-items-center gap-2"><ion-icon name="document-text-outline"></ion-icon> RFQ Management</h2>
        </div>
        <div class="d-flex align-items-center gap-2">
          <img src="../img/profile.jpg" class="rounded-circle" width="36" height="36" alt="">
          <div class="small">
            <strong><?= h($userName) ?></strong><br/>
            <span class="text-muted"><?= h($userRole) ?></span>
          </div>
        </div>
      </div>

      <!-- Top metrics -->
      <div class="row g-3 mb-3">
        <div class="col-6 col-md-3"><div class="metric"><div class="label">Total RFQs</div><div class="value"><?= count($rfqs) ?></div></div></div>
        <div class="col-6 col-md-3"><div class="metric"><div class="label">Open / Sent</div><div class="value"><?= array_sum(array_map(fn($r)=>strtolower($r['status'])==='sent'?1:0,$rfqs)) ?></div></div></div>
        <div class="col-6 col-md-3"><div class="metric"><div class="label">Awarded</div><div class="value"><?= array_sum(array_map(fn($r)=>strtolower($r['status'])==='awarded'?1:0,$rfqs)) ?></div></div></div>
        <div class="col-6 col-md-3 text-md-end d-grid d-md-block">
          <button class="btn btn-violet mt-3 mt-md-0" data-bs-toggle="modal" data-bs-target="#mdlCreateRFQ">
            <ion-icon name="add-circle-outline"></ion-icon> New RFQ
          </button>
        </div>
      </div>

      <!-- RFQ list -->
      <section class="card shadow-sm">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="card-title mb-0">Requests for Quotation</h5>
            <input id="tblSearch" class="form-control form-control-sm" style="max-width:300px" placeholder="Search by RFQ No or Title…">
          </div>

          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="rfqTable">
              <thead class="table-light">
                <tr>
                  <th>RFQ No</th><th>Title</th><th>Due Date</th>
                  <th class="text-center">Invited</th><th class="text-center">Quoted</th>
                  <th>Status</th><th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$rfqs): ?>
                  <tr><td colspan="7" class="text-center py-5 text-muted">No RFQs created yet.</td></tr>
                <?php else: foreach ($rfqs as $r): ?>
                  <tr>
                    <td class="fw-semibold text-primary"><?= h($r['rfq_no']) ?></td>
                    <td><?= h($r['title']) ?></td>
                    <td><?= $r['due_at'] ? date('M d, Y, g:i A', strtotime($r['due_at'])) : '-' ?></td>
                    <td class="text-center"><?= (int)$r['invited_count'] ?></td>
                    <td class="text-center"><?= (int)$r['quoted_count'] ?></td>
                    <td><?= badge($r['status']) ?></td>
                    <td class="text-end">
                      <button class="btn btn-sm btn-outline-secondary" onclick="openRFQModal(<?= (int)$r['id'] ?>)">
                        <ion-icon name="eye-outline"></ion-icon> View
                      </button>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </section>
    </div>
  </div>
</div>

<!-- ====== Create RFQ (Guaranteed Scrollable) ====== -->
<div class="modal fade" id="mdlCreateRFQ" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form id="createRfqForm">
        <div class="modal-header">
          <h5 class="modal-title d-flex align-items-center gap-2">
            <ion-icon name="add-circle-outline"></ion-icon> Create RFQ
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body">
          <div id="createErr" class="alert alert-danger d-none"></div>

          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label">Title <span class="text-danger">*</span></label>
              <input type="text" name="title" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Due Date &amp; Time <span class="text-danger">*</span></label>
              <input type="datetime-local" name="due_at" class="form-control" required>
            </div>
            <div class="col-12">
              <label class="form-label">Description</label>
              <textarea name="description" class="form-control" rows="2" placeholder="What are you sourcing? (optional)"></textarea>
            </div>
            <div class="col-md-3">
              <label class="form-label">Currency</label>
              <select name="currency" class="form-select">
                <option value="PHP">PHP</option><option value="USD">USD</option><option value="EUR">EUR</option><option value="JPY">JPY</option>
              </select>
            </div>
          </div>

          <hr class="my-4">

          <h6 class="fw-semibold mb-2">RFQ Items <span class="text-danger">*</span></h6>
          <div id="itemsWrap" class="vstack gap-2"></div>
          <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="btnAddItem">
            <ion-icon name="add-outline"></ion-icon> Add Item
          </button>

          <hr class="my-4">

          <h6 class="fw-semibold mb-2">Invite Suppliers <span class="text-danger">*</span></h6>
          <?php if (empty($vendors)): ?>
            <div class="alert alert-warning mb-0">No approved vendors yet. Approve at least one supplier first.</div>
          <?php else: ?>
            <input type="search" id="vendorSearch" class="form-control mb-2" placeholder="Filter suppliers by name/email...">
            <div class="vendor-list" id="vendorList">
              <?php foreach ($vendors as $v): ?>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="supplier_ids[]" value="<?= (int)$v['id'] ?>" id="v<?= (int)$v['id'] ?>">
                  <label class="form-check-label" for="v<?= (int)$v['id'] ?>">
                    <strong><?= h($v['company_name']) ?></strong>
                    <?php if (!empty($v['contact_person'])): ?>
                      <span class="text-muted">— <?= h($v['contact_person']) ?></span>
                    <?php endif; ?>
                    <div class="small text-muted"><?= h($v['email']) ?></div>
                  </label>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="modal-footer">
          <button class="btn btn-outline-secondary" data-bs-dismiss="modal" type="button">Cancel</button>
          <button class="btn btn-violet" type="submit">
            <ion-icon name="send-outline"></ion-icon> Create &amp; Send RFQ
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ====== RFQ Detail (Scrollable) ====== -->
<div class="modal fade" id="mdlRFQDetail" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title d-flex align-items-center gap-2">
          <ion-icon name="document-text-outline"></ion-icon> <span id="rfqModalTitle">RFQ Details</span>
        </h5>
        <span id="rfqModalStatus" class="ms-auto me-3"></span>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="rfqModalBody">
        <div class="text-center text-muted py-5">
          <div class="spinner-border text-primary"></div>
          <div class="mt-2">Loading...</div>
        </div>
      </div>
      <div class="modal-footer"><button class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<!-- Toasts -->
<div class="toast-container position-fixed top-0 end-0 p-3" id="toasts" style="z-index:1080"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  const $ = (s, r=document)=>r.querySelector(s);
  const $$ = (s, r=document)=>Array.from(r.querySelectorAll(s));
  const esc = s => String(s??'').replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m]));
  const badgeHTML = s => {
    const v=(s||'').toLowerCase();
    const map={pending:'bg-warning text-dark',sent:'bg-info text-dark',open:'bg-info text-dark',closed:'bg-secondary',cancelled:'bg-dark',awarded:'bg-success',draft:'bg-secondary',declined:'bg-danger'};
    return `<span class="badge rounded-pill ${map[v]||'bg-primary'}">${esc(s||'')}</span>`;
  };
  function toast(msg, variant='success', delay=2200){
    const wrap = $('#toasts');
    const el = document.createElement('div');
    el.className = `toast align-items-center text-bg-${variant} border-0`;
    el.innerHTML = `<div class="d-flex"><div class="toast-body">${msg}</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
    wrap.appendChild(el); new bootstrap.Toast(el,{delay}).show();
    el.addEventListener('hidden.bs.toast', ()=> el.remove());
  }

  /* —— client-side table search —— */
  $('#tblSearch')?.addEventListener('input', e=>{
    const q = e.target.value.toLowerCase();
    $$('#rfqTable tbody tr').forEach(tr=>{
      const text = (tr.cells[0]?.innerText || '') + ' ' + (tr.cells[1]?.innerText || '');
      tr.style.display = text.toLowerCase().includes(q) ? '' : 'none';
    });
  });

  /* —— Create RFQ modal: dynamic items —— */
  const itemsWrap = $('#itemsWrap');
  const btnAdd = $('#btnAddItem');

  function addItemRow(data={item:'',specs:'',qty:'1',uom:'unit'}){
    const row = document.createElement('div');
    row.className = 'row g-2 align-items-end item-row';
    row.innerHTML = `
      <div class="col-md-5">
        <label class="form-label small">Item</label>
        <input class="form-control" name="item[]" value="${esc(data.item)}" required>
      </div>
      <div class="col-md-5">
        <label class="form-label small">Specs / Description</label>
        <input class="form-control" name="specs[]" value="${esc(data.specs)}">
      </div>
      <div class="col-md-1">
        <label class="form-label small">Qty</label>
        <input class="form-control" name="qty[]" type="number" min="0" step="any" value="${esc(data.qty)}" required>
      </div>
      <div class="col-md-1">
        <label class="form-label small">UOM</label>
        <input class="form-control" name="uom[]" value="${esc(data.uom)}">
      </div>
      <div class="col-12">
        <button type="button" class="btn btn-link text-danger p-0 small" onclick="this.closest('.item-row').remove()">
          <ion-icon name="trash-outline"></ion-icon> remove
        </button>
      </div>`;
    itemsWrap?.appendChild(row);
  }
  btnAdd?.addEventListener('click', addItemRow);
  if (itemsWrap && !itemsWrap.children.length) addItemRow();

  /* —— Create RFQ modal: filter vendors —— */
  $('#vendorSearch')?.addEventListener('input', e=>{
    const q = e.target.value.toLowerCase();
    $$('#vendorList .form-check').forEach(div=>{
      div.style.display = div.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
  });

  /* —— Create RFQ submit (AJAX) —— */
  $('#createRfqForm')?.addEventListener('submit', async (ev)=>{
    ev.preventDefault();
    const errEl = $('#createErr');
    errEl?.classList.add('d-none'); errEl.textContent = '';

    const btn = ev.submitter; const prev = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span> Saving...`;

    try {
      const fd = new FormData(ev.target);
      if (!$$('input[name="item[]"]').length) addItemRow();

      const res = await fetch('?ajax=create_rfq', { method:'POST', body: fd });
      const j = await res.json();
      if (!res.ok || j.error) throw new Error(j.error || 'Create failed');

      bootstrap.Modal.getOrCreateInstance($('#mdlCreateRFQ')).hide();
      toast('RFQ created • ' + (j.rfq_no || ''), 'success');
      setTimeout(()=> location.reload(), 400);
    } catch (e) {
      errEl.textContent = e.message || 'Failed to create RFQ';
      errEl.classList.remove('d-none');
    } finally {
      btn.disabled = false; btn.innerHTML = prev;
    }
  });

  /* —— View RFQ modal —— */
  window.openRFQModal = async (id)=>{
    const modal = bootstrap.Modal.getOrCreateInstance($('#mdlRFQDetail'));
    const body = $('#rfqModalBody'), title = $('#rfqModalTitle'), status = $('#rfqModalStatus');
    title.textContent = 'RFQ Details'; status.innerHTML = '';
    body.innerHTML = `<div class="text-center text-muted py-5"><div class="spinner-border text-primary"></div><div class="mt-2">Loading...</div></div>`;
    modal.show();
    try{
      const res = await fetch(`?ajax=rfq_detail&id=${id}`);
      const j = await res.json();
      if (!res.ok || j.error) throw new Error(j.error || 'Load failed');

      const { rfq, items=[], suppliers=[], quotes=[] } = j;
      title.textContent = `RFQ ${esc(rfq.rfq_no || ('#'+id))}`;
      status.innerHTML = badgeHTML(rfq.status);

      const itemsHTML = items.length
        ? `<div class="table-responsive"><table class="table table-sm align-middle">
             <thead><tr><th>#</th><th>Item</th><th>Specs</th><th class="text-end">Qty</th><th>UOM</th></tr></thead>
             <tbody>${items.map(r=>`<tr><td>${r.line_no}</td><td>${esc(r.item)}</td><td class="text-muted">${esc(r.specs)}</td><td class="text-end">${Number(r.qty).toLocaleString()}</td><td>${esc(r.uom)}</td></tr>`).join('')}</tbody>
           </table></div>`
        : `<div class="text-muted">No items.</div>`;

      const supsHTML = suppliers.length
        ? `<div class="list-group list-group-flush">
             ${suppliers.map(s=>`
               <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                 <div>
                   <div class="fw-semibold">${esc(s.company_name)}</div>
                   <div class="small text-muted">${esc(s.contact_person||'')}${s.email?` • ${esc(s.email)}`:''}${s.phone?` • ${esc(s.phone)}`:''}</div>
                 </div>
                 <div>${badgeHTML(s.status)}</div>
               </div>`).join('')}
           </div>`
        : `<div class="text-muted">No invited suppliers.</div>`;

      const quotesHTML = quotes.length
        ? `<div class="table-responsive"><table class="table table-sm align-middle">
             <thead><tr><th>Supplier</th><th class="text-end">Total</th><th>Currency</th><th>Terms</th><th>Submitted</th></tr></thead>
             <tbody>${quotes.map(q=>`<tr>
               <td>${esc(q.supplier_name)}</td>
               <td class="text-end">${Number(q.total||0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}</td>
               <td>${esc(q.currency||rfq.currency||'')}</td>
               <td class="small">${esc(q.terms||'')}</td>
               <td class="small">${esc(q.created_at||'')}</td>
             </tr>`).join('')}</tbody>
           </table></div>`
        : `<div class="text-muted">No quotes submitted yet.</div>`;

      body.innerHTML = `
        <div class="row g-4">
          <div class="col-lg-7">
            <h6 class="fw-semibold">Details</h6>
            <dl class="row mb-0">
              <dt class="col-sm-3">Title</dt><dd class="col-sm-9">${esc(rfq.title||'')}</dd>
              <dt class="col-sm-3">Due</dt><dd class="col-sm-9">${rfq.due_at ? new Date(rfq.due_at.replace(' ', 'T')).toLocaleString() : '-'}</dd>
              <dt class="col-sm-3">Currency</dt><dd class="col-sm-9">${esc(rfq.currency||'')}</dd>
              ${rfq.description ? `<dt class="col-sm-3">Description</dt><dd class="col-sm-9">${esc(rfq.description)}</dd>` : ``}
            </dl>
            <hr class="my-4">
            <h6 class="fw-semibold">Items</h6>
            ${itemsHTML}
          </div>
          <div class="col-lg-5">
            <h6 class="fw-semibold">Invited Suppliers</h6>
            ${supsHTML}
            <hr class="my-4">
            <h6 class="fw-semibold">Quotes</h6>
            ${quotesHTML}
          </div>
        </div>`;
    }catch(e){
      body.innerHTML = `<div class="alert alert-danger">Error: ${esc(e.message||'Failed to load')}</div>`;
    }
  };
})();
</script>
</body>
</html>
