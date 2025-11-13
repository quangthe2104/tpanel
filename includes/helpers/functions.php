<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Security.php';
require_once __DIR__ . '/url_helper.php';

function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

function formatDate($date) {
    return date('d/m/Y H:i', strtotime($date));
}

function escape($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

function formatActivityAction($action, $description = null) {
    $actionMap = [
        'login' => 'Đăng nhập',
        'logout' => 'Đăng xuất',
        'change_password' => 'Đổi mật khẩu',
        'file_operation' => 'Thao tác file',
        'file_moved_to_trash' => 'Chuyển vào thùng rác',
        'backup_created' => 'Tạo backup',
        'backup_deleted' => 'Xóa backup',
        'backup_downloaded' => 'Tải backup',
        'website_added' => 'Thêm website',
        'website_updated' => 'Cập nhật website',
        'website_deleted' => 'Xóa website',
        'database_query' => 'Truy vấn database',
        'user_added' => 'Thêm người dùng',
        'user_updated' => 'Cập nhật người dùng',
        'user_deleted' => 'Xóa người dùng',
        'permission_assigned' => 'Phân quyền',
    ];
    
    $actionText = $actionMap[$action] ?? $action;
    
    // Format description nếu có
    if ($description) {
        // Xử lý các mô tả đặc biệt
        if (strpos($description, 'Backup type:') !== false) {
            $type = str_replace('Backup type: ', '', $description);
            $typeMap = ['full' => 'Full', 'database' => 'Database', 'files' => 'Files'];
            $typeText = $typeMap[$type] ?? $type;
            return $actionText . ': ' . $typeText;
        }
        
        if (strpos($description, 'Moved to trash:') !== false) {
            $path = str_replace('Moved to trash: ', '', $description);
            $filename = basename($path);
            return $actionText . ': ' . escape($filename);
        }
        
        if (strpos($description, 'Website:') !== false) {
            $name = str_replace('Website: ', '', $description);
            return $actionText . ': ' . escape($name);
        }
        
        if (strpos($description, 'User:') !== false) {
            $username = str_replace('User: ', '', $description);
            return $actionText . ': ' . escape($username);
        }
        
        if (strpos($description, 'Backup ID:') !== false) {
            return $actionText;
        }
        
        if (strpos($description, 'User ID:') !== false) {
            return $actionText;
        }
        
        // Mô tả mặc định
        if (in_array($description, ['User logged in', 'User logged out', 'User changed password', 'File operation performed', 'SQL SELECT query executed'])) {
            return $actionText;
        }
        
        return $actionText . ': ' . escape($description);
    }
    
    return $actionText;
}
