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

$token = trim($_GET['token'] ?? '');
if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid token']);
  exit;
}

// Find recipient by token
$sql = "SELECT rr.id AS recipient_id, rr.rfq_id, rr.supplier_id, rr.email
        FROM rfq_recipients rr
        WHERE rr.invite_token = ? LIMIT 1";
$st = $pdo->prepare($sql);
$st->execute([$token]);
$rec = $st->fetch(PDO::FETCH_ASSOC);

if (!$rec) {
  http_response_code(404);
  echo json_encode(['error' => 'Token not found or expired']);
  exit;
}

// RFQ
$rfq = null;
$st = $pdo->prepare("SELECT id, rfq_no, title, status, DATE_FORMAT(due_date,'%Y-%m-%d') AS due_date
                     FROM rfqs WHERE id=?");
$st->execute([$rec['rfq_id']]);
$rfq = $st->fetch(PDO::FETCH_ASSOC);

// Supplier
$sup = null;
$st = $pdo->prepare("SELECT id, code, name, email, phone, rating
                     FROM suppliers WHERE id=?");
$st->execute([$rec['supplier_id']]);
$sup = $st->fetch(PDO::FETCH_ASSOC);

// Any existing quote from this supplier for this RFQ
$quote = null;
$st = $pdo->prepare("SELECT id, rfq_id, supplier_id,
                            total AS total_amount, lead_time_days, rating, notes,
                            DATE_FORMAT(submitted_at,'%Y-%m-%d %H:%i:%s') AS submitted_at
                     FROM quotes
                     WHERE rfq_id=? AND supplier_id=?
                     ORDER BY id DESC LIMIT 1");
$st->execute([$rec['rfq_id'], $rec['supplier_id']]);
$quote = $st->fetch(PDO::FETCH_ASSOC) ?: null;

echo json_encode([
  'ok' => true,
  'rfq' => $rfq ?: new stdClass(),
  'supplier' => $sup ?: new stdClass(),
  'recipient' => [
    'id' => (int)$rec['recipient_id'],
    'email' => $rec['email'],
  ],
  'quote' => $quote,
]);
