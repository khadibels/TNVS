<?php
require_once __DIR__."/../../../includes/config.php";
require_once __DIR__."/../../../includes/auth.php";
require_once __DIR__."/../../../includes/db.php";

require_login("json");
header("Content-Type: application/json; charset=utf-8");

$pdo = db('wms');
if (!$pdo instanceof PDO) { http_response_code(500); echo json_encode(["ok"=>false,"err"=>"DB not available"]); exit; }

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); echo json_encode(["ok"=>false,"err"=>"Missing id"]); exit; }

function col_exists(PDO $pdo, string $t, string $c): bool {
  $s=$pdo->prepare("SELECT 1 FROM information_schema.columns
                     WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1");
  $s->execute([$t,$c]); return (bool)$s->fetchColumn();
}
$label = col_exists($pdo,'warehouse_locations','name') ? 'name'
       : (col_exists($pdo,'warehouse_locations','label') ? 'label' : 'name');
$code  = col_exists($pdo,'warehouse_locations','code') ? 'code' : null;
$hasLat = col_exists($pdo, 'warehouse_locations', 'latitude');
$hasLng = col_exists($pdo, 'warehouse_locations', 'longitude');

$originExpr = $code ? "CONCAT_WS(' - ', o.$code, o.$label)" : "o.$label";
$destExpr   = $code ? "CONCAT_WS(' - ', d.$code, d.$label)" : "d.$label";
$originLatSel = ($hasLat && $hasLng) ? "o.latitude AS origin_latitude, o.longitude AS origin_longitude," : "NULL AS origin_latitude, NULL AS origin_longitude,";
$destLatSel = ($hasLat && $hasLng) ? "d.latitude AS destination_latitude, d.longitude AS destination_longitude," : "NULL AS destination_latitude, NULL AS destination_longitude,";

$hdr = $pdo->prepare("
  SELECT s.id, s.ref_no, s.origin_id, s.destination_id, s.status, s.carrier,
         DATE_FORMAT(s.expected_pickup,'%Y-%m-%d')   AS expected_pickup,
         DATE_FORMAT(s.expected_delivery,'%Y-%m-%d') AS expected_delivery,
         s.contact_name, s.contact_phone, s.notes,
         $originExpr AS origin,
         $destExpr   AS destination,
         $originLatSel
         $destLatSel
         o.address   AS origin_address,
         d.address   AS destination_address
    FROM shipments s
    JOIN warehouse_locations o ON o.id=s.origin_id
    JOIN warehouse_locations d ON d.id=s.destination_id
   WHERE s.id=?");
$hdr->execute([$id]);
$shipment = $hdr->fetch(PDO::FETCH_ASSOC);
if (!$shipment) { http_response_code(404); echo json_encode(["ok"=>false,"err"=>"Not found"]); exit; }

$ev = $pdo->prepare("
  SELECT DATE_FORMAT(event_time,'%Y-%m-%d %H:%i:%s') AS event_time, event_type, details
    FROM shipment_events
   WHERE shipment_id=?
   ORDER BY event_time DESC, id DESC");
$ev->execute([$id]);
$events = $ev->fetchAll(PDO::FETCH_ASSOC);

// AI insights from procurement (if ref_no matches PO)
$ai = null;
try {
  $proc = db('proc');
  if ($proc instanceof PDO) {
    $chk = $proc->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='po_ai_insights'");
    $chk->execute();
    if ($chk->fetchColumn()) {
      $poId = 0;
      $poNo = '';
      if (!empty($shipment['ref_no'])) {
        $po = $proc->prepare("SELECT id, po_no FROM pos WHERE po_no=? LIMIT 1");
        $po->execute([$shipment['ref_no']]);
        $row = $po->fetch(PDO::FETCH_ASSOC);
        if ($row) { $poId = (int)$row['id']; $poNo = (string)$row['po_no']; }
      }
      if ($poId > 0) {
        $ins = $proc->prepare("
          SELECT delivery_method, dates_json, times_json, locations_json, summary
            FROM po_ai_insights
           WHERE po_id=?
           ORDER BY created_at DESC, id DESC
           LIMIT 1
        ");
        $ins->execute([$poId]);
        $row = $ins->fetch(PDO::FETCH_ASSOC);
        if ($row) {
          $ai = [
            'delivery_method' => $row['delivery_method'],
            'dates' => json_decode($row['dates_json'] ?? '[]', true) ?: [],
            'times' => json_decode($row['times_json'] ?? '[]', true) ?: [],
            'locations' => json_decode($row['locations_json'] ?? '[]', true) ?: [],
            'summary' => $row['summary'] ?? '',
            'source_po' => $poNo
          ];
        }
      } else {
        $ins = $proc->query("
          SELECT p.po_no, i.delivery_method, i.dates_json, i.times_json, i.locations_json, i.summary
            FROM po_ai_insights i
            JOIN pos p ON p.id=i.po_id
           ORDER BY i.created_at DESC, i.id DESC
           LIMIT 1
        ");
        $row = $ins ? $ins->fetch(PDO::FETCH_ASSOC) : null;
        if ($row) {
          $ai = [
            'delivery_method' => $row['delivery_method'],
            'dates' => json_decode($row['dates_json'] ?? '[]', true) ?: [],
            'times' => json_decode($row['times_json'] ?? '[]', true) ?: [],
            'locations' => json_decode($row['locations_json'] ?? '[]', true) ?: [],
            'summary' => $row['summary'] ?? '',
            'source_po' => $row['po_no'] ?? ''
          ];
        }
      }
    }
  }
} catch (Throwable $e) { $ai = null; }

echo json_encode(["ok"=>true, "shipment"=>$shipment, "events"=>$events, "ai_insights"=>$ai]);
