<?php
/**
 * Public API: returns open RFQs for the vendor landing page.
 * No authentication required — only non-sensitive summary data is exposed.
 */
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = db('proc');
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Database unavailable');
    }

    $sql = "
        SELECT r.id,
               r.rfq_no,
               r.title,
               r.due_at,
               COUNT(ri.id) AS item_count
        FROM rfqs r
        LEFT JOIN rfq_items ri ON ri.rfq_id = r.id
        WHERE r.status = 'sent'
          AND r.due_at > NOW()
        GROUP BY r.id
        ORDER BY r.due_at ASC
        LIMIT 12
    ";

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok' => true, 'data' => $rows]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
