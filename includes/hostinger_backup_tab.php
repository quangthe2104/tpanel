<?php
$backupManager = new HostingerBackupManager($websiteId, $auth->getUserId());

// Handle backup creation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_backup'])) {
        $type = $_POST['backup_type'];
        try {
            if ($type == 'full') {
                $backupPath = $backupManager->createFullBackup();
            } elseif ($type == 'files') {
                $backupPath = $backupManager->createFilesBackup();
            } elseif ($type == 'database') {
                $backupPath = $backupManager->createDatabaseBackup();
            }
            $auth->logActivity($auth->getUserId(), $websiteId, 'backup_created', "Backup type: $type");
            $backupSuccess = true;
            $backupMessage = "Tạo backup thành công!";
        } catch (Exception $e) {
            $backupSuccess = false;
            $backupMessage = "Lỗi: " . $e->getMessage();
        }
    } elseif (isset($_POST['delete_backup'])) {
        $backupId = $_POST['backup_id'];
        $backupManager->deleteBackup($backupId);
        $auth->logActivity($auth->getUserId(), $websiteId, 'backup_deleted', "Backup ID: $backupId");
        $backupSuccess = true;
        $backupMessage = "Xóa backup thành công!";
    }
}

$backups = $backupManager->getBackups();
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
                    <form method="POST" class="row g-3">
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
                            <button type="submit" name="create_backup" class="btn btn-primary">
                                <i class="bi bi-plus-circle"></i> Tạo Backup
                            </button>
                        </div>
                    </form>
                    <small class="text-muted">
                        <i class="bi bi-info-circle"></i> Backup sẽ được tải về từ server qua SFTP/FTP và lưu trên server Tpanel
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
                                    <td><?php echo $backup['file_size'] ? formatBytes($backup['file_size']) : 'N/A'; ?></td>
                                    <td>
                                        <?php
                                        $statusLabels = [
                                            'completed' => '<span class="badge bg-success">Hoàn thành</span>',
                                            'in_progress' => '<span class="badge bg-warning">Đang xử lý</span>',
                                            'failed' => '<span class="badge bg-danger">Thất bại</span>'
                                        ];
                                        echo $statusLabels[$backup['status']] ?? $backup['status'];
                                        ?>
                                    </td>
                                    <td><?php echo formatDate($backup['created_at']); ?></td>
                                    <td><?php echo escape($backup['username']); ?></td>
                                    <td>
                                        <?php if ($backup['status'] == 'completed' && file_exists($backup['file_path'])): ?>
                                            <a href="download_backup.php?id=<?php echo $backup['id']; ?>" class="btn btn-sm btn-success">
                                                <i class="bi bi-download"></i> Download
                                            </a>
                                        <?php endif; ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Bạn có chắc muốn xóa backup này?');">
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
