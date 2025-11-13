<?php
/**
 * URL Friendly Router
 * Routes friendly URLs to actual PHP files
 */

// Enable error reporting for debugging (will be overridden by config.php)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors, log them instead
ini_set('log_errors', 1);

try {
    require_once __DIR__ . '/includes/helpers/functions.php';
} catch (Throwable $e) {
    error_log("Router error loading functions: " . $e->getMessage());
    http_response_code(500);
    die("Internal Server Error. Please check server logs.");
}

// Get the request URI
$requestUri = $_SERVER['REQUEST_URI'];

// Remove query string
$requestUri = parse_url($requestUri, PHP_URL_PATH);

// Get base path from BASE_URL
$baseUrl = parse_url(BASE_URL, PHP_URL_PATH);
if ($baseUrl && $baseUrl !== '/') {
    $baseUrl = rtrim($baseUrl, '/');
    if (strpos($requestUri, $baseUrl) === 0) {
        $requestUri = substr($requestUri, strlen($baseUrl));
    }
}

$requestUri = trim($requestUri, '/');
// Don't decode before splitting - we need to preserve encoded slashes
// Split first, then decode each segment individually
$segments = $requestUri ? explode('/', $requestUri) : [];
// Decode each segment individually (but preserve structure)
foreach ($segments as $i => $segment) {
    $segments[$i] = urldecode($segment);
}

// Handle simple routes first
if (empty($segments)) {
    require __DIR__ . '/index.php';
    exit;
}

$route = $segments[0];

// Simple routes
if ($route === 'login') {
    require __DIR__ . '/login.php';
    exit;
}

if ($route === 'logout') {
    require __DIR__ . '/logout.php';
    exit;
}

if ($route === 'change-password') {
    require __DIR__ . '/app/change_password.php';
    exit;
}

if ($route === 'my-websites') {
    require __DIR__ . '/app/my_websites.php';
    exit;
}

// Handle AJAX endpoints
if (!empty($segments) && $segments[0] === 'ajax') {
    $ajaxFile = $segments[1] ?? '';
    $ajaxFiles = [
        'check-backup-status' => __DIR__ . '/api/ajax_check_backup_status.php',
        'get-disk-usage' => __DIR__ . '/api/ajax_get_disk_usage.php',
        'update-storage' => __DIR__ . '/api/ajax_update_storage.php',
        'get-user-permissions' => __DIR__ . '/api/ajax_get_user_permissions.php',
    ];
    
    if (isset($ajaxFiles[$ajaxFile])) {
        // Set GET parameters from URL segments or query string
        if (isset($segments[2])) {
            $_GET['id'] = $segments[2];
        }
        // For get-user-permissions, use user_id from query string
        if ($ajaxFile === 'get-user-permissions' && isset($_GET['user_id'])) {
            // Already set from query string
        }
        require $ajaxFiles[$ajaxFile];
        exit;
    }
}

// Handle website routes: /website/{id}/{tab}/{path}
if (!empty($segments) && $segments[0] === 'website') {
    if (isset($segments[1])) {
        $_GET['id'] = $segments[1];
        
        if (isset($segments[2])) {
            $_GET['tab'] = $segments[2];
            
            // Handle path segments - join all remaining segments
            // Note: segments are already decoded individually above
            if (isset($segments[3])) {
                // Check if it's edit:path format
                if (strpos($segments[3], 'edit:') === 0) {
                    $_GET['action'] = 'edit';
                    $path = substr($segments[3], 5); // Remove 'edit:' prefix
                    // Join remaining segments if any
                    if (isset($segments[4])) {
                        $path .= '/' . implode('/', array_slice($segments, 4));
                    }
                    $_GET['path'] = $path;
                } else {
                    // Regular path - join all remaining segments
                    $path = implode('/', array_slice($segments, 3));
                    $_GET['path'] = $path;
                }
            }
        }
    }
    require __DIR__ . '/app/website_manage.php';
    exit;
}

// Handle admin routes: /admin/websites/edit/{id} or /admin/users/edit/{id}
if (!empty($segments) && $segments[0] === 'admin') {
    if (isset($segments[1]) && $segments[1] === 'websites') {
        if (isset($segments[2]) && $segments[2] === 'edit' && isset($segments[3])) {
            $_GET['edit'] = $segments[3];
        }
        require __DIR__ . '/app/admin_websites.php';
        exit;
    }
    
    if (isset($segments[1]) && $segments[1] === 'users') {
        if (isset($segments[2]) && $segments[2] === 'edit' && isset($segments[3])) {
            $_GET['edit'] = $segments[3];
        } elseif (isset($segments[2]) && $segments[2] === 'assign' && isset($segments[3])) {
            $_GET['assign'] = $segments[3];
        }
        require __DIR__ . '/app/admin_users.php';
        exit;
    }
    
    // Default admin page
    require __DIR__ . '/app/admin_websites.php';
    exit;
}

// Handle backup download: /backup/{id}/download
if (!empty($segments) && $segments[0] === 'backup' && isset($segments[1])) {
    $_GET['id'] = $segments[1];
    require __DIR__ . '/api/download_backup.php';
    exit;
}

// If no route matched, show 404 or index
require __DIR__ . '/index.php';

