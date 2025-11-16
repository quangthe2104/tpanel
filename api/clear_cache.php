<?php
/**
 * Clear PHP OPcache
 */

require_once __DIR__ . '/../includes/helpers/functions.php';

$auth = new Auth();
$auth->requireLogin();

if (!$auth->isAdmin()) {
    die('Chỉ admin mới có quyền xóa cache');
}

echo "<h2>Clear PHP Cache</h2>";

// Clear opcache
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "<p>✓ OPcache đã được xóa</p>";
} else {
    echo "<p>✗ OPcache không được bật</p>";
}

// Clear realpath cache
clearstatcache(true);
echo "<p>✓ Realpath cache đã được xóa</p>";

echo "<hr>";
echo "<p>Current timezone: <strong>" . date_default_timezone_get() . "</strong></p>";
echo "<p>Current time: <strong>" . date('Y-m-d H:i:s') . "</strong></p>";

echo "<hr>";
echo "<p><a href='" . BASE_URL . "'>← Quay lại trang chủ</a></p>";

