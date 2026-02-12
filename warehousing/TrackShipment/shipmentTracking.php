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
$isLocationEditor = in_array(strtolower($_SESSION["user"]["role"] ?? ""), ["admin","manager"], true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Track Shipments | TNVS</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <link href="../../css/style.css" rel="stylesheet" />
  <link href="../../css/modules.css" rel="stylesheet" />

  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script src="../../js/sidebar-toggle.js"></script>
  <style>
    :root {
      --map-ink: #0f172a;
      --map-accent: #2563eb;
      --map-accent-2: #14b8a6;
    }
    .map-card {
      border: 1px solid #dbe4ff;
      border-radius: 16px;
      background: radial-gradient(1200px 320px at -10% -50%, #dbeafe 0%, #f8fbff 50%, #ffffff 100%);
      box-shadow: 0 10px 30px rgba(15, 23, 42, .08);
    }
    .map-title {
      font-weight: 700;
      color: var(--map-ink);
      letter-spacing: .2px;
    }
    .shipment-map {
      height: 300px;
      border-radius: 14px;
      border: 1px solid #dbe4ff;
      overflow: hidden;
      background: linear-gradient(180deg, #f8fafc 0%, #eef2ff 100%);
      box-shadow: inset 0 0 0 1px rgba(255,255,255,.35);
    }
    .loc-picker-map {
      height: 320px;
      border-radius: 14px;
      border: 1px solid #c7d2fe;
      overflow: hidden;
      background: linear-gradient(180deg, #f0f9ff 0%, #eef2ff 100%);
    }
    .loc-list {
      max-height: 350px;
      overflow: auto;
      border: 1px solid #e5e7eb;
      border-radius: 12px;
      background: #fff;
    }
    .loc-row {
      cursor: pointer;
    }
    .loc-row:hover {
      background: #f8fafc;
    }
    .loc-row.active {
      background: linear-gradient(90deg, #eff6ff, #ecfeff);
    }
    .map-chip {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 10px;
      border-radius: 999px;
      border: 1px solid #bfdbfe;
      background: #eff6ff;
      color: #1e3a8a;
      font-size: .78rem;
      font-weight: 600;
    }
    .pin-origin, .pin-destination {
      width: 16px;
      height: 16px;
      border-radius: 50%;
      border: 2px solid #fff;
      box-shadow: 0 0 0 3px rgba(37, 99, 235, .22), 0 6px 18px rgba(15,23,42,.3);
    }
    .pin-origin { background: #2563eb; }
    .pin-destination { background: #10b981; box-shadow: 0 0 0 3px rgba(16, 185, 129, .22), 0 6px 18px rgba(15,23,42,.3); }
    .leaflet-popup-content-wrapper {
      border-radius: 12px;
    }
    .place-results {
      margin-top: 8px;
      border: 1px solid #dbe4ff;
      border-radius: 12px;
      background: #fff;
      max-height: 180px;
      overflow: auto;
    }
    .place-item {
      width: 100%;
      border: 0;
      border-bottom: 1px solid #eef2ff;
      background: #fff;
      text-align: left;
      padding: 9px 10px;
      cursor: pointer;
    }
    .place-item:last-child {
      border-bottom: 0;
    }
    .place-item:hover {
      background: #f8fafc;
    }
    .place-item .title {
      font-size: .86rem;
      color: #0f172a;
      font-weight: 600;
      line-height: 1.25;
    }
    .place-item .meta {
      font-size: .75rem;
      color: #64748b;
      margin-top: 2px;
    }
  </style>

</head>
<body class="saas-page">
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
            <h2 class="m-0 d-flex align-items-center gap-2 page-title">
              <ion-icon name="paper-plane-outline"></ion-icon>Track Shipments
            </h2>
          </div>

          <div class="profile-menu" data-profile-menu>
            <button class="profile-trigger" type="button" data-profile-trigger aria-expanded="false" aria-haspopup="true">
              <img src="../../img/profile.jpg" class="rounded-circle" width="36" height="36" alt="">
              <div class="profile-text">
                <div class="profile-name"><?= htmlspecialchars($userName) ?></div>
                <div class="profile-role"><?= htmlspecialchars($userRole) ?></div>
              </div>
              <ion-icon class="profile-caret" name="chevron-down-outline"></ion-icon>
            </button>
            <div class="profile-dropdown" data-profile-dropdown role="menu">
              <a href="<?= u('auth/logout.php') ?>" role="menuitem">Sign out</a>
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
            <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#mdlLocations">
  <ion-icon name="location-outline"></ion-icon> Warehouse Locations
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
          <div class="map-card p-3 mb-3">
            <div class="d-flex flex-wrap align-items-center justify-content-between mb-2 gap-2">
              <div class="map-title">Arrival Route Map</div>
              <div class="d-flex align-items-center gap-2">
                <label for="mapFocusSel" class="small text-muted mb-0">Focus</label>
                <select id="mapFocusSel" class="form-select form-select-sm" style="min-width:220px">
                  <option value="auto">Auto (Origin to Destination)</option>
                </select>
                <button class="btn btn-sm btn-outline-success" id="mapApplyToBtn" type="button" disabled>Set Location</button>
                <span class="map-chip"><ion-icon name="navigate-outline"></ion-icon> Live Route Preview</span>
              </div>
            </div>
            <div class="card-body py-2">
              <div id="arrivalSummary" class="small text-muted mb-2">Loading route preview…</div>
              <div id="shipmentMap" class="shipment-map"></div>
              <div id="mapHint" class="small text-muted mt-2">Pins come from saved location coordinates, or fallback geocoding by address.</div>
            </div>
          </div>
          <div id="vAI" class="card border-0 bg-light mb-3">
            <div class="card-body py-2">
              <div class="fw-semibold mb-1">AI Insights</div>
              <div class="text-muted small">No AI insights available.</div>
            </div>
          </div>
          <div class="alert alert-info small mb-3">
            Status updates are managed by Logistics 2 dispatch. This view is read-only.
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

  <!-- Manage Warehouse Locations -->
  <div class="modal fade" id="mdlLocations" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Warehouse Locations</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-lg-4">
              <div class="d-flex align-items-center justify-content-between mb-2">
                <div class="fw-semibold">Saved Locations</div>
                <button class="btn btn-sm btn-outline-secondary" type="button" id="locNewBtn">New</button>
              </div>
              <input type="text" id="locSearch" class="form-control form-control-sm mb-2" placeholder="Search code/name/address">
              <div class="loc-list">
                <table class="table table-sm align-middle mb-0">
                  <tbody id="locMgrBody">
                    <tr><td class="text-center text-muted py-3">Loading…</td></tr>
                  </tbody>
                </table>
              </div>
              <?php if (!$isLocationEditor): ?>
              <div class="alert alert-warning small mt-2 mb-0">Only admins/managers can save/delete locations.</div>
              <?php endif; ?>
            </div>
            <div class="col-lg-8">
              <form id="locMgrForm" class="row g-2">
                <input type="hidden" id="locId" name="id">
                <div class="col-md-3">
                  <label class="form-label">Code</label>
                  <input class="form-control" id="locCode" name="code" maxlength="32" required>
                </div>
                <div class="col-md-5">
                  <label class="form-label">Name</label>
                  <input class="form-control" id="locName" name="name" maxlength="128" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Address</label>
                  <input class="form-control" id="locAddress" name="address" maxlength="255" placeholder="City / district / street">
                </div>
                <div class="col-md-8">
                  <label class="form-label">Search Place</label>
                  <div class="input-group">
                    <input class="form-control" id="locPlaceQ" placeholder="Search area, barangay, landmark…">
                    <button class="btn btn-outline-primary" id="locPlaceBtn" type="button">Find</button>
                  </div>
                  <div id="locPlaceResults" class="place-results d-none"></div>
                </div>
                <div class="col-md-2">
                  <label class="form-label">Latitude</label>
                  <input class="form-control" id="locLat" name="latitude" placeholder="14.5995">
                </div>
                <div class="col-md-2">
                  <label class="form-label">Longitude</label>
                  <input class="form-control" id="locLng" name="longitude" placeholder="120.9842">
                </div>
                <div class="col-12">
                  <div class="map-card p-2">
                    <div id="locPickerMap" class="loc-picker-map"></div>
                    <div class="small text-muted mt-2 px-1">Tip: drag and click the map to refine the exact warehouse pin.</div>
                  </div>
                </div>
              </form>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <?php if ($isLocationEditor): ?>
            <button class="btn btn-outline-danger me-auto" type="button" id="locDeleteBtn" disabled>Delete</button>
            <button class="btn btn-outline-secondary" type="button" id="locResetBtn">Reset</button>
            <button class="btn btn-primary" type="button" id="locSaveBtn">Save Location</button>
          <?php else: ?>
            <button class="btn btn-outline-secondary" type="button" id="locResetBtn">Reset</button>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- JS -->
  <script>
    const state = { page:1, per:25, q:'', status:'', from:'', to:'' };
    const currentShipment = { id: 0, ref: '' };
    let currentShipmentData = null;
    const mapState = { map: null, points: null, line: null };
    const locState = { map: null, pin: null, rows: [], selectedId: 0 };
    const focusWarehouses = new Map();
    const geoCache = new Map();
    const fallbackCenter = [14.5995, 120.9842]; // Manila
    const phBounds = [[4.2, 116.0], [21.8, 127.2]];
    const GEO_CACHE_PREFIX = 'tnvs_geo_v2_';
    const canEditLocations = <?= $isLocationEditor ? 'true' : 'false' ?>;

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
          <td>
            ${r.origin || '—'}
            ${r.origin_address ? `<div class="small text-muted">${r.origin_address}</div>` : ''}
          </td>
          <td>
            ${r.destination || '—'}
            ${r.destination_address ? `<div class="small text-muted">${r.destination_address}</div>` : ''}
          </td>
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

function getSelectedFocusLocationId(){
  const v = String(document.getElementById('mapFocusSel')?.value || '');
  if (!v.startsWith('loc:')) return 0;
  const id = Number(v.split(':')[1] || 0);
  return Number.isFinite(id) ? id : 0;
}

function updateFocusSaveButton(){
  const btn = document.getElementById('mapApplyToBtn');
  if (!btn) return;
  const locId = getSelectedFocusLocationId();
  const currentDest = Number(currentShipmentData?.destination_id || 0);
  const canSave = locId > 0 && locId !== currentDest;
  btn.disabled = !canSave;
}

async function populateFocusOptions(shipment){
  const focusSel = document.getElementById('mapFocusSel');
  if (!focusSel) return;

  const oLabel = shipment?.origin ? `Origin: ${shipment.origin}` : 'Origin warehouse';
  const dLabel = shipment?.destination ? `Destination: ${shipment.destination}` : 'Destination warehouse';
  let extraOptions = '';
  try {
    const wr = await fetch(`../api/locations_list.php?_ts=${Date.now()}`, { credentials:'same-origin', cache:'no-store' });
    const wt = await wr.text();
    if (wr.ok) {
      const rows = JSON.parse(wt) || [];
      focusWarehouses.clear();
      for (const r of rows) {
        const id = Number(r.id || 0);
        if (!id) continue;
        focusWarehouses.set(id, r);
        const lbl = `${r.code || 'WH'} - ${r.name || 'Warehouse'}`;
        extraOptions += `<option value="loc:${id}">Warehouse: ${lbl}</option>`;
      }
    }
  } catch (e) {}

  focusSel.innerHTML = `
    <option value="auto">Auto (Origin to Destination)</option>
    <option value="origin">${oLabel}</option>
    <option value="destination">${dLabel}</option>
    ${extraOptions}
  `;
  focusSel.value = 'auto';
  updateFocusSaveButton();
}

function applyShipmentMeta(shipment){
  document.getElementById('vRef').textContent = shipment.ref_no;
  const originAddr = shipment.origin_address ? `<div class="small text-muted">From address: ${shipment.origin_address}</div>` : '';
  const destAddr = shipment.destination_address ? `<div class="small text-muted">To address: ${shipment.destination_address}</div>` : '';
  document.getElementById('vMeta').innerHTML =
    `<div>${shipment.origin} → ${shipment.destination} • ${shipment.status} • Carrier: ${shipment.carrier || '—'} • ETA: ${shipment.expected_delivery || '—'}</div>` +
    originAddr + destAddr;
}

async function reloadCurrentShipmentView(){
  if (!currentShipment.id) return;
  const res = await fetch(`./api/get_shipment.php?id=${currentShipment.id}&_ts=${Date.now()}`, {
    credentials:'same-origin',
    cache:'no-store'
  });
  const raw = await res.text();
  if (!res.ok) throw new Error(raw.slice(0, 180));
  const { shipment, events } = JSON.parse(raw);
  currentShipmentData = shipment;
  applyShipmentMeta(shipment);
  document.getElementById('vTimeline').innerHTML =
    (events && events.length)
      ? events.map(ev => `<li class="list-group-item d-flex justify-content-between">
            <span>${ev.event_time} — <strong>${ev.event_type}</strong> ${ev.details ? ('· ' + ev.details) : ''}</span>
         </li>`).join('')
      : '<li class="list-group-item">No events yet.</li>';
  await populateFocusOptions(shipment);
  await renderShipmentMap(shipment, 'auto');
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
    const res = await fetch(`./api/get_shipment.php?id=${id}&_ts=${Date.now()}`, {
      credentials:'same-origin',
      cache:'no-store'
    });
    const raw = await res.text();
    if (!res.ok) { alert('Load failed: ' + raw.slice(0,180)); console.error(raw); return; }
    const { shipment, events, ai_insights } = JSON.parse(raw);
    currentShipmentData = shipment;
    currentShipment.id = Number(shipment.id || 0);
    currentShipment.ref = String(shipment.ref_no || '');
    const aiRefInput = document.getElementById('aiRef');
    if (aiRefInput) aiRefInput.value = currentShipment.ref;

    applyShipmentMeta(shipment);
    // Status updates handled by Logistics 2 (read-only view here)

    const aiWrap = document.getElementById('vAI');
    if (aiWrap) {
      if (!ai_insights) {
        aiWrap.innerHTML = `<div class="card-body py-2"><div class="fw-semibold mb-1">AI Insights</div><div class="text-muted small">No AI insights available.</div></div>`;
      } else {
        const methodMap = { pickup: 'Pickup requested', vendor_deliver: 'Vendor delivers', unknown: 'Unknown' };
        const method = methodMap[ai_insights.delivery_method] || ai_insights.delivery_method || 'Unknown';
        const list = (arr)=> (arr && arr.length) ? arr.join(', ') : '—';
        const summary = ai_insights.summary ? ai_insights.summary : '—';
        const source = ai_insights.source_po ? ` (from ${ai_insights.source_po})` : '';
        aiWrap.innerHTML = `
          <div class="card-body py-2">
            <div class="fw-semibold mb-1">AI Insights${source}</div>
            <div class="small"><strong>Delivery Method:</strong> ${method}</div>
            <div class="small"><strong>Dates:</strong> ${list(ai_insights.dates)}</div>
            <div class="small"><strong>Times:</strong> ${list(ai_insights.times)}</div>
            <div class="small"><strong>Locations:</strong> ${list(ai_insights.locations)}</div>
            <div class="small"><strong>Summary:</strong> ${summary}</div>
          </div>`;
      }
    }

    document.getElementById('vTimeline').innerHTML =
      (events && events.length)
        ? events.map(ev => `<li class="list-group-item d-flex justify-content-between">
              <span>${ev.event_time} — <strong>${ev.event_type}</strong> ${ev.details ? ('· ' + ev.details) : ''}</span>
           </li>`).join('')
        : '<li class="list-group-item">No events yet.</li>';

    const focusSel = document.getElementById('mapFocusSel');
    await populateFocusOptions(shipment);

    const mdlEl = document.getElementById('mdlView');
    const mdl = new bootstrap.Modal(mdlEl);
    mdl.show();
    mdlEl.addEventListener('shown.bs.modal', ()=>{
      renderShipmentMap(shipment, focusSel?.value || 'auto');
    }, { once:true });

    // Update controls removed
  } catch (err) {
    alert('Load failed (network); see console.');
    console.error(err);
  }
}

// AI chat loaded via includes/ai_chatbot.php

function mapSummaryText(shipment){
  const status = String(shipment?.status || '').toLowerCase();
  if (status === 'delivered') return 'Delivered. Shipment already arrived at destination warehouse.';
  if (status === 'in transit') return 'In transit. Route preview shows origin and destination warehouses.';
  if (status === 'delayed') return 'Delayed shipment. Route preview helps monitor expected arrival.';
  if (status === 'ready' || status === 'dispatched') return 'Dispatched from origin warehouse and heading to destination.';
  return 'Route preview between origin and destination warehouses.';
}

function normalizeName(name){
  return String(name || '').replace(/^[A-Z0-9_-]+\s*-\s*/i, '').trim();
}

function buildQuery(name, address){
  const parts = [];
  if (address) parts.push(String(address).trim());
  const cleanName = normalizeName(name);
  if (cleanName) parts.push(cleanName);
  parts.push('Philippines');
  return parts.filter(Boolean).join(', ');
}

function readGeoCache(key){
  if (!key) return null;
  if (geoCache.has(key)) return geoCache.get(key);
  try {
    const raw = localStorage.getItem(GEO_CACHE_PREFIX + key);
    if (!raw) return null;
    const data = JSON.parse(raw);
    if (data && Number.isFinite(data.lat) && Number.isFinite(data.lng)) {
      geoCache.set(key, data);
      return data;
    }
  } catch (e) {}
  return null;
}

function writeGeoCache(key, point){
  if (!key || !point) return;
  geoCache.set(key, point);
  try { localStorage.setItem(GEO_CACHE_PREFIX + key, JSON.stringify(point)); } catch (e) {}
}

async function fetchRoadRoute(originPoint, destPoint){
  if (!originPoint || !destPoint) return null;
  const oLat = Number(originPoint.lat), oLng = Number(originPoint.lng);
  const dLat = Number(destPoint.lat), dLng = Number(destPoint.lng);
  if (!Number.isFinite(oLat) || !Number.isFinite(oLng) || !Number.isFinite(dLat) || !Number.isFinite(dLng)) return null;

  // Try more than one public OSRM service so we get road geometry reliably.
  const endpoints = [
    'https://router.project-osrm.org',
    'https://routing.openstreetmap.de/routed-car'
  ];

  for (const base of endpoints) {
    const url = `${base}/route/v1/driving/${oLng},${oLat};${dLng},${dLat}?overview=full&geometries=geojson&alternatives=false&steps=false&continue_straight=true`;
    try {
      const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
      if (!res.ok) continue;
      const j = await res.json();
      const coords = j?.routes?.[0]?.geometry?.coordinates;
      if (!Array.isArray(coords) || !coords.length) continue;
      const out = coords
        .map(c => [Number(c[1]), Number(c[0])])
        .filter(c => Number.isFinite(c[0]) && Number.isFinite(c[1]));
      // If route has only 2 points it behaves like straight fallback; treat as unusable.
      if (out.length < 3) continue;
      return out;
    } catch (e) {
      // Try next endpoint
    }
  }
  return null;
}

async function geocode(query){
  const rows = await geocodeMany(query, 1);
  return rows[0] || null;
}

async function geocodeMany(query, limit = 5){
  const key = String(query || '').toLowerCase().trim();
  if (!key) return [];
  const cached = readGeoCache(key);
  if (cached && Number.isFinite(cached.lat) && Number.isFinite(cached.lng)) {
    return [{ lat: cached.lat, lng: cached.lng, display_name: key, label: key }];
  }

  const url = `https://nominatim.openstreetmap.org/search?format=jsonv2&countrycodes=ph&bounded=1&viewbox=116,22,127,4&limit=${Math.max(1, Math.min(limit, 8))}&q=${encodeURIComponent(query)}`;
  const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
  if (!res.ok) return [];
  const rows = await res.json();
  if (!Array.isArray(rows) || !rows.length) return [];

  const out = [];
  for (const r of rows) {
    const lat = Number(r.lat);
    const lng = Number(r.lon);
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) continue;
    out.push({
      lat,
      lng,
      display_name: String(r.display_name || ''),
      label: String(r.name || r.display_name || '')
    });
  }
  if (out[0]) writeGeoCache(key, { lat: out[0].lat, lng: out[0].lng });
  return out;
}

function isPhPoint(point){
  if (!point) return false;
  const lat = Number(point.lat);
  const lng = Number(point.lng);
  return Number.isFinite(lat) && Number.isFinite(lng) && lat >= 4.2 && lat <= 21.8 && lng >= 116.0 && lng <= 127.2;
}

function escHtml(v){
  return String(v || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function clearPlaceResults(){
  const el = document.getElementById('locPlaceResults');
  if (!el) return;
  el.classList.add('d-none');
  el.innerHTML = '';
}

function renderPlaceResults(items){
  const el = document.getElementById('locPlaceResults');
  if (!el) return;
  if (!items.length) {
    el.classList.remove('d-none');
    el.innerHTML = `<div class="px-3 py-2 small text-muted">No PH matches found.</div>`;
    return;
  }
  el.innerHTML = items.map((r, idx)=>`
    <button type="button" class="place-item" data-i="${idx}">
      <div class="title">${escHtml(r.label || r.display_name)}</div>
      <div class="meta">${escHtml(r.display_name)} · ${Number(r.lat).toFixed(5)}, ${Number(r.lng).toFixed(5)}</div>
    </button>
  `).join('');
  el.classList.remove('d-none');
  el.querySelectorAll('.place-item').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const i = Number(btn.dataset.i || -1);
      const hit = items[i];
      if (!hit) return;
      setLocPin(hit.lat, hit.lng);
      ensureLocMap().setView([hit.lat, hit.lng], 16);
      if (hit.display_name) document.getElementById('locAddress').value = hit.display_name.slice(0, 255);
      clearPlaceResults();
    });
  });
}

function ensureMap(){
  if (mapState.map) return mapState.map;
  mapState.map = L.map('shipmentMap', {
    zoomControl: true,
    attributionControl: true,
    maxBounds: phBounds,
    maxBoundsViscosity: 0.8,
    minZoom: 5
  });
  L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap &copy; CARTO'
  }).addTo(mapState.map);
  mapState.map.setView(fallbackCenter, 11);
  return mapState.map;
}

async function renderShipmentMap(shipment, focusMode = 'auto'){
  const summaryEl = document.getElementById('arrivalSummary');
  const hintEl = document.getElementById('mapHint');
  if (summaryEl) summaryEl.textContent = mapSummaryText(shipment);

  if (typeof L === 'undefined') {
    if (hintEl) hintEl.textContent = 'Map library failed to load.';
    return;
  }

  const map = ensureMap();
  if (mapState.points) { map.removeLayer(mapState.points); mapState.points = null; }
  if (mapState.line) { map.removeLayer(mapState.line); mapState.line = null; }

  const numberOrNull = (v)=>{
    const n = Number(v);
    return Number.isFinite(n) ? n : null;
  };
  let originPoint = null;
  let destPoint = null;
  const originLat = numberOrNull(shipment?.origin_latitude);
  const originLng = numberOrNull(shipment?.origin_longitude);
  const destLat = numberOrNull(shipment?.destination_latitude);
  const destLng = numberOrNull(shipment?.destination_longitude);
  if (originLat !== null && originLng !== null) {
    const p = { lat: originLat, lng: originLng };
    if (isPhPoint(p)) originPoint = p;
  }
  if (destLat !== null && destLng !== null) {
    const p = { lat: destLat, lng: destLng };
    if (isPhPoint(p)) destPoint = p;
  }

  if (!originPoint || !destPoint) {
    const originQuery = buildQuery(shipment?.origin, shipment?.origin_address);
    const destQuery = buildQuery(shipment?.destination, shipment?.destination_address);
    try {
      const [o, d] = await Promise.all([
        originPoint ? Promise.resolve(originPoint) : geocode(originQuery),
        destPoint ? Promise.resolve(destPoint) : geocode(destQuery),
      ]);
      originPoint = o;
      destPoint = d;
    } catch (e) {}
  }

  if (originPoint && !isPhPoint(originPoint)) originPoint = null;
  if (destPoint && !isPhPoint(destPoint)) destPoint = null;

  const isWarehouseFocus = String(focusMode).startsWith('loc:');
  const wantOrigin = focusMode === 'auto' || focusMode === 'origin';
  const wantDestination = focusMode === 'auto' || focusMode === 'destination';

  let extraPoint = null;
  let extraLabel = '';
  if (isWarehouseFocus) {
    const locId = Number(String(focusMode).split(':')[1] || 0);
    const rec = focusWarehouses.get(locId);
    const lat = Number(rec?.latitude);
    const lng = Number(rec?.longitude);
    if (Number.isFinite(lat) && Number.isFinite(lng) && isPhPoint({lat, lng})) {
      extraPoint = { lat, lng };
      extraLabel = `${rec?.code || 'WH'} - ${rec?.name || 'Warehouse'}`;
      originPoint = null;
      destPoint = null;
    } else {
      originPoint = null;
      destPoint = null;
      if (hintEl) hintEl.textContent = 'Selected warehouse has no saved pin yet. Update it in Warehouse Locations.';
    }
  } else {
    if (!wantOrigin) originPoint = null;
    if (!wantDestination) destPoint = null;
  }

  const points = [];
  mapState.points = L.layerGroup().addTo(map);

  if (originPoint) {
    points.push([originPoint.lat, originPoint.lng]);
    const originIcon = L.divIcon({ className: '', html: '<div class="pin-origin"></div>', iconSize: [16,16], iconAnchor:[8,8] });
    L.marker([originPoint.lat, originPoint.lng], { icon: originIcon })
      .bindPopup(`<strong>Origin</strong><br>${shipment?.origin || 'Warehouse'}`).addTo(mapState.points);
  }
  if (destPoint) {
    points.push([destPoint.lat, destPoint.lng]);
    const destIcon = L.divIcon({ className: '', html: '<div class="pin-destination"></div>', iconSize: [16,16], iconAnchor:[8,8] });
    L.marker([destPoint.lat, destPoint.lng], { icon: destIcon })
      .bindPopup(`<strong>Destination</strong><br>${shipment?.destination || 'Warehouse'}`).addTo(mapState.points);
  }
  if (extraPoint) {
    points.length = 0;
    points.push([extraPoint.lat, extraPoint.lng]);
    const selectedIcon = L.divIcon({ className: '', html: '<div class="pin-destination"></div>', iconSize: [16,16], iconAnchor:[8,8] });
    L.marker([extraPoint.lat, extraPoint.lng], { icon: selectedIcon })
      .bindPopup(`<strong>Warehouse</strong><br>${extraLabel}`).addTo(mapState.points);
  }

  let usedRoadRoute = false;
  if (!isWarehouseFocus && focusMode === 'auto' && originPoint && destPoint) {
    let routePath = await fetchRoadRoute(originPoint, destPoint);
    if (!routePath || routePath.length < 2) {
      routePath = [
        [originPoint.lat, originPoint.lng],
        [destPoint.lat, destPoint.lng]
      ];
      usedRoadRoute = false;
    } else {
      usedRoadRoute = true;
    }
    mapState.line = L.polyline(routePath, usedRoadRoute
      ? { color: '#2563eb', weight: 5, opacity: 0.95 }
      : { color: '#2563eb', weight: 5, opacity: 0.9, dashArray: '10 8' }
    ).addTo(map);
  }

  if (points.length > 1) {
    map.fitBounds(points, { padding: [32, 32] });
    if (map.getZoom() > 14) map.setZoom(14);
    if (hintEl) {
      hintEl.textContent = usedRoadRoute
        ? 'Live road route preview based on saved warehouse pins.'
        : 'Live route preview based on saved warehouse pins and addresses.';
    }
  } else if (points.length === 1) {
    map.setView(points[0], 16, { animate: true });
    if (hintEl) hintEl.textContent = 'Live route preview based on saved warehouse pins and addresses.';
  } else {
    map.setView(fallbackCenter, 11);
    if (hintEl) hintEl.textContent = 'Could not place PH map pins. Open Warehouse Locations and pin exact Philippine coordinates.';
  }

  setTimeout(()=> map.invalidateSize(), 100);
}

document.getElementById('mapFocusSel')?.addEventListener('change', ()=>{
  if (!currentShipmentData) return;
  const mode = document.getElementById('mapFocusSel')?.value || 'auto';
  updateFocusSaveButton();
  renderShipmentMap(currentShipmentData, mode);
});

document.getElementById('mapApplyToBtn')?.addEventListener('click', async ()=>{
  const locId = getSelectedFocusLocationId();
  if (!locId || !currentShipment.id) return;
  const btn = document.getElementById('mapApplyToBtn');
  if (btn) btn.disabled = true;
  try {
    const fd = new URLSearchParams();
    fd.set('id', String(currentShipment.id));
    fd.set('destination_id', String(locId));
    const res = await fetch('./api/update_destination.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: fd.toString()
    });
    const raw = await res.text();
    if (!res.ok) {
      let msg = 'Failed to update destination';
      try { const j = JSON.parse(raw); if (j?.err) msg = j.err; } catch (e) {}
      alert(msg);
      return;
    }
    toast('Destination updated', 'success');
    await loadTable();
    await reloadCurrentShipmentView();
  } catch (e) {
    alert('Failed to update destination.');
  } finally {
    updateFocusSaveButton();
  }
});

function ensureLocMap(){
  if (locState.map) return locState.map;
  locState.map = L.map('locPickerMap', {
    zoomControl: true,
    attributionControl: true,
    maxBounds: phBounds,
    maxBoundsViscosity: 0.9,
    minZoom: 5
  }).setView(fallbackCenter, 11);
  L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap &copy; CARTO'
  }).addTo(locState.map);
  locState.map.on('click', (e)=>{
    setLocPin(e.latlng.lat, e.latlng.lng);
  });
  return locState.map;
}

function setLocPin(lat, lng){
  const p = { lat: Number(lat), lng: Number(lng) };
  if (!isPhPoint(p)) {
    alert('Please pin a location inside the Philippines.');
    return;
  }
  const map = ensureLocMap();
  if (locState.pin) map.removeLayer(locState.pin);
  locState.pin = L.marker([p.lat, p.lng], { draggable: true }).addTo(map);
  locState.pin.on('dragend', ()=>{
    const p = locState.pin.getLatLng();
    if (!isPhPoint(p)) {
      alert('Please keep the pin inside the Philippines.');
      locState.pin.setLatLng([fallbackCenter[0], fallbackCenter[1]]);
      document.getElementById('locLat').value = fallbackCenter[0].toFixed(7);
      document.getElementById('locLng').value = fallbackCenter[1].toFixed(7);
      return;
    }
    document.getElementById('locLat').value = Number(p.lat).toFixed(7);
    document.getElementById('locLng').value = Number(p.lng).toFixed(7);
  });
  document.getElementById('locLat').value = p.lat.toFixed(7);
  document.getElementById('locLng').value = p.lng.toFixed(7);
}

function resetLocForm(){
  locState.selectedId = 0;
  document.getElementById('locId').value = '';
  document.getElementById('locCode').value = '';
  document.getElementById('locName').value = '';
  document.getElementById('locAddress').value = '';
  document.getElementById('locLat').value = '';
  document.getElementById('locLng').value = '';
  document.getElementById('locPlaceQ').value = '';
  clearPlaceResults();
  document.getElementById('locDeleteBtn')?.setAttribute('disabled', 'disabled');
  document.querySelectorAll('.loc-row.active').forEach(el=>el.classList.remove('active'));
  const map = ensureLocMap();
  if (locState.pin) { map.removeLayer(locState.pin); locState.pin = null; }
  map.setView(fallbackCenter, 11);
}

function renderLocRows(rows){
  const tbody = document.getElementById('locMgrBody');
  const q = String(document.getElementById('locSearch')?.value || '').toLowerCase().trim();
  const filtered = q
    ? rows.filter(r => (String(r.code||'') + ' ' + String(r.name||'') + ' ' + String(r.address||'')).toLowerCase().includes(q))
    : rows;
  if (!filtered.length) {
    tbody.innerHTML = '<tr><td class="text-center text-muted py-3">No locations found.</td></tr>';
    return;
  }
  tbody.innerHTML = filtered.map(r=>`
    <tr class="loc-row ${Number(r.id)===locState.selectedId?'active':''}" data-id="${r.id}">
      <td>
        <div class="fw-semibold">${r.code} - ${r.name}</div>
        <div class="small text-muted">${r.address || 'No address'}</div>
      </td>
    </tr>
  `).join('');
  tbody.querySelectorAll('.loc-row').forEach(tr=>{
    tr.addEventListener('click', ()=>{
      const id = Number(tr.dataset.id || 0);
      const rec = locState.rows.find(x=>Number(x.id)===id);
      if (!rec) return;
      locState.selectedId = id;
      document.querySelectorAll('.loc-row.active').forEach(el=>el.classList.remove('active'));
      tr.classList.add('active');
      document.getElementById('locId').value = rec.id || '';
      document.getElementById('locCode').value = rec.code || '';
      document.getElementById('locName').value = rec.name || '';
      document.getElementById('locAddress').value = rec.address || '';
      clearPlaceResults();
      const lat = Number(rec.latitude);
      const lng = Number(rec.longitude);
      if (Number.isFinite(lat) && Number.isFinite(lng)) {
        setLocPin(lat, lng);
        ensureLocMap().setView([lat, lng], 14);
      } else {
        document.getElementById('locLat').value = '';
        document.getElementById('locLng').value = '';
      }
      if (canEditLocations) document.getElementById('locDeleteBtn')?.removeAttribute('disabled');
    });
  });
}

async function loadLocationManager(){
  const res = await fetch('../api/locations_list.php', { credentials:'same-origin' });
  const raw = await res.text();
  if (!res.ok) {
    alert('Failed to load locations list');
    console.error(raw);
    return;
  }
  locState.rows = JSON.parse(raw) || [];
  renderLocRows(locState.rows);
}

async function findPlaceAndPin(){
  const q = String(document.getElementById('locPlaceQ').value || '').trim();
  const address = String(document.getElementById('locAddress').value || '').trim();
  const full = q || address;
  if (!full) return;
  const hits = await geocodeMany(full + ', Philippines', 6);
  renderPlaceResults(hits);
  if (!hits.length) {
    alert('Place not found in the Philippines. Try a clearer PH address.');
    return;
  }
  const p = hits[0];
  setLocPin(p.lat, p.lng);
  ensureLocMap().setView([p.lat, p.lng], 15);
}

async function saveLocation(){
  if (!canEditLocations) return;
  const lat = Number(document.getElementById('locLat').value);
  const lng = Number(document.getElementById('locLng').value);
  if ((document.getElementById('locLat').value || document.getElementById('locLng').value) && !isPhPoint({lat, lng})) {
    alert('Latitude/longitude must be inside Philippines bounds.');
    return;
  }
  const form = document.getElementById('locMgrForm');
  const fd = new FormData(form);
  const res = await fetch('../api/locations_save.php', { method:'POST', body:fd, credentials:'same-origin' });
  const raw = await res.text();
  if (!res.ok) {
    let msg = 'Save failed';
    try { const j = JSON.parse(raw); if (j?.err) msg = j.err; } catch (e) {}
    alert(msg);
    console.error(raw);
    return;
  }
  toast('Location saved', 'success');
  await loadLocations();
  await loadLocationManager();
}

async function deleteLocation(){
  if (!canEditLocations) return;
  const id = Number(document.getElementById('locId').value || 0);
  if (!id) return;
  if (!confirm('Delete this location?')) return;
  const body = new URLSearchParams();
  body.set('id', String(id));
  const res = await fetch('../api/locations_delete.php', {
    method:'POST',
    credentials:'same-origin',
    headers:{ 'Content-Type':'application/x-www-form-urlencoded' },
    body: body.toString()
  });
  const raw = await res.text();
  if (!res.ok) {
    let msg = 'Delete failed';
    try { const j = JSON.parse(raw); if (j?.err) msg = j.err; } catch (e) {}
    alert(msg);
    console.error(raw);
    return;
  }
  toast('Location deleted', 'success');
  resetLocForm();
  await loadLocations();
  await loadLocationManager();
}

document.getElementById('mdlLocations')?.addEventListener('shown.bs.modal', async ()=>{
  ensureLocMap();
  resetLocForm();
  await loadLocationManager();
  setTimeout(()=> ensureLocMap().invalidateSize(), 120);
});
document.getElementById('locSearch')?.addEventListener('input', ()=> renderLocRows(locState.rows));
document.getElementById('locPlaceBtn')?.addEventListener('click', findPlaceAndPin);
document.getElementById('locPlaceQ')?.addEventListener('keydown', (e)=>{
  if (e.key === 'Enter') {
    e.preventDefault();
    findPlaceAndPin();
  }
});
document.getElementById('locResetBtn')?.addEventListener('click', resetLocForm);
document.getElementById('locNewBtn')?.addEventListener('click', resetLocForm);
document.getElementById('locLat')?.addEventListener('change', ()=>{
  const lat = Number(document.getElementById('locLat').value);
  const lng = Number(document.getElementById('locLng').value);
  if (Number.isFinite(lat) && Number.isFinite(lng)) {
    setLocPin(lat, lng);
    ensureLocMap().setView([lat, lng], 15);
  }
});
document.getElementById('locLng')?.addEventListener('change', ()=>{
  const lat = Number(document.getElementById('locLat').value);
  const lng = Number(document.getElementById('locLng').value);
  if (Number.isFinite(lat) && Number.isFinite(lng)) {
    setLocPin(lat, lng);
    ensureLocMap().setView([lat, lng], 15);
  }
});
document.getElementById('locSaveBtn')?.addEventListener('click', saveLocation);
document.getElementById('locDeleteBtn')?.addEventListener('click', deleteLocation);






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
  <script src="../../js/profile-dropdown.js"></script>
</body>
</html>
