<?php
// procurement/api/suppliers_save.php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role(['admin','procurement','manager'], 'json');
header('Content-Type: application/json; charset=utf-8');

$id     = (int)($_POST['id'] ?? 0);
$code   = strtoupper(trim($_POST['code'] ?? ''));
$name   = trim($_POST['name'] ?? '');
$cp     = trim($_POST['contact_person'] ?? '');
$email  = trim($_POST['email'] ?? '');
$phone  = trim($_POST['phone'] ?? '');
$addr   = trim($_POST['address'] ?? '');
$terms  = trim($_POST['payment_terms'] ?? '');
$rating = ($_POST['rating'] === '' ? null : (int)$_POST['rating']);
$lead   = ($_POST['lead_time_days'] === '' ? 0 : max(0,(int)$_POST['lead_time_days']));
$notes  = trim($_POST['notes'] ?? '');
$active = (isset($_POST['is_active']) && $_POST['is_active']=='1') ? 1 : 0;

$errs = [];
if ($code==='') $errs[]='Code is required';
if ($name==='') $errs[]='Name is required';
if ($rating !== null && ($rating<1 || $rating>5)) $errs[]='Rating must be 1–5';
if ($errs){ http_response_code(422); echo json_encode(['errors'=>$errs]); exit; }

try {
  if ($id>0){
    $sql="UPDATE suppliers
          SET code=?, name=?, contact_person=?, email=?, phone=?, address=?,
              rating=?, lead_time_days=?, payment_terms=?, notes=?, is_active=?
          WHERE id=?";
    $pdo->prepare($sql)->execute([$code,$name,$cp,$email,$phone,$addr,$rating,$lead,$terms,$notes,$active,$id]);
  } else {
    $sql="INSERT INTO suppliers
          (code,name,contact_person,email,phone,address,rating,lead_time_days,payment_terms,notes,is_active)
          VALUES (?,?,?,?,?,?,?,?,?,?,?)";
    $pdo->prepare($sql)->execute([$code,$name,$cp,$email,$phone,$addr,$rating,$lead,$terms,$notes,$active]);
  }
  echo json_encode(['ok'=>true]); exit;
} catch (PDOException $e){
  if ($e->getCode()==='23000'){ http_response_code(409); echo json_encode(['error'=>'DUPLICATE_CODE']); }
  else { http_response_code(500); echo json_encode(['error'=>'SERVER_ERROR']); }
  exit;
}
