<?php
/**
 * Backup Script chạy trên server website
 * Script này sẽ được upload lên server và tự động thực hiện backup
 */

// Chạy async, không chờ client disconnect
ignore_user_abort(true);
set_time_limit(0);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '4096M'); // Tăng memory limit lên 4GB cho file lớn
ini_set('max_input_time', 0);
ini_set('post_max_size', '4096M');
ini_set('upload_max_filesize', '4096M');

// Cấu hình từ tham số
$backupType = $_GET['type'] ?? 'full'; // full, files, database
$backupName = $_GET['name'] ?? 'backup_' . date('Y-m-d_H-i-s');

// Xác định thư mục backup dựa trên vị trí script
$scriptDir = dirname(__FILE__);
$scriptDirReal = realpath($scriptDir);

// Xác định thư mục .tpanel/backups (nơi script đang chạy)
// Script ở: /path/to/website/.tpanel/backups/backup_script.php
// Backup dir nên là: /path/to/website/.tpanel/backups/
if (basename($scriptDir) === 'backups' && basename(dirname($scriptDir)) === '.tpanel') {
    // Script đang ở đúng vị trí: .tpanel/backups
    $backupDir = $scriptDirReal . '/';
} else {
    // Fallback: dùng relative path từ script location
    $backupDir = $scriptDirReal . '/';
}

$statusFile = $backupDir . $backupName . '.status';
$resultFile = $backupDir . $backupName . '.result';
$logFile = $backupDir . $backupName . '.log';

// Tạo thư mục backup nếu chưa có
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0755, true);
}

// Hàm ghi log với flush để đảm bảo log được ghi ngay
function writeLog($message, $logFile) {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message\n";
    $result = @file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    // Flush để đảm bảo log được ghi ngay
    if ($result !== false) {
        @fflush(fopen($logFile, 'a'));
    }
}

// Đăng ký shutdown function để ghi log cuối cùng nếu script bị kill
// Sử dụng biến global để tránh vấn đề với closure
$GLOBALS['backup_log_file'] = $logFile;
$GLOBALS['backup_status_file'] = $statusFile;
register_shutdown_function(function() {
    $logFile = $GLOBALS['backup_log_file'] ?? null;
    $statusFile = $GLOBALS['backup_status_file'] ?? null;
    
    if (!$logFile) return;
    
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $logMessage = "[" . date('Y-m-d H:i:s') . "] LỖI FATAL: " . $error['message'] . " tại " . $error['file'] . ":" . $error['line'] . "\n";
        @file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        // Cập nhật status nếu chưa completed
        if ($statusFile && file_exists($statusFile)) {
            $status = @json_decode(@file_get_contents($statusFile), true);
            if ($status && isset($status['status']) && $status['status'] === 'processing') {
                @file_put_contents($statusFile, json_encode([
                    'status' => 'failed',
                    'message' => 'Script bị dừng đột ngột: ' . $error['message'],
                    'failed_at' => date('Y-m-d H:i:s')
                ]));
            }
        }
    }
});

// Bắt đầu log
writeLog("=== Bắt đầu backup: $backupType - $backupName ===", $logFile);
writeLog("Backup directory: $backupDir", $logFile);
writeLog("Memory limit: " . ini_get('memory_limit'), $logFile);
writeLog("Max execution time: " . ini_get('max_execution_time'), $logFile);

// Ghi trạng thái: đang xử lý
file_put_contents($statusFile, json_encode([
    'status' => 'processing',
    'message' => 'Đang tạo backup...',
    'started_at' => date('Y-m-d H:i:s')
]));
writeLog("Đã tạo status file: $statusFile", $logFile);

try {
    $backupPath = '';
    
    if ($backupType === 'full' || $backupType === 'files') {
        // Backup files: zip toàn bộ thư mục hiện tại
        $zipFile = $backupDir . $backupName . '.zip';
        
        writeLog("Bắt đầu backup files...", $logFile);
        writeLog("Target ZIP file: $zipFile", $logFile);
        
        // Kiểm tra shell zip command ngay từ đầu (trước khi mở ZipArchive)
        // Thử nhiều function: exec, system, shell_exec, passthru
        $useShellZip = false;
        $zipCommand = null;
        
        // Hàm helper để thử chạy command
        $tryCommand = function($cmd) use (&$zipCommand, $logFile) {
            $output = [];
            $returnVar = 0;
            $disabled = @ini_get('disable_functions');
            
            // Thử exec() trước (có return code)
            if (function_exists('exec') && (empty($disabled) || strpos($disabled, 'exec') === false)) {
                @exec($cmd . ' 2>&1', $output, $returnVar);
                if ($returnVar == 0) {
                    $result = implode("\n", $output);
                    if (!empty($result)) {
                        return $result;
                    }
                    // Nếu return code = 0 nhưng không có output, vẫn có thể thành công
                    return "OK"; // Marker để biết command chạy thành công
                }
            }
            
            // Thử system()
            if (function_exists('system') && (empty($disabled) || strpos($disabled, 'system') === false)) {
                ob_start();
                @system($cmd . ' 2>&1', $returnVar);
                $output = ob_get_clean();
                if ($returnVar == 0) {
                    if (!empty($output)) {
                        return $output;
                    }
                    return "OK";
                }
            }
            
            // Thử shell_exec()
            if (function_exists('shell_exec') && (empty($disabled) || strpos($disabled, 'shell_exec') === false)) {
                $output = @shell_exec($cmd . ' 2>&1');
                if (!empty($output)) {
                    return $output;
                }
            }
            
            return null;
        };
        
        // Thử tìm zip command - thử trực tiếp trước (thường có trong PATH)
        writeLog("Đang kiểm tra zip command...", $logFile);
        $testZip = $tryCommand('zip --version');
        writeLog("Kết quả zip --version: " . ($testZip ? substr($testZip, 0, 100) : 'NULL'), $logFile);
        
        if (!empty($testZip) && $testZip !== "OK" && (stripos($testZip, 'Zip') !== false || stripos($testZip, 'Copyright') !== false || stripos($testZip, 'Info-ZIP') !== false)) {
            $zipCommand = 'zip';
            $useShellZip = true;
            writeLog("Phát hiện zip command qua --version test, sẽ sử dụng shell zip (nhanh và ổn định hơn)", $logFile);
        } else if ($testZip === "OK") {
            // Command chạy thành công nhưng không có output - có thể là zip command
            // Thử chạy một test khác
            $testZip2 = $tryCommand('zip 2>&1');
            writeLog("Kết quả zip (không có args): " . ($testZip2 ? substr($testZip2, 0, 100) : 'NULL'), $logFile);
            if (!empty($testZip2) && $testZip2 !== "OK" && (stripos($testZip2, 'zip') !== false || stripos($testZip2, 'usage') !== false || stripos($testZip2, 'error') !== false)) {
                $zipCommand = 'zip';
                $useShellZip = true;
                writeLog("Phát hiện zip command (không có version output nhưng command tồn tại), sẽ sử dụng shell zip", $logFile);
            }
        }
        
        if (!$useShellZip) {
            // Thử which/whereis
            writeLog("Thử tìm zip bằng which/whereis...", $logFile);
            $whichResult = $tryCommand('which zip');
            if (!empty($whichResult) && $whichResult !== "OK") {
                $zipCommand = trim($whichResult);
                writeLog("Tìm thấy zip tại: $zipCommand, đang kiểm tra...", $logFile);
                // Kiểm tra lại bằng --version
                $verify = $tryCommand("$zipCommand --version");
                if (!empty($verify) && $verify !== "OK" && (stripos($verify, 'Zip') !== false || stripos($verify, 'Copyright') !== false || stripos($verify, 'Info-ZIP') !== false)) {
                    $useShellZip = true;
                    writeLog("Phát hiện zip command: $zipCommand, sẽ sử dụng shell zip", $logFile);
                } else {
                    writeLog("zip command không hoạt động đúng: $zipCommand", $logFile);
                }
            } else {
                // Thử các đường dẫn phổ biến
                writeLog("Thử các đường dẫn phổ biến...", $logFile);
                $commonPaths = ['/usr/bin/zip', '/bin/zip', '/usr/local/bin/zip'];
                foreach ($commonPaths as $path) {
                    if (file_exists($path)) {
                        writeLog("Tìm thấy file tại: $path, đang kiểm tra...", $logFile);
                        $verify = $tryCommand("$path --version");
                        writeLog("Kết quả kiểm tra $path: " . ($verify ? substr($verify, 0, 100) : 'NULL'), $logFile);
                        
                        if (!empty($verify) && $verify !== "OK" && (stripos($verify, 'Zip') !== false || stripos($verify, 'Copyright') !== false || stripos($verify, 'Info-ZIP') !== false)) {
                            $zipCommand = $path;
                            $useShellZip = true;
                            writeLog("Phát hiện zip command tại: $path, sẽ sử dụng shell zip", $logFile);
                            break;
                        } else if ($verify === "OK") {
                            // File tồn tại và có thể chạy (return code = 0) nhưng không có output
                            // Thử chạy trực tiếp để xác nhận
                            $test2 = $tryCommand("$path 2>&1");
                            writeLog("Kết quả test 2 $path: " . ($test2 ? substr($test2, 0, 100) : 'NULL'), $logFile);
                            if (!empty($test2) && $test2 !== "OK" && (stripos($test2, 'zip') !== false || stripos($test2, 'usage') !== false || stripos($test2, 'error') !== false)) {
                                $zipCommand = $path;
                                $useShellZip = true;
                                writeLog("Phát hiện zip command tại: $path (qua test 2), sẽ sử dụng shell zip", $logFile);
                                break;
                            } else {
                                // File tồn tại nhưng không chạy được - có thể bị disable_functions
                                writeLog("File $path tồn tại nhưng không chạy được (có thể bị disable_functions chặn)", $logFile);
                            }
                        }
                    }
                }
                
                if (!$useShellZip) {
                    writeLog("Không tìm thấy zip command hoạt động, sẽ dùng ZipArchive (có thể mất thời gian với file lớn)", $logFile);
                    writeLog("Lưu ý: File zip tồn tại tại /usr/bin/zip và /bin/zip nhưng không chạy được - có thể bị disable_functions chặn exec/system/shell_exec", $logFile);
                }
            }
        }
        
        // Kiểm tra xem có file .part cũ không (từ lần backup trước bị lỗi)
        // Nếu có file .part, đổi tên thành .zip để tiếp tục hoặc xóa nếu quá cũ
        // Pattern: backup_Tomko_2025-11-13_21-00-17*.part (bao gồm cả .zip.jqe49d.part)
        $partFiles = glob($backupDir . $backupName . '*.part');
        writeLog("Tìm thấy " . count($partFiles) . " file .part cũ", $logFile);
        foreach ($partFiles as $partFile) {
            $partMtime = filemtime($partFile);
            $partAge = time() - $partMtime;
            // Nếu file .part cũ hơn 1 giờ, xóa nó
            if ($partAge > 3600) {
                @unlink($partFile);
                writeLog("Đã xóa file .part cũ: " . basename($partFile) . " (tuổi: " . round($partAge/60) . " phút)", $logFile);
            } else {
                // Nếu file .part còn mới, có thể là đang tạo, giữ lại
                // Nhưng nếu file .zip đã tồn tại, xóa .part
                if (file_exists($zipFile)) {
                    @unlink($partFile);
                    writeLog("Đã xóa file .part vì file .zip đã tồn tại: " . basename($partFile), $logFile);
                } else {
                    writeLog("Giữ lại file .part (có thể đang tạo): " . basename($partFile) . " (kích thước: " . round(filesize($partFile)/1024/1024, 2) . " MB)", $logFile);
                }
            }
        }
        
        // Nếu dùng shell zip, không cần ZipArchive
        if (!$useShellZip) {
            $zip = new ZipArchive();
            
            // Sử dụng FLAG để tránh tạo file .part tạm thời
            // ZipArchive::CREATE | ZipArchive::OVERWRITE sẽ tạo file trực tiếp
            writeLog("Mở ZipArchive với CREATE | OVERWRITE...", $logFile);
            $zipResult = $zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            if ($zipResult !== TRUE) {
                writeLog("LỖI: Không thể tạo zip archive. Code: $zipResult", $logFile);
                throw new Exception("Cannot create zip archive (code: $zipResult)");
            }
            writeLog("ZipArchive đã mở thành công", $logFile);
        } else {
            $zip = null; // Không dùng ZipArchive
            writeLog("Bỏ qua ZipArchive, sẽ dùng shell zip command", $logFile);
        }
        
        // Backup từ thư mục cha của .tpanel/backups (thư mục website)
        // Script ở: /path/to/website/.tpanel/backups/backup_script.php
        // Cần backup từ: /path/to/website/
        if (basename($scriptDir) === 'backups' && basename(dirname($scriptDir)) === '.tpanel') {
            // Script đang ở đúng vị trí: .tpanel/backups
            $backupSourceDir = dirname(dirname($scriptDirReal)); // Thư mục cha của .tpanel
        } else {
            // Fallback: backup thư mục hiện tại (nếu script không ở đúng vị trí)
            $backupSourceDir = dirname($scriptDirReal);
            // Nếu vẫn ở trong .tpanel, lên thêm 1 cấp
            if (basename($backupSourceDir) === '.tpanel') {
                $backupSourceDir = dirname($backupSourceDir);
            }
        }
        
        // Đảm bảo backupSourceDir là absolute path và tồn tại
        $backupSourceDir = realpath($backupSourceDir) ?: $backupSourceDir;
        if (!is_dir($backupSourceDir)) {
            writeLog("LỖI: Thư mục nguồn không tồn tại: $backupSourceDir", $logFile);
            throw new Exception("Backup source directory does not exist: $backupSourceDir");
        }
        writeLog("Thư mục nguồn backup: $backupSourceDir", $logFile);
        
        // Danh sách các thư mục/file cần loại trừ khỏi backup (cache, temp, etc.)
        $excludePatterns = [
            // Thư mục cache phổ biến
            '/cache/',
            '/tmp/',
            '/temp/',
            '/\.cache/',
            '/\.tmp/',
            '/\.temp/',
            '/node_modules/',
            '/vendor/', // Có thể bỏ comment nếu muốn backup vendor
            '/\.git/',
            '/\.svn/',
            '/\.hg/',
            '/\.idea/',
            '/\.vscode/',
            '/\.DS_Store/',
            '/Thumbs\.db/',
            '/sessions/',
            '/logs/',
            '/log/',
            '/error_log/',
            '/\.tpanel/', // Đã có sẵn nhưng thêm vào để chắc chắn
            // File cache phổ biến
            '/\.cache$/',
            '/\.tmp$/',
            '/\.temp$/',
            '/\.swp$/',
            '/\.swo$/',
            '/~$/',
            '/\.log$/',
            '/error_log$/',
            // WordPress cache
            '/wp-content\/cache/',
            '/wp-content\/uploads\/cache/',
            '/wp-content\/litespeed/',
            // Joomla cache
            '/cache/',
            '/tmp/',
            // Laravel cache
            '/storage\/framework\/cache/',
            '/storage\/framework\/sessions/',
            '/storage\/framework\/views/',
            '/bootstrap\/cache/',
            // Drupal cache
            '/sites\/.*\/files\/css/',
            '/sites\/.*\/files\/js/',
            '/sites\/.*\/files\/styles/',
        ];
        
        writeLog("Đã cấu hình " . count($excludePatterns) . " pattern loại trừ cache/temp", $logFile);
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($backupSourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        $fileCount = 0;
        $excludedCount = 0;
        $excludedSize = 0;
        $totalSize = 0;
        $lastLogTime = time();
        
        if ($useShellZip) {
            // Với shell zip, chỉ cần đếm files và tạo file list
            writeLog("Đang quét files để tạo danh sách backup (sẽ dùng shell zip)...", $logFile);
            $fileList = $backupDir . $backupName . '_filelist.txt';
            $fileListHandle = fopen($fileList, 'w');
        
        foreach ($iterator as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                    $relativePath = substr($filePath, strlen($backupSourceDir) + 1);
                    
                // Bỏ qua thư mục backup và file script này
                    if (strpos($filePath, '.tpanel') !== false || 
                        basename($filePath) === basename(__FILE__) ||
                        strpos($filePath, 'backup_script.php') !== false) {
                        continue;
                    }
                    
                    // Kiểm tra xem file có match với pattern loại trừ không
                    $shouldExclude = false;
                    $normalizedPath = str_replace('\\', '/', $relativePath); // Normalize path
                    foreach ($excludePatterns as $pattern) {
                        if (preg_match($pattern, $normalizedPath)) {
                            $shouldExclude = true;
                            break;
                        }
                    }
                    
                    if ($shouldExclude) {
                        $excludedCount++;
                        $excludedSize += filesize($filePath);
                        continue;
                    }
                    
                    // Ghi vào file list
                    fwrite($fileListHandle, $relativePath . "\n");
                    $fileCount++;
                    $totalSize += filesize($filePath);
                    
                    // Log tiến trình mỗi 30 giây hoặc mỗi 1000 files
                    $currentTime = time();
                    if ($fileCount % 1000 == 0 || ($currentTime - $lastLogTime) >= 30) {
                        writeLog("Đã quét $fileCount files (tổng kích thước: " . round($totalSize/1024/1024, 2) . " MB, đã bỏ qua: $excludedCount files, " . round($excludedSize/1024/1024, 2) . " MB)", $logFile);
                        $lastLogTime = $currentTime;
                    }
                }
            }
            fclose($fileListHandle);
            writeLog("Đã tạo file list với $fileCount files", $logFile);
        } else {
            // Với ZipArchive, thêm files vào ZIP
            writeLog("Bắt đầu thêm files vào ZIP...", $logFile);
            foreach ($iterator as $file) {
                if (!$file->isDir()) {
                    $filePath = $file->getRealPath();
                    $relativePath = substr($filePath, strlen($backupSourceDir) + 1);
                    
                    // Bỏ qua thư mục backup và file script này
                    if (strpos($filePath, '.tpanel') !== false || 
                        basename($filePath) === basename(__FILE__) ||
                        strpos($filePath, 'backup_script.php') !== false) {
                        continue;
                    }
                    
                    // Kiểm tra xem file có match với pattern loại trừ không
                    $shouldExclude = false;
                    $normalizedPath = str_replace('\\', '/', $relativePath); // Normalize path
                    foreach ($excludePatterns as $pattern) {
                        if (preg_match($pattern, $normalizedPath)) {
                            $shouldExclude = true;
                            break;
                        }
                    }
                    
                    if ($shouldExclude) {
                        $excludedCount++;
                        $excludedSize += filesize($filePath);
                        continue;
                    }
                    
                    // Thêm file vào ZIP
                    $zip->addFile($filePath, $relativePath);
                    $fileCount++;
                    $totalSize += filesize($filePath);
                    
                    // Log tiến trình mỗi 30 giây hoặc mỗi 1000 files
                    $currentTime = time();
                    if ($fileCount % 1000 == 0 || ($currentTime - $lastLogTime) >= 30) {
                        writeLog("Đã thêm $fileCount files vào ZIP (tổng kích thước: " . round($totalSize/1024/1024, 2) . " MB, đã bỏ qua: $excludedCount files, " . round($excludedSize/1024/1024, 2) . " MB)", $logFile);
                        $lastLogTime = $currentTime;
                    }
                }
            }
        }
        
        writeLog("Hoàn tất thêm files. Tổng: $fileCount files, " . round($totalSize/1024/1024, 2) . " MB", $logFile);
        writeLog("Đã bỏ qua: $excludedCount files cache/temp, " . round($excludedSize/1024/1024, 2) . " MB", $logFile);
        
        // Flush log trước khi đóng ZIP (có thể mất thời gian)
        @fflush(fopen($logFile, 'a'));
        
        $closeStartTime = microtime(true);
        writeLog("Đang đóng ZIP file (có thể mất vài phút với file lớn)...", $logFile);
        
        // Đảm bảo output được flush
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        
        // Xử lý tạo ZIP file
        if ($useShellZip) {
            // Sử dụng shell zip command (đã có file list từ trước)
            writeLog("Bắt đầu tạo ZIP bằng shell command...", $logFile);
            
            $cdCommand = escapeshellarg($backupSourceDir);
            $zipFileEscaped = escapeshellarg($zipFile);
            $fileListEscaped = escapeshellarg($fileList);
            
            // Tạo ZIP với file list
            // cd vào thư mục nguồn, zip với file list từ stdin
            $zipCmd = "cd $cdCommand && $zipCommand -r $zipFileEscaped . -@ < $fileListEscaped 2>&1";
            writeLog("Chạy lệnh shell zip...", $logFile);
            
            // Thử nhiều cách để chạy command
            $zipOutput = null;
            $zipSuccess = false;
            
            if (function_exists('exec')) {
                $output = [];
                $returnVar = 0;
                @exec($zipCmd, $output, $returnVar);
                $zipOutput = implode("\n", $output);
                $zipSuccess = ($returnVar == 0);
            } else if (function_exists('system')) {
                ob_start();
                $returnVar = 0;
                @system($zipCmd, $returnVar);
                $zipOutput = ob_get_clean();
                $zipSuccess = ($returnVar == 0);
            } else if (function_exists('shell_exec')) {
                $zipOutput = @shell_exec($zipCmd);
                $zipSuccess = true; // shell_exec không trả về return code
            }
            
            if (empty($zipOutput)) {
                $zipOutput = "Không có output (có thể thành công)";
            }
            
            @unlink($fileList);
            
            // Kiểm tra kết quả
            sleep(2); // Đợi file được ghi xong
            if (file_exists($zipFile) && filesize($zipFile) > 0) {
                $finalSize = filesize($zipFile);
                writeLog("ZIP file đã được tạo thành công bằng shell command: " . round($finalSize/1024/1024, 2) . " MB", $logFile);
                $closeResult = TRUE;
                $closeDuration = round(microtime(true) - $closeStartTime, 2);
            } else {
                writeLog("LỖI: Shell zip command thất bại. Output: $zipOutput", $logFile);
                $closeResult = FALSE;
                $closeDuration = round(microtime(true) - $closeStartTime, 2);
            }
        } else {
            // Sử dụng ZipArchive (có thể bị treo với file lớn)
            // Với file lớn (>5GB), ZipArchive->close() có thể mất 30-60 phút
            $estimatedTime = round($totalSize / 100, 0); // Ước tính: ~100MB/phút
            writeLog("Sử dụng ZipArchive (với file " . round($totalSize, 2) . " MB, ước tính mất ~{$estimatedTime} phút)...", $logFile);
            
            // Thử đóng với timeout logic
            $closeResult = false;
            $closeDuration = 0;
            
            // Với file lớn, không dùng alarm vì có thể làm gián đoạn
            // Chỉ log cảnh báo nếu quá lâu
            try {
                writeLog("Bắt đầu đóng ZipArchive (blocking call - có thể mất rất lâu)...", $logFile);
                $closeResult = $zip->close();
                $closeDuration = round(microtime(true) - $closeStartTime, 2);
                
            } catch (Exception $e) {
                $closeDuration = round(microtime(true) - $closeStartTime, 2);
                writeLog("LỖI khi đóng ZIP: " . $e->getMessage(), $logFile);
            }
            
            if ($closeResult === TRUE) {
                writeLog("ZIP file đã được đóng thành công (mất " . round($closeDuration/60, 1) . " phút / " . round($closeDuration, 0) . " giây)", $logFile);
            } else {
                writeLog("CẢNH BÁO: ZipArchive->close() trả về FALSE (code: $closeResult) sau " . round($closeDuration/60, 1) . " phút", $logFile);
                // Vẫn tiếp tục để kiểm tra file
            }
        }
        
        // Flush log ngay sau khi đóng
        @fflush(fopen($logFile, 'a'));
        
        // Kiểm tra xem file .zip đã được tạo thành công chưa
        // Nếu chưa có, kiểm tra xem có file .part không và đổi tên
        // Đợi một chút để ZipArchive hoàn tất việc đóng file (với file lớn có thể cần thời gian)
        writeLog("Đợi file ZIP xuất hiện hoặc kiểm tra file .part...", $logFile);
        
        // Với file lớn, có thể cần đợi lâu hơn, nhưng cũng kiểm tra file .part ngay
        $maxWaitTime = 1800; // Tối đa 30 phút (tăng từ 10 phút) - với file 6GB có thể cần thời gian
        $waitInterval = 2; // Kiểm tra mỗi 2 giây
        $waited = 0;
        $partFileFound = false;
        
        while ($waited < $maxWaitTime) {
            // Kiểm tra file ZIP trước
            if (file_exists($zipFile) && filesize($zipFile) > 0) {
                writeLog("File ZIP đã xuất hiện sau $waited giây", $logFile);
                break;
            }
            
            // Kiểm tra file .part ngay từ đầu (không cần đợi)
            // Kiểm tra cả file ẩn và file có pattern khác
            if (!$partFileFound) {
                // Tìm file .part với nhiều pattern
                $partFiles = glob($backupDir . $backupName . '*.part');
                // Tìm file ẩn (bắt đầu bằng .)
                $hiddenPartFiles = glob($backupDir . '.' . $backupName . '*.part');
                // Tìm file có pattern .zip.xxxxx.part
                $zipPartFiles = glob($backupDir . $backupName . '.zip.*.part');
                
                $allPartFiles = array_merge($partFiles, $hiddenPartFiles, $zipPartFiles);
                $allPartFiles = array_unique($allPartFiles);
                
                if (!empty($allPartFiles)) {
                    $partFileFound = true;
                    writeLog("Phát hiện " . count($allPartFiles) . " file .part (ZipArchive có thể đang tạo file)", $logFile);
                    foreach ($allPartFiles as $pf) {
                        $size = @filesize($pf);
                        $mtime = @filemtime($pf);
                        writeLog("  - " . basename($pf) . " (" . round($size/1024/1024, 2) . " MB, " . date('H:i:s', $mtime) . ")", $logFile);
                    }
                    
                    // Tìm file .part lớn nhất
                    $largestPartFile = '';
                    $largestSize = 0;
                    foreach ($allPartFiles as $partFile) {
                        $size = @filesize($partFile);
                        if ($size && $size > $largestSize) {
                            $largestSize = $size;
                            $largestPartFile = $partFile;
                        }
                    }
                    
                    if ($largestPartFile && $largestSize > 1048576) { // > 1MB
                        writeLog("File .part lớn nhất: " . basename($largestPartFile) . " (" . round($largestSize/1024/1024, 2) . " MB)", $logFile);
                        
                        // Nếu file .part đã đủ lớn (gần bằng kích thước dự kiến) và không thay đổi trong 60 giây
                        // Có thể ZipArchive đã hoàn tất nhưng chưa đổi tên
                        $partMtime = filemtime($largestPartFile);
                        $partAge = time() - $partMtime;
                        
                        // Với file lớn (>5GB), cần đợi lâu hơn (120 giây) và kiểm tra kỹ hơn
                        $minAge = ($totalSize > 5000) ? 120 : 60; // 120s cho file >5GB, 60s cho file nhỏ hơn
                        $minSizeRatio = ($totalSize > 5000) ? 0.85 : 0.9; // 85% cho file >5GB, 90% cho file nhỏ hơn
                        
                        if ($partAge > $minAge && $largestSize > ($totalSize * $minSizeRatio * 1024 * 1024)) { // File đã đủ lớn và không thay đổi
                            writeLog("File .part đã đủ lớn (" . round($largestSize/1024/1024, 2) . " MB / " . round($totalSize, 2) . " MB dự kiến) và không thay đổi trong {$minAge}s, thử đổi tên...", $logFile);
                            if (rename($largestPartFile, $zipFile)) {
                                writeLog("Đã đổi tên file .part thành .zip thành công!", $logFile);
                                break;
                            } else if (copy($largestPartFile, $zipFile)) {
                                @unlink($largestPartFile);
                                writeLog("Đã copy file .part thành .zip thành công!", $logFile);
                                break;
                            }
                        }
                    }
                }
            }
            
            sleep($waitInterval);
            $waited += $waitInterval;
            
            // Log mỗi 30 giây (giảm tần suất log)
            if ($waited % 30 == 0) {
                writeLog("Đang đợi file ZIP xuất hiện... (đã đợi $waited giây / " . round($maxWaitTime/60) . " phút)", $logFile);
                if ($partFileFound) {
                    // Kiểm tra lại file .part với nhiều pattern
                    $partFiles = glob($backupDir . $backupName . '*.part');
                    $hiddenPartFiles = glob($backupDir . '.' . $backupName . '*.part');
                    $zipPartFiles = glob($backupDir . $backupName . '.zip.*.part');
                    $allPartFiles = array_unique(array_merge($partFiles, $hiddenPartFiles, $zipPartFiles));
                    
                    if (!empty($allPartFiles)) {
                        $latestPart = '';
                        $latestSize = 0;
                        $latestMtime = 0;
                        foreach ($allPartFiles as $pf) {
                            $size = @filesize($pf);
                            $mtime = @filemtime($pf);
                            if ($size && $size > $latestSize) {
                                $latestSize = $size;
                                $latestPart = $pf;
                                $latestMtime = $mtime;
                            }
                        }
                        if ($latestPart) {
                            $age = time() - $latestMtime;
                            writeLog("File .part hiện tại: " . basename($latestPart) . " (" . round($latestSize/1024/1024, 2) . " MB, tuổi: " . $age . "s, không thay đổi: " . ($age > 30 ? 'CÓ (có thể đã xong)' : 'KHÔNG (đang tạo)') . ")", $logFile);
                            
                            // Nếu file đã không thay đổi và đủ lớn, thử đổi tên
                            // Với file lớn (>5GB), cần đợi lâu hơn
                            $minAgeForRename = ($totalSize > 5000) ? 180 : 120; // 180s cho file >5GB, 120s cho file nhỏ hơn
                            $minSizeRatioForRename = ($totalSize > 5000) ? 0.8 : 0.85; // 80% cho file >5GB, 85% cho file nhỏ hơn
                            
                            if ($age > $minAgeForRename && $latestSize > ($totalSize * $minSizeRatioForRename * 1024 * 1024)) {
                                writeLog("File .part đã không thay đổi {$minAgeForRename}s và đủ lớn (" . round($latestSize/1024/1024, 2) . " MB / " . round($totalSize, 2) . " MB dự kiến), thử đổi tên...", $logFile);
                                if (@rename($latestPart, $zipFile)) {
                                    writeLog("Đã đổi tên file .part thành .zip thành công!", $logFile);
                                    break;
                                } else if (@copy($latestPart, $zipFile)) {
                                    @unlink($latestPart);
                                    writeLog("Đã copy file .part thành .zip thành công!", $logFile);
                                    break;
                                }
                            }
                        }
                    } else {
                        writeLog("Không tìm thấy file .part nào", $logFile);
                    }
                }
            }
        }
        
        if ($waited >= $maxWaitTime) {
            writeLog("CẢNH BÁO: Đã đợi quá " . round($maxWaitTime/60) . " phút mà file ZIP vẫn chưa xuất hiện", $logFile);
        }
        
        writeLog("Kiểm tra file ZIP sau khi đóng...", $logFile);
        $zipExists = file_exists($zipFile);
        $zipSize = $zipExists ? filesize($zipFile) : 0;
        writeLog("File ZIP tồn tại: " . ($zipExists ? 'CÓ' : 'KHÔNG') . ", Kích thước: " . round($zipSize/1024/1024, 2) . " MB", $logFile);
        
        if (!$zipExists || $zipSize == 0) {
            writeLog("File ZIP chưa tồn tại hoặc rỗng, tìm file .part...", $logFile);
            // Tìm file .part tương ứng (pattern: backup_Tomko_2025-11-13_21-00-17*.part)
            $partFiles = glob($backupDir . $backupName . '*.part');
            writeLog("Tìm thấy " . count($partFiles) . " file .part", $logFile);
            
            if (!empty($partFiles)) {
                // Lấy file .part mới nhất và lớn nhất
                $latestPartFile = '';
                $latestTime = 0;
                $largestSize = 0;
                foreach ($partFiles as $partFile) {
                    $mtime = filemtime($partFile);
                    $size = filesize($partFile);
                    writeLog("File .part: " . basename($partFile) . " - Kích thước: " . round($size/1024/1024, 2) . " MB, Thời gian: " . date('Y-m-d H:i:s', $mtime), $logFile);
                    // Ưu tiên file mới nhất và lớn nhất
                    if ($mtime > $latestTime || ($mtime == $latestTime && $size > $largestSize)) {
                        $latestTime = $mtime;
                        $largestSize = $size;
                        $latestPartFile = $partFile;
                    }
                }
                
                // Nếu file .part có kích thước hợp lý (> 1MB), đổi tên thành .zip
                if ($latestPartFile && filesize($latestPartFile) > 1048576) {
                    writeLog("Tìm thấy file .part hợp lệ: " . basename($latestPartFile) . " (" . round(filesize($latestPartFile)/1024/1024, 2) . " MB)", $logFile);
                    // Đợi thêm một chút để đảm bảo file không còn đang được ghi
                    sleep(2);
                    
                    if (rename($latestPartFile, $zipFile)) {
                        writeLog("Đã đổi tên file .part thành .zip thành công", $logFile);
                    } else {
                        writeLog("Không thể đổi tên, thử copy...", $logFile);
                        // Nếu không đổi tên được, copy rồi xóa
                        if (copy($latestPartFile, $zipFile)) {
                            @unlink($latestPartFile);
                            writeLog("Đã copy file .part thành .zip thành công", $logFile);
                        } else {
                            writeLog("LỖI: Không thể copy file .part", $logFile);
                        }
                    }
                } else {
                    writeLog("File .part không hợp lệ hoặc quá nhỏ", $logFile);
                }
            }
        }
        
        // Kiểm tra lại sau khi xử lý .part
        if (!file_exists($zipFile)) {
            writeLog("LỖI: File backup không được tạo thành công", $logFile);
            throw new Exception("Backup file was not created successfully");
        }
        
        $backupPath = $zipFile;
        $fileSize = filesize($zipFile);
        writeLog("File backup đã được tạo thành công: " . round($fileSize/1024/1024, 2) . " MB", $logFile);
        
        // Xóa các file .part còn sót lại sau khi đã tạo xong .zip
        // Đợi một chút để đảm bảo file .zip đã được tạo xong
        sleep(1);
        $partFiles = glob($backupDir . $backupName . '*.part');
        writeLog("Xóa " . count($partFiles) . " file .part còn sót lại...", $logFile);
        foreach ($partFiles as $partFile) {
            if (@unlink($partFile)) {
                writeLog("Đã xóa: " . basename($partFile), $logFile);
            }
        }
    }
    
    if ($backupType === 'full' || $backupType === 'database') {
        writeLog("Bắt đầu backup database...", $logFile);
        // Backup database: cần thông tin DB từ config hoặc tham số
        $dbHost = $_GET['db_host'] ?? 'localhost';
        $dbName = $_GET['db_name'] ?? '';
        $dbUser = $_GET['db_user'] ?? '';
        $dbPass = $_GET['db_pass'] ?? '';
        
        writeLog("Database host: $dbHost, Database name: $dbName, User: $dbUser", $logFile);
        
        if (empty($dbName) || empty($dbUser)) {
            writeLog("LỖI: Thiếu thông tin database", $logFile);
            throw new Exception("Database information not provided. db_name: " . ($dbName ?: 'empty') . ", db_user: " . ($dbUser ?: 'empty'));
        }
        
        // Trên shared hosting, thường cần dùng localhost thay vì IP
        // Thử localhost trước, nếu fail thì thử IP gốc
        $hostsToTry = ['localhost', $dbHost];
        if ($dbHost !== 'localhost' && $dbHost !== '127.0.0.1') {
            $hostsToTry = ['localhost', '127.0.0.1', $dbHost];
        }
        
        // Export database
        $sqlFile = $backupDir . $backupName . '.sql';
        writeLog("SQL file: $sqlFile", $logFile);
        
        $pdo = null;
        $lastError = null;
        
        writeLog("Đang thử kết nối database với các host: " . implode(', ', $hostsToTry), $logFile);
        foreach ($hostsToTry as $tryHost) {
            try {
                writeLog("Thử kết nối với host: $tryHost", $logFile);
                $pdo = new PDO(
                    "mysql:host=$tryHost;dbname=$dbName;charset=utf8mb4",
                    $dbUser,
                    $dbPass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                writeLog("Kết nối database thành công với host: $tryHost", $logFile);
                break;
            } catch (PDOException $e) {
                $lastError = $e->getMessage();
                writeLog("Kết nối thất bại với host $tryHost: " . $e->getMessage(), $logFile);
                $pdo = null;
            }
        }
        
        if (!$pdo) {
            writeLog("LỖI: Không thể kết nối database với bất kỳ host nào", $logFile);
            throw new Exception("Database connection failed: $lastError");
        }
        
        try {
            writeLog("Bắt đầu export database...", $logFile);
            $sql = "-- Database Export: $dbName\n";
            $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
            
            // Get all tables
            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            writeLog("Tìm thấy " . count($tables) . " tables trong database", $logFile);
            
            foreach ($tables as $table) {
                // Export table structure
                $stmt = $pdo->query("SHOW CREATE TABLE `$table`");
                $createTable = $stmt->fetch();
                $sql .= "\n-- Table structure for `$table`\n";
                $sql .= "DROP TABLE IF EXISTS `$table`;\n";
                $sql .= $createTable['Create Table'] . ";\n\n";
                
                // Export table data
                $stmt = $pdo->query("SELECT * FROM `$table`");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (count($rows) > 0) {
                    $sql .= "-- Data for table `$table`\n";
                    $columns = array_keys($rows[0]);
                    $sql .= "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES\n";
                    
                    $values = [];
                    foreach ($rows as $row) {
                        $rowValues = [];
                        foreach ($row as $value) {
                            if ($value === null) {
                                $rowValues[] = 'NULL';
                            } else {
                                $rowValues[] = $pdo->quote($value);
                            }
                        }
                        $values[] = '(' . implode(',', $rowValues) . ')';
                    }
                    $sql .= implode(",\n", $values) . ";\n\n";
                }
            }
            
            file_put_contents($sqlFile, $sql);
            
            if (!file_exists($sqlFile)) {
                writeLog("LỖI: Không thể tạo file SQL", $logFile);
                throw new Exception("Failed to create SQL file: $sqlFile");
            }
            
            $fileSize = filesize($sqlFile);
            writeLog("Đã tạo file SQL thành công: " . round($fileSize/1024/1024, 2) . " MB", $logFile);
        } catch (PDOException $e) {
            writeLog("LỖI khi export database: " . $e->getMessage(), $logFile);
            throw new Exception("Database export failed: " . $e->getMessage());
        }
        
        if ($backupType === 'database') {
            if (!file_exists($sqlFile)) {
                writeLog("LỖI: File SQL không tồn tại", $logFile);
                throw new Exception("Database backup file not created: $sqlFile");
            }
            $fileSize = filesize($sqlFile);
            if ($fileSize == 0) {
                writeLog("LỖI: File SQL rỗng", $logFile);
                throw new Exception("Database backup file is empty: $sqlFile");
            }
            
            writeLog("Bắt đầu nén file SQL thành ZIP...", $logFile);
            // Nén file SQL thành ZIP để tối ưu dung lượng
            $zipFile = $backupDir . $backupName . '.zip';
            $zip = new ZipArchive();
            
            writeLog("Mở ZipArchive cho database backup...", $logFile);
            if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
                writeLog("LỖI: Không thể tạo ZIP archive", $logFile);
                throw new Exception("Cannot create ZIP archive: $zipFile");
            }
            
            // Thêm file SQL vào ZIP với tên gốc
            $zip->addFile($sqlFile, basename($sqlFile));
            writeLog("Đã thêm file SQL vào ZIP, đang đóng...", $logFile);
            
            $closeStartTime = microtime(true);
            $closeResult = $zip->close();
            $closeDuration = round(microtime(true) - $closeStartTime, 2);
            
            if ($closeResult === TRUE) {
                writeLog("ZIP file đã được đóng thành công (mất $closeDuration giây)", $logFile);
            } else {
                writeLog("CẢNH BÁO: ZipArchive->close() trả về FALSE (code: $closeResult) sau $closeDuration giây", $logFile);
            }
            
            sleep(1);
            // Kiểm tra và xử lý file .part nếu có
            $partFiles = glob($backupDir . $backupName . '*.part');
            writeLog("Kiểm tra file ZIP sau khi đóng. Tồn tại: " . (file_exists($zipFile) ? 'CÓ' : 'KHÔNG') . ", Tìm thấy " . count($partFiles) . " file .part", $logFile);
            
            if (!empty($partFiles) && (!file_exists($zipFile) || filesize($zipFile) == 0)) {
                writeLog("File ZIP chưa tồn tại hoặc rỗng, xử lý file .part...", $logFile);
                $latestPartFile = '';
                $latestTime = 0;
                foreach ($partFiles as $partFile) {
                    $mtime = filemtime($partFile);
                    $size = filesize($partFile);
                    writeLog("File .part: " . basename($partFile) . " - " . round($size/1024/1024, 2) . " MB", $logFile);
                    if ($mtime > $latestTime) {
                        $latestTime = $mtime;
                        $latestPartFile = $partFile;
                    }
                }
                if ($latestPartFile && filesize($latestPartFile) > 1024) {
                    writeLog("Đang đổi tên file .part: " . basename($latestPartFile), $logFile);
                    if (rename($latestPartFile, $zipFile)) {
                        writeLog("Đã đổi tên file .part thành công", $logFile);
                    } else if (copy($latestPartFile, $zipFile)) {
                        @unlink($latestPartFile);
                        writeLog("Đã copy file .part thành công", $logFile);
                    }
                }
            }
            
            // Xóa file SQL gốc sau khi nén
            @unlink($sqlFile);
            writeLog("Đã xóa file SQL gốc", $logFile);
            
            // Xóa các file .part còn sót lại
            $partFiles = glob($backupDir . $backupName . '*.part');
            writeLog("Xóa " . count($partFiles) . " file .part còn sót lại...", $logFile);
            foreach ($partFiles as $partFile) {
                if (@unlink($partFile)) {
                    writeLog("Đã xóa: " . basename($partFile), $logFile);
                }
            }
            
            if (!file_exists($zipFile)) {
                writeLog("LỖI: File ZIP không được tạo thành công", $logFile);
                throw new Exception("ZIP file was not created successfully");
            }
            
            $zipSize = filesize($zipFile);
            writeLog("File ZIP database đã được tạo thành công: " . round($zipSize/1024/1024, 2) . " MB", $logFile);
            $backupPath = $zipFile;
            $fileSize = $zipSize;
        } else {
            // Nếu là full backup, thêm SQL vào zip với tên file đúng format
            if (file_exists($sqlFile)) {
                writeLog("Thêm file SQL vào ZIP backup full...", $logFile);
                $zip = new ZipArchive();
                // Mở file ZIP đã tồn tại để thêm file SQL vào (không tạo mới)
                if ($zip->open($backupPath) === TRUE) {
                    $zip->addFile($sqlFile, basename($sqlFile));
                    writeLog("Đã thêm file SQL vào ZIP, đang đóng...", $logFile);
                    
                    $closeStartTime = microtime(true);
                    $closeResult = $zip->close();
                    $closeDuration = round(microtime(true) - $closeStartTime, 2);
                    
                    if ($closeResult === TRUE) {
                        writeLog("ZIP file đã được đóng thành công (mất $closeDuration giây)", $logFile);
                    } else {
                        writeLog("CẢNH BÁO: ZipArchive->close() trả về FALSE (code: $closeResult) sau $closeDuration giây", $logFile);
                    }
                    
                    sleep(1);
                    // Kiểm tra và xử lý file .part nếu có
                    $partFiles = glob($backupDir . $backupName . '*.part');
                    writeLog("Kiểm tra file ZIP sau khi thêm SQL. Tồn tại: " . (file_exists($backupPath) ? 'CÓ' : 'KHÔNG') . ", Kích thước: " . (file_exists($backupPath) ? round(filesize($backupPath)/1024/1024, 2) . " MB" : "0 MB") . ", Tìm thấy " . count($partFiles) . " file .part", $logFile);
                    
                    if (!empty($partFiles) && (!file_exists($backupPath) || filesize($backupPath) == 0)) {
                        writeLog("File ZIP chưa tồn tại hoặc rỗng, xử lý file .part...", $logFile);
                        $latestPartFile = '';
                        $latestTime = 0;
                        foreach ($partFiles as $partFile) {
                            $mtime = filemtime($partFile);
                            $size = filesize($partFile);
                            writeLog("File .part: " . basename($partFile) . " - " . round($size/1024/1024, 2) . " MB", $logFile);
                            if ($mtime > $latestTime) {
                                $latestTime = $mtime;
                                $latestPartFile = $partFile;
                            }
                        }
                        if ($latestPartFile && filesize($latestPartFile) > 1048576) {
                            writeLog("Đang đổi tên file .part: " . basename($latestPartFile), $logFile);
                            if (rename($latestPartFile, $backupPath)) {
                                writeLog("Đã đổi tên file .part thành công", $logFile);
                            } else if (copy($latestPartFile, $backupPath)) {
                                @unlink($latestPartFile);
                                writeLog("Đã copy file .part thành công", $logFile);
                            }
                        }
                    }
                    
                    // Xóa các file .part còn sót lại
                    $partFiles = glob($backupDir . $backupName . '*.part');
                    writeLog("Xóa " . count($partFiles) . " file .part còn sót lại...", $logFile);
                    foreach ($partFiles as $partFile) {
                        if (@unlink($partFile)) {
                            writeLog("Đã xóa: " . basename($partFile), $logFile);
                        }
                    }
                    
                    unlink($sqlFile);
                    $fileSize = filesize($backupPath);
                    writeLog("Đã thêm SQL vào backup full. Kích thước cuối: " . round($fileSize/1024/1024, 2) . " MB", $logFile);
                } else {
                    writeLog("LỖI: Không thể mở ZIP file để thêm SQL", $logFile);
                }
            }
        }
    }
    
    // Ghi kết quả thành công
    $finalFileSize = $fileSize ?? 0;
    writeLog("=== Backup hoàn thành thành công ===", $logFile);
    writeLog("File backup: $backupPath", $logFile);
    writeLog("Kích thước: " . round($finalFileSize/1024/1024, 2) . " MB", $logFile);
    
    file_put_contents($resultFile, json_encode([
        'status' => 'completed',
        'file_path' => $backupPath,
        'file_size' => $finalFileSize,
        'completed_at' => date('Y-m-d H:i:s')
    ]));
    
    // Cập nhật status
    file_put_contents($statusFile, json_encode([
        'status' => 'completed',
        'message' => 'Backup hoàn thành',
        'file_path' => $backupPath,
        'file_size' => $finalFileSize,
        'completed_at' => date('Y-m-d H:i:s')
    ]));
    
    echo json_encode(['success' => true, 'message' => 'Backup completed', 'file' => $backupPath]);
    
} catch (Exception $e) {
    // Ghi lỗi
    $errorMessage = $e->getMessage();
    writeLog("=== LỖI BACKUP ===", $logFile);
    writeLog("Lỗi: $errorMessage", $logFile);
    writeLog("Stack trace: " . $e->getTraceAsString(), $logFile);
    
    file_put_contents($statusFile, json_encode([
        'status' => 'failed',
        'message' => $errorMessage,
        'failed_at' => date('Y-m-d H:i:s')
    ]));
    
    file_put_contents($resultFile, json_encode([
        'status' => 'failed',
        'error' => $errorMessage,
        'failed_at' => date('Y-m-d H:i:s')
    ]));
    
    echo json_encode(['success' => false, 'error' => $errorMessage]);
}

