<?php
require_once __DIR__ . "/../../../includes/config.php";
require_once __DIR__ . "/../../../includes/auth.php";
require_once __DIR__ . "/../../../includes/db.php";
header("Content-Type: application/json; charset=utf-8");

try {
  require_login("json");

  $pdo = db('wms');
  if (!$pdo instanceof PDO) throw new Exception('DB not available');

  $id = (int)($_GET['id'] ?? 0);
  $ref = trim((string)($_GET['ref_no'] ?? ''));
  if ($id <= 0 && $ref === '') throw new Exception('Missing id or ref_no');

  $where = $id > 0 ? 's.id=?' : 's.ref_no=?';
  $param = $id > 0 ? [$id] : [$ref];

  $q = $pdo->prepare("\
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
     WHERE $where
     LIMIT 1
  ");
  $q->execute($param);
  $shipment = $q->fetch(PDO::FETCH_ASSOC);
  if (!$shipment) throw new Exception('Shipment not found');

  $ev = $pdo->prepare("\
    SELECT DATE_FORMAT(event_time,'%Y-%m-%d %H:%i:%s') AS event_time, event_type, details
      FROM shipment_events
     WHERE shipment_id=?
     ORDER BY event_time DESC, id DESC
     LIMIT 5
  ");
  $ev->execute([(int)$shipment['id']]);
  $events = $ev->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $template = "Status: {$shipment['status']}. Ref: {$shipment['ref_no']}. " .
              "From: {$shipment['origin']}" . ($shipment['origin_address'] ? " ({$shipment['origin_address']})" : "") . ". " .
              "To: {$shipment['destination']}" . ($shipment['destination_address'] ? " ({$shipment['destination_address']})" : "") . ". " .
              "Carrier: " . ($shipment['carrier'] ?: '—') . ". " .
              "Contact: " . ($shipment['contact_name'] ?: '—') . " / " . ($shipment['contact_phone'] ?: '—') . ". " .
              "ETA: " . ($shipment['expected_delivery'] ?: '—') . ".";

  $ollamaUrl = defined('OLLAMA_URL') ? OLLAMA_URL : 'http://localhost:11434';
  $model = defined('OLLAMA_MODEL') ? OLLAMA_MODEL : 'llama3.1';

  $userQ = trim((string)($_GET['q'] ?? ''));
  $prompt = "Summarize this delivery in 1-2 sentences for a user asking: \"" . ($userQ !== '' ? $userQ : "Hello, may I know the status of this delivery?") . "\". " .
            "Be clear and concise.\n\n" .
            "Shipment:\n" .
            json_encode($shipment, JSON_UNESCAPED_SLASHES) . "\n" .
            "Recent events:\n" . json_encode($events, JSON_UNESCAPED_SLASHES);

  $summary = null;
  try {
    $payload = json_encode([
      'model' => $model,
      'prompt' => $prompt,
      'stream' => false
    ]);

    $ctx = stream_context_create([
      'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => $payload,
        'timeout' => 8
      ]
    ]);

    $resp = @file_get_contents(rtrim($ollamaUrl, '/') . '/api/generate', false, $ctx);
    if ($resp !== false) {
      $j = json_decode($resp, true);
      if (is_array($j) && isset($j['response'])) {
        $summary = trim((string)$j['response']);
      }
    }
  } catch (Throwable $e) {
    $summary = null;
  }

  if (!$summary) $summary = $template;

  echo json_encode([
    'ok' => true,
    'summary' => $summary,
    'shipment' => $shipment,
    'events' => $events,
    'ai' => $summary !== $template
  ]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'err' => $e->getMessage()]);
}
