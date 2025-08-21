<?php
declare(strict_types=1);
$inc = __DIR__ . '/../../includes';
require_once $inc . '/config.php';
header('Content-Type: text/html; charset=utf-8');

function findInvite(PDO $pdo, string $raw) {
  $hash = hash('sha256', $raw);
  $sql = "SELECT rr.*, r.rfq_no, r.title, r.due_date, r.status
          FROM rfq_recipients rr
          JOIN rfqs r ON r.id = rr.rfq_id
          WHERE rr.invite_token = ?";
  $st = $pdo->prepare($sql); $st->execute([$hash]);
  $inv = $st->fetch(PDO::FETCH_ASSOC);
  if (!$inv) throw new Exception('Invalid link.');
  if ($inv['token_expires_at'] && strtotime($inv['token_expires_at']) < time()) throw new Exception('This link has expired.');
  if (in_array($inv['status'], ['awarded','cancelled'], true)) throw new Exception('This RFQ is closed.');
  return $inv;
}

function getRfqItems(PDO $pdo, int $rfq_id): array {
  // Try to resolve likely column names dynamically
  $cols = [];
  $st = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rfq_items'");
  $st->execute();
  foreach ($st->fetchAll(PDO::FETCH_COLUMN,0) as $c) $cols[$c]=true;

  $id  = 'id';
  $qty = isset($cols['qty']) ? 'qty' : (isset($cols['quantity'])?'quantity':'qty'); // may fail if none
  $descCandidates = ['description','item_description','name','item_name','spec','details','remarks'];
  $desc = null; foreach($descCandidates as $c){ if(isset($cols[$c])){ $desc=$c; break; } }
  if (!$desc) $desc = $id; // fallback

  // If qty column missing, treat qty as 1
  $qtySql = isset($cols[$qty]) ? $qty : '1';

  $sql = "SELECT $id AS id, $qtySql AS qty, $desc AS descr FROM rfq_items WHERE rfq_id = ?";
  $s = $pdo->prepare($sql); $s->execute([$rfq_id]);
  return $s->fetchAll(PDO::FETCH_ASSOC);
}

try {
  $raw = $_GET['t'] ?? '';
  if (!preg_match('/^[a-f0-9]{64}$/', $raw)) throw new Exception('Missing/invalid token.');
  $inv = findInvite($pdo, $raw);
  $items = getRfqItems($pdo, (int)$inv['rfq_id']);
} catch (Throwable $e) {
  http_response_code(400);
  echo "<h2>RFQ Quote Link</h2><p style='color:#b00'>".htmlspecialchars($e->getMessage())."</p>";
  exit;
}
?>
<!doctype html>
<html><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Submit Quote • <?= htmlspecialchars($inv['rfq_no']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
</head>
<body class="bg-light">
<div class="container my-4">
  <div class="card shadow-sm">
    <div class="card-body">
      <h3 class="mb-1">RFQ <?= htmlspecialchars($inv['rfq_no']) ?></h3>
      <div class="text-muted mb-3"><?= htmlspecialchars($inv['title'] ?? '') ?></div>
      <form method="post" action="submit_quote.php" enctype="multipart/form-data">
        <input type="hidden" name="t" value="<?= htmlspecialchars($raw) ?>">
        <div class="table-responsive mb-3">
          <table class="table table-sm align-middle">
            <thead><tr><th style="width:60%">Item</th><th class="text-end">Qty</th><th class="text-end">Unit Price</th></tr></thead>
            <tbody>
              <?php foreach ($items as $it): ?>
              <tr>
                <td><?= htmlspecialchars($it['descr'] ?: ('Item #'.$it['id'])) ?></td>
                <td class="text-end"><?= htmlspecialchars((string)$it['qty']) ?></td>
                <td>
                  <input type="number" step="0.0001" min="0" class="form-control form-control-sm text-end"
                         name="price[<?= (int)$it['id'] ?>]" placeholder="0.00">
                </td>
              </tr>
              <?php endforeach; if (!$items): ?>
              <tr><td colspan="3" class="text-muted">No RFQ items found.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="row g-3">
          <div class="col-12 col-md-4">
            <label class="form-label">Lead time (days)</label>
            <input type="number" min="0" class="form-control" name="lead_time_days" value="0">
          </div>
          <div class="col-12">
            <label class="form-label">Notes</label>
            <textarea class="form-control" name="notes" rows="2"></textarea>
          </div>
          <div class="col-12">
            <label class="form-label">Attachments (PDF/XLS/JPG/PNG)</label>
            <input type="file" class="form-control" name="files[]" multiple>
          </div>
        </div>

        <div class="mt-3 d-flex gap-2">
          <button class="btn btn-primary">Submit Quote</button>
          <a class="btn btn-outline-secondary" href="#">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>
</body></html>
