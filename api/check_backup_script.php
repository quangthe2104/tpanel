<?php
/**
 * Check backup script on remote server
 */

require_once __DIR__ . '/../includes/helpers/functions.php';
require_once __DIR__ . '/../includes/classes/HostingerFileManager.php';

$auth = new Auth();
$auth->requireLogin();

if (!$auth->isAdmin()) {
    die('Chỉ admin mới có quyền kiểm tra');
}

$db = Database::getInstance();
$websiteId = $_GET['website_id'] ?? 0;

if (!$websiteId) {
    die('Vui lòng cung cấp website_id');
}

$website = $db->fetchOne("SELECT * FROM websites WHERE id = ?", [$websiteId]);

if (!$website) {
    die('Website không tồn tại');
}

echo "<h2>Kiểm tra Backup Script trên Server</h2>";
echo "<p>Website: <strong>{$website['name']}</strong></p>";
echo "<hr>";

try {
    $fileManager = new HostingerFileManager(
        $website['sftp_host'],
        $website['sftp_username'],
        $website['sftp_password'],
        $website['path'],
        $website['connection_type'],
        $website['sftp_port']
    );
    
    $scriptPath = '.tpanel/backups/backup_script.php';
    
    if ($fileManager->fileExists($scriptPath)) {
        echo "<p>✓ File script tồn tại: <code>$scriptPath</code></p>";
        
        // Đọc 50 dòng đầu của script
        $content = $fileManager->getFileContent($scriptPath);
        $lines = explode("\n", $content);
        $first50Lines = array_slice($lines, 0, 50);
        
        echo "<h3>50 dòng đầu của script:</h3>";
        echo "<pre style='background: #f5f5f5; padding: 15px; overflow-x: auto;'>";
        echo htmlspecialchars(implode("\n", $first50Lines));
        echo "</pre>";
        
        // Kiểm tra xem có dòng timezone không
        if (strpos($content, "date_default_timezone_set('Asia/Ho_Chi_Minh')") !== false) {
            echo "<p style='color: green;'>✓ Script ĐÃ có timezone GMT+7</p>";
        } else {
            echo "<p style='color: red;'>✗ Script CHƯA có timezone GMT+7</p>";
            echo "<p><strong>→ Cần upload lại script backup!</strong></p>";
        }
        
    } else {
        echo "<p style='color: red;'>✗ File script không tồn tại</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Lỗi: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr>";
echo "<p><a href='" . BASE_URL . "'>← Quay lại trang chủ</a></p>";

