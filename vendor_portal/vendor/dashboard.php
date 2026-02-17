<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_login();

$section = 'vendor';
$active  = 'portal';

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

/* ---------- DB ---------- */
$pdo = db('proc');
if (!$pdo instanceof PDO) { http_response_code(500); die('DB connection error'); }
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------- Fetch open RFQs with their items ---------- */
$search = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset  = ($page - 1) * $perPage;

$where = ["r.status = 'sent'", "r.due_at > NOW()"];
$params = [];

if ($search !== '') {
  $where[] = "(r.title LIKE :q OR r.rfq_no LIKE :q OR r.description LIKE :q)";
  $params[':q'] = "%{$search}%";
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

// Count
$st = $pdo->prepare("SELECT COUNT(*) FROM rfqs r $whereSql");
$st->execute($params);
$totalRfqs = (int)$st->fetchColumn();
$totalPages = max(1, (int)ceil($totalRfqs / $perPage));

// Fetch RFQs
$sql = "
  SELECT r.id, r.rfq_no, r.title, r.description, r.currency, r.due_at, r.created_at,
         (SELECT COUNT(*) FROM rfq_items ri WHERE ri.rfq_id = r.id) AS item_count,
         (SELECT COUNT(*) FROM quotes q WHERE q.rfq_id = r.id AND q.vendor_id = :vid) AS my_quotes,
         (SELECT COUNT(DISTINCT q2.vendor_id) FROM quotes q2 WHERE q2.rfq_id = r.id) AS total_bids
  FROM rfqs r
  $whereSql
  ORDER BY r.created_at DESC
  LIMIT $perPage OFFSET $offset
";
$params[':vid'] = $VENDOR_ID;
$st = $pdo->prepare($sql);
$st->execute($params);
$rfqs = $st->fetchAll(PDO::FETCH_ASSOC);

// Fetch items for each RFQ
$rfqItems = [];
if ($rfqs) {
  $ids = array_column($rfqs, 'id');
  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $st2 = $pdo->prepare("SELECT rfq_id, id, line_no, item, specs, qty, uom FROM rfq_items WHERE rfq_id IN ($placeholders) ORDER BY rfq_id, line_no ASC");
  $st2->execute($ids);
  foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $it) {
    $rfqItems[(int)$it['rfq_id']][] = $it;
  }
}

/* ---------- Helpers ---------- */
function time_remaining(string $due): array {
  $diff = strtotime($due) - time();
  if ($diff <= 0) return ['label' => 'Expired', 'urgent' => true];
  $days = floor($diff / 86400);
  $hrs  = floor(($diff % 86400) / 3600);
  if ($days > 3) return ['label' => "{$days}d {$hrs}h left", 'urgent' => false];
  if ($days > 0) return ['label' => "{$days}d {$hrs}h left", 'urgent' => true];
  return ['label' => "{$hrs}h left", 'urgent' => true];
}

function time_ago(string $dt): string {
  $diff = time() - strtotime($dt);
  if ($diff < 60) return 'Just now';
  if ($diff < 3600) return floor($diff/60) . 'm ago';
  if ($diff < 86400) return floor($diff/3600) . 'h ago';
  if ($diff < 604800) return floor($diff/86400) . 'd ago';
  return date('M j', strtotime($dt));
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Portal — Procurement Feed</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
<link href="<?= $BASE ?>/css/style.css" rel="stylesheet" />
<link href="<?= $BASE ?>/css/modules.css" rel="stylesheet" />
<link href="<?= $BASE ?>/css/vendor_portal_saas.css" rel="stylesheet" />
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
<script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
<script src="<?= $BASE ?>/js/sidebar-toggle.js"></script>
<style>
  * { box-sizing: border-box; }
  body { background: #f0f2f5; font-family: 'Inter', system-ui, -apple-system, sans-serif; }
  .main-content { padding: 1rem; }
  @media(min-width:992px) { .main-content { padding: 1.5rem 2rem; } }

  /* ─── Feed Layout ─── */
  .feed-container {
    max-width: 680px;
    margin: 0 auto;
  }

  /* ─── Search Bar (like Facebook search) ─── */
  .feed-search {
    background: #fff; border-radius: 12px; padding: .75rem 1rem;
    margin-bottom: 1rem; box-shadow: 0 1px 3px rgba(0,0,0,.08);
    display: flex; align-items: center; gap: .6rem;
  }
  .feed-search ion-icon { font-size: 1.2rem; color: #65676b; flex-shrink: 0; }
  .feed-search input {
    flex: 1; border: none; outline: none; font-size: .92rem;
    background: #f0f2f5; border-radius: 20px; padding: .5rem 1rem;
    color: #1c1e21;
  }
  .feed-search input::placeholder { color: #65676b; }

  /* ─── Post Card ─── */
  .post-card {
    background: #fff; border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,.08);
    margin-bottom: 1rem;
    overflow: hidden;
    transition: box-shadow .2s;
  }
  .post-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,.12); }

  /* Post Header (like FB user info) */
  .post-header {
    display: flex; align-items: center; gap: .7rem;
    padding: .85rem 1rem .5rem;
  }
  .post-avatar {
    width: 44px; height: 44px; border-radius: 50%;
    background: linear-gradient(135deg, #6532C9, #7c3aed);
    display: grid; place-items: center; flex-shrink: 0;
  }
  .post-avatar ion-icon { font-size: 1.4rem; color: #fff; }
  .post-user-info { flex: 1; min-width: 0; }
  .post-user-name {
    font-weight: 700; font-size: .92rem; color: #1c1e21;
    display: flex; align-items: center; gap: .4rem;
  }
  .post-user-name .verified {
    background: #6532C9; color: #fff; border-radius: 50%;
    width: 16px; height: 16px; display: inline-grid; place-items: center;
    font-size: .6rem;
  }
  .post-meta-line {
    font-size: .78rem; color: #65676b;
    display: flex; align-items: center; gap: .35rem;
  }
  .post-meta-line ion-icon { font-size: .85rem; }
  .post-deadline {
    font-size: .75rem; font-weight: 600; padding: .15rem .55rem;
    border-radius: 6px; white-space: nowrap;
  }
  .post-deadline.normal { background: #e7f6ec; color: #1a7f37; }
  .post-deadline.urgent { background: #ffeef0; color: #d1242f; }

  /* Post Body */
  .post-body { padding: .25rem 1rem .75rem; }
  .post-title {
    font-size: 1rem; font-weight: 700; color: #1c1e21;
    margin-bottom: .35rem; line-height: 1.35;
  }
  .post-desc {
    font-size: .9rem; color: #1c1e21; line-height: 1.5;
    margin-bottom: .5rem;
  }
  .post-rfq-no {
    display: inline-flex; align-items: center; gap: .3rem;
    font-size: .78rem; font-weight: 600; color: #6532C9;
    background: #f4f2ff; padding: .2rem .6rem; border-radius: 6px;
    margin-bottom: .6rem;
  }

  /* Items List (like FB post content) */
  .post-items {
    background: #f7f8fa; border: 1px solid #e4e6eb;
    border-radius: 10px; overflow: hidden; margin-top: .4rem;
  }
  .post-item {
    display: flex; align-items: center; justify-content: space-between;
    padding: .65rem .85rem;
    border-bottom: 1px solid #e4e6eb;
    transition: background .12s;
  }
  .post-item:last-child { border-bottom: none; }
  .post-item:hover { background: #eef0f3; }
  .item-left { display: flex; align-items: center; gap: .6rem; }
  .item-bullet {
    width: 32px; height: 32px; border-radius: 8px;
    background: linear-gradient(135deg, #efe9ff, #f4f2ff);
    display: grid; place-items: center; flex-shrink: 0;
  }
  .item-bullet ion-icon { font-size: 1rem; color: #6532C9; }
  .item-info-name { font-weight: 600; font-size: .88rem; color: #1c1e21; }
  .item-info-spec { font-size: .78rem; color: #65676b; }
  .item-right {
    text-align: right; white-space: nowrap;
  }
  .item-qty-val { font-weight: 700; font-size: .9rem; color: #1c1e21; }
  .item-qty-uom { font-size: .78rem; color: #65676b; }

  /* Post Engagement Bar (like FB reactions) */
  .post-engagement {
    display: flex; justify-content: space-between; align-items: center;
    padding: .5rem 1rem;
    font-size: .82rem; color: #65676b;
  }
  .engagement-left { display: flex; align-items: center; gap: .35rem; }
  .bid-count-icon {
    width: 20px; height: 20px; border-radius: 50%;
    background: linear-gradient(135deg, #6532C9, #7c3aed);
    display: inline-grid; place-items: center;
  }
  .bid-count-icon ion-icon { font-size: .7rem; color: #fff; }

  /* Post Actions Bar (like FB Like/Comment/Share) */
  .post-actions {
    display: flex; border-top: 1px solid #e4e6eb;
  }
  .post-action-btn {
    flex: 1; display: flex; align-items: center; justify-content: center;
    gap: .45rem; padding: .7rem .5rem;
    font-size: .88rem; font-weight: 600; color: #65676b;
    background: none; border: none; cursor: pointer;
    transition: background .15s, color .15s;
    text-decoration: none;
  }
  .post-action-btn:hover { background: #f0f2f5; color: #1c1e21; }
  .post-action-btn ion-icon { font-size: 1.2rem; }
  .post-action-btn.bid-btn { color: #6532C9; }
  .post-action-btn.bid-btn:hover { background: #f4f2ff; color: #5b21b6; }
  .post-action-btn.bid-btn.already-bid { color: #1a7f37; }
  .post-action-btn.bid-btn.already-bid:hover { background: #e7f6ec; }
  .post-action-btn + .post-action-btn { border-left: 1px solid #e4e6eb; }

  /* ─── Empty / Loading ─── */
  .empty-feed {
    background: #fff; border-radius: 12px; text-align: center;
    padding: 3.5rem 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,.08);
    color: #65676b;
  }
  .empty-feed ion-icon { font-size: 3rem; color: #bec3c9; display: block; margin: 0 auto .75rem; }
  .empty-feed h4 { color: #1c1e21; margin-bottom: .4rem; }

  /* Pagination */
  .feed-paging {
    display: flex; justify-content: center; gap: .5rem; padding: 1rem 0;
  }
  .feed-paging a, .feed-paging span {
    padding: .5rem 1rem; border-radius: 8px; font-size: .88rem;
    font-weight: 600; text-decoration: none; transition: all .15s;
  }
  .feed-paging a {
    background: #fff; color: #6532C9; box-shadow: 0 1px 3px rgba(0,0,0,.08);
  }
  .feed-paging a:hover { background: #f4f2ff; }
  .feed-paging .active-pg {
    background: #6532C9; color: #fff; box-shadow: 0 2px 8px rgba(101,50,201,.25);
  }
  .feed-paging .disabled-pg { opacity: .4; pointer-events: none; background: #e4e6eb; color: #65676b; }
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
            <ion-icon name="storefront-outline"></ion-icon> Portal
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

      <div class="feed-container">

        <!-- Search -->
        <form class="feed-search" method="get" action="">
          <ion-icon name="search-outline"></ion-icon>
          <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search procurement requests…">
        </form>

        <!-- Feed Posts -->
        <?php if (empty($rfqs)): ?>
          <div class="empty-feed">
            <ion-icon name="document-text-outline"></ion-icon>
            <h4>No open requests</h4>
            <p>Procurement hasn't posted any requests yet. Check back soon!</p>
          </div>
        <?php else: ?>
          <?php foreach ($rfqs as $rfq):
            $items = $rfqItems[(int)$rfq['id']] ?? [];
            $tr = time_remaining($rfq['due_at']);
            $quoted = (int)$rfq['my_quotes'] > 0;
            $bidCount = (int)$rfq['total_bids'];
            $itemCount = (int)$rfq['item_count'];
          ?>
          <div class="post-card" id="post-<?= (int)$rfq['id'] ?>">

            <!-- Post Header -->
            <div class="post-header">
              <div class="post-avatar">
                <ion-icon name="business-outline"></ion-icon>
              </div>
              <div class="post-user-info">
                <div class="post-user-name">
                  TNVS Procurement
                  <span class="verified"><ion-icon name="checkmark" style="font-size:.55rem"></ion-icon></span>
                </div>
                <div class="post-meta-line">
                  <ion-icon name="time-outline"></ion-icon>
                  <?= time_ago($rfq['created_at']) ?>
                  <span>·</span>
                  <ion-icon name="globe-outline"></ion-icon>
                  Public
                </div>
              </div>
              <span class="post-deadline <?= $tr['urgent'] ? 'urgent' : 'normal' ?>">
                <ion-icon name="alarm-outline" style="font-size:.8rem;vertical-align:-1px"></ion-icon>
                <?= $tr['label'] ?>
              </span>
            </div>

            <!-- Post Body -->
            <div class="post-body">
              <span class="post-rfq-no">
                <ion-icon name="document-text-outline" style="font-size:.85rem"></ion-icon>
                <?= htmlspecialchars($rfq['rfq_no']) ?>
              </span>
              <div class="post-title"><?= htmlspecialchars($rfq['title']) ?></div>
              <?php if (!empty($rfq['description'])): ?>
                <div class="post-desc"><?= htmlspecialchars(mb_strimwidth($rfq['description'], 0, 200, '…')) ?></div>
              <?php endif; ?>

              <!-- Items list -->
              <?php if (!empty($items)): ?>
              <div class="post-items">
                <?php foreach ($items as $it): ?>
                <div class="post-item">
                  <div class="item-left">
                    <div class="item-bullet">
                      <ion-icon name="cube-outline"></ion-icon>
                    </div>
                    <div>
                      <div class="item-info-name"><?= htmlspecialchars($it['item']) ?></div>
                      <?php if (!empty($it['specs'])): ?>
                        <div class="item-info-spec"><?= htmlspecialchars(mb_strimwidth($it['specs'], 0, 60, '…')) ?></div>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="item-right">
                    <div class="item-qty-val"><?= htmlspecialchars($it['qty']) ?></div>
                    <div class="item-qty-uom"><?= htmlspecialchars($it['uom']) ?></div>
                  </div>
                </div>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
            </div>

            <!-- Engagement (like FB reactions count) -->
            <div class="post-engagement">
              <div class="engagement-left">
                <?php if ($bidCount > 0): ?>
                  <span class="bid-count-icon"><ion-icon name="pricetag"></ion-icon></span>
                  <?= $bidCount ?> vendor<?= $bidCount !== 1 ? 's' : '' ?> bid
                <?php endif; ?>
              </div>
              <span><?= $itemCount ?> item<?= $itemCount !== 1 ? 's' : '' ?></span>
            </div>

            <!-- Action Buttons (like FB Like/Comment/Share) -->
            <div class="post-actions">
              <?php if ($quoted): ?>
                <a href="<?= $BASE ?>/vendor_portal/vendor/rfqs.php?open=<?= (int)$rfq['id'] ?>" class="post-action-btn bid-btn already-bid">
                  <ion-icon name="checkmark-circle"></ion-icon>
                  Bid Submitted
                </a>
              <?php else: ?>
                <a href="<?= $BASE ?>/vendor_portal/vendor/rfqs.php?open=<?= (int)$rfq['id'] ?>" class="post-action-btn bid-btn">
                  <ion-icon name="pricetag-outline"></ion-icon>
                  Bid
                </a>
              <?php endif; ?>
              <a href="<?= $BASE ?>/vendor_portal/vendor/rfqs.php?open=<?= (int)$rfq['id'] ?>" class="post-action-btn">
                <ion-icon name="eye-outline"></ion-icon>
                View Details
              </a>
              <button class="post-action-btn" onclick="sharePost('<?= htmlspecialchars($rfq['rfq_no']) ?>', '<?= htmlspecialchars(addslashes($rfq['title'])) ?>')">
                <ion-icon name="share-social-outline"></ion-icon>
                Share
              </button>
            </div>
          </div>
          <?php endforeach; ?>

          <!-- Pagination -->
          <?php if ($totalPages > 1): ?>
          <div class="feed-paging">
            <?php if ($page > 1): ?>
              <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">← Previous</a>
            <?php else: ?>
              <span class="disabled-pg">← Previous</span>
            <?php endif; ?>

            <?php
              $startPg = max(1, $page - 2);
              $endPg   = min($totalPages, $page + 2);
              for ($i = $startPg; $i <= $endPg; $i++):
            ?>
              <?php if ($i === $page): ?>
                <span class="active-pg"><?= $i ?></span>
              <?php else: ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
              <?php endif; ?>
            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
              <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next →</a>
            <?php else: ?>
              <span class="disabled-pg">Next →</span>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        <?php endif; ?>

      </div><!-- /feed-container -->
    </div><!-- /main -->
  </div>
</div>

<script>
function sharePost(rfqNo, title) {
  const text = `Check out this procurement request: ${rfqNo} — ${title}`;
  if (navigator.share) {
    navigator.share({ title: rfqNo, text: text, url: window.location.href });
  } else if (navigator.clipboard) {
    navigator.clipboard.writeText(text + '\n' + window.location.href);
    alert('Link copied to clipboard!');
  } else {
    prompt('Copy this link:', window.location.href);
  }
}
</script>
<script src="<?= $BASE ?>/js/profile-dropdown.js"></script>
</body>
</html>
