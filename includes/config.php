<?php

if (!defined('BASE_URL')) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';


    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $rootName  = 'TNVS_2.0_final'; // change only if your folder name changes locally

    $parts     = explode('/', trim($scriptDir, '/'));
    $idx       = array_search($rootName, $parts, true);

    if ($idx !== false) {

        $basePath = '/' . implode('/', array_slice($parts, 0, $idx + 1)) . '/';
    } else {
        $basePath = '/';
    }

    define('BASE_URL', $scheme . '://' . $host . $basePath);
}

if (!defined('APP_DEBUG')) define('APP_DEBUG', true);
if (APP_DEBUG) {
  ini_set('display_errors', '1');
  ini_set('display_startup_errors', '1');
  error_reporting(E_ALL);
}

// ---------- Defaults ----------
if (!defined('DB_HOST'))    define('DB_HOST', '127.0.0.1');
if (!defined('DB_PORT'))    define('DB_PORT', '3306');
if (!defined('DB_NAME'))    define('DB_NAME', 'tnvs');
if (!defined('DB_USER'))    define('DB_USER', 'root');
if (!defined('DB_PASS'))    define('DB_PASS', '');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

// ---------- Per-module DBs  ----------
/** AUTH (users/login) */
if (!defined('DB_AUTH_HOST')) define('DB_AUTH_HOST', DB_HOST);
if (!defined('DB_AUTH_NAME')) define('DB_AUTH_NAME', 'logi_auth');
if (!defined('DB_AUTH_USER')) define('DB_AUTH_USER', DB_USER);
if (!defined('DB_AUTH_PASS')) define('DB_AUTH_PASS', DB_PASS);

/** WMS */
if (!defined('DB_WMS_HOST')) define('DB_WMS_HOST', DB_HOST);
if (!defined('DB_WMS_NAME')) define('DB_WMS_NAME', 'logi_wms');
if (!defined('DB_WMS_USER')) define('DB_WMS_USER', DB_USER);
if (!defined('DB_WMS_PASS')) define('DB_WMS_PASS', DB_PASS);

/** PROCUREMENT */
if (!defined('DB_PROC_HOST')) define('DB_PROC_HOST', DB_HOST);
if (!defined('DB_PROC_NAME')) define('DB_PROC_NAME', 'logi_procurement');
if (!defined('DB_PROC_USER')) define('DB_PROC_USER', DB_USER);
if (!defined('DB_PROC_PASS')) define('DB_PROC_PASS', DB_PASS);

/** PLT (Project Tracking) */
if (!defined('DB_PLT_HOST')) define('DB_PLT_HOST', DB_HOST);
if (!defined('DB_PLT_NAME')) define('DB_PLT_NAME', 'logi_plt');
if (!defined('DB_PLT_USER')) define('DB_PLT_USER', DB_USER);
if (!defined('DB_PLT_PASS')) define('DB_PLT_PASS', DB_PASS);

/** ALMS (Assets/LMS) */
if (!defined('DB_ALMS_HOST')) define('DB_ALMS_HOST', DB_HOST);
if (!defined('DB_ALMS_NAME')) define('DB_ALMS_NAME', 'logi_alms');
if (!defined('DB_ALMS_USER')) define('DB_ALMS_USER', DB_USER);
if (!defined('DB_ALMS_PASS')) define('DB_ALMS_PASS', DB_PASS);

/** DOCS (Document Management) */
if (!defined('DB_DOCS_HOST')) define('DB_DOCS_HOST', DB_HOST);
if (!defined('DB_DOCS_NAME')) define('DB_DOCS_NAME', 'logi_docs');
if (!defined('DB_DOCS_USER')) define('DB_DOCS_USER', DB_USER);
if (!defined('DB_DOCS_PASS')) define('DB_DOCS_PASS', DB_PASS);
