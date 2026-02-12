<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_login();

$pdo = db('proc'); if(!$pdo instanceof PDO){ http_response_code(500); die('DB'); }

$u = current_user();
$VENDOR_ID = (int)($u['vendor_id'] ?? 0);
if ($VENDOR_ID <= 0) { http_response_code(403); die('No vendor'); }
$vendorName = $u['company_name'] ?? ($u['name'] ?? 'Vendor');
$BASE = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';

$vendorInfo = [
  'company_name' => $vendorName,
  'contact_person' => $u['name'] ?? '',
  'phone' => $u['phone'] ?? '',
  'address' => ''
];
try {
  $st = $pdo->prepare("SELECT company_name, contact_person, phone, address FROM vendors WHERE id=? LIMIT 1");
  $st->execute([$VENDOR_ID]);
  if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $vendorInfo = array_merge($vendorInfo, array_filter($row, fn($v)=>$v!==null));
  }
} catch (Throwable $e) { }

function vendor_avatar_url(): string {
  $base = rtrim(BASE_URL, '/'); $id = (int)($_SESSION['user']['vendor_id'] ?? 0);
  if ($id <= 0) return $base . '/img/profile.jpg';
  $root = realpath(__DIR__ . '/../../'); $dir = $root . "/vendor_portal/vendor/uploads";
  foreach (['jpg','jpeg','png','webp'] as $ext) {
    $files = glob($dir . "/vendor_{$id}_*.{$ext}");
    if ($files && file_exists($files[0])) return $base . str_replace($root, '', $files[0]);
  }
  return $base . '/img/profile.jpg';
}

$section = 'vendor';
$active = 'vendor_shipments';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Shipment Request | Vendor Portal</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
<link href="<?= $BASE ?>/css/style.css" rel="stylesheet" />
<link href="<?= $BASE ?>/css/modules.css" rel="stylesheet" />
<link href="<?= $BASE ?>/css/vendor_portal_saas.css" rel="stylesheet" />
<script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
<script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
<style>
  body{background:#f6f7fb}
  .main-content{padding:1.25rem} @media(min-width:992px){.main-content{padding:2rem}}
  .card{border-radius:16px}
  .form-text{font-size:.8rem}
</style>
</head>
<body class="vendor-saas">
<div class="container-fluid p-0">
  <div class="row g-0">
    <?php include __DIR__ . '/../../includes/sidebar.php'; ?>
    <div class="col main-content">

      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-3">
          <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2" aria-label="Toggle sidebar">
            <ion-icon name="menu-outline"></ion-icon>
          </button>
          <h2 class="m-0 d-flex align-items-center gap-2 page-title">
            <ion-icon name="paper-plane-outline"></ion-icon> Shipment Request
          </h2>
        </div>
        <div class="profile-menu" data-profile-menu>
          <button class="profile-trigger" type="button" data-profile-trigger>
            <img src="<?= htmlspecialchars(vendor_avatar_url(), ENT_QUOTES) ?>" class="rounded-circle" width="36" height="36" alt="">
            <div class="profile-text">
              <div class="profile-name"><?= htmlspecialchars($vendorName, ENT_QUOTES) ?></div>
              <div class="profile-role">vendor</div>
            </div>
            <ion-icon class="profile-caret" name="chevron-down-outline"></ion-icon>
          </button>
          <div class="profile-dropdown" data-profile-dropdown role="menu">
            <a href="<?= $BASE ?>/vendor_portal/vendor/notifications.php" role="menuitem">Notifications</a>
            <a href="<?= u('auth/logout.php') ?>" role="menuitem">Sign out</a>
          </div>
        </div>
      </div>

      <section class="card shadow-sm mb-3">
        <div class="card-body">
          <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
            <div>
              <h5 class="mb-1">Create a shipment request</h5>
              <div class="text-muted small">Submit pickup details. This appears in Track Shipments for dispatch; destination is assigned by Procurement/Warehouse.</div>
            </div>
            <span class="badge bg-info-subtle text-info">Vendor → Dispatch</span>
          </div>
          <hr class="my-3">

          <form id="reqForm" class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Purchase Order</label>
              <select id="poSelect" name="po_id" class="form-select" required>
                <option value="">Loading POs…</option>
              </select>
              <div class="form-text">Only accepted POs can be submitted for pickup request.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Destination Warehouse</label>
              <input class="form-control" value="Assigned by Procurement / Warehouse" disabled>
              <div class="form-text">Procurement or Warehouse will assign the destination warehouse.</div>
            </div>

            <div class="col-md-7">
              <label class="form-label">Pickup Address</label>
              <textarea class="form-control" name="pickup_address" rows="2" required><?= htmlspecialchars((string)($vendorInfo['address'] ?? ''), ENT_QUOTES) ?></textarea>
            </div>
            <div class="col-md-5">
              <label class="form-label">Pickup Contact Person</label>
              <input class="form-control" name="pickup_contact_name" value="<?= htmlspecialchars((string)($vendorInfo['contact_person'] ?? ''), ENT_QUOTES) ?>" required>
              <div class="form-text">Who should Log 2 contact on pickup?</div>
            </div>

            <div class="col-md-4">
              <label class="form-label">Pickup Contact Phone</label>
              <input class="form-control" name="pickup_contact_phone" value="<?= htmlspecialchars((string)($vendorInfo['phone'] ?? ''), ENT_QUOTES) ?>" required>
            </div>
            <div class="col-12">
              <label class="form-label">Notes</label>
              <textarea class="form-control" name="notes" rows="3" placeholder="Any special handling or schedule notes"></textarea>
            </div>

            <div class="col-12 d-flex justify-content-end">
              <button type="submit" class="btn btn-primary">
                <ion-icon name="paper-plane-outline" class="me-1"></ion-icon> Submit Request
              </button>
            </div>
          </form>
        </div>
      </section>

      <div class="toast-container position-fixed top-0 end-0 p-3" id="toasts" style="z-index:1080"></div>

    </div>
  </div>
</div>

<script>
const BASE = '<?= $BASE ?>';
const API = {
  poList: BASE + '/vendor_portal/vendor/api/po/list.php',
  create: BASE + '/vendor_portal/vendor/api/shipments/create.php',
  notis: BASE + '/vendor_portal/vendor/api/notifications_list.php'
};

const $ = (s)=>document.querySelector(s);
const esc = (s)=>String(s).replace(/[&<>"]/g, c=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;"}[c]));

async function fetchJSON(url, opts={}){
  const res = await fetch(url, {credentials:'same-origin', ...opts});
  const text = await res.text();
  let data; try{ data = JSON.parse(text); }catch(e){ throw new Error('Bad JSON'); }
  if(!res.ok) throw new Error(data.error || 'Request failed');
  return data;
}

function toast(msg, type='success'){
  const wrap = document.getElementById('toasts');
  const el = document.createElement('div');
  el.className='toast align-items-center text-bg-'+type+' border-0 show';
  el.role='alert';
  el.innerHTML = `<div class="d-flex"><div class="toast-body">${esc(msg)}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
  wrap.appendChild(el); setTimeout(()=>el.remove(), 2300);
}

async function loadPOs(){
  const sel = document.getElementById('poSelect');
  sel.innerHTML = '<option value="">Loading…</option>';
  try{
    const url = new URL(API.poList, window.location.href);
    url.searchParams.set('per','200');
    url.searchParams.set('status','accepted');
    const j = await fetchJSON(url.toString());
    const rows = j.data || [];
    if(!rows.length){
      sel.innerHTML = '<option value="">No accepted POs found</option>';
      return;
    }
    sel.innerHTML = '<option value="">Select PO…</option>' + rows.map(r=>{
      const title = r.title ? ` • ${r.title}` : '';
      return `<option value="${r.id}">${esc(r.po_no)}${esc(title)}</option>`;
    }).join('');
  }catch(e){
    sel.innerHTML = '<option value="">Failed to load POs</option>';
  }
}


document.getElementById('reqForm').addEventListener('submit', async (e)=>{
  e.preventDefault();
  const fd = new FormData(e.currentTarget);
  try{
    const res = await fetchJSON(API.create, {method:'POST', body:fd});
    toast('Shipment request submitted');
    e.currentTarget.reset();
    await loadPOs();
  }catch(err){
    toast(err.message || 'Failed', 'danger');
  }
});

(async function refreshNotis(){
  try{
    const j = await fetchJSON(API.notis);
    const c = Number(j.unread||0);
    const el = document.getElementById('notifCount');
    el.textContent = c>99 ? '99+' : c;
    el.classList.toggle('d-none', c<=0);
  }catch(e){}
})();

loadPOs();
</script>
<script src="<?= $BASE ?>/js/profile-dropdown.js"></script>
</body>
</html>
