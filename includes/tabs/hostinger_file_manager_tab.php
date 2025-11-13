<?php
$security = Security::getInstance();
$action = isset($_GET['action']) && in_array($_GET['action'], ['list', 'edit', 'view']) ? $_GET['action'] : 'list';
$currentPath = $security->sanitizePath($_GET['path'] ?? '');

if ($currentPath && $fileManager) {
    $fileManager->setPath($currentPath);
}

// POST requests are now handled in website_manage.php BEFORE output
// This file only displays the UI

if ($fileManager) {
    $files = $fileManager->listFiles();
    $relativePath = $fileManager->getRelativePath();
} else {
    $files = [];
    $relativePath = '';
}
?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-folder"></i> File Manager (<?php echo strtoupper($website['connection_type'] ?? 'SFTP'); ?>)</h5>
        <?php if ($fileManager): ?>
        <div>
            <a href="<?php echo websiteUrl($websiteId, 'files', '.tpanel/trash'); ?>" class="btn btn-sm btn-secondary" title="Thùng rác">
                <i class="bi bi-trash"></i> Thùng rác
            </a>
            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#createFileModal">
                <i class="bi bi-file-plus"></i> Tạo File
            </button>
            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#createFolderModal">
                <i class="bi bi-folder-plus"></i> Tạo Thư mục
            </button>
            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#uploadModal">
                <i class="bi bi-upload"></i> Upload
            </button>
        </div>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php 
        // Display session messages
        if (isset($_SESSION['file_manager_error'])) {
            echo '<div class="alert alert-danger">' . escape($_SESSION['file_manager_error']) . '</div>';
            unset($_SESSION['file_manager_error']);
        }
        if (isset($_SESSION['file_manager_success'])) {
            echo '<div class="alert alert-success">' . escape($_SESSION['file_manager_success']) . '</div>';
            unset($_SESSION['file_manager_success']);
        }
        ?>
        <?php if (!$fileManager): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle"></i> <strong>Không thể kết nối đến server.</strong><br>
                <?php if (isset($connectionError)): ?>
                    <small>Lỗi: <?php echo escape($connectionError); ?></small><br>
                <?php endif; ?>
                <small>Vui lòng kiểm tra:</small>
                <ul class="mb-0">
                    <li>Thông tin SFTP/FTP trong phần quản lý website (host, username, password, port)</li>
                    <li>Đường dẫn (path) có đúng không</li>
                    <li>PHP extension SSH2 (cho SFTP) hoặc FTP (cho FTP) đã được cài đặt chưa</li>
                    <li>Xem error log để biết chi tiết lỗi</li>
                </ul>
            </div>
        <?php else: ?>
            <?php if (empty($files) && empty($relativePath)): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i> Thư mục hiện tại trống hoặc không thể đọc được.
                    <?php if (isset($connectionError)): ?>
                        <br><small>Lỗi: <?php echo escape($connectionError); ?></small>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <!-- Breadcrumb -->
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?php echo websiteUrl($websiteId, 'files'); ?>">Root</a></li>
                    <?php
                    // Use currentPath from fileManager instead of relativePath for breadcrumb
                    $currentPathForBreadcrumb = $fileManager ? $fileManager->getCurrentPath() : '';
                    
                    // If we're editing a file, use the directory path instead of file path
                    if ($action === 'edit' && !empty($_GET['path'])) {
                        $editPath = $_GET['path'];
                        // Get directory path (remove filename)
                        $currentPathForBreadcrumb = dirname($currentPathForBreadcrumb);
                        if ($currentPathForBreadcrumb === '.' || $currentPathForBreadcrumb === '/') {
                            $currentPathForBreadcrumb = $fileManager ? $fileManager->getBasePath() : '/';
                        }
                    }
                    
                    // Remove basePath from currentPath to get relative path for breadcrumb
                    $basePath = $fileManager ? $fileManager->getBasePath() : '/';
                    
                    // Only remove basePath from the beginning, not all occurrences
                    if ($basePath === '/' || empty($basePath)) {
                        // If basePath is root, just remove leading slash
                        $breadcrumbPath = ltrim($currentPathForBreadcrumb, '/');
                    } else {
                        // If basePath is not root, remove it from the beginning
                        if (strpos($currentPathForBreadcrumb, $basePath) === 0) {
                            $breadcrumbPath = substr($currentPathForBreadcrumb, strlen($basePath));
                            $breadcrumbPath = ltrim($breadcrumbPath, '/');
                        } else {
                            $breadcrumbPath = '';
                        }
                    }
                    
                    if (!empty($breadcrumbPath)) {
                        // Special handling for .tpanel/trash - treat it as a single unit
                        $pathParts = [];
                        if (strpos($breadcrumbPath, '.tpanel/trash') === 0) {
                            // Path starts with .tpanel/trash
                            $pathParts[] = '.tpanel/trash';
                            $remaining = substr($breadcrumbPath, 13); // Remove '.tpanel/trash'
                            $remaining = ltrim($remaining, '/');
                            // Only show subdirectories if we're actually navigating into them
                            // Check if we're in a subdirectory by comparing with the actual path from GET
                            // Note: $_GET['path'] is already decoded by router.php
                            $actualPath = isset($_GET['path']) ? $_GET['path'] : '';
                            if (!empty($remaining) && !empty($actualPath) && $actualPath !== '.tpanel/trash' && strpos($actualPath, '.tpanel/trash/') === 0) {
                                // We're actually in a subdirectory of trash, show it
                                $remainingParts = explode('/', $remaining);
                                $pathParts = array_merge($pathParts, $remainingParts);
                            }
                            // If remaining is empty or we're just at .tpanel/trash, don't add anything
                        } else {
                            // Normal path - special handling for .tpanel subdirectories
                            if (strpos($breadcrumbPath, '.tpanel/') === 0) {
                                // Path starts with .tpanel/, treat special paths as single units
                                $pathParts = [];
                                
                                // Special handling for .tpanel/trash and .tpanel/backups - treat as single unit
                                if (strpos($breadcrumbPath, '.tpanel/trash') === 0) {
                                    $pathParts[] = '.tpanel/trash';
                                    $remaining = substr($breadcrumbPath, 13); // Remove '.tpanel/trash'
                                    $remaining = ltrim($remaining, '/');
                                    if (!empty($remaining)) {
                                        $remainingParts = explode('/', $remaining);
                                        $pathParts = array_merge($pathParts, $remainingParts);
                                    }
                                } elseif (strpos($breadcrumbPath, '.tpanel/backups') === 0) {
                                    $pathParts[] = '.tpanel/backups';
                                    $remaining = substr($breadcrumbPath, 15); // Remove '.tpanel/backups'
                                    $remaining = ltrim($remaining, '/');
                                    if (!empty($remaining)) {
                                        $remainingParts = explode('/', $remaining);
                                        $pathParts = array_merge($pathParts, $remainingParts);
                                    }
                                } else {
                                    // Other .tpanel subdirectories
                                    $remaining = $breadcrumbPath;
                                    // Extract .tpanel first
                                    if (strpos($remaining, '.tpanel/') === 0) {
                                        $pathParts[] = '.tpanel';
                                        $remaining = substr($remaining, 8); // Remove '.tpanel/'
                                    } elseif ($remaining === '.tpanel') {
                                        $pathParts[] = '.tpanel';
                                        $remaining = '';
                                    }
                                    
                                    // Split remaining path
                                    if (!empty($remaining)) {
                                        $remainingParts = explode('/', $remaining);
                                        $pathParts = array_merge($pathParts, $remainingParts);
                                    }
                                }
                            } else {
                                // Normal path
                                $pathParts = explode('/', $breadcrumbPath);
                            }
                        }
                        
                        $currentPath = '';
                        foreach ($pathParts as $part) {
                            if ($part) {
                                $currentPath .= ($currentPath ? '/' : '') . $part;
                                // Display name: special handling for .tpanel subdirectories
                                if ($part === '.tpanel/trash') {
                                    $displayName = 'Thùng rác';
                                } elseif ($part === '.tpanel/backups') {
                                    $displayName = 'Backups';
                                } else {
                                    $displayName = $part;
                                }
                                echo '<li class="breadcrumb-item"><a href="' . websiteUrl($websiteId, 'files', $currentPath) . '">' . escape($displayName) . '</a></li>';
                            }
                        }
                    }
                    
                    // If editing a file, show filename as last breadcrumb item (not clickable)
                    if ($action === 'edit' && !empty($_GET['path'])) {
                        $editPath = $_GET['path'];
                        $filename = basename($editPath);
                        echo '<li class="breadcrumb-item active" aria-current="page">' . escape($filename) . '</li>';
                    }
                    ?>
                </ol>
            </nav>
            
            <?php if ($action !== 'edit'): ?>
            <!-- Bulk Actions -->
            <form id="bulkActionForm" method="POST" onsubmit="return confirmBulkAction();">
                <?php echo $security->getCSRFField(); ?>
                <div class="mb-3 d-flex align-items-center gap-3" style="background: #f8f9fa; padding: 12px; border-radius: 6px;">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="selectAll" onchange="toggleSelectAll(this)">
                        <label class="form-check-label" for="selectAll">
                            <strong>Chọn tất cả</strong>
                        </label>
                    </div>
                    <div class="flex-grow-1"></div>
                    <div class="d-flex align-items-center gap-2">
                        <select name="bulk_action" id="bulkActionSelect" class="form-select form-select-sm" style="width: auto;" required>
                            <option value="">-- Chọn thao tác --</option>
                            <option value="delete">Xóa vào thùng rác</option>
                        </select>
                        <button type="submit" class="btn btn-sm btn-danger" id="bulkActionSubmit" disabled>
                            <i class="bi bi-check-circle"></i> Thực hiện
                        </button>
                    </div>
                </div>
                
                <!-- File List -->
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th style="width: 40px;">
                                    <input type="checkbox" class="form-check-input" id="selectAllHeader" onchange="toggleSelectAll(this)">
                                </th>
                                <th>Tên</th>
                                <th>Kích thước</th>
                                <th>Ngày sửa</th>
                                <th>Quyền</th>
                                <th>Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($relativePath)): ?>
                            <tr>
                                <td>-</td>
                                <td><i class="bi bi-arrow-left"></i> <a href="<?php echo websiteUrl($websiteId, 'files', dirname($relativePath)); ?>">..</a></td>
                                <td>-</td>
                                <td>-</td>
                                <td>-</td>
                                <td>-</td>
                            </tr>
                            <?php endif; ?>
                            
                            <?php if (empty($files)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted">
                                    <i class="bi bi-folder-x"></i> Không có file hoặc thư mục nào
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($files as $file): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="selected_items[]" value="<?php echo escape($file['path']); ?>" class="form-check-input file-checkbox" onchange="updateBulkActionButton()">
                                </td>
                                <td>
                                    <i class="bi bi-<?php echo $file['type'] == 'directory' ? 'folder-fill text-warning' : 'file-text'; ?>"></i>
                                    <?php if ($file['type'] == 'directory'): ?>
                                        <a href="<?php echo websiteUrl($websiteId, 'files', $file['path']); ?>">
                                            <?php echo escape($file['name']); ?>
                                        </a>
                                    <?php else: ?>
                                        <a href="<?php echo websiteUrl($websiteId, 'files', $file['path'], 'edit'); ?>">
                                            <?php echo escape($file['name']); ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $file['type'] == 'file' ? formatBytes($file['size']) : '-'; ?></td>
                                <td><?php echo date('d/m/Y H:i', $file['modified']); ?></td>
                                <td><code><?php echo $file['permissions']; ?></code></td>
                                <td>
                                    <?php if ($file['type'] == 'file'): ?>
                                        <a href="<?php echo websiteUrl($websiteId, 'files', $file['path'], 'edit'); ?>" class="btn btn-sm btn-info" title="Chỉnh sửa">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($action == 'edit' && $fileManager): ?>
    <?php
    $editPath = isset($_GET['path']) ? $_GET['path'] : '';
    $content = $fileManager->getFileContent($editPath);
    ?>
    <div class="card mt-4">
        <div class="card-header">
            <h5>Chỉnh sửa: <?php echo escape(basename($editPath)); ?></h5>
        </div>
        <div class="card-body">
            <form method="POST">
                <?php $security = Security::getInstance(); echo $security->getCSRFField(); ?>
                <input type="hidden" name="path" value="<?php echo escape($editPath); ?>">
                <div class="mb-3">
                    <textarea name="content" class="form-control" rows="20" style="font-family: monospace;"><?php echo escape($content); ?></textarea>
                </div>
                <button type="submit" name="save_file" class="btn btn-primary">Lưu</button>
                <a href="<?php echo websiteUrl($websiteId, 'files', dirname($editPath)); ?>" class="btn btn-secondary">Hủy</a>
            </form>
        </div>
    </div>
<?php endif; ?>

<!-- Modals -->
<div class="modal fade" id="createFileModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?php $security = Security::getInstance(); echo $security->getCSRFField(); ?>
                <div class="modal-header">
                    <h5 class="modal-title">Tạo File mới</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Tên file</label>
                        <input type="text" name="filename" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nội dung</label>
                        <textarea name="content" class="form-control" rows="5"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" name="create_file" class="btn btn-primary">Tạo</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="createFolderModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?php $security = Security::getInstance(); echo $security->getCSRFField(); ?>
                <div class="modal-header">
                    <h5 class="modal-title">Tạo Thư mục mới</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Tên thư mục</label>
                        <input type="text" name="dirname" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" name="create_folder" class="btn btn-primary">Tạo</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="uploadModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <?php $security = Security::getInstance(); echo $security->getCSRFField(); ?>
                <div class="modal-header">
                    <h5 class="modal-title">Upload File</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Chọn file</label>
                        <input type="file" name="upload_file" class="form-control" required>
                    </div>
                    <input type="hidden" name="upload_path" value="<?php echo escape($relativePath); ?>">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-primary">Upload</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleSelectAll(checkbox) {
    const checkboxes = document.querySelectorAll('.file-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = checkbox.checked;
    });
    document.getElementById('selectAllHeader').checked = checkbox.checked;
    document.getElementById('selectAll').checked = checkbox.checked;
    updateBulkActionButton();
}

function updateBulkActionButton() {
    const checked = document.querySelectorAll('.file-checkbox:checked');
    const select = document.getElementById('bulkActionSelect');
    const submitBtn = document.getElementById('bulkActionSubmit');
    
    if (checked.length > 0 && select.value) {
        submitBtn.disabled = false;
    } else {
        submitBtn.disabled = true;
    }
}

document.getElementById('bulkActionSelect').addEventListener('change', updateBulkActionButton);

function confirmBulkAction() {
    const checked = document.querySelectorAll('.file-checkbox:checked');
    const action = document.getElementById('bulkActionSelect').value;
    
    if (checked.length === 0) {
        alert('Vui lòng chọn ít nhất một file/thư mục!');
        return false;
    }
    
    if (!action) {
        alert('Vui lòng chọn thao tác!');
        return false;
    }
    
    if (action === 'delete') {
        const count = checked.length;
        const names = Array.from(checked).map(cb => {
            const row = cb.closest('tr');
            const nameCell = row.querySelector('td:nth-child(2)');
            return nameCell ? nameCell.textContent.trim() : '';
        }).filter(n => n).slice(0, 5);
        
        let message = 'Bạn có chắc muốn chuyển ' + count + ' item(s) sau vào THÙNG RÁC?\n\n';
        message += names.join('\n');
        if (count > 5) {
            message += '\n... và ' + (count - 5) + ' item(s) khác';
        }
            message += '\n\n📦 File sẽ được chuyển vào thư mục .tpanel/trash và có thể khôi phục sau.';
        
        return confirm(message);
    }
    
    return true;
}

// Show success/error messages from URL params
<?php
if (isset($_GET['success'])) {
    echo "alert('" . escape($_GET['success']) . "');";
}
if (isset($_GET['error'])) {
    echo "alert('" . escape($_GET['error']) . "');";
}
?>
</script>
