<?php
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/vendor_media.php';

require_login('json');
require_role(['admin','vendor_manager'],'json');

$proc = db('proc');
$auth = db('auth');

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['ok'=>false,'error'=>'INVALID_ID']);
    exit;
}

$proc->beginTransaction();

$st = $proc->prepare("SELECT profile_photo, email FROM vendors WHERE id = ? FOR UPDATE");
$st->execute([$id]);
$vendor = $st->fetch(PDO::FETCH_ASSOC);
if (!$vendor) {
    $proc->rollBack();
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['ok'=>false,'error'=>'NOT_FOUND']);
    exit;
}

$photo = $vendor['profile_photo'] ?? null;

$delV = $proc->prepare("DELETE FROM vendors WHERE id = ?");
$delV->execute([$id]);

$delU = $auth->prepare("DELETE FROM users WHERE vendor_id = ?");
$delU->execute([$id]);

$proc->commit();

vendor_photo_delete($photo);

header('Content-Type: application/json');
echo json_encode(['ok'=>true,'message'=>'Vendor deleted']);
