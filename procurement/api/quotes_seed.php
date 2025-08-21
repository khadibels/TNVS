<?php
// procurement/api/quotes_seed.php
declare(strict_types=1);

$inc = __DIR__ . '/../../includes';
require_once $inc . '/config.php';
require_once $inc . '/auth.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

// --- helpers ---
function bad(string $m, int $c=400){ http_response_code($c); echo json_encode(['error'=>$m]); exit; }
function table_exists(PDO $pdo, string $t): bool {
  $st=$pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
  $st->execute([$t]); return (bool)$st->fetchColumn();
}
function col_exists(PDO $pdo, string $t, string $c): bool {
  $st=$pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
  $st->execute([$t,$c]); return (bool)$st->fetchColumn();
}
function first_col(PDO $pdo, string $t, array $cands): ?string {
  foreach($cands as $c) if (col_exists($pdo,$t,$c)) return $c;
  return null;
}

try {
  $rfq_id = (int)($_GET['rfq_id'] ?? 0);
  if ($rfq_id <= 0) bad('rfq_id required');

  // invited suppliers for this RFQ
  $st = $pdo->prepare("SELECT supplier_id FROM rfq_recipients WHERE rfq_id=? ORDER BY supplier_id ASC");
  $st->execute([$rfq_id]);
  $suppliers = $st->fetchAll(PDO::FETCH_COLUMN,0);
  if (!$suppliers) bad('No invited suppliers to seed');

  $supplier_id = (int)($_GET['supplier_id'] ?? 0);
  if ($supplier_id && !in_array($supplier_id, array_map('intval',$suppliers), true)) {
    bad('Supplier not invited to this RFQ');
  }
  if (!$supplier_id) {
    $supplier_id = (int)$suppliers[array_rand($suppliers)];
  }

  // quotes.* columns (all optional)
  $qTotalCol = first_col($pdo,'quotes',['total_amount','total','grand_total','total_cache']); // may be null
  $qLeadCol  = first_col($pdo,'quotes',['lead_time_days','lead_time','lead_days']);
  $qSubCol   = first_col($pdo,'quotes',['submitted_at','created_at','updated_at']);
  $hasFinal  = col_exists($pdo,'quotes','is_final');

  // quote_items capability (to compute total if quotes total not present)
  $hasQI = table_exists($pdo,'quote_items');
  $qiFk  = $hasQI ? first_col($pdo,'quote_items',['quote_id','q_id','quotes_id','quoteId']) : null;
  $qtyC  = $hasQI ? first_col($pdo,'quote_items',['quantity','qty','qty_ordered','qty_approved']) : null;
  $priceC= $hasQI ? first_col($pdo,'quote_items',['unit_price','price','unit_cost','rate','unitrate']) : null;
  $lineC = $hasQI ? first_col($pdo,'quote_items',['line_total','amount','total_amount','total']) : null;

  if (!$qTotalCol && !($hasQI && $qiFk && ($lineC || ($qtyC && $priceC)))) {
    bad('Cannot compute totals: add quotes.total_amount or quote_items(quantity, unit_price) or line_total');
  }

  $pdo->beginTransaction();

  // upsert one quote per rfq/supplier
  $st = $pdo->prepare("SELECT id FROM quotes WHERE rfq_id=? AND supplier_id=? LIMIT 1");
  $st->execute([$rfq_id,$supplier_id]);
  $quote_id = (int)($st->fetchColumn() ?: 0);

  $lead = random_int(3,21);
  $now  = date('Y-m-d H:i:s');

  if (!$quote_id) {
    $cols = ['rfq_id','supplier_id'];
    $vals = [$rfq_id,$supplier_id];
    if ($qTotalCol){ $cols[]=$qTotalCol; $vals[]=0; }
    if ($qLeadCol){ $cols[]=$qLeadCol; $vals[]=$lead; }
    if ($qSubCol){ $cols[]=$qSubCol; $vals[]=$now; }
    if ($hasFinal){ $cols[]='is_final'; $vals[]=1; }

    $ph = implode(',', array_fill(0,count($cols),'?'));
    $sql = "INSERT INTO quotes (`".implode('`,`',$cols)."`) VALUES ($ph)";
    $pdo->prepare($sql)->execute($vals);
    $quote_id = (int)$pdo->lastInsertId();
  } else {
    $sets=[]; $vals=[];
    if ($qLeadCol){ $sets[]="`$qLeadCol`=?"; $vals[]=$lead; }
    if ($qSubCol){ $sets[]="`$qSubCol`=?"; $vals[]=$now; }
    if ($hasFinal){ $sets[]="`is_final`=1"; }
    if ($sets){
      $vals[]=$quote_id;
      $pdo->prepare("UPDATE quotes SET ".implode(', ',$sets)." WHERE id=?")->execute($vals);
    }
  }

  // seed items (ensures per-line rfq_item_id if that column exists)
  $seedTotal = 0.0;
  $itemsInserted = 0;
  if ($hasQI && $qiFk) {
    $qiRfqItemC = first_col($pdo,'quote_items',['rfq_item_id','item_id','rfqitem_id','rfq_item']);

    // map to real rfq_items if available
    $rfqItemIds = [];
    if ($qiRfqItemC && table_exists($pdo,'rfq_items')) {
      $stIds = $pdo->prepare("SELECT id FROM rfq_items WHERE rfq_id=? ORDER BY id ASC");
      $stIds->execute([$rfq_id]);
      $rfqItemIds = $stIds->fetchAll(PDO::FETCH_COLUMN,0);
    }

    // clean old items to avoid unique-key collisions
    $pdo->prepare("DELETE FROM quote_items WHERE `$qiFk`=?")->execute([$quote_id]);

    $n = max(2, min(4, count($rfqItemIds) ?: 3)); // 2–4 lines (or based on rfq_items)
    for ($i=0; $i<$n; $i++){
      $qty   = random_int(1,5);
      $price = random_int(100,800);
      $seedTotal += $qty * $price;

      $cols = [$qiFk];
      $vals = [$quote_id];

      if ($qiRfqItemC) {
        $rfq_item_id_val = $rfqItemIds[$i] ?? (1000 + $i); // unique fallback
        $cols[] = $qiRfqItemC; $vals[] = $rfq_item_id_val;
      }

      if ($qtyC)   { $cols[]=$qtyC;   $vals[]=$qty; }
      if ($priceC) { $cols[]=$priceC; $vals[]=$price; }
      if ($lineC && !($qtyC && $priceC)) { // only set line total when we can't set qty & price
        $cols[]=$lineC; $vals[]=$qty*$price;
      }

      $ph = implode(',', array_fill(0,count($cols),'?'));
      $sql = "INSERT INTO quote_items (`".implode('`,`',$cols)."`) VALUES ($ph)";
      $pdo->prepare($sql)->execute($vals);
      $itemsInserted++;
    }
  }

  // if quotes has a total column, store a reasonable total
  if ($qTotalCol) {
    $totalToSet = $seedTotal > 0 ? $seedTotal : random_int(2000,20000);
    $pdo->prepare("UPDATE quotes SET `$qTotalCol`=? WHERE id=?")->execute([$totalToSet, $quote_id]);
  }

  $pdo->commit();

  echo json_encode([
    'ok' => true,
    'rfq_id' => $rfq_id,
    'supplier_id' => $supplier_id,
    'quote_id' => $quote_id,
    'seeded_items' => $itemsInserted,
    'used_quotes_total_column' => $qTotalCol ?: null
  ]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()]);
}
