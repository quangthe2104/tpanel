<?php
/**
 * AJAX endpoint to check backup status
 * Returns JSON with backup status information
 */

require_once __DIR__ . '/../includes/helpers/functions.php';
require_once __DIR__ . '/../includes/classes/HostingerBackupManager.php';

header('Content-Type: application/json');

$auth = new Auth();
$auth->requireLogin();

$security = Security::getInstance();
$backupId = $security->validateInt($_GET['id'] ?? 0, 1);

if (!$backupId) {
    echo json_encode(['error' => 'Backup ID không hợp lệ']);
    exit;
}

$db = Database::getInstance();

// Get backup info
$backup = $db->fetchOne(
    "SELECT * FROM backups WHERE id = ?",
    [$backupId]
);

if (!$backup) {
    echo json_encode(['error' => 'Backup không tồn tại']);
    exit;
}

// Check permission
if (!$auth->isAdmin() && $backup['user_id'] != $auth->getUserId()) {
    echo json_encode(['error' => 'Bạn không có quyền xem backup này']);
    exit;
}

// If backup is in_progress, try to check status from server
if ($backup['status'] === 'in_progress') {
    try {
        $website = $db->fetchOne("SELECT * FROM websites WHERE id = ?", [$backup['website_id']]);
        if ($website) {
            $backupManager = new HostingerBackupManager($backup['website_id'], $backup['user_id']);
            $backupManager->checkBackupStatus($backupId);
            
            // Get updated backup info
            $backup = $db->fetchOne(
                "SELECT * FROM backups WHERE id = ?",
                [$backupId]
            );
        }
    } catch (Exception $e) {
        // Log error but continue with current status
        error_log("Error checking backup status: " . $e->getMessage());
    }
}

// Get website info for building direct download URL (chỉ lấy domain, không lấy url vì có thể chưa có trong DB)
$website = $db->fetchOne("SELECT domain FROM websites WHERE id = ?", [$backup['website_id']]);

// Return backup status
$response = [
    'status' => $backup['status'],
    'file_size' => $backup['file_size'],
    'expires_at' => $backup['expires_at'],
    'created_at' => $backup['created_at'],
    'remote_path' => $backup['remote_path'] ?? null,
    'website_url' => !empty($website['domain']) ? 'https://' . $website['domain'] : null,
    'website_domain' => $website['domain'] ?? null
];

echo json_encode($response);

