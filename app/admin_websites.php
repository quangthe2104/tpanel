<?php
require_once __DIR__ . '/../includes/helpers/functions.php';

$auth = new Auth();
$auth->requireAdmin();

$db = Database::getInstance();
$security = Security::getInstance();
$pageTitle = 'Quản lý Website';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $security->checkCSRF();
        
        if (isset($_POST['add_website'])) {
            $name = $security->sanitizeString($_POST['name'] ?? '', 255);
            $domain = $security->sanitizeString($_POST['domain'] ?? '', 255);
            $path = $security->sanitizePath($_POST['path'] ?? '');
            $connectionType = in_array($_POST['connection_type'] ?? 'sftp', ['sftp', 'ftp']) ? $_POST['connection_type'] : 'sftp';
            $sftpHost = $security->sanitizeString($_POST['sftp_host'] ?? '', 255);
            $sftpPort = $security->validateInt($_POST['sftp_port'] ?? 22, 1, 65535) ?: 22;
            $sftpUsername = $security->sanitizeString($_POST['sftp_username'] ?? '', 255);
            $sftpPassword = $_POST['sftp_password'] ?? '';
            $dbHost = $security->sanitizeString($_POST['db_host'] ?? 'localhost', 255);
            $dbName = $security->sanitizeString($_POST['db_name'] ?? '', 255);
            $dbUser = $security->sanitizeString($_POST['db_user'] ?? '', 255);
            $dbPassword = $_POST['db_password'] ?? '';
            $totalStorage = !empty($_POST['total_storage']) ? $security->validateInt($_POST['total_storage'], 0, 1000000) : null;
            // Convert GB to bytes
            if ($totalStorage) {
                $totalStorage = $totalStorage * 1024 * 1024 * 1024;
            }
            
            $db->query(
                "INSERT INTO websites (name, domain, path, connection_type, sftp_host, sftp_port, sftp_username, sftp_password, db_host, db_name, db_user, db_password, total_storage) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$name, $domain, $path, $connectionType, $sftpHost, $sftpPort, $sftpUsername, $sftpPassword, $dbHost, $dbName, $dbUser, $dbPassword, $totalStorage]
            );
            
            $auth->logActivity($auth->getUserId(), null, 'website_added', "Website: $name");
            $success = "Thêm website thành công!";
        } elseif (isset($_POST['edit_website'])) {
            $id = $security->validateInt($_POST['id'] ?? 0, 1);
            if (!$id) {
                $error = 'ID website không hợp lệ';
            } else {
                $name = $security->sanitizeString($_POST['name'] ?? '', 255);
                $domain = $security->sanitizeString($_POST['domain'] ?? '', 255);
                $path = $security->sanitizePath($_POST['path'] ?? '');
                $connectionType = in_array($_POST['connection_type'] ?? 'sftp', ['sftp', 'ftp']) ? $_POST['connection_type'] : 'sftp';
                $sftpHost = $security->sanitizeString($_POST['sftp_host'] ?? '', 255);
                $sftpPort = $security->validateInt($_POST['sftp_port'] ?? 22, 1, 65535) ?: 22;
                $sftpUsername = $security->sanitizeString($_POST['sftp_username'] ?? '', 255);
                $sftpPassword = $_POST['sftp_password'] ?? '';
                
                // Get database info - always update if provided
                $dbHost = $security->sanitizeString(trim($_POST['db_host'] ?? ''), 255);
                $dbName = $security->sanitizeString(trim($_POST['db_name'] ?? ''), 255);
                $dbUser = $security->sanitizeString(trim($_POST['db_user'] ?? ''), 255);
                $dbPassword = $_POST['db_password'] ?? '';
                $totalStorage = !empty($_POST['total_storage']) ? $security->validateInt($_POST['total_storage'], 0, 1000000) : null;
                // Convert GB to bytes
                if ($totalStorage) {
                    $totalStorage = $totalStorage * 1024 * 1024 * 1024;
                }
                
                // Get current website data to preserve password if not provided
                $currentWebsite = $db->fetchOne("SELECT * FROM websites WHERE id = ?", [$id]);
            
                if ($sftpPassword) {
                    // Update SFTP password
                    if ($dbPassword) {
                        // Update both passwords
                        $db->query(
                            "UPDATE websites SET name = ?, domain = ?, path = ?, connection_type = ?, sftp_host = ?, sftp_port = ?, sftp_username = ?, sftp_password = ?, db_host = ?, db_name = ?, db_user = ?, db_password = ?, total_storage = ? WHERE id = ?",
                            [$name, $domain, $path, $connectionType, $sftpHost, $sftpPort, $sftpUsername, $sftpPassword, $dbHost, $dbName, $dbUser, $dbPassword, $totalStorage, $id]
                        );
                    } else {
                        // Update SFTP password but keep DB password
                        $db->query(
                            "UPDATE websites SET name = ?, domain = ?, path = ?, connection_type = ?, sftp_host = ?, sftp_port = ?, sftp_username = ?, sftp_password = ?, db_host = ?, db_name = ?, db_user = ?, total_storage = ? WHERE id = ?",
                            [$name, $domain, $path, $connectionType, $sftpHost, $sftpPort, $sftpUsername, $sftpPassword, $dbHost, $dbName, $dbUser, $totalStorage, $id]
                        );
                    }
                } else {
                    // Don't update SFTP password
                    if ($dbPassword) {
                        // Update DB password only
                        $db->query(
                            "UPDATE websites SET name = ?, domain = ?, path = ?, connection_type = ?, sftp_host = ?, sftp_port = ?, sftp_username = ?, db_host = ?, db_name = ?, db_user = ?, db_password = ?, total_storage = ? WHERE id = ?",
                            [$name, $domain, $path, $connectionType, $sftpHost, $sftpPort, $sftpUsername, $dbHost, $dbName, $dbUser, $dbPassword, $totalStorage, $id]
                        );
                    } else {
                        // Keep both passwords, but update other DB fields
                        $db->query(
                            "UPDATE websites SET name = ?, domain = ?, path = ?, connection_type = ?, sftp_host = ?, sftp_port = ?, sftp_username = ?, db_host = ?, db_name = ?, db_user = ?, total_storage = ? WHERE id = ?",
                            [$name, $domain, $path, $connectionType, $sftpHost, $sftpPort, $sftpUsername, $dbHost, $dbName, $dbUser, $totalStorage, $id]
                        );
                    }
                }
                
                $auth->logActivity($auth->getUserId(), $id, 'website_updated', "Website: $name");
                $success = "Cập nhật website thành công!";
            }
        } elseif (isset($_POST['delete_website'])) {
            $id = $security->validateInt($_POST['id'] ?? 0, 1);
            if ($id) {
                $db->query("DELETE FROM websites WHERE id = ?", [$id]);
                $auth->logActivity($auth->getUserId(), $id, 'website_deleted', "Website ID: $id");
                $success = "Xóa website thành công!";
            }
        }
    } catch (Exception $e) {
        error_log("Error in admin_websites.php: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
        $error = "Có lỗi xảy ra: " . htmlspecialchars($e->getMessage());
    } catch (Throwable $e) {
        error_log("Fatal error in admin_websites.php: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
        $error = "Có lỗi nghiêm trọng xảy ra. Vui lòng kiểm tra log.";
    }
}

$websites = $db->fetchAll("SELECT * FROM websites ORDER BY name");
$editId = $security->validateInt($_GET['edit'] ?? 0, 1);
$editWebsite = $editId ? $db->fetchOne("SELECT * FROM websites WHERE id = ?", [$editId]) : null;

include __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-md-12 d-flex justify-content-between align-items-center">
        <div>
            <h2><i class="bi bi-globe"></i> Quản lý Website</h2>
            <p class="text-muted">Thêm, sửa, xóa website</p>
        </div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addWebsiteModal">
            <i class="bi bi-plus-circle"></i> Thêm Website
        </button>
    </div>
</div>

<?php if (isset($success)): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <?php echo escape($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle"></i> <?php echo $error; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Tên</th>
                        <th>Domain</th>
                        <th>Kết nối</th>
                        <th>Database</th>
                        <th>Trạng thái</th>
                        <th>Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($websites as $website): ?>
                    <tr>
                        <td><?php echo $website['id']; ?></td>
                        <td><?php echo escape($website['name']); ?></td>
                        <td><?php echo escape($website['domain']); ?></td>
                        <td>
                            <span class="badge bg-info"><?php echo strtoupper($website['connection_type'] ?? 'SFTP'); ?></span>
                            <small class="d-block text-muted"><?php echo escape($website['sftp_host'] ?? 'N/A'); ?></small>
                        </td>
                        <td><?php echo $website['db_name'] ? escape($website['db_name']) : '<span class="text-muted">N/A</span>'; ?></td>
                        <td>
                            <span class="badge bg-<?php echo $website['status'] == 'active' ? 'success' : 'secondary'; ?>">
                                <?php echo $website['status'] == 'active' ? 'Hoạt động' : 'Tạm dừng'; ?>
                            </span>
                        </td>
                        <td>
                            <a href="<?php echo adminUrl('websites', 'edit', $website['id']); ?>" class="btn btn-sm btn-info">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <a href="<?php echo websiteUrl($website['id']); ?>" class="btn btn-sm btn-primary">
                                <i class="bi bi-gear"></i>
                            </a>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Bạn có chắc muốn xóa website này?');">
                                <?php $security = Security::getInstance(); echo $security->getCSRFField(); ?>
                                <input type="hidden" name="id" value="<?php echo $website['id']; ?>">
                                <button type="submit" name="delete_website" class="btn btn-sm btn-danger">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit Website Modal -->
<div class="modal fade" id="addWebsiteModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <?php $security = Security::getInstance(); echo $security->getCSRFField(); ?>
                <div class="modal-header">
                    <h5 class="modal-title"><?php echo $editWebsite ? 'Sửa' : 'Thêm'; ?> Website</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if ($editWebsite): ?>
                        <input type="hidden" name="id" value="<?php echo $editWebsite['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="mb-3">
                        <label class="form-label">Tên website *</label>
                        <input type="text" name="name" class="form-control" value="<?php echo $editWebsite ? escape($editWebsite['name']) : ''; ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Domain *</label>
                        <input type="text" name="domain" class="form-control" value="<?php echo $editWebsite ? escape($editWebsite['domain']) : ''; ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Đường dẫn trên server</label>
                        <input type="text" name="path" class="form-control" value="<?php echo $editWebsite ? escape($editWebsite['path']) : ''; ?>" placeholder="Để trống hoặc / để tự động phát hiện, hoặc nhập: /public_html">
                        <small class="text-muted">
                            <strong>FTP:</strong> Để trống hoặc nhập <code>/</code> để tự động sử dụng thư mục làm việc hiện tại.<br>
                            <strong>SFTP:</strong> Nhập đường dẫn đầy đủ, ví dụ: <code>/home/username/domains/example.com/public_html</code> hoặc <code>/public_html</code>
                        </small>
                    </div>
                    
                    <hr>
                    <h6>Thông tin kết nối SFTP/FTP</h6>
                    
                    <div class="mb-3">
                        <label class="form-label">Loại kết nối</label>
                        <select name="connection_type" class="form-select">
                            <option value="sftp" <?php echo ($editWebsite && ($editWebsite['connection_type'] ?? 'sftp') == 'sftp') ? 'selected' : ''; ?>>SFTP (Khuyến nghị)</option>
                            <option value="ftp" <?php echo ($editWebsite && ($editWebsite['connection_type'] ?? '') == 'ftp') ? 'selected' : ''; ?>>FTP</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">SFTP/FTP Host *</label>
                        <input type="text" name="sftp_host" class="form-control" value="<?php echo $editWebsite ? escape($editWebsite['sftp_host'] ?? '') : ''; ?>" placeholder="ftp.yourdomain.com hoặc IP" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">SFTP/FTP Port</label>
                        <input type="number" name="sftp_port" class="form-control" value="<?php echo $editWebsite ? ($editWebsite['sftp_port'] ?? 22) : '22'; ?>" placeholder="22 cho SFTP, 21 cho FTP">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">SFTP/FTP Username *</label>
                        <input type="text" name="sftp_username" class="form-control" value="<?php echo $editWebsite ? escape($editWebsite['sftp_username'] ?? '') : ''; ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">SFTP/FTP Password *</label>
                        <input type="password" name="sftp_password" class="form-control" value="" placeholder="<?php echo $editWebsite ? 'Để trống nếu không đổi' : ''; ?>" <?php echo $editWebsite ? '' : 'required'; ?>>
                    </div>
                    
                    <hr>
                    <h6>Thông tin Database (Tùy chọn)</h6>
                    
                    <div class="mb-3">
                        <label class="form-label">DB Host</label>
                        <input type="text" name="db_host" class="form-control" value="<?php echo $editWebsite ? escape($editWebsite['db_host'] ?? '') : ''; ?>" placeholder="mysql.example.com hoặc localhost">
                        <small class="text-muted">IP hoặc hostname của MySQL server. Lưu ý: MySQL server phải cho phép kết nối từ IP của server Tpanel.</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">DB Name</label>
                        <input type="text" name="db_name" class="form-control" value="<?php echo $editWebsite ? escape($editWebsite['db_name']) : ''; ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">DB User</label>
                        <input type="text" name="db_user" class="form-control" value="<?php echo $editWebsite ? escape($editWebsite['db_user']) : ''; ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">DB Password</label>
                        <input type="password" name="db_password" class="form-control" value="" placeholder="<?php echo $editWebsite ? 'Để trống nếu không đổi' : ''; ?>">
                    </div>
                    
                    <hr>
                    <h6>Dung lượng Storage</h6>
                    
                    <div class="mb-3">
                        <label class="form-label">Tổng dung lượng (GB)</label>
                        <input type="number" name="total_storage" class="form-control" value="<?php echo $editWebsite && $editWebsite['total_storage'] ? round($editWebsite['total_storage'] / (1024*1024*1024), 2) : ''; ?>" placeholder="Ví dụ: 10 (cho 10GB)" step="0.01" min="0">
                        <small class="text-muted">Tổng dung lượng của website (bao gồm đã dùng và chưa dùng). Dung lượng đã dùng sẽ được cập nhật tự động định kỳ.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" name="<?php echo $editWebsite ? 'edit_website' : 'add_website'; ?>" class="btn btn-primary">
                        <?php echo $editWebsite ? 'Cập nhật' : 'Thêm'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($editWebsite): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var modal = new bootstrap.Modal(document.getElementById('addWebsiteModal'));
    modal.show();
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
