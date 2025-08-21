<?php

declare(strict_types=1);

$token = trim($_GET['token'] ?? '');
$err = '';
$info = null;

// Build absolute BASE
$base = '';
if (defined('BASE_URL') && BASE_URL) {
  $base = rtrim((string)BASE_URL, '/');
} else {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $prefix = rtrim(dirname(dirname($_SERVER['REQUEST_URI'] ?? '/procurement/supplier/quote.php')), '/');
  $base   = $scheme.'://'.$host.$prefix;
}

function http_get_json(string $url): ?array {
  // try file_get_contents
  $json = @file_get_contents($url);
  if ($json !== false) {
    $data = json_decode($json, true);
    if (is_array($data)) return $data;
  }
  // fallback to cURL
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_TIMEOUT => 10,
    ]);
    $out = curl_exec($ch);
    curl_close($ch);
    if ($out !== false) {
      $data = json_decode($out, true);
      if (is_array($data)) return $data;
    }
  }
  return null;
}

if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
  $err = 'Missing or invalid token';
} else {
  $apiUrl = $base . '/api/quote_info.php?token=' . urlencode($token);

  $info = http_get_json($apiUrl);
  if (!$info || isset($info['error'])) {
    $err = $info['error'] ?? 'Invalid token';
  }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Submit Quote</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
</head>
<body class="bg-light">
<div class="container py-4">
  <div class="card">
    <div class="card-body">
      <h5 class="card-title">Submit Quote</h5>

      <?php if ($err): ?>
        <div class="alert alert-danger mb-3"><?= htmlspecialchars($err) ?></div>
        <div class="small text-muted">
          If this keeps showing, try opening:
          <code><?= htmlspecialchars(($base . '/procurement/api/quote_info.php?token=' . $token)) ?></code>
          directly in your browser to see the raw API response.
        </div>
      <?php else: ?>
        <div class="mb-3 small text-muted">
          <div><strong>RFQ:</strong> <?= htmlspecialchars(($info['rfq']['rfq_no'] ?? '') . ' — ' . ($info['rfq']['title'] ?? '')) ?></div>
          <div><strong>Supplier:</strong> <?= htmlspecialchars($info['supplier']['name'] ?? '') ?></div>
          <?php if (!empty($info['rfq']['due_date'])): ?>
            <div><strong>Due:</strong> <?= htmlspecialchars($info['rfq']['due_date']) ?></div>
          <?php endif; ?>
        </div>

        <form id="qForm">
          <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Total Amount</label>
              <input type="number" step="0.01" min="0" class="form-control" name="total_amount" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Lead Time (days)</label>
              <input type="number" min="0" class="form-control" name="lead_time_days" required>
            </div>
            <div class="col-12">
              <label class="form-label">Notes</label>
              <textarea class="form-control" name="notes" rows="2"></textarea>
            </div>
          </div>
          <div class="mt-3 d-flex gap-2 align-items-center">
            <button class="btn btn-primary" type="submit">Submit Quote</button>
            <span id="msg" class="text-success"></span>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
async function post(url, data){
  const res = await fetch(url, { method:'POST', body:data });
  const txt = await res.text();
  if (!res.ok) throw new Error(txt||res.statusText);
  try { return JSON.parse(txt||'{}'); } catch { return {}; }
}
document.getElementById('qForm')?.addEventListener('submit', async (e)=>{
  e.preventDefault();
  const fd = new FormData(e.target);
  const btn = e.target.querySelector('button[type="submit"]');
  const msg = document.getElementById('msg');
  btn.disabled = true; msg.textContent = '';
  try {
    await post('../api/quote_submit.php', fd);
    msg.textContent = 'Quote submitted. Thank you!';
    e.target.reset();
  } catch (err) {
    alert(err.message);
  } finally {
    btn.disabled = false;
  }
});
</script>
</body>
</html>
