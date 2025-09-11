<?php
require_once __DIR__ . "/../../includes/config.php";
require_once __DIR__ . "/../../includes/auth.php";
require_once __DIR__ . "/../../includes/db.php";

require_role(['admin','procurement_officer'], 'json');

header('Content-Type: application/json; charset=utf-8');

$pdo = db('proc') ?: db('wms');
if (!$pdo instanceof PDO) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'err'=>'DB not available']);
  exit;
}

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Submit Quote</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
</head>
<body class="bg-light">
<div class="container py-4">
  <div class="mx-auto" style="max-width:720px">
    <div class="card shadow-sm">
      <div class="card-body">
        <h4 class="mb-3">Submit Quote</h4>
        <div id="info" class="mb-3 text-muted small">Loading RFQ…</div>
        <form id="qForm" class="d-none">
          <input type="hidden" id="token" name="token">
          <div class="row g-3">
            <div class="col-12 col-md-6">
              <label class="form-label">Total Amount</label>
              <input type="number" step="0.01" min="0" class="form-control" id="total_amount" name="total_amount" required>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Lead Time (days)</label>
              <input type="number" min="0" class="form-control" id="lead_time_days" name="lead_time_days" required>
            </div>
            <div class="col-12">
              <label class="form-label">Notes (optional)</label>
              <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
            </div>
            <div class="col-12">
              <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" value="0" id="asDraft">
                <label class="form-check-label" for="asDraft">
                  Save as draft (you can submit final later)
                </label>
              </div>
            </div>
            <div class="col-12 d-flex gap-2">
              <button class="btn btn-primary" type="submit">Submit</button>
              <div id="err" class="text-danger small d-none"></div>
            </div>
          </div>
        </form>
      </div>
    </div>
    <div class="text-center text-muted small mt-3">
      ViaHale Procurement • RFQ portal
    </div>
  </div>
</div>
<script>
const $ = s=>document.querySelector(s);
function parseErr(e){ try{const j=JSON.parse(e.message); return j.error||e.message; }catch(_){ return e.message||'Request failed'; } }

(async function init(){
  const params = new URLSearchParams(location.search);
  const token = params.get('token')||'';
  if(!token){ $('#info').textContent='Missing token.'; return; }
  $('#token').value = token;

  try{
    const res = await fetch('../api/quote_info.php?token='+encodeURIComponent(token));
    if(!res.ok) throw new Error(await res.text());
    const j = await res.json();
    $('#info').innerHTML = `
      <div><strong>RFQ:</strong> ${j.rfq.rfq_no} — ${j.rfq.title}</div>
      <div><strong>Supplier:</strong> ${j.supplier.code} — ${j.supplier.name}</div>
      <div><strong>Due date:</strong> ${j.rfq.due_date||'-'}</div>
    `;
    if (j.quote) {
      $('#total_amount').value = j.quote.total_amount ?? '';
      $('#lead_time_days').value = j.quote.lead_time_days ?? '';
      $('#notes').value = j.quote.notes ?? '';
    }
    $('#qForm').classList.remove('d-none');
  }catch(e){
    $('#info').classList.remove('text-muted');
    $('#info').classList.add('text-danger');
    $('#info').textContent = parseErr(e);
  }
})();

$('#qForm')?.addEventListener('submit', async (ev)=>{
  ev.preventDefault();
  $('#err').classList.add('d-none');
  try{
    const fd = new FormData(ev.target);
    fd.set('is_final', $('#asDraft').checked ? '0' : '1');
    const res = await fetch('../api/quote_submit.php', { method:'POST', body:fd });
    if(!res.ok) throw new Error(await res.text());
    const j = await res.json();
    alert(j.final ? 'Quote submitted!' : 'Draft saved!');
    if (j.final) location.reload();
  }catch(e){
    const msg = parseErr(e);
    $('#err').textContent = msg; $('#err').classList.remove('d-none');
  }
});
</script>
</body>
</html>
