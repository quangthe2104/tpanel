<?php
require_once 'includes/functions.php';
require_once 'includes/HostingerFileManager.php';
require_once 'includes/DatabaseManager.php';
require_once 'includes/HostingerBackupManager.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$websiteId = $_GET['id'] ?? 0;

if (!$auth->hasWebsiteAccess($websiteId)) {
    die('Bạn không có quyền truy cập website này');
}

$website = $db->fetchOne("SELECT * FROM websites WHERE id = ?", [$websiteId]);
if (!$website) {
    die('Website không tồn tại');
}

$pageTitle = 'Quản lý: ' . $website['name'];
$tab = $_GET['tab'] ?? 'files';

include 'includes/header.php';

// Get website statistics
$fileManager = null;
$diskUsage = 0;
try {
    $fileManager = new HostingerFileManager(
        $website['sftp_host'],
        $website['sftp_username'],
        $website['sftp_password'],
        $website['path'],
        $website['connection_type'] ?? 'sftp',
        $website['sftp_port'] ?? 22
    );
    $diskUsage = $fileManager->getDirectorySize();
} catch (Exception $e) {
    $diskUsage = 0;
    $fileManager = null;
}

$dbSize = 0;
if ($website['db_name']) {
    try {
        $dbManager = new DatabaseManager(
            $website['db_host'],
            $website['db_name'],
            $website['db_user'],
            $website['db_password']
        );
        $dbSize = $dbManager->getDatabaseSize() * 1024 * 1024; // Convert MB to bytes
    } catch (Exception $e) {
        // Database connection failed
    }
}
$totalSize = $diskUsage + $dbSize;

// Helper function for formatting bytes
function formatBytesSafe($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}
?>

<div class="row mb-4">
    <div class="col-md-12">
        <h2><i class="bi bi-gear"></i> Quản lý: <?php echo escape($website['name']); ?></h2>
        <p class="text-muted"><?php echo escape($website['domain']); ?></p>
    </div>
</div>

<!-- Statistics -->
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card">
            <div class="card-body">
                <h6 class="text-muted">Dung lượng Files</h6>
                <h4><?php echo formatBytesSafe($diskUsage); ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body">
                <h6 class="text-muted">Dung lượng Database</h6>
                <h4><?php echo formatBytesSafe($dbSize); ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body">
                <h6 class="text-muted">Tổng dung lượng</h6>
                <h4><?php echo formatBytesSafe($totalSize); ?></h4>
            </div>
        </div>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link <?php echo $tab == 'files' ? 'active' : ''; ?>" href="?id=<?php echo $websiteId; ?>&tab=files">
            <i class="bi bi-folder"></i> File Manager
        </a>
    </li>
    <?php if ($website['db_name'] && $auth->canManageDatabase($websiteId)): ?>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab == 'database' ? 'active' : ''; ?>" href="?id=<?php echo $websiteId; ?>&tab=database">
            <i class="bi bi-database"></i> Database Manager
        </a>
    </li>
    <?php endif; ?>
    <?php if ($auth->canBackup($websiteId)): ?>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab == 'backup' ? 'active' : ''; ?>" href="?id=<?php echo $websiteId; ?>&tab=backup">
            <i class="bi bi-archive"></i> Backup & Restore
        </a>
    </li>
    <?php endif; ?>
</ul>

<!-- Tab Content -->
<?php if ($tab == 'files' && $auth->canManageFiles($websiteId)): ?>
    <?php 
    if (!$fileManager) {
        echo '<div class="alert alert-danger">Không thể kết nối đến Hostinger. Vui lòng kiểm tra cấu hình SFTP/FTP.</div>';
    } else {
        include 'includes/hostinger_file_manager_tab.php'; 
    }
    ?>
<?php elseif ($tab == 'database' && $auth->canManageDatabase($websiteId)): ?>
    <?php include 'includes/database_manager_tab.php'; ?>
<?php elseif ($tab == 'backup' && $auth->canBackup($websiteId)): ?>
    <?php include 'includes/hostinger_backup_tab.php'; ?>
<?php else: ?>
    <div class="alert alert-warning">Bạn không có quyền truy cập tính năng này.</div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
