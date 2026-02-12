<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_login();

$section = 'vendor';
$active  = 'dashboard';

$user       = current_user();
$VENDOR_ID  = (int)($user['vendor_id'] ?? 0);
$vendorName = $user['company_name'] ?? ($user['name'] ?? 'Vendor');
$BASE       = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';

if ($VENDOR_ID <= 0) { http_response_code(403); die('No vendor context'); }

function vendor_avatar_url(): string {
  $base = rtrim(BASE_URL, '/');
  $id   = (int)($_SESSION['user']['vendor_id'] ?? 0);
  if ($id <= 0) return $base . '/img/profile.jpg';
  $root = realpath(__DIR__ . '/../../');
  $uploadDir = $root . "/vendor_portal/vendor/uploads";
  foreach (['jpg','jpeg','png','webp'] as $ext) {
    $files = glob($uploadDir . "/vendor_{$id}_*.{$ext}");
    if ($files && file_exists($files[0])) {
      $rel = str_replace($root, '', $files[0]);
      return $base . $rel;
    }
  }
  return $base . '/img/profile.jpg';
}

/* ---------- DB helpers ---------- */
$pdo = db('proc');
if (!$pdo instanceof PDO) { http_response_code(500); die('DB connection error'); }
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function table_exists(PDO $pdo, string $name): bool {
  try{
    $st=$pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $st->execute([$name]); return (bool)$st->fetchColumn();
  }catch(Throwable){ return false; }
}
function fetch_val(PDO $pdo, string $sql, array $p=[], $fallback=0){
  try{ $st=$pdo->prepare($sql); $st->execute($p); $v=$st->fetchColumn(); return $v!==false?$v:$fallback; }catch(Throwable){ return $fallback; }
}

$hasQuotes = table_exists($pdo,'quotes');
$hasRFQs   = table_exists($pdo,'rfqs');
$hasPOS    = table_exists($pdo,'pos');
$hasAwards = table_exists($pdo,'rfq_item_awards');

/* ---------- Chart: PO responses ---------- */
$poBuckets = ['pending'=>0,'acknowledged'=>0,'accepted'=>0,'declined'=>0];
if ($hasPOS){
  $st=$pdo->prepare("
    SELECT COALESCE(vendor_ack_status,'pending') s, COUNT(*) c
    FROM pos
    WHERE vendor_id=? AND status IN ('issued','acknowledged','closed','cancelled')
    GROUP BY COALESCE(vendor_ack_status,'pending')
  ");
  try{
    $st->execute([$VENDOR_ID]);
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){ $poBuckets[strtolower($r['s'])] = (int)$r['c']; }
  }catch(Throwable){}
}

/* ---------- Chart: Quotes submitted ---------- */
$quoteLabels = [];
$quoteSeries = [];
// prepare day buckets
$map=[]; $tz = new DateTimeZone('Asia/Manila'); $today = new DateTime('today', $tz);
for($i=29;$i>=0;$i--){
  $d = (clone $today)->modify("-{$i} day");
  $key = $d->format('Y-m-d');
  $quoteLabels[] = $d->format('M j');
  $map[$key]=0;
}
if ($hasQuotes){
  $st=$pdo->prepare("
    SELECT DATE(created_at) d, COUNT(*) c
    FROM quotes
    WHERE vendor_id=? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
    GROUP BY DATE(created_at)
  ");
  try{
    $st->execute([$VENDOR_ID]);
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){
      $k=$r['d']; if(isset($map[$k])) $map[$k]=(int)$r['c'];
    }
  }catch(Throwable){}
}
$quoteSeries = array_values($map);

/* ---------- Chart: RFQs by status ---------- */
$rfqStatusBuckets = ['open'=>0,'awarded'=>0,'closed'=>0,'cancelled'=>0];
if ($hasRFQs){
  $invitedJoin = table_exists($pdo,'rfq_suppliers') ? " OR r.id IN (SELECT rfq_id FROM rfq_suppliers WHERE vendor_id=? ) " : "";
  $sql = "
    SELECT CASE WHEN r.status='sent' THEN 'open' ELSE r.status END s, COUNT(*) c
    FROM rfqs r
    WHERE (r.id IN (SELECT DISTINCT rfq_id FROM quotes WHERE vendor_id=? ) {$invitedJoin})
    GROUP BY s
  ";
  try{
    $params = [$VENDOR_ID]; if($invitedJoin) $params[] = $VENDOR_ID;
    $st=$pdo->prepare($sql); $st->execute($params);
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){ $rfqStatusBuckets[strtolower($r['s'])]=(int)$r['c']; }
  }catch(Throwable){}
}

/* ---------- Right column: Awards to me ---------- */
$awards = [];
if ($hasAwards){
  // Prefer item-level awards table
  $sql = "
    SELECT a.id, a.rfq_id, a.vendor_id, a.created_at,
           r.rfq_no, r.title, r.currency
    FROM rfq_item_awards a
    JOIN rfqs r ON r.id=a.rfq_id
    WHERE a.vendor_id=?
    ORDER BY a.created_at DESC
    LIMIT 8
  ";
  try{ $st=$pdo->prepare($sql); $st->execute([$VENDOR_ID]); $awards=$st->fetchAll(PDO::FETCH_ASSOC); }catch(Throwable){}
}
if (!$awards && $hasRFQs && $hasQuotes){
  $sql="
    SELECT r.id rfq_id, r.rfq_no, r.title, r.updated_at created_at, r.currency
    FROM rfqs r
    WHERE r.status='awarded' AND EXISTS(SELECT 1 FROM quotes q WHERE q.vendor_id=? AND q.rfq_id=r.id)
    ORDER BY r.updated_at DESC
    LIMIT 8
  ";
  try{ $st=$pdo->prepare($sql); $st->execute([$VENDOR_ID]); $awards=$st->fetchAll(PDO::FETCH_ASSOC); }catch(Throwable){}
}
if (!$awards && $hasPOS){
  $sql="
    SELECT id rfq_id, po_no rfq_no, CONCAT('PO • ',po_no) title, issued_at created_at, currency, total
    FROM pos
    WHERE vendor_id=? AND status IN ('issued','acknowledged','closed','cancelled')
    ORDER BY issued_at DESC
    LIMIT 8
  ";
  try{ $st=$pdo->prepare($sql); $st->execute([$VENDOR_ID]); $awards=$st->fetchAll(PDO::FETCH_ASSOC); }catch(Throwable){}
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Vendor Portal — Dashboard</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
<link href="<?= $BASE ?>/css/style.css" rel="stylesheet" />
<link href="<?= $BASE ?>/css/modules.css" rel="stylesheet" />
<link href="<?= $BASE ?>/css/vendor_portal_saas.css" rel="stylesheet" />
<script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
<script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
<script src="<?= $BASE ?>/js/sidebar-toggle.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<style>
  body{background:#f6f7fb}
  .main-content{padding:1.25rem} @media(min-width:992px){.main-content{padding:2rem}}
  .card{border-radius:16px}
  .chart-card canvas{width:100%!important;height:280px!important}
  .mini-legend .badge{font-weight:500}
</style>
</head>
<body class="vendor-saas">
<div class="container-fluid p-0">
  <div class="row g-0">
    <?php include __DIR__ . '/../../includes/sidebar.php'; ?>
    <div class="col main-content">
      <!-- Topbar -->
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-3">
          <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2" aria-label="Toggle sidebar">
            <ion-icon name="menu-outline"></ion-icon>
          </button>
          <h2 class="m-0 d-flex align-items-center gap-2 page-title">
            <ion-icon name="home-outline"></ion-icon> Dashboard
          </h2>
        </div>
        <div class="profile-menu" data-profile-menu>
          <button class="profile-trigger" type="button" data-profile-trigger>
            <img src="<?= htmlspecialchars(vendor_avatar_url(), ENT_QUOTES) ?>" class="rounded-circle" width="36" height="36" alt="">
            <div class="profile-text">
              <div class="profile-name"><?= htmlspecialchars($vendorName, ENT_QUOTES) ?></div>
              <div class="profile-role">vendor</div>
            </div>
            <ion-icon class="profile-caret" name="chevron-down-outline"></ion-icon>
          </button>
          <div class="profile-dropdown" data-profile-dropdown role="menu">
            <a href="<?= $BASE ?>/vendor_portal/vendor/notifications.php" role="menuitem">Notifications</a>
            <a href="<?= u('auth/logout.php') ?>" role="menuitem">Sign out</a>
          </div>
        </div>
      </div>

      <div class="row g-3">
        <!-- PO responses -->
        <div class="col-12 col-lg-6">
          <div class="card shadow-sm chart-card h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h5 class="m-0">PO responses (issued)</h5>
                <ion-icon name="copy-outline"></ion-icon>
              </div>
              <canvas id="poChart"></canvas>
              <div class="mini-legend mt-2 small">
                <span class="badge bg-primary-subtle text-dark me-2">pending: <?= (int)$poBuckets['pending'] ?></span>
                <span class="badge bg-info text-dark me-2">acknowledged: <?= (int)$poBuckets['acknowledged'] ?></span>
                <span class="badge bg-success-subtle text-dark me-2">accepted: <?= (int)$poBuckets['accepted'] ?></span>
                <span class="badge bg-warning-subtle text-dark">declined: <?= (int)$poBuckets['declined'] ?></span>
              </div>
            </div>
          </div>
        </div>

        <!-- Quotes submitted (last 30 days) -->
        <div class="col-12 col-lg-6">
          <div class="card shadow-sm chart-card h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h5 class="m-0">Quotes submitted (last 30 days)</h5>
                <ion-icon name="trending-up-outline"></ion-icon>
              </div>
              <canvas id="quotesChart"></canvas>
            </div>
          </div>
        </div>

        <!-- RFQs by status -->
        <div class="col-12 col-lg-6">
          <div class="card shadow-sm chart-card h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h5 class="m-0">RFQs by status</h5>
                <ion-icon name="stats-chart-outline"></ion-icon>
              </div>
              <canvas id="rfqChart"></canvas>
            </div>
          </div>
        </div>

        <!-- Awards to me  -->
        <div class="col-12 col-lg-6">
          <div class="card shadow-sm h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h5 class="m-0"><ion-icon name="trophy-outline" class="me-2"></ion-icon>Awards to me</h5>
                <a class="small" href="<?= $BASE ?>/vendor_portal/vendor/my_quotes.php">See all</a>
              </div>
              <?php if (!$awards): ?>
                <div class="text-center text-muted py-4">No awards yet.</div>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                      <tr><th>Ref</th><th>Title</th><th>When</th><th class="text-end">Action</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($awards as $a): ?>
                      <tr>
                        <td class="fw-semibold"><?= htmlspecialchars($a['rfq_no'] ?? ('RFQ#'.$a['rfq_id'])) ?></td>
                        <td><?= htmlspecialchars($a['title'] ?? '') ?></td>
                        <td><?= htmlspecialchars($a['created_at'] ? date('m/d/Y, g:i A', strtotime($a['created_at'])) : '-') ?></td>
                        <td class="text-end">
                          <a href="<?= $BASE ?>/vendor_portal/vendor/my_quotes.php" class="btn btn-sm btn-outline-secondary">
                            <ion-icon name="open-outline"></ion-icon> View
                          </a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

      </div><!-- /row -->
    </div><!-- /main -->
  </div>
</div>

<script>
  // Data from PHP
  const poData = <?= json_encode(array_values($poBuckets)) ?>; 
  const quotesLabels = <?= json_encode($quoteLabels) ?>;
  const quotesSeries = <?= json_encode($quoteSeries) ?>;
  const rfqSeries = <?= json_encode(array_values($rfqStatusBuckets)) ?>;

  Chart.defaults.font.family = getComputedStyle(document.body).fontFamily || 'system-ui';
  Chart.defaults.color = getComputedStyle(document.body).color || '#222';

  // PO responses
  new Chart(document.getElementById('poChart'), {
    type: 'bar',
    data: {
      labels: ['pending','acknowledged','accepted','declined'],
      datasets: [{ label:'Count', data: poData, borderWidth:1 }]
    },
    options: {
      maintainAspectRatio:false,
      scales:{ y:{ beginAtZero:true, ticks:{ precision:0 } } },
      plugins:{ legend:{ display:false } }
    }
  });

  // Quotes submitted
  new Chart(document.getElementById('quotesChart'), {
    type: 'line',
    data: { labels: quotesLabels, datasets: [{ label:'Quotes', data: quotesSeries, tension:.3, borderWidth:2, pointRadius:0, fill:false }] },
    options: {
      maintainAspectRatio:false,
      scales:{ y:{ beginAtZero:true, ticks:{ precision:0 } } },
      plugins:{ legend:{ display:false }, tooltip:{ mode:'index', intersect:false } }
    }
  });

  // RFQs by status (bar)
  new Chart(document.getElementById('rfqChart'), {
    type: 'bar',
    data: {
      labels:['open','awarded','closed','cancelled'],
      datasets:[{ label:'RFQs', data: rfqSeries, borderWidth:1 }]
    },
    options:{
      maintainAspectRatio:false,
      scales:{ y:{ beginAtZero:true, ticks:{ precision:0 } } },
      plugins:{ legend:{ display:false } }
    }
  });
</script>
<script src="<?= $BASE ?>/js/profile-dropdown.js"></script>
</body>
</html>
