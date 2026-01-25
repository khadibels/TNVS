<?php
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/vendor_capability.php';

header('Content-Type: application/json; charset=utf-8');

try {
  require_login();
  require_role(['admin','vendor_manager']);
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('POST required');

  $docId = (int)($_POST['doc_id'] ?? 0);
  $status = trim((string)($_POST['status'] ?? ''));
  $note = substr(trim((string)($_POST['note'] ?? '')),0,255);
  if ($docId <= 0) throw new Exception('Invalid document');
  if (!in_array($status, ['approved','rejected'], true)) throw new Exception('Invalid status');

  $pdo = db('proc'); if (!$pdo instanceof PDO) throw new Exception('DB error');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  ensure_vendor_capability_tables($pdo);

  $doc = $pdo->prepare("SELECT vendor_id FROM vendor_documents WHERE id=?");
  $doc->execute([$docId]);
  $vendorId = (int)$doc->fetchColumn();
  if ($vendorId <= 0) throw new Exception('Document not found');

  $up = $pdo->prepare("
    UPDATE vendor_documents
       SET status=?, review_note=?, reviewed_at=NOW()
     WHERE id=?
  ");
  $up->execute([$status, $note ?: null, $docId]);

  $cats = get_all_categories($pdo);
  recompute_vendor_capability($pdo, $vendorId, $cats);

  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['error'=>$e->getMessage()]);
}
