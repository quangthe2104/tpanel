<?php
// Tắt tất cả output buffering NGAY TỪ ĐẦU (trước khi include bất cứ file nào)
while (ob_get_level()) {
    ob_end_clean();
}
if (ob_get_level() === 0) {
    // Đảm bảo không có output buffering
    ini_set('output_buffering', 0);
    ini_set('zlib.output_compression', 0);
}

// Tăng timeout cho file lớn (2GB có thể mất 10-20 phút tùy tốc độ mạng)
set_time_limit(0); // Không giới hạn thời gian
ini_set('max_execution_time', 0);
ini_set('memory_limit', '512M'); // Chỉ cần 512M vì dùng streaming

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

// Log download (async, không chặn)
$auth->logActivity($auth->getUserId(), $backup['website_id'], 'backup_downloaded', "Backup ID: $backupId");

// GỬI HEADERS NGAY LẬP TỨC để browser biết đang download
$filename = basename($backup['filename']);
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, must-revalidate, no-store');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Accel-Buffering: no'); // Tắt buffering cho Nginx
header('X-Accel-Limit-Rate: 0'); // Không giới hạn tốc độ cho Nginx
header('Connection: close'); // Đóng connection ngay sau khi xong

// Gửi một số data ngay để "đánh thức" browser (QUAN TRỌNG cho shared hosting)
// Browser sẽ bắt đầu download ngay khi nhận được data đầu tiên
$placeholderSize = 1024; // 1KB

// Set Content-Length = file size + placeholder (nếu có file size)
if (!empty($backup['file_size']) && $backup['file_size'] > 0) {
    header('Content-Length: ' . ($backup['file_size'] + $placeholderSize));
}

// Flush headers ngay lập tức - QUAN TRỌNG cho server online
if (ob_get_level() > 0) {
    @ob_end_flush();
}
flush();

// Gửi placeholder data ngay - LUÔN gửi để browser biết đang download
echo str_repeat(' ', $placeholderSize);
flush();

// Download từ server website
if (!empty($backup['remote_path'])) {
    try {
        // Tăng timeout cho kết nối SFTP/FTP
        ini_set('default_socket_timeout', 300);
        
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
            try {
                if ($fileManager->fileExists($tryPath)) {
                    $filePath = $tryPath;
                    break;
                }
            } catch (Exception $e) {
                // Tiếp tục thử path khác
                continue;
            }
        }
        
        if ($filePath === false) {
            // Nếu đã gửi headers và data, không thể die() được nữa
            // Gửi error message dưới dạng text
            echo "\n\nERROR: Không thể tìm thấy file backup trên server.";
            exit;
        }
        
        // Stream file trực tiếp (không load vào memory)
        $bytesStreamed = $fileManager->streamFile($filePath);
        
        if ($bytesStreamed === false) {
            echo "\n\nERROR: Lỗi khi stream file backup từ server.";
            exit;
        }
        
        exit;
    } catch (Exception $e) {
        // Log lỗi để debug
        error_log("Download backup error: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
        
        // Nếu đã gửi headers, không thể die() được nữa
        // Gửi error message dưới dạng text
        echo "\n\nERROR: Lỗi khi tải file: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        exit;
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
