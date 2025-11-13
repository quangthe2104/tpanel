<?php
require_once __DIR__ . '/../includes/helpers/functions.php';
require_once __DIR__ . '/../includes/classes/StorageManager.php';

$auth = new Auth();
$auth->requireLogin();

if (!$auth->isAdmin()) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Permission denied']);
    exit;
}

$security = Security::getInstance();
$websiteId = $security->validateInt($_GET['id'] ?? 0, 1);
if (!$websiteId) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Website ID không hợp lệ']);
    exit;
}

try {
    $storageManager = new StorageManager($websiteId);
    $usedStorage = $storageManager->updateStorageFromServer();
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'used_storage' => $usedStorage,
        'used_storage_formatted' => formatBytes($usedStorage)
    ]);
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

