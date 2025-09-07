  <?php
  require_once __DIR__ . '/../includes/config.php';
  require_once __DIR__ . '/../includes/auth.php';

  require_login();
  require_role(['admin']);

  $active = 'dashboard';

  /* -------------------- Helpers (shared) -------------------- */
  function table_exists(PDO $pdo, string $name): bool
  {
      try {
          $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
          $stmt->execute([$name]);
          return (bool) $stmt->fetchColumn();
      } catch (Throwable $e) {
          return false;
      }
  }
  function column_exists(PDO $pdo, string $table, string $col): bool
  {
      try {
          $s = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
          $s->execute([$col]);
          return (bool) $s->fetchColumn();
      } catch (Throwable $e) {
          return false;
      }
  }
  function fetch_val(PDO $pdo, string $sql, array $params = [], $fallback = 0)
  {
      try {
          $st = $pdo->prepare($sql);
          $st->execute($params);
          $v = $st->fetchColumn();
          return $v !== false ? $v : $fallback;
      } catch (Throwable $e) {
          return $fallback;
      }
  }
  function qall(PDO $pdo, string $sql, array $bind = [])
  {
      try {
          $st = $pdo->prepare($sql);
          $st->execute($bind);
          return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
      } catch (Throwable $e) {
          return [];
      }
  }

  /* -------------------- CURRENT USER (optional) -------------------- */
  $userName = "Admin";
  $userRole = "System Admin";
  if (function_exists("current_user")) {
      $u = current_user();
      $userName = $u["name"] ?? $userName;
      $userRole = $u["role"] ?? $userRole;
  }

  /* ================================================================
    SECTION A: SMART WAREHOUSING (from your SW dashboard)
  ================================================================ */
  $sw_hasItems = table_exists($pdo, "inventory_items");
  $sw_hasLvl = table_exists($pdo, "stock_levels");
  $sw_hasTx = table_exists($pdo, "stock_transactions");
  $sw_hasLoc = table_exists($pdo, "warehouse_locations");
  $sw_hasShip = table_exists($pdo, "shipments");

  /* KPIs */
  $sw_totalSkus = $sw_hasItems
      ? (int) fetch_val(
          $pdo,
          "SELECT COUNT(*) FROM inventory_items WHERE archived=0",
          []
      )
      : 0;
  $sw_totalUnits = $sw_hasLvl
      ? (int) fetch_val($pdo, "SELECT COALESCE(SUM(qty),0) FROM stock_levels")
      : 0;
  $sw_locationsCount = $sw_hasLoc
      ? (int) fetch_val($pdo, "SELECT COUNT(*) FROM warehouse_locations")
      : 0;

  $sw_lowStockCount = 0;
  if ($sw_hasItems && $sw_hasLvl) {
      $sw_lowStockCount = (int) fetch_val(
          $pdo,
          "
      SELECT COUNT(*) FROM (
        SELECT i.id, i.reorder_level, COALESCE(SUM(l.qty),0) total
        FROM inventory_items i
        LEFT JOIN stock_levels l ON l.item_id=i.id
        WHERE i.archived=0
        GROUP BY i.id, i.reorder_level
        HAVING i.reorder_level>0 AND COALESCE(SUM(l.qty),0) <= i.reorder_level
      ) x
    "
      );
  }

  /* Charts: On-hand by Category */
  $sw_catLabels = ["Raw", "Packaging", "Finished"];
  $sw_catData = [0, 0, 0];
  if ($sw_hasLvl && $sw_hasItems) {
      $rows = qall(
          $pdo,
          "
      SELECT i.category, COALESCE(SUM(l.qty),0) qty
      FROM stock_levels l JOIN inventory_items i ON i.id=l.item_id
      WHERE i.archived=0
      GROUP BY i.category
    "
      );
      $tmp = [];
      foreach ($rows as $r) {
          $tmp[$r["category"]] = (int) $r["qty"];
      }
      foreach ($sw_catLabels as $i => $lab) {
          $sw_catData[$i] = $tmp[$lab] ?? 0;
      }
  }

  /* Charts: 30-day movements */
  $sw_trendLabels = [];
  $sw_incoming = [];
  $sw_outgoing = [];
  $tz = new DateTimeZone("Asia/Manila");
  $today = new DateTime("today", $tz);
  $map = [];
  for ($i = 29; $i >= 0; $i--) {
      $d = clone $today;
      $d->modify("-$i day");
      $k = $d->format("Y-m-d");
      $map[$k] = ["in" => 0, "out" => 0];
  }
  if ($sw_hasTx) {
      $rows = qall(
          $pdo,
          "
      SELECT DATE(created_at) d,
            SUM(CASE WHEN qty>0 THEN qty ELSE 0 END) incoming,
            SUM(CASE WHEN qty<0 THEN -qty ELSE 0 END) outgoing
      FROM stock_transactions
      WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
      GROUP BY DATE(created_at)
      ORDER BY DATE(created_at)
    "
      );
      foreach ($rows as $r) {
          $k = $r["d"];
          if (isset($map[$k])) {
              $map[$k]["in"] = (int) $r["incoming"];
              $map[$k]["out"] = (int) $r["outgoing"];
          }
      }
  }
  foreach ($map as $k => $io) {
      $d = DateTime::createFromFormat("Y-m-d", $k, $tz);
      $sw_trendLabels[] = $d ? $d->format("M j") : $k;
      $sw_incoming[] = $io["in"];
      $sw_outgoing[] = $io["out"];
  }

  /* Charts: On-hand by Location */
  $sw_locLabels = [];
  $sw_locData = [];
  if ($sw_hasLvl && $sw_hasLoc) {
      $rows = qall(
          $pdo,
          "
      SELECT w.name label, COALESCE(SUM(l.qty),0) qty
      FROM stock_levels l JOIN warehouse_locations w ON w.id=l.location_id
      GROUP BY w.id HAVING COALESCE(SUM(l.qty),0)>0
      ORDER BY qty DESC
    "
      );
      $top = 6;
      $sumOthers = 0;
      foreach ($rows as $i => $r) {
          if ($i < $top) {
              $sw_locLabels[] = $r["label"];
              $sw_locData[] = (int) $r["qty"];
          } else {
              $sumOthers += (int) $r["qty"];
          }
      }
      if ($sumOthers > 0) {
          $sw_locLabels[] = "Others";
          $sw_locData[] = $sumOthers;
      }
  }

  /* Charts: Shipment status */
  $sw_shipLabels = ["In Transit", "Delivered", "Delayed"];
  $sw_shipData = [0, 0, 0];
  if ($sw_hasShip) {
      $rows = qall(
          $pdo,
          "SELECT status, COUNT(*) c FROM shipments GROUP BY status"
      );
      $map = [];
      foreach ($rows as $r) {
          $map[$r["status"]] = (int) $r["c"];
      }
      foreach ($sw_shipLabels as $i => $s) {
          $sw_shipData[$i] = $map[$s] ?? 0;
      }
  }

  /* ================================================================
    SECTION B: PROCUREMENT (from your procurement dashboard)
  ================================================================ */
  $hasDB = isset($pdo) && $pdo instanceof PDO;

  $poHeaderTbl = null;
  $poItemTbl = null;
  if ($hasDB) {
      if (table_exists($pdo, "pos")) {
          $poHeaderTbl = "pos";
      } elseif (table_exists($pdo, "purchase_orders")) {
          $poHeaderTbl = "purchase_orders";
      }
      if (table_exists($pdo, "po_items")) {
          $poItemTbl = "po_items";
      } elseif (table_exists($pdo, "purchase_order_items")) {
          $poItemTbl = "purchase_order_items";
      }
  }
  $poDateCol = null;
  if ($poHeaderTbl) {
      foreach (["issue_date", "order_date", "created_at", "date"] as $c) {
          if (column_exists($pdo, $poHeaderTbl, $c)) {
              $poDateCol = $c;
              break;
          }
      }
  }
  $poTotalCol = null;
  if ($poHeaderTbl) {
      foreach (["total", "total_amount", "grand_total"] as $c) {
          if (column_exists($pdo, $poHeaderTbl, $c)) {
              $poTotalCol = $c;
              break;
          }
      }
  }
  $hasSup = $hasDB && table_exists($pdo, "suppliers");
  $hasRFQ = $hasDB && table_exists($pdo, "rfqs");
  $hasPR = $hasDB && table_exists($pdo, "procurement_requests");

  /* KPIs */
  $pr_activeSuppliers = $hasSup
      ? (int) fetch_val(
          $pdo,
          "SELECT COUNT(*) FROM suppliers WHERE IFNULL(is_active,1)=1"
      )
      : 0;
  $pr_openRFQs = $hasRFQ
      ? (int) fetch_val(
          $pdo,
          "SELECT COUNT(*) FROM rfqs WHERE status IN ('open','sent','pending','draft')"
      )
      : 0;
  $pr_openPOs = 0;
  if ($poHeaderTbl) {
      $pr_openPOs = (int) fetch_val(
          $pdo,
          "SELECT COUNT(*) FROM `$poHeaderTbl` WHERE LOWER(IFNULL(status,'')) IN ('draft','approved','ordered','partially_received')"
      );
  }
  $pr_pendingPRs = $hasPR
      ? (int) fetch_val(
          $pdo,
          "SELECT COUNT(*) FROM procurement_requests WHERE status IN ('pending','for_approval','approved','submitted')"
      )
      : 0;

  $pr_spendThisMonth = 0.0;
  if ($poHeaderTbl && $poDateCol) {
      if (
          $poItemTbl &&
          column_exists($pdo, $poItemTbl, "qty") &&
          column_exists($pdo, $poItemTbl, "price")
      ) {
          $joinKey = column_exists($pdo, $poItemTbl, "po_id")
              ? "po_id"
              : (column_exists($pdo, $poItemTbl, "purchase_order_id")
                  ? "purchase_order_id"
                  : null);
          if ($joinKey) {
              $pr_spendThisMonth = (float) fetch_val(
                  $pdo,
                  "
          SELECT COALESCE(SUM(i.qty*i.price),0)
          FROM `$poHeaderTbl` p JOIN `$poItemTbl` i ON i.$joinKey=p.id
          WHERE DATE_FORMAT(p.`$poDateCol`,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')
        ",
                  [],
                  0.0
              );
          }
      } elseif ($poTotalCol) {
          $pr_spendThisMonth = (float) fetch_val(
              $pdo,
              "
        SELECT COALESCE(SUM(p.`$poTotalCol`),0)
        FROM `$poHeaderTbl` p
        WHERE DATE_FORMAT(p.`$poDateCol`,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')
      ",
              [],
              0.0
          );
      }
  }

  /* Charts */
  $pr_poStatusLabels = [
      "draft",
      "approved",
      "ordered",
      "partially_received",
      "received",
      "closed",
      "cancelled",
  ];
  $pr_poStatusData = array_fill(0, count($pr_poStatusLabels), 0);
  if ($poHeaderTbl) {
      $rows = qall(
          $pdo,
          "SELECT LOWER(IFNULL(status,'')) s, COUNT(*) c FROM `$poHeaderTbl` GROUP BY s"
      );
      $map = [];
      foreach ($rows as $r) {
          $map[$r["s"]] = (int) $r["c"];
      }
      foreach ($pr_poStatusLabels as $i => $s) {
          $pr_poStatusData[$i] = $map[$s] ?? 0;
      }
  }

  $pr_monLabels = [];
  $pr_monAmounts = [];
  if ($poHeaderTbl && $poDateCol) {
      $first = new DateTime("first day of this month", $tz);
      $buckets = [];
      for ($i = 5; $i >= 0; $i--) {
          $d = (clone $first)->modify("-$i months");
          $ym = $d->format("Y-m");
          $pr_monLabels[] = $d->format("M Y");
          $buckets[$ym] = 0.0;
      }
      $sql = null;
      if (
          $poItemTbl &&
          column_exists($pdo, $poItemTbl, "qty") &&
          column_exists($pdo, $poItemTbl, "price")
      ) {
          $joinKey = column_exists($pdo, $poItemTbl, "po_id")
              ? "po_id"
              : (column_exists($pdo, $poItemTbl, "purchase_order_id")
                  ? "purchase_order_id"
                  : null);
          if ($joinKey) {
              $sql = "SELECT DATE_FORMAT(p.`$poDateCol`,'%Y-%m') ym, SUM(i.qty*i.price) amt
              FROM `$poHeaderTbl` p JOIN `$poItemTbl` i ON i.`$joinKey`=p.id
              WHERE p.`$poDateCol` >= DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL 5 MONTH)
              GROUP BY DATE_FORMAT(p.`$poDateCol`,'%Y-%m')";
          }
      } elseif ($poTotalCol) {
          $sql = "SELECT DATE_FORMAT(p.`$poDateCol`,'%Y-%m') ym, SUM(p.`$poTotalCol`) amt
            FROM `$poHeaderTbl` p
            WHERE p.`$poDateCol` >= DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL 5 MONTH)
            GROUP BY DATE_FORMAT(p.`$poDateCol`,'%Y-%m')";
      }
      if ($sql) {
          $rows = qall($pdo, $sql);
          foreach ($rows as $r) {
              $ym = $r["ym"];
              if (isset($buckets[$ym])) {
                  $buckets[$ym] = (float) $r["amt"];
              }
          }
      }
      foreach ($buckets as $amt) {
          $pr_monAmounts[] = (float) $amt;
      }
  }

  $pr_topSupLabels = [];
  $pr_topSupAmts = [];
  if ($hasSup && $poHeaderTbl && $poDateCol) {
      $sql = null;
      if (
          $poItemTbl &&
          column_exists($pdo, $poItemTbl, "qty") &&
          column_exists($pdo, $poItemTbl, "price") &&
          column_exists($pdo, $poHeaderTbl, "supplier_id")
      ) {
          $joinKey = column_exists($pdo, $poItemTbl, "po_id")
              ? "po_id"
              : (column_exists($pdo, $poItemTbl, "purchase_order_id")
                  ? "purchase_order_id"
                  : null);
          if ($joinKey) {
              $sql = "SELECT s.name, SUM(i.qty*i.price) amt
              FROM `$poHeaderTbl` p
              JOIN suppliers s ON s.id=p.supplier_id
              JOIN `$poItemTbl` i ON i.`$joinKey`=p.id
              WHERE p.`$poDateCol` >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
              GROUP BY s.id ORDER BY amt DESC LIMIT 6";
          }
      } elseif ($poTotalCol && column_exists($pdo, $poHeaderTbl, "supplier_id")) {
          $sql = "SELECT s.name, SUM(p.`$poTotalCol`) amt
            FROM `$poHeaderTbl` p JOIN suppliers s ON s.id=p.supplier_id
            WHERE p.`$poDateCol` >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
            GROUP BY s.id ORDER BY amt DESC LIMIT 6";
      }
      if ($sql) {
          $rows = qall($pdo, $sql);
          foreach ($rows as $r) {
              $pr_topSupLabels[] = $r["name"];
              $pr_topSupAmts[] = (float) $r["amt"];
          }
      }
  }

  /* ================================================================
    SECTION C: PLT (from your PLT dashboard)
  ================================================================ */
  $plt_kToday = (int) fetch_val(
      $pdo,
      "SELECT COUNT(*) FROM plt_shipments WHERE schedule_date = CURDATE()"
  );
  $plt_kWeek = (int) fetch_val(
      $pdo,
      "SELECT COUNT(*) FROM plt_shipments WHERE schedule_date >= CURDATE() AND schedule_date < DATE_ADD(CURDATE(), INTERVAL 7 DAY)"
  );
  $plt_kDel7 = (int) fetch_val(
      $pdo,
      "SELECT COUNT(*) FROM plt_shipments WHERE status='delivered' AND COALESCE(delivered_at, eta_date, schedule_date) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)"
  );
  $plt_kProj = (int) fetch_val(
      $pdo,
      "SELECT COUNT(*) FROM plt_projects WHERE status IN('planned','ongoing','delayed')"
  );
  $plt_kVeh = (int) fetch_val(
      $pdo,
      "SELECT COUNT(DISTINCT TRIM(vehicle)) FROM plt_shipments WHERE TRIM(COALESCE(vehicle,'')) <> ''"
  );
  $plt_kDrv = (int) fetch_val(
      $pdo,
      "SELECT COUNT(DISTINCT TRIM(driver))  FROM plt_shipments WHERE TRIM(COALESCE(driver,'')) <> ''"
  );

  $plt_rowsStatus = qall(
      $pdo,
      "
    SELECT LOWER(status) AS status, COUNT(*) AS total
    FROM plt_shipments
    WHERE schedule_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY LOWER(status)
    ORDER BY total DESC
  "
  );
  $plt_rowsDaily = qall(
      $pdo,
      "
    SELECT DATE(schedule_date) d, COUNT(*) total
    FROM plt_shipments
    WHERE schedule_date >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
    GROUP BY DATE(schedule_date)
    ORDER BY d ASC
  "
  );
  $plt_upcoming = qall(
      $pdo,
      "
    SELECT s.id, s.shipment_no, s.origin, s.destination, s.schedule_date, s.status, s.vehicle, s.driver,
          p.name AS project_name
    FROM plt_shipments s
    LEFT JOIN plt_projects p ON p.id = s.project_id
    WHERE s.schedule_date >= CURDATE() AND s.schedule_date < DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY s.schedule_date ASC, s.id ASC
    LIMIT 10
  "
  );
  $plt_recent = qall(
      $pdo,
      "
    SELECT s.id, s.shipment_no, s.origin, s.destination, s.schedule_date, s.eta_date, s.status,
          p.name AS project_name
    FROM plt_shipments s
    LEFT JOIN plt_projects p ON p.id = s.project_id
    ORDER BY s.id DESC
    LIMIT 10
  "
  );
  $plt_atRisk = qall(
      $pdo,
      "
    SELECT id, code, name, owner_name, deadline_date
    FROM plt_projects
    WHERE status='delayed'
    ORDER BY COALESCE(deadline_date, '9999-12-31') ASC, id DESC
    LIMIT 10
  "
  );
  $plt_stLabels = array_map(fn($r) => $r["status"], $plt_rowsStatus);
  $plt_stValues = array_map(fn($r) => (int) $r["total"], $plt_rowsStatus);
  $plt_dailyLabels = [];
  $plt_dailyValues = [];
  $start = new DateTime(date("Y-m-d", strtotime("-13 days")));
  for ($i = 0; $i < 14; $i++) {
      $day = clone $start;
      $day->modify("+$i day");
      $lbl = $day->format("Y-m-d");
      $plt_dailyLabels[] = $lbl;
      $match = array_values(
          array_filter($plt_rowsDaily, fn($r) => $r["d"] === $lbl)
      );
      $plt_dailyValues[] = $match ? (int) $match[0]["total"] : 0;
  }
  ?>
  <!DOCTYPE html>
  <html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Dashboard | Admin</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
    <link href="../css/style.css" rel="stylesheet"/>
    <link href="../css/modules.css" rel="stylesheet"/>
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    <script src="../js/sidebar-toggle.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    <style>
      .kpi-card .icon-wrap,.kpi .icon-wrap{width:42px;height:42px;display:flex;align-items:center;justify-content:center;border-radius:12px}
      .chart-card canvas{width:100%!important;height:320px!important}
      .badge-status{font-weight:500}
      .section-title{margin:6px 0 14px 0}
    </style>
  </head>
  <body>
  <div class="container-fluid p-0">
    <div class="row g-0">

      <?php include __DIR__ . '/../includes/sidebar.php' ?>

      <!-- Main unified content -->
      <div class="col main-content p-3 p-lg-4">

        <!-- Topbar -->
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div class="d-flex align-items-center gap-3">
            <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2"><ion-icon name="menu-outline"></ion-icon></button>
            <h2 class="m-0">Dashboard</h2>
          </div>
          <div class="d-flex align-items-center gap-2">
            <img src="../img/profile.jpg" class="rounded-circle" width="36" height="36" alt="">
            <div class="small">
              <strong><?= htmlspecialchars($userName) ?></strong><br/>
              <span class="text-muted"><?= htmlspecialchars($userRole) ?></span>
            </div>
          </div>
        </div>

        <!-- ===================== Smart Warehousing ===================== -->
        <h5 class="section-title d-flex align-items-center gap-2"><ion-icon name="business-outline"></ion-icon> Smart Warehousing</h5>

        <div class="row g-3 mb-3">
          <div class="col-6 col-md-3"><div class="card shadow-sm kpi-card h-100"><div class="card-body d-flex gap-3 align-items-center">
            <div class="icon-wrap bg-primary-subtle"><ion-icon name="cube-outline"></ion-icon></div>
            <div><div class="text-muted small">Total SKUs</div><div class="h4 m-0"><?= number_format(
                $sw_totalSkus
            ) ?></div></div>
          </div></div></div>
          <div class="col-6 col-md-3"><div class="card shadow-sm kpi-card h-100"><div class="card-body d-flex gap-3 align-items-center">
            <div class="icon-wrap bg-success-subtle"><ion-icon name="layers-outline"></ion-icon></div>
            <div><div class="text-muted small">On-hand Units</div><div class="h4 m-0"><?= number_format(
                $sw_totalUnits
            ) ?></div></div>
          </div></div></div>
          <div class="col-6 col-md-3"><div class="card shadow-sm kpi-card h-100"><div class="card-body d-flex gap-3 align-items-center">
            <div class="icon-wrap bg-warning-subtle"><ion-icon name="alert-circle-outline"></ion-icon></div>
            <div><div class="text-muted small">Low Stock</div><div class="h4 m-0"><?= number_format(
                $sw_lowStockCount
            ) ?></div></div>
          </div></div></div>
          <div class="col-6 col-md-3"><div class="card shadow-sm kpi-card h-100"><div class="card-body d-flex gap-3 align-items-center">
            <div class="icon-wrap bg-info-subtle"><ion-icon name="navigate-outline"></ion-icon></div>
            <div><div class="text-muted small">Locations</div><div class="h4 m-0"><?= number_format(
                $sw_locationsCount
            ) ?></div></div>
          </div></div></div>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-12 col-lg-6"><div class="card shadow-sm chart-card h-100"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2"><h6 class="m-0">On-hand by Category</h6><ion-icon name="stats-chart-outline"></ion-icon></div>
            <canvas id="sw_catChart"></canvas>
          </div></div></div>
          <div class="col-12 col-lg-6"><div class="card shadow-sm chart-card h-100"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2"><h6 class="m-0">30-day Movements</h6><ion-icon name="trending-up-outline"></ion-icon></div>
            <canvas id="sw_trendChart"></canvas>
          </div></div></div>
        </div>

        <div class="row g-3 mb-4">
          <div class="col-12 col-lg-6"><div class="card shadow-sm chart-card h-100"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2"><h6 class="m-0">On-hand by Location</h6><ion-icon name="pin-outline"></ion-icon></div>
            <canvas id="sw_locChart"></canvas>
          </div></div></div>
          <div class="col-12 col-lg-6"><div class="card shadow-sm chart-card h-100"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2"><h6 class="m-0">Shipment Status</h6><ion-icon name="paper-plane-outline"></ion-icon></div>
            <canvas id="sw_shipChart"></canvas>
            <?php if (
                !$sw_hasShip
            ): ?><div class="text-muted small mt-2">Tip: create a <code>shipments</code> table with a <code>status</code> column.</div><?php endif; ?>
          </div></div></div>
        </div>

        <!-- ===================== Procurement & Sourcing ===================== -->
        <h5 class="section-title d-flex align-items-center gap-2"><ion-icon name="pricetags-outline"></ion-icon> Procurement &amp; Sourcing</h5>

        <div class="row g-3 mb-3">
          <div class="col-6 col-md-3"><div class="card shadow-sm kpi-card h-100"><div class="card-body d-flex gap-3 align-items-center">
            <div class="icon-wrap bg-primary-subtle"><ion-icon name="people-outline"></ion-icon></div>
            <div><div class="text-muted small">Active Suppliers</div><div class="h4 m-0"><?= number_format(
                $pr_activeSuppliers
            ) ?></div></div>
          </div></div></div>
          <div class="col-6 col-md-3"><div class="card shadow-sm kpi-card h-100"><div class="card-body d-flex gap-3 align-items-center">
            <div class="icon-wrap bg-info-subtle"><ion-icon name="mail-open-outline"></ion-icon></div>
            <div><div class="text-muted small">Open RFQs</div><div class="h4 m-0"><?= number_format(
                $pr_openRFQs
            ) ?></div></div>
          </div></div></div>
          <div class="col-6 col-md-3"><div class="card shadow-sm kpi-card h-100"><div class="card-body d-flex gap-3 align-items-center">
            <div class="icon-wrap bg-warning-subtle"><ion-icon name="document-text-outline"></ion-icon></div>
            <div><div class="text-muted small">Open POs</div><div class="h4 m-0"><?= number_format(
                $pr_openPOs
            ) ?></div></div>
          </div></div></div>
          <div class="col-6 col-md-3"><div class="card shadow-sm kpi-card h-100"><div class="card-body d-flex gap-3 align-items-center">
            <div class="icon-wrap bg-success-subtle"><ion-icon name="cash-outline"></ion-icon></div>
            <div><div class="text-muted small">Spend (This Month)</div><div class="h4 m-0">₱<?= number_format(
                $pr_spendThisMonth,
                2
            ) ?></div></div>
          </div></div></div>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-12 col-lg-6"><div class="card shadow-sm chart-card h-100"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2"><h6 class="m-0">PO Status</h6><ion-icon name="pie-chart-outline"></ion-icon></div>
            <canvas id="pr_chartStatus"></canvas>
            <?php if (
                !$poHeaderTbl
            ): ?><div class="text-muted small mt-2">Tip: ensure a <code>pos</code> or <code>purchase_orders</code> table with a <code>status</code> column.</div><?php endif; ?>
          </div></div></div>

          <div class="col-12 col-lg-6"><div class="card shadow-sm chart-card h-100"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2"><h6 class="m-0">Monthly Spend</h6><ion-icon name="stats-chart-outline"></ion-icon></div>
            <canvas id="pr_chartMonth"></canvas>
            <?php if (
                !$poHeaderTbl ||
                !$poDateCol
            ): ?><div class="text-muted small mt-2">Tip: add a date column like <code>issue_date</code> to your PO header.</div><?php endif; ?>
          </div></div></div>
        </div>

        <div class="row g-3 mb-4">
          <div class="col-12"><div class="card shadow-sm chart-card h-100"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2"><h6 class="m-0">Top Suppliers (last 90 days)</h6><ion-icon name="ribbon-outline"></ion-icon></div>
            <canvas id="pr_chartSup"></canvas>
            <?php if (
                !$hasSup ||
                !$poHeaderTbl
            ): ?><div class="text-muted small mt-2">Tip: ensure <code>suppliers</code> and POs are linked via <code>supplier_id</code>.</div><?php endif; ?>
          </div></div></div>
        </div>

        <!-- ===================== PLT ===================== -->
        <h5 class="section-title d-flex align-items-center gap-2"><ion-icon name="trail-sign-outline"></ion-icon> PLT</h5>

        <div class="row g-3 mb-3">
          <div class="col-6 col-md-3"><div class="card shadow-sm kpi h-100"><div class="card-body d-flex gap-3 align-items-center">
            <div class="icon-wrap bg-primary-subtle"><ion-icon name="calendar-outline"></ion-icon></div>
            <div><div class="text-muted small">Deliveries Today</div><div class="h4 m-0"><?= $plt_kToday ?></div></div>
          </div></div></div>
          <div class="col-6 col-md-3"><div class="card shadow-sm kpi h-100"><div class="card-body d-flex gap-3 align-items-center">
            <div class="icon-wrap bg-info-subtle"><ion-icon name="calendar-number-outline"></ion-icon></div>
            <div><div class="text-muted small">Next 7 Days</div><div class="h4 m-0"><?= $plt_kWeek ?></div></div>
          </div></div></div>
          <div class="col-6 col-md-3"><div class="card shadow-sm kpi h-100"><div class="card-body d-flex gap-3 align-items-center">
            <div class="icon-wrap bg-success-subtle"><ion-icon name="checkmark-done-outline"></ion-icon></div>
            <div><div class="text-muted small">Delivered (7d)</div><div class="h4 m-0"><?= $plt_kDel7 ?></div></div>
          </div></div></div>
          <div class="col-6 col-md-3"><div class="card shadow-sm kpi h-100"><div class="card-body d-flex gap-3 align-items-center">
            <div class="icon-wrap bg-violet-subtle"><ion-icon name="briefcase-outline"></ion-icon></div>
            <div><div class="text-muted small">Active Projects</div><div class="h4 m-0"><?= $plt_kProj ?></div></div>
          </div></div></div>
          <div class="col-6 col-md-3"><div class="card shadow-sm kpi h-100"><div class="card-body d-flex gap-3 align-items-center">
            <div class="icon-wrap bg-warning-subtle"><ion-icon name="bus-outline"></ion-icon></div>
            <div><div class="text-muted small">Vehicles</div><div class="h4 m-0"><?= $plt_kVeh ?></div></div>
          </div></div></div>
          <div class="col-6 col-md-3"><div class="card shadow-sm kpi h-100"><div class="card-body d-flex gap-3 align-items-center">
            <div class="icon-wrap bg-secondary-subtle"><ion-icon name="person-outline"></ion-icon></div>
            <div><div class="text-muted small">Drivers</div><div class="h4 m-0"><?= $plt_kDrv ?></div></div>
          </div></div></div>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-12 col-lg-6"><div class="card shadow-sm chart-card h-100"><div class="card-body">
            <h6 class="mb-2 d-flex align-items-center gap-2"><ion-icon name="pie-chart-outline"></ion-icon> Status (last 30d)</h6>
            <canvas id="plt_status"></canvas>
          </div></div></div>
          <div class="col-12 col-lg-6"><div class="card shadow-sm chart-card h-100"><div class="card-body">
            <h6 class="mb-2 d-flex align-items-center gap-2"><ion-icon name="bar-chart-outline"></ion-icon> Shipments per Day (last 14d)</h6>
            <canvas id="plt_daily"></canvas>
          </div></div></div>
        </div>

        <div class="row g-3">
          <div class="col-12 col-lg-6">
            <div class="card shadow-sm h-100">
              <div class="card-body">
                <h6 class="mb-2 d-flex align-items-center gap-2"><ion-icon name="calendar-outline"></ion-icon> Upcoming Deliveries (7d)</h6>
                <div class="table-responsive">
                  <table class="table table-sm align-middle">
                    <thead><tr><th>Schedule</th><th>Shipment</th><th>Project</th><th>Route</th><th>Status</th></tr></thead>
                    <tbody>
                      <?php if (!$plt_upcoming): ?>
                        <tr><td colspan="5" class="text-muted text-center py-3">No upcoming deliveries</td></tr>
                      <?php else:foreach ($plt_upcoming as $r): ?>
                        <tr>
                          <td><?= htmlspecialchars($r["schedule_date"]) ?></td>
                          <td class="fw-semibold"><?= htmlspecialchars(
                              $r["shipment_no"] ?: "SHP-" . $r["id"]
                          ) ?></td>
                          <td><?= htmlspecialchars(
                              $r["project_name"] ?: "-"
                          ) ?></td>
                          <td class="text-muted"><?= htmlspecialchars(
                              $r["origin"] ?: "-"
                          ) ?> → <?= htmlspecialchars(
      $r["destination"] ?: "-"
  ) ?></td>
                          <td><span class="badge bg-secondary badge-status"><?= htmlspecialchars(
                              ucfirst(str_replace("_", " ", $r["status"]))
                          ) ?></span></td>
                        </tr>
                      <?php endforeach;endif; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>

          <div class="col-12 col-lg-6">
            <div class="card shadow-sm h-100">
              <div class="card-body">
                <h6 class="mb-2 d-flex align-items-center gap-2"><ion-icon name="time-outline"></ion-icon> Recent Shipments</h6>
                <div class="table-responsive">
                  <table class="table table-sm align-middle">
                    <thead><tr><th>Shipment</th><th>Project</th><th>Schedule</th><th>ETA</th><th>Status</th></tr></thead>
                    <tbody>
                      <?php if (!$plt_recent): ?>
                        <tr><td colspan="5" class="text-muted text-center py-3">No data</td></tr>
                      <?php else:foreach ($plt_recent as $r): ?>
                        <tr>
                          <td class="fw-semibold"><?= htmlspecialchars(
                              $r["shipment_no"] ?: "SHP-" . $r["id"]
                          ) ?></td>
                          <td><?= htmlspecialchars(
                              $r["project_name"] ?: "-"
                          ) ?></td>
                          <td><?= htmlspecialchars(
                              $r["schedule_date"] ?: "-"
                          ) ?></td>
                          <td><?= htmlspecialchars($r["eta_date"] ?: "-") ?></td>
                          <td><span class="badge bg-secondary badge-status"><?= htmlspecialchars(
                              ucfirst(str_replace("_", " ", $r["status"]))
                          ) ?></span></td>
                        </tr>
                      <?php endforeach;endif; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>

          <div class="col-12">
            <div class="card shadow-sm">
              <div class="card-body">
                <h6 class="mb-2 d-flex align-items-center gap-2"><ion-icon name="alert-circle-outline"></ion-icon> Projects at Risk</h6>
                <div class="table-responsive">
                  <table class="table table-sm align-middle">
                    <thead><tr><th>Code</th><th>Name</th><th>Owner</th><th>Deadline</th></tr></thead>
                    <tbody>
                      <?php if (!$plt_atRisk): ?>
                        <tr><td colspan="4" class="text-muted text-center py-3">No delayed projects</td></tr>
                      <?php else:foreach ($plt_atRisk as $p): ?>
                        <tr>
                          <td class="fw-semibold"><?= htmlspecialchars(
                              $p["code"] ?: "PRJ-" . $p["id"]
                          ) ?></td>
                          <td><?= htmlspecialchars($p["name"]) ?></td>
                          <td><?= htmlspecialchars(
                              $p["owner_name"] ?: "—"
                          ) ?></td>
                          <td><?= htmlspecialchars(
                              $p["deadline_date"] ?: "—"
                          ) ?></td>
                        </tr>
                      <?php endforeach;endif; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>

        </div><!-- /PLT tables -->

      </div><!-- /main -->
    </div><!-- /row -->
  </div><!-- /container -->

  <script>
    // Global Chart.js
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily || 'system-ui';
    Chart.defaults.color = getComputedStyle(document.body).color || '#222';

    /* ---------- SW charts ---------- */
    const sw_catLabels  = <?= json_encode($sw_catLabels) ?>;
    const sw_catData    = <?= json_encode($sw_catData) ?>;
    const sw_trendLabels= <?= json_encode($sw_trendLabels) ?>;
    const sw_incoming   = <?= json_encode($sw_incoming) ?>;
    const sw_outgoing   = <?= json_encode($sw_outgoing) ?>;
    const sw_locLabels  = <?= json_encode($sw_locLabels) ?>;
    const sw_locData    = <?= json_encode($sw_locData) ?>;
    const sw_shipLabels = <?= json_encode($sw_shipLabels) ?>;
    const sw_shipData   = <?= json_encode($sw_shipData) ?>;

    new Chart(document.getElementById('sw_catChart'), {
      type:'bar',
      data:{ labels: sw_catLabels, datasets:[{ label:'Units', data: sw_catData, borderWidth:1 }] },
      options:{ maintainAspectRatio:false, scales:{ y:{ beginAtZero:true, ticks:{ precision:0 } } }, plugins:{ legend:{ display:false } } }
    });

    new Chart(document.getElementById('sw_trendChart'), {
      type:'line',
      data:{ labels: sw_trendLabels, datasets:[
        { label:'Incoming', data: sw_incoming, tension:.3, fill:false, borderWidth:2, pointRadius:0 },
        { label:'Outgoing', data: sw_outgoing, tension:.3, fill:false, borderWidth:2, pointRadius:0 }
      ]},
      options:{ maintainAspectRatio:false, scales:{ y:{ beginAtZero:true, ticks:{ precision:0 } } }, plugins:{ tooltip:{ mode:'index', intersect:false } } }
    });

    new Chart(document.getElementById('sw_locChart'), {
      type:'doughnut',
      data:{ labels: sw_locLabels, datasets:[{ data: sw_locData, borderWidth:1 }] },
      options:{ maintainAspectRatio:false, plugins:{ legend:{ position:'bottom' } } }
    });

    new Chart(document.getElementById('sw_shipChart'), {
      type:'doughnut',
      data:{ labels: sw_shipLabels, datasets:[{ data: sw_shipData, borderWidth:1 }] },
      options:{ maintainAspectRatio:false, plugins:{ legend:{ position:'bottom' } } }
    });

    /* ---------- Procurement charts ---------- */
    const pr_poStatusLabels = <?= json_encode($pr_poStatusLabels) ?>;
    const pr_poStatusData   = <?= json_encode($pr_poStatusData) ?>;
    const pr_monLabels      = <?= json_encode($pr_monLabels) ?>;
    const pr_monAmounts     = <?= json_encode($pr_monAmounts) ?>;
    const pr_topSupLabels   = <?= json_encode($pr_topSupLabels) ?>;
    const pr_topSupAmts     = <?= json_encode($pr_topSupAmts) ?>;

    new Chart(document.getElementById('pr_chartStatus'), {
      type:'doughnut',
      data:{ labels: pr_poStatusLabels, datasets:[{ data: pr_poStatusData, borderWidth:1 }] },
      options:{ maintainAspectRatio:false, plugins:{ legend:{ position:'bottom' } } }
    });

    new Chart(document.getElementById('pr_chartMonth'), {
      type:'bar',
      data:{ labels: pr_monLabels, datasets:[{ label:'Spend', data: pr_monAmounts, borderWidth:1 }] },
      options:{ maintainAspectRatio:false, scales:{ y:{ beginAtZero:true, ticks:{ precision:0 } } }, plugins:{ legend:{ display:false }, tooltip:{ mode:'index', intersect:false } } }
    });

    new Chart(document.getElementById('pr_chartSup'), {
      type:'bar',
      data:{ labels: pr_topSupLabels, datasets:[{ label:'Amount', data: pr_topSupAmts, borderWidth:1 }] },
      options:{ maintainAspectRatio:false, indexAxis:'y', scales:{ x:{ beginAtZero:true, ticks:{ precision:0 } } }, plugins:{ legend:{ display:false } } }
    });

    /* ---------- PLT charts ---------- */
    const plt_stLabels = <?= json_encode($plt_stLabels) ?>;
    const plt_stValues = <?= json_encode($plt_stValues) ?>;
    const plt_dailyLabels = <?= json_encode($plt_dailyLabels) ?>;
    const plt_dailyValues = <?= json_encode($plt_dailyValues) ?>;

    new Chart(document.getElementById('plt_status'), {
      type:'doughnut',
      data:{ labels: plt_stLabels, datasets:[{ data: plt_stValues, borderWidth:1 }] },
      options:{ maintainAspectRatio:false, plugins:{ legend:{ position:'bottom' } } }
    });
    new Chart(document.getElementById('plt_daily'), {
      type:'bar',
      data:{ labels: plt_dailyLabels, datasets:[{ label:'Shipments', data: plt_dailyValues, borderWidth:1 }] },
      options:{ maintainAspectRatio:false, scales:{ y:{ beginAtZero:true, ticks:{ precision:0 } } }, plugins:{ legend:{ display:false } } }
    });
  </script>
  </body>
  </html>
