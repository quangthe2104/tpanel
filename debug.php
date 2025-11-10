<?php
/**
 * Debug Script - Kiểm tra lỗi HTTP 500
 * Truy cập: https://yourdomain.com/debug.php
 * XÓA FILE NÀY SAU KHI DEBUG XONG!
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug - Tpanel</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1000px; margin: 20px auto; padding: 20px; background: #f5f5f5; }
        .container { background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #667eea; margin-top: 0; }
        .section { margin: 20px 0; padding: 15px; background: #f8f9fa; border-radius: 5px; }
        .success { color: #28a745; }
        .error { color: #dc3545; }
        .warning { color: #ffc107; }
        pre { background: #2d2d2d; color: #f8f8f2; padding: 15px; border-radius: 5px; overflow-x: auto; }
        .info { background: #d1ecf1; padding: 10px; border-left: 4px solid #0c5460; margin: 10px 0; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Tpanel Debug Information</h1>
        
        <?php
        $errors = [];
        $warnings = [];
        $success = [];
        
        // 1. Kiểm tra PHP Version
        echo "<div class='section'>";
        echo "<h3>1. PHP Version</h3>";
        $phpVersion = phpversion();
        echo "<p>Version: <strong>$phpVersion</strong></p>";
        if (version_compare($phpVersion, '7.4.0', '>=')) {
            $success[] = "PHP version OK";
            echo "<p class='success'>✓ PHP version đạt yêu cầu (>= 7.4)</p>";
        } else {
            $errors[] = "PHP version quá cũ";
            echo "<p class='error'>✗ PHP version quá cũ, cần >= 7.4</p>";
        }
        echo "</div>";
        
        // 2. Kiểm tra PHP Extensions
        echo "<div class='section'>";
        echo "<h3>2. PHP Extensions</h3>";
        $required = ['pdo', 'pdo_mysql', 'zip', 'curl'];
        $optional = ['ssh2', 'ftp'];
        
        foreach ($required as $ext) {
            if (extension_loaded($ext)) {
                echo "<p class='success'>✓ $ext</p>";
            } else {
                $errors[] = "Missing extension: $ext";
                echo "<p class='error'>✗ $ext (BẮT BUỘC)</p>";
            }
        }
        
        foreach ($optional as $ext) {
            if (extension_loaded($ext)) {
                echo "<p class='success'>✓ $ext</p>";
            } else {
                $warnings[] = "Missing optional extension: $ext";
                echo "<p class='warning'>⚠ $ext (Tùy chọn - cần cho SFTP/FTP)</p>";
            }
        }
        echo "</div>";
        
        // 3. Kiểm tra file config
        echo "<div class='section'>";
        echo "<h3>3. File Configuration</h3>";
        
        $configFiles = [
            'config/config.php' => 'Bắt buộc',
            'config/database.php' => 'Bắt buộc',
            'config/database.php.example' => 'Có sẵn',
            'config/hostinger.php.example' => 'Có sẵn',
        ];
        
        foreach ($configFiles as $file => $type) {
            if (file_exists($file)) {
                $size = filesize($file);
                $readable = is_readable($file) ? '✓' : '✗';
                echo "<p class='success'>$readable $file ($type) - " . formatBytes($size) . "</p>";
            } else {
                if ($type === 'Bắt buộc') {
                    $errors[] = "Missing file: $file";
                    echo "<p class='error'>✗ $file ($type) - KHÔNG TỒN TẠI!</p>";
                } else {
                    echo "<p class='warning'>⚠ $file ($type) - Không có (OK)</p>";
                }
            }
        }
        echo "</div>";
        
        // 4. Kiểm tra database connection
        echo "<div class='section'>";
        echo "<h3>4. Database Connection</h3>";
        
        if (file_exists('config/database.php')) {
            try {
                $dbConfig = require 'config/database.php';
                
                echo "<p>Host: <strong>" . htmlspecialchars($dbConfig['host'] ?? 'N/A') . "</strong></p>";
                echo "<p>Database: <strong>" . htmlspecialchars($dbConfig['dbname'] ?? 'N/A') . "</strong></p>";
                echo "<p>Username: <strong>" . htmlspecialchars($dbConfig['username'] ?? 'N/A') . "</strong></p>";
                
                // Test connection
                try {
                    $dsn = "mysql:host={$dbConfig['host']};charset={$dbConfig['charset'] ?? 'utf8mb4'}";
                    $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    ]);
                    $success[] = "Database connection OK";
                    echo "<p class='success'>✓ Kết nối database thành công</p>";
                    
                    // Check database exists
                    try {
                        $pdo->exec("USE `{$dbConfig['dbname']}`");
                        echo "<p class='success'>✓ Database '{$dbConfig['dbname']}' tồn tại</p>";
                        
                        // Check tables
                        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
                        echo "<p>Tables: <strong>" . count($tables) . "</strong></p>";
                        if (count($tables) > 0) {
                            echo "<ul>";
                            foreach ($tables as $table) {
                                echo "<li>$table</li>";
                            }
                            echo "</ul>";
                        } else {
                            $warnings[] = "Database chưa có tables";
                            echo "<p class='warning'>⚠ Database chưa có tables. Chạy install.php để tạo.</p>";
                        }
                    } catch (PDOException $e) {
                        $errors[] = "Database không tồn tại: " . $e->getMessage();
                        echo "<p class='error'>✗ Database '{$dbConfig['dbname']}' không tồn tại hoặc không có quyền truy cập</p>";
                    }
                } catch (PDOException $e) {
                    $errors[] = "Database connection failed: " . $e->getMessage();
                    echo "<p class='error'>✗ Không thể kết nối database: " . htmlspecialchars($e->getMessage()) . "</p>";
                }
            } catch (Exception $e) {
                $errors[] = "Config file error: " . $e->getMessage();
                echo "<p class='error'>✗ Lỗi đọc config: " . htmlspecialchars($e->getMessage()) . "</p>";
            }
        } else {
            $errors[] = "config/database.php không tồn tại";
            echo "<p class='error'>✗ File config/database.php không tồn tại!</p>";
            echo "<p class='info'>Tạo file từ config/database.php.example</p>";
        }
        echo "</div>";
        
        // 5. Kiểm tra file permissions
        echo "<div class='section'>";
        echo "<h3>5. File Permissions</h3>";
        
        $dirs = ['backups', 'config'];
        foreach ($dirs as $dir) {
            if (is_dir($dir)) {
                $writable = is_writable($dir) ? '✓' : '✗';
                $readable = is_readable($dir) ? '✓' : '✗';
                $perms = substr(sprintf('%o', fileperms($dir)), -4);
                if (is_writable($dir)) {
                    echo "<p class='success'>$writable $dir/ - Writable (perms: $perms)</p>";
                } else {
                    $warnings[] = "Directory not writable: $dir";
                    echo "<p class='warning'>$writable $dir/ - Không writable (perms: $perms)</p>";
                }
            } else {
                echo "<p class='warning'>⚠ $dir/ - Không tồn tại (sẽ tự tạo khi cần)</p>";
            }
        }
        echo "</div>";
        
        // 6. Kiểm tra includes
        echo "<div class='section'>";
        echo "<h3>6. Core Files</h3>";
        
        $coreFiles = [
            'includes/Database.php',
            'includes/Auth.php',
            'includes/functions.php',
            'includes/HostingerFileManager.php',
            'includes/DatabaseManager.php',
        ];
        
        foreach ($coreFiles as $file) {
            if (file_exists($file)) {
                echo "<p class='success'>✓ $file</p>";
            } else {
                $errors[] = "Missing core file: $file";
                echo "<p class='error'>✗ $file - KHÔNG TỒN TẠI!</p>";
            }
        }
        echo "</div>";
        
        // 7. Kiểm tra syntax errors
        echo "<div class='section'>";
        echo "<h3>7. PHP Syntax Check</h3>";
        
        $phpFiles = glob('*.php');
        $phpFiles = array_merge($phpFiles, glob('includes/*.php'));
        $phpFiles = array_merge($phpFiles, glob('config/*.php'));
        
        $syntaxErrors = [];
        foreach ($phpFiles as $file) {
            if (strpos($file, '.example') !== false) continue;
            
            $output = [];
            $return = 0;
            exec("php -l " . escapeshellarg($file) . " 2>&1", $output, $return);
            
            if ($return === 0) {
                echo "<p class='success'>✓ $file</p>";
            } else {
                $syntaxErrors[] = $file;
                $errors[] = "Syntax error in: $file";
                echo "<p class='error'>✗ $file</p>";
                echo "<pre>" . htmlspecialchars(implode("\n", $output)) . "</pre>";
            }
        }
        echo "</div>";
        
        // 8. Tóm tắt
        echo "<div class='section'>";
        echo "<h3>📊 Tóm Tắt</h3>";
        
        echo "<p><strong>Success:</strong> " . count($success) . "</p>";
        echo "<p><strong>Warnings:</strong> " . count($warnings) . "</p>";
        echo "<p><strong>Errors:</strong> " . count($errors) . "</p>";
        
        if (count($errors) > 0) {
            echo "<div class='info' style='background:#f8d7da;border-color:#dc3545;'>";
            echo "<h4>❌ Các lỗi cần sửa:</h4>";
            echo "<ul>";
            foreach ($errors as $error) {
                echo "<li>$error</li>";
            }
            echo "</ul>";
            echo "</div>";
        }
        
        if (count($warnings) > 0) {
            echo "<div class='info' style='background:#fff3cd;border-color:#ffc107;'>";
            echo "<h4>⚠️ Cảnh báo:</h4>";
            echo "<ul>";
            foreach ($warnings as $warning) {
                echo "<li>$warning</li>";
            }
            echo "</ul>";
            echo "</div>";
        }
        
        if (count($errors) === 0) {
            echo "<div class='info' style='background:#d4edda;border-color:#28a745;'>";
            echo "<h4>✅ Không có lỗi nghiêm trọng!</h4>";
            echo "<p>Nếu vẫn gặp lỗi HTTP 500, kiểm tra:</p>";
            echo "<ul>";
            echo "<li>PHP error logs trong hPanel</li>";
            echo "<li>Apache/Nginx error logs</li>";
            echo "<li>File .htaccess có đúng không</li>";
            echo "<li>Quyền truy cập file và thư mục</li>";
            echo "</ul>";
            echo "</div>";
        }
        echo "</div>";
        
        function formatBytes($bytes, $precision = 2) {
            $units = ['B', 'KB', 'MB', 'GB'];
            $bytes = max($bytes, 0);
            $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
            $pow = min($pow, count($units) - 1);
            $bytes /= pow(1024, $pow);
            return round($bytes, $precision) . ' ' . $units[$pow];
        }
        ?>
        
        <div style="margin-top: 30px; padding: 15px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 5px;">
            <strong>⚠️ Bảo mật:</strong> Xóa file debug.php sau khi debug xong!
        </div>
    </div>
</body>
</html>
