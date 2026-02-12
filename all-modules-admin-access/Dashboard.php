<?php
// all-modules-admin-access/Dashboard.php

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

require_login();
require_role(['admin']);  

$active = 'dashboard';

/* -------------------- DB connections (one per module) -------------------- */
$wmsPdo  = db('wms');                                // Smart Warehousing
$procPdo = function_exists('db') ? (db('proc') ?: (function_exists('db') ? (db('procurement') ?: null) : null)) : null; // Procurement (support both keys)
if (!$procPdo) {                                     // soft fallback so page still loads
  try { $procPdo = db('procurement'); } catch(Throwable $e) {}
}
$pltPdo  = db('plt');                                // PLT

/* -------------------- helpers -------------------- */
function table_exists(?PDO $pdo = null, string $table = ''): bool {
  if (!$pdo) return false;
  try {
    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
  } catch (Throwable $e) { return false; }
}
function column_exists(?PDO $pdo = null, string $table = '', string $col = ''): bool {
  if (!$pdo) return false;
  try {
    $s = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $s->execute([$col]);
    return (bool)$s->fetchColumn();
  } catch (Throwable $e) { return false; }
}
function fetch_val(?PDO $pdo = null, string $sql = '', array $params = [], $fallback = 0) {
  if (!$pdo) return $fallback;
  try {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $v = $st->fetchColumn();
    return $v !== false ? $v : $fallback;
  } catch (Throwable $e) { return $fallback; }
}
function qall(?PDO $pdo = null, string $sql = '', array $bind = []) {
  if (!$pdo) return [];
  try {
    $st = $pdo->prepare($sql);
    $st->execute($bind);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) { return []; }
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
  SECTION A: SMART WAREHOUSING  (uses $wmsPdo)
=============================================================== */
$sw_hasItems = table_exists($wmsPdo, "inventory_items");
$sw_hasLvl   = table_exists($wmsPdo, "stock_levels");
$sw_hasTx    = table_exists($wmsPdo, "stock_transactions");
$sw_hasLoc   = table_exists($wmsPdo, "warehouse_locations");
$sw_hasShip  = table_exists($wmsPdo, "shipments");

/* KPIs */
$sw_totalSkus      = $sw_hasItems ? (int)fetch_val($wmsPdo, "SELECT COUNT(*) FROM inventory_items WHERE archived=0") : 0;
$sw_totalUnits     = $sw_hasLvl   ? (int)fetch_val($wmsPdo, "SELECT COALESCE(SUM(qty),0) FROM stock_levels") : 0;
$sw_locationsCount = $sw_hasLoc   ? (int)fetch_val($wmsPdo, "SELECT COUNT(*) FROM warehouse_locations") : 0;

$sw_lowStockCount = 0;
if ($sw_hasItems && $sw_hasLvl) {
  $sw_lowStockCount = (int) fetch_val($wmsPdo, "
    SELECT COUNT(*) FROM (
      SELECT i.id, i.reorder_level, COALESCE(SUM(l.qty),0) total
      FROM inventory_items i
      LEFT JOIN stock_levels l ON l.item_id=i.id
      WHERE i.archived=0
      GROUP BY i.id, i.reorder_level
      HAVING i.reorder_level>0 AND COALESCE(SUM(l.qty),0) <= i.reorder_level
    ) x
  ");
}

/* Charts: On-hand by Category */
$sw_catLabels = ["Raw","Packaging","Finished"];
$sw_catData   = [0,0,0];
if ($sw_hasLvl && $sw_hasItems) {
  $rows = qall($wmsPdo, "
    SELECT i.category, COALESCE(SUM(l.qty),0) qty
    FROM stock_levels l JOIN inventory_items i ON i.id=l.item_id
    WHERE i.archived=0
    GROUP BY i.category
  ");
  $tmp=[]; foreach ($rows as $r) $tmp[$r['category']] = (int)$r['qty'];
  foreach ($sw_catLabels as $i=>$lab) $sw_catData[$i] = $tmp[$lab] ?? 0;
}

/* Charts: 30-day movements */
$sw_trendLabels=[]; $sw_incoming=[]; $sw_outgoing=[];
$tz = new DateTimeZone("Asia/Manila");
$today = new DateTime("today", $tz);
$map = [];
for ($i=29; $i>=0; $i--) {
  $d = clone $today; $d->modify("-$i day");
  $map[$d->format("Y-m-d")] = ['in'=>0,'out'=>0];
}
if ($sw_hasTx) {
  $rows = qall($wmsPdo, "
    SELECT DATE(created_at) d,
           SUM(CASE WHEN qty>0 THEN qty ELSE 0 END) incoming,
           SUM(CASE WHEN qty<0 THEN -qty ELSE 0 END) outgoing
    FROM stock_transactions
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
    GROUP BY DATE(created_at)
    ORDER BY DATE(created_at)
  ");
  foreach ($rows as $r) {
    $k=$r['d']; if(isset($map[$k])) { $map[$k]['in']=(int)$r['incoming']; $map[$k]['out']=(int)$r['outgoing']; }
  }
}
foreach ($map as $k=>$io) {
  $d = DateTime::createFromFormat("Y-m-d",$k,$tz);
  $sw_trendLabels[] = $d ? $d->format("M j") : $k;
  $sw_incoming[] = $io['in'];
  $sw_outgoing[] = $io['out'];
}

/* Charts: On-hand by Location */
$sw_locLabels=[]; $sw_locData=[];
if ($sw_hasLvl && $sw_hasLoc) {
  $rows = qall($wmsPdo, "
    SELECT w.name label, COALESCE(SUM(l.qty),0) qty
    FROM stock_levels l JOIN warehouse_locations w ON w.id=l.location_id
    GROUP BY w.id HAVING COALESCE(SUM(l.qty),0)>0
    ORDER BY qty DESC
  ");
  $top=6; $others=0;
  foreach ($rows as $i=>$r) {
    if ($i<$top){ $sw_locLabels[]=$r['label']; $sw_locData[]=(int)$r['qty']; }
    else { $others += (int)$r['qty']; }
  }
  if ($others>0){ $sw_locLabels[]='Others'; $sw_locData[]=$others; }
}

/* Charts: Shipment status */
$sw_shipLabels = ["In Transit","Delivered","Delayed"];
$sw_shipData   = [0,0,0];
if ($sw_hasShip) {
  $rows = qall($wmsPdo, "SELECT status, COUNT(*) c FROM shipments GROUP BY status");
  $map=[]; foreach ($rows as $r) $map[$r['status']] = (int)$r['c'];
  foreach ($sw_shipLabels as $i=>$s) $sw_shipData[$i] = $map[$s] ?? 0;
}

/* ================================================================
  SECTION B: PROCUREMENT & SOURCING  (uses $procPdo)
=============================================================== */
$hasProcDB = $procPdo instanceof PDO;

$poHeaderTbl = null; $poItemTbl = null;
if ($hasProcDB) {
  if (table_exists($procPdo,'pos'))                $poHeaderTbl = 'pos';
  elseif (table_exists($procPdo,'purchase_orders'))$poHeaderTbl = 'purchase_orders';

  if (table_exists($procPdo,'po_items'))                 $poItemTbl='po_items';
  elseif (table_exists($procPdo,'purchase_order_items')) $poItemTbl='purchase_order_items';
}

$poDateCol = null;
if ($poHeaderTbl) foreach (['issue_date','order_date','created_at','date'] as $c) { if (column_exists($procPdo,$poHeaderTbl,$c)) { $poDateCol=$c; break; } }
$poTotalCol = null;
if ($poHeaderTbl) foreach (['total','total_amount','grand_total'] as $c) { if (column_exists($procPdo,$poHeaderTbl,$c)) { $poTotalCol=$c; break; } }

$hasSup = $hasProcDB && table_exists($procPdo,'suppliers');
$hasRFQ = $hasProcDB && table_exists($procPdo,'rfqs');
$hasPR  = $hasProcDB && table_exists($procPdo,'procurement_requests');

/* KPIs */
$pr_activeSuppliers = $hasSup ? (int)fetch_val($procPdo,"SELECT COUNT(*) FROM suppliers WHERE IFNULL(is_active,1)=1") : 0;
$pr_openRFQs        = $hasRFQ ? (int)fetch_val($procPdo,"SELECT COUNT(*) FROM rfqs WHERE status IN ('open','sent','pending','draft')") : 0;
$pr_openPOs         = ($poHeaderTbl) ? (int)fetch_val($procPdo,"SELECT COUNT(*) FROM `$poHeaderTbl` WHERE LOWER(IFNULL(status,'')) IN ('draft','approved','ordered','partially_received')") : 0;
$pr_pendingPRs      = $hasPR ? (int)fetch_val($procPdo,"SELECT COUNT(*) FROM procurement_requests WHERE status IN ('pending','for_approval','approved','submitted')") : 0;

$pr_spendThisMonth = 0.0;
if ($poHeaderTbl && $poDateCol) {
  if ($poItemTbl && column_exists($procPdo,$poItemTbl,'qty') && column_exists($procPdo,$poItemTbl,'price')) {
    $joinKey = column_exists($procPdo,$poItemTbl,'po_id') ? 'po_id' : (column_exists($procPdo,$poItemTbl,'purchase_order_id') ? 'purchase_order_id' : null);
    if ($joinKey) {
      $pr_spendThisMonth = (float)fetch_val($procPdo,"
        SELECT COALESCE(SUM(i.qty*i.price),0)
        FROM `$poHeaderTbl` p JOIN `$poItemTbl` i ON i.$joinKey=p.id
        WHERE DATE_FORMAT(p.`$poDateCol`,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')
      ",[],0.0);
    }
  } elseif ($poTotalCol) {
    $pr_spendThisMonth = (float)fetch_val($procPdo,"
      SELECT COALESCE(SUM(p.`$poTotalCol`),0)
      FROM `$poHeaderTbl` p
      WHERE DATE_FORMAT(p.`$poDateCol`,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')
    ",[],0.0);
  }
}

/* Charts */
$pr_poStatusLabels = ['draft','approved','ordered','partially_received','received','closed','cancelled'];
$pr_poStatusData   = array_fill(0,count($pr_poStatusLabels),0);
if ($poHeaderTbl) {
  $rows = qall($procPdo,"SELECT LOWER(IFNULL(status,'')) s, COUNT(*) c FROM `$poHeaderTbl` GROUP BY s");
  $map=[]; foreach($rows as $r) $map[$r['s']] = (int)$r['c'];
  foreach ($pr_poStatusLabels as $i=>$s) $pr_poStatusData[$i] = $map[$s] ?? 0;
}

$pr_monLabels=[]; $pr_monAmounts=[];
if ($poHeaderTbl && $poDateCol) {
  $first = new DateTime("first day of this month", $tz);
  $buckets=[];
  for ($i=5;$i>=0;$i--){
    $d=(clone $first)->modify("-$i months");
    $ym=$d->format('Y-m'); $pr_monLabels[]=$d->format('M Y'); $buckets[$ym]=0.0;
  }
  $sql=null;
  if ($poItemTbl && column_exists($procPdo,$poItemTbl,'qty') && column_exists($procPdo,$poItemTbl,'price')) {
    $joinKey = column_exists($procPdo,$poItemTbl,'po_id') ? 'po_id' : (column_exists($procPdo,$poItemTbl,'purchase_order_id') ? 'purchase_order_id' : null);
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
  if ($sql){
    $rows = qall($procPdo,$sql);
    foreach($rows as $r){ if(isset($buckets[$r['ym']])) $buckets[$r['ym']] = (float)$r['amt']; }
  }
  foreach ($buckets as $amt) $pr_monAmounts[] = (float)$amt;
}

$pr_topSupLabels=[]; $pr_topSupAmts=[];
if ($hasSup && $poHeaderTbl && $poDateCol) {
  $sql=null;
  if ($poItemTbl && column_exists($procPdo,$poItemTbl,'qty') && column_exists($procPdo,$poItemTbl,'price') && column_exists($procPdo,$poHeaderTbl,'supplier_id')) {
    $joinKey = column_exists($procPdo,$poItemTbl,'po_id') ? 'po_id' : (column_exists($procPdo,$poItemTbl,'purchase_order_id') ? 'purchase_order_id' : null);
    if ($joinKey) {
      $sql = "SELECT s.name, SUM(i.qty*i.price) amt
              FROM `$poHeaderTbl` p
              JOIN suppliers s ON s.id=p.supplier_id
              JOIN `$poItemTbl` i ON i.`$joinKey`=p.id
              WHERE p.`$poDateCol` >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
              GROUP BY s.id ORDER BY amt DESC LIMIT 6";
    }
  } elseif ($poTotalCol && column_exists($procPdo,$poHeaderTbl,'supplier_id')) {
    $sql = "SELECT s.name, SUM(p.`$poTotalCol`) amt
            FROM `$poHeaderTbl` p JOIN suppliers s ON s.id=p.supplier_id
            WHERE p.`$poDateCol` >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
            GROUP BY s.id ORDER BY amt DESC LIMIT 6";
  }
  if ($sql){
    $rows = qall($procPdo,$sql);
    foreach($rows as $r){ $pr_topSupLabels[]=$r['name']; $pr_topSupAmts[]=(float)$r['amt']; }
  }
}

/* ================================================================
  SECTION C: PLT  (uses $pltPdo)
=============================================================== */
$plt_kToday = (int)fetch_val($pltPdo, "SELECT COUNT(*) FROM plt_shipments WHERE schedule_date = CURDATE()");
$plt_kWeek  = (int)fetch_val($pltPdo, "SELECT COUNT(*) FROM plt_shipments WHERE schedule_date >= CURDATE() AND schedule_date < DATE_ADD(CURDATE(), INTERVAL 7 DAY)");
$plt_kDel7  = (int)fetch_val($pltPdo, "SELECT COUNT(*) FROM plt_shipments WHERE status='delivered' AND COALESCE(delivered_at, eta_date, schedule_date) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
$plt_kProj  = (int)fetch_val($pltPdo, "SELECT COUNT(*) FROM plt_projects WHERE status IN('planned','ongoing','delayed')");
$plt_kVeh   = (int)fetch_val($pltPdo, "SELECT COUNT(DISTINCT TRIM(vehicle)) FROM plt_shipments WHERE TRIM(COALESCE(vehicle,'')) <> ''");
$plt_kDrv   = (int)fetch_val($pltPdo, "SELECT COUNT(DISTINCT TRIM(driver))  FROM plt_shipments WHERE TRIM(COALESCE(driver,'')) <> ''");

$plt_rowsStatus = qall($pltPdo, "
  SELECT LOWER(status) AS status, COUNT(*) AS total
  FROM plt_shipments
  WHERE schedule_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
  GROUP BY LOWER(status)
  ORDER BY total DESC
");
$plt_rowsDaily = qall($pltPdo, "
  SELECT DATE(schedule_date) d, COUNT(*) total
  FROM plt_shipments
  WHERE schedule_date >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
  GROUP BY DATE(schedule_date)
  ORDER BY d ASC
");
$plt_upcoming = qall($pltPdo, "
  SELECT s.id, s.shipment_no, s.origin, s.destination, s.schedule_date, s.status, s.vehicle, s.driver,
         p.name AS project_name
  FROM plt_shipments s
  LEFT JOIN plt_projects p ON p.id = s.project_id
  WHERE s.schedule_date >= CURDATE() AND s.schedule_date < DATE_ADD(CURDATE(), INTERVAL 7 DAY)
  ORDER BY s.schedule_date ASC, s.id ASC
  LIMIT 10
");
$plt_recent = qall($pltPdo, "
  SELECT s.id, s.shipment_no, s.origin, s.destination, s.schedule_date, s.eta_date, s.status,
         p.name AS project_name
  FROM plt_shipments s
  LEFT JOIN plt_projects p ON p.id = s.project_id
  ORDER BY s.id DESC
  LIMIT 10
");
$plt_atRisk = qall($pltPdo, "
  SELECT id, code, name, owner_name, deadline_date
  FROM plt_projects
  WHERE status='delayed'
  ORDER BY COALESCE(deadline_date, '9999-12-31') ASC, id DESC
  LIMIT 10
");
$plt_stLabels = array_map(fn($r)=>$r['status'],$plt_rowsStatus);
$plt_stValues = array_map(fn($r)=>(int)$r['total'],$plt_rowsStatus);
$plt_dailyLabels=[]; $plt_dailyValues=[];
$start = new DateTime(date("Y-m-d", strtotime("-13 days")));
for ($i=0;$i<14;$i++){
  $day = clone $start; $day->modify("+$i day");
  $lbl = $day->format("Y-m-d");
  $plt_dailyLabels[]=$lbl;
  $match = array_values(array_filter($plt_rowsDaily, fn($r)=>$r['d']===$lbl));
  $plt_dailyValues[] = $match ? (int)$match[0]['total'] : 0;
}
$pr_hasTopSupData = !empty($pr_topSupLabels) && array_sum($pr_topSupAmts) > 0;
$plt_hasStatusData = !empty($plt_stValues) && array_sum($plt_stValues) > 0;
$plt_hasDailyData  = !empty($plt_dailyValues) && array_sum($plt_dailyValues) > 0;
$plt_hasUpcomingData = !empty($plt_upcoming);
$plt_hasRecentData = !empty($plt_recent);
$plt_hasAtRiskData = !empty($plt_atRisk);
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
    .admin-dashboard {
      --vh-primary: #6a45ff;
      --vh-primary-700: #4d2bd8;
      --vh-primary-100: #efeaff;
      --vh-primary-200: #e0d8ff;
      --vh-surface: #ffffff;
      --vh-surface-soft: #f6f4ff;
      --vh-text-strong: #2b2349;
      --vh-text-muted: #6f6c80;
      --vh-shadow: 0 18px 40px rgba(40, 34, 84, 0.08);
      --vh-shadow-soft: 0 10px 24px rgba(40, 34, 84, 0.06);
    }

    .admin-dashboard body,
    .admin-dashboard {
      background: linear-gradient(180deg, #f3f1ff 0%, #f8f9ff 40%, #fbfbff 100%);
      color: var(--vh-text-muted);
    }

    .admin-dashboard .main-content {
      background: transparent;
      padding: 28px !important;
    }

    .admin-dashboard .dashboard-topbar {
      margin-bottom: 22px !important;
    }

    .admin-dashboard .page-title {
      font-size: 1.9rem;
      font-weight: 700;
      color: var(--vh-text-strong);
      letter-spacing: -0.02em;
    }

    .admin-dashboard .page-breadcrumb {
      font-size: 0.9rem;
      color: #8b86a3;
    }

    .admin-dashboard .card,
    .admin-dashboard .chart-card {
      border: 1px solid rgba(106, 69, 255, 0.08);
      border-radius: 18px;
      background: var(--vh-surface);
      box-shadow: var(--vh-shadow-soft);
    }

    .admin-dashboard .card-body {
      padding: 1.4rem 1.5rem;
    }

    .admin-dashboard .kpi-card,
    .admin-dashboard .kpi {
      min-height: 120px;
    }

    .admin-dashboard .kpi-card .icon-wrap,
    .admin-dashboard .kpi .icon-wrap {
      width: 54px;
      height: 54px;
      border-radius: 16px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.35rem;
      color: var(--vh-primary-700);
      background: var(--vh-primary-100);
    }

    .admin-dashboard .kpi-card .h4,
    .admin-dashboard .kpi .h4 {
      font-size: 1.6rem;
      font-weight: 700;
      color: var(--vh-text-strong);
    }

    .admin-dashboard .text-muted,
    .admin-dashboard .small {
      color: var(--vh-text-muted) !important;
    }

    .admin-dashboard .section-title {
      margin: 10px 0 16px 0;
      font-weight: 700;
      color: var(--vh-text-strong);
      letter-spacing: -0.01em;
    }

    .admin-dashboard .section-title ion-icon {
      color: var(--vh-primary);
    }

    .admin-dashboard .chart-card canvas {
      width: 100% !important;
      height: 300px !important;
    }

    .admin-dashboard .badge-status {
      font-weight: 500;
    }

    .admin-dashboard .table thead th {
      font-weight: 600;
      color: #4b4566;
    }

    .admin-dashboard .bg-primary-subtle,
    .admin-dashboard .bg-info-subtle,
    .admin-dashboard .bg-violet-subtle {
      background-color: var(--vh-primary-100) !important;
      color: var(--vh-primary-700) !important;
    }

    .admin-dashboard .bg-success-subtle {
      background-color: #e8f7f0 !important;
      color: #18825d !important;
    }

    .admin-dashboard .bg-warning-subtle {
      background-color: #fff4e2 !important;
      color: #b96a00 !important;
    }

    .admin-dashboard .card h6,
    .admin-dashboard .card .h6 {
      font-weight: 700;
      color: var(--vh-text-strong);
    }

  </style>
</head>
<body class="admin-dashboard">
<div class="container-fluid p-0">
  <div class="row g-0">

    <?php include __DIR__ . '/../includes/sidebar.php' ?>

    <!-- Main unified content -->
    <div class="col main-content p-3 p-lg-4">

      <!-- Topbar -->
      <div class="dashboard-topbar d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-3">
          <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2"><ion-icon name="menu-outline"></ion-icon></button>
          <div>
            <h2 class="m-0 page-title">Dashboard</h2>
            <div class="page-breadcrumb">Home / Dashboard</div>
          </div>
        </div>
        <div class="profile-menu" data-profile-menu>
          <button class="profile-trigger" type="button" data-profile-trigger aria-expanded="false" aria-haspopup="true">
            <img src="../img/profile.jpg" class="rounded-circle" width="36" height="36" alt="">
            <div class="profile-text">
              <div class="profile-name"><?= htmlspecialchars($userName) ?></div>
              <div class="profile-role"><?= htmlspecialchars($userRole) ?></div>
            </div>
            <ion-icon class="profile-caret" name="chevron-down-outline"></ion-icon>
          </button>
          <div class="profile-dropdown" data-profile-dropdown role="menu">
            <a href="<?= u('auth/logout.php') ?>" role="menuitem">Sign out</a>
          </div>
        </div>
      </div>

      <!-- ===================== Smart Warehousing ===================== -->
      <h5 class="section-title d-flex align-items-center gap-2"><ion-icon name="business-outline"></ion-icon> Smart Warehousing</h5>

      <div class="row g-3 mb-3">
        <div class="col-6 col-md-3"><div class="card shadow-sm kpi-card h-100"><div class="card-body d-flex gap-3 align-items-center">
          <div class="icon-wrap bg-primary-subtle"><ion-icon name="cube-outline"></ion-icon></div>
          <div><div class="text-muted small">Total SKUs</div><div class="h4 m-0"><?= number_format($sw_totalSkus) ?></div></div>
        </div></div></div>
        <div class="col-6 col-md-3"><div class="card shadow-sm kpi-card h-100"><div class="card-body d-flex gap-3 align-items-center">
          <div class="icon-wrap bg-success-subtle"><ion-icon name="layers-outline"></ion-icon></div>
          <div><div class="text-muted small">On-hand Units</div><div class="h4 m-0"><?= number_format($sw_totalUnits) ?></div></div>
        </div></div></div>
        <div class="col-6 col-md-3"><div class="card shadow-sm kpi-card h-100"><div class="card-body d-flex gap-3 align-items-center">
          <div class="icon-wrap bg-warning-subtle"><ion-icon name="alert-circle-outline"></ion-icon></div>
          <div><div class="text-muted small">Low Stock</div><div class="h4 m-0"><?= number_format($sw_lowStockCount) ?></div></div>
        </div></div></div>
        <div class="col-6 col-md-3"><div class="card shadow-sm kpi-card h-100"><div class="card-body d-flex gap-3 align-items-center">
          <div class="icon-wrap bg-info-subtle"><ion-icon name="navigate-outline"></ion-icon></div>
          <div><div class="text-muted small">Locations</div><div class="h4 m-0"><?= number_format($sw_locationsCount) ?></div></div>
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
          <?php if(!$sw_hasShip): ?><div class="text-muted small mt-2">Tip: create a <code>shipments</code> table with a <code>status</code> column.</div><?php endif; ?>
        </div></div></div>
      </div>

      <!-- ===================== Procurement & Sourcing ===================== -->
      <h5 class="section-title d-flex align-items-center gap-2"><ion-icon name="pricetags-outline"></ion-icon> Procurement &amp; Sourcing</h5>

      <div class="row g-3 mb-3">
        <div class="col-6 col-md-3"><div class="card shadow-sm kpi-card h-100"><div class="card-body d-flex gap-3 align-items-center">
          <div class="icon-wrap bg-primary-subtle"><ion-icon name="people-outline"></ion-icon></div>
          <div><div class="text-muted small">Active Suppliers</div><div class="h4 m-0"><?= number_format($pr_activeSuppliers) ?></div></div>
        </div></div></div>
        <div class="col-6 col-md-3"><div class="card shadow-sm kpi-card h-100"><div class="card-body d-flex gap-3 align-items-center">
          <div class="icon-wrap bg-info-subtle"><ion-icon name="mail-open-outline"></ion-icon></div>
          <div><div class="text-muted small">Open RFQs</div><div class="h4 m-0"><?= number_format($pr_openRFQs) ?></div></div>
        </div></div></div>
        <div class="col-6 col-md-3"><div class="card shadow-sm kpi-card h-100"><div class="card-body d-flex gap-3 align-items-center">
          <div class="icon-wrap bg-warning-subtle"><ion-icon name="document-text-outline"></ion-icon></div>
          <div><div class="text-muted small">Open POs</div><div class="h4 m-0"><?= number_format($pr_openPOs) ?></div></div>
        </div></div></div>
        <div class="col-6 col-md-3"><div class="card shadow-sm kpi-card h-100"><div class="card-body d-flex gap-3 align-items-center">
          <div class="icon-wrap bg-success-subtle"><ion-icon name="cash-outline"></ion-icon></div>
          <div><div class="text-muted small">Spend (This Month)</div><div class="h4 m-0">₱<?= number_format($pr_spendThisMonth,2) ?></div></div>
        </div></div></div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-12 col-lg-6"><div class="card shadow-sm chart-card h-100"><div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2"><h6 class="m-0">PO Status</h6><ion-icon name="pie-chart-outline"></ion-icon></div>
          <canvas id="pr_chartStatus"></canvas>
          <?php if(!$poHeaderTbl): ?><div class="text-muted small mt-2">Tip: ensure a <code>pos</code> or <code>purchase_orders</code> table with a <code>status</code> column.</div><?php endif; ?>
        </div></div></div>

        <div class="col-12 col-lg-6"><div class="card shadow-sm chart-card h-100"><div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2"><h6 class="m-0">Monthly Spend</h6><ion-icon name="stats-chart-outline"></ion-icon></div>
          <canvas id="pr_chartMonth"></canvas>
          <?php if(!$poHeaderTbl || !$poDateCol): ?><div class="text-muted small mt-2">Tip: add a date column like <code>issue_date</code> to your PO header.</div><?php endif; ?>
        </div></div></div>
      </div>

      <?php if ($pr_hasTopSupData): ?>
      <div class="row g-3 mb-4">
        <div class="col-12"><div class="card shadow-sm chart-card h-100"><div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2"><h6 class="m-0">Top Suppliers (last 90 days)</h6><ion-icon name="ribbon-outline"></ion-icon></div>
          <canvas id="pr_chartSup"></canvas>
        </div></div></div>
      </div>
      <?php endif; ?>

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

      <?php if ($plt_hasStatusData || $plt_hasDailyData): ?>
      <div class="row g-3 mb-3">
        <?php if ($plt_hasStatusData): ?>
        <div class="col-12 col-lg-6"><div class="card shadow-sm chart-card h-100"><div class="card-body">
          <h6 class="mb-2 d-flex align-items-center gap-2"><ion-icon name="pie-chart-outline"></ion-icon> Status (last 30d)</h6>
          <canvas id="plt_status"></canvas>
        </div></div></div>
        <?php endif; ?>
        <?php if ($plt_hasDailyData): ?>
        <div class="col-12 col-lg-6"><div class="card shadow-sm chart-card h-100"><div class="card-body">
          <h6 class="mb-2 d-flex align-items-center gap-2"><ion-icon name="bar-chart-outline"></ion-icon> Shipments per Day (last 14d)</h6>
          <canvas id="plt_daily"></canvas>
        </div></div></div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <div class="row g-3">
        <?php if ($plt_hasUpcomingData): ?>
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
                    <?php else: foreach ($plt_upcoming as $r): ?>
                      <tr>
                        <td><?= htmlspecialchars($r["schedule_date"]) ?></td>
                        <td class="fw-semibold"><?= htmlspecialchars($r["shipment_no"] ?: "SHP-" . $r["id"]) ?></td>
                        <td><?= htmlspecialchars($r["project_name"] ?: "-") ?></td>
                        <td class="text-muted"><?= htmlspecialchars($r["origin"] ?: "-") ?> → <?= htmlspecialchars($r["destination"] ?: "-") ?></td>
                        <td><span class="badge bg-secondary badge-status"><?= htmlspecialchars(ucfirst(str_replace("_"," ",$r["status"]))) ?></span></td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($plt_hasRecentData): ?>
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
                    <?php else: foreach ($plt_recent as $r): ?>
                      <tr>
                        <td class="fw-semibold"><?= htmlspecialchars($r["shipment_no"] ?: "SHP-" . $r["id"]) ?></td>
                        <td><?= htmlspecialchars($r["project_name"] ?: "-") ?></td>
                        <td><?= htmlspecialchars($r["schedule_date"] ?: "-") ?></td>
                        <td><?= htmlspecialchars($r["eta_date"] ?: "-") ?></td>
                        <td><span class="badge bg-secondary badge-status"><?= htmlspecialchars(ucfirst(str_replace("_"," ",$r["status"]))) ?></span></td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($plt_hasAtRiskData): ?>
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
                    <?php else: foreach ($plt_atRisk as $p): ?>
                      <tr>
                        <td class="fw-semibold"><?= htmlspecialchars($p["code"] ?: "PRJ-" . $p["id"]) ?></td>
                        <td><?= htmlspecialchars($p["name"]) ?></td>
                        <td><?= htmlspecialchars($p["owner_name"] ?: "—") ?></td>
                        <td><?= htmlspecialchars($p["deadline_date"] ?: "—") ?></td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <?php if (!$plt_hasUpcomingData && !$plt_hasRecentData && !$plt_hasAtRiskData && !$plt_hasStatusData && !$plt_hasDailyData): ?>
        <div class="col-12">
          <div class="alert alert-light border text-muted mb-0">No PLT dashboard data available yet.</div>
        </div>
        <?php endif; ?>

      </div><!-- /PLT tables -->

    </div><!-- /main -->
  </div><!-- /row -->
</div><!-- /container -->

<script>
  // Global Chart.js defaults
  Chart.defaults.font.family = getComputedStyle(document.body).fontFamily || 'system-ui';
  Chart.defaults.color = getComputedStyle(document.body).color || '#222';
  const dashboardStyles = getComputedStyle(document.body);
  const purple = dashboardStyles.getPropertyValue('--vh-primary').trim() || '#6a45ff';
  const purpleDeep = dashboardStyles.getPropertyValue('--vh-primary-700').trim() || '#4d2bd8';
  const purpleSoft = dashboardStyles.getPropertyValue('--vh-primary-100').trim() || '#efeaff';
  const purpleSoft2 = dashboardStyles.getPropertyValue('--vh-primary-200').trim() || '#e0d8ff';
  const chartGrid = 'rgba(106, 69, 255, 0.12)';
  Chart.defaults.borderColor = chartGrid;

  /* ---------- SW charts ---------- */
  const sw_catLabels   = <?= json_encode($sw_catLabels) ?>;
  const sw_catData     = <?= json_encode($sw_catData) ?>;
  const sw_trendLabels = <?= json_encode($sw_trendLabels) ?>;
  const sw_incoming    = <?= json_encode($sw_incoming) ?>;
  const sw_outgoing    = <?= json_encode($sw_outgoing) ?>;
  const sw_locLabels   = <?= json_encode($sw_locLabels) ?>;
  const sw_locData     = <?= json_encode($sw_locData) ?>;
  const sw_shipLabels  = <?= json_encode($sw_shipLabels) ?>;
  const sw_shipData    = <?= json_encode($sw_shipData) ?>;

  const swCatEl = document.getElementById('sw_catChart');
  if (swCatEl) new Chart(swCatEl, {
    type:'bar',
    data:{ labels: sw_catLabels, datasets:[{ label:'Units', data: sw_catData, borderWidth:1, backgroundColor: purpleSoft2, borderColor: purpleDeep }] },
    options:{ maintainAspectRatio:false, scales:{ y:{ beginAtZero:true, ticks:{ precision:0 } }, x:{ grid:{ display:false } } }, plugins:{ legend:{ display:false } } }
  });

  const swTrendEl = document.getElementById('sw_trendChart');
  if (swTrendEl) new Chart(swTrendEl, {
    type:'line',
    data:{ labels: sw_trendLabels, datasets:[
      { label:'Incoming', data: sw_incoming, tension:.35, fill:false, borderWidth:2.5, pointRadius:0, borderColor: purple, backgroundColor: purpleSoft },
      { label:'Outgoing', data: sw_outgoing, tension:.35, fill:false, borderWidth:2.5, pointRadius:0, borderColor: purpleDeep, backgroundColor: purpleSoft2 }
    ]},
    options:{ maintainAspectRatio:false, scales:{ y:{ beginAtZero:true, ticks:{ precision:0 } } }, plugins:{ tooltip:{ mode:'index', intersect:false } } }
  });

  const swLocEl = document.getElementById('sw_locChart');
  if (swLocEl) new Chart(swLocEl, {
    type:'doughnut',
    data:{ labels: sw_locLabels, datasets:[{ data: sw_locData, borderWidth:1, backgroundColor: [purple, purpleDeep, purpleSoft2, '#cabdff', '#b39bff', '#9d86ff', '#8a73ff'] }] },
    options:{ maintainAspectRatio:false, plugins:{ legend:{ position:'bottom' } } }
  });

  const swShipEl = document.getElementById('sw_shipChart');
  if (swShipEl) new Chart(swShipEl, {
    type:'doughnut',
    data:{ labels: sw_shipLabels, datasets:[{ data: sw_shipData, borderWidth:1, backgroundColor: [purple, '#7b5bff', '#9b87ff'] }] },
    options:{ maintainAspectRatio:false, plugins:{ legend:{ position:'bottom' } } }
  });

  /* ---------- Procurement charts ---------- */
  const pr_poStatusLabels = <?= json_encode($pr_poStatusLabels) ?>;
  const pr_poStatusData   = <?= json_encode($pr_poStatusData) ?>;
  const pr_monLabels      = <?= json_encode($pr_monLabels) ?>;
  const pr_monAmounts     = <?= json_encode($pr_monAmounts) ?>;
  const pr_topSupLabels   = <?= json_encode($pr_topSupLabels) ?>;
  const pr_topSupAmts     = <?= json_encode($pr_topSupAmts) ?>;

  const prStatusEl = document.getElementById('pr_chartStatus');
  if (prStatusEl) new Chart(prStatusEl, {
    type:'doughnut',
    data:{ labels: pr_poStatusLabels, datasets:[{ data: pr_poStatusData, borderWidth:1, backgroundColor: [purple, '#7b5bff', '#8c6bff', '#9d86ff', '#b39bff', '#cabdff', purpleSoft2] }] },
    options:{ maintainAspectRatio:false, plugins:{ legend:{ position:'bottom' } } }
  });

  const prMonthEl = document.getElementById('pr_chartMonth');
  if (prMonthEl) new Chart(prMonthEl, {
    type:'bar',
    data:{ labels: pr_monLabels, datasets:[{ label:'Spend', data: pr_monAmounts, borderWidth:1, backgroundColor: purpleSoft2, borderColor: purpleDeep }] },
    options:{ maintainAspectRatio:false, scales:{ y:{ beginAtZero:true, ticks:{ precision:0 } }, x:{ grid:{ display:false } } }, plugins:{ legend:{ display:false }, tooltip:{ mode:'index', intersect:false } } }
  });

  const prSupEl = document.getElementById('pr_chartSup');
  if (prSupEl) new Chart(prSupEl, {
    type:'bar',
    data:{ labels: pr_topSupLabels, datasets:[{ label:'Amount', data: pr_topSupAmts, borderWidth:1, backgroundColor: purpleSoft2, borderColor: purpleDeep }] },
    options:{ maintainAspectRatio:false, indexAxis:'y', scales:{ x:{ beginAtZero:true, ticks:{ precision:0 } }, y:{ grid:{ display:false } } }, plugins:{ legend:{ display:false } } }
  });

  /* ---------- PLT charts ---------- */
  const plt_stLabels    = <?= json_encode($plt_stLabels) ?>;
  const plt_stValues    = <?= json_encode($plt_stValues) ?>;
  const plt_dailyLabels = <?= json_encode($plt_dailyLabels) ?>;
  const plt_dailyValues = <?= json_encode($plt_dailyValues) ?>;

  const pltStatusEl = document.getElementById('plt_status');
  if (pltStatusEl) new Chart(pltStatusEl, {
    type:'doughnut',
    data:{ labels: plt_stLabels, datasets:[{ data: plt_stValues, borderWidth:1, backgroundColor: [purple, '#7b5bff', '#9b87ff', '#b39bff', purpleSoft2] }] },
    options:{ maintainAspectRatio:false, plugins:{ legend:{ position:'bottom' } } }
  });
  const pltDailyEl = document.getElementById('plt_daily');
  if (pltDailyEl) new Chart(pltDailyEl, {
    type:'bar',
    data:{ labels: plt_dailyLabels, datasets:[{ label:'Shipments', data: plt_dailyValues, borderWidth:1, backgroundColor: purpleSoft2, borderColor: purpleDeep }] },
    options:{ maintainAspectRatio:false, scales:{ y:{ beginAtZero:true, ticks:{ precision:0 } }, x:{ grid:{ display:false } } }, plugins:{ legend:{ display:false } } }
  });

</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="../js/profile-dropdown.js"></script>
</body>
</html>
