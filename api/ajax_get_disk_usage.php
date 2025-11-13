<?php
/**
 * AJAX endpoint to calculate disk usage
 * Called when user clicks "Tính ngay" button
 */

require_once __DIR__ . '/../includes/helpers/functions.php';
require_once __DIR__ . '/../includes/classes/HostingerFileManager.php';

header('Content-Type: application/json');

$auth = new Auth();
$auth->requireLogin();

$security = Security::getInstance();
$websiteId = $security->validateInt($_GET['id'] ?? 0, 1);

if (!$websiteId) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Website ID không hợp lệ']);
    exit;
}

if (!$auth->hasWebsiteAccess($websiteId)) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Không có quyền truy cập']);
    exit;
}

$db = Database::getInstance();
$website = $db->fetchOne("SELECT * FROM websites WHERE id = ?", [$websiteId]);

if (!$website) {
    echo json_encode(['error' => 'Website không tồn tại']);
    exit;
}

try {
    if (empty($website['sftp_host']) || empty($website['sftp_username']) || empty($website['sftp_password'])) {
        throw new Exception("Thông tin kết nối SFTP/FTP chưa được cấu hình đầy đủ");
    }
    
    $basePath = trim($website['path'] ?? '');
    if (empty($basePath)) {
        $basePath = '/';
    }
    
    $fileManager = new HostingerFileManager(
        $website['sftp_host'],
        $website['sftp_username'],
        $website['sftp_password'],
        $basePath,
        $website['connection_type'] ?? 'ftp',
        $website['sftp_port'] ?? ($website['connection_type'] === 'sftp' ? 22 : 21)
    );
    
    // Calculate disk usage (this may take time)
    $diskUsage = $fileManager->getDirectorySize();
    
    echo json_encode([
        'success' => true,
        'diskUsage' => $diskUsage,
        'diskUsageFormatted' => formatBytes($diskUsage)
    ]);
} catch (Exception $e) {
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}
