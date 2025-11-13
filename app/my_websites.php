<?php
require_once __DIR__ . '/../includes/helpers/functions.php';
require_once __DIR__ . '/../includes/classes/StorageManager.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$pageTitle = 'Website của tôi';

$userId = $auth->getUserId();

if ($auth->isAdmin()) {
    $websites = $db->fetchAll("SELECT * FROM websites ORDER BY name");
} else {
    $websites = $db->fetchAll(
        "SELECT w.* FROM websites w 
         INNER JOIN user_website_permissions uwp ON w.id = uwp.website_id 
         WHERE uwp.user_id = ? AND w.status = 'active'
         ORDER BY w.name",
        [$userId]
    );
}

// Lấy thông tin bổ sung cho mỗi website
foreach ($websites as &$website) {
    // Lấy storage từ DB
    $storageManager = new StorageManager($website['id']);
    $storageInfo = $storageManager->getStorageFromDB();
    $website['disk_usage'] = $storageInfo['used'] ?? 0;
    $website['total_storage'] = $storageInfo['total'] ?? $website['total_storage'] ?? null;
    
    // Đếm số backup
    $website['backup_count'] = $db->fetchOne(
        "SELECT COUNT(*) as count FROM backups WHERE website_id = ? AND status = 'completed'",
        [$website['id']]
    )['count'];
    
    // Đếm số backup đang xử lý
    $website['backup_in_progress'] = $db->fetchOne(
        "SELECT COUNT(*) as count FROM backups WHERE website_id = ? AND status = 'in_progress'",
        [$website['id']]
    )['count'];
    
    // Đếm số database (chỉ cần biết có hay không)
    $website['has_database'] = !empty($website['db_name']);
}
unset($website);

include __DIR__ . '/../includes/header.php';
?>

<style>
.circular-chart {
    display: block;
    margin: 0 auto;
}
.circle {
    transition: stroke-dasharray 0.3s ease;
}
</style>

<div class="row mb-4">
    <div class="col-md-12">
        <h2><i class="bi bi-collection"></i> Website của tôi</h2>
        <p class="text-muted">Danh sách website bạn có quyền quản lý</p>
    </div>
</div>

<?php if (empty($websites)): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle"></i> Bạn chưa có quyền quản lý website nào. Vui lòng liên hệ admin.
    </div>
<?php else: ?>
    <div class="row">
        <?php foreach ($websites as $website): ?>
        <div class="col-md-4 mb-4">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title">
                        <i class="bi bi-globe text-primary"></i> <?php echo escape($website['name']); ?>
                    </h5>
                    <p class="card-text">
                        <small class="text-muted">
                            <i class="bi bi-link-45deg"></i> <?php echo escape($website['domain']); ?>
                        </small>
                    </p>
                    <!-- Storage and Stats - Split 50/50 Layout -->
                    <div class="mb-3">
                        <div class="row g-2">
                            <!-- Left Side: Circular Progress Chart Only -->
                            <div class="col-6 d-flex align-items-center justify-content-center">
                                <?php 
                                $percent = 0;
                                $chartColor = '#6c757d';
                                if ($website['disk_usage'] > 0 && $website['total_storage']) {
                                    $percent = ($website['disk_usage'] / $website['total_storage']) * 100;
                                    if ($percent > 100) $percent = 100;
                                    $chartColor = $percent > 80 ? '#dc3545' : ($percent > 60 ? '#ffc107' : '#28a745');
                                }
                                ?>
                                <div class="position-relative" style="width: 110px; height: 110px;">
                                    <svg class="circular-chart" viewBox="0 0 36 36" style="width: 110px; height: 110px;">
                                        <path class="circle-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="#e0e0e0" stroke-width="3"/>
                                        <path class="circle" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="<?php echo $chartColor; ?>" stroke-width="3" stroke-dasharray="<?php echo $percent; ?>, 100" transform="rotate(-90 18 18)"/>
                                    </svg>
                                    <div class="position-absolute top-50 start-50 translate-middle text-center" style="font-size: 1rem; font-weight: bold; color: <?php echo $chartColor; ?>; line-height: 1;">
                                        <?php echo round($percent); ?>%
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Right Side: Stats with Storage Info -->
                            <div class="col-6">
                                <div class="d-flex flex-column justify-content-center h-100">
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <div class="d-flex align-items-center">
                                            <i class="bi bi-hdd text-muted me-1" style="font-size: 0.85rem;"></i>
                                            <small class="text-muted" style="font-size: 0.7rem;">Dung lượng:</small>
                                        </div>
                                        <div>
                                            <small class="fw-bold" style="font-size: 0.7rem;">
                                                <?php if ($website['disk_usage'] > 0): ?>
                                                    <?php echo formatBytes($website['disk_usage']); ?>
                                                    <?php if ($website['total_storage']): ?>
                                                        <span class="text-muted">/ <?php echo formatBytes($website['total_storage']); ?></span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">N/A</span>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <div class="d-flex align-items-center">
                                            <i class="bi bi-archive text-muted me-1" style="font-size: 0.85rem;"></i>
                                            <small class="text-muted" style="font-size: 0.7rem;">Backup:</small>
                                        </div>
                                        <div>
                                            <?php if ($website['backup_count'] > 0): ?>
                                                <span class="badge bg-success" style="font-size: 0.7rem;"><?php echo $website['backup_count']; ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary" style="font-size: 0.7rem;">0</span>
                                            <?php endif; ?>
                                            <?php if ($website['backup_in_progress'] > 0): ?>
                                                <span class="badge bg-warning ms-1" style="font-size: 0.7rem;"><?php echo $website['backup_in_progress']; ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <div class="d-flex align-items-center">
                                            <i class="bi bi-database text-muted me-1" style="font-size: 0.85rem;"></i>
                                            <small class="text-muted" style="font-size: 0.7rem;">Database:</small>
                                        </div>
                                        <div>
                                            <?php if ($website['has_database']): ?>
                                                <span class="badge bg-info" style="font-size: 0.7rem;">1</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary" style="font-size: 0.7rem;">0</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center justify-content-between">
                                        <div class="d-flex align-items-center">
                                            <i class="bi bi-hdd-rack text-muted me-1" style="font-size: 0.85rem;"></i>
                                            <small class="text-muted" style="font-size: 0.7rem;">Kết nối:</small>
                                        </div>
                                        <div>
                                            <span class="badge bg-<?php echo strtolower($website['connection_type'] ?? 'ftp') === 'sftp' ? 'primary' : 'secondary'; ?>" style="font-size: 0.7rem;">
                                                <?php echo strtoupper($website['connection_type'] ?? 'FTP'); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <a href="<?php echo websiteUrl($website['id']); ?>" class="btn btn-primary">
                            <i class="bi bi-gear"></i> Quản lý Website
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
