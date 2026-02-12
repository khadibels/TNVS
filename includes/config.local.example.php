<?php
// Copy this file to includes/config.local.php on each environment,
// then set real credentials (do not commit secrets).

// Optional base path when app is hosted in a subfolder.
// Example: define('APP_BASE_PATH', 'TNVS_2.0_final');
// define('APP_BASE_PATH', '');

// Shared fallback credentials
define('DB_HOST', '127.0.0.1');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');

// Per-module DB names (and optional per-module users/passwords)
define('DB_AUTH_NAME', 'logi_auth');
define('DB_WMS_NAME', 'logi_wms');
define('DB_PROC_NAME', 'logi_procurement');
define('DB_PLT_NAME', 'logi_plt');
define('DB_ALMS_NAME', 'logi_alms');
define('DB_DOCS_NAME', 'logi_docs');

// Optional per-module creds if different from DB_USER/DB_PASS
// define('DB_WMS_USER', 'your_wms_user');
// define('DB_WMS_PASS', 'your_wms_password');

// LLM endpoint. On shared hosting this is often unavailable unless you run it yourself.
// If unavailable, ai_chat.php now falls back to direct DB-based replies.
define('OLLAMA_URL', 'http://127.0.0.1:11434');
define('OLLAMA_MODEL', 'llama3:latest');
