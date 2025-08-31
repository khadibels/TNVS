<?php
require_once __DIR__ . "/../../../includes/config.php";
require_once __DIR__ . "/../../../includes/auth.php";
require_login();
header("Content-Type: application/json");

$fromIn = $_GET["from"] ?? "";
$toIn = $_GET["to"] ?? "";
$status = trim($_GET["status"] ?? "");
$carrier = trim($_GET["carrier"] ?? "");

$validDate = fn($s) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
$from = $validDate($fromIn) ? $fromIn : null;
$to = $validDate($toIn) ? $toIn : null;

$w = [];
$p = [];
if ($status !== "") {
    $w[] = "s.status = ?";
    $p[] = $status;
}
if ($carrier !== "") {
    $w[] = "s.carrier = ?";
    $p[] = $carrier;
}
if ($from !== null) {
    $w[] = "s.expected_delivery >= ?";
    $p[] = $from;
}
if ($to !== null) {
    $w[] = "s.expected_delivery <= ?";
    $p[] = $to;
}
$where = $w ? "WHERE " . implode(" AND ", $w) : "";

$sql = "
  SELECT
    s.id, s.ref_no, s.status, s.carrier, s.expected_delivery,
    MIN(CASE WHEN se.event_type='Dispatched' THEN se.event_time END) AS first_dispatch,
    MAX(CASE WHEN se.event_type='Delivered' THEN se.event_time END)  AS delivered_at,
    CONCAT(o.code,' - ',o.name) AS origin,
    CONCAT(d.code,' - ',d.name) AS destination
  FROM shipments s
  LEFT JOIN shipment_events se ON se.shipment_id = s.id
  LEFT JOIN warehouse_locations o ON o.id = s.origin_id
  LEFT JOIN warehouse_locations d ON d.id = s.destination_id
  $where
  GROUP BY s.id
  ORDER BY s.id DESC
  LIMIT 500
";
$st = $pdo->prepare($sql);
$st->execute($p);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$today = new DateTimeImmutable("today");

$tot = [
    "total" => 0,
    "delivered" => 0,
    "in_transit" => 0,
    "delayed" => 0,
    "cancelled" => 0,
    "returned" => 0,
    "on_time_rate" => 0,
    "avg_transit_days" => null,
];
$byStatus = [
    "Draft" => 0,
    "Ready" => 0,
    "Dispatched" => 0,
    "In Transit" => 0,
    "Delivered" => 0,
    "Delayed" => 0,
    "Cancelled" => 0,
    "Returned" => 0,
];
$carriers = []; // carrier => ['total'=>..,'deliv'=>..,'ontime'=>..]
$lanes = []; // "WH1 - Main → WH2 - Overflow" => count
$lateList = []; // top late not-delivered

$sumTransit = 0;
$cntTransit = 0;
$ontime = 0;
$delivCnt = 0;

foreach ($rows as $r) {
    $tot["total"]++;
    $stName = $r["status"] ?? "";
    if (isset($byStatus[$stName])) {
        $byStatus[$stName]++;
    }

    // delivered metrics
    $deliveredAt = $r["delivered_at"]
        ? new DateTimeImmutable($r["delivered_at"])
        : null;
    if ($stName === "Delivered") {
        $tot["delivered"]++;
        $delivCnt++;
        if ($deliveredAt) {
            $exp = $r["expected_delivery"]
                ? new DateTimeImmutable($r["expected_delivery"])
                : null;
            if ($exp && $deliveredAt <= $exp->setTime(23, 59, 59)) {
                $ontime++;
            }
        }
    } elseif ($stName === "Cancelled") {
        $tot["cancelled"]++;
    } elseif ($stName === "Returned") {
        $tot["returned"]++;
    } elseif ($stName === "Delayed") {
        $tot["delayed"]++;
    } else {
        // late if past expected_delivery and not delivered/cancelled/returned
        if ($r["expected_delivery"]) {
            $exp = new DateTimeImmutable($r["expected_delivery"]);
            if ($today > $exp) {
                $days = $today->diff($exp)->days;
                $lateList[] = [
                    "id" => $r["id"],
                    "ref_no" => $r["ref_no"],
                    "days_overdue" => $days,
                    "dest" => $r["destination"],
                ];
            }
        }
        if (
            $stName === "In Transit" ||
            $stName === "Dispatched" ||
            $stName === "Ready"
        ) {
            $tot["in_transit"]++;
        }
    }

    // transit time = Delivered - first Dispatched (if both exist)
    $firstDisp = $r["first_dispatch"]
        ? new DateTimeImmutable($r["first_dispatch"])
        : null;
    if ($firstDisp && $deliveredAt) {
        $sumTransit += max(0, (int) $deliveredAt->diff($firstDisp)->days);
        $cntTransit++;
    }

    // carriers
    $c = $r["carrier"] ?: "—";
    $carriers[$c] = $carriers[$c] ?? [
        "total" => 0,
        "deliv" => 0,
        "ontime" => 0,
    ];
    $carriers[$c]["total"]++;
    if ($stName === "Delivered") {
        $carriers[$c]["deliv"]++;
        if (
            $deliveredAt &&
            $r["expected_delivery"] &&
            $deliveredAt <=
                (new DateTimeImmutable($r["expected_delivery"]))->setTime(
                    23,
                    59,
                    59
                )
        ) {
            $carriers[$c]["ontime"]++;
        }
    }

    // lanes
    $lane = ($r["origin"] ?: "—") . " → " . ($r["destination"] ?: "—");
    $lanes[$lane] = ($lanes[$lane] ?? 0) + 1;
}

$tot["on_time_rate"] = $delivCnt ? round(($ontime * 100) / $delivCnt, 1) : 0.0;
$tot["avg_transit_days"] = $cntTransit
    ? round($sumTransit / $cntTransit, 1)
    : null;

// format carriers & lanes
$carOut = [];
foreach ($carriers as $name => $m) {
    $rate = $m["deliv"] ? round(($m["ontime"] * 100) / $m["deliv"], 1) : 0.0;
    $carOut[] = [
        "carrier" => $name,
        "total" => $m["total"],
        "delivered" => $m["deliv"],
        "on_time_rate" => $rate,
    ];
}
usort($carOut, fn($a, $b) => $b["total"] <=> $a["total"]);
$laneOut = [];
foreach ($lanes as $k => $v) {
    $laneOut[] = ["lane" => $k, "total" => $v];
}
usort($laneOut, fn($a, $b) => $b["total"] <=> $a["total"]);

usort($lateList, fn($a, $b) => $b["days_overdue"] <=> $a["days_overdue"]);
$lateOut = array_slice($lateList, 0, 10);

echo json_encode([
    "ok" => true,
    "totals" => $tot,
    "status_breakdown" => $byStatus,
    "carriers" => $carOut,
    "lanes" => $laneOut,
    "late" => $lateOut,
]);

// ----- CSV download branch -----
if (isset($_GET["format"]) && strtolower($_GET["format"]) === "csv") {
    $payload = $payload ?? [
        "ok" => $ok ?? true,
        "totals" => $totals ?? [],
        "status_breakdown" => $status_breakdown ?? [],
        "carriers" => $carriers ?? [],
        "lanes" => $lanes ?? [],
        "late" => $late ?? [],
    ];

    header("Content-Type: text/csv; charset=utf-8");
    header(
        "Content-Disposition: attachment; filename=shipments_report_" .
            date("Ymd_His") .
            ".csv"
    );

    $out = fopen("php://output", "w");

    // Report header / filters
    fputcsv($out, ["Shipments Report"]);
    fputcsv($out, ["Generated", date("Y-m-d H:i:s")]);
    fputcsv($out, [
        "From",
        $_GET["from"] ?? "",
        "To",
        $_GET["to"] ?? "",
        "Status",
        $_GET["status"] ?? "",
        "Carrier",
        $_GET["carrier"] ?? "",
    ]);
    fputcsv($out, []); // blank line

    // Totals
    fputcsv($out, ["Totals"]);
    $t = $payload["totals"] ?? [];
    fputcsv($out, ["Total", $t["total"] ?? 0]);
    fputcsv($out, ["Delivered", $t["delivered"] ?? 0]);
    fputcsv($out, ["In Transit", $t["in_transit"] ?? 0]);
    fputcsv($out, ["Delayed", $t["delayed"] ?? 0]);
    fputcsv($out, ["Cancelled", $t["cancelled"] ?? 0]);
    fputcsv($out, ["Returned", $t["returned"] ?? 0]);
    fputcsv($out, ["On-time %", $t["on_time_rate"] ?? 0]);
    fputcsv($out, ["Avg Transit (days)", $t["avg_transit_days"] ?? "—"]);
    fputcsv($out, []);

    // By Status
    fputcsv($out, ["By Status"]);
    fputcsv($out, ["Status", "Total"]);
    foreach ($payload["status_breakdown"] ?? [] as $status => $cnt) {
        fputcsv($out, [$status, $cnt]);
    }
    fputcsv($out, []);

    // Carriers
    fputcsv($out, ["Carriers"]);
    fputcsv($out, ["Carrier", "Total", "Delivered", "On-time %"]);
    foreach ($payload["carriers"] ?? [] as $c) {
        fputcsv($out, [
            $c["carrier"] ?? "—",
            $c["total"] ?? 0,
            $c["delivered"] ?? 0,
            $c["on_time_rate"] ?? 0,
        ]);
    }
    fputcsv($out, []);

    // Lanes
    fputcsv($out, ["Top Lanes"]);
    fputcsv($out, ["Lane", "Total"]);
    foreach ($payload["lanes"] ?? [] as $ln) {
        fputcsv($out, [$ln["lane"] ?? "—", $ln["total"] ?? 0]);
    }
    fputcsv($out, []);

    // Late
    fputcsv($out, ["Most Overdue (not delivered)"]);
    fputcsv($out, ["Ref", "Dest", "Days overdue"]);
    foreach ($payload["late"] ?? [] as $r) {
        fputcsv($out, [
            $r["ref_no"] ?? "—",
            $r["dest"] ?? "—",
            $r["days_overdue"] ?? 0,
        ]);
    }

    fclose($out);
    exit();
}

