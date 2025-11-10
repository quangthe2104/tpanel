<?php
/**
 * Tpanel Installation Script
 * Chạy script này để cài đặt database tự động
 */

require_once 'config/config.php';
require_once 'includes/Database.php';

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
    foreach ($statements as $statement) {
        if (empty(trim($statement))) continue;
        
        try {
            $pdo->exec($statement);
            $count++;
            
            // Extract table name if it's CREATE TABLE
            if (preg_match('/CREATE TABLE.*?`?(\w+)`?/i', $statement, $matches)) {
                echo "  ✓ Tạo bảng: {$matches[1]}\n";
            }
        } catch (PDOException $e) {
            // Ignore "table already exists" errors
            if (strpos($e->getMessage(), 'already exists') === false) {
                echo "  ⚠ Lỗi: " . $e->getMessage() . "\n";
            }
        }
    }
    
    echo "\n✓ Đã import $count statements thành công\n";
    echo "\n=== Cài đặt hoàn tất! ===\n\n";
    echo "Bạn có thể đăng nhập với:\n";
    echo "  URL: http://localhost/tpanel/login.php\n";
    echo "  Username: admin\n";
    echo "  Password: admin123\n";
    echo "\n⚠️  VUI LÒNG ĐỔI MẬT KHẨU NGAY SAU KHI ĐĂNG NHẬP!\n";
    echo "\nBước tiếp theo:\n";
    echo "1. Đăng nhập vào Tpanel\n";
    echo "2. Đổi mật khẩu admin\n";
    echo "3. Thêm website từ Hostinger\n";
    echo "4. Phân quyền cho người dùng\n";
    
} catch (PDOException $e) {
    die("ERROR: " . $e->getMessage() . "\n");
}
