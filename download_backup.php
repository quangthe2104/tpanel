<?php
require_once 'includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

$backupId = $_GET['id'] ?? 0;
$db = Database::getInstance();

$backup = $db->fetchOne("SELECT * FROM backups WHERE id = ?", [$backupId]);

if (!$backup) {
    die('Backup không tồn tại');
}

// Check permission
if (!$auth->isAdmin() && $backup['user_id'] != $auth->getUserId()) {
    die('Bạn không có quyền download backup này');
}

if (!file_exists($backup['file_path'])) {
    die('File backup không tồn tại');
}

// Log download
$auth->logActivity($auth->getUserId(), $backup['website_id'], 'backup_downloaded', "Backup ID: $backupId");

// Download file
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($backup['file_path']) . '"');
header('Content-Length: ' . filesize($backup['file_path']));
readfile($backup['file_path']);
exit;
