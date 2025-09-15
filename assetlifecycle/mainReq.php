<?php
require_once __DIR__ . "/../includes/config.php";
require_once __DIR__ . "/../includes/auth.php";
require_login();
require_role(['admin', 'asset_manager']);

require_once __DIR__ . "/../includes/db.php";

$pdo = db('alms');
if (!$pdo) {
  http_response_code(500);
  exit('Database connect failed for ALMS. Check DB name/user/pass in config.php and CyberPanel privileges.');
}


$section = 'alms';
$active = 'requests';


if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$UPLOAD_DIR = __DIR__ . '/uploads/';
$UPLOAD_URL = 'uploads/';

function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

/* ---------- DB bootstrap ---------- */
function ensureRequestsTable(PDO $pdo){
  $pdo->exec("CREATE TABLE IF NOT EXISTS maintenance_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id INT NULL,
    asset_name VARCHAR(255) NOT NULL,
    -- `type` is optional in some installs; we will INSERT conditionally
    type VARCHAR(100) NULL,
    issue_description TEXT NOT NULL,
    priority_level ENUM('Low','Normal','High') NOT NULL DEFAULT 'Normal',
    status ENUM('Pending','In Progress','Completed','Cancelled') NOT NULL DEFAULT 'Pending',
    reported_by VARCHAR(120) NULL,
    assigned_to INT NULL,
    parts_used TEXT NULL,
    cost DECIMAL(12,2) NULL DEFAULT 0,
    attachment VARCHAR(255) NULL,
    date_reported TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completion_date DATETIME NULL,
    remarks TEXT NULL,
    INDEX (assigned_to), INDEX (status), INDEX (priority_level), INDEX (date_reported)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function ensureTechniciansTable(PDO $pdo){
  $pdo->exec("CREATE TABLE IF NOT EXISTS technicians (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    phone VARCHAR(50) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $cnt = (int)$pdo->query("SELECT COUNT(*) FROM technicians")->fetchColumn();
  if ($cnt === 0) $pdo->exec("INSERT INTO technicians (name,phone) VALUES ('Tech A',''),('Tech B',''),('Tech C','')");
}
function ensureHistoryTable(PDO $pdo){
  $pdo->exec("CREATE TABLE IF NOT EXISTS maintenance_request_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL,
    action VARCHAR(50) NOT NULL,
    changes_json JSON NULL,
    actor VARCHAR(100) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (request_id),
    CONSTRAINT fk_mrh_request FOREIGN KEY (request_id) REFERENCES maintenance_requests(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
ensureRequestsTable($pdo);
ensureTechniciansTable($pdo);
ensureHistoryTable($pdo);

/* ---------- helpers ---------- */
$__RAW = file_get_contents('php://input');
$__JSON = [];
if (!empty($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
  $__JSON = json_decode($__RAW, true) ?: [];
}
function jpost($k,$d=null){ global $__JSON; return $_POST[$k] ?? ($__JSON[$k] ?? $d); }

function col_exists(PDO $pdo, string $table, string $col): bool {
  $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
  $q->execute([$table, $col]);
  return (bool)$q->fetchColumn();
}

function logHistory(PDO $pdo, int $reqId, string $action, array $changes=[], ?string $actor=null){
  $pdo->prepare("INSERT INTO maintenance_request_history (request_id,action,changes_json,actor) VALUES (:r,:a,:c,:act)")
      ->execute([':r'=>$reqId,':a'=>$action,':c'=>$changes?json_encode($changes,JSON_UNESCAPED_UNICODE):null,':act'=>$actor]);
}

/* ---------- API ---------- */
if (isset($_GET['action'])) {
  header('Content-Type: application/json; charset=utf-8');
  $action = $_GET['action'];

  try {

    if ($action === 'list') {
      $status   = $_GET['status']   ?? '';
      $priority = $_GET['priority'] ?? '';
      $sql = "SELECT mr.*, t.name AS technician_name
              FROM maintenance_requests mr
              LEFT JOIN technicians t ON mr.assigned_to=t.id
              WHERE 1=1";
      $p = [];
      if ($status)   { $sql.=" AND mr.status=:s";          $p[':s']=$status; }
      if ($priority) { $sql.=" AND mr.priority_level=:p";  $p[':p']=$priority; }
      $sql .= " ORDER BY mr.date_reported DESC LIMIT 200";
      $st = $pdo->prepare($sql);
      $st->execute($p);
      echo json_encode(['success'=>true,'data'=>$st->fetchAll(PDO::FETCH_ASSOC)]); exit;
    }

    if ($action === 'get_technicians') {
      $rows = $pdo->query("SELECT id,name FROM technicians WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
      echo json_encode(['success'=>true,'data'=>$rows]); exit;
    }

    if ($action === 'add') {
      $asset_name  = trim($_POST['asset_name'] ?? '');
      $asset_id    = $_POST['asset_id'] ?? null;
      $type        = trim($_POST['type'] ?? ''); // optional
      $desc        = trim($_POST['description'] ?? '');
      $priority    = $_POST['priority'] ?? 'Normal';
      $reported_by = trim($_POST['reported_by'] ?? '');
      $assigned_to = $_POST['assigned_to'] ?: null;
      if ($asset_name === '' || $desc === '') { echo json_encode(['success'=>false,'msg'=>'Asset and description required.']); exit; }

      // attachment (optional)
      $attachmentPath = null;
      if (!empty($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $ext  = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
        $safe = bin2hex(random_bytes(8)).($ext?'.'.$ext:'');
        if (!is_dir($UPLOAD_DIR)) @mkdir($UPLOAD_DIR,0755,true);
        if (!move_uploaded_file($_FILES['attachment']['tmp_name'],$UPLOAD_DIR.$safe)) {
          echo json_encode(['success'=>false,'msg'=>'Upload failed']); exit;
        }
        $attachmentPath = $UPLOAD_URL.$safe;
      }

      // insert: include `type` only if it exists
      $hasType = col_exists($pdo,'maintenance_requests','type');
      if ($hasType) {
        $sql = "INSERT INTO maintenance_requests
                (asset_id, asset_name, `type`, issue_description, priority_level, reported_by, assigned_to, attachment)
                VALUES (:aid, :an, :ty, :d, :pr, :rb, :asgn, :att)";
        $params = [
          ':aid'=>$asset_id ?: null, ':an'=>$asset_name, ':ty'=>($type!==''?$type:null),
          ':d'=>$desc, ':pr'=>$priority, ':rb'=>$reported_by ?: null, ':asgn'=>$assigned_to ?: null, ':att'=>$attachmentPath
        ];
      } else {
        $sql = "INSERT INTO maintenance_requests
                (asset_id, asset_name, issue_description, priority_level, reported_by, assigned_to, attachment)
                VALUES (:aid, :an, :d, :pr, :rb, :asgn, :att)";
        $params = [
          ':aid'=>$asset_id ?: null, ':an'=>$asset_name,
          ':d'=>$desc, ':pr'=>$priority, ':rb'=>$reported_by ?: null, ':asgn'=>$assigned_to ?: null, ':att'=>$attachmentPath
        ];
      }
      $stmt = $pdo->prepare($sql);
      $stmt->execute($params);
      $newId = (int)$pdo->lastInsertId();

      logHistory($pdo,$newId,'created',[
        'asset_name'=>['from'=>null,'to'=>$asset_name],
        'issue_description'=>['from'=>null,'to'=>$desc],
        'priority_level'=>['from'=>null,'to'=>$priority],
        'reported_by'=>['from'=>null,'to'=>$reported_by?:null],
        'assigned_to'=>['from'=>null,'to'=>$assigned_to?:null],
        'type'=>['from'=>null,'to'=>$type?:null]
      ], $reported_by ?: null);

      echo json_encode(['success'=>true,'msg'=>'Request added.','id'=>$newId]); exit;
    }

    if ($action === 'update') {
      $id = (int)jpost('id',0); if(!$id){ echo json_encode(['success'=>false,'msg'=>'Invalid ID']); exit; }
      $status = jpost('status','Pending');
      $assigned_to = jpost('assigned_to') ?: null;
      $parts_used = jpost('parts_used');
      $cost = jpost('cost',0);
      $completion_date = jpost('completion_date') ?: null;
      $remarks = jpost('remarks');
      if ($status==='Completed' && !$completion_date) $completion_date = date('Y-m-d H:i:s');

      $ps=$pdo->prepare("SELECT status,assigned_to,parts_used,cost,completion_date,remarks FROM maintenance_requests WHERE id=:i");
      $ps->execute([':i'=>$id]); $old=$ps->fetch(PDO::FETCH_ASSOC) ?: [];

      $pdo->prepare("UPDATE maintenance_requests SET
        status=:s, assigned_to=:a, parts_used=:p, cost=:c, completion_date=:cd, remarks=:r
        WHERE id=:i")->execute([
          ':s'=>$status, ':a'=>$assigned_to, ':p'=>$parts_used, ':c'=>$cost?:0,
          ':cd'=>$completion_date, ':r'=>$remarks, ':i'=>$id
        ]);

      $chg=[];
      foreach (['status'=>$status,'assigned_to'=>$assigned_to,'parts_used'=>$parts_used,'cost'=>$cost?:0,'completion_date'=>$completion_date,'remarks'=>$remarks] as $k=>$v){
        $o=$old[$k]??null; if($o===null && $v===null) continue; if((string)$o !== (string)$v) $chg[$k]=['from'=>$o,'to'=>$v];
      }
      if ($chg) logHistory($pdo,$id,'updated',$chg,null);

      echo json_encode(['success'=>true,'msg'=>'Request updated.']); exit;
    }

    if ($action === 'delete') {
      $id = (int)jpost('id',0); if(!$id){ echo json_encode(['success'=>false,'msg'=>'Invalid ID']); exit; }
      $r=$pdo->prepare("SELECT attachment FROM maintenance_requests WHERE id=:i"); $r->execute([':i'=>$id]);
      $row=$r->fetch(PDO::FETCH_ASSOC);
      if ($row && !empty($row['attachment'])) {
        $abs=__DIR__ . '/' . ltrim($row['attachment'],'/');
        if (is_file($abs)) @unlink($abs);
      }
      $pdo->prepare("DELETE FROM maintenance_requests WHERE id=:i")->execute([':i'=>$id]);
      echo json_encode(['success'=>true,'msg'=>'Deleted']); exit;
    }

    if ($action === 'history') {
      $rid = (int)($_GET['request_id'] ?? jpost('request_id',0));
      if(!$rid){ echo json_encode(['success'=>false,'msg'=>'Invalid request id']); exit; }
      $st=$pdo->prepare("SELECT id,action,changes_json,actor,created_at FROM maintenance_request_history WHERE request_id=:r ORDER BY created_at DESC, id DESC");
      $st->execute([':r'=>$rid]);
      $rows=$st->fetchAll(PDO::FETCH_ASSOC);
      foreach ($rows as &$row){ $row['changes']=$row['changes_json']?json_decode($row['changes_json'],true):null; unset($row['changes_json']); }
      echo json_encode(['success'=>true,'data'=>$rows]); exit;
    }

    echo json_encode(['success'=>false,'msg'=>'Unknown action']); exit;

  } catch (Throwable $e) {
    // always JSON on error
    http_response_code(500);
    echo json_encode(['success'=>false,'msg'=>'Server error','detail'=>$e->getMessage()]); exit;
  }
}

/* ---------- Page data ---------- */
$userName = $_SESSION["user"]["name"] ?? "Nicole Malitao";
$userRole = $_SESSION["user"]["role"] ?? "Warehouse Manager";

$total   = (int)$pdo->query("SELECT COUNT(*) FROM maintenance_requests")->fetchColumn();
$pending = (int)$pdo->query("SELECT COUNT(*) FROM maintenance_requests WHERE status='Pending'")->fetchColumn();
$inprog  = (int)$pdo->query("SELECT COUNT(*) FROM maintenance_requests WHERE status='In Progress'")->fetchColumn();
$done    = (int)$pdo->query("SELECT COUNT(*) FROM maintenance_requests WHERE status='Completed'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Maintenance Requests | TNVS</title>

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

      <?php include __DIR__ . '/../includes/sidebar.php' ?>

      <!-- Main Content -->
      <div class="col main-content p-3 p-lg-4">

        <!-- Topbar -->
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div class="d-flex align-items-center gap-3">
            <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2" aria-label="Toggle sidebar">
              <ion-icon name="menu-outline"></ion-icon>
            </button>
            <h2 class="m-0 d-flex align-items-center gap-2">
              <ion-icon name="layers-outline"></ion-icon>Maintenance Requests
            </h2>
          </div>

          <div class="d-flex align-items-center gap-2">
            <img src="../img/profile.jpg" class="rounded-circle" width="36" height="36" alt="">
            <div class="small">
              <strong><?= h($userName) ?></strong><br/>
              <span class="text-muted"><?= h($userRole) ?></span>
            </div>
          </div>
        </div>

        <!-- KPIs -->
        <section class="card shadow-sm mb-3">
          <div class="card-body">
            <div class="row g-3">
              <div class="col-6 col-md-3"><div class="text-muted small">Total</div><div class="fs-5 fw-semibold"><?= $total ?></div></div>
              <div class="col-6 col-md-3"><div class="text-muted small">Pending</div><div class="fs-5 fw-semibold"><?= $pending ?></div></div>
              <div class="col-6 col-md-3"><div class="text-muted small">In Progress</div><div class="fs-5 fw-semibold"><?= $inprog ?></div></div>
              <div class="col-6 col-md-3"><div class="text-muted small">Completed</div><div class="fs-5 fw-semibold"><?= $done ?></div></div>
            </div>
          </div>
        </section>

        <!-- Add Request -->
        <section class="card shadow-sm mb-3">
          <div class="card-body">
            <form id="addForm" enctype="multipart/form-data" class="row g-2 align-items-end">
              <div class="col-12 col-md-4">
                <label class="form-label small">Asset</label>
                <input class="form-control" name="asset_name" id="asset_name" placeholder="Asset name or ID" required>
              </div>
              <div class="col-6 col-md-2">
                <label class="form-label small">Priority</label>
                <select class="form-select" name="priority" id="priority">
                  <option value="Normal" selected>Normal</option>
                  <option value="Low">Low</option>
                  <option value="High">High</option>
                </select>
              </div>
              <div class="col-6 col-md-2">
                <label class="form-label small">Reported by</label>
                <input class="form-control" name="reported_by" id="reported_by" placeholder="Name">
              </div>
              <div class="col-12 col-md-3">
                <label class="form-label small">Assign to</label>
                <select class="form-select" name="assigned_to" id="assigned_to"><option value="">-- Select technician --</option></select>
              </div>
              <div class="col-12">
                <label class="form-label small">Issue description</label>
                <textarea class="form-control" name="description" id="description" required></textarea>
              </div>
              <div class="col-12 col-md-4">
                <input class="form-control" type="file" name="attachment" id="attachment" accept="image/*,.pdf,.doc,.docx">
              </div>
              <div class="col-12 col-md-3 d-grid">
                <button class="btn btn-violet" type="submit"><ion-icon name="add-circle-outline"></ion-icon> Submit</button>
              </div>
              <div class="col-12 col-md-2 d-grid">
                <button class="btn btn-outline-secondary" type="button" id="resetBtn">Reset</button>
              </div>
              <div class="col-12 small text-muted" id="formMessage"></div>
            </form>
          </div>
        </section>

        <!-- Filters -->
        <section class="card shadow-sm mb-3">
          <div class="card-body d-flex flex-wrap gap-2 align-items-end">
            <div>
              <label class="form-label small">Status</label>
              <select id="filterStatus" class="form-select">
                <option value="">All</option>
                <option>Pending</option><option>In Progress</option><option>Completed</option><option>Cancelled</option>
              </select>
            </div>
            <div>
              <label class="form-label small">Priority</label>
              <select id="filterPriority" class="form-select">
                <option value="">All</option>
                <option>Low</option><option>Normal</option><option>High</option>
              </select>
            </div>
            <button class="btn btn-outline-secondary" id="applyFilters">Apply</button>
            <button class="btn btn-outline-secondary" id="refreshBtn">Refresh</button>
            <button class="btn btn-violet" id="exportCsv"><ion-icon name="download-outline"></ion-icon> Export CSV</button>
          </div>
        </section>

        <!-- Table -->
        <section class="card shadow-sm">
          <div class="card-body">
            <h5 class="mb-3">Requests</h5>
            <div class="table-responsive">
              <table class="table align-middle">
                <thead class="sticky-th">
                  <tr>
                    <th>ID</th>
                    <th>Asset / Issue</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Assigned</th>
                    <th>Reported</th>
                    <th>Attachment</th>
                    <th class="text-end">Actions</th>
                  </tr>
                </thead>
                <tbody id="tblBody">
                  <tr><td colspan="8" class="text-center py-4 text-muted">Loading…</td></tr>
                </tbody>
              </table>
            </div>
            <div id="tableMsg" class="small text-muted mt-2"></div>
          </div>
        </section>

      </div><!-- /main -->
    </div>
  </div>

  <!-- Update Modal -->
  <div id="editor" class="modal fade" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <form id="updateForm">
          <div class="modal-header">
            <h5 class="modal-title">Update Request <span id="editId"></span></h5>
            <button class="btn-close" data-bs-dismiss="modal" type="button"></button>
          </div>
          <div class="modal-body row g-2">
            <input type="hidden" name="id" id="edit_id" />
            <div class="col-12 col-md-4">
              <label class="form-label small">Status</label>
              <select class="form-select" name="status" id="edit_status">
                <option>Pending</option><option>In Progress</option><option>Completed</option><option>Cancelled</option>
              </select>
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label small">Assign to</label>
              <select class="form-select" name="assigned_to" id="edit_assigned_to"><option value="">-- Select --</option></select>
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label small">Completion Date</label>
              <input type="datetime-local" class="form-control" name="completion_date" id="edit_completion_date" />
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label small">Cost</label>
              <input class="form-control" name="cost" id="edit_cost" placeholder="0.00" />
            </div>
            <div class="col-12">
              <label class="form-label small">Parts used</label>
              <input class="form-control" name="parts_used" id="edit_parts_used" />
            </div>
            <div class="col-12">
              <label class="form-label small">Remarks</label>
              <textarea class="form-control" name="remarks" id="edit_remarks"></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-violet" type="submit"><ion-icon name="save-outline"></ion-icon> Save</button>
            <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Close</button>
            <button class="btn btn-danger" type="button" id="deleteBtn"><ion-icon name="trash-outline"></ion-icon> Delete</button>
          </div>
          <div id="updateMsg" class="small text-muted px-3 pb-2"></div>
        </form>
      </div>
    </div>
  </div>

  <!-- History Modal -->
  <div id="historyModal" class="modal fade" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Request History <span id="historyId"></span></h5>
          <button class="btn-close" data-bs-dismiss="modal" type="button"></button>
        </div>
        <div class="modal-body">
          <div id="historyEmpty" class="text-muted small mb-2">No history.</div>
          <ul id="historyList" class="list-group small"></ul>
        </div>
        <div class="modal-footer">
          <button class="btn btn-outline-secondary" data-bs-dismiss="modal" type="button">Close</button>
        </div>
      </div>
    </div>
  </div>

<script>
/* ---------- tiny API wrapper that always returns JSON or detail ---------- */
async function api(action, data=null, isForm=false){
  const url = new URL(window.location.href);
  url.searchParams.set('action', action);

  const opts = { method: 'POST', credentials: 'same-origin' };
  if (data) {
    if (isForm) { opts.body = data; }
    else { opts.headers = { 'Content-Type': 'application/json' }; opts.body = JSON.stringify(data); }
  }

  const res = await fetch(url.toString(), opts);
  const text = await res.text();
  try { return JSON.parse(text); }
  catch { return { success:false, msg:'Server did not return JSON', detail:text.slice(0,300), _status:res.status }; }
}

function escapeHtml(s){return String(s||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
function priorityBadge(p){return {'High':'danger','Normal':'primary','Low':'success'}[p]||'secondary';}

/* ---------- technicians ---------- */
async function loadTechnicians(){
  const r = await api('get_technicians');
  const sel = document.getElementById('assigned_to');
  const editSel = document.getElementById('edit_assigned_to');
  sel.innerHTML = '<option value="">-- Select technician --</option>';
  editSel.innerHTML = '<option value="">-- Select --</option>';
  if (r.success && Array.isArray(r.data)) {
    r.data.forEach(t=>{
      const o = document.createElement('option');
      o.value = t.id; o.textContent = t.name;
      sel.appendChild(o.cloneNode(true));
      editSel.appendChild(o);
    });
  }
}

/* ---------- table ---------- */
function renderTable(rows){
  const tb=document.getElementById('tblBody');
  if(!rows.length){ tb.innerHTML='<tr><td colspan="8" class="text-center py-4 text-muted">No requests found</td></tr>'; return; }
  tb.innerHTML='';
  rows.forEach(r=>{
    const attach=r.attachment?`<a href="${r.attachment}" target="_blank">View</a>`:'';
    const assigned=r.technician_name??'';
    const tr=document.createElement('tr');
    tr.innerHTML=`
      <td>${r.id}</td>
      <td>
        <div class="fw-semibold">${escapeHtml(r.asset_name)}</div>
        <div class="text-muted small">${escapeHtml(r.issue_description||'').slice(0,80)}${(r.issue_description||'').length>80?'…':''}</div>
      </td>
      <td><span class="badge bg-${priorityBadge(r.priority_level)}">${r.priority_level}</span></td>
      <td>${r.status}</td>
      <td>${assigned}</td>
      <td class="small">${r.date_reported||''}</td>
      <td>${attach}</td>
      <td class="text-end">
        <button class="btn btn-sm btn-outline-primary" onclick="openEditor(${r.id})">Update</button>
        <button class="btn btn-sm btn-outline-secondary" onclick="openHistory(${r.id})">History</button>
      </td>`;
    tb.appendChild(tr);
  });
}

async function fetchList(){
  const status=document.getElementById('filterStatus').value;
  const priority=document.getElementById('filterPriority').value;
  const url=new URL(window.location.href);
  url.searchParams.set('action','list');
  if(status) url.searchParams.set('status',status);
  if(priority) url.searchParams.set('priority',priority);
  const res = await fetch(url.toString(), { credentials:'same-origin' });
  const txt = await res.text();
  let json; try { json = JSON.parse(txt); } catch { json = { success:false, detail:txt.slice(0,300) }; }
  if(json.success) renderTable(json.data);
  else document.getElementById('tableMsg').textContent = 'Load failed: ' + (json.detail || json.msg || 'Unknown');
}

/* ---------- add ---------- */
document.getElementById('addForm').addEventListener('submit',async e=>{
  e.preventDefault();
  const f=new FormData(e.target);
  const res=await api('add',f,true);
  document.getElementById('formMessage').textContent =
    (res.success ? 'Added' : (res.msg || 'Error')) + (res.detail ? ` — ${res.detail}` : '');
  if(res.success){ e.target.reset(); fetchList(); }
});
document.getElementById('resetBtn').addEventListener('click',()=>document.getElementById('addForm').reset());

/* ---------- filters/export ---------- */
document.getElementById('applyFilters').addEventListener('click',fetchList);
document.getElementById('refreshBtn').addEventListener('click',fetchList);
document.getElementById('exportCsv').addEventListener('click',async ()=>{
  const url=new URL(window.location.href); url.searchParams.set('action','list');
  const res=await fetch(url.toString(), { credentials:'same-origin' }); const json=await res.json();
  const rows=json.data||[]; if(!rows.length) return alert('No data to export');
  const cols=Object.keys(rows[0]);
  const csv=[cols.join(',')].concat(rows.map(r=>cols.map(c=>`"${String(r[c]||'').replace(/"/g,'""')}"`).join(','))).join('\n');
  const blob=new Blob([csv],{type:'text/csv'}); const a=document.createElement('a');
  a.href=URL.createObjectURL(blob); a.download='maintenance_requests.csv'; document.body.appendChild(a); a.click(); a.remove();
});

/* ---------- editor ---------- */
document.getElementById('updateForm').addEventListener('submit',async e=>{
  e.preventDefault();
  const dt=document.getElementById('edit_completion_date').value;
  const data={
    id:document.getElementById('edit_id').value,
    status:document.getElementById('edit_status').value,
    assigned_to:document.getElementById('edit_assigned_to').value,
    parts_used:document.getElementById('edit_parts_used').value,
    cost:document.getElementById('edit_cost').value||0,
    completion_date:dt? new Date(dt).toISOString().slice(0,19).replace('T',' ') : '',
    remarks:document.getElementById('edit_remarks').value
  };
  const res=await api('update',data);
  document.getElementById('updateMsg').textContent=(res.msg||'')+(res.detail?` — ${res.detail}`:'');
  if(res.success){ fetchList(); bootstrap.Modal.getInstance(document.getElementById('editor')).hide(); }
});

async function openEditor(id){
  const url=new URL(window.location.href); url.searchParams.set('action','list'); url.searchParams.set('limit',200);
  const res=await fetch(url.toString(), { credentials:'same-origin' }); const json=await res.json();
  const item=(json.data||[]).find(x=>Number(x.id)===Number(id));
  if(!item) return alert('Record not found');
  document.getElementById('editId').textContent='#'+id;
  document.getElementById('edit_id').value=item.id;
  document.getElementById('edit_status').value=item.status;
  document.getElementById('edit_assigned_to').value=item.assigned_to||'';
  document.getElementById('edit_parts_used').value=item.parts_used||'';
  document.getElementById('edit_cost').value=item.cost||'';
  document.getElementById('edit_completion_date').value=item.completion_date? (new Date(item.completion_date)).toISOString().slice(0,16):'';
  document.getElementById('edit_remarks').value=item.remarks||'';
  document.getElementById('updateMsg').textContent='';
  new bootstrap.Modal(document.getElementById('editor')).show();
}

document.getElementById('deleteBtn').addEventListener('click',async ()=>{
  if(!confirm('Delete this request?')) return;
  const id=document.getElementById('edit_id').value;
  const res=await api('delete',{id});
  if(res.success){ fetchList(); bootstrap.Modal.getInstance(document.getElementById('editor')).hide(); }
  else alert((res.msg||'Delete failed') + (res.detail?` — ${res.detail}`:''));
});

/* ---------- init ---------- */
setInterval(fetchList, 8000);
loadTechnicians().then(fetchList);
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
