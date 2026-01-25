<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/vendor_capability.php';

$proc = db('proc');
$wms  = db('wms');
if (!$proc instanceof PDO) { die("Proc DB error\n"); }
if (!$wms instanceof PDO) { die("WMS DB error\n"); }

$proc->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$wms->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

ensure_vendor_capability_tables($proc);

// Ensure categories exist in WMS
$cats = ['Laptop','Furniture'];
foreach ($cats as $c) {
  $chk = $wms->prepare("SELECT COUNT(*) FROM inventory_categories WHERE name=?");
  $chk->execute([$c]);
  if (!(int)$chk->fetchColumn()) {
    $ins = $wms->prepare("INSERT INTO inventory_categories (name, active) VALUES (?,1)");
    $ins->execute([$c]);
  }
}

// Insert sample vendors if not exist
$vendors = [
  ['company_name'=>'Bidder 1 Trading','email'=>'bidder1@example.com','contact_person'=>'Bidder One'],
  ['company_name'=>'Bidder 2 Furnishings','email'=>'bidder2@example.com','contact_person'=>'Bidder Two'],
];

foreach ($vendors as $v) {
  $chk = $proc->prepare("SELECT id FROM vendors WHERE email=? LIMIT 1");
  $chk->execute([$v['email']]);
  $vid = (int)$chk->fetchColumn();
  if ($vid <= 0) {
    $ins = $proc->prepare("INSERT INTO vendors (company_name, contact_person, email, status, created_at) VALUES (?,?,?,?,NOW())");
    $ins->execute([$v['company_name'], $v['contact_person'], $v['email'], 'approved']);
    $vid = (int)$proc->lastInsertId();
  }

  // Sample documents
  if ($v['company_name'] === 'Bidder 1 Trading') {
    $docs = [
      ['doc_type'=>'catalog','category'=>'Laptop'],
      ['doc_type'=>'delivery_receipt','category'=>'Laptop'],
    ];
  } else {
    $docs = [
      ['doc_type'=>'catalog','category'=>'Furniture'],
      ['doc_type'=>'delivery_receipt','category'=>'Furniture'],
    ];
  }
  foreach ($docs as $d) {
    $exists = $proc->prepare("SELECT COUNT(*) FROM vendor_documents WHERE vendor_id=? AND doc_type=? AND category=?");
    $exists->execute([$vid,$d['doc_type'],$d['category']]);
    if (!(int)$exists->fetchColumn()) {
      $ins = $proc->prepare("INSERT INTO vendor_documents (vendor_id, doc_type, category, status, created_at) VALUES (?,?,?,'approved',NOW())");
      $ins->execute([$vid,$d['doc_type'],$d['category']]);
    }
  }

  $allCats = get_all_categories($proc);
  recompute_vendor_capability($proc, $vid, $allCats);
}

echo "Sample vendors and documents created.\n";
