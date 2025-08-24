<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/config.php';
require_once __DIR__.'/../../includes/auth.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$st=$pdo->query("SELECT id, name FROM departments WHERE is_active=1 ORDER BY name");
echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
