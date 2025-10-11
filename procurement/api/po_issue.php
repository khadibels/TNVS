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

  echo json_encode(['ok'=>true,'message'=>'PO issued']);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['error'=>$e->getMessage()]);
}
