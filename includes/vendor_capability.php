<?php

function ensure_vendor_capability_tables(PDO $proc): void
{
    $proc->exec("
        CREATE TABLE IF NOT EXISTS vendor_documents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vendor_id INT NOT NULL,
            doc_type VARCHAR(50) NOT NULL,
            category VARCHAR(100) NULL,
            file_path VARCHAR(255) NULL,
            url VARCHAR(255) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            review_note VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reviewed_at DATETIME NULL,
            INDEX idx_vendor (vendor_id),
            INDEX idx_doc_type (doc_type),
            INDEX idx_category (category)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $proc->exec("
        CREATE TABLE IF NOT EXISTS vendor_category_capability (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vendor_id INT NOT NULL,
            category VARCHAR(100) NOT NULL,
            status VARCHAR(20) NOT NULL,
            reason VARCHAR(255) NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_vendor_category (vendor_id, category)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $proc->exec("
        CREATE TABLE IF NOT EXISTS rfq_vendor_exclusions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            rfq_id INT NOT NULL,
            vendor_id INT NOT NULL,
            reason VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_rfq_vendor (rfq_id, vendor_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function get_all_categories(PDO $proc): array
{
    $categories = [];
    try {
        $wms = function_exists('db') ? db('wms') : null;
        if ($wms instanceof PDO) {
            $cats = $wms->query("SELECT name FROM inventory_categories ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($cats as $c) {
                $c = trim((string)$c);
                if ($c !== '') $categories[] = $c;
            }
        }
    } catch (Throwable $e) { }

    // fallback: use categories from vendor_documents
    try {
        $rows = $proc->query("SELECT DISTINCT category FROM vendor_documents WHERE category IS NOT NULL AND category<>''")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $c) {
            $c = trim((string)$c);
            if ($c !== '') $categories[] = $c;
        }
    } catch (Throwable $e) { }

    $categories = array_values(array_unique($categories));
    sort($categories);
    return $categories;
}

function recompute_vendor_capability(PDO $proc, int $vendorId, array $allCategories): void
{
    if ($vendorId <= 0 || empty($allCategories)) return;

    $docs = $proc->prepare("
        SELECT doc_type, category, status
          FROM vendor_documents
         WHERE vendor_id=?
    ");
    $docs->execute([$vendorId]);
    $rows = $docs->fetchAll(PDO::FETCH_ASSOC);

    $byCat = [];
    foreach ($rows as $r) {
        $cat = trim((string)($r['category'] ?? ''));
        if ($cat === '') continue;
        $byCat[$cat][] = $r;
    }

    $ins = $proc->prepare("
        INSERT INTO vendor_category_capability (vendor_id, category, status, reason, updated_at)
        VALUES (?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE status=VALUES(status), reason=VALUES(reason), updated_at=NOW()
    ");

    foreach ($allCategories as $cat) {
        $cat = trim((string)$cat);
        if ($cat === '') continue;
        $docsForCat = $byCat[$cat] ?? [];

        $hasApprovedReceipt = false;
        $hasApprovedCatalog = false;
        foreach ($docsForCat as $d) {
            if (strtolower((string)$d['status']) !== 'approved') continue;
            $type = strtolower((string)$d['doc_type']);
            if (in_array($type, ['delivery_receipt','invoice'], true)) $hasApprovedReceipt = true;
            if (in_array($type, ['catalog','website'], true)) $hasApprovedCatalog = true;
        }

        if ($hasApprovedReceipt) {
            $status = 'verified';
            $reason = 'Approved delivery receipt/invoice';
        } elseif ($hasApprovedCatalog) {
            $status = 'unverified';
            $reason = 'Approved catalog or website link';
        } else {
            $status = 'not_capable';
            $reason = 'No approved documents for this category';
        }

        $ins->execute([$vendorId, $cat, $status, $reason]);
    }
}
