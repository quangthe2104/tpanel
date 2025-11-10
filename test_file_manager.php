<?php
/**
 * Test File Manager Connection
 * Truy cập: https://yourdomain.com/test_file_manager.php?id=WEBSITE_ID
 * XÓA FILE NÀY SAU KHI TEST XONG!
 */

require_once 'includes/functions.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';
require_once 'includes/HostingerFileManager.php';

$auth = new Auth();
$auth->requireLogin();

error_reporting(E_ALL);
ini_set('display_errors', 1);

$db = Database::getInstance();
$websiteId = $_GET['id'] ?? 0;

if (!$auth->hasWebsiteAccess($websiteId)) {
    die('Bạn không có quyền truy cập website này');
}

$website = $db->fetchOne("SELECT * FROM websites WHERE id = ?", [$websiteId]);
if (!$website) {
    die('Website không tồn tại');
}

?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test File Manager - <?php echo escape($website['name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        pre { background: #f5f5f5; padding: 15px; border-radius: 5px; overflow-x: auto; }
        .success { color: #28a745; }
        .error { color: #dc3545; }
        .info { color: #17a2b8; }
    </style>
</head>
<body>
    <div class="container mt-4">
        <h2>Test File Manager: <?php echo escape($website['name']); ?></h2>
        
        <div class="card mt-4">
            <div class="card-header">
                <h5>Thông tin kết nối</h5>
            </div>
            <div class="card-body">
                <table class="table">
                    <tr>
                        <th>Connection Type:</th>
                        <td><?php echo strtoupper($website['connection_type'] ?? 'FTP'); ?></td>
                    </tr>
                    <tr>
                        <th>Host:</th>
                        <td><?php echo escape($website['sftp_host'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <th>Port:</th>
                        <td><?php echo escape($website['sftp_port'] ?? ($website['connection_type'] === 'sftp' ? '22' : '21')); ?></td>
                    </tr>
                    <tr>
                        <th>Username:</th>
                        <td><?php echo escape($website['sftp_username'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <th>Password:</th>
                        <td><?php echo str_repeat('*', strlen($website['sftp_password'] ?? '')); ?></td>
                    </tr>
                    <tr>
                        <th>Base Path:</th>
                        <td><?php echo escape($website['path'] ?? '/'); ?></td>
                    </tr>
                </table>
            </div>
        </div>
        
        <div class="card mt-4">
            <div class="card-header">
                <h5>Test kết nối</h5>
            </div>
            <div class="card-body">
                <?php
                $fileManager = null;
                $error = null;
                
                try {
                    echo "<p class='info'>Đang thử kết nối...</p>";
                    
                    if (empty($website['sftp_host']) || empty($website['sftp_username']) || empty($website['sftp_password'])) {
                        throw new Exception("Thông tin kết nối chưa đầy đủ");
                    }
                    
                    $fileManager = new HostingerFileManager(
                        $website['sftp_host'],
                        $website['sftp_username'],
                        $website['sftp_password'],
                        $website['path'] ?? '/',
                        $website['connection_type'] ?? 'ftp',
                        $website['sftp_port'] ?? ($website['connection_type'] === 'sftp' ? 22 : 21)
                    );
                    
                    echo "<p class='success'>✓ Kết nối thành công!</p>";
                } catch (Exception $e) {
                    $error = $e->getMessage();
                    echo "<p class='error'>✗ Lỗi kết nối: " . escape($error) . "</p>";
                }
                ?>
            </div>
        </div>
        
        <?php if ($fileManager): ?>
        <div class="card mt-4">
            <div class="card-header">
                <h5>Test list files</h5>
            </div>
            <div class="card-body">
                <?php
                try {
                    echo "<p class='info'>Base Path: " . escape($fileManager->getCurrentPath()) . "</p>";
                    echo "<p class='info'>Relative Path: " . escape($fileManager->getRelativePath()) . "</p>";
                    
                    $files = $fileManager->listFiles();
                    
                    echo "<p class='success'>✓ Tìm thấy " . count($files) . " file/thư mục</p>";
                    
                    if (empty($files)) {
                        echo "<p class='error'>⚠ Thư mục trống hoặc không thể đọc được</p>";
                    } else {
                        echo "<table class='table table-sm'>";
                        echo "<thead><tr><th>Name</th><th>Type</th><th>Size</th><th>Path</th></tr></thead>";
                        echo "<tbody>";
                        foreach ($files as $file) {
                            echo "<tr>";
                            echo "<td>" . escape($file['name']) . "</td>";
                            echo "<td><span class='badge bg-" . ($file['type'] === 'directory' ? 'warning' : 'info') . "'>" . escape($file['type']) . "</span></td>";
                            echo "<td>" . ($file['type'] === 'file' ? formatBytes($file['size']) : '-') . "</td>";
                            echo "<td><code>" . escape($file['path']) . "</code></td>";
                            echo "</tr>";
                        }
                        echo "</tbody></table>";
                    }
                } catch (Exception $e) {
                    echo "<p class='error'>✗ Lỗi list files: " . escape($e->getMessage()) . "</p>";
                }
                ?>
            </div>
        </div>
        
        <div class="card mt-4">
            <div class="card-header">
                <h5>Test tạo file/thư mục</h5>
            </div>
            <div class="card-body">
                <?php
                if (isset($_POST['test_create_file'])) {
                    $testFile = 'test_' . time() . '.txt';
                    $result = $fileManager->createFile($testFile, 'Test content ' . date('Y-m-d H:i:s'));
                    if ($result) {
                        echo "<p class='success'>✓ Tạo file '$testFile' thành công!</p>";
                    } else {
                        echo "<p class='error'>✗ Không thể tạo file '$testFile'</p>";
                    }
                }
                
                if (isset($_POST['test_create_dir'])) {
                    $testDir = 'test_' . time();
                    $result = $fileManager->createDirectory($testDir);
                    if ($result) {
                        echo "<p class='success'>✓ Tạo thư mục '$testDir' thành công!</p>";
                    } else {
                        echo "<p class='error'>✗ Không thể tạo thư mục '$testDir'</p>";
                    }
                }
                ?>
                
                <form method="POST" class="d-inline">
                    <button type="submit" name="test_create_file" class="btn btn-primary">Test tạo file</button>
                </form>
                <form method="POST" class="d-inline">
                    <button type="submit" name="test_create_dir" class="btn btn-primary">Test tạo thư mục</button>
                </form>
            </div>
        </div>
        
        <div class="card mt-4">
            <div class="card-header">
                <h5>PHP Extensions</h5>
            </div>
            <div class="card-body">
                <ul>
                    <li>SSH2: <?php echo extension_loaded('ssh2') ? '<span class="success">✓ Có</span>' : '<span class="error">✗ Không</span>'; ?></li>
                    <li>FTP: <?php echo function_exists('ftp_connect') ? '<span class="success">✓ Có</span>' : '<span class="error">✗ Không</span>'; ?></li>
                </ul>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="alert alert-warning mt-4">
            <strong>⚠️ Bảo mật:</strong> Xóa file test_file_manager.php sau khi test xong!
        </div>
    </div>
</body>
</html>
