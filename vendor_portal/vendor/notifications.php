<?php
// File: vendor_portal/vendor/notifications.php
require_once __DIR__ . "/../../includes/config.php";
require_once __DIR__ . "/../../includes/auth.php";
require_once __DIR__ . "/../../includes/db.php";
require_login();

$section = 'vendor';
$active  = 'notifications';

$user       = current_user();
$vendorName = $user['company_name'] ?? ($user['name'] ?? 'Vendor');
$BASE       = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';

function vendor_avatar_url(): string {
  $base = rtrim(BASE_URL, '/');
  $id   = (int)($_SESSION['user']['vendor_id'] ?? 0);
  if ($id <= 0) return $base . '/img/profile.jpg';
  $root = realpath(__DIR__ . '/../../');
  $uploadDir = $root . "/vendor_portal/vendor/uploads";
  foreach (['jpg','jpeg','png','webp'] as $ext) {
    $files = glob($uploadDir . "/vendor_{$id}_*.{$ext}");
    if ($files && file_exists($files[0])) {
      $relPath = str_replace($root, '', $files[0]);
      return $base . $relPath;
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
<title>Notifications | Vendor Portal</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
<link href="<?= $BASE ?>/css/style.css" rel="stylesheet" />
<link href="<?= $BASE ?>/css/modules.css" rel="stylesheet" />
<link href="<?= $BASE ?>/css/vendor_portal_saas.css" rel="stylesheet" />
<script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
<script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
<script src="<?= $BASE ?>/js/sidebar-toggle.js"></script>
<style>
  .card{border-radius:16px}
</style>
</head>
<body class="vendor-saas">
<div class="container-fluid p-0">
  <div class="row g-0">
    <?php include __DIR__ . '/../../includes/sidebar.php'; ?>

    <div class="col main-content p-4">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-3">
          <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2">
            <ion-icon name="menu-outline"></ion-icon>
          </button>
          <h2 class="m-0 d-flex align-items-center gap-2 page-title">
            <ion-icon name="notifications-outline"></ion-icon> Notifications
          </h2>
        </div>
        <div class="profile-menu" data-profile-menu>
          <button class="profile-trigger" type="button" data-profile-trigger>
            <img src="<?= vendor_avatar_url() ?>" class="rounded-circle" width="36" height="36" alt="">
            <div class="profile-text">
              <div class="profile-name"><?= htmlspecialchars($vendorName, ENT_QUOTES, 'UTF-8') ?></div>
              <div class="profile-role">vendor</div>
            </div>
            <ion-icon class="profile-caret" name="chevron-down-outline"></ion-icon>
          </button>
          <div class="profile-dropdown" data-profile-dropdown role="menu">
            <a href="<?= $BASE ?>/vendor_portal/vendor/notifications.php" role="menuitem">Notifications</a>
            <a href="<?= $BASE ?>/auth/logout.php" role="menuitem">Sign out</a>
          </div>
        </div>
      </div>

      <div class="card shadow-sm">
        <div class="card-body" id="wrap"><div class="text-center text-muted py-5">Loading…</div></div>
      </div>
    </div>
  </div>
</div>

<script>
const apiList = '<?= $BASE ?>/vendor_portal/vendor/api/notifications_list.php';
const apiMark = '<?= $BASE ?>/vendor_portal/vendor/api/notifications_mark.php';
const wrap    = document.getElementById('wrap');

async function fetchJSON(u,o){const r=await fetch(u,o); if(!r.ok) throw new Error(await r.text()); return r.json();}
function esc(s){return String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));}

async function load(){
  try{
    const j = await fetchJSON(apiList);
    if(!j.data || !j.data.length){
      wrap.innerHTML = `<div class="text-center text-muted py-5">No notifications.</div>`;
      return;
    }
    wrap.innerHTML = j.data.map(n=>`
      <div class="d-flex align-items-start border-bottom py-3">
        <div class="me-3 mt-1">${n.is_read? '' : '<span class="badge bg-danger">New</span>'}</div>
        <div class="flex-grow-1">
          <div class="fw-semibold">${esc(n.title)}</div>
          <div class="text-muted small">${esc(n.body||'')}</div>
          <div class="small text-secondary mt-1">${new Date(n.created_at).toLocaleString()}</div>
          ${n.rfq_id ? `<div class="mt-2">
            <a class="btn btn-sm btn-primary" href="./rfqs.php#open=${n.rfq_id}" data-open="${n.id},${n.rfq_id}">
              <ion-icon name="open-outline"></ion-icon> Open RFQ
            </a>
          </div>`:''}
        </div>
      </div>`).join('');
  }catch(e){
    wrap.innerHTML = `<div class="alert alert-danger">${esc(e.message)}</div>`;
  }
}

document.addEventListener('click', async (e)=>{
  const a = e.target.closest('a[data-open]');
  if(!a) return;
  const [nid, rfq] = a.getAttribute('data-open').split(',');
  try{ await fetchJSON(apiMark, {method:'POST', body:new URLSearchParams({id:nid})}); }catch{}
  window.location.href = './rfqs.php#open='+rfq;
});

load();

const trigger = document.getElementById('profileTrigger');
const dropdown = document.getElementById('profileDropdown');
if (trigger && dropdown) {
  trigger.addEventListener('click', () => {
    const isOpen = dropdown.style.display === 'block';
    dropdown.style.display = isOpen ? 'none' : 'block';
    trigger.setAttribute('aria-expanded', String(!isOpen));
  });
  document.addEventListener('click', (e) => {
    if (!trigger.contains(e.target) && !dropdown.contains(e.target)) {
      dropdown.style.display = 'none';
      trigger.setAttribute('aria-expanded', 'false');
    }
  });
}
</script>
<script src="<?= $BASE ?>/js/profile-dropdown.js"></script>
</body>
</html>
