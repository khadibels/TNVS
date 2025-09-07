<?php
// procurement/settings.php (Departments instead of Categories)
$inc = __DIR__ . "/../includes";
if (file_exists($inc . "/config.php")) {
    require_once $inc . "/config.php";
}
if (file_exists($inc . "/auth.php")) {
    require_once $inc . "/auth.php";
}
if (function_exists("require_login")) {
    require_login();
}

require_role(['admin', 'proc_officer']);

$userName = "Procurement User";
$userRole = "Procurement";
if (function_exists("current_user")) {
    $u = current_user();
    $userName = $u["name"] ?? $userName;
    $userRole = $u["role"] ?? $userRole;
}

function safe_fetch_all(PDO $pdo, string $sql, array $params = [])
{
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

$departments = isset($pdo)
    ? safe_fetch_all(
        $pdo,
        "SELECT id,name,is_active FROM departments ORDER BY name"
    )
    : [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>RFQs & Sourcing | Procurement</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="../css/style.css" rel="stylesheet" />
  <link href="../css/modules.css" rel="stylesheet" />
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <script src="../js/sidebar-toggle.js"></script>
</head>
<body>
<div class="container-fluid p-0">
  <div class="row g-0">
    
    
    <!-- Sidebar -->
    <div class="sidebar d-flex flex-column">
      <div class="d-flex justify-content-center align-items-center mb-4 mt-3">
        <img src="../img/logo.png" id="logo" class="img-fluid me-2" style="height:55px" alt="Logo">
      </div>
      <h6 class="text-uppercase mb-2">Procurement</h6>
      <nav class="nav flex-column px-2 mb-4">
        <a class="nav-link" href="./procurementDashboard.php"><ion-icon name="home-outline"></ion-icon><span>Dashboard</span></a>
        <a class="nav-link" href="./supplierManagement.php"><ion-icon name="person-outline"></ion-icon><span>Supplier Management</span></a>
        <a class="nav-link" href="./rfqManagement.php"><ion-icon name="mail-open-outline"></ion-icon><span>RFQs & Sourcing</span></a>
        <a class="nav-link" href="./purchaseOrders.php"><ion-icon name="document-text-outline"></ion-icon><span>Purchase Orders</span></a>
        <a class="nav-link" href="./procurementRequests.php"><ion-icon name="clipboard-outline"></ion-icon><span>Procurement Requests</span></a>
        <a class="nav-link" href="./inventoryView.php"><ion-icon name="archive-outline"></ion-icon><span>Inventory Management</span></a>
        <a class="nav-link" href="./budgetReports.php"><ion-icon name="analytics-outline"></ion-icon><span>Budget & Reports</span></a>
        <a class="nav-link active" href="./settings.php"><ion-icon name="settings-outline"></ion-icon><span>Settings</span></a>
      </nav>
      <div class="logout-section">
        <a class="nav-link text-danger" href="<?= defined("BASE_URL")
            ? BASE_URL
            : "#" ?>/auth/logout.php">
          <ion-icon name="log-out-outline"></ion-icon> Logout
        </a>
      </div>
    </div>

    <!-- Main -->
    <div class="col main-content p-3 p-lg-4">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-3">
          <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2" aria-label="Toggle sidebar">
            <ion-icon name="menu-outline"></ion-icon>
          </button>
          <h2 class="m-0">Settings</h2>
        </div>
        <div class="d-flex align-items-center gap-2">
          <img src="../img/profile.jpg" class="rounded-circle" width="36" height="36" alt="">
          <div class="small">
            <strong><?= htmlspecialchars($userName) ?></strong><br/>
            <span class="text-muted"><?= htmlspecialchars($userRole) ?></span>
          </div>
        </div>
      </div>

      <!-- Departments Section -->
      <div class="card shadow-sm mb-3">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h5 class="card-title m-0">Departments</h5>
            <button class="btn btn-violet btn-sm" onclick="openDeptModal()">Add Department</button>
          </div>
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead><tr><th>Name</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
              <tbody id="deptBody">
                <?php if ($departments):
                    foreach ($departments as $d): ?>
                <tr data-id="<?= (int) $d["id"] ?>">
                  <td class="fw-semibold"><?= htmlspecialchars(
                      $d["name"]
                  ) ?></td>
                  <td><?= $d["is_active"]
                      ? '<span class="badge bg-success">Active</span>'
                      : '<span class="badge bg-secondary">Inactive</span>' ?></td>
                  <td class="text-end">
                    <button class="btn btn-sm btn-outline-secondary me-1" onclick='editDept(<?= (int) $d[
                        "id"
                    ] ?>, <?= json_encode($d) ?>)'>Edit</button>
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteDept(<?= (int) $d[
                        "id"
                    ] ?>)">Delete</button>
                  </td>
                </tr>
                <?php endforeach;
                else:
                     ?>
                <tr><td colspan="3" class="text-center text-muted py-3">No departments yet.</td></tr>
                <?php
                endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- Department Modal -->
<div class="modal fade" id="mdlDept" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="deptForm">
        <div class="modal-header">
          <h5 class="modal-title" id="deptTitle">Add Department</h5>
          <button class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" id="deptId">
          <div class="mb-3">
            <label class="form-label">Name</label>
            <input type="text" name="name" id="deptName" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Active?</label>
            <select name="is_active" id="deptActive" class="form-select">
              <option value="1">Active</option>
              <option value="0">Inactive</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-outline-secondary" data-bs-dismiss="modal" type="button">Cancel</button>
          <button class="btn btn-primary" type="submit">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const $ = (s,r=document)=>r.querySelector(s);
async function fetchJSON(url,opts={}){const res=await fetch(url,opts);const t=await res.text();try{return JSON.parse(t);}catch{return {error:t}}}

function openDeptModal(){
  $('#deptTitle').textContent='Add Department';
  $('#deptId').value=''; $('#deptName').value=''; $('#deptActive').value='1';
  bootstrap.Modal.getOrCreateInstance('#mdlDept').show();
}
function editDept(id,row){
  $('#deptTitle').textContent='Edit Department';
  $('#deptId').value=id;
  $('#deptName').value=row.name||'';
  $('#deptActive').value=row.is_active||'0';
  bootstrap.Modal.getOrCreateInstance('#mdlDept').show();
}
async function deleteDept(id){
  if(!confirm('Delete this department?')) return;
  await fetchJSON('./api/settings_save_dept.php',{method:'POST',body:new URLSearchParams({id,delete:'1'})});
  location.reload();
}

document.getElementById('deptForm').addEventListener('submit',async ev=>{
  ev.preventDefault();
  const fd=new FormData(ev.target);
  await fetchJSON('./api/settings_save_dept.php',{method:'POST',body:fd});
  bootstrap.Modal.getInstance('#mdlDept')?.hide();
  location.reload();
});
</script>
</body>
</html>
