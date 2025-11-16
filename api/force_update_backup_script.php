<?php
/**
 * Force update backup script on remote server
 * Delete old script and upload new one with timezone
 */

require_once __DIR__ . '/../includes/helpers/functions.php';
require_once __DIR__ . '/../includes/classes/HostingerFileManager.php';

$auth = new Auth();
$auth->requireLogin();

if (!$auth->isAdmin()) {
    die('Chỉ admin mới có quyền thực hiện');
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

echo "<h2>Force Update Backup Script</h2>";
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
    
    $remoteScriptPath = '.tpanel/backups/backup_script.php';
    $localScriptPath = __DIR__ . '/../scripts/server_backup_script.php';
    
    // Xóa script cũ nếu tồn tại
    if ($fileManager->fileExists($remoteScriptPath)) {
        echo "<p>→ Đang xóa script cũ...</p>";
        $fileManager->deleteFile($remoteScriptPath);
        echo "<p>✓ Đã xóa script cũ</p>";
    } else {
        echo "<p>→ Script cũ không tồn tại</p>";
    }
    
    // Upload script mới
    echo "<p>→ Đang upload script mới...</p>";
    $fileManager->putFile($localScriptPath, $remoteScriptPath);
    echo "<p>✓ Đã upload script mới</p>";
    
    // Kiểm tra lại
    echo "<p>→ Kiểm tra script mới...</p>";
    $content = $fileManager->getFileContent($remoteScriptPath);
    
    if (strpos($content, "date_default_timezone_set('Asia/Ho_Chi_Minh')") !== false) {
        echo "<p style='color: green; font-weight: bold;'>✓ Script mới ĐÃ có timezone GMT+7</p>";
        echo "<p><strong>→ Bây giờ tạo backup mới sẽ có thời gian đúng GMT+7!</strong></p>";
    } else {
        echo "<p style='color: red;'>✗ Script mới vẫn CHƯA có timezone</p>";
        echo "<p>Vui lòng kiểm tra file scripts/server_backup_script.php</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Lỗi: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr>";
echo "<p><a href='" . BASE_URL . "website/{$websiteId}/manage'>← Quay lại quản lý website</a></p>";

