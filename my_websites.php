<?php
require_once 'includes/functions.php';

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

include 'includes/header.php';
?>

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
                    <p class="card-text">
                        <small class="text-muted">
                            <i class="bi bi-folder"></i> <?php echo escape($website['path']); ?>
                        </small>
                    </p>
                    
                    <?php
                    // Get website stats
                    try {
                        require_once 'includes/HostingerFileManager.php';
                        $fileManager = new HostingerFileManager(
                            $website['sftp_host'] ?? '',
                            $website['sftp_username'] ?? '',
                            $website['sftp_password'] ?? '',
                            $website['path'],
                            $website['connection_type'] ?? 'sftp',
                            $website['sftp_port'] ?? 22
                        );
                        $diskUsage = $fileManager->getDirectorySize();
                        $diskUsageFormatted = $fileManager->formatBytes($diskUsage);
                    } catch (Exception $e) {
                        $diskUsageFormatted = 'N/A';
                    }
                    ?>
                    
                    <div class="mb-3">
                        <small class="text-muted">Dung lượng sử dụng:</small>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar" role="progressbar" style="width: 100%"></div>
                        </div>
                        <small><?php echo $diskUsageFormatted; ?></small>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <a href="website_manage.php?id=<?php echo $website['id']; ?>" class="btn btn-primary">
                            <i class="bi bi-gear"></i> Quản lý Website
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
