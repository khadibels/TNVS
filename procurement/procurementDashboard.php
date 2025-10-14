<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

require_login();
require_role(['admin','procurement_officer']);

$section = 'procurement';
$active  = 'dashboard';
$BASE    = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';

$user     = current_user();
$userName = $user['name'] ?? 'User';
$userRole = $user['role'] ?? '';

$pdo = db('proc');
if (!$pdo instanceof PDO) { http_response_code(500); die('DB connection error'); }
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function table_exists(PDO $pdo, string $t): bool {
  try{ $st=$pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?"); $st->execute([$t]); return (bool)$st->fetchColumn(); }
  catch(Throwable){ return false; }
}
function fetch_val(PDO $pdo, string $sql, array $p=[], $fallback=0){
  try{ $st=$pdo->prepare($sql); $st->execute($p); $v=$st->fetchColumn(); return $v!==false?$v:$fallback; }
  catch(Throwable){ return $fallback; }
}

$hasRFQs   = table_exists($pdo,'rfqs');
$hasQuotes = table_exists($pdo,'quotes');
$hasPOS    = table_exists($pdo,'pos');
$hasVendors= table_exists($pdo,'vendors');
$hasAwards = table_exists($pdo,'rfq_item_awards');

$totalRFQs = $hasRFQs   ? (int)fetch_val($pdo, "SELECT COUNT(*) FROM rfqs") : 0;
$totalQts  = $hasQuotes ? (int)fetch_val($pdo, "SELECT COUNT(*) FROM quotes") : 0;
$totalPOs  = $hasPOS    ? (int)fetch_val($pdo, "SELECT COUNT(*) FROM pos") : 0;
$totalVen  = $hasVendors? (int)fetch_val($pdo, "SELECT COUNT(*) FROM vendors WHERE status='approved'") : 0;

$poBuckets = ['draft'=>0,'issued'=>0,'acknowledged'=>0,'closed'=>0,'cancelled'=>0];
if ($hasPOS){
  try{
    $rows = $pdo->query("SELECT LOWER(status) s, COUNT(*) c FROM pos GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $r){ $k=$r['s']; if(isset($poBuckets[$k])) $poBuckets[$k]=(int)$r['c']; }
  }catch(Throwable){}
}

$qLabels=[]; $qSeries=[];
$map=[]; $tz=new DateTimeZone('Asia/Manila'); $today=new DateTime('today',$tz);
for($i=29;$i>=0;$i--){ $d=(clone $today)->modify("-{$i} day"); $key=$d->format('Y-m-d'); $qLabels[]=$d->format('M j'); $map[$key]=0; }
if ($hasQuotes){
  try{
    $st=$pdo->query("SELECT DATE(created_at) d, COUNT(*) c FROM quotes
                     WHERE created_at>=DATE_SUB(CURDATE(), INTERVAL 29 DAY)
                     GROUP BY DATE(created_at)");
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){ $k=$r['d']; if(isset($map[$k])) $map[$k]=(int)$r['c']; }
  }catch(Throwable){}
}
$qSeries=array_values($map);

$rfqBuckets=['open'=>0,'awarded'=>0,'closed'=>0,'cancelled'=>0];
if ($hasRFQs){
  try{
    $rows=$pdo->query("SELECT CASE WHEN status='sent' THEN 'open' ELSE LOWER(status) END s, COUNT(*) c
                       FROM rfqs GROUP BY s")->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $r){ $k=$r['s']; if(isset($rfqBuckets[$k])) $rfqBuckets[$k]=(int)$r['c']; }
  }catch(Throwable){}
}

$awards=[];
if ($hasAwards){
  try{
    $sql="SELECT a.id, a.rfq_id, a.vendor_id, a.created_at,
                 r.rfq_no, r.title, v.company_name
          FROM rfq_item_awards a
          JOIN rfqs r ON r.id=a.rfq_id
          LEFT JOIN vendors v ON v.id=a.vendor_id
          ORDER BY a.created_at DESC LIMIT 8";
    $awards=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
  }catch(Throwable){}
}
if (!$awards && $hasRFQs){
  try{
    $sql="SELECT r.id rfq_id, r.rfq_no, r.title, r.updated_at created_at, v.company_name
          FROM rfqs r LEFT JOIN vendors v ON v.id=r.awarded_vendor_id
          WHERE r.status='awarded' ORDER BY r.updated_at DESC LIMIT 8";
    $awards=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
  }catch(Throwable){}
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Procurement — Dashboard</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
<link href="<?= $BASE ?>/css/style.css" rel="stylesheet" />
<link href="<?= $BASE ?>/css/modules.css" rel="stylesheet" />
<script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
<script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
<script src="<?= $BASE ?>/js/sidebar-toggle.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<style>
  body{background:#f6f7fb}
  .main-content{padding:1.25rem} @media(min-width:992px){.main-content{padding:2rem}}
  .card{border-radius:16px}
  .kpi-card .icon-wrap{width:42px;height:42px;display:flex;align-items:center;justify-content:center;border-radius:12px}
  .chart-card canvas{width:100%!important;height:320px!important}
</style>
</head>
<body>
<div class="container-fluid p-0">
  <div class="row g-0">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="col main-content">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-3">
          <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2" aria-label="Toggle sidebar">
            <ion-icon name="menu-outline"></ion-icon>
          </button>
          <h2 class="m-0">Dashboard</h2>
        </div>
         <div class="d-flex align-items-center gap-2">
          <img src="../img/profile.jpg" class="rounded-circle" width="36" height="36" alt="">
          <div class="small">
            <strong><?= h($userName) ?></strong><br/>
            <span class="text-muted"><?= h($userRole) ?></span>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
          <div class="card shadow-sm kpi-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
              <div class="icon-wrap bg-primary-subtle"><ion-icon name="document-text-outline" style="font-size:20px"></ion-icon></div>
              <div><div class="text-muted small">Total RFQs</div><div class="h4 m-0"><?= number_format($totalRFQs) ?></div></div>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="card shadow-sm kpi-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
              <div class="icon-wrap bg-success-subtle"><ion-icon name="pricetags-outline" style="font-size:20px"></ion-icon></div>
              <div><div class="text-muted small">Quotes Received</div><div class="h4 m-0"><?= number_format($totalQts) ?></div></div>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="card shadow-sm kpi-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
              <div class="icon-wrap bg-info-subtle"><ion-icon name="file-tray-full-outline" style="font-size:20px"></ion-icon></div>
              <div><div class="text-muted small">Purchase Orders</div><div class="h4 m-0"><?= number_format($totalPOs) ?></div></div>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="card shadow-sm kpi-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
              <div class="icon-wrap bg-warning-subtle"><ion-icon name="people-outline" style="font-size:20px"></ion-icon></div>
              <div><div class="text-muted small">Approved Vendors</div><div class="h4 m-0"><?= number_format($totalVen) ?></div></div>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-12 col-lg-6">
          <section class="card shadow-sm chart-card h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h5 class="card-title m-0">POs by status</h5>
                <ion-icon name="bar-chart-outline"></ion-icon>
              </div>
              <canvas id="poChart"></canvas>
            </div>
          </section>
        </div>

        <div class="col-12 col-lg-6">
          <section class="card shadow-sm chart-card h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h5 class="card-title m-0">Quotes received (last 30 days)</h5>
                <ion-icon name="trending-up-outline"></ion-icon>
              </div>
              <canvas id="quotesChart"></canvas>
            </div>
          </section>
        </div>
      </div>

      <div class="row g-3">
        <div class="col-12 col-lg-6">
          <section class="card shadow-sm chart-card h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h5 class="card-title m-0">RFQs by status</h5>
                <ion-icon name="stats-chart-outline"></ion-icon>
              </div>
              <canvas id="rfqChart"></canvas>
            </div>
          </section>
        </div>

        <div class="col-12 col-lg-6">
          <section class="card shadow-sm h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h5 class="m-0"><ion-icon name="trophy-outline" class="me-2"></ion-icon>Recent awards</h5>
                <a class="small" href="<?= $BASE ?>/procurement/quoteEvaluation.php">Go to evaluation</a>
              </div>
              <?php if (empty($awards)): ?>
                <div class="text-center text-muted py-4">No awards yet.</div>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th>RFQ No</th><th>Title</th><th>Vendor</th><th>When</th></tr></thead>
                    <tbody>
                      <?php foreach ($awards as $a): ?>
                        <tr>
                          <td class="fw-semibold"><?= h($a['rfq_no'] ?? ('RFQ#'.$a['rfq_id'])) ?></td>
                          <td><?= h($a['title'] ?? '') ?></td>
                          <td><?= h($a['company_name'] ?? '-') ?></td>
                          <td><?= isset($a['created_at']) ? date('M d, Y g:i A', strtotime($a['created_at'])) : '-' ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </section>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
  const poSeries = <?= json_encode(array_values($poBuckets)) ?>;
  const qLabels  = <?= json_encode($qLabels) ?>;
  const qSeries  = <?= json_encode($qSeries) ?>;
  const rfqSeries= <?= json_encode(array_values($rfqBuckets)) ?>;

  const palette = {
    blue:'#3b82f6',
    blueLight:'rgba(59,130,246,.25)',
    pink:'#f472b6',
    grid:'rgba(0,0,0,.06)'
  };

  Chart.defaults.font.family = getComputedStyle(document.body).fontFamily || 'system-ui';
  Chart.defaults.color = getComputedStyle(document.body).color || '#222';
  Chart.defaults.elements.bar.borderRadius = 8;

  const gridOpts = { grid:{ color:palette.grid, drawBorder:false } };

  new Chart(document.getElementById('poChart'), {
    type:'bar',
    data:{ labels:['draft','issued','acknowledged','closed','cancelled'],
      datasets:[{ data:poSeries, backgroundColor:palette.blueLight, borderColor:palette.blue, borderWidth:1 }] },
    options:{ maintainAspectRatio:false, scales:{ y:{ beginAtZero:true, ticks:{precision:0}, ...gridOpts }, x:{ ...gridOpts } },
      plugins:{ legend:{ display:false } } }
  });

  new Chart(document.getElementById('quotesChart'), {
    type:'line',
    data:{ labels:qLabels,
      datasets:[{ data:qSeries, borderColor:palette.blue, backgroundColor:palette.blueLight, fill:true, tension:.35, pointRadius:0, borderWidth:2 }] },
    options:{ maintainAspectRatio:false, scales:{ y:{ beginAtZero:true, ticks:{precision:0}, ...gridOpts }, x:{ ...gridOpts } },
      plugins:{ legend:{ display:false }, tooltip:{ mode:'index', intersect:false } } }
  });

  new Chart(document.getElementById('rfqChart'), {
    type:'bar',
    data:{ labels:['open','awarded','closed','cancelled'],
      datasets:[{ data:rfqSeries, backgroundColor:palette.blueLight, borderColor:palette.blue, borderWidth:1 }] },
    options:{ maintainAspectRatio:false, scales:{ y:{ beginAtZero:true, ticks:{precision:0}, ...gridOpts }, x:{ ...gridOpts } },
      plugins:{ legend:{ display:false } } }
  });
</script>
</body>
</html>
