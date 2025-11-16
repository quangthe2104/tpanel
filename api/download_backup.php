<?php
require_once __DIR__ . '/../includes/helpers/functions.php';
require_once __DIR__ . '/../includes/classes/HostingerFileManager.php';

// Tăng timeout cho file lớn (2GB có thể mất 10-20 phút tùy tốc độ mạng)
set_time_limit(0); // Không giới hạn thời gian
ini_set('max_execution_time', 0);
ini_set('memory_limit', '512M'); // Chỉ cần 512M vì dùng streaming

// Tắt tất cả output buffering ngay từ đầu
while (ob_get_level()) {
    ob_end_clean();
}

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

// Log download (async, không chặn)
$auth->logActivity($auth->getUserId(), $backup['website_id'], 'backup_downloaded', "Backup ID: $backupId");

// GỬI HEADERS NGAY LẬP TỨC để browser biết đang download
$filename = basename($backup['filename']);
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Accel-Buffering: no'); // Tắt buffering cho Nginx

// Dùng file size từ database nếu có (nhanh hơn query từ server)
if (!empty($backup['file_size']) && $backup['file_size'] > 0) {
    header('Content-Length: ' . $backup['file_size']);
}

// Flush headers ngay lập tức để browser biết đang download
flush();

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
        
        $filePath = false;
        
        // Tìm file path (không cần lấy size nữa vì đã có trong header)
        foreach ($pathsToTry as $tryPath) {
            // Sanitize path
            $tryPath = $security->sanitizePath($tryPath);
            // Chỉ cần check file exists, không cần lấy size
            if ($fileManager->fileExists($tryPath)) {
                $filePath = $tryPath;
                break;
            }
        }
        
        if ($filePath === false) {
            die('Không thể tìm thấy file backup trên server.');
        }
        
        // Stream file trực tiếp (không load vào memory)
        $bytesStreamed = $fileManager->streamFile($filePath);
        
        if ($bytesStreamed === false) {
            die('Lỗi khi stream file backup từ server.');
        }
        
        exit;
    } catch (Exception $e) {
        die('Lỗi khi tải file: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
    }
} else {
    // Fallback: download từ server Tpanel (nếu còn file tạm)
    if (!empty($backup['file_path']) && file_exists($backup['file_path'])) {
        $fileSize = filesize($backup['file_path']);
        header('Content-Length: ' . $fileSize);
        flush();
        
        // Dùng readfile để stream (PHP tự động stream, không load vào memory)
        readfile($backup['file_path']);
        exit;
    } else {
        die('File backup không tồn tại');
    }
}
