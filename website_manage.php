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
$connectionError = null;
$diskUsageCalculating = false;

try {
    if (empty($website['sftp_host']) || empty($website['sftp_username']) || empty($website['sftp_password'])) {
        throw new Exception("Thông tin kết nối SFTP/FTP chưa được cấu hình đầy đủ");
    }
    
    // Use empty string or / for auto-detection with FTP
    $basePath = trim($website['path'] ?? '');
    if (empty($basePath)) {
        $basePath = '/'; // Will auto-detect for FTP
    }
    
    $fileManager = new HostingerFileManager(
        $website['sftp_host'],
        $website['sftp_username'],
        $website['sftp_password'],
        $basePath,
        $website['connection_type'] ?? 'ftp',
        $website['sftp_port'] ?? ($website['connection_type'] === 'sftp' ? 22 : 21)
    );
    
    // Skip getDirectorySize() on initial load - it's too slow
    // Will calculate on demand or show "Đang tính toán..."
    $diskUsageCalculating = true;
} catch (Exception $e) {
    $diskUsage = 0;
    $fileManager = null;
    $connectionError = $e->getMessage();
    error_log("FileManager connection error: " . $connectionError);
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
                <h4 id="disk-usage-display">
                    <?php if ($diskUsageCalculating): ?>
                        <span class="text-muted"><i class="bi bi-hourglass-split"></i> Đang tính toán...</span>
                    <?php else: ?>
                        <?php echo formatBytes($diskUsage); ?>
                    <?php endif; ?>
                </h4>
                <?php if ($diskUsageCalculating && $fileManager): ?>
                    <small><a href="#" onclick="calculateDiskUsage(); return false;" class="text-primary">Tính ngay</a></small>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body">
                <h6 class="text-muted">Dung lượng Database</h6>
                <h4><?php echo formatBytes($dbSize); ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body">
                <h6 class="text-muted">Tổng dung lượng</h6>
                <h4 id="total-size-display">
                    <?php if ($diskUsageCalculating): ?>
                        <span class="text-muted"><?php echo formatBytes($dbSize); ?> + ...</span>
                    <?php else: ?>
                        <?php echo formatBytes($totalSize); ?>
                    <?php endif; ?>
                </h4>
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
    <?php include 'includes/hostinger_file_manager_tab.php'; ?>
<?php elseif ($tab == 'database' && $auth->canManageDatabase($websiteId)): ?>
    <?php include 'includes/database_manager_tab.php'; ?>
<?php elseif ($tab == 'backup' && $auth->canBackup($websiteId)): ?>
    <?php include 'includes/hostinger_backup_tab.php'; ?>
<?php else: ?>
    <div class="alert alert-warning">Bạn không có quyền truy cập tính năng này.</div>
<?php endif; ?>

<script>
function calculateDiskUsage() {
    const displayEl = document.getElementById('disk-usage-display');
    const totalEl = document.getElementById('total-size-display');
    
    if (!displayEl) return;
    
    // Show loading
    displayEl.innerHTML = '<span class="text-muted"><i class="bi bi-hourglass-split"></i> Đang tính toán...</span>';
    
    // Make AJAX request
    fetch('ajax_get_disk_usage.php?id=<?php echo $websiteId; ?>')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayEl.innerHTML = data.diskUsageFormatted;
                
                // Update total size
                if (totalEl) {
                    const dbSize = <?php echo $dbSize; ?>;
                    const totalSize = data.diskUsage + dbSize;
                    totalEl.innerHTML = formatBytes(totalSize);
                }
            } else {
                displayEl.innerHTML = '<span class="text-danger">Lỗi: ' + (data.error || 'Không thể tính toán') + '</span>';
            }
        })
        .catch(error => {
            displayEl.innerHTML = '<span class="text-danger">Lỗi kết nối</span>';
            console.error('Error:', error);
        });
}

function formatBytes(bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
}
</script>

<?php include 'includes/footer.php'; ?>
