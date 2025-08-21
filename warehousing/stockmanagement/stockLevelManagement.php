<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();



/* ---- DB guards ---- */
function table_exists(PDO $pdo, string $name): bool
{
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$name]);
    return (bool) $stmt->fetchColumn();
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
</head>
<body>
  <div class="container-fluid p-0">
    <div class="row g-0">

      <!-- Sidebar -->
<div class="sidebar d-flex flex-column">
  <div class="d-flex justify-content-center align-items-center mb-4 mt-3">
    <img src="../../img/logo.png" id="logo" class="img-fluid me-2" style="height:55px" alt="Logo">
  </div>

  <h6 class="text-uppercase mb-2">Smart Warehousing</h6>

  <nav class="nav flex-column px-2 mb-4">
    <a class="nav-link" href="../warehouseDashboard.php">
      <ion-icon name="home-outline"></ion-icon><span>Dashboard</span>
    </a>

    <a class="nav-link" href="../inventory/inventoryTracking.php">
      <ion-icon name="cube-outline"></ion-icon><span>Track Inventory</span>
    </a>

    <a class="nav-link active" href="./stockLevelManagement.php">
      <ion-icon name="layers-outline"></ion-icon><span>Stock Management</span>
    </a>

    <a class="nav-link" href="../TrackShipment/shipmentTracking.php">
      <ion-icon name="paper-plane-outline"></ion-icon><span>Track Shipments</span>
    </a>

    <a class="nav-link" href="../warehouseReports.php">
      <ion-icon name="file-tray-stacked-outline"></ion-icon><span>Reports</span>
    </a>

    <a class="nav-link" href="../warehouseSettings.php">
      <ion-icon name="settings-outline"></ion-icon><span>Settings</span>
    </a>
  </nav>

  <!-- Logout -->
  <div class="logout-section">
    <a class="nav-link text-danger" href="<?= BASE_URL ?>/auth/logout.php">
      <ion-icon name="log-out-outline"></ion-icon> Logout
    </a>
  </div>
</div>

      <!-- Main Content -->
      <div class="col main-content p-3 p-lg-4">

        <!-- Topbar  -->
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div class="d-flex align-items-center gap-3">
            <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2" aria-label="Toggle sidebar">
              <ion-icon name="menu-outline"></ion-icon>
            </button>
           <h2 class="m-0 d-flex align-items-center gap-2">
        <ion-icon name="layers-outline"></ion-icon>Stock Management
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

        <!-- Flash -->
        <?php if ($ok): ?>
          <div class="alert alert-success alert-dismissible fade show"><?= $ok ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($err): ?>
          <div class="alert alert-danger alert-dismissible fade show"><?= $err ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>

        <!-- DB Not Ready -->
        <?php if (!$dbReady): ?>
          <div class="alert alert-warning">
            Database not initialized for Stock Management.
            Please run the migration SQL for <b>warehouse_locations</b>, <b>stock_levels</b>, and <b>stock_transactions</b>.
          </div>
        <?php endif; ?>

        <?php if ($dbReady): ?>
        <!-- Action Buttons -->
        <section class="mb-3">
          <div class="d-flex gap-2">
            <button class="btn btn-violet" data-bs-toggle="modal" data-bs-target="#inModal">
              <ion-icon name="download-outline"></ion-icon> Stock In
            </button>
            <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#outModal">
              <ion-icon name="exit-outline"></ion-icon> Stock Out
            </button>
            <button class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#transferModal">
              <ion-icon name="swap-horizontal-outline"></ion-icon> Transfer
            </button>
          </div>
        </section>

        <!-- Current Stock Levels -->
        <section class="card shadow-sm mb-3">
          <div class="card-body">
            <h5 class="mb-3">Current Stock Levels</h5>
            

             <!-- Filters for Levels -->
<form method="get" class="row g-2 align-items-center mb-2" id="levelsFilter">
  <div class="col-12 col-md-auto">
    <select name="loc_id" class="form-select form-select-sm" onchange="this.form.submit()">
      <option value="">All Locations</option>
      <?php foreach ($locations as $l): ?>
        <option value="<?= (int) $l["id"] ?>" <?= $locId === (int) $l["id"]
    ? "selected"
    : "" ?>>
          <?= htmlspecialchars($l["name"]) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="col-12 col-md-auto">
    <select name="lvl_per" class="form-select form-select-sm" onchange="this.form.submit()">
      <?php foreach ([10, 25, 50, 100] as $opt): ?>
        <option value="<?= $opt ?>" <?= $lvlPer === $opt
    ? "selected"
    : "" ?>>Show <?= $opt ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <?php if (isset($_GET["tx_all"])): ?>
    <input type="hidden" name="tx_all" value="1">
  <?php endif; ?>
</form>
            <div class="table-responsive levels-scroll">
              <table class="table align-middle">
                <thead>
                  <tr>
                    <th>SKU</th>
                    <th>Name</th>
                    <th>Category</th>
                    <th>Location</th>
                    <th class="text-end">Total</th>
                    <th class="text-end">Qty</th>

                  </tr>

                 


                </thead>
                <tbody>
                  <?php if (!$levels): ?>
                    <tr><td colspan="6" class="text-center py-4 text-muted">No stock levels yet.</td></tr>
                  <?php else:foreach ($levels as $r): ?>
                    <tr>
                      <td><?= htmlspecialchars($r["sku"]) ?></td>
                      <td><?= htmlspecialchars($r["item_name"]) ?></td>
                      <td><?= htmlspecialchars($r["category"]) ?></td>
                      <td><?= htmlspecialchars($r["location_name"]) ?></td>

                      
                      <td class="text-end">
                        <?= (int) $r["total_qty"] ?>
                        <?php if (
                            (int) $r["total_qty"] <= (int) $r["reorder_level"]
                        ): ?>
                          <span class="badge bg-warning ms-1">Low</span>
                        <?php endif; ?>
                      </td>

                    
                    <td class="text-end"><?= (int) $r["qty"] ?></td>

                    </tr>
                  <?php endforeach;endif; ?>
                </tbody>
              </table>
            </div>

          

<div class="d-flex justify-content-between align-items-center mt-2">
  <div class="small text-muted">
    Page <?= $lvlPage ?> of <?= $lvlPages ?> • <?= $lvlTotal ?> row(s)
  </div>

  <nav aria-label="Levels pages">
    <ul class="pagination pagination-sm mb-0">
      <li class="page-item <?= $lvlPage <= 1 ? "disabled" : "" ?>">
        <a class="page-link" href="<?= $lvlPage <= 1
            ? "#"
            : qs(["lvl_page" => $lvlPage - 1]) ?>">&laquo;</a>
      </li>

      <?php for (
          $p = max(1, $lvlPage - 2);
          $p <= min($lvlPages, $lvlPage + 2);
          $p++
      ): ?>
        <li class="page-item <?= $p === $lvlPage ? "active" : "" ?>">
          <a class="page-link" href="<?= qs([
              "lvl_page" => $p,
          ]) ?>"><?= $p ?></a>
        </li>
      <?php endfor; ?>

      <li class="page-item <?= $lvlPage >= $lvlPages ? "disabled" : "" ?>">
        <a class="page-link" href="<?= $lvlPage >= $lvlPages
            ? "#"
            : qs(["lvl_page" => $lvlPage + 1]) ?>">&raquo;</a>
      </li>
    </ul>
  </nav>
</div>
  </div>
</section>

        <!-- Recent Transactions -->
        <section class="card shadow-sm">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="mb-0">Recent Transactions</h5>
  <?php if (!isset($_GET["tx_all"])): ?>
    <a class="btn btn-sm btn-outline-secondary" href="?tx_all=1">Show all</a>
  <?php else: ?>
    <a class="btn btn-sm btn-outline-secondary" href="stockLevelManagement.php">Show less</a>
  <?php endif; ?>
</div>

            <div class="table-responsive tx-scroll">
              <table class="table align-middle">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>When</th>
                    <th>Item</th>
                    <th>Action</th>
                    <th>From</th>
                    <th>To</th>
                    <th class="text-end">Qty</th>
                    <th>Note</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$tx): ?>
                    <tr><td colspan="8" class="text-center py-4 text-muted">No transactions yet.</td></tr>
                  <?php else:foreach ($tx as $t): ?>
                    <tr>
                      <td>#<?= (int) $t["id"] ?></td>
                      <td><?= htmlspecialchars(
                          date("Y-m-d H:i", strtotime($t["created_at"]))
                      ) ?></td>
                      <td><?= htmlspecialchars(
                          $t["sku"] . " — " . $t["item_name"]
                      ) ?></td>
                      <td><?= htmlspecialchars($t["action"]) ?></td>
                      <td><?= htmlspecialchars($t["from_loc"] ?? "—") ?></td>
                      <td><?= htmlspecialchars($t["to_loc"] ?? "—") ?></td>
                      <td class="text-end"><?= (int) $t["qty"] ?></td>
                      <td><?= htmlspecialchars($t["note"] ?? "") ?></td>
                    </tr>
                  <?php endforeach;endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </section>
        <?php endif; ?>

      </div>
    </div>
  </div>


  <?php include __DIR__ . "/partials/stock_modals.php"; ?>


  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>