<?php
require_once __DIR__ . "/../../includes/config.php";
require_once __DIR__ . "/../../includes/auth.php";
require_once __DIR__ . "/../../includes/db.php";
require_login();


$wms  = db('wms');
$pdo  = $wms;

$section = 'warehousing';
$active = 'shipments';

/* ---- DB guards ---- */
function table_exists(PDO $pdo, string $name): bool
{
    $sql = "SELECT 1
              FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = ?";
    $st = $pdo->prepare($sql);
    $st->execute([$name]);
    return (bool)$st->fetchColumn();
}

$hasLoc = table_exists($pdo, "warehouse_locations");
$hasShip = table_exists($pdo, "shipments");
$dbReady = $hasLoc && $hasShip;

$locOptionsHtml = "";
if ($hasLoc) {
    $rows = $pdo
        ->query(
            'SELECT id, CONCAT_WS(" - ", code, name) AS name FROM warehouse_locations ORDER BY name'
        )
        ->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $l) {
        $id = (int) $l["id"];
        $nm = htmlspecialchars($l["name"], ENT_QUOTES);
        $locOptionsHtml .= "<option value=\"$id\">$nm</option>";
    }
}

/* ---- User (topbar) ---- */
$userName = $_SESSION["user"]["name"] ?? "Nicole Malitao";
$userRole = $_SESSION["user"]["role"] ?? "Warehouse Manager";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Track Shipments | TNVS</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="../../css/style.css" rel="stylesheet" />
  <link href="../../css/modules.css" rel="stylesheet" />

  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <script src="../../js/sidebar-toggle.js"></script>

 
</head>
<body>
  <div class="container-fluid p-0">
    <div class="row g-0">

<?php include __DIR__ . '/../../includes/sidebar.php' ?>

  

      <!-- Main Content -->
      <div class="col main-content p-3 p-lg-4">

        <!-- Topbar -->
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div class="d-flex align-items-center gap-3">
            <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2" aria-label="Toggle sidebar">
              <ion-icon name="menu-outline"></ion-icon>
            </button>
            <h2 class="m-0 d-flex align-items-center gap-2">
        <ion-icon name="paper-plane-outline"></ion-icon>Track Shipments
      </h2>
          </div>

          <div class="d-flex align-items-center gap-2">
            <img src="../../img/profile.jpg" class="rounded-circle" width="36" height="36" alt="">
            <div class="small">
              <strong><?= htmlspecialchars($userName) ?></strong><br/>
              <span class="text-muted"><?= htmlspecialchars($userRole) ?></span>
            </div>
          </div>
        </div>

        <!-- DB Not Ready -->
        <?php if (!$dbReady): ?>
          <div class="alert alert-warning">
            Database not initialized for <b>Track Shipments</b>.
            Please create the tables <b>warehouse_locations</b> and <b>shipments</b> (plus optional <b>shipment_events</b>, <b>shipment_items</b>).
          </div>
        <?php else: ?>

        <!-- Actions -->
        <section class="mb-3">
          <div class="d-flex gap-2">
            <button class="btn btn-violet" data-bs-toggle="modal" data-bs-target="#mdlAdd">
  <ion-icon name="add-circle-outline"></ion-icon> New Shipment
</button>

          </div>
        </section>

        <!-- Filters -->
        <section class="card shadow-sm mb-3">
          <div class="card-body">
            <form id="filterForm" class="row g-2 align-items-end">
              <div class="col-12 col-md-3">
                <label class="form-label">Search</label>
                <input type="text" class="form-control" id="fQ" placeholder="Ref # / carrier / destination">
              </div>
              <div class="col-12 col-md-2">
                <label class="form-label">Status</label>
                <select class="form-select" id="fStatus">
                  <option value="">All</option>
                  <option>Draft</option><option>Ready</option><option>Dispatched</option>
                  <option>In Transit</option><option>Delivered</option>
                  <option>Delayed</option><option>Cancelled</option><option>Returned</option>
                </select>
              </div>
              <div class="col-6 col-md-2">
                <label class="form-label">From (ETA)</label>
                <input type="date" class="form-control" id="fFrom">
              </div>
              <div class="col-6 col-md-2">
                <label class="form-label">To (ETA)</label>
                <input type="date" class="form-control" id="fTo">
              </div>
              <div class="col-12 col-md-2">
                <label class="form-label">Show</label>
                <select id="per" class="form-select">
                  <option>10</option><option selected>25</option><option>50</option><option>100</option>
                </select>
              </div>
              <div class="col-12 col-md-1 d-grid">
                <button type="submit" class="btn btn-outline-secondary">Filter</button>
              </div>
            </form>
          </div>
        </section>

        <!-- Shipments Table -->
        <section class="card shadow-sm">
          <div class="card-body">
            <h5 class="mb-3">Shipments</h5>

            <div class="table-responsive shipments-scroll">
              <table class="table align-middle">
                <thead class="sticky-th">
                  <tr>
                    <th>Ref #</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Status</th>
                    <th>ETA</th>
                    <th>Carrier</th>
                    <th class="text-end">Actions</th>
                  </tr>
                </thead>
                <tbody id="tblBody">
                  <tr><td colspan="7" class="text-center py-4 text-muted">Loading…</td></tr>
                </tbody>
              </table>
            </div>

            <div class="d-flex justify-content-between align-items-center mt-2">
  <div id="pagerText" class="small text-muted"></div>
  <nav aria-label="Shipments pages">
    <ul class="pagination pagination-sm mb-0" id="pager"></ul>
  </nav>
</div>

          </div>
        </section>

        <?php endif;
/* dbReady */
?>

      </div><!-- /main -->
    </div>
  </div>

  <!-- Add Shipment Modal -->
  <div class="modal fade" id="mdlAdd" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <form id="addForm">
          <div class="modal-header">
            <h5 class="modal-title">New Shipment</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body row g-3">
            <div class="col-md-6">
              <label class="form-label">Origin</label>
              <select class="form-select" name="origin_id" id="originSel" required>
  <?= $locOptionsHtml ?>
</select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Destination</label>
              <select class="form-select" name="destination_id" id="destSel" required>
  <?= $locOptionsHtml ?>
</select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Carrier</label>
              <input class="form-control" name="carrier" placeholder="e.g., LBC, J&T, In-house">
            </div>
            <div class="col-md-6">
              <label class="form-label">Contact Person</label>
              <input class="form-control" name="contact_name">
            </div>
            <div class="col-md-6">
              <label class="form-label">Contact Phone</label>
              <input class="form-control" name="contact_phone">
            </div>
            <div class="col-md-3">
              <label class="form-label">Pickup Date</label>
              <input type="date" class="form-control" name="expected_pickup" required>
            </div>
            <div class="col-md-3">
              <label class="form-label">ETA Delivery</label>
              <input type="date" class="form-control" name="expected_delivery" required>
            </div>
            <div class="col-12">
              <label class="form-label">Notes</label>
              <textarea class="form-control" name="notes" rows="2"></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button class="btn btn-primary" type="submit">Create</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- View / Update Modal -->
  <div class="modal fade" id="mdlView" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Shipment <span id="vRef"></span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div id="vMeta" class="mb-3 small text-muted"></div>
          <div class="d-flex gap-2 align-items-end mb-3">
            <div>
              <label class="form-label">Update status</label>
              <select id="vStatus" class="form-select">
                <option>Ready</option><option>Dispatched</option><option>In Transit</option>
                <option>Delivered</option><option>Delayed</option><option>Cancelled</option><option>Returned</option>
              </select>
            </div>
            <div class="flex-grow-1">
              <label class="form-label">Details (optional)</label>
              <input id="vDetails" class="form-control" placeholder="e.g., Arrived at Qc hub">
            </div>
            <button id="btnPostEvent" class="btn btn-primary">Post</button>
          </div>
          <h6 class="mb-2">Timeline</h6>
          <ul id="vTimeline" class="list-group small"></ul>
        </div>
        <div class="modal-footer">
          <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>

  <!-- JS -->
  <script>
    const state = { page:1, per:25, q:'', status:'', from:'', to:'' };

    // Reset filters + go back to page 1 so the newest shipment is visible
function resetFiltersToAll() {
  const q = document.getElementById('fQ');
  const st = document.getElementById('fStatus');
  const f  = document.getElementById('fFrom');
  const t  = document.getElementById('fTo');
  if (q) q.value = '';
  if (st) st.value = '';
  if (f) f.value = '';
  if (t) t.value = '';
  state.q = ''; state.status = ''; state.from = ''; state.to = '';
  state.page = 1;
}


    document.getElementById('filterForm')?.addEventListener('submit', (e)=>{
      e.preventDefault();
      state.q = document.getElementById('fQ').value.trim();
      state.status = document.getElementById('fStatus').value;
      state.from = document.getElementById('fFrom').value;
      state.to = document.getElementById('fTo').value;
      state.per = parseInt(document.getElementById('per').value, 10) || 25;
      state.page = 1;
      loadTable();
    });

    document.getElementById('prevBtn')?.addEventListener('click', ()=>{
      if(state.page>1){ state.page--; loadTable(); }
    });
    document.getElementById('nextBtn')?.addEventListener('click', ()=>{
      state.page++; loadTable();
    });

    async function loadLocations(){
      const res = await fetch('./api/locations.php', {credentials:'same-origin'});
      if(!res.ok){ alert('Failed to load locations'); return; }
      const data = await res.json();
     const opts = data.map(l=>`<option value="${l.id}">${l.name}</option>`).join('');

      const originSel = document.getElementById('originSel');
      const destSel = document.getElementById('destSel');
      if(originSel && destSel){ originSel.innerHTML = destSel.innerHTML = opts; }
    }

 async function loadTable(){
  const tbody = document.getElementById('tblBody');
  if (tbody) {
    tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-muted">Loading…</td></tr>';
  }

  const params = new URLSearchParams({
    page: state.page, per: state.per, q: state.q, status: state.status,
    from: state.from, to: state.to
  });

  let raw = '';
  try {
    const res = await fetch('./api/list_shipments.php?' + params.toString(), { credentials:'same-origin' });
    raw = await res.text();

    // Clearer error when PHP returns 4xx/5xx
    if (!res.ok) {
      alert(`Server error ${res.status}. Preview:\n` + raw.slice(0,200));
      console.error('list_shipments.php HTTP ' + res.status + ':\n' + raw);
      return;
    }

    // Parse JSON with a specific message when it fails
    let data;
    try { data = JSON.parse(raw); }
    catch (e) {
      alert('Server returned invalid JSON. See console for the first 400 chars.');
      console.error('Bad JSON from list_shipments.php:\n' + raw.slice(0,400));
      return;
    }

    if (!data.rows || data.rows.length === 0) {
      tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-muted">No shipments found</td></tr>';
    } else {
      tbody.innerHTML = data.rows.map(r => `
        <tr>
          <td class="fw-semibold">${r.ref_no}</td>
          <td>${r.origin}</td>
          <td>${r.destination}</td>
          <td><span class="badge bg-${badge(r.status)}">${r.status}</span></td>
          <td>${r.eta || ''}</td>
          <td>${r.carrier || ''}</td>
          <td class="text-end">
            <button class="btn btn-sm btn-outline-primary" onclick="openView(${r.id})">View</button>
          </td>
        </tr>
      `).join('');
    }

    document.getElementById('pagerText').textContent =
      `Page ${data.page} of ${data.total_pages} • ${data.total} total`;
    renderPager(data.page, data.total_pages);

  } catch (err) {
    alert('Request failed before we got a response. Check the browser console.');
    console.error(err, '\nRaw response (if any):\n', raw);
  }
}





    function badge(st){
      return ({
        'Draft':'secondary','Ready':'info','Dispatched':'primary',
        'In Transit':'warning','Delivered':'success','Delayed':'danger',
        'Cancelled':'dark','Returned':'secondary'
      })[st] || 'secondary';
    }

    function renderPager(page, totalPages){
  const el = document.getElementById('pager');
  if (!el) return;

  const windowSize = 2;
  const start = Math.max(1, page - windowSize);
  const end   = Math.min(totalPages, page + windowSize);

  let html = '';

  // prev
  html += `<li class="page-item ${page <= 1 ? 'disabled' : ''}">
    <a class="page-link" href="#" data-goto="${page - 1}" aria-label="Previous">&laquo;</a>
  </li>`;

  // numbers
  for (let p = start; p <= end; p++) {
    html += `<li class="page-item ${p === page ? 'active' : ''}">
      <a class="page-link" href="#" data-goto="${p}">${p}</a>
    </li>`;
  }

  // next
  html += `<li class="page-item ${page >= totalPages ? 'disabled' : ''}">
    <a class="page-link" href="#" data-goto="${page + 1}" aria-label="Next">&raquo;</a>
  </li>`;

  el.innerHTML = html;

  // wire up clicks
  el.querySelectorAll('a[data-goto]').forEach(a => {
    a.addEventListener('click', (ev) => {
      ev.preventDefault();
      const p = parseInt(a.dataset.goto, 10);
      if (!Number.isFinite(p) || p < 1 || p > totalPages || p === page) return;
      state.page = p;
      loadTable();
    });
  });
}

    document.getElementById('addForm')?.addEventListener('submit', async (e)=>{
  e.preventDefault();
  const btn = e.submitter;
  if (btn) btn.disabled = true;

  try {
    const formData = new FormData(e.target);
    const res  = await fetch('./api/add_shipment.php', {
      method:'POST',
      body:formData,
      credentials:'same-origin'
    });
    const text = await res.text();

    if (!res.ok) {
      let msg = 'Create failed';
      try { const j = JSON.parse(text); if (j && j.err) msg = j.err; } catch {}
      alert(msg);
      console.error('add_shipment.php error:', text);
      return;
    }

    // success
    e.target.reset();

    const addMdlEl = document.getElementById('mdlAdd');
    const addMdl   = bootstrap.Modal.getOrCreateInstance(addMdlEl);
    addMdl.hide();

    addMdlEl.addEventListener('hidden.bs.modal', () => cleanupBackdrops(), { once: true });

    let ref = '';
    try { const j = JSON.parse(text); ref = j.ref_no || ''; } catch {}
    toast(ref ? `Created ${ref}` : 'Shipment created', 'success');

    resetFiltersToAll();
    await loadTable();

  } catch (err) {
    alert('Create failed (network). Check console.');
    console.error(err);
  } finally {
    if (btn) btn.disabled = false;
  }
});


    


async function openView(id){
  try {
    const res = await fetch(`./api/get_shipment.php?id=${id}`, { credentials:'same-origin' });
    const raw = await res.text();
    if (!res.ok) { alert('Load failed: ' + raw.slice(0,180)); console.error(raw); return; }
    const { shipment, events } = JSON.parse(raw);

    document.getElementById('vRef').textContent = shipment.ref_no;
    document.getElementById('vMeta').textContent =
      `${shipment.origin} → ${shipment.destination} • ${shipment.status} • Carrier: ${shipment.carrier || '—'} • ETA: ${shipment.expected_delivery || '—'}`;
    document.getElementById('vStatus').value = shipment.status;
    document.getElementById('vDetails').value = '';

    document.getElementById('vTimeline').innerHTML =
      (events && events.length)
        ? events.map(ev => `<li class="list-group-item d-flex justify-content-between">
              <span>${ev.event_time} — <strong>${ev.event_type}</strong> ${ev.details ? ('· ' + ev.details) : ''}</span>
           </li>`).join('')
        : '<li class="list-group-item">No events yet.</li>';

    new bootstrap.Modal(document.getElementById('mdlView')).show();

    document.getElementById('btnPostEvent').onclick = async ()=>{
      const body = new URLSearchParams({
        id,
        status: document.getElementById('vStatus').value,
        details: document.getElementById('vDetails').value
      });
      const up = await fetch('./api/update_status.php', { method:'POST', body, credentials:'same-origin' });
      const ut = await up.text();
      if (!up.ok) { alert('Update failed: ' + ut.slice(0,180)); console.error(ut); return; }
      toast(`Status updated to ${document.getElementById('vStatus').value}`, 'success');
      openView(id);
      loadTable();  
    };
  } catch (err) {
    alert('Load failed (network); see console.');
    console.error(err);
  }
}






    // init
    <?php if ($dbReady): ?>
    loadLocations().then(loadTable);
    <?php endif; ?>

    function toast(msg, variant='success', delay=2200){
  const wrap = document.getElementById('toasts');
  const el = document.createElement('div');
  el.className = `toast text-bg-${variant} border-0`;
  el.role = 'status';
  el.ariaLive = 'polite';
  el.ariaAtomic = 'true';
  el.innerHTML = `
    <div class="d-flex">
      <div class="toast-body">${msg}</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>`;
  wrap.appendChild(el);
  const t = new bootstrap.Toast(el, { delay });
  t.show();
  el.addEventListener('hidden.bs.toast', ()=> el.remove());
}

function cleanupBackdrops() {
  document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
  document.body.classList.remove('modal-open');
  document.body.style.removeProperty('paddingRight');
}
document.addEventListener('hidden.bs.modal', cleanupBackdrops);


  </script>

<!-- Toasts -->
<div class="toast-container position-fixed top-0 end-0 p-3" id="toasts" style="z-index:1080"></div>


  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
