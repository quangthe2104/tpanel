<?php
$backupManager = new HostingerBackupManager($websiteId, $auth->getUserId());

// Hiển thị thông báo từ URL parameter (sau redirect từ website_manage.php)
if (isset($_GET['success']) && $_GET['success'] == '1') {
    $backupSuccess = true;
    $backupMessage = isset($_GET['message']) ? urldecode($_GET['message']) : "Đã gửi lệnh backup! Đang xử lý trên server...";
}
if (isset($_GET['error']) && $_GET['error'] == '1') {
    $backupSuccess = false;
    $backupMessage = isset($_GET['message']) ? urldecode($_GET['message']) : "Đã xảy ra lỗi!";
}

$backups = $backupManager->getBackups();

foreach ($backups as $backup) {
    if ($backup['status'] === 'in_progress') {
        try {
            $backupManager->checkBackupStatus($backup['id']);
        } catch (Exception $e) {
            // Ignore
        }
    }
}

$backups = $backupManager->getBackups();

// Không tự động refresh để tránh tạo backup liên tục
?>

<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-archive"></i> Backup & Restore</h5>
            </div>
            <div class="card-body">
                <?php if (isset($backupSuccess)): ?>
                    <div class="alert alert-<?php echo $backupSuccess ? 'success' : 'danger'; ?>">
                        <?php echo escape($backupMessage); ?>
                    </div>
                <?php endif; ?>
                
                <!-- Create Backup -->
                <div class="mb-4">
                    <h6>Tạo Backup mới</h6>
                    <form method="POST" action="<?php echo websiteUrl($websiteId, 'backup'); ?>" class="row g-3" id="create-backup-form">
                        <?php $security = Security::getInstance(); echo $security->getCSRFField(); ?>
                        <div class="col-md-4">
                            <select name="backup_type" class="form-select" required>
                                <option value="full">Full Backup (Files + Database)</option>
                                <option value="files">Chỉ Files</option>
                                <?php if ($website['db_name']): ?>
                                <option value="database">Chỉ Database</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" name="create_backup" class="btn btn-primary" id="create-backup-btn">
                                <i class="bi bi-plus-circle" id="create-backup-icon"></i> <span id="create-backup-text">Tạo Backup</span>
                            </button>
                        </div>
                    </form>
                    <small class="text-muted">
                        <i class="bi bi-info-circle"></i> Backup sẽ được lưu trực tiếp trên server website và tự động xóa sau 6 tiếng
                    </small>
                </div>
                
                <hr>
                
                <!-- Backup List -->
                <h6>Danh sách Backup</h6>
                <?php if (empty($backups)): ?>
                    <p class="text-muted">Chưa có backup nào</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Loại</th>
                                    <th>Tên file</th>
                                    <th>Kích thước</th>
                                    <th>Trạng thái</th>
                                    <th>Ngày tạo</th>
                                    <th>Người tạo</th>
                                    <th>Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($backups as $backup): ?>
                                <tr>
                                    <td>
                                        <?php
                                        $typeLabels = [
                                            'full' => '<span class="badge bg-primary">Full</span>',
                                            'files' => '<span class="badge bg-info">Files</span>',
                                            'database' => '<span class="badge bg-success">Database</span>'
                                        ];
                                        echo $typeLabels[$backup['type']] ?? $backup['type'];
                                        ?>
                                    </td>
                                    <td><?php echo escape($backup['filename']); ?></td>
                                    <td id="backup-size-<?php echo $backup['id']; ?>"><?php echo $backup['file_size'] ? formatBytes($backup['file_size']) : 'N/A'; ?></td>
                                    <td id="backup-status-cell-<?php echo $backup['id']; ?>">
                                        <?php
                                        $statusLabels = [
                                            'completed' => '<span class="badge bg-success">Hoàn thành</span>',
                                            'in_progress' => '<span class="badge bg-warning">Đang xử lý</span>',
                                            'failed' => '<span class="badge bg-danger">Thất bại</span>'
                                        ];
                                        echo $statusLabels[$backup['status']] ?? $backup['status'];
                                        ?>
                                    </td>
                                    <td>
                                        <?php echo formatDate($backup['created_at']); ?>
                                        <?php if ($backup['expires_at']): ?>
                                            <br><small class="text-muted">Hết hạn: <?php echo formatDate($backup['expires_at']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo escape($backup['username']); ?></td>
                                    <td id="backup-action-<?php echo $backup['id']; ?>">
                                        <?php if ($backup['status'] == 'completed'): ?>
                                            <?php 
                                            $isExpired = $backup['expires_at'] && strtotime($backup['expires_at']) < time();
                                            $fileExists = isset($backup['file_exists']) ? $backup['file_exists'] : true; // Default true để tránh lỗi nếu chưa check
                                            
                                            if ($isExpired): ?>
                                                <span class="badge bg-secondary">Đã hết hạn</span>
                                            <?php elseif (!$fileExists): ?>
                                                <span class="badge bg-danger">File đã bị xóa</span>
                                            <?php else: ?>
                                                <?php
                                                // Tạo HTTP URL trực tiếp nếu có thể (để download nhanh hơn và có progress ngay)
                                                $directUrl = null;
                                                if (!empty($backup['remote_path'])) {
                                                    $websiteUrl = !empty($backup['website_url']) ? $backup['website_url'] : (!empty($backup['domain']) ? 'https://' . $backup['domain'] : null);
                                                    if ($websiteUrl) {
                                                        // Normalize remote_path để lấy relative path từ web root
                                                        $remotePath = $backup['remote_path'];
                                                        
                                                        // Nếu là absolute path từ server, extract relative path
                                                        if (strpos($remotePath, '/home/') === 0 || (strpos($remotePath, '/') === 0 && strpos($remotePath, '.tpanel') === false)) {
                                                            // Tìm public_html trong path
                                                            if (strpos($remotePath, 'public_html/') !== false) {
                                                                $parts = explode('public_html/', $remotePath, 2);
                                                                if (isset($parts[1])) {
                                                                    $remotePath = $parts[1];
                                                                }
                                                            } elseif (strpos($remotePath, 'www/') !== false) {
                                                                $parts = explode('www/', $remotePath, 2);
                                                                if (isset($parts[1])) {
                                                                    $remotePath = $parts[1];
                                                                }
                                                            }
                                                        }
                                                        
                                                        // Đảm bảo remotePath là relative
                                                        $remotePath = ltrim($remotePath, '/');
                                                        $directUrl = rtrim($websiteUrl, '/') . '/' . $remotePath;
                                                    }
                                                }
                                                $downloadUrl = $directUrl ? $directUrl : backupUrl($backup['id']);
                                                ?>
                                                <a href="<?php echo htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8'); ?>" 
                                                   class="btn btn-sm btn-success download-backup-btn" 
                                                   data-backup-id="<?php echo $backup['id']; ?>"
                                                   <?php if ($directUrl): ?>target="_blank"<?php endif; ?>>
                                                    <i class="bi bi-download"></i> <span class="btn-text">Download</span>
                                                </a>
                                            <?php endif; ?>
                                        <?php elseif ($backup['status'] == 'in_progress'): ?>
                                            <span class="badge bg-warning">
                                                <i class="bi bi-hourglass-split"></i> Đang xử lý...
                                            </span>
                                        <?php endif; ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Bạn có chắc muốn xóa backup này?');">
                                            <?php $security = Security::getInstance(); echo $security->getCSRFField(); ?>
                                            <input type="hidden" name="backup_id" value="<?php echo $backup['id']; ?>">
                                            <button type="submit" name="delete_backup" class="btn btn-sm btn-danger">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
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

<script>
// Auto-refresh backup status mỗi 5 giây
(function() {
    // Lấy danh sách backup IDs đang in_progress
    const inProgressBackups = [
        <?php 
        $inProgressIds = [];
        foreach ($backups as $backup) {
            if ($backup['status'] === 'in_progress') {
                $inProgressIds[] = $backup['id'];
            }
        }
        echo !empty($inProgressIds) ? implode(',', $inProgressIds) : '';
        ?>
    ];
    
    if (inProgressBackups.length === 0) {
        return; // Không có backup nào đang xử lý
    }
    
    // Function format bytes (giống PHP formatBytes)
    function formatBytes(bytes) {
        if (!bytes || bytes === 0) return 'N/A';
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        const pow = Math.floor(Math.log(bytes) / Math.log(1024));
        const value = bytes / Math.pow(1024, pow);
        return Math.round(value * 100) / 100 + ' ' + units[Math.min(pow, units.length - 1)];
    }
    
    // Function update UI khi status thay đổi
    function updateBackupUI(backupId, data) {
        const statusCell = document.getElementById('backup-status-cell-' + backupId);
        const sizeCell = document.getElementById('backup-size-' + backupId);
        const actionCell = document.getElementById('backup-action-' + backupId);
        
        if (!statusCell || !actionCell) return;
        
        // Update status badge
        let statusHtml = '';
        if (data.status === 'completed') {
            statusHtml = '<span class="badge bg-success">Hoàn thành</span>';
        } else if (data.status === 'failed') {
            statusHtml = '<span class="badge bg-danger">Thất bại</span>';
        } else {
            statusHtml = '<span class="badge bg-warning">Đang xử lý</span>';
        }
        statusCell.innerHTML = statusHtml;
        
        // Update file size
        if (sizeCell && data.file_size) {
            sizeCell.textContent = formatBytes(data.file_size);
        }
        
        // Update action button
        const deleteForm = actionCell.querySelector('form');
        const deleteFormHtml = deleteForm ? deleteForm.outerHTML : '';
        
        if (data.status === 'completed') {
            const isExpired = data.expires_at && new Date(data.expires_at) < new Date();
            if (isExpired) {
                actionCell.innerHTML = '<span class="badge bg-secondary">Đã hết hạn</span> ' + deleteFormHtml;
            } else {
                // Tạo HTTP URL trực tiếp nếu có thể (để download nhanh hơn và có progress ngay)
                let downloadUrl = '<?php echo BASE_URL; ?>backup/' + backupId + '/download';
                let useDirectUrl = false;
                
                if (data.remote_path) {
                    const websiteUrl = data.website_url || (data.website_domain ? 'https://' + data.website_domain : null);
                    if (websiteUrl) {
                        // Normalize remote_path để lấy relative path từ web root
                        let remotePath = data.remote_path;
                        
                        // Nếu là absolute path từ server, extract relative path
                        if (remotePath.startsWith('/home/') || (remotePath.startsWith('/') && !remotePath.startsWith('/.tpanel'))) {
                            // Tìm public_html trong path
                            const publicHtmlIndex = remotePath.indexOf('public_html/');
                            if (publicHtmlIndex !== -1) {
                                remotePath = remotePath.substring(publicHtmlIndex + 'public_html/'.length);
                            } else {
                                const wwwIndex = remotePath.indexOf('www/');
                                if (wwwIndex !== -1) {
                                    remotePath = remotePath.substring(wwwIndex + 'www/'.length);
                                }
                            }
                        }
                        
                        // Đảm bảo remotePath là relative (bỏ leading slash)
                        remotePath = remotePath.replace(/^\/+/, '');
                        downloadUrl = websiteUrl.replace(/\/$/, '') + '/' + remotePath;
                        useDirectUrl = true;
                    }
                }
                
                actionCell.innerHTML = '<a href="' + downloadUrl + '" class="btn btn-sm btn-success download-backup-btn" data-backup-id="' + backupId + '"' + (useDirectUrl ? ' target="_blank"' : '') + '><i class="bi bi-download"></i> <span class="btn-text">Download</span></a> ' + deleteFormHtml;
            }
        } else if (data.status === 'failed') {
            actionCell.innerHTML = '<span class="badge bg-danger">Thất bại</span> ' + deleteFormHtml;
        }
    }
    
    // Function check status cho một backup
    function checkBackupStatus(backupId, checkCount = 0) {
        const maxChecks = 120; // Tối đa 10 phút (120 * 5s)
        
        if (checkCount >= maxChecks) {
            const statusCell = document.getElementById('backup-status-cell-' + backupId);
            if (statusCell) {
                statusCell.innerHTML = '<span class="badge bg-warning"><i class="bi bi-exclamation-triangle"></i> Timeout</span>';
            }
            return;
        }
        
        fetch('<?php echo BASE_URL; ?>ajax/check-backup-status?id=' + backupId)
            .then(response => {
                // Kiểm tra response status
                if (!response.ok) {
                    throw new Error('HTTP error! status: ' + response.status);
                }
                // Kiểm tra content type
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    throw new Error('Response is not JSON');
                }
                return response.json();
            })
            .then(data => {
                // Kiểm tra nếu có error trong response
                if (data.error) {
                    console.error('Error checking backup status:', data.error);
                    // Nếu có status, vẫn update UI
                    if (data.status) {
                        updateBackupUI(backupId, data);
                    }
                    // Tiếp tục check nếu chưa quá max
                    if (checkCount < maxChecks && (data.status === 'in_progress' || !data.status)) {
                        setTimeout(() => checkBackupStatus(backupId, checkCount + 1), 5000);
                    }
                    return;
                }
                
                // Đảm bảo có status
                if (!data.status) {
                    console.warn('No status in response:', data);
                    if (checkCount < maxChecks) {
                        setTimeout(() => checkBackupStatus(backupId, checkCount + 1), 5000);
                    }
                    return;
                }
                
                // Update UI
                updateBackupUI(backupId, data);
                
                // Nếu vẫn đang xử lý, tiếp tục check
                if (data.status === 'in_progress') {
                    setTimeout(() => checkBackupStatus(backupId, checkCount + 1), 5000);
                }
            })
            .catch(error => {
                console.error('Error fetching backup status:', error);
                // Tiếp tục check nếu chưa quá max (có thể là lỗi tạm thời)
                if (checkCount < maxChecks) {
                    setTimeout(() => checkBackupStatus(backupId, checkCount + 1), 5000);
                }
            });
    }
    
    // Bắt đầu check cho tất cả backup đang in_progress
    inProgressBackups.forEach(backupId => {
        if (backupId) {
            // Check ngay lập tức, sau đó mỗi 5 giây
            checkBackupStatus(backupId);
        }
    });
})();

// Xử lý loading state cho nút Tạo Backup - Sử dụng submit event nhưng không preventDefault
document.addEventListener('DOMContentLoaded', function() {
    const createBackupForm = document.getElementById('create-backup-form');
    const createBackupBtn = document.getElementById('create-backup-btn');
    const createBackupIcon = document.getElementById('create-backup-icon');
    const createBackupText = document.getElementById('create-backup-text');
    const backupTypeSelect = document.querySelector('#create-backup-form select[name="backup_type"]');
    
    if (createBackupForm && createBackupBtn) {
        createBackupForm.addEventListener('submit', function(e) {
            // KHÔNG preventDefault - để form submit bình thường
            // Chỉ thay đổi UI ngay lập tức
            
            // Thay đổi icon thành spinner
            if (createBackupIcon) {
                createBackupIcon.className = 'spinner-border spinner-border-sm me-1';
                createBackupIcon.style.width = '1rem';
                createBackupIcon.style.height = '1rem';
            }
            
            // Thay đổi text
            if (createBackupText) {
                createBackupText.textContent = 'Đang xử lý...';
            }
            
            // Disable nút và select SAU KHI form đã bắt đầu submit
            setTimeout(function() {
                createBackupBtn.disabled = true;
                if (backupTypeSelect) {
                    backupTypeSelect.disabled = true;
                }
                createBackupBtn.classList.add('disabled');
            }, 10);
            
            // Form sẽ submit bình thường - KHÔNG preventDefault, KHÔNG return false
        });
    }
});

</script>
