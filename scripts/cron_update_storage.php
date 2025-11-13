<?php
/**
 * Cron job script để cập nhật dung lượng storage cho tất cả websites
 * Chạy định kỳ (ví dụ: mỗi giờ hoặc mỗi ngày)
 * 
 * Cách chạy:
 * - Thêm vào crontab: 0 * * * * php /path/to/tpanel/cron_update_storage.php
 * - Hoặc chạy thủ công: php cron_update_storage.php
 */

require_once __DIR__ . '/../includes/helpers/functions.php';
require_once __DIR__ . '/../includes/classes/StorageManager.php';

$db = Database::getInstance();

// Lấy danh sách websites active
$websites = $db->fetchAll("SELECT id FROM websites WHERE status = 'active'");

$successCount = 0;
$errorCount = 0;
$errors = [];

foreach ($websites as $website) {
    try {
        $storageManager = new StorageManager($website['id']);
        $storageManager->updateStorageFromServer();
        $successCount++;
        echo "✓ Updated storage for website ID: {$website['id']}\n";
    } catch (Exception $e) {
        $errorCount++;
        $errors[] = "Website ID {$website['id']}: " . $e->getMessage();
        echo "✗ Failed to update storage for website ID: {$website['id']} - {$e->getMessage()}\n";
    }
}

echo "\n=== Summary ===\n";
echo "Success: $successCount\n";
echo "Errors: $errorCount\n";

if (!empty($errors)) {
    echo "\nErrors:\n";
    foreach ($errors as $error) {
        echo "- $error\n";
    }
}

