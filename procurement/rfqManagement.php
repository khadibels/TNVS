<?php
// File: procurement/rfqManagement.php
require_once __DIR__ . "/../includes/config.php";
require_once __DIR__ . "/../includes/auth.php";
require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/vendor_capability.php";

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

function col_exists(PDO $pdo, string $t, string $c): bool {
  $s=$pdo->prepare("SELECT 1 FROM information_schema.columns
                     WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1");
  $s->execute([$t,$c]); return (bool)$s->fetchColumn();
}

/* ---------- AJAX: RFQ detail for modal ---------- */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'rfq_detail') {
  header('Content-Type: application/json; charset=utf-8');
  try {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) throw new Exception("Invalid quotation request id");

    $st = $pdo->prepare("SELECT * FROM rfqs WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $rfq = $st->fetch(PDO::FETCH_ASSOC);
    if (!$rfq) throw new Exception("Quotation request not found");

    $it = $pdo->prepare("SELECT * FROM rfq_items WHERE rfq_id=? ORDER BY line_no ASC, id ASC");
    $it->execute([$id]);
    $items = $it->fetchAll(PDO::FETCH_ASSOC);
    if (!col_exists($pdo,'rfq_items','category')) {
      try {
        $catMap = [];
        $cst = $pdo->prepare("SELECT rfq_item_id, category FROM rfq_item_categories WHERE rfq_id=?");
        $cst->execute([$id]);
        foreach ($cst->fetchAll(PDO::FETCH_ASSOC) as $r) {
          $catMap[(int)$r['rfq_item_id']] = $r['category'];
        }
        foreach ($items as &$row) {
          $row['category'] = $catMap[(int)$row['id']] ?? '';
        }
        unset($row);
      } catch (Throwable $e) { }
    }

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

    $ex = [];
    try {
      ensure_vendor_capability_tables($pdo);
      $exq = $pdo->prepare("
        SELECT e.vendor_id, e.reason, v.company_name, v.email
          FROM rfq_vendor_exclusions e
          JOIN vendors v ON v.id=e.vendor_id
         WHERE e.rfq_id=?
      ");
      $exq->execute([$id]);
      $ex = $exq->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { }

    echo json_encode(['rfq'=>$rfq,'items'=>$items,'suppliers'=>$suppliers,'quotes'=>$quotes,'excluded'=>$ex]);
  } catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
  }
  exit;
}

/* ---------- Helpers ---------- */
function time_remaining(string $due): array {
  $diff = strtotime($due) - time();
  if ($diff <= 0) return ['label' => 'Expired', 'urgent' => true];
  $days = floor($diff / 86400);
  $hrs  = floor(($diff % 86400) / 3600);
  if ($days > 3) return ['label' => "{$days}d {$hrs}h left", 'urgent' => false];
  if ($days > 0) return ['label' => "{$days}d {$hrs}h left", 'urgent' => true];
  return ['label' => "{$hrs}h left", 'urgent' => true];
}

function time_ago(string $dt): string {
  $diff = time() - strtotime($dt);
  if ($diff < 60) return 'Just now';
  if ($diff < 3600) return floor($diff/60) . 'm ago';
  if ($diff < 86400) return floor($diff/3600) . 'h ago';
  if ($diff < 604800) return floor($diff/86400) . 'd ago';
  return date('M j', strtotime($dt));
}

/* ---------- AJAX: Create RFQ (modal submit) ---------- */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'create_rfq' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json; charset=utf-8');
  try {
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $currency    = 'PHP';
    $due_raw     = trim($_POST['due_at'] ?? '');

    $due_at = null;
    if ($due_raw !== '') {
      $due_raw = str_replace('T', ' ', $due_raw);
      $t = strtotime($due_raw);
      if ($t !== false) $due_at = date('Y-m-d H:i:s', $t);
    }

    $item_names    = $_POST['item']  ?? [];
    $item_specs    = $_POST['specs'] ?? [];
    $item_qty      = $_POST['qty']   ?? [];
    $item_category = $_POST['category'] ?? [];
    $item_uom      = [];
    $supplier_ids  = array_map('intval', $_POST['supplier_ids'] ?? []);

    $clean_items = [];
    $count = max(count($item_names), count($item_specs), count($item_qty), count($item_category));
    for ($i=0; $i<$count; $i++) {
      $nm = trim($item_names[$i] ?? '');
      if ($nm === '') continue;
      $sp = trim($item_specs[$i] ?? '');
      $qt = (float)($item_qty[$i] ?? 0);
      $cat = trim($item_category[$i] ?? '');
      if ($qt <= 0) $qt = 1;
      $clean_items[] = ['item'=>$nm,'specs'=>$sp,'qty'=>$qt,'uom'=>'unit','category'=>$cat];
    }

    if ($title === '') throw new Exception("Title is required.");
    if (!$due_at)      throw new Exception("A valid Due Date/Time is required.");
    if (empty($clean_items))  throw new Exception("Add at least one quotation item.");

    $hasCatCol = col_exists($pdo,'rfq_items','category');
    if (!$hasCatCol) {
      $pdo->exec("
        CREATE TABLE IF NOT EXISTS rfq_item_categories (
          id INT AUTO_INCREMENT PRIMARY KEY,
          rfq_id INT NOT NULL,
          rfq_item_id INT NOT NULL,
          category VARCHAR(100) NULL,
          UNIQUE KEY uniq_item (rfq_item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
      ");
    }

    ensure_vendor_capability_tables($pdo);

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
    $ii = $hasCatCol
      ? $pdo->prepare("INSERT INTO rfq_items (rfq_id,line_no,item,specs,qty,uom,category) VALUES (?,?,?,?,?,?,?)")
      : $pdo->prepare("INSERT INTO rfq_items (rfq_id,line_no,item,specs,qty,uom) VALUES (?,?,?,?,?,?)");
    $insCat = $hasCatCol ? null : $pdo->prepare("INSERT INTO rfq_item_categories (rfq_id, rfq_item_id, category) VALUES (?,?,?)");
    foreach ($clean_items as $row) {
      if ($hasCatCol) {
        $ii->execute([$rfq_id,$line++,$row['item'],$row['specs'],$row['qty'],$row['uom'],$row['category']]);
      } else {
        $ii->execute([$rfq_id,$line++,$row['item'],$row['specs'],$row['qty'],$row['uom']]);
        $itemId = (int)$pdo->lastInsertId();
        $insCat->execute([$rfq_id, $itemId, $row['category']]);
      }
    }

    // Categories (optional)
    $categories = array_values(array_unique(array_filter(array_map(fn($r)=>trim((string)$r['category']), $clean_items))));

    // Note: No longer auto-inviting individual vendors to 'rfq_suppliers'. 
    // The RFQ is now public ('sent' status) and visible to all approved vendors in the portal.

    $pdo->commit();
    echo json_encode(['ok'=>1,'id'=>$rfq_id,'rfq_no'=>$rfq_no,'message'=>'Quotation request posted to portal']);
  } catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) {
      try { $pdo->rollBack(); } catch (Throwable $rb) { /* ignore rollback error */ }
    }
    http_response_code(400);
    echo json_encode(['error'=>$e->getMessage()]);
  }
  exit;
}


/* ---------- Categories (from WMS) ---------- */
$categories = [];
try {
  $wms = db('wms');
  if ($wms instanceof PDO) {
    $categories = $wms->query("SELECT name FROM inventory_categories ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
  }
} catch (Throwable $e) {}

/* ---------- Load RFQs ---------- */
$rfqs = [];
try {
  $sql = "
    SELECT
      r.id, r.rfq_no, r.title, r.description, r.due_at, r.currency, r.status, r.created_at,
      (SELECT COUNT(*) FROM rfq_suppliers rs WHERE rs.rfq_id = r.id) AS invited_count,
      (SELECT COUNT(*) FROM quotes q WHERE q.rfq_id = r.id) AS quoted_count
    FROM rfqs r
    ORDER BY r.id DESC
    LIMIT 200
  ";
  $rfqs = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

  // Fetch items for previews
  $rfqItems = [];
  if ($rfqs) {
    $ids = array_column($rfqs, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $st2 = $pdo->prepare("SELECT rfq_id, id, line_no, item, specs, qty, uom FROM rfq_items WHERE rfq_id IN ($placeholders) ORDER BY rfq_id, line_no ASC");
    $st2->execute($ids);
    foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $it) {
      $rfqItems[(int)$it['rfq_id']][] = $it;
    }
  }
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
  <title>Quotation Management | TNVS</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="../css/style.css" rel="stylesheet" />
  <link href="../css/modules.css" rel="stylesheet" />
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <script src="../js/sidebar-toggle.js"></script>

  <style>
    body { background: #f0f2f5; font-family: 'Inter', system-ui, -apple-system, sans-serif; }
    .main-content { padding: 1.25rem; }
    @media(min-width:992px) { .main-content { padding: 1.5rem 2rem; } }

    /* ─── Metric Cards ─── */
    .metric-card {
      background: #fff; border-radius: 12px; padding: 1.25rem;
      box-shadow: 0 1px 3px rgba(0,0,0,.08); border: none;
      transition: transform .2s;
    }
    .metric-card .label { font-size: .75rem; font-weight: 600; color: #65676b; text-transform: uppercase; margin-bottom: .25rem; }
    .metric-card .value { font-size: 1.5rem; font-weight: 700; color: #1c1e21; }
    .metric-card .icon { font-size: 1.5rem; color: #6532C9; opacity: .8; }

    /* ─── Feed Layout ─── */
    .feed-container { max-width: 800px; margin: 0 auto; }
    
    .feed-header {
      background: #fff; border-radius: 12px; padding: 1rem 1.25rem;
      margin-bottom: 1.25rem; box-shadow: 0 1px 3px rgba(0,0,0,.08);
      display: flex; align-items: center; justify-content: space-between; gap: 1rem;
    }
    .feed-search-wrap { flex: 1; position: relative; }
    .feed-search-wrap ion-icon { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: #65676b; font-size: 1.1rem; }
    .feed-search-wrap input {
      width: 100%; border: none; background: #f0f2f5; border-radius: 20px;
      padding: .6rem 1rem .6rem 2.8rem; font-size: .92rem; outline: none;
    }

    /* ─── Post Card ─── */
    .post-card {
      background: #fff; border-radius: 12px;
      box-shadow: 0 1px 3px rgba(0,0,0,.08);
      margin-bottom: 1.25rem;
      overflow: hidden;
    }
    .post-header { display: flex; align-items: center; gap: .75rem; padding: 1rem 1.25rem .5rem; }
    .post-avatar {
      width: 44px; height: 44px; border-radius: 50%;
      background: linear-gradient(135deg, #6532C9, #7c3aed);
      display: grid; place-items: center; color: #fff; font-size: 1.4rem;
    }
    .post-user-info { flex: 1; }
    .post-user-name { font-weight: 700; font-size: .95rem; color: #1c1e21; display: flex; align-items: center; gap: .4rem; }
    .post-meta-line { font-size: .8rem; color: #65676b; display: flex; align-items: center; gap: .4rem; }
    .post-status-badge { font-size: .75rem; font-weight: 600; padding: .2rem .75rem; border-radius: 20px; }

    .post-body { padding: .5rem 1.25rem 1rem; }
    .post-title { font-size: 1.1rem; font-weight: 700; color: #1c1e21; margin-bottom: .4rem; }
    .post-desc { font-size: .92rem; color: #4b4d50; line-height: 1.5; margin-bottom: .75rem; }
    .post-rfq-no {
      display: inline-flex; align-items: center; gap: .3rem;
      font-size: .78rem; font-weight: 600; color: #6532C9;
      background: #f4f2ff; padding: .25rem .75rem; border-radius: 6px;
      margin-bottom: .75rem;
    }

    /* Items Table in Post */
    .post-items-table {
      width: 100%; border-collapse: separate; border-spacing: 0;
      border: 1px solid #e4e6eb; border-radius: 10px; overflow: hidden;
      font-size: .88rem;
    }
    .post-items-table th { background: #f7f8fa; padding: .6rem .8rem; font-weight: 600; color: #65676b; text-align: left; border-bottom: 1px solid #e4e6eb; }
    .post-items-table td { padding: .65rem .8rem; border-bottom: 1px solid #f0f2f5; color: #1c1e21; }
    .post-items-table tr:last-child td { border-bottom: none; }

    /* Footer / Engagement */
    .post-engagement {
      display: flex; justify-content: space-between; align-items: center;
      padding: .6rem 1.25rem; font-size: .82rem; color: #65676b;
      border-top: 1px solid #f0f2f5;
    }
    .post-actions { display: flex; border-top: 1px solid #e4e6eb; }
    .btn-post-action {
      flex: 1; background: none; border: none; padding: .75rem .5rem;
      display: flex; align-items: center; justify-content: center; gap: .5rem;
      font-weight: 600; color: #65676b; font-size: .9rem; transition: background .15s;
    }
    .btn-post-action:hover { background: #f0f2f5; color: #1c1e21; }
    .btn-post-action.primary { color: #6532C9; }
    .btn-post-action.primary:hover { background: #f4f2ff; }
    .btn-post-action + .btn-post-action { border-left: 1px solid #e4e6eb; }

    .btn-violet { background: #6532C9; color: #fff; border: none; font-weight: 600; border-radius: 8px; padding: .5rem 1.25rem; }
    .btn-violet:hover { background: #5b21b6; color: #fff; }

    #mdlCreateRFQ .modal-content { border-radius: 16px; border: none; }
    #mdlCreateRFQ .modal-header { border-bottom: 1px solid #f0f2f5; padding: 1.25rem 1.5rem; }
  </style>
</head>
<body class="saas-page">
<div class="container-fluid p-0">
  <div class="row g-0">

    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="col main-content">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <div class="d-flex align-items-center gap-3">
          <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2" aria-label="Toggle sidebar">
            <ion-icon name="menu-outline"></ion-icon>
          </button>
          <h2 class="m-0 d-flex align-items-center gap-2 page-title"><ion-icon name="document-text-outline"></ion-icon> Quotation Management</h2>
        </div>
        <div class="profile-menu" data-profile-menu>
          <button class="profile-trigger" type="button" data-profile-trigger aria-expanded="false" aria-haspopup="true">
            <img src="../img/profile.jpg" class="rounded-circle" width="36" height="36" alt="">
            <div class="profile-text">
              <div class="profile-name"><?= h($userName) ?></div>
              <div class="profile-role"><?= h($userRole) ?></div>
            </div>
            <ion-icon class="profile-caret" name="chevron-down-outline"></ion-icon>
          </button>
          <div class="profile-dropdown" data-profile-dropdown role="menu">
            <a href="<?= u('auth/logout.php') ?>" role="menuitem">Sign out</a>
          </div>
        </div>
      </div>

      <!-- Top metrics -->
      <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
          <div class="metric-card d-flex align-items-center gap-3">
            <div class="post-avatar" style="width:40px; height:40px; background:#f4f2ff; color:#6532C9">
              <ion-icon name="documents-outline"></ion-icon>
            </div>
            <div>
              <div class="label">Total Requests</div>
              <div class="value"><?= count($rfqs) ?></div>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="metric-card d-flex align-items-center gap-3">
            <div class="post-avatar" style="width:40px; height:40px; background:#e7f6ec; color:#1a7f37">
              <ion-icon name="send-outline"></ion-icon>
            </div>
            <div>
              <div class="label">Open / Sent</div>
              <div class="value"><?= array_sum(array_map(fn($r)=>strtolower($r['status'])==='sent'?1:0,$rfqs)) ?></div>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="metric-card d-flex align-items-center gap-3">
            <div class="post-avatar" style="width:40px; height:40px; background:#fff8e1; color:#f59e0b">
              <ion-icon name="trophy-outline"></ion-icon>
            </div>
            <div>
              <div class="label">Awarded</div>
              <div class="value"><?= array_sum(array_map(fn($r)=>strtolower($r['status'])==='awarded'?1:0,$rfqs)) ?></div>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3 d-flex align-items-center justify-content-md-end">
          <button class="btn btn-violet w-100 w-md-auto py-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#mdlCreateRFQ">
            <ion-icon name="add-circle-outline"></ion-icon> Post New Quotation
          </button>
        </div>
      </div>

      <div class="feed-container">
        <!-- Feed Header with Search -->
        <div class="feed-header">
          <div class="feed-search-wrap">
            <ion-icon name="search-outline"></ion-icon>
            <input type="text" id="tblSearch" placeholder="Search by Quotation No or Title…">
          </div>
          <div class="text-muted small d-none d-md-block">Showing latest <?= count($rfqs) ?> requests</div>
        </div>

        <!-- RFQ Feed -->
        <div id="rfqFeed">
          <?php if (!$rfqs): ?>
            <div class="post-card p-5 text-center text-muted">
              <ion-icon name="document-text-outline" style="font-size:3rem; opacity:.2"></ion-icon>
              <p class="mt-2">No quotation requests created yet.</p>
            </div>
          <?php else: foreach ($rfqs as $r): 
            $items = $rfqItems[(int)$r['id']] ?? [];
            $tr = time_remaining($r['due_at']);
          ?>
            <div class="post-card rfq-post" data-title="<?= h($r['title']) ?>" data-no="<?= h($r['rfq_no']) ?>">
              <div class="post-header">
                <div class="post-avatar">
                  <ion-icon name="person-outline"></ion-icon>
                </div>
                <div class="post-user-info">
                  <div class="post-user-name">
                    TNVS Procurement
                    <span class="ms-auto"><?= badge($r['status']) ?></span>
                  </div>
                  <div class="post-meta-line">
                    <ion-icon name="time-outline"></ion-icon>
                    <?= time_ago($r['created_at']) ?>
                    <span>•</span>
                    <ion-icon name="calendar-outline"></ion-icon>
                    Due: <?= $r['due_at'] ? date('M d, Y', strtotime($r['due_at'])) : '-' ?>
                  </div>
                </div>
              </div>

              <div class="post-body">
                <div class="post-rfq-no">
                  <ion-icon name="finger-print-outline"></ion-icon>
                  <?= h($r['rfq_no']) ?>
                </div>
                <div class="post-title"><?= h($r['title']) ?></div>
                <?php if ($r['description']): ?>
                  <div class="post-desc text-muted small"><?= h(mb_strimwidth($r['description'],0,180,'...')) ?></div>
                <?php endif; ?>

                <?php if ($items): ?>
                  <table class="post-items-table mt-2">
                    <thead>
                      <tr><th>Item</th><th class="text-end">Qty</th></tr>
                    </thead>
                    <tbody>
                      <?php foreach (array_slice($items,0,3) as $it): ?>
                        <tr>
                          <td><?= h($it['item']) ?> <span class="text-muted small">(<?= h($it['specs']) ?>)</span></td>
                          <td class="text-end fw-bold">₱ <?= (float)$it['qty'] ?> <?= h($it['uom']) ?></td>
                        </tr>
                      <?php endforeach; ?>
                      <?php if (count($items) > 3): ?>
                        <tr><td colspan="2" class="text-center text-primary small fw-bold">+ <?= count($items) - 3 ?> more items</td></tr>
                      <?php endif; ?>
                    </tbody>
                  </table>
                <?php endif; ?>
              </div>

              <div class="post-engagement">
                <div class="d-flex align-items-center gap-3">
                  <span class="d-flex align-items-center gap-1">
                    <ion-icon name="people-outline"></ion-icon> <?= (int)$r['invited_count'] ?> invited
                  </span>
                  <span class="d-flex align-items-center gap-1 text-primary fw-bold">
                    <ion-icon name="pricetag-outline"></ion-icon> <?= (int)$r['quoted_count'] ?> bids
                  </span>
                </div>
                <div class="text-<?= $tr['urgent'] ? 'danger' : 'muted' ?> fw-semibold">
                  <?= $tr['label'] ?>
                </div>
              </div>

              <div class="post-actions">
                <button class="btn-post-action primary" onclick="openRFQModal(<?= (int)$r['id'] ?>)">
                  <ion-icon name="eye-outline"></ion-icon> View & Evaluate
                </button>
                <button class="btn-post-action">
                  <ion-icon name="share-social-outline"></ion-icon> Share
                </button>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ====== Create Quotation Request (Guaranteed Scrollable) ====== -->
<div class="modal fade" id="mdlCreateRFQ" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form id="createRfqForm">
        <div class="modal-header">
          <h5 class="modal-title d-flex align-items-center gap-2">
            <ion-icon name="add-circle-outline"></ion-icon> Post Quotation Request
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
          </div>

          <hr class="my-4">

          <h6 class="fw-semibold mb-2">Quotation Items <span class="text-danger">*</span></h6>
          <div id="itemsWrap" class="vstack gap-2"></div>
          <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="btnAddItem">
            <ion-icon name="add-outline"></ion-icon> Add Item
          </button>

          <hr class="my-4">
          <div class="alert alert-info">
            <ion-icon name="information-circle-outline"></ion-icon>
            This request will be posted to the <strong>Vendor Portal</strong>. All approved vendors will be able to view and submit bids.
          </div>
        </div>

        <div class="modal-footer">
          <button class="btn btn-outline-secondary" data-bs-dismiss="modal" type="button">Cancel</button>
          <button class="btn btn-violet" type="submit">
            <ion-icon name="cloud-upload-outline"></ion-icon> Post to Portal
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ====== Quotation Detail (Scrollable) ====== -->
<div class="modal fade" id="mdlRFQDetail" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title d-flex align-items-center gap-2">
          <ion-icon name="document-text-outline"></ion-icon> <span id="rfqModalTitle">Quotation Details</span>
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
<script src="../js/profile-dropdown.js"></script>
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

  /* —— client-side feed search —— */
  $('#tblSearch')?.addEventListener('input', e=>{
    const q = e.target.value.toLowerCase();
    $$('.rfq-post').forEach(card=>{
      const title = card.dataset.title.toLowerCase();
      const rfqNo = card.dataset.no.toLowerCase();
      card.style.display = (title.includes(q) || rfqNo.includes(q)) ? '' : 'none';
    });
  });

  /* —— Create RFQ modal: dynamic items —— */
  const itemsWrap = $('#itemsWrap');
  const btnAdd = $('#btnAddItem');
  const categoryOptions = `<?php foreach ($categories as $c): ?><option value="<?= h($c) ?>"><?= h($c) ?></option><?php endforeach; ?>`;

  function addItemRow(data={item:'',specs:'',qty:'1'}){
    const row = document.createElement('div');
    row.className = 'row g-2 align-items-end item-row';
    row.innerHTML = `
      <div class="col-md-4">
        <label class="form-label small">Item</label>
        <input class="form-control" name="item[]" value="${esc(data.item)}" required>
      </div>
      <div class="col-md-4">
        <label class="form-label small">Specs / Description</label>
        <input class="form-control" name="specs[]" value="${esc(data.specs)}">
      </div>
      <div class="col-md-2">
        <label class="form-label small">Qty</label>
        <input class="form-control" name="qty[]" type="number" min="0" step="any" value="${esc(data.qty)}" required>
      </div>
      <div class="col-md-2">
        <label class="form-label small">Category</label>
        <select class="form-select" name="category[]" required>
          <option value="">Select</option>
          ${categoryOptions}
        </select>
      </div>
      <div class="col-12">
        <button type="button" class="btn btn-link text-danger p-0 small" onclick="this.closest('.item-row').remove()">
          <ion-icon name="trash-outline"></ion-icon> remove
        </button>
      </div>`;
    itemsWrap?.appendChild(row);
    const sel = row.querySelector('select[name="category[]"]');
    if (sel && data.category) sel.value = data.category;
  }
  btnAdd?.addEventListener('click', addItemRow);
  if (itemsWrap && !itemsWrap.children.length) addItemRow();


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
      toast('Quotation request created • ' + (j.rfq_no || ''), 'success');
      setTimeout(()=> location.reload(), 400);
    } catch (e) {
      errEl.textContent = e.message || 'Failed to create quotation request';
      errEl.classList.remove('d-none');
    } finally {
      btn.disabled = false; btn.innerHTML = prev;
    }
  });

  /* —— View RFQ modal —— */
  window.openRFQModal = async (id)=>{
    const modal = bootstrap.Modal.getOrCreateInstance($('#mdlRFQDetail'));
    const body = $('#rfqModalBody'), title = $('#rfqModalTitle'), status = $('#rfqModalStatus');
    title.textContent = 'Quotation Details'; status.innerHTML = '';
    body.innerHTML = `<div class="text-center text-muted py-5"><div class="spinner-border text-primary"></div><div class="mt-2">Loading...</div></div>`;
    modal.show();
    try{
      const res = await fetch(`?ajax=rfq_detail&id=${id}`);
      const j = await res.json();
      if (!res.ok || j.error) throw new Error(j.error || 'Load failed');

      const { rfq, items=[], suppliers=[], quotes=[], excluded=[] } = j;
      title.textContent = `Quotation ${esc(rfq.rfq_no || ('#'+id))}`;
      status.innerHTML = badgeHTML(rfq.status);

      const itemsHTML = items.length
        ? `<div class="table-responsive"><table class="table table-sm align-middle">
             <thead><tr><th>#</th><th>Item</th><th>Specs</th><th>Category</th><th class="text-end">Qty</th></tr></thead>
             <tbody>${items.map(r=>`<tr><td>${r.line_no}</td><td>${esc(r.item)}</td><td class="text-muted">${esc(r.specs)}</td><td>${esc(r.category||'')}</td><td class="text-end fw-bold">${Number(r.qty)} ${esc(r.uom||'')}</td></tr>`).join('')}</tbody>
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

      const excludedHTML = excluded.length
        ? `<div class="list-group list-group-flush">
             ${excluded.map(s=>`
               <div class="list-group-item px-0">
                 <div class="fw-semibold">${esc(s.company_name)}</div>
                 <div class="small text-muted">${esc(s.email||'')}</div>
                 <div class="small text-danger">Auto-excluded: ${esc(s.reason||'')}</div>
               </div>`).join('')}
           </div>`
        : `<div class="text-muted">No auto-excluded suppliers.</div>`;

      const quotesHTML = quotes.length
        ? `<div class="table-responsive"><table class="table table-sm align-middle">
             <thead><tr><th>Supplier</th><th class="text-end">Total</th><th>Currency</th><th>Submitted</th><th class="text-end">Action</th></tr></thead>
             <tbody>${quotes.map(q=>{
               const isAwarded = (rfq.status.toLowerCase()==='awarded' && Number(q.vendor_id)===Number(rfq.awarded_vendor_id));
               const awardBtn = (rfq.status.toLowerCase()==='sent')
                 ? `<button class="btn btn-sm btn-violet px-3" onclick="awardQuote(${rfq.id}, ${q.vendor_id}, '${esc(q.supplier_name)}')">Award</button>`
                 : (isAwarded ? `<span class="badge bg-success"><ion-icon name="checkmark-done-outline"></ion-icon> Winner</span>` : '');

               return `<tr>
                <td><div class="fw-bold">${esc(q.supplier_name)}</div><div class="text-muted small">${esc(q.terms||'')}</div></td>
                <td class="text-end fw-bold text-violet">₱ ${Number(q.total||0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}</td>
                <td>${esc(q.currency||rfq.currency||'')}</td>
                <td class="small">${esc(q.created_at||'')}</td>
                <td class="text-end">${awardBtn}</td>
              </tr>`;
             }).join('')}</tbody>
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
            <h6 class="fw-semibold">Auto-excluded Suppliers</h6>
            ${excludedHTML}
            <hr class="my-4">
            <h6 class="fw-semibold">Quotes</h6>
            ${quotesHTML}
          </div>
        </div>`;
    }catch(e){
      body.innerHTML = `<div class="alert alert-danger">Error: ${esc(e.message||'Failed to load')}</div>`;
    }
  };

  /* —— Award Quote —— */
  window.awardQuote = async (rfqId, vendorId, vendorName)=>{
    if (!confirm(`Are you sure you want to award this quotation to "${vendorName}"?\n\nThis will automatically create a draft Purchase Order.`)) return;
    
    try {
      const fd = new FormData();
      fd.append('rfq_id', rfqId);
      fd.append('vendor_id', vendorId);
      fd.append('mode', 'overall');

      const res = await fetch('api/award_quote.php', { method:'POST', body: fd });
      const j = await res.json();
      if (!res.ok || j.error) throw new Error(j.error || 'Award failed');

      toast(`<strong>Award Complete!</strong><br>PO #${j.po_no} has been created in draft.`, 'success', 4000);
      setTimeout(()=> location.reload(), 1500);
    } catch (e) {
      toast(e.message, 'danger', 4000);
    }
  };
})();
</script>
</body>
</html>
