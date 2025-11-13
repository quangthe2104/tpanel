<?php
/**
 * URL Friendly Router
 * Routes friendly URLs to actual PHP files
 */

require_once 'includes/helpers/functions.php';

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
    require 'index.php';
    exit;
}

$route = $segments[0];

// Simple routes
if ($route === 'login') {
    require 'login.php';
    exit;
}

if ($route === 'logout') {
    require 'logout.php';
    exit;
}

if ($route === 'change-password') {
    require 'app/change_password.php';
    exit;
}

if ($route === 'my-websites') {
    require 'app/my_websites.php';
    exit;
}

// Handle AJAX endpoints
if (!empty($segments) && $segments[0] === 'ajax') {
    $ajaxFile = $segments[1] ?? '';
    $ajaxFiles = [
        'check-backup-status' => 'api/ajax_check_backup_status.php',
        'get-disk-usage' => 'api/ajax_get_disk_usage.php',
        'update-storage' => 'api/ajax_update_storage.php',
        'get-user-permissions' => 'api/ajax_get_user_permissions.php',
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
    require 'app/website_manage.php';
    exit;
}

// Handle admin routes: /admin/websites/edit/{id} or /admin/users/edit/{id}
if (!empty($segments) && $segments[0] === 'admin') {
    if (isset($segments[1]) && $segments[1] === 'websites') {
        if (isset($segments[2]) && $segments[2] === 'edit' && isset($segments[3])) {
            $_GET['edit'] = $segments[3];
        }
        require 'app/admin_websites.php';
        exit;
    }
    
    if (isset($segments[1]) && $segments[1] === 'users') {
        if (isset($segments[2]) && $segments[2] === 'edit' && isset($segments[3])) {
            $_GET['edit'] = $segments[3];
        } elseif (isset($segments[2]) && $segments[2] === 'assign' && isset($segments[3])) {
            $_GET['assign'] = $segments[3];
        }
        require 'app/admin_users.php';
        exit;
    }
    
    // Default admin page
    require 'app/admin_websites.php';
    exit;
}

// Handle backup download: /backup/{id}/download
if (!empty($segments) && $segments[0] === 'backup' && isset($segments[1])) {
    $_GET['id'] = $segments[1];
    require 'api/download_backup.php';
    exit;
}

// If no route matched, show 404 or index
require 'index.php';

