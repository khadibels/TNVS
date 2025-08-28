<?php
// PLT/api/plt_report_shipments.php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();
// If you gate writes/exports by role, you can also do: require_role(['admin','manager','staff','viewer']);

header('Cache-Control: no-store');

$fmt      = strtolower($_GET['format'] ?? 'json');
$from     = trim($_GET['from'] ?? '');
$to       = trim($_GET['to'] ?? '');
$status   = trim($_GET['status'] ?? '');
$vehicle  = trim($_GET['vehicle'] ?? '');

$w = []; $p = [];
if ($from !== '') { $w[] = 's.schedule_date >= :from'; $p[':from'] = $from; }
if ($to   !== '') { $w[] = 's.schedule_date <= :to';   $p[':to']   = $to;   }
if ($status !== '') { $w[] = 's.status = :status'; $p[':status'] = $status; }
if ($vehicle !== '') { $w[] = 's.vehicle LIKE :veh'; $p[':veh'] = '%'.$vehicle.'%'; }
$where = $w ? ('WHERE '.implode(' AND ',$w)) : '';

/* detect optional columns for nicer KPIs */
$cols = [];
try {
  $q=$pdo->query("DESCRIBE plt_shipments");
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){ $cols[strtolower($r['Field'])]=true; }
} catch(Exception $e) {}
$hasDeliveredAt = isset($cols['delivered_at']);

/* Totals */
$sqlTotals = "
  SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN s.status='delivered' THEN 1 ELSE 0 END) AS delivered,
    AVG(DATEDIFF(COALESCE(" . ($hasDeliveredAt ? "s.delivered_at" : "s.eta_date") . ", s.eta_date), s.schedule_date)) AS avg_transit_days
  FROM plt_shipments s
  $where
";
$tot = $pdo->prepare($sqlTotals); $tot->execute($p);
$totals = $tot->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0,'delivered'=>0,'avg_transit_days'=>null];
$totals['total'] = (int)$totals['total'];
$totals['delivered'] = (int)$totals['delivered'];
$totals['avg_transit_days'] = is_null($totals['avg_transit_days']) ? null : round((float)$totals['avg_transit_days'],1);

/* On-time rate: if delivered_at exists, on time = delivered_at <= eta_date */
$onTime = null;
if ($hasDeliveredAt) {
  $sqlOn = "
    SELECT
      SUM(CASE WHEN s.status='delivered' THEN 1 ELSE 0 END) AS delivered,
      SUM(CASE WHEN s.status='delivered' AND s.delivered_at IS NOT NULL AND DATE(s.delivered_at) <= s.eta_date THEN 1 ELSE 0 END) AS ontime
    FROM plt_shipments s
    $where
  ";
  $st = $pdo->prepare($sqlOn); $st->execute($p);
  $r = $st->fetch(PDO::FETCH_ASSOC) ?: ['delivered'=>0,'ontime'=>0];
  if ((int)$r['delivered'] > 0) {
    $onTime = round(((int)$r['ontime'] * 100.0) / (int)$r['delivered'], 1);
  } else {
    $onTime = null;
  }
}
$totals['on_time_rate'] = $onTime;

/* Status breakdown */
$sb = [];
$sqlSB = "SELECT s.status, COUNT(*) AS c FROM plt_shipments s $where GROUP BY s.status";
$st = $pdo->prepare($sqlSB); $st->execute($p);
foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){ $sb[$r['status'] ?: '—'] = (int)$r['c']; }

/* Vehicles (top) */
$sqlVeh = "SELECT COALESCE(NULLIF(TRIM(s.vehicle),''),'—') AS vehicle, COUNT(*) AS total
           FROM plt_shipments s $where GROUP BY vehicle ORDER BY total DESC LIMIT 10";
$vv = $pdo->prepare($sqlVeh); $vv->execute($p);
$vehicles = $vv->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* Lanes (origin → destination) */
$sqlLane = "SELECT CONCAT(COALESCE(NULLIF(TRIM(s.origin),''),'—'),' → ',COALESCE(NULLIF(TRIM(s.destination),''),'—')) AS lane,
                   COUNT(*) AS total
            FROM plt_shipments s $where GROUP BY lane ORDER BY total DESC LIMIT 10";
$ln = $pdo->prepare($sqlLane); $ln->execute($p);
$lanes = $ln->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* Late (not delivered, past ETA) */
$sqlLate = "SELECT s.id, COALESCE(NULLIF(s.shipment_no,''), CONCAT('SHP-',s.id)) AS ref_no,
                   s.destination AS dest, DATEDIFF(CURDATE(), s.eta_date) AS days_overdue
            FROM plt_shipments s
            WHERE (s.status IS NULL OR s.status <> 'delivered')
              AND s.eta_date IS NOT NULL AND s.eta_date < CURDATE()
              " . ($where ? " AND " . preg_replace('/^WHERE\s+/','',$where) : '') . "
            ORDER BY s.eta_date ASC
            LIMIT 20";
$lt = $pdo->prepare($sqlLate); $lt->execute($p);
$late = $lt->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* CSV export of detailed rows */
if ($fmt === 'csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=plt_shipments_report.csv');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['Shipment No','Project','Origin','Destination','Schedule','ETA','Status','Vehicle','Driver']);
  $sqlRows = "
    SELECT
      COALESCE(NULLIF(s.shipment_no,''), CONCAT('SHP-',s.id)) AS shipment_no,
      (SELECT p.name FROM plt_projects p WHERE p.id = s.project_id LIMIT 1) AS project_name,
      s.origin, s.destination, s.schedule_date, s.eta_date, s.status, s.vehicle, s.driver
    FROM plt_shipments s
    $where
    ORDER BY s.schedule_date, s.id
  ";
  $st = $pdo->prepare($sqlRows); $st->execute($p);
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($out, [
      $r['shipment_no'],
      $r['project_name'],
      $r['origin'],
      $r['destination'],
      $r['schedule_date'],
      $r['eta_date'],
      $r['status'],
      $r['vehicle'],
      $r['driver']
    ]);
  }
  fclose($out);
  exit;
}

/* JSON */
header('Content-Type: application/json');
echo json_encode([
  'totals' => $totals,
  'status_breakdown' => $sb,
  'vehicles' => $vehicles,
  'lanes' => $lanes,
  'late' => $late
], JSON_UNESCAPED_UNICODE);
