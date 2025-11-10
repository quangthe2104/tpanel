<?php
require_once 'includes/functions.php';

$auth = new Auth();
$auth->requireAdmin();

$db = Database::getInstance();
$pageTitle = 'Quản lý Website';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_website'])) {
        $name = $_POST['name'];
        $domain = $_POST['domain'];
        $path = $_POST['path'];
        $connectionType = $_POST['connection_type'] ?? 'sftp';
        $sftpHost = $_POST['sftp_host'] ?? '';
        $sftpPort = $_POST['sftp_port'] ?? 22;
        $sftpUsername = $_POST['sftp_username'] ?? '';
        $sftpPassword = $_POST['sftp_password'] ?? '';
        $dbHost = $_POST['db_host'] ?? 'localhost';
        $dbName = $_POST['db_name'] ?? '';
        $dbUser = $_POST['db_user'] ?? '';
        $dbPassword = $_POST['db_password'] ?? '';
        
        $db->query(
            "INSERT INTO websites (name, domain, path, connection_type, sftp_host, sftp_port, sftp_username, sftp_password, db_host, db_name, db_user, db_password) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$name, $domain, $path, $connectionType, $sftpHost, $sftpPort, $sftpUsername, $sftpPassword, $dbHost, $dbName, $dbUser, $dbPassword]
        );
        
        $auth->logActivity($auth->getUserId(), null, 'website_added', "Website: $name");
        $success = "Thêm website thành công!";
    } elseif (isset($_POST['edit_website'])) {
        $id = $_POST['id'];
        $name = $_POST['name'];
        $domain = $_POST['domain'];
        $path = $_POST['path'];
        $connectionType = $_POST['connection_type'] ?? 'sftp';
        $sftpHost = $_POST['sftp_host'] ?? '';
        $sftpPort = $_POST['sftp_port'] ?? 22;
        $sftpUsername = $_POST['sftp_username'] ?? '';
        $sftpPassword = $_POST['sftp_password'] ?? '';
        $dbHost = $_POST['db_host'] ?? 'localhost';
        $dbName = $_POST['db_name'] ?? '';
        $dbUser = $_POST['db_user'] ?? '';
        $dbPassword = $_POST['db_password'] ?? '';
        
        if ($sftpPassword) {
            // Update SFTP password
            if ($dbPassword) {
                $db->query(
                    "UPDATE websites SET name = ?, domain = ?, path = ?, connection_type = ?, sftp_host = ?, sftp_port = ?, sftp_username = ?, sftp_password = ?, db_host = ?, db_name = ?, db_user = ?, db_password = ? WHERE id = ?",
                    [$name, $domain, $path, $connectionType, $sftpHost, $sftpPort, $sftpUsername, $sftpPassword, $dbHost, $dbName, $dbUser, $dbPassword, $id]
                );
            } else {
                $db->query(
                    "UPDATE websites SET name = ?, domain = ?, path = ?, connection_type = ?, sftp_host = ?, sftp_port = ?, sftp_username = ?, sftp_password = ?, db_host = ?, db_name = ?, db_user = ? WHERE id = ?",
                    [$name, $domain, $path, $connectionType, $sftpHost, $sftpPort, $sftpUsername, $sftpPassword, $dbHost, $dbName, $dbUser, $id]
                );
            }
        } else {
            // Don't update SFTP password
            if ($dbPassword) {
                $db->query(
                    "UPDATE websites SET name = ?, domain = ?, path = ?, connection_type = ?, sftp_host = ?, sftp_port = ?, sftp_username = ?, db_host = ?, db_name = ?, db_user = ?, db_password = ? WHERE id = ?",
                    [$name, $domain, $path, $connectionType, $sftpHost, $sftpPort, $sftpUsername, $dbHost, $dbName, $dbUser, $dbPassword, $id]
                );
            } else {
                $db->query(
                    "UPDATE websites SET name = ?, domain = ?, path = ?, connection_type = ?, sftp_host = ?, sftp_port = ?, sftp_username = ?, db_host = ?, db_name = ?, db_user = ? WHERE id = ?",
                    [$name, $domain, $path, $connectionType, $sftpHost, $sftpPort, $sftpUsername, $dbHost, $dbName, $dbUser, $id]
                );
            }
        }
        
        $auth->logActivity($auth->getUserId(), $id, 'website_updated', "Website: $name");
        $success = "Cập nhật website thành công!";
    } elseif (isset($_POST['delete_website'])) {
        $id = $_POST['id'];
        $db->query("DELETE FROM websites WHERE id = ?", [$id]);
        $auth->logActivity($auth->getUserId(), $id, 'website_deleted', "Website ID: $id");
        $success = "Xóa website thành công!";
    }
}

$websites = $db->fetchAll("SELECT * FROM websites ORDER BY name");
$editId = $_GET['edit'] ?? null;
$editWebsite = $editId ? $db->fetchOne("SELECT * FROM websites WHERE id = ?", [$editId]) : null;

include 'includes/header.php';
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
                            <a href="?edit=<?php echo $website['id']; ?>" class="btn btn-sm btn-info">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <a href="website_manage.php?id=<?php echo $website['id']; ?>" class="btn btn-sm btn-primary">
                                <i class="bi bi-gear"></i>
                            </a>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Bạn có chắc muốn xóa website này?');">
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
                        <label class="form-label">Đường dẫn trên Hostinger *</label>
                        <input type="text" name="path" class="form-control" value="<?php echo $editWebsite ? escape($editWebsite['path']) : ''; ?>" placeholder="/public_html hoặc /domains/domain.com/public_html" required>
                        <small class="text-muted">Đường dẫn thư mục gốc của website trên Hostinger</small>
                    </div>
                    
                    <hr>
                    <h6>Thông tin kết nối Hostinger (SFTP/FTP)</h6>
                    
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
                        <input type="text" name="db_host" class="form-control" value="<?php echo $editWebsite ? escape($editWebsite['db_host']) : 'localhost'; ?>">
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

<?php include 'includes/footer.php'; ?>
