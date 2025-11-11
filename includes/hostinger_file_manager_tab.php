<?php
$action = $_GET['action'] ?? 'list';
$currentPath = $_GET['path'] ?? '';

if ($currentPath && $fileManager) {
    $fileManager->setPath($currentPath);
}

// Handle file operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $fileManager) {
    $auth->logActivity($auth->getUserId(), $websiteId, 'file_operation', 'File operation performed');
    
    if (isset($_POST['create_file'])) {
        $filename = trim($_POST['filename'] ?? '');
        $content = $_POST['content'] ?? '';
        if (empty($filename)) {
            echo '<div class="alert alert-danger">Tên file không được để trống!</div>';
        } elseif ($fileManager->createFile($filename, $content)) {
            // Redirect to GET URL to avoid resubmitting POST
            $redirectUrl = 'website_manage.php?id=' . urlencode($websiteId) . '&tab=files';
            if (!empty($currentPath)) {
                $redirectUrl .= '&path=' . urlencode($currentPath);
            }
            header('Location: ' . $redirectUrl);
            exit;
        } else {
            echo '<div class="alert alert-danger">Không thể tạo file! Kiểm tra quyền truy cập và error log.</div>';
        }
    } elseif (isset($_POST['create_folder'])) {
        $dirname = trim($_POST['dirname'] ?? '');
        if (empty($dirname)) {
            echo '<div class="alert alert-danger">Tên thư mục không được để trống!</div>';
        } elseif ($fileManager->createDirectory($dirname)) {
            // Redirect to GET URL to avoid resubmitting POST
            $redirectUrl = 'website_manage.php?id=' . urlencode($websiteId) . '&tab=files';
            if (!empty($currentPath)) {
                $redirectUrl .= '&path=' . urlencode($currentPath);
            }
            header('Location: ' . $redirectUrl);
            exit;
        } else {
            echo '<div class="alert alert-danger">Không thể tạo thư mục! Kiểm tra quyền truy cập và error log.</div>';
        }
    } elseif (isset($_POST['delete'])) {
        $path = $_POST['path'];
        if ($fileManager->deleteFile($path)) {
            // Redirect to GET URL to avoid resubmitting POST
            $redirectUrl = 'website_manage.php?id=' . urlencode($websiteId) . '&tab=files';
            if (!empty($currentPath)) {
                $redirectUrl .= '&path=' . urlencode($currentPath);
            }
            header('Location: ' . $redirectUrl);
            exit;
        } else {
            echo '<div class="alert alert-danger">Không thể xóa!</div>';
        }
    } elseif (isset($_POST['save_file'])) {
        $path = $_POST['path'];
        $content = $_POST['content'];
        if ($fileManager->saveFileContent($path, $content)) {
            echo '<div class="alert alert-success">Lưu file thành công!</div>';
        } else {
            echo '<div class="alert alert-danger">Không thể lưu file!</div>';
        }
    } elseif (isset($_FILES['upload_file'])) {
        $dest = $_POST['upload_path'] ?? '';
        if ($fileManager->uploadFile($_FILES['upload_file'], $dest)) {
            // Redirect to GET URL to avoid resubmitting POST
            $redirectUrl = 'website_manage.php?id=' . urlencode($websiteId) . '&tab=files';
            if (!empty($currentPath)) {
                $redirectUrl .= '&path=' . urlencode($currentPath);
            }
            header('Location: ' . $redirectUrl);
            exit;
        } else {
            echo '<div class="alert alert-danger">Upload thất bại!</div>';
        }
    }
}

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
                    <li class="breadcrumb-item"><a href="?id=<?php echo $websiteId; ?>&tab=files&path=">Root</a></li>
                    <?php
                    $pathParts = explode('/', trim($relativePath, '/'));
                    $currentPath = '';
                    foreach ($pathParts as $part) {
                        if ($part) {
                            $currentPath .= '/' . $part;
                            echo '<li class="breadcrumb-item"><a href="?id=' . $websiteId . '&tab=files&path=' . urlencode($currentPath) . '">' . escape($part) . '</a></li>';
                        }
                    }
                    ?>
                </ol>
            </nav>
            
            <!-- File List -->
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
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
                            <td><i class="bi bi-arrow-left"></i> <a href="?id=<?php echo $websiteId; ?>&tab=files&path=<?php echo urlencode(dirname($relativePath)); ?>">..</a></td>
                            <td>-</td>
                            <td>-</td>
                            <td>-</td>
                            <td>-</td>
                        </tr>
                        <?php endif; ?>
                        
                        <?php if (empty($files)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted">
                                <i class="bi bi-folder-x"></i> Không có file hoặc thư mục nào
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($files as $file): ?>
                        <tr>
                            <td>
                                <i class="bi bi-<?php echo $file['type'] == 'directory' ? 'folder-fill text-warning' : 'file-text'; ?>"></i>
                                <?php if ($file['type'] == 'directory'): ?>
                                    <a href="?id=<?php echo $websiteId; ?>&tab=files&path=<?php echo urlencode($file['path']); ?>">
                                        <?php echo escape($file['name']); ?>
                                    </a>
                                <?php else: ?>
                                    <a href="?id=<?php echo $websiteId; ?>&tab=files&action=edit&path=<?php echo urlencode($file['path']); ?>">
                                        <?php echo escape($file['name']); ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $file['type'] == 'file' ? formatBytes($file['size']) : '-'; ?></td>
                            <td><?php echo date('d/m/Y H:i', $file['modified']); ?></td>
                            <td><code><?php echo $file['permissions']; ?></code></td>
                            <td>
                                <?php if ($file['type'] == 'file'): ?>
                                    <a href="?id=<?php echo $websiteId; ?>&tab=files&action=edit&path=<?php echo urlencode($file['path']); ?>" class="btn btn-sm btn-info" title="Chỉnh sửa">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                <?php endif; ?>
                                <button class="btn btn-sm btn-danger" onclick="deleteItem('<?php echo urlencode($file['path']); ?>', '<?php echo escape($file['name']); ?>')" title="Xóa">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($action == 'edit' && $fileManager): ?>
    <?php
    $editPath = $_GET['path'] ?? '';
    $content = $fileManager->getFileContent($editPath);
    ?>
    <div class="card mt-4">
        <div class="card-header">
            <h5>Chỉnh sửa: <?php echo escape(basename($editPath)); ?></h5>
        </div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="path" value="<?php echo escape($editPath); ?>">
                <div class="mb-3">
                    <textarea name="content" class="form-control" rows="20" style="font-family: monospace;"><?php echo escape($content); ?></textarea>
                </div>
                <button type="submit" name="save_file" class="btn btn-primary">Lưu</button>
                <a href="?id=<?php echo $websiteId; ?>&tab=files&path=<?php echo urlencode(dirname($editPath)); ?>" class="btn btn-secondary">Hủy</a>
            </form>
        </div>
    </div>
<?php endif; ?>

<!-- Modals -->
<div class="modal fade" id="createFileModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
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

<form id="deleteForm" method="POST" style="display:none;">
    <input type="hidden" name="path" id="deletePath">
    <input type="hidden" name="delete" value="1">
</form>

<script>
function deleteItem(path, name) {
    if (confirm('Bạn có chắc muốn xóa "' + name + '"?')) {
        document.getElementById('deletePath').value = path;
        document.getElementById('deleteForm').submit();
    }
}
</script>
