<?php
/**
 * Fix timezone for existing backups
 * Convert UTC timestamps to GMT+7
 */

require_once __DIR__ . '/../includes/helpers/functions.php';

$auth = new Auth();
$auth->requireLogin();

// Chỉ admin mới chạy được
if (!$auth->isAdmin()) {
    die('Chỉ admin mới có quyền chạy script này');
}

$db = Database::getInstance();

// Lấy tất cả backup
$backups = $db->fetchAll("SELECT id, created_at, expires_at FROM backups");

echo "<h2>Fix Backup Timezone (UTC → GMT+7)</h2>";
echo "<p>Tổng số backup: " . count($backups) . "</p>";

$updated = 0;

foreach ($backups as $backup) {
    // Chuyển đổi từ UTC sang GMT+7 (thêm 7 giờ)
    $createdAt = $backup['created_at'];
    $expiresAt = $backup['expires_at'];
    
    // Parse timestamp và thêm 7 giờ
    $newCreatedAt = date('Y-m-d H:i:s', strtotime($createdAt) + (7 * 3600));
    $newExpiresAt = $expiresAt ? date('Y-m-d H:i:s', strtotime($expiresAt) + (7 * 3600)) : null;
    
    // Update database
    $db->query(
        "UPDATE backups SET created_at = ?, expires_at = ? WHERE id = ?",
        [$newCreatedAt, $newExpiresAt, $backup['id']]
    );
    
    echo "<p>Backup #{$backup['id']}: {$createdAt} → {$newCreatedAt}</p>";
    $updated++;
}

echo "<hr>";
echo "<p><strong>✓ Đã cập nhật {$updated} backup</strong></p>";
echo "<p><a href='" . BASE_URL . "'>← Quay lại trang chủ</a></p>";

