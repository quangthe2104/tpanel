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

// Error reporting
error_reporting(E_ALL);
// Set display_errors = 1 để debug, = 0 trong production
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', ROOT_PATH . '/error_log.txt');

// Backup settings
define('BACKUP_DIR', ROOT_PATH . '/backups/');
define('MAX_BACKUP_SIZE', 500 * 1024 * 1024); // 500MB

// File upload settings
define('MAX_UPLOAD_SIZE', 100 * 1024 * 1024); // 100MB
