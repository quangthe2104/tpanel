<?php
/**
 * Tpanel Installation Script
 * Chạy script này để cài đặt database tự động
 */

require_once 'config/config.php';
require_once 'includes/Database.php';

// Check if already installed
if (php_sapi_name() !== 'cli') {
    // Running from browser
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Tpanel Installation</title>";
    echo "<style>body{font-family:Arial,sans-serif;max-width:800px;margin:50px auto;padding:20px;background:#f5f5f5;}";
    echo ".container{background:white;padding:30px;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,0.1);}";
    echo "h1{color:#667eea;margin-top:0;}pre{background:#f8f9fa;padding:15px;border-radius:5px;overflow-x:auto;}";
    echo ".success{color:#28a745;}.error{color:#dc3545;}.warning{color:#ffc107;}</style></head><body><div class='container'>";
    echo "<h1>🔧 Tpanel Installation</h1>";
    echo "<pre>";
}

echo "=== Tpanel Installation ===\n";
echo "Đang kiểm tra cấu hình...\n\n";

// Read SQL file
$sqlFile = __DIR__ . '/database/schema.sql';
if (!file_exists($sqlFile)) {
    die("ERROR: Không tìm thấy file database/schema.sql\n");
}

$sql = file_get_contents($sqlFile);

// Remove comments and split by semicolon
$sql = preg_replace('/--.*$/m', '', $sql);
$sql = preg_replace('/\/\*.*?\*\//s', '', $sql);

// Get database config
$dbConfig = require __DIR__ . '/config/database.php';

try {
    // Connect to MySQL without database
    $dsn = "mysql:host={$dbConfig['host']};charset={$dbConfig['charset']}";
    $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    
    echo "✓ Kết nối MySQL thành công\n";
    
    // Create database if not exists
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbConfig['dbname']}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "✓ Database '{$dbConfig['dbname']}' đã được tạo\n";
    
    // Use database
    $pdo->exec("USE `{$dbConfig['dbname']}`");
    echo "✓ Đã chọn database '{$dbConfig['dbname']}'\n";
    
    // Split SQL into individual statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && !preg_match('/^(USE|CREATE DATABASE)/i', $stmt);
        }
    );
    
    echo "\nĐang import các bảng...\n";
    $count = 0;
    $adminCreated = false;
    foreach ($statements as $statement) {
        if (empty(trim($statement))) continue;
        
        try {
            $pdo->exec($statement);
            $count++;
            
            // Extract table name if it's CREATE TABLE
            if (preg_match('/CREATE TABLE.*?`?(\w+)`?/i', $statement, $matches)) {
                echo "  ✓ Tạo bảng: {$matches[1]}\n";
            }
            
            // Check if admin user was inserted
            if (stripos($statement, "INSERT INTO `users`") !== false && stripos($statement, "'admin'") !== false) {
                $adminCreated = true;
            }
        } catch (PDOException $e) {
            // Ignore "table already exists" and "duplicate entry" errors
            if (strpos($e->getMessage(), 'already exists') === false && strpos($e->getMessage(), 'Duplicate entry') === false) {
                echo "  ⚠ Lỗi: " . $e->getMessage() . "\n";
            }
        }
    }
    
    // Ensure admin user exists with correct password
    try {
        $checkAdmin = $pdo->query("SELECT id FROM users WHERE username = 'admin'")->fetch();
        if (!$checkAdmin) {
            $adminPassword = password_hash('admin123', PASSWORD_DEFAULT);
            $pdo->exec("INSERT INTO `users` (`username`, `email`, `password`, `full_name`, `role`) VALUES ('admin', 'admin@tpanel.local', '$adminPassword', 'Administrator', 'admin')");
            echo "  ✓ Tạo user admin mặc định\n";
            $adminCreated = true;
        } else {
            // Update password to ensure it's correct
            $adminPassword = password_hash('admin123', PASSWORD_DEFAULT);
            $pdo->exec("UPDATE users SET password = '$adminPassword' WHERE username = 'admin'");
            echo "  ✓ Cập nhật password admin\n";
        }
    } catch (PDOException $e) {
        echo "  ⚠ Không thể tạo/cập nhật user admin: " . $e->getMessage() . "\n";
        echo "  → Vui lòng sử dụng reset_admin.php để tạo/reset password\n";
    }
    
    echo "\n✓ Đã import $count statements thành công\n";
    echo "\n=== Cài đặt hoàn tất! ===\n\n";
    
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']);
    $loginUrl = rtrim($baseUrl, '/') . '/login.php';
    
    echo "Bạn có thể đăng nhập với:\n";
    echo "  URL: $loginUrl\n";
    echo "  Username: admin\n";
    echo "  Password: admin123\n";
    echo "\n⚠️  VUI LÒNG ĐỔI MẬT KHẨU NGAY SAU KHI ĐĂNG NHẬP!\n";
    echo "\n🔒 QUAN TRỌNG: Xóa hoặc đổi tên file install.php để bảo mật!\n";
    echo "\nBước tiếp theo:\n";
    echo "1. Xóa file install.php (bảo mật)\n";
    echo "2. Đăng nhập vào Tpanel\n";
    echo "3. Đổi mật khẩu admin\n";
    echo "4. Thêm website từ Hostinger\n";
    echo "5. Phân quyền cho người dùng\n";
    
    if (php_sapi_name() !== 'cli') {
        echo "</pre>";
        echo "<div style='margin-top:20px;padding:15px;background:#fff3cd;border-left:4px solid #ffc107;border-radius:5px;'>";
        echo "<strong>⚠️ Bảo mật:</strong> Vui lòng xóa hoặc đổi tên file <code>install.php</code> sau khi cài đặt xong!";
        echo "</div>";
        echo "<div style='margin-top:15px;'>";
        echo "<a href='login.php' style='display:inline-block;padding:10px 20px;background:#667eea;color:white;text-decoration:none;border-radius:5px;'>Đăng nhập ngay</a>";
        echo "</div>";
        echo "</div></body></html>";
    }
    
} catch (PDOException $e) {
    $errorMsg = "ERROR: " . $e->getMessage() . "\n";
    if (php_sapi_name() !== 'cli') {
        echo "</pre>";
        echo "<div style='margin-top:20px;padding:15px;background:#f8d7da;border-left:4px solid #dc3545;border-radius:5px;color:#721c24;'>";
        echo "<strong>❌ Lỗi:</strong> " . htmlspecialchars($e->getMessage());
        echo "<br><br><strong>Kiểm tra:</strong>";
        echo "<ul>";
        echo "<li>File config/database.php đã được tạo chưa?</li>";
        echo "<li>Thông tin database có đúng không?</li>";
        echo "<li>Database và user đã được tạo trên Hostinger chưa?</li>";
        echo "</ul>";
        echo "</div>";
        echo "</div></body></html>";
    }
    die($errorMsg);
}
