<?php
require_once __DIR__ . "/../../includes/config.php";
require_once __DIR__ . "/../../includes/auth.php";
require_once __DIR__ . "/../../includes/db.php";
require_login();
require_role(['admin', 'manager']);

$section = "warehousing";
$active = "stock";

$wms  = db('wms');
$pdo  = $wms;

/* ---- DB guards ---- */
function table_exists(PDO $pdo, string $name): bool {
    $st = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($name));
    return (bool)$st->fetchColumn();
}

$hasLoc = table_exists($pdo, "warehouse_locations");
$hasLvl = table_exists($pdo, "stock_levels");
$hasTx = table_exists($pdo, "stock_transactions");
$dbReady = $hasLoc && $hasLvl && $hasTx;

$txLimit = isset($_GET["tx_all"]) ? 200 : 20;

function qs(array $overrides = []): string
{
    $qs = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) {
            unset($qs[$k]);
        } else {
            $qs[$k] = $v;
        }
    }
    return "?" . http_build_query($qs);
}

/* ---- Data ---- */
$userName = $_SESSION["user"]["name"] ?? "Nicole Malitao";
$userRole = $_SESSION["user"]["role"] ?? "Warehouse Manager";

$items = $locations = $levels = $tx = [];
if ($dbReady) {
    // Select options
    $items = $pdo
        ->query("SELECT id, sku, name FROM inventory_items ORDER BY name")
        ->fetchAll(PDO::FETCH_ASSOC);
    $locations = $pdo
        ->query("SELECT id, name FROM warehouse_locations ORDER BY name")
        ->fetchAll(PDO::FETCH_ASSOC);

    // ---- Levels pagination/filter ----
    $lvlPer = max(5, min(100, (int) ($_GET["lvl_per"] ?? 10))); // 10 default, allow 5–100 :)

    $lvlPage = max(1, (int) ($_GET["lvl_page"] ?? 1));
    $lvlOff = ($lvlPage - 1) * $lvlPer;
    $locId = isset($_GET["loc_id"]) ? (int) $_GET["loc_id"] : 0;

    // Count grouped rows (one row per item+location, only if sum > 0)
    if ($locId) {
        $countSql = "
    SELECT COUNT(*) FROM (
      SELECT 1
      FROM stock_levels sl
      WHERE sl.location_id = :loc
      GROUP BY sl.item_id, sl.location_id
      HAVING SUM(sl.qty) > 0
    ) x
  ";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->bindValue(":loc", $locId, PDO::PARAM_INT);
    } else {
        $countSql = "
    SELECT COUNT(*) FROM (
      SELECT 1
      FROM stock_levels sl
      GROUP BY sl.item_id, sl.location_id
      HAVING SUM(sl.qty) > 0
    ) x
  ";
        $countStmt = $pdo->prepare($countSql);
    }
    $countStmt->execute();
    $lvlTotal = (int) $countStmt->fetchColumn();
    $lvlPages = max(1, (int) ceil($lvlTotal / $lvlPer));

    // Main query: AGGREGATE per item+location (qty), and join total per item
    $levelSql =
        "
  SELECT
    g.item_id,
    g.location_id,
    i.sku,
    i.name       AS item_name,
    i.category,
    i.reorder_level,
    wl.name      AS location_name,
    g.qty,               -- per-location sum
    t.total_qty          -- total across all locations
  FROM (
    SELECT sl.item_id, sl.location_id, SUM(sl.qty) AS qty
    FROM stock_levels sl
    " .
        ($locId ? "WHERE sl.location_id = :loc" : "") .
        "
    GROUP BY sl.item_id, sl.location_id
    HAVING SUM(sl.qty) > 0
  ) g
  JOIN inventory_items     i  ON i.id  = g.item_id
  JOIN warehouse_locations wl ON wl.id = g.location_id
  JOIN (
    SELECT item_id, SUM(qty) AS total_qty
    FROM stock_levels
    GROUP BY item_id
    HAVING SUM(qty) > 0
  ) t ON t.item_id = g.item_id
  ORDER BY i.name, wl.name
  LIMIT :lim OFFSET :off
";
    $stmt = $pdo->prepare($levelSql);
    if ($locId) {
        $stmt->bindValue(":loc", $locId, PDO::PARAM_INT);
    }
    $stmt->bindValue(":lim", $lvlPer, PDO::PARAM_INT);
    $stmt->bindValue(":off", $lvlOff, PDO::PARAM_INT);
    $stmt->execute();
    $levels = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Recent transactions (bind LIMIT safely)
    $txStmt = $pdo->prepare("
  SELECT st.id, st.item_id, st.from_location_id, st.to_location_id, st.qty, st.action, st.note, st.created_at,
         ii.sku, ii.name AS item_name,
         lf.name AS from_loc, lt.name AS to_loc
  FROM stock_transactions st
  JOIN inventory_items ii ON ii.id = st.item_id
  LEFT JOIN warehouse_locations lf ON lf.id = st.from_location_id
  LEFT JOIN warehouse_locations lt ON lt.id = st.to_location_id
  ORDER BY st.created_at DESC
  LIMIT :lim
");
    $txStmt->bindValue(":lim", (int) $txLimit, PDO::PARAM_INT);
    $txStmt->execute();
    $tx = $txStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Flash
$ok = isset($_GET["ok"]) ? htmlspecialchars($_GET["ok"]) : "";
$err = isset($_GET["err"]) ? htmlspecialchars($_GET["err"]) : "";

// Helpers
// u() is defined in sidebar.php which is included in the body.
// We only define h() if missing.
if (!function_exists('h')) {
    function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Stock Management | TNVS</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="../../css/style.css" rel="stylesheet" />
  <link href="../../css/modules.css" rel="stylesheet" />

  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <script src="../../js/sidebar-toggle.js"></script>

  <style>
    :root {
      --slate-50: #f8fafc;
      --slate-100: #f1f5f9;
      --slate-200: #e2e8f0;
      --slate-600: #475569;
      --slate-800: #1e293b;
      --primary-600: #4f46e5;
    }
    body { background-color: var(--slate-50); }

    /* Custom Table */
    .card-table { border: 1px solid var(--slate-200); border-radius: 1rem; background: white; box-shadow: 0 1px 3px rgba(0,0,0,0.05); overflow: hidden; }
    .table-custom thead th { 
      font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; 
      color: var(--slate-600); background: var(--slate-50); 
      border-bottom: 1px solid var(--slate-200); font-weight: 600; padding: 1rem 1.5rem;
    }
    .table-custom tbody td { 
      padding: 1rem 1.5rem; border-bottom: 1px solid var(--slate-100); 
      font-size: 0.95rem; color: var(--slate-800); vertical-align: middle;
    }
    .table-custom tr:last-child td { border-bottom: none; }
    .table-custom tr:hover td { background-color: #f8fafc; }
    
    .f-mono { font-family: 'SF Mono', 'Segoe UI Mono', 'Roboto Mono', monospace; letter-spacing: -0.5px; }
    .badge-stock { padding: 5px 10px; border-radius: 6px; font-weight: 600; font-size: 0.75rem; }
    .badge-stock.low { background: #fee2e2; color: #991b1b; }
    .badge-stock.ok { background: #dcfce7; color: #166534; }
  </style>
</head>
<body class="saas-page">
  <div class="container-fluid p-0">
    <div class="row g-0">

     <?php include __DIR__ . '/../../includes/sidebar.php' ?>

      <!-- Main Content -->
      <div class="col main-content p-3 p-lg-4">

        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="d-flex align-items-center gap-3">
              <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2">
                  <ion-icon name="menu-outline"></ion-icon>
              </button>
              <h2 class="m-0 d-flex align-items-center gap-2 page-title">
                  <ion-icon name="layers-outline"></ion-icon>Stock Management
              </h2>
            </div>
            
            <div class="d-flex align-items-center gap-3">
               <!-- History Button -->
               <button class="btn btn-white border shadow-sm d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#historyModal">
                  <ion-icon name="time-outline"></ion-icon>
                  <span>History</span>
               </button>

               <div class="profile-menu" data-profile-menu>
                <button class="profile-trigger" type="button" data-profile-trigger>
                <img src="../../img/profile.jpg" class="rounded-circle" width="36" height="36" alt="">
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
        </div>

        <div class="px-4 pb-5">
            <!-- Flash -->
            <?php if ($ok): ?>
              <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm"><ion-icon name="checkmark-circle-outline" class="me-2"></ion-icon><?= $ok ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>
            <?php if ($err): ?>
              <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm"><ion-icon name="alert-circle-outline" class="me-2"></ion-icon><?= $err ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>

            <?php if (!$dbReady): ?>
              <div class="alert alert-warning border-0 shadow-sm">
                <b>Setup Required:</b> Database tables (warehouse_locations, stock_levels, stock_transactions) are missing.
              </div>
            <?php else: ?>

            <!-- Action Bar -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                 <!-- Actions -->
                  <div class="d-flex gap-2">
                    <button class="btn btn-primary d-flex align-items-center gap-2 px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#inModal">
                      <ion-icon name="arrow-down-circle-outline"></ion-icon> Stock In
                    </button>
                    <button class="btn btn-white border shadow-sm d-flex align-items-center gap-2 px-3" data-bs-toggle="modal" data-bs-target="#outModal">
                      <ion-icon name="arrow-up-circle-outline"></ion-icon> Stock Out
                    </button>
                    <button class="btn btn-white border shadow-sm d-flex align-items-center gap-2 px-3" data-bs-toggle="modal" data-bs-target="#transferModal">
                      <ion-icon name="swap-horizontal-outline"></ion-icon> Transfer
                    </button>
                  </div>

                  <!-- Quick Filters -->
                  <form method="get" class="d-flex gap-2 align-items-center" id="levelsFilter">
                       <select name="loc_id" class="form-select" style="min-width: 180px;" onchange="this.form.submit()">
                        <option value="">All Locations</option>
                        <?php foreach ($locations as $l): ?>
                          <option value="<?= (int) $l["id"] ?>" <?= $locId === (int) $l["id"] ? "selected" : "" ?>>
                            <?= h($l["name"]) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                  </form>
            </div>

            <!-- Levels Table -->
            <div class="card-table">
               <div class="table-responsive">
                  <table class="table table-custom mb-0 align-middle">
                    <thead>
                      <tr>
                        <th>SKU</th>
                        <th>Item Name</th>
                        <th>Location</th>
                        <th class="text-end" style="width:120px">Total Stock</th>
                        <th class="text-end" style="width:120px">In Location</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if (!$levels): ?>
                        <tr><td colspan="5" class="text-center py-5 text-muted">No stock data found. Use "Stock In" to add items.</td></tr>
                      <?php else: foreach ($levels as $r): 
                          $total = (int)$r['total_qty'];
                          $reorder = (int)$r['reorder_level'];
                          $isLow = $total <= $reorder;
                      ?>
                        <tr>
                          <td class="f-mono fw-semibold text-primary"><?= h($r["sku"]) ?></td>
                          <td class="fw-medium text-dark"><?= h($r["item_name"]) ?></td>
                          <td><span class="badge bg-light text-dark border"><?= h($r["location_name"]) ?></span></td>
                          
                          <td class="text-end f-mono">
                             <?php if($isLow): ?>
                               <span class="badge-stock low"><?= $total ?></span>
                             <?php else: ?>
                               <span class="badge-stock ok"><?= $total ?></span>
                             <?php endif; ?>
                          </td>
                          <td class="text-end f-mono fw-bold text-dark"><?= (int)$r['qty'] ?></td>
                        </tr>
                      <?php endforeach; endif; ?>
                    </tbody>
                  </table>
               </div>
                <!-- Pagination -->
                <div class="d-flex justify-content-between align-items-center p-3 border-top bg-light bg-opacity-50">
                    <div class="small text-muted">
                        Page <?= $lvlPage ?> of <?= $lvlPages ?> • <?= $lvlTotal ?> row(s)
                    </div>
                    <nav>
                      <ul class="pagination pagination-sm mb-0 shadow-sm">
                        <li class="page-item <?= $lvlPage <= 1 ? "disabled" : "" ?>">
                          <a class="page-link" href="<?= qs(["lvl_page" => $lvlPage - 1]) ?>">&laquo;</a>
                        </li>
                        <li class="page-item disabled"><a class="page-link border-0 bg-transparent text-muted"><?= $lvlPage ?></a></li>
                         <li class="page-item <?= $lvlPage >= $lvlPages ? "disabled" : "" ?>">
                          <a class="page-link" href="<?= qs(["lvl_page" => $lvlPage + 1]) ?>">&raquo;</a>
                        </li>
                      </ul>
                    </nav>
                </div>
            </div>

            <?php endif; ?>
        </div>

      </div>
    </div>
  </div>

  <!-- History Modal -->
  <div class="modal fade" id="historyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
      <div class="modal-content border-0 shadow">
        <div class="modal-header">
           <div>
             <h5 class="modal-title fw-bold">Stock History</h5>
             <div class="text-muted small">Recent movements and adjustments</div>
           </div>
           <button class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body p-0">
           <table class="table table-hover align-middle mb-0">
             <thead class="bg-light sticky-top" style="z-index: 2;">
               <tr>
                 <th class="ps-4">Date</th>
                 <th>SKU</th>
                 <th>Action</th>
                 <th>From</th>
                 <th>To</th>
                 <th class="text-end pe-4">Qty</th>
                 <th>Note</th>
               </tr>
             </thead>
             <tbody>
               <?php if (!$tx): ?>
                 <tr><td colspan="7" class="text-center py-5 text-muted">No history available.</td></tr>
               <?php else: foreach ($tx as $t): ?>
                 <tr>
                   <td class="ps-4 text-muted small"><?= date('M d, H:i', strtotime($t['created_at'])) ?></td>
                   <td class="fw-medium text-dark"><?= h($t['sku']) ?></td>
                   <td><span class="badge bg-secondary bg-opacity-10 text-secondary border"><?= h($t['action']) ?></span></td>
                   <td class="small"><?= h($t['from_loc']??'—') ?></td>
                   <td class="small"><?= h($t['to_loc']??'—') ?></td>
                   <td class="text-end pe-4 f-mono fw-bold"><?= (int)$t['qty'] ?></td>
                   <td class="small text-muted"><?= h($t['note']??'') ?></td>
                 </tr>
               <?php endforeach; endif; ?>
             </tbody>
           </table>
        </div>
        <div class="modal-footer bg-light">
           <a href="?tx_all=1" class="btn btn-sm btn-link text-decoration-none">Load All History</a>
           <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>

  <?php include __DIR__ . "/partials/stock_modals.php"; ?>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="../../js/profile-dropdown.js"></script>
</body>
</html>
