<?php
require_once __DIR__ . "/../../../includes/config.php";
require_once __DIR__ . "/../../../includes/auth.php";
require_login();
header("Content-Type: application/json");

$id = (int) ($_GET["id"] ?? 0);
if (!$id) {
    http_response_code(400);
    echo json_encode(["ok" => false, "err" => "Missing id"]);
    exit();
}

$hdr = $pdo->prepare("
  SELECT s.id, s.ref_no, s.status, s.carrier,
         DATE_FORMAT(s.expected_pickup,  '%Y-%m-%d') AS expected_pickup,
         DATE_FORMAT(s.expected_delivery,'%Y-%m-%d') AS expected_delivery,
         s.contact_name, s.contact_phone, s.notes,
         CONCAT(o.code,' - ',o.name) AS origin,
         CONCAT(d.code,' - ',d.name) AS destination
  FROM shipments s
  JOIN warehouse_locations o ON o.id = s.origin_id
  JOIN warehouse_locations d ON d.id = s.destination_id
  WHERE s.id = ?
");
$hdr->execute([$id]);
$shipment = $hdr->fetch(PDO::FETCH_ASSOC);
if (!$shipment) {
    http_response_code(404);
    echo json_encode(["ok" => false, "err" => "Not found"]);
    exit();
}

$ev = $pdo->prepare("
  SELECT DATE_FORMAT(event_time,'%Y-%m-%d %H:%i:%s') AS event_time,
         event_type, details
  FROM shipment_events
  WHERE shipment_id = ?
  ORDER BY event_time DESC, id DESC
");
$ev->execute([$id]);
$events = $ev->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(["ok" => true, "shipment" => $shipment, "events" => $events]);
