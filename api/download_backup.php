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

// Lấy backup với thông tin website (không dùng w.url vì có thể chưa có trong DB)
$backup = $db->fetchOne("SELECT b.*, w.sftp_host, w.sftp_username, w.sftp_password, w.sftp_port, w.connection_type, w.path, w.domain 
                         FROM backups b 
                         LEFT JOIN websites w ON b.website_id = w.id 
                         WHERE b.id = ?", [$backupId]);

// Tạo url từ domain nếu có
if (!empty($backup['domain'])) {
    $backup['url'] = 'https://' . $backup['domain'];
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

// Lưu file size từ DB (sẽ được override nếu có từ HTTP headers)
$finalFileSize = !empty($backup['file_size']) && $backup['file_size'] > 0 ? $backup['file_size'] : null;

// KIỂM TRA XEM CÓ THỂ DÙNG HTTP STREAM KHÔNG (proxy qua server tpanel)
if (!empty($backup['remote_path']) && !empty($backup['url'])) {
    try {
        // Normalize remote_path để lấy relative path từ web root
        $remotePath = $backup['remote_path'];
        
        // Nếu là absolute path từ server, extract relative path từ web root
        // Ví dụ: /home/u624007921/domains/tomko.com.vn/public_html/.tpanel/backups/file.zip
        // -> .tpanel/backups/file.zip
        if (strpos($remotePath, '/') === 0 || strpos($remotePath, '/home/') === 0) {
            // Tìm public_html trong path
            if (strpos($remotePath, 'public_html/') !== false) {
                $parts = explode('public_html/', $remotePath, 2);
                if (isset($parts[1])) {
                    $remotePath = $parts[1];
                }
            } elseif (strpos($remotePath, 'www/') !== false) {
                // Hoặc nếu dùng www/ thay vì public_html/
                $parts = explode('www/', $remotePath, 2);
                if (isset($parts[1])) {
                    $remotePath = $parts[1];
                }
            } elseif (!empty($backup['path'])) {
                // Nếu có basePath, thử normalize theo basePath
                $basePath = rtrim($backup['path'], '/');
                if (!empty($basePath) && strpos($remotePath, $basePath) === 0) {
                    $remotePath = substr($remotePath, strlen($basePath));
                    $remotePath = ltrim($remotePath, '/');
                }
            }
        }
        
        // Đảm bảo remotePath là relative (bỏ leading slash nếu có)
        $remotePath = ltrim($remotePath, '/');
        
        // Tạo HTTP URL
        $websiteUrl = rtrim($backup['url'], '/');
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
            debugLog("Download backup #$backupId: File accessible via HTTP, redirecting directly to: $httpUrl");
            
            // Redirect trực tiếp đến file HTTP (NHANH NHẤT - browser tự xử lý download và progress)
            // Browser sẽ download trực tiếp từ source, progress bar hiển thị ngay từ đầu
            header('Location: ' . $httpUrl, true, 302);
            exit;
        } else {
            $status = $headers ? $headers[0] : 'No response';
            debugLog("Download backup #$backupId: HTTP URL not accessible (status: $status), falling back to SFTP/FTP stream");
        }
    } catch (Exception $e) {
        debugLog("Download backup #$backupId: HTTP check failed: " . $e->getMessage() . ", falling back to SFTP/FTP stream");
    }
}

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
            header('Content-Type: text/plain; charset=utf-8');
            echo "ERROR: Không thể tìm thấy file backup trên server.";
            exit;
        }
        
        // Lấy file size từ server nếu chưa có (để set Content-Length chính xác)
        if (!$finalFileSize || $finalFileSize <= 0) {
            try {
                $actualFileSize = $fileManager->getFileSize($filePath);
                if ($actualFileSize && $actualFileSize > 0) {
                    $finalFileSize = $actualFileSize;
                    debugLog("Download backup #$backupId: Got file size from server: " . $finalFileSize);
                }
            } catch (Exception $e) {
                debugLog("Download backup #$backupId: Could not get file size from server: " . $e->getMessage());
            }
        }
        
        // GỬI TẤT CẢ HEADERS (bao gồm Content-Length) SAU KHI ĐÃ CÓ FILE SIZE
        $filename = basename($backup['filename']);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        // Set Content-Length chính xác (QUAN TRỌNG để browser hiển thị progress)
        if ($finalFileSize && $finalFileSize > 0) {
            header('Content-Length: ' . $finalFileSize);
            debugLog("Download backup #$backupId: Set Content-Length header: " . $finalFileSize);
        } else {
            debugLog("Download backup #$backupId: WARNING - No file size available for Content-Length");
        }
        
        header('Cache-Control: no-cache, must-revalidate, no-store');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Accel-Buffering: no'); // Tắt buffering cho Nginx
        header('X-Accel-Limit-Rate: 0'); // Không giới hạn tốc độ cho Nginx
        header('Connection: close'); // Đóng connection ngay sau khi xong
        
        // Flush headers ngay lập tức (PHẢI flush trước khi bắt đầu stream)
        if (ob_get_level() > 0) {
            @ob_end_flush();
        }
        flush();
        
        debugLog("Download backup #$backupId: Starting stream from: $filePath (size: " . ($finalFileSize ?? 'unknown') . ")");
        
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
