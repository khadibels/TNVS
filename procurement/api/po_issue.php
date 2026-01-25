<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

try {
  require_login(); require_role(['admin','procurement_officer']);
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('POST required');

  $id    = (int)($_POST['id'] ?? 0);
  $terms = trim((string)($_POST['terms'] ?? ''));
  $notes = trim((string)($_POST['notes'] ?? ''));
  if ($id <= 0) throw new Exception('Invalid id');

  $pdo = db('proc'); if(!$pdo instanceof PDO) throw new Exception('DB error');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $st = $pdo->prepare("SELECT status FROM pos WHERE id=?");
  $st->execute([$id]);
  $status = $st->fetchColumn();
  if (!$status) throw new Exception('PO not found');
  if (strtolower($status) !== 'draft') throw new Exception('PO is not in draft');

  $hasItems = $pdo->prepare("SELECT COUNT(*) FROM po_items WHERE po_id=?");
  $hasItems->execute([$id]);
  if (!(int)$hasItems->fetchColumn()) throw new Exception('PO has no items');

  $up = $pdo->prepare("
    UPDATE pos
       SET status='issued',
           issued_at=NOW(),
           terms=?,
           notes=?,
           updated_at=NOW()
     WHERE id=? AND status='draft'
  ");
  $up->execute([$terms, $notes, $id]);
  if ($up->rowCount() === 0) throw new Exception('Nothing updated');

  // Notify Smart Warehousing about incoming delivery
  try {
    $poInfo = $pdo->prepare("SELECT po_no, vendor_id FROM pos WHERE id=?");
    $poInfo->execute([$id]);
    $po = $poInfo->fetch(PDO::FETCH_ASSOC);

    $vendorName = 'Vendor';
    if (!empty($po['vendor_id'])) {
      $vn = $pdo->prepare("SELECT company_name FROM vendors WHERE id=?");
      $vn->execute([(int)$po['vendor_id']]);
      $vendorName = $vn->fetchColumn() ?: $vendorName;
    }

    $wms = db('wms');
    if ($wms instanceof PDO) {
      $wms->exec("
        CREATE TABLE IF NOT EXISTS warehouse_notifications (
          id INT AUTO_INCREMENT PRIMARY KEY,
          title VARCHAR(255) NOT NULL,
          body TEXT NOT NULL,
          status VARCHAR(32) NOT NULL DEFAULT 'unread',
          ref_type VARCHAR(50) NULL,
          ref_id INT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
      ");

      $title = 'Incoming Delivery (PO ' . ($po['po_no'] ?? '') . ')';
      $body = 'A purchase order has been issued to ' . $vendorName . '. Prepare for incoming delivery.';
      $ins = $wms->prepare("INSERT INTO warehouse_notifications (title, body, ref_type, ref_id) VALUES (?,?,?,?)");
      $ins->execute([$title, $body, 'po', $id]);
    }
  } catch (Throwable $e) { }

  echo json_encode(['ok'=>true,'message'=>'PO issued']);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['error'=>$e->getMessage()]);
}
