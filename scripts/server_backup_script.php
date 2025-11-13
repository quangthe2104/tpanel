<?php
/**
 * Backup Script chạy trên server website
 * Script này sẽ được upload lên server và tự động thực hiện backup
 */

// Chạy async, không chờ client disconnect
ignore_user_abort(true);
set_time_limit(0);

// Cấu hình từ tham số
$backupType = $_GET['type'] ?? 'full'; // full, files, database
$backupName = $_GET['name'] ?? 'backup_' . date('Y-m-d_H-i-s');

// Xác định thư mục backup dựa trên vị trí script
$scriptDir = dirname(__FILE__);
$scriptDirReal = realpath($scriptDir);

// Xác định thư mục .tpanel/backups (nơi script đang chạy)
// Script ở: /path/to/website/.tpanel/backups/backup_script.php
// Backup dir nên là: /path/to/website/.tpanel/backups/
if (basename($scriptDir) === 'backups' && basename(dirname($scriptDir)) === '.tpanel') {
    // Script đang ở đúng vị trí: .tpanel/backups
    $backupDir = $scriptDirReal . '/';
} else {
    // Fallback: dùng relative path từ script location
    $backupDir = $scriptDirReal . '/';
}

$statusFile = $backupDir . $backupName . '.status';
$resultFile = $backupDir . $backupName . '.result';

// Tạo thư mục backup nếu chưa có
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0755, true);
}

// Ghi trạng thái: đang xử lý
file_put_contents($statusFile, json_encode([
    'status' => 'processing',
    'message' => 'Đang tạo backup...',
    'started_at' => date('Y-m-d H:i:s')
]));

try {
    $backupPath = '';
    
    if ($backupType === 'full' || $backupType === 'files') {
        // Backup files: zip toàn bộ thư mục hiện tại
        $zipFile = $backupDir . $backupName . '.zip';
        $zip = new ZipArchive();
        
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
            throw new Exception("Cannot create zip archive");
        }
        
        // Backup từ thư mục cha của .tpanel/backups (thư mục website)
        // Script ở: /path/to/website/.tpanel/backups/backup_script.php
        // Cần backup từ: /path/to/website/
        if (basename($scriptDir) === 'backups' && basename(dirname($scriptDir)) === '.tpanel') {
            // Script đang ở đúng vị trí: .tpanel/backups
            $backupSourceDir = dirname(dirname($scriptDirReal)); // Thư mục cha của .tpanel
        } else {
            // Fallback: backup thư mục hiện tại (nếu script không ở đúng vị trí)
            $backupSourceDir = dirname($scriptDirReal);
            // Nếu vẫn ở trong .tpanel, lên thêm 1 cấp
            if (basename($backupSourceDir) === '.tpanel') {
                $backupSourceDir = dirname($backupSourceDir);
            }
        }
        
        // Đảm bảo backupSourceDir là absolute path và tồn tại
        $backupSourceDir = realpath($backupSourceDir) ?: $backupSourceDir;
        if (!is_dir($backupSourceDir)) {
            throw new Exception("Backup source directory does not exist: $backupSourceDir");
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($backupSourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($iterator as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                // Bỏ qua thư mục backup và file script này
                    if (strpos($filePath, '.tpanel') === false && 
                        basename($filePath) !== basename(__FILE__) &&
                        strpos($filePath, 'backup_script.php') === false) {
                    $relativePath = substr($filePath, strlen($backupSourceDir) + 1);
                    $zip->addFile($filePath, $relativePath);
                }
            }
        }
        
        $zip->close();
        $backupPath = $zipFile;
        $fileSize = filesize($zipFile);
    }
    
    if ($backupType === 'full' || $backupType === 'database') {
        // Backup database: cần thông tin DB từ config hoặc tham số
        $dbHost = $_GET['db_host'] ?? 'localhost';
        $dbName = $_GET['db_name'] ?? '';
        $dbUser = $_GET['db_user'] ?? '';
        $dbPass = $_GET['db_pass'] ?? '';
        
        if (empty($dbName) || empty($dbUser)) {
            throw new Exception("Database information not provided. db_name: " . ($dbName ?: 'empty') . ", db_user: " . ($dbUser ?: 'empty'));
        }
        
        // Trên shared hosting, thường cần dùng localhost thay vì IP
        // Thử localhost trước, nếu fail thì thử IP gốc
        $hostsToTry = ['localhost', $dbHost];
        if ($dbHost !== 'localhost' && $dbHost !== '127.0.0.1') {
            $hostsToTry = ['localhost', '127.0.0.1', $dbHost];
        }
        
        // Export database
        $sqlFile = $backupDir . $backupName . '.sql';
        
        $pdo = null;
        $lastError = null;
        
        foreach ($hostsToTry as $tryHost) {
            try {
                $pdo = new PDO(
                    "mysql:host=$tryHost;dbname=$dbName;charset=utf8mb4",
                    $dbUser,
                    $dbPass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                break;
            } catch (PDOException $e) {
                $lastError = $e->getMessage();
                $pdo = null;
            }
        }
        
        if (!$pdo) {
            throw new Exception("Database connection failed: $lastError");
        }
        
        try {
            $sql = "-- Database Export: $dbName\n";
            $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
            
            // Get all tables
            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($tables as $table) {
                // Export table structure
                $stmt = $pdo->query("SHOW CREATE TABLE `$table`");
                $createTable = $stmt->fetch();
                $sql .= "\n-- Table structure for `$table`\n";
                $sql .= "DROP TABLE IF EXISTS `$table`;\n";
                $sql .= $createTable['Create Table'] . ";\n\n";
                
                // Export table data
                $stmt = $pdo->query("SELECT * FROM `$table`");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (count($rows) > 0) {
                    $sql .= "-- Data for table `$table`\n";
                    $columns = array_keys($rows[0]);
                    $sql .= "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES\n";
                    
                    $values = [];
                    foreach ($rows as $row) {
                        $rowValues = [];
                        foreach ($row as $value) {
                            if ($value === null) {
                                $rowValues[] = 'NULL';
                            } else {
                                $rowValues[] = $pdo->quote($value);
                            }
                        }
                        $values[] = '(' . implode(',', $rowValues) . ')';
                    }
                    $sql .= implode(",\n", $values) . ";\n\n";
                }
            }
            
            file_put_contents($sqlFile, $sql);
            
            if (!file_exists($sqlFile)) {
                throw new Exception("Failed to create SQL file: $sqlFile");
            }
            
            $fileSize = filesize($sqlFile);
        } catch (PDOException $e) {
            throw new Exception("Database export failed: " . $e->getMessage());
        }
        
        if ($backupType === 'database') {
            if (!file_exists($sqlFile)) {
                throw new Exception("Database backup file not created: $sqlFile");
            }
            $fileSize = filesize($sqlFile);
            if ($fileSize == 0) {
                throw new Exception("Database backup file is empty: $sqlFile");
            }
            
            // Nén file SQL thành ZIP để tối ưu dung lượng
            $zipFile = $backupDir . $backupName . '.zip';
            $zip = new ZipArchive();
            
            if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
                throw new Exception("Cannot create ZIP archive: $zipFile");
            }
            
            // Thêm file SQL vào ZIP với tên gốc
            $zip->addFile($sqlFile, basename($sqlFile));
            $zip->close();
            
            // Xóa file SQL gốc sau khi nén
            @unlink($sqlFile);
            
            $zipSize = filesize($zipFile);
            $backupPath = $zipFile;
            $fileSize = $zipSize;
        } else {
            // Nếu là full backup, thêm SQL vào zip với tên file đúng format
            if (file_exists($sqlFile)) {
                $zip = new ZipArchive();
                if ($zip->open($backupPath, ZipArchive::CREATE) === TRUE) {
                    $zip->addFile($sqlFile, basename($sqlFile));
                    $zip->close();
                    unlink($sqlFile);
                    $fileSize = filesize($backupPath);
                }
            }
        }
    }
    
    // Ghi kết quả thành công
    file_put_contents($resultFile, json_encode([
        'status' => 'completed',
        'file_path' => $backupPath,
        'file_size' => $fileSize ?? 0,
        'completed_at' => date('Y-m-d H:i:s')
    ]));
    
    // Cập nhật status
    file_put_contents($statusFile, json_encode([
        'status' => 'completed',
        'message' => 'Backup hoàn thành',
        'file_path' => $backupPath,
        'file_size' => $fileSize ?? 0,
        'completed_at' => date('Y-m-d H:i:s')
    ]));
    
    echo json_encode(['success' => true, 'message' => 'Backup completed', 'file' => $backupPath]);
    
} catch (Exception $e) {
    // Ghi lỗi
    file_put_contents($statusFile, json_encode([
        'status' => 'failed',
        'message' => $e->getMessage(),
        'failed_at' => date('Y-m-d H:i:s')
    ]));
    
    file_put_contents($resultFile, json_encode([
        'status' => 'failed',
        'error' => $e->getMessage(),
        'failed_at' => date('Y-m-d H:i:s')
    ]));
    
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

