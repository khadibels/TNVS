<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../../includes/config.php';
require_once __DIR__ . '/../../../../includes/db.php';
require_once __DIR__ . '/../../../../includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

try{
  require_login();
  if($_SERVER['REQUEST_METHOD']!=='POST') throw new Exception('POST required');
  $u=current_user(); $vendorId=(int)($u['vendor_id']??0); if($vendorId<=0) throw new Exception('No vendor');
  $id=(int)($_POST['id']??0); if($id<=0) throw new Exception('Invalid id');
  $ship=trim((string)($_POST['promised_ship_at']??'')) ?: null;
  $delv=trim((string)($_POST['promised_deliver_at']??'')) ?: null;
  $note=trim((string)($_POST['note']??''));

  $pdo=db('proc'); if(!$pdo instanceof PDO) throw new Exception('DB');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $up=$pdo->prepare("
    UPDATE pos
       SET vendor_ack_status='accepted',
           vendor_ack_at=NOW(),
           vendor_note=?,
           promised_ship_at=?,
           promised_deliver_at=?
     WHERE id=? AND vendor_id=? AND status='issued' AND vendor_ack_status IN ('pending','acknowledged')
  ");
  $up->execute([$note,$ship,$delv,$id,$vendorId]);
  if(!$up->rowCount()) throw new Exception('Not allowed');
  echo json_encode(['ok'=>true]);
}catch(Throwable $e){ http_response_code(400); echo json_encode(['error'=>$e->getMessage()]); }
