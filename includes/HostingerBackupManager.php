<?php
require_once __DIR__ . '/HostingerFileManager.php';
require_once __DIR__ . '/DatabaseManager.php';

/**
 * Backup Manager sử dụng Hostinger SFTP/FTP và MySQL
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
        
        // Khởi tạo FileManager với thông tin Hostinger
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
        if (!is_dir(BACKUP_DIR)) {
            mkdir(BACKUP_DIR, 0755, true);
        }
        
        $timestamp = date('Y-m-d_H-i-s');
        $backupName = "backup_{$this->website['name']}_{$timestamp}";
        $backupPath = BACKUP_DIR . $backupName;
        mkdir($backupPath, 0755, true);
        
        $backupId = $this->logBackup('full', $backupName, $backupPath);
        
        try {
            // Backup files qua SFTP/FTP
            $this->backupFiles($this->website['path'], $backupPath . '/files');
            
            // Backup database
            if ($this->website['db_name']) {
                $this->backupDatabase($backupPath . '/database.sql');
            }
            
            // Create archive
            $archivePath = $this->createArchive($backupPath, $backupName);
            $fileSize = filesize($archivePath);
            
            $this->db->query(
                "UPDATE backups SET status = 'completed', file_path = ?, file_size = ? WHERE id = ?",
                [$archivePath, $fileSize, $backupId]
            );
            
            $this->deleteDirectory($backupPath);
            
            return $archivePath;
        } catch (Exception $e) {
            $this->db->query("UPDATE backups SET status = 'failed' WHERE id = ?", [$backupId]);
            throw $e;
        }
    }
    
    public function createFilesBackup() {
        if (!is_dir(BACKUP_DIR)) {
            mkdir(BACKUP_DIR, 0755, true);
        }
        
        $timestamp = date('Y-m-d_H-i-s');
        $backupName = "backup_files_{$this->website['name']}_{$timestamp}";
        $backupPath = BACKUP_DIR . $backupName;
        mkdir($backupPath, 0755, true);
        
        $backupId = $this->logBackup('files', $backupName, $backupPath);
        
        try {
            $this->backupFiles($this->website['path'], $backupPath);
            $archivePath = $this->createArchive($backupPath, $backupName);
            $fileSize = filesize($archivePath);
            
            $this->db->query(
                "UPDATE backups SET status = 'completed', file_path = ?, file_size = ? WHERE id = ?",
                [$archivePath, $fileSize, $backupId]
            );
            
            $this->deleteDirectory($backupPath);
            
            return $archivePath;
        } catch (Exception $e) {
            $this->db->query("UPDATE backups SET status = 'failed' WHERE id = ?", [$backupId]);
            throw $e;
        }
    }
    
    public function createDatabaseBackup() {
        if (!$this->website['db_name']) {
            throw new Exception("Database not configured");
        }
        
        if (!is_dir(BACKUP_DIR)) {
            mkdir(BACKUP_DIR, 0755, true);
        }
        
        $timestamp = date('Y-m-d_H-i-s');
        $backupName = "backup_db_{$this->website['name']}_{$timestamp}.sql";
        $backupPath = BACKUP_DIR . $backupName;
        
        $backupId = $this->logBackup('database', $backupName, $backupPath);
        
        try {
            $this->backupDatabase($backupPath);
            $fileSize = filesize($backupPath);
            
            $this->db->query(
                "UPDATE backups SET status = 'completed', file_size = ? WHERE id = ?",
                [$fileSize, $backupId]
            );
            
            return $backupPath;
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
        return $this->db->fetchAll(
            "SELECT b.*, w.name as website_name, u.username 
             FROM backups b 
             LEFT JOIN websites w ON b.website_id = w.id 
             LEFT JOIN users u ON b.user_id = u.id 
             WHERE b.website_id = ? 
             ORDER BY b.created_at DESC 
             LIMIT ?",
            [$this->websiteId, $limit]
        );
    }
    
    public function deleteBackup($backupId) {
        $backup = $this->db->fetchOne("SELECT * FROM backups WHERE id = ? AND website_id = ?", [$backupId, $this->websiteId]);
        if ($backup && file_exists($backup['file_path'])) {
            unlink($backup['file_path']);
        }
        $this->db->query("DELETE FROM backups WHERE id = ? AND website_id = ?", [$backupId, $this->websiteId]);
    }
}
