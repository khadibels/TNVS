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

// Fetch Unread Notification Count
$unReadCount = 0;
try {
  $stU = $pdo->prepare("SELECT COUNT(*) FROM vendor_notifications WHERE vendor_id = :vid AND is_read = 0");
  $stU->execute([':vid' => $VENDOR_ID]);
  $unReadCount = (int)$stU->fetchColumn();
} catch (Throwable $e) {}

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

  /* ─── Modal Custom Styles ─── */
  .modal-content { border-radius: 12px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,.15); }
  .modal-header { border-bottom: 1px solid #f0f2f5; padding: 1.25rem 1.5rem; }
  .modal-body { padding: 1.5rem; }
  .modal-footer { border-top: 1px solid #f0f2f5; padding: 1rem 1.5rem; }
  .modal-title { font-weight: 700; color: #1c1e21; font-size: 1.15rem; }
  
  .bid-item-row { 
    background: #f8f9fa; border-radius: 10px; padding: 1rem; 
    margin-bottom: 1rem; border: 1px solid #e9ecef;
  }
  .bid-item-title { font-weight: 600; color: #1c1e21; margin-bottom: .25rem; }
  .bid-item-specs { font-size: .82rem; color: #65676b; margin-bottom: .75rem; }
  .bid-item-inputs { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
  
  .form-label { font-weight: 600; font-size: .85rem; color: #4b4d50; margin-bottom: .4rem; }
  .form-control:focus { border-color: #6532C9; box-shadow: 0 0 0 0.25rem rgba(101, 50, 201, 0.15); }
  
  .btn-bid-submit { 
    background: #6532C9; border: none; color: #fff; font-weight: 600; 
    padding: .6rem 2rem; border-radius: 8px; transition: all .2s;
  }
  .btn-bid-submit:hover { background: #5b21b6; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(101, 50, 201, 0.3); }
  .btn-bid-submit:disabled { opacity: .6; cursor: not-allowed; transform: none; box-shadow: none; }

  /* ─── Toast Customization ─── */
  .toast-container { z-index: 9999; }
  .bid-toast {
    background: #fff; border: none; border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0,0,0,.15); overflow: hidden;
  }
  .bid-toast .toast-header {
    background: #6532C9; color: #fff; border: none; padding: .75rem 1rem;
  }
  .bid-toast .toast-header .btn-close { filter: brightness(0) invert(1); }
  .bid-toast .toast-body { padding: 1.25rem; font-weight: 500; color: #1c1e21; }
  .bid-toast .toast-icon { 
    width: 32px; height: 32px; border-radius: 50%; background: #e7f6ec; 
    color: #1a7f37; display: grid; place-items: center; font-size: 1.2rem;
  }

  /* ─── Notification Dropdown ─── */
  .header-actions { display: flex; align-items: center; gap: .75rem; margin-right: 1.25rem; }
  .nav-icon-btn {
    width: 40px; height: 40px; border-radius: 50%; background: #e4e6eb;
    display: grid; place-items: center; cursor: pointer; position: relative;
    color: #1c1e21; transition: background .2s; border: none;
  }
  .nav-icon-btn:hover { background: #d8dadf; }
  .nav-icon-btn ion-icon { font-size: 1.35rem; }
  .nav-icon-btn .badge {
    position: absolute; top: -2px; right: -2px;
    background: #e41e3f; color: #fff; font-size: .65rem;
    min-width: 18px; height: 18px; border-radius: 10px;
    display: grid; place-items: center; border: 2px solid #fff;
    padding: 0 4px;
  }

  .notif-dropdown {
    position: absolute; top: 100%; right: 0; width: 360px;
    background: #fff; border-radius: 12px; box-shadow: 0 12px 28px 0 rgba(0,0,0,0.2), 0 2px 4px 0 rgba(0,0,0,0.1);
    margin-top: 8px; z-index: 1000; display: none; overflow: hidden;
  }
  .notif-dropdown.show { display: block; }
  .notif-header { padding: .75rem 1rem; border-bottom: 1px solid #ebedf0; display: flex; align-items: center; justify-content: space-between; }
  .notif-header h6 { margin: 0; font-weight: 700; font-size: 1.1rem; }
  .notif-list { max-height: 400px; overflow-y: auto; }
  .notif-item {
    display: flex; gap: .75rem; padding: .65rem .75rem;
    text-decoration: none; color: inherit; transition: background .2s; border-radius: 8px; margin: 4px 8px;
  }
  .notif-item:hover { background: #f2f2f2; }
  .notif-item.unread { position: relative; }
  .notif-item.unread::after {
    content: ''; width: 8px; height: 8px; border-radius: 50%; background: #1877f2;
    position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
  }
  .notif-icon { 
    width: 48px; height: 48px; border-radius: 50%; display: grid; place-items: center; flex-shrink: 0;
    background: #e7f3ff; color: #1877f2;
  }
  .notif-icon.award { background: #e7f6ec; color: #1a7f37; }
  .notif-icon.bid { background: #fdf2f2; color: #d1242f; }
  .notif-content { flex: 1; min-width: 0; }
  .notif-title { font-size: .88rem; font-weight: 600; line-height: 1.3; margin-bottom: 2px; }
  .notif-body { font-size: .82rem; color: #65676b; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
  .notif-time { font-size: .75rem; color: #1877f2; font-weight: 500; margin-top: 4px; }
  .notif-item:not(.unread) .notif-time { color: #65676b; font-weight: 400; }
  .notif-footer { padding: .75rem; text-align: center; border-top: 1px solid #ebedf0; }
  .notif-footer a { font-size: .88rem; font-weight: 600; color: #1877f2; text-decoration: none; }
  .notif-footer a:hover { text-decoration: underline; }

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
        
        <div class="d-flex align-items-center">
          <div class="header-actions">
            <!-- Notifications Bell -->
            <div class="position-relative">
              <button class="nav-icon-btn" id="notifBell" aria-label="Notifications" aria-expanded="false">
                <ion-icon name="notifications"></ion-icon>
                <?php if ($unReadCount > 0): ?>
                  <span class="badge" id="notifBadge"><?= $unReadCount ?></span>
                <?php endif; ?>
              </button>
              
              <div class="notif-dropdown shadow" id="notifDropdown">
                <div class="notif-header">
                  <h6>Notifications</h6>
                </div>
                <div class="notif-list" id="notifList">
                  <div class="text-center py-4"><span class="spinner-border spinner-border-sm text-primary"></span></div>
                </div>
                <div class="notif-footer">
                  <a href="#">Mark all as read</a>
                </div>
              </div>
            </div>
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
              <a href="<?= $BASE ?>/vendor_portal/vendor/account.php" role="menuitem">My Account</a>
              <a href="<?= u('auth/logout.php') ?>" role="menuitem">Sign out</a>
            </div>
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
                    <div class="item-qty-val">₱ <?= (float)$it['qty'] ?></div>
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
                <button type="button" class="post-action-btn bid-btn already-bid" disabled>
                  <ion-icon name="checkmark-circle"></ion-icon>
                  Bid Submitted
                </button>
              <?php else: ?>
                <button type="button" class="post-action-btn bid-btn" onclick="openBidModal(<?= (int)$rfq['id'] ?>)">
                  <ion-icon name="pricetag-outline"></ion-icon>
                  Bid
                </button>
              <?php endif; ?>
              <button type="button" class="post-action-btn" onclick="openBidModal(<?= (int)$rfq['id'] ?>)">
                <ion-icon name="eye-outline"></ion-icon>
                View Details
              </button>
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

<!-- Bidding Modal -->
<div class="modal fade" id="bidModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title d-flex align-items-center gap-2" id="bidModalHeader">
          <ion-icon name="pricetag-outline"></ion-icon> <span>Submit Quote / Bid</span>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="bidRfqInfo" class="mb-4">
          <div class="d-flex justify-content-between align-items-start mb-2">
            <h4 id="bidRfqTitle" class="fw-bold m-0"></h4>
            <span id="bidRfqNo" class="badge bg-light text-primary border px-3 py-2"></span>
          </div>
          <p id="bidRfqDesc" class="text-muted small"></p>
        </div>

        <form id="bidForm">
          <input type="hidden" name="rfq_id" id="bidRfqId">
          
          <div id="bidItemsList" class="mb-4">
            <!-- Items populated by JS -->
          </div>

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label text-muted small">Currency</label>
              <div id="bidCurrencyDisplay" class="fw-bold">₱ (PHP)</div>
              <input type="hidden" id="bidCurrency" value="PHP">
            </div>
            <div class="col-md-6">
              <label class="form-label text-muted small">Lead Time (Days)</label>
              <div id="bidLeadTimeDisplay" class="fw-bold d-none"></div>
              <input type="number" name="lead_time_days" id="bidLeadTimeInput" class="form-control" required min="1" placeholder="e.g. 7">
            </div>
            <div class="col-12">
              <label class="form-label text-muted small">Additional Terms / Notes</label>
              <div id="bidTermsDisplay" class="fw-bold d-none" style="white-space:pre-wrap"></div>
              <textarea name="terms" id="bidTermsInput" class="form-control" rows="3" placeholder="Specify any terms or special conditions..."></textarea>
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer" id="bidModalFooter">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" form="bidForm" id="btnSubmitBid" class="btn-bid-submit">
          Post My Bid
        </button>
      </div>
      <div class="modal-footer d-none" id="viewModalFooter">
        <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Toast Notification Container -->
<div class="toast-container position-fixed bottom-0 end-0 p-3">
  <div id="bidSuccessToast" class="toast bid-toast" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="toast-header">
      <strong class="me-auto d-flex align-items-center gap-2">
        <ion-icon name="notifications-outline"></ion-icon>
        <span id="toastTitleText">Notification</span>
      </strong>
      <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
    <div class="toast-body d-flex align-items-start gap-3">
        <div class="toast-icon">
            <ion-icon name="checkmark-circle"></ion-icon>
        </div>
        <div id="toastMsgText">Your bid has been submitted.</div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
const esc = s => String(s??'').replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m]));

document.addEventListener('DOMContentLoaded', () => {
    bidModal = new bootstrap.Modal(document.getElementById('bidModal'));
});

async function openBidModal(rfqId) {
    const modalEl = document.getElementById('bidModal');
    const itemsList = document.getElementById('bidItemsList');
    itemsList.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div><p class="mt-2 text-muted">Loading details...</p></div>';
    
    bidModal.show();

    try {
        const res = await fetch(`api/rfq_detail.php?id=${rfqId}`);
        const json = await res.json();
        
        if (!res.ok) throw new Error(json.error || 'Failed to load details');

        document.getElementById('bidRfqTitle').textContent = json.rfq.title;
        document.getElementById('bidRfqNo').textContent = json.rfq.rfq_no;
        document.getElementById('bidRfqDesc').textContent = json.rfq.description;
        document.getElementById('bidRfqId').value = rfqId;
        document.getElementById('bidCurrency').value = json.rfq.currency;
        document.getElementById('bidCurrencyDisplay').textContent = json.rfq.currency;

        const hasBid = json.my_quotes && json.my_quotes.length > 0;
        const bid = hasBid ? json.my_quotes[0] : null;

        // Toggle UI mode
        document.getElementById('bidModalHeader').innerHTML = hasBid 
            ? `<ion-icon name="document-text-outline"></ion-icon> <span>Quotation Details</span>`
            : `<ion-icon name="pricetag-outline"></ion-icon> <span>Submit Quote / Bid</span>`;
        
        document.getElementById('bidModalFooter').classList.toggle('d-none', hasBid);
        document.getElementById('viewModalFooter').classList.toggle('d-none', !hasBid);

        // Form fields vs Display fields
        document.getElementById('bidLeadTimeInput').classList.toggle('d-none', hasBid);
        document.getElementById('bidLeadTimeDisplay').classList.toggle('d-none', !hasBid);
        document.getElementById('bidLeadTimeDisplay').textContent = bid ? `${bid.lead_time_days} days` : '';
        
        document.getElementById('bidTermsInput').classList.toggle('d-none', hasBid);
        document.getElementById('bidTermsDisplay').classList.toggle('d-none', !hasBid);
        document.getElementById('bidTermsDisplay').textContent = bid ? bid.terms : 'No extra terms.';

        itemsList.innerHTML = '';
        json.items.forEach(it => {
            const row = document.createElement('div');
            row.className = 'bid-item-row';
            const priceVal = (hasBid && it.my_price) ? it.my_price : '';
            row.innerHTML = `
                <div class="bid-item-title">${esc(it.item)}</div>
                <div class="bid-item-specs text-truncate">${esc(it.specs || 'No specifications provided')}</div>
                <div class="bid-item-inputs">
                    <div>
                        <label class="form-label d-block text-muted small">Quantity</label>
                        <div class="fw-bold">${Number(it.qty)} ${esc(it.uom)}</div>
                    </div>
                    <div>
                        <label class="form-label text-muted small">Unit Price (₱)</label>
                        ${hasBid 
                          ? `<div class="fw-bold text-violet">₱ ${Number(it.my_price||0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}</div>`
                          : `<div class="input-group input-group-sm">
                               <span class="input-group-text bg-light">₱</span>
                               <input type="number" name="items[${it.id}]" class="form-control" required step="0.01" min="0.01">
                             </div>`
                        }
                    </div>
                </div>
            `;
            itemsList.appendChild(row);
        });

    } catch (err) {
        itemsList.innerHTML = `<div class="alert alert-danger">${err.message}</div>`;
    }
}

document.getElementById('bidForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('btnSubmitBid');
    const originalText = btn.innerHTML;
    
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Posting...';

    try {
        const fd = new FormData(e.target);
        const res = await fetch('api/quote_submit.php', {
            method: 'POST',
            body: fd
        });
        const json = await res.json();

        if (!res.ok) throw new Error(json.error || 'Submission failed');

        // Success!
        bidModal.hide();
        
        // Show success and reload or update UI
        const rfqId = document.getElementById('bidRfqId').value;
        const postCard = document.getElementById(`post-${rfqId}`);
        if (postCard) {
            const bidBtn = postCard.querySelector('.bid-btn');
            if (bidBtn) {
                bidBtn.outerHTML = `
                    <button type="button" class="post-action-btn bid-btn already-bid" disabled>
                        <ion-icon name="checkmark-circle"></ion-icon>
                        Bid Submitted
                    </button>
                `;
            }
        }
        
        showToast('Bid Complete', 'Your quotation has been successfully posted to the portal.');

    } catch (err) {
        showToast('Submission Failed', err.message, 'danger');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
});

function showToast(title, message, type = 'success') {
    const toastEl = document.getElementById('bidSuccessToast');
    const toastTitle = document.getElementById('toastTitleText');
    const toastMsg = document.getElementById('toastMsgText');
    const toastIcon = toastEl.querySelector('.toast-icon');
    
    toastTitle.textContent = title;
    toastMsg.textContent = message;
    
    if (type === 'danger') {
        toastIcon.style.background = '#ffeef0';
        toastIcon.style.color = '#d1242f';
        toastIcon.innerHTML = '<ion-icon name="alert-circle"></ion-icon>';
    } else {
        toastIcon.style.background = '#e7f6ec';
        toastIcon.style.color = '#1a7f37';
        toastIcon.innerHTML = '<ion-icon name="checkmark-circle"></ion-icon>';
    }

    const t = new bootstrap.Toast(toastEl, { delay: 4000 });
    t.show();
}

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

  /* ─── Notifications Logic ─── */
  (function() {
    const $ = s => document.querySelector(s);
    const bell = $('#notifBell'), dropdown = $('#notifDropdown'), list = $('#notifList'), badge = $('#notifBadge');
    if (!bell) return;

    let isOpen = false;

    async function loadNotifs() {
      try {
        const res = await fetch('api/notifications_list.php');
        const j = await res.json();
        if (!j.data || !j.data.length) {
          list.innerHTML = `<div class="text-center text-muted py-5 px-3 small">No notifications yet.</div>`;
          return;
        }
        list.innerHTML = j.data.slice(0, 10).map(n => {
          const isAward = (n.title||'').toLowerCase().includes('approved');
          const isBid   = (n.title||'').toLowerCase().includes('bid') || (n.title||'').toLowerCase().includes('quotation');
          const icon    = isAward ? 'trophy' : (isBid ? 'pricetag' : 'notifications');
          const cls     = isAward ? 'award' : (isBid ? 'bid' : '');
          
          return `
            <a href="#" class="notif-item ${n.is_read ? '' : 'unread'}" data-nid="${n.id}" data-rfq="${n.rfq_id || ''}">
              <div class="notif-icon ${cls}"><ion-icon name="${icon}"></ion-icon></div>
              <div class="notif-content">
                <div class="notif-title">${esc(n.title)}</div>
                <div class="notif-body">${esc(n.body)}</div>
                <div class="notif-time">${new Date(n.created_at).toLocaleDateString()}</div>
              </div>
            </a>`;
        }).join('');
      } catch (e) {
        list.innerHTML = `<div class="p-3 text-danger small">Failed to load notifications.</div>`;
      }
    }

    bell.addEventListener('click', e => {
      e.stopPropagation();
      isOpen = !isOpen;
      dropdown.classList.toggle('show', isOpen);
      bell.setAttribute('aria-expanded', String(isOpen));
      if (isOpen) {
        loadNotifs();
        // Mark all as read when opening (optional, or mark individually)
        markAllRead();
      }
    });

    async function markAllRead() {
      try {
        await fetch('api/notifications_mark.php', { method: 'POST', body: new URLSearchParams({ all: '1' }) });
        if (badge) badge.remove();
      } catch (e) {}
    }

    document.addEventListener('click', e => {
      if (isOpen && !dropdown.contains(e.target) && !bell.contains(e.target)) {
        isOpen = false;
        dropdown.classList.remove('show');
        bell.setAttribute('aria-expanded', 'false');
      }
      
      const item = e.target.closest('.notif-item');
      if (item) {
        e.preventDefault();
        const rfqId = item.dataset.rfq;
        if (rfqId) {
          // Open the RFQ modal on the dashboard if it exists
          if (window.openBidModal) window.openBidModal(rfqId);
        }
        isOpen = false;
        dropdown.classList.remove('show');
      }
    });
  })();
</script>
<script src="<?= $BASE ?>/js/profile-dropdown.js"></script>
</body>
</html>
