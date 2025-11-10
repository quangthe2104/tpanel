<?php
// Base configuration
define('BASE_URL', 'http://localhost/tpanel/');
define('ROOT_PATH', dirname(__DIR__));

// Session configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS

// Timezone
date_default_timezone_set('Asia/Ho_Chi_Minh');

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Backup settings
define('BACKUP_DIR', ROOT_PATH . '/backups/');
define('MAX_BACKUP_SIZE', 500 * 1024 * 1024); // 500MB

// File upload settings
define('MAX_UPLOAD_SIZE', 100 * 1024 * 1024); // 100MB
