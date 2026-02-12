<?php
// ===================== Helpers =====================
if (!function_exists('cfg_env')) {
    function cfg_env(string $key, ?string $default = null): ?string
    {
        $v = getenv($key);
        if ($v !== false && $v !== '') return (string)$v;
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') return (string)$_ENV[$key];
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return (string)$_SERVER[$key];
        return $default;
    }
}

// ===================== BASE_URL =====================
if (!defined('BASE_URL')) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptPath = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/'));

    $basePathOverride = trim((string)cfg_env('APP_BASE_PATH', ''), " \t\n\r\0\x0B/");
    if ($basePathOverride !== '') {
        $basePath = '/' . $basePathOverride . '/';
    } else {
        $rootName = basename(dirname(__DIR__)); // project root folder name
        $basePath = '/';
        if ($rootName !== '' && preg_match('#/' . preg_quote($rootName, '#') . '(/|$)#', $scriptPath)) {
            $basePath = '/' . $rootName . '/';
        }
    }

    define('BASE_URL', $scheme . '://' . $host . $basePath);
}

// ===================== Debug (safe during build) =====================
if (!defined('APP_DEBUG')) define('APP_DEBUG', true);
if (APP_DEBUG) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

// ===================== Generic defaults (safe in repo) =====================
defined('DB_HOST')    || define('DB_HOST',    cfg_env('DB_HOST', 'localhost'));
defined('DB_PORT')    || define('DB_PORT',    '3306');
defined('DB_NAME')    || define('DB_NAME',    cfg_env('DB_NAME', 'tnvs'));
defined('DB_USER')    || define('DB_USER',    cfg_env('DB_USER', 'root'));
defined('DB_PASS')    || define('DB_PASS',    cfg_env('DB_PASS', ''));
defined('DB_CHARSET') || define('DB_CHARSET', 'utf8mb4');

// ===================== Machine-specific overrides =====================
$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    require_once $local; 
}

// ===================== Per-module DB settings (only if not set by local) =================
defined('DB_AUTH_HOST') || define('DB_AUTH_HOST', cfg_env('DB_AUTH_HOST', DB_HOST));
defined('DB_AUTH_NAME') || define('DB_AUTH_NAME', cfg_env('DB_AUTH_NAME', 'logi_auth'));
defined('DB_AUTH_USER') || define('DB_AUTH_USER', cfg_env('DB_AUTH_USER', DB_USER));
defined('DB_AUTH_PASS') || define('DB_AUTH_PASS', cfg_env('DB_AUTH_PASS', DB_PASS));

defined('DB_WMS_HOST')  || define('DB_WMS_HOST',  cfg_env('DB_WMS_HOST', DB_HOST));
defined('DB_WMS_NAME')  || define('DB_WMS_NAME',  cfg_env('DB_WMS_NAME', 'logi_wms'));
defined('DB_WMS_USER')  || define('DB_WMS_USER',  cfg_env('DB_WMS_USER', DB_USER));
defined('DB_WMS_PASS')  || define('DB_WMS_PASS',  cfg_env('DB_WMS_PASS', DB_PASS));

defined('DB_PROC_HOST') || define('DB_PROC_HOST', cfg_env('DB_PROC_HOST', DB_HOST));
defined('DB_PROC_NAME') || define('DB_PROC_NAME', cfg_env('DB_PROC_NAME', 'logi_procurement'));
defined('DB_PROC_USER') || define('DB_PROC_USER', cfg_env('DB_PROC_USER', DB_USER));
defined('DB_PROC_PASS') || define('DB_PROC_PASS', cfg_env('DB_PROC_PASS', DB_PASS));

defined('DB_PLT_HOST')  || define('DB_PLT_HOST',  cfg_env('DB_PLT_HOST', DB_HOST));
defined('DB_PLT_NAME')  || define('DB_PLT_NAME',  cfg_env('DB_PLT_NAME', 'logi_plt'));
defined('DB_PLT_USER')  || define('DB_PLT_USER',  cfg_env('DB_PLT_USER', DB_USER));
defined('DB_PLT_PASS')  || define('DB_PLT_PASS',  cfg_env('DB_PLT_PASS', DB_PASS));

defined('DB_ALMS_HOST') || define('DB_ALMS_HOST', cfg_env('DB_ALMS_HOST', DB_HOST));
defined('DB_ALMS_NAME') || define('DB_ALMS_NAME', cfg_env('DB_ALMS_NAME', 'logi_alms'));
defined('DB_ALMS_USER') || define('DB_ALMS_USER', cfg_env('DB_ALMS_USER', DB_USER));
defined('DB_ALMS_PASS') || define('DB_ALMS_PASS', cfg_env('DB_ALMS_PASS', DB_PASS));

defined('DB_DOCS_HOST') || define('DB_DOCS_HOST', cfg_env('DB_DOCS_HOST', DB_HOST));
defined('DB_DOCS_NAME') || define('DB_DOCS_NAME', cfg_env('DB_DOCS_NAME', 'logi_docs'));
defined('DB_DOCS_USER') || define('DB_DOCS_USER', cfg_env('DB_DOCS_USER', DB_USER));
defined('DB_DOCS_PASS') || define('DB_DOCS_PASS', cfg_env('DB_DOCS_PASS', DB_PASS));

// ===================== Ollama (local LLM) =====================
defined('OLLAMA_URL')   || define('OLLAMA_URL', cfg_env('OLLAMA_URL', 'http://localhost:11434'));
defined('OLLAMA_MODEL') || define('OLLAMA_MODEL', cfg_env('OLLAMA_MODEL', 'llama3:latest'));

// ===================== AI / NLP (Java CoreNLP Server) =====================
defined('CORENLP_URL') || define('CORENLP_URL', 'http://localhost:9000');
