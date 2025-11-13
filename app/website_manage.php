<?php
require_once __DIR__ . '/../includes/helpers/functions.php';
require_once __DIR__ . '/../includes/classes/HostingerFileManager.php';
require_once __DIR__ . '/../includes/classes/DatabaseManager.php';
require_once __DIR__ . '/../includes/classes/HostingerBackupManager.php';
require_once __DIR__ . '/../includes/classes/StorageManager.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$security = Security::getInstance();
$websiteId = $security->validateInt($_GET['id'] ?? 0, 1);

if (!$websiteId) {
    die('Website ID không hợp lệ');
}

if (!$auth->hasWebsiteAccess($websiteId)) {
    die('Bạn không có quyền truy cập website này');
}

$website = $db->fetchOne("SELECT * FROM websites WHERE id = ?", [$websiteId]);
if (!$website) {
    die('Website không tồn tại');
}

$pageTitle = 'Quản lý: ' . $website['name'];
$tab = isset($_GET['tab']) && in_array($_GET['tab'], ['files', 'database', 'backup', 'stats']) ? $_GET['tab'] : 'files';

// Start output buffering VERY early to prevent "headers already sent" errors
// Must be before any output, including from included files
if (ob_get_level() === 0) {
    ob_start();
}

// Get website statistics and file manager BEFORE handling POST
// This is needed because POST handling needs fileManager
$fileManager = null;
$diskUsage = 0;
$connectionError = null;
$diskUsageCalculating = false;

// Lấy storage từ DB (nhanh)
$storageManager = new StorageManager($websiteId);
$storageInfo = $storageManager->getStorageFromDB();
$diskUsage = $storageInfo['used'] ?? 0;
$totalStorage = $storageInfo['total'] ?? null;
$storageUpdatedAt = $storageInfo['updated_at'] ?? null;

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
    
    // Nếu chưa có storage trong DB hoặc quá cũ (> 24h), đánh dấu cần cập nhật
    if (!$diskUsage || ($storageUpdatedAt && (time() - strtotime($storageUpdatedAt)) > 86400)) {
        $diskUsageCalculating = true;
    }
} catch (Exception $e) {
    $fileManager = null;
    $connectionError = $e->getMessage();
}

$dbSize = 0;
$dbError = null;
if ($website['db_name']) {
    try {
        $dbHost = $website['db_host'] ?? 'localhost';
        
        $dbManager = new DatabaseManager(
            $dbHost,
            $website['db_name'],
            $website['db_user'],
            $website['db_password']
        );
        $dbSize = $dbManager->getDatabaseSize() * 1024 * 1024; // Convert MB to bytes
    } catch (Exception $e) {
        $dbError = $e->getMessage();
    }
}
$totalSize = $diskUsage + $dbSize;

// Handle POST requests for file operations BEFORE outputting HTML
// This must be done before include header.php to allow redirects
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $fileManager && $tab === 'files' && $auth->canManageFiles($websiteId)) {
    $security = Security::getInstance();
    $security->checkCSRF();
    
    $action = isset($_GET['action']) ? $_GET['action'] : 'list';
    $currentPath = isset($_GET['path']) ? $_GET['path'] : '';
    
    // Sanitize path
    $currentPath = $security->sanitizePath($currentPath);
    
    if ($currentPath && $fileManager) {
        $fileManager->setPath($currentPath);
    }
    
    $auth->logActivity($auth->getUserId(), $websiteId, 'file_operation', 'File operation performed');
    
    if (isset($_POST['create_file'])) {
        $filename = $security->sanitizeFilename(trim($_POST['filename'] ?? ''));
        $content = $_POST['content'] ?? '';
        if (empty($filename)) {
            $_SESSION['file_manager_error'] = 'Tên file không được để trống!';
        } elseif ($fileManager->createFile($filename, $content)) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            $redirectUrl = websiteUrl($websiteId, 'files', $currentPath);
            header('Location: ' . $redirectUrl);
            exit;
        } else {
            $_SESSION['file_manager_error'] = 'Không thể tạo file! Kiểm tra quyền truy cập và error log.';
        }
    } elseif (isset($_POST['create_folder'])) {
        $dirname = $security->sanitizeFilename(trim($_POST['dirname'] ?? ''));
        if (empty($dirname)) {
            $_SESSION['file_manager_error'] = 'Tên thư mục không được để trống!';
        } elseif ($fileManager->createDirectory($dirname)) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            $redirectUrl = websiteUrl($websiteId, 'files', $currentPath);
            header('Location: ' . $redirectUrl);
            exit;
        } else {
            $_SESSION['file_manager_error'] = 'Không thể tạo thư mục! Kiểm tra quyền truy cập và error log.';
        }
    } elseif (isset($_POST['bulk_action']) && $_POST['bulk_action'] === 'delete' && isset($_POST['selected_items'])) {
        $selectedItems = $_POST['selected_items'];
        $movedCount = 0;
        $failedCount = 0;
        $errors = [];
        
        if (empty($selectedItems) || !is_array($selectedItems)) {
            $_SESSION['file_manager_error'] = 'Vui lòng chọn ít nhất một file/thư mục để xóa!';
        } else {
            foreach ($selectedItems as $path) {
                // Sanitize path to prevent path traversal
                $path = $security->sanitizePath($path);
                if ($fileManager->moveToTrash($path)) {
                    $movedCount++;
                    $auth->logActivity($auth->getUserId(), $websiteId, 'file_moved_to_trash', "Moved to trash: $path");
                } else {
                    $failedCount++;
                    $errors[] = basename($path);
                }
            }
            
            if ($movedCount > 0) {
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                $redirectUrl = websiteUrl($websiteId, 'files', $currentPath);
                if ($failedCount > 0) {
                    $redirectUrl .= '&error=' . urlencode("Đã chuyển $movedCount item(s) vào thùng rác. Không thể chuyển " . implode(', ', $errors));
                } else {
                    $redirectUrl .= '&success=' . urlencode("Đã chuyển $movedCount item(s) vào thùng rác thành công");
                }
                header('Location: ' . $redirectUrl);
                exit;
            } else {
                $_SESSION['file_manager_error'] = 'Không thể chuyển bất kỳ item nào vào thùng rác!';
            }
        }
    } elseif (isset($_POST['save_file'])) {
        $path = $security->sanitizePath($_POST['path'] ?? '');
        $content = $_POST['content'] ?? '';
        if ($fileManager->saveFileContent($path, $content)) {
            $_SESSION['file_manager_success'] = 'Lưu file thành công!';
        } else {
            $_SESSION['file_manager_error'] = 'Không thể lưu file!';
        }
    } elseif (isset($_FILES['upload_file'])) {
        // Validate file upload
        $validation = $security->validateFileUpload($_FILES['upload_file'], [], MAX_UPLOAD_SIZE);
        if (!$validation['valid']) {
            $_SESSION['file_manager_error'] = $validation['error'];
        } else {
            $dest = $security->sanitizePath($_POST['upload_path'] ?? '');
            if ($fileManager->uploadFile($_FILES['upload_file'], $dest)) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            $redirectUrl = websiteUrl($websiteId, 'files', $currentPath);
                header('Location: ' . $redirectUrl);
                exit;
            } else {
                $_SESSION['file_manager_error'] = 'Upload thất bại!';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $security = Security::getInstance();
    $security->checkCSRF();
    
    if (isset($_POST['create_backup'])) {
        if ($auth->canBackup($websiteId)) {
            require_once __DIR__ . '/../includes/classes/HostingerBackupManager.php';
            $backupManager = new HostingerBackupManager($websiteId, $auth->getUserId());
            
            $type = $_POST['backup_type'] ?? '';
            // Validate backup type
            if (!in_array($type, ['full', 'files', 'database'])) {
                $_SESSION['backup_error'] = 'Loại backup không hợp lệ!';
            } else {
            
                try {
                    $result = null;
                    if ($type == 'full') {
                        $result = $backupManager->createFullBackup();
                    } elseif ($type == 'files') {
                        $result = $backupManager->createFilesBackup();
                    } elseif ($type == 'database') {
                        $result = $backupManager->createDatabaseBackup();
                    }
                    
                    $auth->logActivity($auth->getUserId(), $websiteId, 'backup_created', "Backup type: $type");
                    
                    while (ob_get_level() > 0) {
                        ob_end_clean();
                    }
                    header('Location: ' . websiteUrl($websiteId, 'backup'));
                    exit;
                } catch (Exception $e) {
                    $_SESSION['backup_error'] = "Lỗi: " . $e->getMessage();
                    while (ob_get_level() > 0) {
                        ob_end_clean();
                    }
                    header('Location: ' . websiteUrl($websiteId, 'backup') . '?error=1&message=' . urlencode($e->getMessage()));
                    exit;
                }
            }
        }
    }
}

// Handle delete backup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_backup'])) {
    if ($auth->canBackup($websiteId)) {
        require_once __DIR__ . '/../includes/classes/HostingerBackupManager.php';
        $backupManager = new HostingerBackupManager($websiteId, $auth->getUserId());
        
        $backupId = $_POST['backup_id'];
        $result = $backupManager->deleteBackup($backupId);
        $auth->logActivity($auth->getUserId(), $websiteId, 'backup_deleted', "Backup ID: $backupId");
        
        // Redirect để tránh resubmit khi refresh
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if ($result['success']) {
            header('Location: ' . websiteUrl($websiteId, 'backup') . '?success=1&message=' . urlencode($result['message'] ?? "Xóa backup thành công!"));
        } else {
            header('Location: ' . websiteUrl($websiteId, 'backup') . '?error=1&message=' . urlencode($result['message'] ?? "Lỗi khi xóa backup!"));
        }
        exit;
    } else {
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-md-12">
        <h2><i class="bi bi-gear"></i> Quản lý: <?php echo escape($website['name']); ?></h2>
        <p class="text-muted"><?php echo escape($website['domain']); ?></p>
    </div>
</div>

<!-- Statistics -->
<style>
.stats-card {
    height: 100%;
    display: flex;
    flex-direction: column;
}
.stats-card .card-body {
    flex: 1;
    display: flex;
    flex-direction: column;
}
.stats-card h4 {
    margin-bottom: 0.5rem;
}
.stats-card .text-muted {
    margin-top: auto;
}
</style>
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card stats-card">
            <div class="card-body">
                <h6 class="text-muted">Dung lượng Files</h6>
                <h4 id="disk-usage-display" style="display: flex; align-items: center; gap: 8px;">
                    <?php if ($diskUsage): ?>
                        <span><?php echo formatBytes($diskUsage); ?></span>
                        <?php if ($fileManager): ?>
                            <a href="#" onclick="updateStorage(); return false;" class="text-primary" style="text-decoration: none;" title="Cập nhật">
                                <i class="bi bi-arrow-clockwise" id="storage-refresh-icon" style="display: inline-block;"></i>
                            </a>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="text-muted"><i class="bi bi-hourglass-split"></i> Chưa có dữ liệu</span>
                    <?php endif; ?>
                </h4>
                <?php if ($storageUpdatedAt): ?>
                    <small class="text-muted" style="font-size: 0.75rem;">Cập nhật: <?php echo formatDate($storageUpdatedAt); ?></small>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stats-card">
            <div class="card-body">
                <h6 class="text-muted">Dung lượng Database</h6>
                <?php if ($dbError): ?>
                    <h4 class="text-danger" style="font-size: 0.9rem;" title="<?php echo escape($dbError); ?>">
                        <i class="bi bi-exclamation-triangle"></i> Lỗi kết nối
                    </h4>
                    <small class="text-danger d-block mt-1"><?php echo escape($dbError); ?></small>
                <?php else: ?>
                    <h4><?php echo formatBytes($dbSize); ?></h4>
                    <small class="text-muted" style="font-size: 0.75rem;">Cập nhật: <?php echo formatDate(date('Y-m-d H:i:s')); ?></small>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stats-card">
            <div class="card-body">
                <h6 class="text-muted">Tổng dung lượng</h6>
                <h4 id="total-size-display">
                    <?php if ($diskUsageCalculating): ?>
                        <span class="text-muted"><?php echo formatBytes($dbSize); ?> + ...</span>
                    <?php else: ?>
                        <?php echo formatBytes($totalSize); ?>
                        <?php if ($totalStorage): ?>
                            <span class="text-muted" style="font-size: 0.7em; font-weight: normal;">/ <?php echo formatBytes($totalStorage); ?></span>
                        <?php endif; ?>
                    <?php endif; ?>
                </h4>
                <?php if ($totalStorage): ?>
                    <div class="mt-2">
                        <div class="progress" style="height: 6px;">
                            <?php 
                            $percent = ($totalSize / $totalStorage) * 100;
                            if ($percent > 100) $percent = 100;
                            ?>
                            <div class="progress-bar <?php echo $percent > 80 ? 'bg-danger' : ($percent > 60 ? 'bg-warning' : 'bg-success'); ?>" role="progressbar" style="width: <?php echo $percent; ?>%"></div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link <?php echo $tab == 'files' ? 'active' : ''; ?>" href="<?php echo websiteUrl($websiteId, 'files'); ?>">
            <i class="bi bi-folder"></i> File Manager
        </a>
    </li>
    <?php if ($website['db_name'] && $auth->canManageDatabase($websiteId)): ?>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab == 'database' ? 'active' : ''; ?>" href="<?php echo websiteUrl($websiteId, 'database'); ?>">
            <i class="bi bi-database"></i> Database Manager
        </a>
    </li>
    <?php endif; ?>
    <?php if ($auth->canBackup($websiteId)): ?>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab == 'backup' ? 'active' : ''; ?>" href="<?php echo websiteUrl($websiteId, 'backup'); ?>">
            <i class="bi bi-archive"></i> Backup & Restore
        </a>
    </li>
    <?php endif; ?>
</ul>

<!-- Tab Content -->
<?php if ($tab == 'files' && $auth->canManageFiles($websiteId)): ?>
    <?php include __DIR__ . '/../includes/tabs/hostinger_file_manager_tab.php'; ?>
<?php elseif ($tab == 'database' && $auth->canManageDatabase($websiteId)): ?>
    <?php include __DIR__ . '/../includes/tabs/database_manager_tab.php'; ?>
<?php elseif ($tab == 'backup' && $auth->canBackup($websiteId)): ?>
    <?php include __DIR__ . '/../includes/tabs/hostinger_backup_tab.php'; ?>
<?php else: ?>
    <div class="alert alert-warning">Bạn không có quyền truy cập tính năng này.</div>
<?php endif; ?>

<script>
function updateStorage() {
    const displayEl = document.getElementById('disk-usage-display');
    const refreshIcon = document.getElementById('storage-refresh-icon');
    
    if (!displayEl || !refreshIcon) return;
    
    // Disable click during loading
    refreshIcon.style.pointerEvents = 'none';
    refreshIcon.style.cursor = 'wait';
    
    // Add spinning class - force reflow to ensure animation starts
    refreshIcon.classList.remove('spinning');
    void refreshIcon.offsetWidth; // Force reflow
    refreshIcon.classList.add('spinning');
    
    
    // Make AJAX request
    fetch('<?php echo ajaxUrl('update-storage', ['id' => $websiteId]); ?>')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Keep spinning until page reloads
                // Reload page to show updated data
                setTimeout(() => {
                    location.reload();
                }, 500);
            } else {
                // Stop spinning on error
                refreshIcon.classList.remove('spinning');
                refreshIcon.style.pointerEvents = '';
                refreshIcon.style.cursor = 'pointer';
                alert('Lỗi: ' + (data.error || 'Không thể cập nhật'));
            }
        })
        .catch(error => {
            // Stop spinning on error
            refreshIcon.classList.remove('spinning');
            refreshIcon.style.pointerEvents = '';
            refreshIcon.style.cursor = 'pointer';
            alert('Lỗi kết nối');
            console.error('Error:', error);
        });
}

// CSS animation for spinning icon - add to head if not exists
if (!document.getElementById('storage-spin-style')) {
    const style = document.createElement('style');
    style.id = 'storage-spin-style';
    style.textContent = `
        @keyframes storage-spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        #storage-refresh-icon.spinning {
            animation: storage-spin 0.8s linear infinite !important;
            -webkit-animation: storage-spin 0.8s linear infinite !important;
            transform-origin: center center;
        }
        #storage-refresh-icon {
            cursor: pointer;
            transition: opacity 0.2s;
            display: inline-block !important;
        }
        #storage-refresh-icon:hover:not(.spinning) {
            opacity: 0.8;
        }
    `;
    document.head.appendChild(style);
}

function formatBytes(bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
