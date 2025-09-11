<?php
// Public endpoint (token based) – no auth/session required
declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = db('proc');
if (!$pdo instanceof PDO) {
  http_response_code(500);
  echo json_encode(['error' => 'DB not available']);
  exit;
}

$token = trim($_POST['token'] ?? '');
$total = isset($_POST['total_amount']) ? (float)$_POST['total_amount'] : null;
$lead  = isset($_POST['lead_time_days']) ? (int)$_POST['lead_time_days'] : null;
$notes = trim($_POST['notes'] ?? '');

if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid token']);
  exit;
}
if ($total === null || $total < 0) {
  http_response_code(400);
  echo json_encode(['error' => 'Total amount is required']);
  exit;
}
if ($lead === null || $lead < 0) {
  http_response_code(400);
  echo json_encode(['error' => 'Lead time is required']);
  exit;
}

// Validate token → get rfq & supplier
$st = $pdo->prepare("SELECT rfq_id, supplier_id
                     FROM rfq_recipients
                     WHERE invite_token=? LIMIT 1");
$st->execute([$token]);
$rec = $st->fetch(PDO::FETCH_ASSOC);
if (!$rec) {
  http_response_code(404);
  echo json_encode(['error' => 'Token not found or expired']);
  exit;
}

$rfqId = (int)$rec['rfq_id'];
$supId = (int)$rec['supplier_id'];

// Optional: ensure RFQ is still open for quotes (not awarded/closed/cancelled)
$st = $pdo->prepare("SELECT status, due_date FROM rfqs WHERE id=?");
$st->execute([$rfqId]);
$rfq = $st->fetch(PDO::FETCH_ASSOC);
if ($rfq) {
  $status = strtolower($rfq['status'] ?? '');
  if (in_array($status, ['awarded','closed','cancelled'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'RFQ is not accepting quotes']);
    exit;
  }
}

$pdo->beginTransaction();
try {
  // If a quote already exists for (rfq_id, supplier_id), update it; else insert.
  $st = $pdo->prepare("SELECT id FROM quotes WHERE rfq_id=? AND supplier_id=? LIMIT 1");
  $st->execute([$rfqId, $supId]);
  $existingId = (int)($st->fetchColumn() ?: 0);

  if ($existingId) {
    $u = $pdo->prepare("UPDATE quotes
                        SET total=?, lead_time_days=?, notes=?, submitted_at=NOW()
                        WHERE id=?");
    $u->execute([$total, $lead, $notes, $existingId]);
    $quoteId = $existingId;
  } else {
    $i = $pdo->prepare("INSERT INTO quotes
                        (rfq_id, supplier_id, total, lead_time_days, notes, submitted_at)
                        VALUES (?,?,?,?,?,NOW())");
    $i->execute([$rfqId, $supId, $total, $lead, $notes]);
    $quoteId = (int)$pdo->lastInsertId();
  }

  $pdo->commit();
  echo json_encode(['ok' => true, 'quote_id' => $quoteId]);
} catch (Throwable $e) {
  $pdo->rollBack();
  http_response_code(500);
  if (defined('APP_DEBUG') && APP_DEBUG) {
    echo json_encode(['error' => 'Save failed', 'detail' => $e->getMessage()]);
  } else {
    echo json_encode(['error' => 'Save failed']);
  }
}
