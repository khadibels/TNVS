<?php
require_once __DIR__ . "/../../../includes/config.php";
require_once __DIR__ . "/../../../includes/auth.php";
require_once __DIR__ . "/../../../includes/db.php";
header("Content-Type: application/json; charset=utf-8");

function extract_ref(string $q): string {
  $q = strtoupper($q);
  if (preg_match('/\b(PO|SHP|RFQ)-\d{6,8}-\d{3,6}\b/', $q, $m)) return $m[0];
  if (preg_match('/\b(SHP|PO)-\d{8}-\d{4}\b/', $q, $m)) return $m[0];
  return '';
}

function is_greeting(string $q): bool {
  $q = strtolower(trim($q));
  return in_array($q, ['hi','hello','hey','yo','good morning','good afternoon','good evening'], true);
}

function list_tables(PDO $pdo, string $schema, int $limit = 8): array {
  $st = $pdo->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema=? ORDER BY table_name LIMIT $limit");
  $st->execute([$schema]);
  return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function table_exists(PDO $pdo, string $schema, string $table): bool {
  $st = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=? AND table_name=? LIMIT 1");
  $st->execute([$schema, $table]);
  return (bool)$st->fetchColumn();
}

function fetch_rows(PDO $pdo, string $sql, array $params = []): array {
  $st = $pdo->prepare($sql);
  $st->execute($params);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function db_overview(PDO $pdo, string $schema, int $limit = 8): array {
  $out = ['schema' => $schema, 'tables' => []];
  $st = $pdo->prepare("
    SELECT table_name, table_rows
      FROM information_schema.tables
     WHERE table_schema=?
     ORDER BY table_rows DESC
     LIMIT $limit
  ");
  $st->execute([$schema]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  foreach ($rows as $r) {
    $out['tables'][] = [
      'name' => $r['table_name'],
      'rows' => (int)($r['table_rows'] ?? 0),
    ];
  }
  $ct = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=?");
  $ct->execute([$schema]);
  $out['table_count'] = (int)$ct->fetchColumn();
  return $out;
}

function ollama_generate(array $payload, array $bases, string &$err): ?string {
  $err = '';
  $json = json_encode($payload);
  foreach ($bases as $base) {
    $url = rtrim($base, '/') . '/api/generate';
    // Prefer cURL if available
    if (function_exists('curl_init')) {
      $ch = curl_init($url);
      curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12
      ]);
      $resp = curl_exec($ch);
      $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $cerr = curl_error($ch);
      curl_close($ch);
      if ($resp !== false && $code >= 200 && $code < 300) {
        $j = json_decode($resp, true);
        if (is_array($j) && isset($j['response'])) {
          $reply = trim((string)$j['response']);
          if ($reply !== '') return $reply;
        }
      }
      $err = $cerr ?: "HTTP $code from $url";
      continue;
    }

    $ctx = stream_context_create([
      'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => $json,
        'timeout' => 12
      ]
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp !== false) {
      $j = json_decode($resp, true);
      if (is_array($j) && isset($j['response'])) {
        $reply = trim((string)$j['response']);
        if ($reply !== '') return $reply;
      }
    } else {
      $err = "No response from $url";
    }
  }
  return null;
}

function ollama_list_models(array $bases): array {
  foreach ($bases as $base) {
    $url = rtrim($base, '/') . '/api/tags';
    if (function_exists('curl_init')) {
      $ch = curl_init($url);
      curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8
      ]);
      $resp = curl_exec($ch);
      $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);
      if ($resp !== false && $code >= 200 && $code < 300) {
        $j = json_decode($resp, true);
        if (is_array($j) && isset($j['models'])) {
          return array_map(fn($m)=>$m['name'] ?? '', $j['models']);
        }
      }
    } else {
      $resp = @file_get_contents($url);
      if ($resp !== false) {
        $j = json_decode($resp, true);
        if (is_array($j) && isset($j['models'])) {
          return array_map(fn($m)=>$m['name'] ?? '', $j['models']);
        }
      }
    }
  }
  return [];
}

try {
  require_login("json");

  if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('POST required');

  $q = trim((string)($_POST['q'] ?? ''));
  $id = (int)($_POST['id'] ?? 0);
  $ref = trim((string)($_POST['ref_no'] ?? ''));

  if ($q === '') throw new Exception('Question is required');

  if (is_greeting($q)) {
    echo json_encode([
      'ok' => true,
      'reply' => 'Hello! How can I help you today? You can ask about shipments, inventory, procurement, logistics, vendors, or reports.'
    ]);
    exit;
  }

  $pdo = db('wms');
  if (!$pdo instanceof PDO) throw new Exception('DB not available');

  if ($id <= 0 && $ref === '') {
    $ref = extract_ref($q);
  }

  $shipment = null;
  if ($id > 0) {
    $st = $pdo->prepare("\
      SELECT s.id, s.ref_no, s.status, s.carrier,
             DATE_FORMAT(s.expected_pickup,'%Y-%m-%d') AS expected_pickup,
             DATE_FORMAT(s.expected_delivery,'%Y-%m-%d') AS expected_delivery,
             s.contact_name, s.contact_phone, s.notes,
             COALESCE(CONCAT(o.code,' - ',o.name), '—') AS origin,
             COALESCE(o.address, '') AS origin_address,
             COALESCE(CONCAT(d.code,' - ',d.name), '—') AS destination,
             COALESCE(d.address, '') AS destination_address
        FROM shipments s
        LEFT JOIN warehouse_locations o ON o.id=s.origin_id
        LEFT JOIN warehouse_locations d ON d.id=s.destination_id
       WHERE s.id=?
       LIMIT 1
    ");
    $st->execute([$id]);
    $shipment = $st->fetch(PDO::FETCH_ASSOC);
  } elseif ($ref !== '') {
    $st = $pdo->prepare("\
      SELECT s.id, s.ref_no, s.status, s.carrier,
             DATE_FORMAT(s.expected_pickup,'%Y-%m-%d') AS expected_pickup,
             DATE_FORMAT(s.expected_delivery,'%Y-%m-%d') AS expected_delivery,
             s.contact_name, s.contact_phone, s.notes,
             COALESCE(CONCAT(o.code,' - ',o.name), '—') AS origin,
             COALESCE(o.address, '') AS origin_address,
             COALESCE(CONCAT(d.code,' - ',d.name), '—') AS destination,
             COALESCE(d.address, '') AS destination_address
        FROM shipments s
        LEFT JOIN warehouse_locations o ON o.id=s.origin_id
        LEFT JOIN warehouse_locations d ON d.id=s.destination_id
       WHERE s.ref_no=?
       LIMIT 1
    ");
    $st->execute([$ref]);
    $shipment = $st->fetch(PDO::FETCH_ASSOC);
  }

  $context = [
    'question' => $q,
    'schema' => [],
    'data' => []
  ];

  if ($shipment) {
    $ev = $pdo->prepare("\
      SELECT DATE_FORMAT(event_time,'%Y-%m-%d %H:%i:%s') AS event_time, event_type, details
        FROM shipment_events
       WHERE shipment_id=?
       ORDER BY event_time DESC, id DESC
       LIMIT 5
    ");
    $ev->execute([(int)$shipment['id']]);
    $events = $ev->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $context['data']['shipment'] = $shipment;
    $context['data']['shipment_events'] = $events;
  }

  // Collect other DB context based on keywords
  $qLower = strtolower($q);
  $proc = db('proc');
  $auth = db('auth');
  $plt  = db('plt');
  $alms = db('alms');
  $docs = db('docs');

  if ($proc instanceof PDO) {
    $context['schema']['procurement'] = list_tables($proc, DB_PROC_NAME, 8);
    if (preg_match('/\b(po|purchase order|rfq|quote|vendor|procurement)\b/i', $q)) {
      if (table_exists($proc, DB_PROC_NAME, 'pos')) {
        $context['data']['pos_recent'] = fetch_rows($proc, "SELECT id, po_no, status, total, issued_at FROM pos ORDER BY id DESC LIMIT 5");
      }
      if (table_exists($proc, DB_PROC_NAME, 'rfqs')) {
        $context['data']['rfqs_recent'] = fetch_rows($proc, "SELECT id, rfq_no, title, status, due_at FROM rfqs ORDER BY id DESC LIMIT 5");
      }
      if (table_exists($proc, DB_PROC_NAME, 'vendors')) {
        $context['data']['vendors_recent'] = fetch_rows($proc, "SELECT id, company_name, status, created_at FROM vendors ORDER BY id DESC LIMIT 5");
        $context['data']['vendors_count'] = fetch_rows($proc, "SELECT COUNT(*) AS total, SUM(status='approved') AS approved FROM vendors");
      }
    }
  }

  if ($pdo instanceof PDO) {
    $context['schema']['wms'] = list_tables($pdo, DB_WMS_NAME, 8);
    if (preg_match('/\b(shipment|delivery|dispatch|track)\b/i', $q)) {
      $context['data']['shipments_recent'] = fetch_rows($pdo, "SELECT id, ref_no, status, expected_delivery FROM shipments ORDER BY id DESC LIMIT 5");
    }
    if (preg_match('/\b(inventory|stock|item)\b/i', $q) && table_exists($pdo, DB_WMS_NAME, 'inventory_items')) {
      $context['data']['inventory_items_recent'] = fetch_rows($pdo, "SELECT id, sku, name, is_active FROM inventory_items ORDER BY id DESC LIMIT 5");
    }
  }

  if ($docs instanceof PDO) {
    $context['schema']['docs'] = list_tables($docs, DB_DOCS_NAME, 8);
    if (preg_match('/\b(logistics|trip|document)\b/i', $q)) {
      if (table_exists($docs, DB_DOCS_NAME, 'logistics_records')) {
        $context['data']['logistics_recent'] = fetch_rows($docs, "SELECT id, trip_ref, driver_name, trip_date, origin, destination FROM logistics_records ORDER BY id DESC LIMIT 5");
      }
      if (table_exists($docs, DB_DOCS_NAME, 'documents')) {
        $context['data']['documents_recent'] = fetch_rows($docs, "SELECT id, title, doc_type, status, issue_date FROM documents ORDER BY id DESC LIMIT 5");
      }
    }
  }

  if ($plt instanceof PDO) {
    $context['schema']['plt'] = list_tables($plt, DB_PLT_NAME, 8);
    if (preg_match('/\b(project|plt)\b/i', $q) && table_exists($plt, DB_PLT_NAME, 'plt_projects')) {
      $context['data']['projects_recent'] = fetch_rows($plt, "SELECT id, name, status, start_date, end_date FROM plt_projects ORDER BY id DESC LIMIT 5");
    }
  }

  if ($alms instanceof PDO) {
    $context['schema']['alms'] = list_tables($alms, DB_ALMS_NAME, 8);
  }

  if ($auth instanceof PDO) {
    $context['schema']['auth'] = list_tables($auth, DB_AUTH_NAME, 8);
  }

  // Always include live DB overview (no hardcoded tables)
  $context['db_overview'] = [];
  if ($proc instanceof PDO) $context['db_overview'][] = db_overview($proc, DB_PROC_NAME);
  if ($pdo instanceof PDO)  $context['db_overview'][] = db_overview($pdo, DB_WMS_NAME);
  if ($docs instanceof PDO) $context['db_overview'][] = db_overview($docs, DB_DOCS_NAME);
  if ($plt instanceof PDO)  $context['db_overview'][] = db_overview($plt, DB_PLT_NAME);
  if ($alms instanceof PDO) $context['db_overview'][] = db_overview($alms, DB_ALMS_NAME);
  if ($auth instanceof PDO) $context['db_overview'][] = db_overview($auth, DB_AUTH_NAME);

  // Dynamic summary for dashboard-style requests
  if (preg_match('/\b(summarize|summary|dashboard|overview|all data)\b/i', $q)) {
    $parts = [];
    foreach ($context['db_overview'] as $ov) {
      $label = $ov['schema'];
      $tables = $ov['tables'] ?? [];
      $top = [];
      foreach ($tables as $t) {
        $top[] = $t['name'] . ' (' . $t['rows'] . ')';
      }
      $parts[] = $label . ': ' . ($ov['table_count'] ?? 0) . ' tables; top activity: ' . (implode(', ', $top) ?: 'no tables');
    }
    $reply = "Here’s a live system snapshot across all databases: " . implode(' | ', $parts) . ".";
    echo json_encode(['ok'=>true,'reply'=>$reply,'ref_no'=>$shipment['ref_no'] ?? '']);
    exit;
  }

  $ollamaUrl = defined('OLLAMA_URL') ? OLLAMA_URL : 'http://localhost:11434';
  $model = defined('OLLAMA_MODEL') ? OLLAMA_MODEL : 'llama3.1';

  // Direct, accurate answers for common count questions
  if (preg_match('/\bhow many (suppliers|vendors)\b/i', $q)) {
    if ($proc instanceof PDO && table_exists($proc, DB_PROC_NAME, 'vendors')) {
      $cnt = fetch_rows($proc, "SELECT COUNT(*) AS total, SUM(status='approved') AS approved FROM vendors");
      $total = (int)($cnt[0]['total'] ?? 0);
      $approved = (int)($cnt[0]['approved'] ?? 0);
      $reply = "We currently have {$total} suppliers in the system, with {$approved} approved.";
      echo json_encode(['ok'=>true,'reply'=>$reply,'ref_no'=>$shipment['ref_no'] ?? '']);
      exit;
    }
  }

  if (preg_match('/\bhow many (rfqs|requests for quotation|quotation requests)\b/i', $q)) {
    if ($proc instanceof PDO && table_exists($proc, DB_PROC_NAME, 'rfqs')) {
      $cnt = fetch_rows($proc, "SELECT COUNT(*) AS total FROM rfqs");
      $total = (int)($cnt[0]['total'] ?? 0);
      $reply = "We currently have {$total} RFQ requests in the system.";
      echo json_encode(['ok'=>true,'reply'=>$reply,'ref_no'=>$shipment['ref_no'] ?? '']);
      exit;
    }
  }

  if (preg_match('/\bhow many shipments\b/i', $q) && $pdo instanceof PDO) {
    $cnt = fetch_rows($pdo, "SELECT COUNT(*) AS total FROM shipments");
    $total = (int)($cnt[0]['total'] ?? 0);
    $reply = "We currently have {$total} shipments in the system.";
    echo json_encode(['ok'=>true,'reply'=>$reply,'ref_no'=>$shipment['ref_no'] ?? '']);
    exit;
  }

  if (preg_match('/\bhow many (inventory categories|item categories|categories)\b/i', $q) && $pdo instanceof PDO) {
    if (table_exists($pdo, DB_WMS_NAME, 'inventory_categories')) {
      $cnt = fetch_rows($pdo, "SELECT COUNT(*) AS total FROM inventory_categories");
      $total = (int)($cnt[0]['total'] ?? 0);
      $reply = "We currently have {$total} inventory categories.";
      echo json_encode(['ok'=>true,'reply'=>$reply,'ref_no'=>$shipment['ref_no'] ?? '']);
      exit;
    }
  }

  if (preg_match('/\bhow many users\b/i', $q) && $auth instanceof PDO) {
    if (table_exists($auth, DB_AUTH_NAME, 'users')) {
      $cnt = fetch_rows($auth, "SELECT COUNT(*) AS total FROM users");
      $total = (int)($cnt[0]['total'] ?? 0);
      $reply = "We currently have {$total} users in the system.";
      echo json_encode(['ok'=>true,'reply'=>$reply,'ref_no'=>$shipment['ref_no'] ?? '']);
      exit;
    }
  }

  if (preg_match('/\b(list|show|who are|who\'?s|names of)\b.*\busers\b/i', $q) && $auth instanceof PDO) {
    if (table_exists($auth, DB_AUTH_NAME, 'users')) {
      $rows = fetch_rows($auth, "SELECT name FROM users ORDER BY id ASC LIMIT 20");
      $names = array_values(array_filter(array_map(fn($r)=>trim((string)$r['name']), $rows)));
      $reply = $names ? ("Here are the users on record: " . implode(', ', $names) . ".") : "There are no users on record.";
      echo json_encode(['ok'=>true,'reply'=>$reply,'ref_no'=>$shipment['ref_no'] ?? '']);
      exit;
    }
  }

  $history = [];
  if (!empty($_POST['history'])) {
    $h = json_decode((string)$_POST['history'], true);
    if (is_array($h)) {
      $history = array_slice($h, -12);
    }
  }
  $histText = strtolower(json_encode($history, JSON_UNESCAPED_SLASHES));

  if (preg_match('/\b(list|show|what are|specify)\b.*\b(inventory categories|item categories|categories)\b/i', $q) ||
      (preg_match('/\b(what are those|specify those|list them)\b/i', $q) && strpos($histText, 'inventory categories') !== false)) {
    if ($pdo instanceof PDO && table_exists($pdo, DB_WMS_NAME, 'inventory_categories')) {
      $rows = fetch_rows($pdo, "SELECT name FROM inventory_categories ORDER BY name");
      $names = array_values(array_filter(array_map(fn($r)=>trim((string)$r['name']), $rows)));
      $reply = $names ? ("Here are the inventory categories: " . implode(', ', $names) . ".") : "There are no inventory categories yet.";
      echo json_encode(['ok'=>true,'reply'=>$reply,'ref_no'=>$shipment['ref_no'] ?? '']);
      exit;
    }
  }

  $prompt = "You are a helpful TNVS system assistant. Respond like ChatGPT: natural, concise, and non-technical. " .
            "Do NOT mention schemas, tables, JSON, arrays, or field names. " .
            "Summarize the results in plain language and bullets only if helpful. " .
            "Never invent names, counts, or records. If data is missing, ask one short follow-up question.\n\n" .
            "Conversation so far (for context):\n" . json_encode($history, JSON_UNESCAPED_SLASHES) . "\n\n" .
            "User question: \"{$q}\"\n\n" .
            "Context data (for reference only, do not expose raw JSON):\n" . json_encode($context, JSON_UNESCAPED_SLASHES);

  $reply = null;
  $aiErr = '';
  $candidates = array_unique([
    rtrim($ollamaUrl, '/'),
    'http://127.0.0.1:11434',
    'http://localhost:11434',
  ]);
  $payload = [
    'model' => $model,
    'prompt' => $prompt,
    'stream' => false
  ];
  $reply = ollama_generate($payload, $candidates, $aiErr);

  if (!$reply) {
    $models = array_filter(ollama_list_models($candidates));
    if ($models) {
      $preferred = '';
      foreach ($models as $m) {
        if (stripos($m, 'llama3') === 0) { $preferred = $m; break; }
      }
      if ($preferred === '') $preferred = $models[0];
      $payload['model'] = $preferred;
      $reply = ollama_generate($payload, $candidates, $aiErr);
    }
  }

  if (!$reply) $aiErr = $aiErr ?: 'AI server not reachable.';

  if (!$reply) {
    http_response_code(503);
    echo json_encode([
      'ok' => false,
      'err' => $aiErr ?: 'AI not available'
    ]);
    exit;
  }

  echo json_encode([
    'ok' => true,
    'reply' => $reply,
    'ref_no' => $shipment['ref_no'] ?? ''
  ]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'err' => $e->getMessage()]);
}
