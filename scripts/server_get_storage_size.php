<?php
/**
 * Script chạy trên server website để tính dung lượng nhanh
 * Sử dụng lệnh du để tính nhanh hơn so với recursive PHP
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Set error handler để bắt mọi lỗi (chỉ bắt errors, không bắt warnings và notices)
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // Bỏ qua notices, warnings về file exists, và deprecated
    if ($errno === E_NOTICE || 
        $errno === E_STRICT || 
        $errno === E_DEPRECATED ||
        $errno === E_WARNING && strpos($errstr, 'File exists') !== false ||
        $errno === E_WARNING && strpos($errstr, 'mkdir') !== false) {
        return false; // Let PHP handle it
    }
    
    // Chỉ xử lý fatal errors
    if ($errno === E_ERROR || $errno === E_PARSE || $errno === E_CORE_ERROR || $errno === E_COMPILE_ERROR) {
        $error = [
            'success' => false,
            'error' => "PHP Error ($errno): $errstr in $errfile:$errline",
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code(500);
        }
        echo json_encode($error);
        exit;
    }
    
    return false;
}, E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR);

// Set exception handler
set_exception_handler(function($exception) {
    $error = [
        'success' => false,
        'error' => "Exception: " . $exception->getMessage() . " in " . $exception->getFile() . ":" . $exception->getLine(),
        'trace' => $exception->getTraceAsString(),
        'updated_at' => date('Y-m-d H:i:s')
    ];
    
    if (!headers_sent()) {
        header('Content-Type: application/json');
        http_response_code(500);
    }
    echo json_encode($error);
    exit;
});

// Chạy async nếu được gọi từ HTTP
if (php_sapi_name() !== 'cli') {
    ignore_user_abort(true);
    set_time_limit(30);
    header('Content-Type: application/json');
}

// Xác định thư mục output dựa trên vị trí script
$scriptDir = dirname(__FILE__);
$scriptDirReal = realpath($scriptDir);

// Xác định thư mục .tpanel/storage (nơi script đang chạy)
// Script ở: /path/to/website/.tpanel/storage/get_storage_size.php
// Output dir nên là: /path/to/website/.tpanel/storage/
if (basename($scriptDir) === 'storage' && basename(dirname($scriptDir)) === '.tpanel') {
    // Script đang ở đúng vị trí: .tpanel/storage
    $outputDir = $scriptDirReal . '/';
} else {
    // Fallback: dùng relative path từ script location
    $outputDir = $scriptDirReal . '/';
}

$resultFile = $outputDir . 'storage_size.json';

// Thử tạo thư mục, nhưng không throw exception nếu fail
@mkdir($outputDir, 0755, true);

try {
    // Xác định thư mục cần tính (thư mục website - cha của .tpanel)
    // Script ở: /path/to/website/.tpanel/storage/get_storage_size.php
    // Cần tính: /path/to/website/
    if (basename($scriptDir) === 'storage' && basename(dirname($scriptDir)) === '.tpanel') {
        // Script đang ở đúng vị trí: .tpanel/storage
        $targetDir = dirname(dirname($scriptDirReal)); // Thư mục cha của .tpanel
    } else {
        // Fallback: tính thư mục hiện tại
        $targetDir = dirname($scriptDirReal);
        if (basename($targetDir) === '.tpanel') {
            $targetDir = dirname($targetDir);
        }
    }
    
    // Normalize path
    $targetDir = realpath($targetDir) ?: $targetDir;
    
    // Đảm bảo thư mục tồn tại
    if (!is_dir($targetDir)) {
        throw new Exception("Target directory does not exist: $targetDir (scriptDir: $scriptDirReal)");
    }
    
    // Kiểm tra quyền đọc
    if (!is_readable($targetDir)) {
        throw new Exception("Target directory is not readable: $targetDir");
    }
    
    $size = 0;
    
    // Kiểm tra xem shell_exec có được phép không
    $canUseShell = function_exists('shell_exec') && !in_array('shell_exec', explode(',', ini_get('disable_functions')));
    
    // Thử dùng lệnh du (nhanh nhất) - chỉ trên Linux/Unix và nếu shell_exec được phép
    if ($canUseShell && strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        $command = "du -sb " . escapeshellarg($targetDir) . " 2>&1";
        $output = @shell_exec($command);
        
        if ($output && preg_match('/^(\d+)\s+/', trim($output), $matches)) {
            $size = (int)$matches[1];
        }
    }
    
    // Nếu du không hoạt động hoặc shell_exec bị disable, dùng PHP recursive
    if ($size === 0) {
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($targetDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->isReadable()) {
                    $size += $file->getSize();
                }
            }
        } catch (Exception $iterError) {
            throw new Exception("Cannot calculate directory size: " . $iterError->getMessage());
        }
    }
    
    // Ghi kết quả
    $result = [
        'success' => true,
        'size' => $size,
        'size_formatted' => formatBytes($size),
        'updated_at' => date('Y-m-d H:i:s'),
        'target_dir' => $targetDir
    ];
    
    // Ghi vào file để backup
    if (is_dir($outputDir)) {
        @file_put_contents($resultFile, json_encode($result));
    }
    
    // Output result
    $output = json_encode($result);
    
    if (php_sapi_name() === 'cli') {
        echo $output;
    } else {
        echo $output;
    }
    
} catch (Exception $e) {
    $errorMsg = $e->getMessage();
    
    $error = [
        'success' => false,
        'error' => $errorMsg,
        'updated_at' => date('Y-m-d H:i:s')
    ];
    
    // Ghi vào file để backup
    if (is_dir($outputDir)) {
        @file_put_contents($resultFile, json_encode($error));
    }
    
    $output = json_encode($error);
    
    if (php_sapi_name() === 'cli') {
        echo $output;
    } else {
        http_response_code(500);
        echo $output;
    }
}

function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= pow(1024, $pow);
    
    return round($bytes, $precision) . ' ' . $units[$pow];
}

