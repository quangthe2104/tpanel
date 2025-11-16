<?php
// Hiển thị lỗi để debug (chỉ trong development, nên tắt trong production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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

// Tạo file log riêng để debug
$logFile = __DIR__ . '/../logs/download_debug.log';
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}

function debugLog($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

try {
    require_once __DIR__ . '/../includes/helpers/functions.php';
    require_once __DIR__ . '/../includes/classes/HostingerFileManager.php';

    $auth = new Auth();
    $auth->requireLogin();

    $security = Security::getInstance();
    $backupId = $security->validateInt($_GET['id'] ?? 0, 1);
    if (!$backupId) {
        throw new Exception('Backup ID không hợp lệ');
    }
} catch (Exception $e) {
    debugLog("Download backup initialization error: " . $e->getMessage());
    die('Lỗi khởi tạo: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

$db = Database::getInstance();

// Thử lấy cột url, nếu không có thì bỏ qua (để tương thích với DB chưa migrate)
try {
    $backup = $db->fetchOne("SELECT b.*, w.sftp_host, w.sftp_username, w.sftp_password, w.sftp_port, w.connection_type, w.path, w.url, w.domain 
                             FROM backups b 
                             LEFT JOIN websites w ON b.website_id = w.id 
                             WHERE b.id = ?", [$backupId]);
} catch (Exception $e) {
    // Fallback nếu cột url chưa tồn tại
    $backup = $db->fetchOne("SELECT b.*, w.sftp_host, w.sftp_username, w.sftp_password, w.sftp_port, w.connection_type, w.path, w.domain 
                             FROM backups b 
                             LEFT JOIN websites w ON b.website_id = w.id 
                             WHERE b.id = ?", [$backupId]);
    // Tạo url từ domain nếu có
    if (!empty($backup['domain'])) {
        $backup['url'] = 'https://' . $backup['domain'];
    }
}

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

// KIỂM TRA XEM CÓ THỂ DÙNG HTTP REDIRECT KHÔNG (nhanh nhất, không cần stream)
if (!empty($backup['remote_path']) && !empty($backup['url'])) {
    try {
        // Tạo HTTP URL từ remote_path
        $websiteUrl = rtrim($backup['url'], '/');
        $remotePath = ltrim($backup['remote_path'], '/');
        $httpUrl = $websiteUrl . '/' . $remotePath;
        
        debugLog("Download backup #$backupId: Trying HTTP URL: $httpUrl");
        
        // Kiểm tra xem file có thể truy cập qua HTTP không (timeout 5 giây)
        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'method' => 'HEAD',
                'ignore_errors' => true
            ]
        ]);
        $headers = @get_headers($httpUrl, 1, $context);
        
        if ($headers && strpos($headers[0], '200') !== false) {
            debugLog("Download backup #$backupId: File accessible via HTTP, redirecting to: $httpUrl");
            
            // Redirect đến file HTTP (nhanh nhất, không cần stream)
            header("Location: $httpUrl");
            exit;
        } else {
            $status = $headers ? $headers[0] : 'No response';
            debugLog("Download backup #$backupId: HTTP URL not accessible (status: $status), falling back to SFTP/FTP stream");
        }
    } catch (Exception $e) {
        debugLog("Download backup #$backupId: HTTP check failed: " . $e->getMessage() . ", falling back to SFTP/FTP stream");
    }
}

// GỬI HEADERS NGAY LẬP TỨC để browser biết đang download (nếu không dùng HTTP redirect)
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

// Download từ server website qua SFTP/FTP
if (!empty($backup['remote_path'])) {
    try {
        
        // PHƯƠNG PHÁP 2: Dùng SFTP/FTP (fallback)
        // Tăng timeout cho kết nối SFTP/FTP
        ini_set('default_socket_timeout', 600); // Tăng lên 10 phút
        
        // Log để debug
        debugLog("Download backup #$backupId: Starting connection to {$backup['connection_type']}://{$backup['sftp_host']}");
        
        $fileManager = new HostingerFileManager(
            $backup['sftp_host'],
            $backup['sftp_username'],
            $backup['sftp_password'],
            $backup['path'],
            $backup['connection_type'],
            $backup['sftp_port']
        );
        
        debugLog("Download backup #$backupId: Connected successfully");
        
        // Thử nhiều cách path khác nhau
        $pathsToTry = [
            $backup['remote_path'], // Path gốc
            ltrim($backup['remote_path'], '/'), // Bỏ leading slash
            '.tpanel/backups/' . basename($backup['remote_path']), // Relative path
            basename($backup['remote_path']) // Chỉ filename
        ];
        
        $filePath = false;
        
        // Tìm file path
        foreach ($pathsToTry as $tryPath) {
            // Sanitize path
            $tryPath = $security->sanitizePath($tryPath);
            debugLog("Download backup #$backupId: Trying path: $tryPath");
            
            try {
                if ($fileManager->fileExists($tryPath)) {
                    $filePath = $tryPath;
                    debugLog("Download backup #$backupId: Found file at: $tryPath");
                    break;
                }
            } catch (Exception $e) {
                debugLog("Download backup #$backupId: Path $tryPath failed: " . $e->getMessage());
                continue;
            }
        }
        
        if ($filePath === false) {
            debugLog("Download backup #$backupId: ERROR - File not found. Tried paths: " . implode(', ', $pathsToTry));
            echo "\n\nERROR: Không thể tìm thấy file backup trên server.";
            exit;
        }
        
        debugLog("Download backup #$backupId: Starting stream from: $filePath");
        
        // Stream file trực tiếp (không load vào memory)
        $bytesStreamed = $fileManager->streamFile($filePath);
        
        if ($bytesStreamed === false) {
            debugLog("Download backup #$backupId: ERROR - Stream failed");
            echo "\n\nERROR: Lỗi khi stream file backup từ server.";
            exit;
        }
        
        debugLog("Download backup #$backupId: Stream completed. Bytes: $bytesStreamed");
        exit;
    } catch (Exception $e) {
        // Log lỗi để debug
        debugLog("Download backup #$backupId ERROR: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine());
        debugLog("Download backup #$backupId Trace: " . $e->getTraceAsString());
        
        // Gửi error message
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
