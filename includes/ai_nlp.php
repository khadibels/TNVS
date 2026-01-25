<?php

function ai_detect_delivery_method(string $text): string
{
    $t = strtolower($text);
    $pickup = ['pick up', 'pickup', 'collect', 'collection', 'dispatch driver', 'send driver', 'request pickup'];
    $deliver = ['deliver', 'delivery', 'drop off', 'ship to', 'send to warehouse'];
    foreach ($pickup as $k) {
        if (strpos($t, $k) !== false) return 'pickup';
    }
    foreach ($deliver as $k) {
        if (strpos($t, $k) !== false) return 'vendor_deliver';
    }
    return 'unknown';
}

function ai_summarize_note(string $text): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text));
    if ($text === '') return '';
    $parts = preg_split('/(?<=[.!?])\s+/', $text);
    $summary = $parts[0] ?? $text;
    return mb_substr($summary, 0, 200);
}

function ai_call_corenlp(string $text): ?array
{
    $url = rtrim(CORENLP_URL, '/');
    if ($url === '') return null;

    $props = json_encode([
        'annotators' => 'tokenize,ssplit,pos,lemma,ner',
        'outputFormat' => 'json'
    ]);

    $ch = curl_init($url . '/?properties=' . urlencode($props));
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $text);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 4);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/plain; charset=utf-8']);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $code < 200 || $code >= 300) return null;

    $json = json_decode($resp, true);
    return is_array($json) ? $json : null;
}

function ai_extract_delivery_details(string $text): array
{
    $result = [
        'dates' => [],
        'times' => [],
        'locations' => []
    ];

    $nlp = ai_call_corenlp($text);
    if ($nlp && !empty($nlp['sentences'])) {
        foreach ($nlp['sentences'] as $s) {
            foreach ($s['tokens'] ?? [] as $tok) {
                $ner = $tok['ner'] ?? '';
                $word = trim((string)($tok['word'] ?? ''));
                if ($word === '') continue;
                if ($ner === 'DATE') $result['dates'][] = $word;
                if ($ner === 'TIME') $result['times'][] = $word;
                if ($ner === 'LOCATION' || $ner === 'CITY' || $ner === 'STATE_OR_PROVINCE' || $ner === 'COUNTRY') {
                    $result['locations'][] = $word;
                }
            }
        }
    }

    // fallback: basic regex for dates/times if CoreNLP unavailable
    if (!$result['dates']) {
        preg_match_all('/\\b(\\d{1,2}[\\/\\-]\\d{1,2}[\\/\\-]\\d{2,4})\\b/', $text, $m);
        if (!empty($m[1])) $result['dates'] = $m[1];
    }
    if (!$result['times']) {
        preg_match_all('/\\b(\\d{1,2}:\\d{2}\\s?(AM|PM|am|pm)?)\\b/', $text, $m);
        if (!empty($m[1])) $result['times'] = $m[1];
    }

    $result['dates'] = array_values(array_unique($result['dates']));
    $result['times'] = array_values(array_unique($result['times']));
    $result['locations'] = array_values(array_unique($result['locations']));

    return $result;
}

function ai_analyze_delivery_note(string $text): array
{
    $details = ai_extract_delivery_details($text);
    return [
        'method' => ai_detect_delivery_method($text),
        'summary' => ai_summarize_note($text),
        'dates' => $details['dates'],
        'times' => $details['times'],
        'locations' => $details['locations'],
    ];
}
