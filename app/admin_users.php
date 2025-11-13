<?php
require_once __DIR__ . '/../includes/helpers/functions.php';

$auth = new Auth();
$auth->requireAdmin();

$db = Database::getInstance();
$security = Security::getInstance();
$pageTitle = 'Quản lý Người dùng';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $security->checkCSRF();
    
    if (isset($_POST['add_user'])) {
        $username = $security->sanitizeString($_POST['username'] ?? '', 100);
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        $fullName = $security->sanitizeString($_POST['full_name'] ?? '', 255);
        $role = in_array($_POST['role'] ?? 'user', ['admin', 'user']) ? $_POST['role'] : 'user';
        
        // Validate email
        if (!$security->validateEmail($email)) {
            $error = 'Email không hợp lệ';
        } elseif (strlen($password) < 6) {
            $error = 'Mật khẩu phải có ít nhất 6 ký tự';
        } else {
            $password = password_hash($password, PASSWORD_DEFAULT);
        
            $db->query(
                "INSERT INTO users (username, email, password, full_name, role) VALUES (?, ?, ?, ?, ?)",
                [$username, $email, $password, $fullName, $role]
            );
            
            $auth->logActivity($auth->getUserId(), null, 'user_added', "User: $username");
            $success = "Thêm người dùng thành công!";
        }
    } elseif (isset($_POST['edit_user'])) {
        $id = $security->validateInt($_POST['id'] ?? 0, 1);
        if (!$id) {
            $error = 'ID người dùng không hợp lệ';
        } else {
            $username = $security->sanitizeString($_POST['username'] ?? '', 100);
            $email = $_POST['email'] ?? '';
            $fullName = $security->sanitizeString($_POST['full_name'] ?? '', 255);
            $role = in_array($_POST['role'] ?? 'user', ['admin', 'user']) ? $_POST['role'] : 'user';
            $status = in_array($_POST['status'] ?? 'active', ['active', 'inactive']) ? $_POST['status'] : 'active';
            
            // Validate email
            if (!$security->validateEmail($email)) {
                $error = 'Email không hợp lệ';
            } else {
                if (!empty($_POST['password'])) {
                    if (strlen($_POST['password']) < 6) {
                        $error = 'Mật khẩu phải có ít nhất 6 ký tự';
                    } else {
                        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                        $db->query(
                            "UPDATE users SET username = ?, email = ?, password = ?, full_name = ?, role = ?, status = ? WHERE id = ?",
                            [$username, $email, $password, $fullName, $role, $status, $id]
                        );
                    }
                } else {
                    $db->query(
                        "UPDATE users SET username = ?, email = ?, full_name = ?, role = ?, status = ? WHERE id = ?",
                        [$username, $email, $fullName, $role, $status, $id]
                    );
                }
                
                $auth->logActivity($auth->getUserId(), null, 'user_updated', "User: $username");
                $success = "Cập nhật người dùng thành công!";
            }
        }
    } elseif (isset($_POST['delete_user'])) {
        $id = $security->validateInt($_POST['id'] ?? 0, 1);
        if ($id && $id != $auth->getUserId()) {
            $db->query("DELETE FROM users WHERE id = ? AND id != ?", [$id, $auth->getUserId()]);
            $auth->logActivity($auth->getUserId(), null, 'user_deleted', "User ID: $id");
            $success = "Xóa người dùng thành công!";
        }
    } elseif (isset($_POST['save_permissions'])) {
        $userId = $security->validateInt($_POST['user_id'] ?? 0, 1);
        $websiteIds = $_POST['website_ids'] ?? [];
        $permissions = $_POST['permissions'] ?? [];
        
        if (!$userId) {
            $error = 'ID người dùng không hợp lệ';
        } else {
        
            // Validate website IDs
            $validWebsiteIds = [];
            foreach ($websiteIds as $websiteId) {
                $wid = $security->validateInt($websiteId, 1);
                if ($wid) {
                    $validWebsiteIds[] = $wid;
                }
            }
            
            // Remove all existing permissions for this user
            $db->query("DELETE FROM user_website_permissions WHERE user_id = ?", [$userId]);
            
            // Add new permissions
            foreach ($validWebsiteIds as $websiteId) {
                $perm = $permissions[$websiteId] ?? [];
                $canFiles = isset($perm['can_manage_files']) ? 1 : 0;
                $canDatabase = isset($perm['can_manage_database']) ? 1 : 0;
                $canBackup = isset($perm['can_backup']) ? 1 : 0;
                $canViewStats = isset($perm['can_view_stats']) ? 1 : 0;
                
                $db->query(
                    "INSERT INTO user_website_permissions (user_id, website_id, can_manage_files, can_manage_database, can_backup, can_view_stats) 
                     VALUES (?, ?, ?, ?, ?, ?)",
                    [$userId, $websiteId, $canFiles, $canDatabase, $canBackup, $canViewStats]
                );
            }
            
            $auth->logActivity($auth->getUserId(), null, 'permission_assigned', "User ID: $userId");
            $success = "Phân quyền thành công!";
            $assignUserId = null; // Close modal
        }
    }
}

$users = $db->fetchAll("SELECT * FROM users ORDER BY username");
$websites = $db->fetchAll("SELECT * FROM websites ORDER BY name");
$editId = $security->validateInt($_GET['edit'] ?? 0, 1);
$editUser = $editId ? $db->fetchOne("SELECT * FROM users WHERE id = ?", [$editId]) : null;
$assignUserId = $security->validateInt($_GET['assign'] ?? 0, 1);
$assignUser = $assignUserId ? $db->fetchOne("SELECT * FROM users WHERE id = ?", [$assignUserId]) : null;

include __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-md-12 d-flex justify-content-between align-items-center">
        <div>
            <h2><i class="bi bi-people"></i> Quản lý Người dùng</h2>
            <p class="text-muted">Thêm, sửa, xóa và phân quyền người dùng</p>
        </div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
            <i class="bi bi-person-plus"></i> Thêm Người dùng
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
                        <th>Tên đăng nhập</th>
                        <th>Email</th>
                        <th>Họ tên</th>
                        <th>Vai trò</th>
                        <th>Trạng thái</th>
                        <th>Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?php echo $user['id']; ?></td>
                        <td><?php echo escape($user['username']); ?></td>
                        <td><?php echo escape($user['email']); ?></td>
                        <td><?php echo escape($user['full_name'] ?? 'N/A'); ?></td>
                        <td>
                            <span class="badge bg-<?php echo $user['role'] == 'admin' ? 'danger' : 'primary'; ?>">
                                <?php echo $user['role'] == 'admin' ? 'Admin' : 'User'; ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge bg-<?php echo $user['status'] == 'active' ? 'success' : 'secondary'; ?>">
                                <?php echo $user['status'] == 'active' ? 'Hoạt động' : 'Tạm dừng'; ?>
                            </span>
                        </td>
                        <td>
                            <a href="<?php echo adminUrl('users', 'edit', $user['id']); ?>" class="btn btn-sm btn-info">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#assignPermissionModal" data-user-id="<?php echo $user['id']; ?>" data-username="<?php echo escape($user['username']); ?>">
                                <i class="bi bi-key"></i> Phân quyền
                            </button>
                            <?php if ($user['id'] != $auth->getUserId()): ?>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Bạn có chắc muốn xóa người dùng này?');">
                                <?php $security = Security::getInstance(); echo $security->getCSRFField(); ?>
                                <input type="hidden" name="id" value="<?php echo $user['id']; ?>">
                                <button type="submit" name="delete_user" class="btn btn-sm btn-danger">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?php $security = Security::getInstance(); echo $security->getCSRFField(); ?>
                <div class="modal-header">
                    <h5 class="modal-title"><?php echo $editUser ? 'Sửa' : 'Thêm'; ?> Người dùng</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if ($editUser): ?>
                        <input type="hidden" name="id" value="<?php echo $editUser['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="mb-3">
                        <label class="form-label">Tên đăng nhập *</label>
                        <input type="text" name="username" class="form-control" value="<?php echo $editUser ? escape($editUser['username']) : ''; ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Email *</label>
                        <input type="email" name="email" class="form-control" value="<?php echo $editUser ? escape($editUser['email']) : ''; ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Mật khẩu <?php echo $editUser ? '(Để trống nếu không đổi)' : '*'; ?></label>
                        <input type="password" name="password" class="form-control" <?php echo $editUser ? '' : 'required'; ?>>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Họ tên</label>
                        <input type="text" name="full_name" class="form-control" value="<?php echo $editUser ? escape($editUser['full_name'] ?? '') : ''; ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Vai trò</label>
                        <select name="role" class="form-select">
                            <option value="user" <?php echo ($editUser && $editUser['role'] == 'user') ? 'selected' : ''; ?>>User</option>
                            <option value="admin" <?php echo ($editUser && $editUser['role'] == 'admin') ? 'selected' : ''; ?>>Admin</option>
                        </select>
                    </div>
                    
                    <?php if ($editUser): ?>
                    <div class="mb-3">
                        <label class="form-label">Trạng thái</label>
                        <select name="status" class="form-select">
                            <option value="active" <?php echo $editUser['status'] == 'active' ? 'selected' : ''; ?>>Hoạt động</option>
                            <option value="inactive" <?php echo $editUser['status'] == 'inactive' ? 'selected' : ''; ?>>Tạm dừng</option>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" name="<?php echo $editUser ? 'edit_user' : 'add_user'; ?>" class="btn btn-primary">
                        <?php echo $editUser ? 'Cập nhật' : 'Thêm'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Assign Permission Modal -->
<div class="modal fade" id="assignPermissionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <?php echo $security->getCSRFField(); ?>
                <div class="modal-header">
                    <h5 class="modal-title">Phân quyền: <span id="assignPermissionUsername"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="user_id" id="assignPermissionUserId" value="">
                        
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Website</th>
                                        <th>Quản lý Files</th>
                                        <th>Quản lý Database</th>
                                        <th>Backup</th>
                                        <th>Xem thống kê</th>
                                    </tr>
                                </thead>
                                <tbody id="assignPermissionTableBody">
                                    <?php foreach ($websites as $website): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" name="website_ids[]" value="<?php echo $website['id']; ?>" 
                                                   onchange="togglePermissions(this, <?php echo $website['id']; ?>)" 
                                                   class="website-checkbox">
                                            <label class="ms-2"><?php echo escape($website['name']); ?></label>
                                        </td>
                                        <td>
                                            <input type="checkbox" name="permissions[<?php echo $website['id']; ?>][can_manage_files]" 
                                                   id="perm_files_<?php echo $website['id']; ?>"
                                                   disabled>
                                        </td>
                                        <td>
                                            <input type="checkbox" name="permissions[<?php echo $website['id']; ?>][can_manage_database]" 
                                                   id="perm_db_<?php echo $website['id']; ?>"
                                                   disabled>
                                        </td>
                                        <td>
                                            <input type="checkbox" name="permissions[<?php echo $website['id']; ?>][can_backup]" 
                                                   id="perm_backup_<?php echo $website['id']; ?>"
                                                   disabled>
                                        </td>
                                        <td>
                                            <input type="checkbox" name="permissions[<?php echo $website['id']; ?>][can_view_stats]" 
                                                   id="perm_stats_<?php echo $website['id']; ?>"
                                                   disabled>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                    <button type="submit" name="save_permissions" class="btn btn-primary">Lưu phân quyền</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function togglePermissions(checkbox, websiteId) {
    var enabled = checkbox.checked;
    document.getElementById('perm_files_' + websiteId).disabled = !enabled;
    document.getElementById('perm_db_' + websiteId).disabled = !enabled;
    document.getElementById('perm_backup_' + websiteId).disabled = !enabled;
    document.getElementById('perm_stats_' + websiteId).disabled = !enabled;
    
    // Uncheck permissions when website is unchecked
    if (!enabled) {
        document.getElementById('perm_files_' + websiteId).checked = false;
        document.getElementById('perm_db_' + websiteId).checked = false;
        document.getElementById('perm_backup_' + websiteId).checked = false;
        document.getElementById('perm_stats_' + websiteId).checked = false;
    }
}

// Load permissions when modal is shown
document.addEventListener('DOMContentLoaded', function() {
    var assignModal = document.getElementById('assignPermissionModal');
    if (assignModal) {
        assignModal.addEventListener('show.bs.modal', function(event) {
            var button = event.relatedTarget;
            var userId = button.getAttribute('data-user-id');
            var username = button.getAttribute('data-username');
            
            document.getElementById('assignPermissionUserId').value = userId;
            document.getElementById('assignPermissionUsername').textContent = username;
            
            // Load permissions via AJAX
            loadUserPermissionsAjax(userId);
        });
    }
    
    // Auto-open modal if assignUserId is set (from URL parameter)
    <?php if ($assignUserId && $assignUser): ?>
    var modal = new bootstrap.Modal(document.getElementById('assignPermissionModal'));
    document.getElementById('assignPermissionUserId').value = <?php echo $assignUserId; ?>;
    document.getElementById('assignPermissionUsername').textContent = '<?php echo escape($assignUser['username']); ?>';
    <?php
    $userPermissions = $db->fetchAll(
        "SELECT * FROM user_website_permissions WHERE user_id = ?",
        [$assignUserId]
    );
    $permissionMap = [];
    foreach ($userPermissions as $perm) {
        $permissionMap[$perm['website_id']] = $perm;
    }
    ?>
    loadUserPermissions(<?php echo $assignUserId; ?>, <?php echo json_encode($permissionMap); ?>);
    modal.show();
    <?php endif; ?>
});

function loadUserPermissionsAjax(userId) {
    // Reset all checkboxes
    var checkboxes = document.querySelectorAll('#assignPermissionTableBody .website-checkbox');
    checkboxes.forEach(function(cb) {
        cb.checked = false;
        var websiteId = cb.value;
        document.getElementById('perm_files_' + websiteId).checked = false;
        document.getElementById('perm_files_' + websiteId).disabled = true;
        document.getElementById('perm_db_' + websiteId).checked = false;
        document.getElementById('perm_db_' + websiteId).disabled = true;
        document.getElementById('perm_backup_' + websiteId).checked = false;
        document.getElementById('perm_backup_' + websiteId).disabled = true;
        document.getElementById('perm_stats_' + websiteId).checked = false;
        document.getElementById('perm_stats_' + websiteId).disabled = true;
    });
    
    // Load permissions via AJAX
    fetch('<?php echo BASE_URL; ?>ajax/get-user-permissions?user_id=' + userId)
        .then(response => {
            if (!response.ok) {
                throw new Error('HTTP error! status: ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            console.log('Permissions loaded:', data); // Debug
            if (data.success && data.permissions) {
                data.permissions.forEach(function(perm) {
                    var websiteId = String(perm.website_id); // Convert to string for comparison
                    var cb = document.querySelector('#assignPermissionTableBody input.website-checkbox[value="' + websiteId + '"]');
                    if (cb) {
                        cb.checked = true;
                        togglePermissions(cb, websiteId);
                        
                        // Set checkboxes based on permissions (handle both boolean and integer values)
                        var filesCheckbox = document.getElementById('perm_files_' + websiteId);
                        var dbCheckbox = document.getElementById('perm_db_' + websiteId);
                        var backupCheckbox = document.getElementById('perm_backup_' + websiteId);
                        var statsCheckbox = document.getElementById('perm_stats_' + websiteId);
                        
                        if (filesCheckbox) filesCheckbox.checked = !!perm.can_manage_files;
                        if (dbCheckbox) dbCheckbox.checked = !!perm.can_manage_database;
                        if (backupCheckbox) backupCheckbox.checked = !!perm.can_backup;
                        if (statsCheckbox) statsCheckbox.checked = !!perm.can_view_stats;
                    } else {
                        console.warn('Checkbox not found for website ID:', websiteId);
                    }
                });
            } else {
                console.warn('No permissions found or invalid response:', data);
            }
        })
        .catch(error => {
            console.error('Error loading permissions:', error);
        });
}

function loadUserPermissions(userId, permissionMap) {
    // Reset all checkboxes
    var checkboxes = document.querySelectorAll('#assignPermissionTableBody .website-checkbox');
    checkboxes.forEach(function(cb) {
        cb.checked = false;
        var websiteId = cb.value;
        document.getElementById('perm_files_' + websiteId).checked = false;
        document.getElementById('perm_files_' + websiteId).disabled = true;
        document.getElementById('perm_db_' + websiteId).checked = false;
        document.getElementById('perm_db_' + websiteId).disabled = true;
        document.getElementById('perm_backup_' + websiteId).checked = false;
        document.getElementById('perm_backup_' + websiteId).disabled = true;
        document.getElementById('perm_stats_' + websiteId).checked = false;
        document.getElementById('perm_stats_' + websiteId).disabled = true;
    });
    
    // Load from permissionMap if provided
    if (permissionMap) {
        for (var websiteId in permissionMap) {
            var perm = permissionMap[websiteId];
            var cb = document.querySelector('#assignPermissionTableBody input[value="' + websiteId + '"]');
            if (cb) {
                cb.checked = true;
                togglePermissions(cb, websiteId);
                
                // Set checkboxes based on permissions (handle both boolean and integer values)
                document.getElementById('perm_files_' + websiteId).checked = !!perm.can_manage_files;
                document.getElementById('perm_db_' + websiteId).checked = !!perm.can_manage_database;
                document.getElementById('perm_backup_' + websiteId).checked = !!perm.can_backup;
                document.getElementById('perm_stats_' + websiteId).checked = !!perm.can_view_stats;
            }
        }
    }
}
</script>

<?php if ($editUser): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var modal = new bootstrap.Modal(document.getElementById('addUserModal'));
    modal.show();
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
