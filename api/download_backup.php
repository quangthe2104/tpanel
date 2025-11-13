<?php
require_once __DIR__ . '/../includes/helpers/functions.php';
require_once __DIR__ . '/../includes/classes/HostingerFileManager.php';

$auth = new Auth();
$auth->requireLogin();

$security = Security::getInstance();
$backupId = $security->validateInt($_GET['id'] ?? 0, 1);
if (!$backupId) {
    die('Backup ID không hợp lệ');
}

$db = Database::getInstance();

$backup = $db->fetchOne("SELECT b.*, w.sftp_host, w.sftp_username, w.sftp_password, w.sftp_port, w.connection_type, w.path 
                         FROM backups b 
                         LEFT JOIN websites w ON b.website_id = w.id 
                         WHERE b.id = ?", [$backupId]);

if (!$backup) {
    die('Backup không tồn tại');
}

// Check permission
if (!$auth->isAdmin() && $backup['user_id'] != $auth->getUserId()) {
    die('Bạn không có quyền download backup này');
}

// Kiểm tra file đã hết hạn chưa
if ($backup['expires_at'] && strtotime($backup['expires_at']) < time()) {
    die('File backup đã hết hạn và đã bị xóa');
}

// Log download
$auth->logActivity($auth->getUserId(), $backup['website_id'], 'backup_downloaded', "Backup ID: $backupId");

// Download từ server website
if (!empty($backup['remote_path'])) {
    try {
        $fileManager = new HostingerFileManager(
            $backup['sftp_host'],
            $backup['sftp_username'],
            $backup['sftp_password'],
            $backup['path'],
            $backup['connection_type'],
            $backup['sftp_port']
        );
        
        // Thử nhiều cách path khác nhau
        $pathsToTry = [
            $backup['remote_path'], // Path gốc
            ltrim($backup['remote_path'], '/'), // Bỏ leading slash
            '.tpanel/backups/' . basename($backup['remote_path']), // Relative path
            basename($backup['remote_path']) // Chỉ filename
        ];
        
        $content = false;
        
        foreach ($pathsToTry as $tryPath) {
            // Sanitize path
            $tryPath = $security->sanitizePath($tryPath);
            $content = $fileManager->getFileContent($tryPath);
            if ($content !== false) {
                break;
            }
        }
        
        if ($content === false) {
            die('Không thể tải file backup từ server.');
        }
        
        // Download file
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($backup['filename']) . '"');
        header('Content-Length: ' . strlen($content));
        echo $content;
        exit;
    } catch (Exception $e) {
        die('Lỗi khi tải file: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
    }
} else {
    // Fallback: download từ server Tpanel (nếu còn file tạm)
    if (!empty($backup['file_path']) && file_exists($backup['file_path'])) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($backup['file_path']) . '"');
        header('Content-Length: ' . filesize($backup['file_path']));
        readfile($backup['file_path']);
        exit;
    } else {
        die('File backup không tồn tại');
    }
}
