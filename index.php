<?php
require_once __DIR__ . '/includes/helpers/functions.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$pageTitle = 'Trang chủ';

// Get statistics
if ($auth->isAdmin()) {
    $totalWebsites = $db->fetchOne("SELECT COUNT(*) as count FROM websites")['count'];
    $totalUsers = $db->fetchOne("SELECT COUNT(*) as count FROM users WHERE role = 'user'")['count'];
    $totalBackups = $db->fetchOne("SELECT COUNT(*) as count FROM backups WHERE status = 'completed'")['count'];
} else {
    $userId = $auth->getUserId();
    $totalWebsites = $db->fetchOne(
        "SELECT COUNT(DISTINCT website_id) as count FROM user_website_permissions WHERE user_id = ?",
        [$userId]
    )['count'];
    $totalBackups = $db->fetchOne(
        "SELECT COUNT(*) as count FROM backups WHERE user_id = ? AND status = 'completed'",
        [$userId]
    )['count'];
}

include __DIR__ . '/includes/header.php';
?>

<div class="row mb-4">
    <div class="col-md-12">
        <h2><i class="bi bi-speedometer2"></i> Dashboard</h2>
        <p class="text-muted">Tổng quan hệ thống quản lý website</p>
    </div>
</div>

<div class="row">
    <div class="col-md-4">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-white-50 small">Tổng Website</div>
                    <div class="stat-value"><?php echo $totalWebsites; ?></div>
                </div>
                <i class="bi bi-globe" style="font-size: 3rem; opacity: 0.3;"></i>
            </div>
        </div>
    </div>
    
    <?php if ($auth->isAdmin()): ?>
    <div class="col-md-4">
        <div class="stat-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-white-50 small">Tổng Người dùng</div>
                    <div class="stat-value"><?php echo $totalUsers; ?></div>
                </div>
                <i class="bi bi-people" style="font-size: 3rem; opacity: 0.3;"></i>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <div class="col-md-4">
        <div class="stat-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-white-50 small">Tổng Backup</div>
                    <div class="stat-value"><?php echo $totalBackups; ?></div>
                </div>
                <i class="bi bi-archive" style="font-size: 3rem; opacity: 0.3;"></i>
            </div>
        </div>
    </div>
</div>

<div class="row mt-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-clock-history"></i> Hoạt động gần đây</h5>
            </div>
            <div class="card-body">
                <?php
                // Lọc hoạt động: Admin thấy tất cả, User chỉ thấy hoạt động của mình
                $userId = $auth->getUserId();
                if ($auth->isAdmin()) {
                    $logs = $db->fetchAll(
                        "SELECT al.*, u.username, w.name as website_name 
                         FROM activity_logs al 
                         LEFT JOIN users u ON al.user_id = u.id 
                         LEFT JOIN websites w ON al.website_id = w.id 
                         ORDER BY al.created_at DESC 
                         LIMIT 20"
                    );
                } else {
                    $logs = $db->fetchAll(
                        "SELECT al.*, u.username, w.name as website_name 
                         FROM activity_logs al 
                         LEFT JOIN users u ON al.user_id = u.id 
                         LEFT JOIN websites w ON al.website_id = w.id 
                         WHERE al.user_id = ?
                         ORDER BY al.created_at DESC 
                         LIMIT 20",
                        [$userId]
                    );
                }
                
                if (empty($logs)):
                ?>
                    <p class="text-muted">Chưa có hoạt động nào</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Thời gian</th>
                                    <?php if ($auth->isAdmin()): ?>
                                    <th>Người dùng</th>
                                    <?php endif; ?>
                                    <th>Website</th>
                                    <th>Hoạt động</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><?php echo formatDate($log['created_at']); ?></td>
                                    <?php if ($auth->isAdmin()): ?>
                                    <td><?php echo escape($log['username'] ?? 'N/A'); ?></td>
                                    <?php endif; ?>
                                    <td><?php echo escape($log['website_name'] ?? 'Hệ thống'); ?></td>
                                    <td>
                                        <span class="badge bg-info">
                                            <?php echo formatActivityAction($log['action'], $log['description']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
