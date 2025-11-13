<?php
require_once __DIR__ . '/HostingerFileManager.php';
require_once __DIR__ . '/DatabaseManager.php';

/**
 * Backup Manager sử dụng SFTP/FTP và MySQL
 */
class HostingerBackupManager {
    private $db;
    private $websiteId;
    private $userId;
    private $website;
    private $fileManager;
    
    public function __construct($websiteId, $userId) {
        $this->db = Database::getInstance();
        $this->websiteId = $websiteId;
        $this->userId = $userId;
        
        $this->website = $this->db->fetchOne("SELECT * FROM websites WHERE id = ?", [$websiteId]);
        if (!$this->website) {
            throw new Exception("Website not found");
        }
        
        // Khởi tạo FileManager với thông tin kết nối
        $this->fileManager = new HostingerFileManager(
            $this->website['sftp_host'],
            $this->website['sftp_username'],
            $this->website['sftp_password'],
            $this->website['path'],
            $this->website['connection_type'],
            $this->website['sftp_port']
        );
    }
    
    public function createFullBackup() {
        $this->cleanupExpiredBackups();
        
        $timestamp = date('Y-m-d_H-i-s');
        $backupName = "backup_{$this->website['name']}_{$timestamp}";
        
        $backupId = $this->logBackup('full', $backupName . '.zip', '');
        
        try {
            $scriptPath = __DIR__ . '/../../scripts/server_backup_script.php';
            $remoteScriptPath = '.tpanel/backups/backup_script.php';
            $this->fileManager->putFile($scriptPath, $remoteScriptPath);
            
            $this->triggerBackupScript('full', $backupName, $backupId);
            
            $expiresAt = date('Y-m-d H:i:s', strtotime('+6 hours'));
            
            $this->db->query(
                "UPDATE backups SET status = 'in_progress', expires_at = ? WHERE id = ?",
                [$expiresAt, $backupId]
            );
            
            return ['backup_id' => $backupId, 'status' => 'in_progress'];
        } catch (Exception $e) {
            $this->db->query("UPDATE backups SET status = 'failed' WHERE id = ?", [$backupId]);
            throw $e;
        }
    }
    
    public function createFilesBackup() {
        $this->cleanupExpiredBackups();
        
        $timestamp = date('Y-m-d_H-i-s');
        $backupName = "backup_files_{$this->website['name']}_{$timestamp}";
        
        $backupId = $this->logBackup('files', $backupName . '.zip', '');
        
        try {
            $scriptPath = __DIR__ . '/../../scripts/server_backup_script.php';
            $remoteScriptPath = '.tpanel/backups/backup_script.php';
            $this->fileManager->putFile($scriptPath, $remoteScriptPath);
            
            $this->triggerBackupScript('files', $backupName, $backupId);
            
            $expiresAt = date('Y-m-d H:i:s', strtotime('+6 hours'));
            
            $this->db->query(
                "UPDATE backups SET status = 'in_progress', expires_at = ? WHERE id = ?",
                [$expiresAt, $backupId]
            );
            
            return ['backup_id' => $backupId, 'status' => 'in_progress'];
        } catch (Exception $e) {
            $this->db->query("UPDATE backups SET status = 'failed' WHERE id = ?", [$backupId]);
            throw $e;
        }
    }
    
    public function createDatabaseBackup() {
        if (!$this->website['db_name']) {
            throw new Exception("Database not configured");
        }
        
        $this->cleanupExpiredBackups();
        
        $timestamp = date('Y-m-d_H-i-s');
        $backupName = "backup_db_{$this->website['name']}_{$timestamp}";
        
        $backupId = $this->logBackup('database', $backupName . '.zip', '');
        
        try {
            $scriptPath = __DIR__ . '/../../scripts/server_backup_script.php';
            $remoteScriptPath = '.tpanel/backups/backup_script.php';
            $this->fileManager->putFile($scriptPath, $remoteScriptPath);
            
            $this->triggerBackupScript('database', $backupName, $backupId);
            
            $expiresAt = date('Y-m-d H:i:s', strtotime('+6 hours'));
            
            $this->db->query(
                "UPDATE backups SET status = 'in_progress', expires_at = ? WHERE id = ?",
                [$expiresAt, $backupId]
            );
            
            return ['backup_id' => $backupId, 'status' => 'in_progress'];
        } catch (Exception $e) {
            $this->db->query("UPDATE backups SET status = 'failed' WHERE id = ?", [$backupId]);
            throw $e;
        }
    }
    
    private function backupFiles($remotePath, $localPath) {
        if (!is_dir($localPath)) {
            mkdir($localPath, 0755, true);
        }
        
        $files = $this->fileManager->listFiles($remotePath);
        
        foreach ($files as $file) {
            $localFilePath = $localPath . '/' . $file['name'];
            $remoteFilePath = $remotePath . '/' . $file['name'];
            
            if ($file['type'] === 'directory') {
                if (!is_dir($localFilePath)) {
                    mkdir($localFilePath, 0755, true);
                }
                $this->backupFiles($remoteFilePath, $localFilePath);
            } else {
                $content = $this->fileManager->getFileContent($file['path']);
                if ($content !== false) {
                    file_put_contents($localFilePath, $content);
                }
            }
        }
    }
    
    private function backupDatabase($outputPath) {
        $dbManager = new DatabaseManager(
            $this->website['db_host'],
            $this->website['db_name'],
            $this->website['db_user'],
            $this->website['db_password']
        );
        
        $sql = $dbManager->exportDatabase();
        file_put_contents($outputPath, $sql);
    }
    
    private function createArchive($sourcePath, $archiveName) {
        $archivePath = BACKUP_DIR . $archiveName . '.zip';
        
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
            throw new Exception("Cannot create zip archive");
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourcePath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($iterator as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($sourcePath) + 1);
                $zip->addFile($filePath, $relativePath);
            }
        }
        
        $zip->close();
        return $archivePath;
    }
    
    private function logBackup($type, $filename, $filePath) {
        $this->db->query(
            "INSERT INTO backups (website_id, user_id, type, filename, file_path, status) VALUES (?, ?, ?, ?, ?, 'in_progress')",
            [$this->websiteId, $this->userId, $type, $filename, $filePath]
        );
        return $this->db->lastInsertId();
    }
    
    /**
     * Xóa các file backup đã hết hạn (hơn 6 tiếng)
     */
    private function cleanupExpiredBackups() {
        try {
            $expiredBackups = $this->db->fetchAll(
                "SELECT id, remote_path, filename FROM backups 
                 WHERE website_id = ? 
                 AND status = 'completed' 
                 AND expires_at IS NOT NULL 
                 AND expires_at < NOW()",
                [$this->websiteId]
            );
            
            foreach ($expiredBackups as $backup) {
                $deletedFiles = [];
                
                // Xóa file backup chính - thử nhiều path khác nhau (giống deleteBackup)
                $backupName = !empty($backup['filename']) ? pathinfo($backup['filename'], PATHINFO_FILENAME) : '';
                $backupFilePath = !empty($backup['filename']) ? '.tpanel/backups/' . $backup['filename'] : '';
                
                $backupPathsToTry = [];
                // 1. Path từ database (remote_path)
                if (!empty($backup['remote_path'])) {
                    $backupPathsToTry[] = $backup['remote_path'];
                }
                // 2. Path dựa trên filename
                if (!empty($backupFilePath)) {
                    $backupPathsToTry[] = $backupFilePath;
                }
                // 3. Chỉ filename (nếu đã ở trong thư mục backups)
                if (!empty($backup['filename'])) {
                    $backupPathsToTry[] = $backup['filename'];
                }
                
                $backupFileDeleted = false;
                foreach ($backupPathsToTry as $backupPath) {
                    try {
                        if ($this->fileManager->deleteFile($backupPath)) {
                            $deletedFiles[] = $backupPath;
                            $backupFileDeleted = true;
                            break;
                        }
                    } catch (Exception $e) {
                        // Ignore
                    }
                }
                
                if (!empty($backupName)) {
                    $statusFile = '.tpanel/backups/' . $backupName . '.status';
                    $resultFile = '.tpanel/backups/' . $backupName . '.result';
                    
                    try {
                        if ($this->fileManager->deleteFile($statusFile)) {
                            $deletedFiles[] = $statusFile;
                        }
                    } catch (Exception $e) {
                        // Ignore
                    }
                    
                    try {
                        if ($this->fileManager->deleteFile($resultFile)) {
                            $deletedFiles[] = $resultFile;
                        }
                    } catch (Exception $e) {
                        // Ignore
                    }
                }
                
                $this->db->query("DELETE FROM backups WHERE id = ?", [$backup['id']]);
            }
        } catch (Exception $e) {
            // Ignore
        }
    }
    
    private function deleteDirectory($dir) {
        if (!is_dir($dir)) return;
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $filePath = $dir . '/' . $file;
            is_dir($filePath) ? $this->deleteDirectory($filePath) : unlink($filePath);
        }
        rmdir($dir);
    }
    
    public function getBackups($limit = 50) {
        // Xóa file backup cũ hơn 6 tiếng trước khi lấy danh sách
        $this->cleanupExpiredBackups();
        
        $backups = $this->db->fetchAll(
            "SELECT b.*, w.name as website_name, u.username 
             FROM backups b 
             LEFT JOIN websites w ON b.website_id = w.id 
             LEFT JOIN users u ON b.user_id = u.id 
             WHERE b.website_id = ? 
             ORDER BY b.created_at DESC 
             LIMIT ?",
            [$this->websiteId, $limit]
        );
        
        // Kiểm tra file thực tế trên server cho các backup đã completed
        foreach ($backups as &$backup) {
            $backup['file_exists'] = false;
            
            if ($backup['status'] === 'completed' && !empty($backup['remote_path'])) {
                try {
                    // Kiểm tra file backup chính
                    $backup['file_exists'] = $this->fileManager->fileExists($backup['remote_path']);
                    
                    // Nếu không tìm thấy với remote_path, thử với path dựa trên filename
                    if (!$backup['file_exists']) {
                        $backupFilePath = '.tpanel/backups/' . $backup['filename'];
                        $backup['file_exists'] = $this->fileManager->fileExists($backupFilePath);
                    }
                } catch (Exception $e) {
                    $backup['file_exists'] = false;
                }
            }
        }
        
        return $backups;
    }
    
    public function deleteBackup($backupId) {
        $backup = $this->db->fetchOne("SELECT * FROM backups WHERE id = ? AND website_id = ?", [$backupId, $this->websiteId]);
        if (!$backup) {
            return ['success' => false, 'message' => 'Backup not found'];
        }
        
        $results = [
            'backup_file' => false,
            'status_file' => false,
            'result_file' => false,
            'database' => false
        ];
        
        try {
            // Xóa các file status và result liên quan
            $backupName = pathinfo($backup['filename'], PATHINFO_FILENAME);
            $statusFilePath = '.tpanel/backups/' . $backupName . '.status';
            $resultFilePath = '.tpanel/backups/' . $backupName . '.result';
            $backupFilePath = '.tpanel/backups/' . $backup['filename'];
            
            // Xóa file backup chính trên server website
            // Thử nhiều cách path khác nhau
            $backupPathsToTry = [];
            
            // 1. Path từ database (remote_path)
            if (!empty($backup['remote_path'])) {
                $backupPathsToTry[] = $backup['remote_path'];
            }
            
            // 2. Path dựa trên filename (giống status/result)
            $backupPathsToTry[] = $backupFilePath;
            
            // 3. Chỉ filename (nếu đã ở trong thư mục backups)
            $backupPathsToTry[] = $backup['filename'];
            
            foreach ($backupPathsToTry as $backupPath) {
                try {
                    $result = $this->fileManager->deleteFile($backupPath);
                    if ($result) {
                        $results['backup_file'] = true;
                        break;
                    }
                } catch (Exception $e) {
                    // Ignore
                }
            }
            
            try {
                $result = $this->fileManager->deleteFile($statusFilePath);
                if ($result) {
                    $results['status_file'] = true;
                }
            } catch (Exception $e) {
                // Ignore
            }
            
            try {
                $result = $this->fileManager->deleteFile($resultFilePath);
                if ($result) {
                    $results['result_file'] = true;
                }
            } catch (Exception $e) {
                // Ignore
            }
            
            if (!empty($backup['file_path']) && file_exists($backup['file_path'])) {
                @unlink($backup['file_path']);
            }
        } catch (Exception $e) {
            // Ignore
        }
        
        try {
            $this->db->query("DELETE FROM backups WHERE id = ? AND website_id = ?", [$backupId, $this->websiteId]);
            $results['database'] = true;
        } catch (Exception $e) {
            // Ignore
        }
        
        // Tạo thông báo dựa trên kết quả
        $messages = [];
        if ($results['backup_file']) {
            $messages[] = 'File backup đã được xóa trên server';
        } else {
            $messages[] = 'Không thể xóa file backup trên server (có thể file đã bị xóa trước đó)';
        }
        
        if ($results['status_file']) {
            $messages[] = 'File status đã được xóa';
        }
        
        if ($results['result_file']) {
            $messages[] = 'File result đã được xóa';
        }
        
        if ($results['database']) {
            $messages[] = 'Đã xóa record trong database';
        } else {
            $messages[] = 'Lỗi khi xóa record trong database';
        }
        
        return [
            'success' => $results['database'], // Thành công nếu ít nhất xóa được database record
            'results' => $results,
            'message' => implode('. ', $messages)
        ];
    }
    
    /**
     * Trigger backup script trên server (async)
     */
    private function triggerBackupScript($type, $backupName, $backupId) {
        // Tạo URL để trigger script trên server
        // Lưu ý: Cần có domain của website để gọi script
        $domain = $this->website['domain'] ?? '';
        if (empty($domain)) {
            throw new Exception("Website domain not configured");
        }
        
        // Build URL với tham số
        $params = [
            'type' => $type,
            'name' => $backupName,
            'backup_id' => $backupId
        ];
        
        if ($type === 'full' || $type === 'database') {
            if (!empty($this->website['db_host'])) {
                // Trên shared hosting, thường cần dùng localhost thay vì IP
                // Tự động chuyển IP thành localhost
                $dbHost = $this->website['db_host'];
                if (filter_var($dbHost, FILTER_VALIDATE_IP)) {
                    // Nếu là IP, chuyển thành localhost
                    $dbHost = 'localhost';
                }
                
                $params['db_host'] = $dbHost;
                $params['db_name'] = $this->website['db_name'];
                $params['db_user'] = $this->website['db_user'];
                $params['db_pass'] = $this->website['db_password'];
            }
        }
        
        $url = (strpos($domain, 'http') === 0 ? $domain : 'http://' . $domain) . '/.tpanel/backups/backup_script.php?' . http_build_query($params);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
        curl_exec($ch);
        curl_close($ch);
    }
    
    /**
     * Kiểm tra trạng thái backup từ server
     */
    public function checkBackupStatus($backupId) {
        $backup = $this->db->fetchOne("SELECT * FROM backups WHERE id = ? AND website_id = ?", [$backupId, $this->websiteId]);
        if (!$backup) {
            return ['status' => 'not_found'];
        }
        
        // Nếu đã completed hoặc failed, trả về trạng thái từ database
        if ($backup['status'] === 'completed' || $backup['status'] === 'failed') {
            return [
                'status' => $backup['status'],
                'remote_path' => $backup['remote_path'] ?? null,
                'file_size' => $backup['file_size'] ?? null
            ];
        }
        
        // Nếu đang xử lý, kiểm tra file status trên server
        try {
            $backupName = pathinfo($backup['filename'], PATHINFO_FILENAME);
            $statusFilePath = '.tpanel/backups/' . $backupName . '.status';
            $resultFilePath = '.tpanel/backups/' . $backupName . '.result';
            $backupFilePath = '.tpanel/backups/' . $backup['filename'];
            
            $statusContent = $this->fileManager->getFileContent($statusFilePath);
            $resultContent = $this->fileManager->getFileContent($resultFilePath);
            
            // Ưu tiên đọc từ result file (nếu có)
            if ($resultContent !== false && !empty($resultContent)) {
                $result = json_decode($resultContent, true);
                if ($result && isset($result['status'])) {
                    if ($result['status'] === 'completed') {
                        $remotePath = $result['file_path'] ?? $backupFilePath;
                        $fileSize = $result['file_size'] ?? null;
                        
                        // Nếu chưa có file_size, thử lấy từ file thực tế
                        if (!$fileSize) {
                            try {
                                $fileInfo = $this->fileManager->listFiles('.tpanel/backups');
                                foreach ($fileInfo as $file) {
                                    if ($file['name'] === $backup['filename']) {
                                        $fileSize = $file['size'];
                                        break;
                                    }
                                }
                            } catch (Exception $e) {
                                // Ignore
                            }
                        }
                        
                        $this->db->query(
                            "UPDATE backups SET status = 'completed', remote_path = ?, file_size = ? WHERE id = ?",
                            [$remotePath, $fileSize, $backupId]
                        );
                        
                        return [
                            'status' => 'completed',
                            'remote_path' => $remotePath,
                            'file_size' => $fileSize
                        ];
                    } elseif ($result['status'] === 'failed') {
                        $this->db->query(
                            "UPDATE backups SET status = 'failed' WHERE id = ?",
                            [$backupId]
                        );
                        
                        return [
                            'status' => 'failed',
                            'message' => $result['error'] ?? 'Backup failed'
                        ];
                    }
                }
            }
            
            // Nếu không có result, đọc từ status file
            if ($statusContent !== false && !empty($statusContent)) {
                $status = json_decode($statusContent, true);
                if ($status && isset($status['status'])) {
                    if ($status['status'] === 'completed') {
                        $remotePath = $status['file_path'] ?? $backupFilePath;
                        $fileSize = $status['file_size'] ?? null;
                        
                        // Nếu chưa có file_size, thử lấy từ file thực tế
                        if (!$fileSize) {
                            try {
                                $fileInfo = $this->fileManager->listFiles('.tpanel/backups');
                                foreach ($fileInfo as $file) {
                                    if ($file['name'] === $backup['filename']) {
                                        $fileSize = $file['size'];
                                        break;
                                    }
                                }
                            } catch (Exception $e) {
                                // Ignore
                            }
                        }
                        
                        $this->db->query(
                            "UPDATE backups SET status = 'completed', remote_path = ?, file_size = ? WHERE id = ?",
                            [$remotePath, $fileSize, $backupId]
                        );
                        
                        return [
                            'status' => 'completed',
                            'remote_path' => $remotePath,
                            'file_size' => $fileSize
                        ];
                    } elseif ($status['status'] === 'failed') {
                        $this->db->query(
                            "UPDATE backups SET status = 'failed' WHERE id = ?",
                            [$backupId]
                        );
                        
                        return [
                            'status' => 'failed',
                            'message' => $status['message'] ?? 'Backup failed'
                        ];
                    } elseif ($status['status'] === 'processing') {
                        // Đang xử lý, nhưng kiểm tra xem file backup đã tồn tại chưa
                        try {
                            $fileInfo = $this->fileManager->listFiles('.tpanel/backups');
                            foreach ($fileInfo as $file) {
                                if ($file['name'] === $backup['filename']) {
                                    // File đã tồn tại, mark as completed
                                    $this->db->query(
                                        "UPDATE backups SET status = 'completed', remote_path = ?, file_size = ? WHERE id = ?",
                                        [$backupFilePath, $file['size'], $backupId]
                                    );
                                    
                                    return [
                                        'status' => 'completed',
                                        'remote_path' => $backupFilePath,
                                        'file_size' => $file['size']
                                    ];
                                }
                            }
                        } catch (Exception $e) {
                            // Ignore
                        }
                        
                        return [
                            'status' => 'in_progress',
                            'message' => $status['message'] ?? 'Đang xử lý...'
                        ];
                    }
                }
            }
            
            // Nếu không có cả status và result, nhưng file backup đã tồn tại, mark as completed
            if ($backup['status'] === 'in_progress') {
                try {
                    $fileInfo = $this->fileManager->listFiles('.tpanel/backups');
                    foreach ($fileInfo as $file) {
                        if ($file['name'] === $backup['filename']) {
                            // File đã tồn tại, mark as completed
                            $this->db->query(
                                "UPDATE backups SET status = 'completed', remote_path = ?, file_size = ? WHERE id = ?",
                                [$backupFilePath, $file['size'], $backupId]
                            );
                            
                            return [
                                'status' => 'completed',
                                'remote_path' => $backupFilePath,
                                'file_size' => $file['size']
                            ];
                        }
                    }
                } catch (Exception $e) {
                    // Ignore
                }
            }
        } catch (Exception $e) {
            // Ignore
        }
        
        // Trả về trạng thái hiện tại
        return ['status' => $backup['status'] ?? 'in_progress'];
    }
}

