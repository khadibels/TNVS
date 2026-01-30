<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // Allow access from other origins (adjust as needed for security)
header('Access-Control-Allow-Methods: GET');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php'; // Add auth
require_once __DIR__ . '/../../includes/db.php';

try {
    // Auth Check: Token OR Session Role
    $token = $_GET['token'] ?? '';
    if ($token !== 'core1_integration_log1_2026') {
        // If token allows bypass, we skip this. If not, we enforce login & role.
        if (function_exists('require_login')) require_login();
        // Assuming 'vendor_manager' or 'admin' is required, consistent with other vendor files
        if (function_exists('require_role')) require_role(['admin', 'vendor_manager']); 
    }

    // connect to procurement database
    $pdo = db('proc');
    if (!$pdo) {
        throw new Exception('Database connection failed');
    }

    // Select basic vendor info
    $sql = "SELECT id, company_name, contact_person, email, phone, address, status, created_at 
            FROM vendors 
            ORDER BY company_name ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $vendors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'count' => count($vendors),
        'data' => $vendors
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
