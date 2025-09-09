<?php
// all-modules-admin-access/accessControl.php
$inc = __DIR__ . "/../includes";
if (file_exists($inc . "/config.php"))  require_once $inc . "/config.php";
if (file_exists($inc . "/auth.php"))    require_once $inc . "/auth.php";
if (function_exists("require_login"))   require_login();
if (function_exists("require_role"))    require_role(['admin']);

$active = 'settings_access';

$userName = $_SESSION["user"]["name"] ?? "Admin";
$userRole = $_SESSION["user"]["role"] ?? "Admin";
$BASE = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Access Control | Admin</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="<?= $BASE ?>/css/style.css" rel="stylesheet"/>
  <link href="<?= $BASE ?>/css/modules.css" rel="stylesheet"/>

  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>

  <style>
    .table-sm th, .table-sm td{ vertical-align: middle; }
    .search-input{ max-width: 340px; }
  </style>
</head>
<body>
<div class="container-fluid p-0">
  <div class="row g-0">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="col main-content p-3 p-lg-4">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-3">
          <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2">
            <ion-icon name="menu-outline"></ion-icon>
          </button>
          <h2 class="m-0">Access Control</h2>
        </div>
        <div class="d-flex align-items-center gap-2">
          <img src="<?= $BASE ?>/img/profile.jpg" class="rounded-circle" width="36" height="36" alt="">
          <div class="small">
            <strong><?= htmlspecialchars($userName) ?></strong><br/>
            <span class="text-muted"><?= htmlspecialchars($userRole) ?></span>
          </div>
        </div>
      </div>

      <!-- Users & Roles -->
      <section class="card shadow-sm">
        <div class="card-body">
          <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
            <div class="input-group search-input">
              <span class="input-group-text"><ion-icon name="search-outline"></ion-icon></span>
              <input id="uSearch" type="search" class="form-control" placeholder="Search users by name or email">
            </div>
            <div class="ms-auto d-flex gap-2">
              <button id="btnRefreshUsers" class="btn btn-outline-secondary">
                <ion-icon name="refresh-outline"></ion-icon> Refresh
              </button>
            </div>
          </div>

          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead>
                <tr>
                  <th style="width:36px"></th>
                  <th>Name</th>
                  <th>Email</th>
                  <th style="width:220px">Role</th>
                </tr>
              </thead>
              <tbody id="uBody">
                <tr><td colspan="4" class="text-center py-4">Loading…</td></tr>
              </tbody>
            </table>
          </div>

          <div class="d-flex justify-content-between align-items-center mt-2">
            <div class="small text-muted" id="uPageInfo"></div>
            <nav><ul class="pagination pagination-sm mb-0" id="uPager"></ul></nav>
          </div>
        </div>
      </section>
    </div>
  </div>
</div>

<!-- Toasts -->
<div class="toast-container position-fixed top-0 end-0 p-3" id="toasts" style="z-index:1080"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const API_BASE = './api';
const api = {
  users_list: API_BASE + '/users_list.php',
  set_role:   API_BASE + '/user_set_role.php'
};


const $ = (s,r=document)=>r.querySelector(s);
async function fetchJSON(u,o={}){const r=await fetch(u,o);if(!r.ok)throw new Error(await r.text()||r.statusText);return r.json();}
function parseErr(e){try{const j=JSON.parse(e.message);if(j.error)return j.error;}catch{}return e.message||'Request failed';}
function toast(msg,variant='success',delay=2200){
  let w=document.getElementById('toasts');
  if(!w){w=document.createElement('div');w.id='toasts';w.className='toast-container position-fixed top-0 end-0 p-3';w.style.zIndex=1080;document.body.appendChild(w);}
  const el=document.createElement('div');el.className=`toast text-bg-${variant} border-0`;
  el.innerHTML=`<div class="d-flex"><div class="toast-body">${msg}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
  w.appendChild(el);const t=new bootstrap.Toast(el,{delay});t.show();el.addEventListener('hidden.bs.toast',()=>el.remove());
}

let uState = { page:1, q:'', perPage:10 };

async function loadUsers(){
  const qs = new URLSearchParams({ page:uState.page, q:uState.q, perPage:uState.perPage });
  const { data, roles, pagination } = await fetchJSON(api.users_list + '?' + qs.toString());

  const tbody = document.getElementById('uBody');
  tbody.innerHTML = (data||[]).length ? data.map(u=>`
    <tr>
      <td><ion-icon name="person-circle-outline"></ion-icon></td>
      <td>${escapeHtml(u.name||'-')}</td>
      <td>${escapeHtml(u.email||'-')}</td>
      <td>
        <div class="input-group input-group-sm">
          <select class="form-select form-select-sm" id="role_${u.id}">
            ${mkOptions(roles, u.role)}
          </select>
          <button class="btn btn-outline-primary" onclick="saveRole(${u.id})">
            <ion-icon name="save-outline"></ion-icon>
          </button>
        </div>
      </td>
    </tr>
  `).join('') : `<tr><td colspan="4" class="text-center py-4 text-muted">No users found.</td></tr>`;

  const { page, perPage, total } = pagination || {page:1, perPage:uState.perPage, total:0};
  const totalPages = Math.max(1, Math.ceil(total/perPage));
  document.getElementById('uPageInfo').textContent = `Page ${page} of ${totalPages} • ${total} result(s)`;
  const pager = document.getElementById('uPager'); pager.innerHTML='';
  const li=(p,l=p,d=false,a=false)=>`<li class="page-item ${d?'disabled':''} ${a?'active':''}">
    <a class="page-link" href="#" onclick="uGo(${p});return false;">${l}</a></li>`;
  pager.insertAdjacentHTML('beforeend', li(page-1,'&laquo;', page<=1));
  for(let p=Math.max(1,page-2); p<=Math.min(totalPages,page+2); p++) pager.insertAdjacentHTML('beforeend', li(p,p,false,p===page));
  pager.insertAdjacentHTML('beforeend', li(page+1,'&raquo;', page>=totalPages));
}

function mkOptions(roles, current){
  return (roles||[]).map(r =>
    `<option value="${escapeAttr(r.name)}"${r.name===current ? ' selected' : ''}>${escapeHtml(r.name)}</option>`
  ).join('');
}



window.saveRole = async (userId)=>{
  const sel = document.getElementById('role_'+userId);
  const role = sel?.value || '';
  try{
    await fetchJSON(api.set_role, {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({ id:userId, role })
    });
    toast('Role updated');
  }catch(e){
    alert(parseErr(e));
  }
};

window.uGo = (p)=>{ if(!p||p<1) return; uState.page=p; loadUsers().catch(e=>alert(parseErr(e))); };
document.getElementById('btnRefreshUsers').addEventListener('click', ()=>loadUsers().catch(e=>alert(parseErr(e))));
document.getElementById('uSearch').addEventListener('input', (e)=>{ uState.q = e.target.value.trim(); uState.page=1; loadUsers().catch(e=>alert(parseErr(e))); });

function escapeHtml(s){ return (s??'').toString().replace(/[&<>"]/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m])); }
function escapeAttr(s){ return (s??'').toString().replace(/"/g,'&quot;'); }

document.addEventListener('DOMContentLoaded', ()=>{ loadUsers().catch(e=>alert(parseErr(e))); });
</script>
</body>
</html>
