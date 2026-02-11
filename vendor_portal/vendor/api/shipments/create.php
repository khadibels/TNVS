<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../../includes/config.php';
require_once __DIR__ . '/../../../../includes/db.php';
require_once __DIR__ . '/../../../../includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

try {
  require_login();
  require_role(['vendor']);
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('POST required');

  $u = current_user();
  $vendorId = (int)($u['vendor_id'] ?? 0);
  if ($vendorId <= 0) throw new Exception('No vendor');

  $poId = (int)($_POST['po_id'] ?? 0);
  $pickupAddress = trim((string)($_POST['pickup_address'] ?? ''));
  $pickupContact = trim((string)($_POST['pickup_contact_name'] ?? ''));
  $pickupPhone = trim((string)($_POST['pickup_contact_phone'] ?? ''));
  $notes = trim((string)($_POST['notes'] ?? ''));

  if ($poId <= 0) throw new Exception('Select a PO');
  if ($pickupAddress === '') throw new Exception('Pickup address is required');
  if ($pickupContact === '') throw new Exception('Pickup contact person is required');
  if ($pickupPhone === '') throw new Exception('Pickup contact phone is required');
  // Destination is assigned by procurement/warehouse later

  $proc = db('proc');
  if (!$proc instanceof PDO) throw new Exception('DB error');
  $proc->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $poSt = $proc->prepare("SELECT id, po_no, status, vendor_ack_status FROM pos WHERE id=? AND vendor_id=? LIMIT 1");
  $poSt->execute([$poId, $vendorId]);
  $po = $poSt->fetch(PDO::FETCH_ASSOC);
  if (!$po) throw new Exception('PO not found');
  if (strtolower((string)$po['status']) !== 'issued') throw new Exception('PO must be issued');
  if (!in_array(strtolower((string)$po['vendor_ack_status']), ['accepted'], true)) {
    throw new Exception('Please accept the PO first');
  }

  $proc->exec("
    CREATE TABLE IF NOT EXISTS vendor_pickup_requests (
      id INT AUTO_INCREMENT PRIMARY KEY,
      po_id INT NOT NULL,
      vendor_id INT NOT NULL,
      shipment_id INT NULL,
      pickup_address VARCHAR(255) NOT NULL,
      pickup_contact_name VARCHAR(120) NOT NULL,
      pickup_contact_phone VARCHAR(60) NOT NULL,
      notes TEXT NULL,
      status ENUM('pending','approved','declined','converted') NOT NULL DEFAULT 'pending',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_vpr_po (po_id),
      KEY idx_vpr_vendor (vendor_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  // Backfill column if table existed without shipment_id
  try {
    $chk = $proc->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='vendor_pickup_requests' AND column_name='shipment_id'");
    $chk->execute();
    if (!$chk->fetchColumn()) {
      $proc->exec("ALTER TABLE vendor_pickup_requests ADD COLUMN shipment_id INT NULL AFTER vendor_id");
    }
  } catch (Throwable $e) { }

  $dup = $proc->prepare("SELECT id FROM vendor_pickup_requests WHERE po_id=? AND vendor_id=? LIMIT 1");
  $dup->execute([$poId, $vendorId]);
  if ($dup->fetchColumn()) throw new Exception('Pickup request already submitted for this PO');

  $ins = $proc->prepare("
    INSERT INTO vendor_pickup_requests
      (po_id, vendor_id, pickup_address, pickup_contact_name, pickup_contact_phone, notes)
    VALUES (?,?,?,?,?,?)
  ");
  $ins->execute([$poId, $vendorId, $pickupAddress, $pickupContact, $pickupPhone, $notes]);
  $reqId = (int)$proc->lastInsertId();

  // Create shipment in WMS so it appears in Track Shipments
  $vendorName = trim((string)($u['company_name'] ?? $u['name'] ?? 'Vendor'));
  $wms = db('wms');
  if (!$wms instanceof PDO) throw new Exception('WMS DB error');
  $wms->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $originCode = 'VEND-' . $vendorId;
  $originName = 'Vendor Pickup - ' . $vendorName;
  $locSt = $wms->prepare("SELECT id FROM warehouse_locations WHERE code=? LIMIT 1");
  $locSt->execute([$originCode]);
  $originId = (int)$locSt->fetchColumn();
  if ($originId <= 0) {
    $insLoc = $wms->prepare("INSERT INTO warehouse_locations (code, name, address) VALUES (?,?,?)");
    $insLoc->execute([$originCode, $originName, $pickupAddress]);
    $originId = (int)$wms->lastInsertId();
  } else {
    $updLoc = $wms->prepare("UPDATE warehouse_locations SET name=?, address=? WHERE id=?");
    $updLoc->execute([$originName, $pickupAddress, $originId]);
  }

  // Default destination = Main Warehouse (WH1 / Main Warehouse) else first location
  $destId = 0;
  $destQ = $wms->prepare("SELECT id FROM warehouse_locations WHERE code='WH1' OR name='Main Warehouse' ORDER BY id ASC LIMIT 1");
  $destQ->execute();
  $destId = (int)$destQ->fetchColumn();
  if ($destId <= 0) {
    $fallback = $wms->query("SELECT id FROM warehouse_locations ORDER BY id ASC LIMIT 1")->fetchColumn();
    $destId = (int)$fallback;
  }
  if ($destId <= 0) throw new Exception('No warehouse locations configured');

  $refNo = (string)($po['po_no'] ?? '');
  if ($refNo === '') throw new Exception('PO number missing');

  $dupShip = $wms->prepare("SELECT id FROM shipments WHERE ref_no=? LIMIT 1");
  $dupShip->execute([$refNo]);
  if ($dupShip->fetchColumn()) throw new Exception('Shipment already created for this PO');

  $fullNotesParts = ["PO: {$refNo}", "Pickup: {$pickupAddress}"];
  if ($notes !== '') $fullNotesParts[] = $notes;
  $fullNotes = implode("\n", $fullNotesParts);

  $insShip = $wms->prepare("INSERT INTO shipments
    (ref_no, origin_id, destination_id, status, carrier, contact_name, contact_phone, expected_pickup, expected_delivery, notes, created_by)
    VALUES (?,?,?,?,?,?,?,?,?,?,?)");
  $insShip->execute([
    $refNo,
    $originId,
    $destId,
    'Ready',
    '',
    $pickupContact,
    $pickupPhone,
    null,
    null,
    $fullNotes,
    (int)($_SESSION['user']['id'] ?? 0)
  ]);
  $shipId = (int)$wms->lastInsertId();
  $ev = $wms->prepare("INSERT INTO shipment_events (shipment_id, event_type, details, user_id) VALUES (?,?,?,?)");
  $ev->execute([$shipId, 'Ready', 'Vendor pickup request submitted', (int)($_SESSION['user']['id'] ?? 0)]);

  $upReq = $proc->prepare("UPDATE vendor_pickup_requests SET shipment_id=?, status='converted' WHERE id=?");
  $upReq->execute([$shipId, $reqId]);

  echo json_encode(['ok' => true, 'id' => $reqId, 'shipment_id' => $shipId, 'ref_no' => $refNo]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['error' => $e->getMessage()]);
}
