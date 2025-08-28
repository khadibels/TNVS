<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

function norm(?string $s): ?string {
  $s = is_string($s) ? trim($s) : '';
  return ($s === '') ? null : $s;
}
function norm_date(?string $s): ?string {
  $s = norm($s);
  if (!$s) return null;
  // accept YYYY-MM-DD only
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;
  return $s;
}

try {
  $id          = (int)($_POST['id'] ?? 0);
  $project_id  = ($_POST['project_id'] ?? '') === '' ? null : (int)$_POST['project_id'];
  $shipment_no = norm($_POST['shipment_no'] ?? '');
  $status      = strtolower(norm($_POST['status'] ?? '') ?? 'planned');

  $origin      = norm($_POST['origin'] ?? '');
  $destination = norm($_POST['destination'] ?? '');
  $schedule    = norm_date($_POST['schedule_date'] ?? '');
  $eta         = norm_date($_POST['eta_date'] ?? '');

  $vehicle     = norm($_POST['vehicle'] ?? '');
  $driver      = norm($_POST['driver'] ?? '');
  $notes       = norm($_POST['notes'] ?? '');

  $isDelivered = ($status === 'delivered');

  if ($id > 0) {
    // UPDATE
    $sql = "UPDATE plt_shipments
            SET project_id   = :project_id,
                shipment_no  = :shipment_no,
                status       = :status,
                origin       = :origin,
                destination  = :destination,
                schedule_date= :schedule_date,
                eta_date     = :eta_date,
                vehicle      = :vehicle,
                driver       = :driver,
                notes        = :notes,
                delivered_at = CASE
                                  WHEN :is_delivered = 1 THEN COALESCE(delivered_at, NOW())
                                  ELSE NULL
                                END
            WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
      ':id'            => $id,
      ':project_id'    => $project_id,
      ':shipment_no'   => $shipment_no,
      ':status'        => $status,
      ':origin'        => $origin,
      ':destination'   => $destination,
      ':schedule_date' => $schedule,
      ':eta_date'      => $eta,
      ':vehicle'       => $vehicle,
      ':driver'        => $driver,
      ':notes'         => $notes,
      ':is_delivered'  => $isDelivered ? 1 : 0,
    ]);

  } else {
    // INSERT
    $sql = "INSERT INTO plt_shipments
              (project_id, shipment_no, status, origin, destination,
               schedule_date, eta_date, vehicle, driver, notes, delivered_at)
            VALUES
              (:project_id, :shipment_no, :status, :origin, :destination,
               :schedule_date, :eta_date, :vehicle, :driver, :notes,
               CASE WHEN :is_delivered = 1 THEN NOW() ELSE NULL END)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
      ':project_id'    => $project_id,
      ':shipment_no'   => $shipment_no,
      ':status'        => $status,
      ':origin'        => $origin,
      ':destination'   => $destination,
      ':schedule_date' => $schedule,
      ':eta_date'      => $eta,
      ':vehicle'       => $vehicle,
      ':driver'        => $driver,
      ':notes'         => $notes,
      ':is_delivered'  => $isDelivered ? 1 : 0,
    ]);
    $id = (int)$pdo->lastInsertId();
  }

  echo json_encode(['ok' => true, 'id' => $id]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()]);
}
