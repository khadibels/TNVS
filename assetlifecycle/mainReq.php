<?php
// maintenance_requests.php
// Single-file Maintenance Requests module (PDO + AJAX)

// === CONFIG ===
$DB_HOST = '127.0.0.1';
$DB_NAME = 'alms_db';
$DB_USER = 'root';
$DB_PASS = ''; // change as needed
$UPLOAD_DIR = __DIR__ . '/uploads/'; // ensure writable

// === DB CONNECTION via PDO ===
try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo "DB Connection failed: " . htmlspecialchars($e->getMessage());
    exit;
}

// === History table + JSON helpers ===
function ensureHistoryTable(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS maintenance_request_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        request_id INT NOT NULL,
        action VARCHAR(50) NOT NULL,
        changes_json JSON NULL,
        actor VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (request_id),
        CONSTRAINT fk_mrh_request FOREIGN KEY (request_id) REFERENCES maintenance_requests(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
// ensure table exists
ensureHistoryTable($pdo);

// Support JSON body for AJAX (e.g., update/delete)
$__RAW = file_get_contents('php://input');
$__JSON = [];
if (!empty($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
    $__JSON = json_decode($__RAW, true) ?: [];
}
function postv(string $key, $default = null) {
    global $__JSON;
    return $_POST[$key] ?? ($__JSON[$key] ?? $default);
}

// Request history logger
function logRequestHistory(PDO $pdo, int $requestId, string $action, array $changes = [], ?string $actor = null): void {
    $stmt = $pdo->prepare("INSERT INTO maintenance_request_history (request_id, action, changes_json, actor) VALUES (:request_id, :action, :changes_json, :actor)");
    $stmt->execute([
        ':request_id' => $requestId,
        ':action' => $action,
        ':changes_json' => $changes ? json_encode($changes, JSON_UNESCAPED_UNICODE) : null,
        ':actor' => $actor
    ]);
}

// === Utility: sanitize output ===
function h($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

// === Handle AJAX actions (JSON) ===
if (isset($_GET['action'])) {
    $action = $_GET['action'];

    header('Content-Type: application/json; charset=utf-8');

    if ($action === 'list') {
        // optional filters
        $status = $_GET['status'] ?? '';
        $priority = $_GET['priority'] ?? '';
        $stmt = $pdo->prepare("
            SELECT mr.*, t.name AS technician_name
            FROM maintenance_requests mr
            LEFT JOIN technicians t ON mr.assigned_to = t.id
            WHERE 1=1
            " . ($status ? " AND mr.status=:status" : "") . ($priority ? " AND mr.priority_level=:priority" : "") . "
            ORDER BY mr.date_reported DESC
            LIMIT 100
        ");
        if ($status && $priority) $stmt->execute([':status'=>$status, ':priority'=>$priority]);
        elseif ($status) $stmt->execute([':status'=>$status]);
        elseif ($priority) $stmt->execute([':priority'=>$priority]);
        else $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success'=>true,'data'=>$rows]);
        exit;
    }

    if ($action === 'get_technicians') {
        $rows = $pdo->query("SELECT id, name FROM technicians ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success'=>true,'data'=>$rows]);
        exit;
    }

    if ($action === 'add') {
        // adding via AJAX form (multipart)
        // validate
        $asset_name = trim($_POST['asset_name'] ?? '');
        $asset_id = $_POST['asset_id'] ?? null;
        $description = trim($_POST['description'] ?? '');
        $priority = $_POST['priority'] ?? 'Normal';
        $reported_by = trim($_POST['reported_by'] ?? '');
        $assigned_to = $_POST['assigned_to'] ?: null;

        if (!$asset_name || !$description) {
            echo json_encode(['success'=>false,'msg'=>'Asset name and description required.']);
            exit;
        }

        // handle file upload (optional)
        $attachmentPath = null;
        if (!empty($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $f = $_FILES['attachment'];
            $ext = pathinfo($f['name'], PATHINFO_EXTENSION);
            $safe = bin2hex(random_bytes(8)) . '.' . $ext;
            if (!is_dir($UPLOAD_DIR)) mkdir($UPLOAD_DIR, 0755, true);
            $dest = $UPLOAD_DIR . $safe;
            if (move_uploaded_file($f['tmp_name'], $dest)) {
                $attachmentPath = 'uploads/' . $safe;
            }
        }

        $stmt = $pdo->prepare("INSERT INTO maintenance_requests
            (asset_id, asset_name, issue_description, priority_level, reported_by, assigned_to, attachment)
            VALUES (:asset_id, :asset_name, :issue_description, :priority_level, :reported_by, :assigned_to, :attachment)");
        $stmt->execute([
            ':asset_id' => $asset_id ?: null,
            ':asset_name' => $asset_name,
            ':issue_description' => $description,
            ':priority_level' => $priority,
            ':reported_by' => $reported_by ?: null,
            ':assigned_to' => $assigned_to ?: null,
            ':attachment' => $attachmentPath
        ]);
        $newId = (int)$pdo->lastInsertId();
        // log creation
        logRequestHistory($pdo, $newId, 'created', [
          'asset_name' => ['from'=>null,'to'=>$asset_name],
          'issue_description' => ['from'=>null,'to'=>$description],
          'priority_level' => ['from'=>null,'to'=>$priority],
          'reported_by' => ['from'=>null,'to'=>$reported_by ?: null],
          'assigned_to' => ['from'=>null,'to'=>$assigned_to ?: null]
        ], $reported_by ?: null);

        echo json_encode(['success'=>true,'msg'=>'Request added.','id'=>$newId]);
        exit;
    }

    if ($action === 'update') {
        $id = intval(postv('id', 0));
        if (!$id) { echo json_encode(['success'=>false,'msg'=>'Invalid ID']); exit; }

        $status = postv('status', 'Pending');
        $assigned_to = postv('assigned_to') ?: null;
        $parts_used = postv('parts_used');
        $cost = postv('cost', 0);
        $completion_date = postv('completion_date') ?: null;
        $remarks = postv('remarks');

        // If status is Completed and no completion_date set, set to now
        if ($status === 'Completed' && !$completion_date) $completion_date = date('Y-m-d H:i:s');

        // fetch previous values for diff
        $prevStmt = $pdo->prepare("SELECT status, assigned_to, parts_used, cost, completion_date, remarks FROM maintenance_requests WHERE id=:id");
        $prevStmt->execute([':id'=>$id]);
        $prev = $prevStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $stmt = $pdo->prepare("UPDATE maintenance_requests SET status=:status, assigned_to=:assigned_to, parts_used=:parts_used, cost=:cost, completion_date=:completion_date, remarks=:remarks WHERE id=:id");
        $stmt->execute([
            ':status' => $status,
            ':assigned_to' => $assigned_to,
            ':parts_used' => $parts_used,
            ':cost' => $cost ?: 0,
            ':completion_date' => $completion_date,
            ':remarks' => $remarks,
            ':id' => $id
        ]);

        // build change set
        $changes = [];
        $fields = [
          'status' => $status,
          'assigned_to' => $assigned_to,
          'parts_used' => $parts_used,
          'cost' => $cost ?: 0,
          'completion_date' => $completion_date,
          'remarks' => $remarks
        ];
        foreach ($fields as $k=>$newVal) {
          $oldVal = $prev[$k] ?? null;
          // normalize types to strings for comparison
          if ($oldVal === null && $newVal === null) continue;
          if ((string)$oldVal !== (string)$newVal) {
            $changes[$k] = ['from'=>$oldVal, 'to'=>$newVal];
          }
        }
        if ($changes) logRequestHistory($pdo, $id, 'updated', $changes, null);

        echo json_encode(['success'=>true,'msg'=>'Request updated.']);
        exit;
    }

    if ($action === 'delete') {
        $id = intval(postv('id', 0));
        if (!$id) { echo json_encode(['success'=>false,'msg'=>'Invalid ID']); exit; }
        // optionally remove attachment file
        $row = $pdo->prepare("SELECT attachment FROM maintenance_requests WHERE id=:id");
        $row->execute([':id'=>$id]);
        $r = $row->fetch(PDO::FETCH_ASSOC);
        if ($r && !empty($r['attachment']) && file_exists(__DIR__ . '/' . $r['attachment'])) {
            @unlink(__DIR__ . '/' . $r['attachment']);
        }
        $stmt = $pdo->prepare("DELETE FROM maintenance_requests WHERE id=:id");
        $stmt->execute([':id'=>$id]);
        echo json_encode(['success'=>true,'msg'=>'Deleted']);
        exit;
    }

    if ($action === 'history') {
        $rid = intval($_GET['request_id'] ?? postv('request_id', 0));
        if (!$rid) { echo json_encode(['success'=>false,'msg'=>'Invalid request id']); exit; }
        $stmt = $pdo->prepare("SELECT id, action, changes_json, actor, created_at FROM maintenance_request_history WHERE request_id = :rid ORDER BY created_at DESC, id DESC");
        $stmt->execute([':rid' => $rid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['changes'] = $row['changes_json'] ? json_decode($row['changes_json'], true) : null;
            unset($row['changes_json']);
        }
        echo json_encode(['success'=>true,'data'=>$rows]);
        exit;
    }

    echo json_encode(['success'=>false,'msg'=>'Unknown action']);
    exit;
}

// === Below: HTML UI ===
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Maintenance Requests — TNVS Logistics</title>
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<style>
  :root {
    --primary: #6d28d9;
    --primary-dark: #6d28d9;
    --danger: #e55353;
    --danger-dark: #c03d3d;
    --muted: #6c757d;
    --bg: #f0f2f5;
    --card: #fff;
    --radius: 10px;
    --shadow: 0 4px 14px rgba(0,0,0,0.08);
  }

  body {
    font-family: 'Segoe UI', Arial, sans-serif;
    background: var(--bg);
    margin: 0;
    color: #222;
  }

  .container {
    max-width: 1100px;
    margin: 30px auto;
    padding: 0 15px;
  }

  header {
    text-align: center;
    margin-bottom: 20px;
  }

  header h1 {
    font-size: 26px;
    margin: 0;
    color: var(--primary);
  }

  .small {
    font-size: 13px;
    color: var(--muted);
  }

  .card {
    background: var(--card);
    padding: 20px;
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    margin-bottom: 20px;
    transition: transform 0.2s ease;
  }

  .card:hover {
    transform: translateY(-2px);
  }

  /* Form controls */
  input, select, textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: var(--radius);
    font-size: 14px;
    transition: border-color 0.2s ease;
  }

  input:focus, select:focus, textarea:focus {
    border-color: var(--primary);
    outline: none;
  }

  textarea {
    resize: vertical;
  }

  /* Buttons */
  .btn {
    display: inline-block;
    background: var(--primary);
    color: #fff;
    padding: 10px 16px;
    border-radius: var(--radius);
    border: none;
    font-size: 14px;
    cursor: pointer;
    transition: background 0.2s ease, transform 0.2s ease;
  }

  .btn:hover {
    background: var(--primary-dark);
    transform: translateY(-1px);
  }

  .btn.ghost {
    background: transparent;
    color: var(--primary);
    border: 1px solid var(--primary);
  }

  .btn.ghost:hover {
    background: var(--primary);
    color: #fff;
  }
  .btn.danger {
    background: var(--danger);
  }

  .btn.danger:hover {
    background: var(--danger-dark);
  }

  /* Table */
  table {
    width: 100%;
    border-collapse: collapse;
    background: white;
    border-radius: var(--radius);
    overflow: hidden;
    box-shadow: var(--shadow);
  }

  th, td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #eee;
  }

  th {
    background: #f7f9ff;
    font-weight: 600;
    color: #333;
  }

  /* Status styles */
  .status-Pending { color: orange; font-weight: bold; }
  .status-In\ Progress { color: #007bff; font-weight: bold; }
  .status-Completed { color: green; font-weight: bold; }
  .status-Cancelled { color: #777; font-weight: bold; }

  /* Modal editor */
  #editor {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.4);
    align-items: center;
    justify-content: center;
    animation: fadeIn 0.2s ease;
  }

  #editor > div {
    background: var(--card);
    border-radius: var(--radius);
    padding: 20px;
    box-shadow: var(--shadow);
    animation: slideIn 0.3s ease;
  }

  @keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
  }

  @keyframes slideIn {
    from { transform: translateY(-10px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
  }

  /* Responsive */
  @media (max-width: 768px) {
    .row { flex-direction: column; }
  }
</style>

</head>
<body>
<div class="container">
  <header style="position:sticky;top:0;z-index:1000;background:var(--bg);padding:10px 0;">
  <div style="display:flex;align-items:center;gap:10px;justify-content:center;flex-wrap:wrap;">
    <button class="btn ghost" onclick="history.back()">
      <i class='bx bx-arrow-back'></i> Back
    </button>
    <div style="text-align:center;">
      <h1><i class='bx bx-wrench'></i> Maintenance Requests</h1>
      <div class="small">Manage incoming service and maintenance requests for assets</div>
    </div>
  </div>
</header>

  <!-- Add Request Form -->
  <section class="card">
    <form id="addForm" enctype="multipart/form-data">
      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <div style="flex:1;min-width:220px">
          <label class="small">Asset (existing or new)</label>
          <input name="asset_name" id="asset_name" placeholder="Asset name or ID" required />
        </div>
        <div style="min-width:170px">
          <label class="small">Priority</label>
          <select name="priority" id="priority">
            <option value="Normal">Normal</option>
            <option value="Low">Low</option>
            <option value="High">High</option>
          </select>
        </div>
        <div style="min-width:170px">
          <label class="small">Reported by</label>
          <input name="reported_by" id="reported_by" placeholder="Name" />
        </div>
        <div style="min-width:170px">
          <label class="small">Assign to (optional)</label>
          <select name="assigned_to" id="assigned_to"><option value="">-- Select technician --</option></select>
        </div>
      </div>

      <div style="margin-top:10px">
        <label class="small">Issue description</label>
        <textarea name="description" id="description" required></textarea>
      </div>

      <div style="display:flex;gap:10px;margin-top:8px;align-items:center;">
        <input type="file" name="attachment" id="attachment" accept="image/*,.pdf,.doc,.docx" />
        <button class="btn" type="submit">Submit Request</button>
        <button class="btn ghost" type="button" id="resetBtn">Reset</button>
      </div>
      <div id="formMessage" class="small" style="margin-top:8px"></div>
    </form>
  </section>

  <!-- Filters + Export (basic) -->
  <section class="card" style="display:flex;justify-content:space-between;align-items:center;">
    <div style="display:flex;gap:8px;align-items:center">
      <label class="small">Status</label>
      <select id="filterStatus">
        <option value="">All</option>
        <option>Pending</option>
        <option>In Progress</option>
        <option>Completed</option>
        <option>Cancelled</option>
      </select>
      <label class="small">Priority</label>
      <select id="filterPriority">
        <option value="">All</option>
        <option>Low</option>
        <option>Normal</option>
        <option>High</option>
      </select>
      <button class="btn ghost" id="applyFilters">Apply</button>
    </div>
    <div class="controls">
      <button class="btn ghost" id="refreshBtn">Refresh</button>
      <button class="btn ghost" id="exportCsv">Export CSV</button>
    </div>
  </section>

  <!-- Requests Table -->
  <section class="card">
    <table id="requestsTable">
      <thead>
        <tr>
          <th>ID</th>
          <th>Asset</th>
          <th>Priority</th>
          <th>Status</th>
          <th>Assigned</th>
          <th>Reported</th>
          <th>Attachment</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>
    <div id="tableMsg" class="small" style="margin-top:8px"></div>
  </section>
</div>

<!-- Modal-like simple editor area (in-page) -->
<section id="editor" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);align-items:center;justify-content:center;">
  <div style="width:800px;max-width:95%;background:#fff;padding:14px;border-radius:8px;box-shadow:0 6px 18px rgba(0,0,0,0.2)">
    <h3 style="margin-top:0">Update Request <span id="editId"></span></h3>
    <form id="updateForm">
      <input type="hidden" name="id" id="edit_id" />
      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <div style="flex:1">
          <label class="small">Status</label>
          <select name="status" id="edit_status">
            <option>Pending</option><option>In Progress</option><option>Completed</option><option>Cancelled</option>
          </select>
        </div>
        <div style="min-width:200px">
          <label class="small">Assign to</label>
          <select name="assigned_to" id="edit_assigned_to"><option value="">-- Select --</option></select>
        </div>
        <div style="min-width:160px">
          <label class="small">Completion Date</label>
          <input type="datetime-local" name="completion_date" id="edit_completion_date" />
        </div>
        <div style="min-width:130px">
          <label class="small">Cost</label>
          <input name="cost" id="edit_cost" placeholder="0.00" />
        </div>
      </div>

      <div style="margin-top:8px">
        <label class="small">Parts used</label>
        <input name="parts_used" id="edit_parts_used" />
      </div>
      <div style="margin-top:8px">
        <label class="small">Remarks</label>
        <textarea name="remarks" id="edit_remarks"></textarea>
      </div>

      <div style="display:flex;gap:8px;margin-top:10px;justify-content:flex-end">
        <button class="btn" type="submit">Save</button>
        <button class="btn ghost" type="button" id="closeEditor">Cancel</button>
        <button class="btn" type="button" id="deleteBtn" style="background:#e55353">Delete</button>
      </div>
      <div id="updateMsg" class="small" style="margin-top:8px"></div>
    </form>
  </div>
</section>

<!-- History modal -->
<section id="historyModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);align-items:center;justify-content:center;">
  <div style="width:720px;max-width:95%;background:#fff;padding:14px;border-radius:8px;box-shadow:0 6px 18px rgba(0,0,0,0.2)">
    <h3 style="margin-top:0">Request History <span id="historyId"></span></h3>
    <div id="historyContent" class="small" style="max-height:60vh;overflow:auto;border:1px solid #eee;border-radius:6px;padding:10px;background:#fafafa">
      <div id="historyEmpty" style="color:#777">No history.</div>
      <ul id="historyList" style="list-style:none;padding-left:0;margin:0"></ul>
    </div>
    <div style="display:flex;gap:8px;margin-top:10px;justify-content:flex-end">
      <button class="btn ghost" type="button" id="closeHistory">Close</button>
    </div>
  </div>
</section>

<script>
// Helper fetch wrapper
async function api(action, data = null, isForm = false) {
  const url = new URL(window.location.href);
  url.searchParams.set('action', action);
  let opts = { method: 'POST' };
  if (data) {
    if (isForm) opts.body = data;
    else { opts.headers = { 'Content-Type':'application/json' }; opts.body = JSON.stringify(data); }
  }
  const res = await fetch(url.toString(), opts);
  return res.json();
}

// populate technicians select
async function loadTechnicians() {
  const r = await api('get_technicians');
  if (r.success) {
    const sel = document.getElementById('assigned_to');
    const editSel = document.getElementById('edit_assigned_to');
    sel.innerHTML = '<option value="">-- Select technician --</option>';
    editSel.innerHTML = '<option value="">-- Select --</option>';
    r.data.forEach(t => {
      const o = `<option value="${t.id}">${t.name}</option>`;
      sel.insertAdjacentHTML('beforeend', o);
      editSel.insertAdjacentHTML('beforeend', o);
    });
  }
}

// build table rows
function renderTable(rows) {
  const tb = document.querySelector('#requestsTable tbody');
  tb.innerHTML = '';
  if (!rows.length) {
    document.getElementById('tableMsg').textContent = 'No requests found.';
    return;
  }
  document.getElementById('tableMsg').textContent = '';
  rows.forEach(r => {
    const attach = r.attachment ? `<a href="${r.attachment}" target="_blank">View</a>` : '';
    const assigned = r.technician_name ?? '';
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${r.id}</td>
      <td><strong>${escapeHtml(r.asset_name)}</strong><div class="small">${escapeHtml(r.issue_description).slice(0,80)}${r.issue_description.length>80?'...':''}</div></td>
      <td>${r.priority_level}</td>
      <td class="status-${r.status.replace(/\s/g,'\\ ')}">${r.status}</td>
      <td>${assigned}</td>
      <td class="small">${r.date_reported}</td>
      <td>${attach}</td>
      <td>
        <button class="btn ghost" data-id="${r.id}" onclick="openEditor(${r.id})">Update</button>
        <button class="btn ghost" style="margin-left:6px" onclick="openHistory(${r.id})">History</button>
      </td>
    `;
    tb.appendChild(tr);
  });
}

// escape helper
function escapeHtml(s){ return String(s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

// fetch and render list
async function fetchList() {
  const status = document.getElementById('filterStatus').value;
  const priority = document.getElementById('filterPriority').value;
  const params = new URLSearchParams();
  if (status) params.set('status', status);
  if (priority) params.set('priority', priority);
  const url = new URL(window.location.href);
  url.searchParams.set('action','list');
  if (status) url.searchParams.set('status', status);
  if (priority) url.searchParams.set('priority', priority);
  const res = await fetch(url.toString());
  const json = await res.json();
  if (json.success) renderTable(json.data);
}

// handle add form (AJAX with file)
document.getElementById('addForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const f = new FormData(e.target);
  const res = await api('add', f, true);
  document.getElementById('formMessage').textContent = res.msg || (res.success ? 'Added' : 'Error');
  if (res.success) {
    e.target.reset();
    fetchList();
  }
});

// update form
document.getElementById('updateForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const data = {
    id: document.getElementById('edit_id').value,
    status: document.getElementById('edit_status').value,
    assigned_to: document.getElementById('edit_assigned_to').value,
    parts_used: document.getElementById('edit_parts_used').value,
    cost: document.getElementById('edit_cost').value || 0,
    completion_date: document.getElementById('edit_completion_date').value ? new Date(document.getElementById('edit_completion_date').value).toISOString().slice(0,19).replace('T',' ') : '',
    remarks: document.getElementById('edit_remarks').value
  };
  const res = await api('update', data);
  document.getElementById('updateMsg').textContent = res.msg || '';
  if (res.success) {
    fetchList();
    setTimeout(()=>closeEditor(),400);
  }
});

// open editor and load record into fields
async function openEditor(id) {
  // get single record from list fetch (simple approach)
  const url = new URL(window.location.href);
  url.searchParams.set('action','list');
  url.searchParams.set('limit',100);
  const res = await fetch(url.toString());
  const json = await res.json();
  const item = (json.data || []).find(x => Number(x.id) === Number(id));
  if (!item) return alert('Record not found');
  document.getElementById('editor').style.display = 'flex';
  document.getElementById('editId').textContent = '#' + id;
  document.getElementById('edit_id').value = item.id;
  document.getElementById('edit_status').value = item.status;
  document.getElementById('edit_assigned_to').value = item.assigned_to || '';
  document.getElementById('edit_parts_used').value = item.parts_used || '';
  document.getElementById('edit_cost').value = item.cost || '';
  document.getElementById('edit_completion_date').value = item.completion_date ? (new Date(item.completion_date)).toISOString().slice(0,16) : '';
  document.getElementById('edit_remarks').value = item.remarks || '';
  document.getElementById('updateMsg').textContent = '';
}

// close editor
document.getElementById('closeEditor').addEventListener('click', closeEditor);
function closeEditor(){ document.getElementById('editor').style.display = 'none'; }

// delete from editor
document.getElementById('deleteBtn').addEventListener('click', async () => {
  if (!confirm('Delete this request?')) return;
  const id = document.getElementById('edit_id').value;
  const res = await api('delete', {id});
  if (res.success) { fetchList(); closeEditor(); }
  else alert(res.msg || 'Delete failed');
});

// history modal logic
async function openHistory(id){
  document.getElementById('historyModal').style.display = 'flex';
  document.getElementById('historyId').textContent = '#' + id;
  const res = await api('history', {request_id:id});
  if (res.success) renderHistory(res.data || []);
  else {
    renderHistory([]);
    alert(res.msg || 'Failed to load history');
  }
}
function renderHistory(items){
  const list = document.getElementById('historyList');
  const empty = document.getElementById('historyEmpty');
  list.innerHTML='';
  if (!items.length){ empty.style.display='block'; return; }
  empty.style.display='none';
  items.forEach(it => {
    const li = document.createElement('li');
    const when = it.created_at;
    const actor = it.actor ? ` by ${escapeHtml(it.actor)}` : '';
    let changesHtml = '';
    if (it.changes && typeof it.changes === 'object'){
      const rows = Object.entries(it.changes).map(([k, v]) => `<div><code>${escapeHtml(k)}</code>: <span style="color:#b00">${escapeHtml(String(v.from ?? ''))}</span> → <span style="color:#070">${escapeHtml(String(v.to ?? ''))}</span></div>`).join('');
      changesHtml = `<div style="margin-top:4px;padding-left:6px">${rows}</div>`;
    }
    li.innerHTML = `<div style="padding:8px;border-bottom:1px solid #eee"><strong>${escapeHtml(it.action)}</strong><span class="small"> at ${escapeHtml(when)}${actor}</span>${changesHtml}</div>`;
    list.appendChild(li);
  });
}

document.getElementById('closeHistory').addEventListener('click', () => { document.getElementById('historyModal').style.display = 'none'; });

// small controls
document.getElementById('refreshBtn').addEventListener('click', fetchList);
document.getElementById('applyFilters').addEventListener('click', fetchList);
document.getElementById('resetBtn').addEventListener('click', ()=>document.getElementById('addForm').reset());

// export CSV (client-side)
document.getElementById('exportCsv').addEventListener('click', async () => {
  const url = new URL(window.location.href); url.searchParams.set('action','list');
  const res = await fetch(url.toString()); const json = await res.json();
  const rows = json.data || [];
  if (!rows.length) return alert('No data to export');
  const cols = Object.keys(rows[0]);
  const csv = [cols.join(',')].concat(rows.map(r => cols.map(c => `"${String(r[c]||'').replace(/"/g,'""')}"`).join(','))).join('\n');
  const blob = new Blob([csv], {type:'text/csv'}); const a = document.createElement('a');
  a.href = URL.createObjectURL(blob); a.download = 'maintenance_requests.csv'; document.body.appendChild(a); a.click(); a.remove();
});

// periodic refresh every 8s
setInterval(fetchList, 8000);

// initial load
loadTechnicians().then(fetchList);
</script>
</body>
</html>
