<?php
require_once __DIR__ . '/../includes/helpers/functions.php';

$auth = new Auth();
$auth->requireAdmin();

$security = Security::getInstance();
$userId = $security->validateInt($_GET['user_id'] ?? 0, 1);

if (!$userId) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
    exit;
}

$db = Database::getInstance();
$permissions = $db->fetchAll(
    "SELECT * FROM user_website_permissions WHERE user_id = ?",
    [$userId]
);

$permissionMap = [];
foreach ($permissions as $perm) {
    $permissionMap[$perm['website_id']] = [
        'website_id' => (int)$perm['website_id'],
        'can_manage_files' => !empty($perm['can_manage_files']),
        'can_manage_database' => !empty($perm['can_manage_database']),
        'can_backup' => !empty($perm['can_backup']),
        'can_view_stats' => !empty($perm['can_view_stats']),
    ];
}

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'permissions' => array_values($permissionMap)
]);

