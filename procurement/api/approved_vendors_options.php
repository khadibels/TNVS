    <?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_login();
require_role(['admin','procurement_officer']);

$pdo = db('proc');
$sql = "SELECT id, COALESCE(legal_name, company_name) AS company_name
        FROM vendors
        WHERE status='approved'
        ORDER BY company_name";

$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
header('Content-Type: application/json');
echo json_encode(['rows'=>$rows]);
