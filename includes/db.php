<?php
/**
 * Unified PDO connector with simple pooling.
 *
 * Usage:
 *   $pdo = db('wms');   // warehousing
 *   $pdo = db('auth');  // authentication
 *   $pdo = db('proc');  // procurement
 */

function db(string $which = 'wms'): ?PDO
{
    static $pool = [];

    if (isset($pool[$which]) && $pool[$which] instanceof PDO) {
        return $pool[$which];
    }

    // Build connection map (pulls constants from includes/config.php)
    $map = [
        'auth' => [
            'host'    => defined('DB_AUTH_HOST') ? DB_AUTH_HOST : (defined('DB_HOST') ? DB_HOST : '127.0.0.1'),
            'name'    => defined('DB_AUTH_NAME') ? DB_AUTH_NAME : (defined('DB_NAME') ? DB_NAME : ''),
            'user'    => defined('DB_AUTH_USER') ? DB_AUTH_USER : (defined('DB_USER') ? DB_USER : 'root'),
            'pass'    => defined('DB_AUTH_PASS') ? DB_AUTH_PASS : (defined('DB_PASS') ? DB_PASS : ''),
            'charset' => defined('DB_CHARSET')   ? DB_CHARSET   : 'utf8mb4',
        ],
        'wms' => [
            'host'    => defined('DB_WMS_HOST') ? DB_WMS_HOST : (defined('DB_HOST') ? DB_HOST : '127.0.0.1'),
            'name'    => defined('DB_WMS_NAME') ? DB_WMS_NAME : (defined('DB_NAME') ? DB_NAME : ''),
            'user'    => defined('DB_WMS_USER') ? DB_WMS_USER : (defined('DB_USER') ? DB_USER : 'root'),
            'pass'    => defined('DB_WMS_PASS') ? DB_WMS_PASS : (defined('DB_PASS') ? DB_PASS : ''),
            'charset' => defined('DB_CHARSET')  ? DB_CHARSET  : 'utf8mb4',
        ],
        'proc' => [
            'host'    => defined('DB_PROC_HOST') ? DB_PROC_HOST : (defined('DB_HOST') ? DB_HOST : '127.0.0.1'),
            'name'    => defined('DB_PROC_NAME') ? DB_PROC_NAME : (defined('DB_NAME') ? DB_NAME : ''),
            'user'    => defined('DB_PROC_USER') ? DB_PROC_USER : (defined('DB_USER') ? DB_USER : 'root'),
            'pass'    => defined('DB_PROC_PASS') ? DB_PROC_PASS : (defined('DB_PASS') ? DB_PASS : ''),
            'charset' => defined('DB_CHARSET')   ? DB_CHARSET   : 'utf8mb4',
        ],
    ];

    if (!isset($map[$which])) {
        if (defined('APP_DEBUG') && APP_DEBUG) error_log("[DB] Unknown connection key: {$which}");
        return null;
    }

    $c = $map[$which];

    try {
        $dsn = "mysql:host={$c['host']};dbname={$c['name']};charset={$c['charset']}";
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return $pool[$which] = $pdo;
    } catch (Throwable $e) {
        if (defined('APP_DEBUG') && APP_DEBUG) {
            error_log("[DB] Connect '{$which}' failed: " . $e->getMessage());
        }
        return null;
    }
}
