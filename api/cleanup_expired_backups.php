<?php
/**
 * Cleanup Expired Backups
 * Xóa tất cả backup đã hết hạn (cả file và database record)
 * Có thể chạy thủ công hoặc qua cron job
 */

require_once __DIR__ . '/../includes/helpers/functions.php';

$auth = new Auth();
$auth->requireLogin();

// Chỉ admin mới có quyền chạy cleanup
if (!$auth->isAdmin()) {
    die('Bạn không có quyền thực hiện thao tác này');
}

$db = Database::getInstance();

// Lấy tất cả website
$websites = $db->fetchAll("SELECT * FROM websites");

$results = [];
$totalDeleted = 0;
$totalFailed = 0;

foreach ($websites as $website) {
    try {
        require_once __DIR__ . '/../includes/classes/HostingerBackupManager.php';
        
        $backupManager = new HostingerBackupManager($website['id']);
        
        // Lấy danh sách backup hết hạn trước khi xóa
        $expiredBackups = $db->fetchAll(
            "SELECT id, filename, expires_at FROM backups 
             WHERE website_id = ? 
             AND status = 'completed' 
             AND expires_at IS NOT NULL 
             AND expires_at < NOW()",
            [$website['id']]
        );
        
        if (count($expiredBackups) > 0) {
            $results[] = [
                'website' => $website['name'],
                'expired_count' => count($expiredBackups),
                'backups' => $expiredBackups
            ];
            
            // Gọi getBackups() để trigger cleanup
            $backupManager->getBackups();
            
            // Kiểm tra xem đã xóa thành công chưa
            $remainingExpired = $db->fetchAll(
                "SELECT id FROM backups 
                 WHERE website_id = ? 
                 AND status = 'completed' 
                 AND expires_at IS NOT NULL 
                 AND expires_at < NOW()",
                [$website['id']]
            );
            
            $deleted = count($expiredBackups) - count($remainingExpired);
            $totalDeleted += $deleted;
            $totalFailed += count($remainingExpired);
            
            $results[count($results) - 1]['deleted'] = $deleted;
            $results[count($results) - 1]['failed'] = count($remainingExpired);
        }
        
    } catch (Exception $e) {
        $results[] = [
            'website' => $website['name'],
            'error' => $e->getMessage()
        ];
    }
}

// Hiển thị kết quả
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Cleanup Expired Backups</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .summary { background: #f0f0f0; padding: 15px; margin-bottom: 20px; border-radius: 5px; }
        .success { color: green; }
        .error { color: red; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #4CAF50; color: white; }
        tr:nth-child(even) { background-color: #f2f2f2; }
    </style>
</head>
<body>
    <h1>Cleanup Expired Backups</h1>
    
    <div class="summary">
        <h3>Tổng kết:</h3>
        <p class="success">✓ Đã xóa thành công: <strong><?php echo $totalDeleted; ?></strong> backup</p>
        <?php if ($totalFailed > 0): ?>
            <p class="error">✗ Không thể xóa: <strong><?php echo $totalFailed; ?></strong> backup (file không tồn tại hoặc lỗi kết nối)</p>
        <?php endif; ?>
    </div>
    
    <?php if (count($results) > 0): ?>
        <h3>Chi tiết:</h3>
        <table>
            <tr>
                <th>Website</th>
                <th>Số backup hết hạn</th>
                <th>Đã xóa</th>
                <th>Không xóa được</th>
                <th>Chi tiết</th>
            </tr>
            <?php foreach ($results as $result): ?>
                <tr>
                    <td><?php echo htmlspecialchars($result['website']); ?></td>
                    <td><?php echo isset($result['expired_count']) ? $result['expired_count'] : 'N/A'; ?></td>
                    <td class="success"><?php echo isset($result['deleted']) ? $result['deleted'] : 'N/A'; ?></td>
                    <td class="error"><?php echo isset($result['failed']) ? $result['failed'] : 'N/A'; ?></td>
                    <td>
                        <?php if (isset($result['error'])): ?>
                            <span class="error">Lỗi: <?php echo htmlspecialchars($result['error']); ?></span>
                        <?php elseif (isset($result['backups'])): ?>
                            <?php foreach ($result['backups'] as $backup): ?>
                                - <?php echo htmlspecialchars($backup['filename']); ?> (hết hạn: <?php echo $backup['expires_at']; ?>)<br>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p>Không có backup nào hết hạn.</p>
    <?php endif; ?>
    
    <p style="margin-top: 30px;">
        <a href="<?php echo BASE_URL; ?>">← Quay lại trang chủ</a>
    </p>
</body>
</html>

