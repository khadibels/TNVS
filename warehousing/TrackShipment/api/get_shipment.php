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

$originExpr = $code ? "CONCAT_WS(' - ', o.$code, o.$label)" : "o.$label";
$destExpr   = $code ? "CONCAT_WS(' - ', d.$code, d.$label)" : "d.$label";

$hdr = $pdo->prepare("
  SELECT s.id, s.ref_no, s.status, s.carrier,
         DATE_FORMAT(s.expected_pickup,'%Y-%m-%d')   AS expected_pickup,
         DATE_FORMAT(s.expected_delivery,'%Y-%m-%d') AS expected_delivery,
         s.contact_name, s.contact_phone, s.notes,
         $originExpr AS origin,
         $destExpr   AS destination
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

echo json_encode(["ok"=>true, "shipment"=>$shipment, "events"=>$events]);
