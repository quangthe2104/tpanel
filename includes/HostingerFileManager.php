<?php
/**
 * File Manager sử dụng SFTP/FTP để kết nối với Hostinger
 */
class HostingerFileManager {
    private $sftpConnection;
    private $ftpConnection;
    private $connectionType; // 'sftp' or 'ftp'
    private $basePath;
    private $currentPath;
    
    public function __construct($host, $username, $password, $basePath = '/', $connectionType = 'sftp', $port = null) {
        $this->basePath = rtrim($basePath, '/');
        $this->currentPath = $this->basePath;
        $this->connectionType = $connectionType;
        
        if ($connectionType === 'sftp') {
            $port = $port ?? 22;
            $this->sftpConnection = $this->connectSFTP($host, $username, $password, $port);
        } else {
            $port = $port ?? 21;
            $this->ftpConnection = $this->connectFTP($host, $username, $password, $port);
        }
    }
    
    private function connectSFTP($host, $username, $password, $port = 22) {
        if (!extension_loaded('ssh2')) {
            throw new Exception("SSH2 extension chưa được cài đặt. Vui lòng cài đặt php-ssh2 extension.");
        }
        
        $connection = ssh2_connect($host, $port);
        if (!$connection) {
            throw new Exception("Không thể kết nối đến SFTP server: $host:$port");
        }
        
        if (!ssh2_auth_password($connection, $username, $password)) {
            throw new Exception("Xác thực SFTP thất bại");
        }
        
        return ssh2_sftp($connection);
    }
    
    private function connectFTP($host, $username, $password, $port = 21, $ssl = false) {
        if ($ssl) {
            $connection = ftp_ssl_connect($host, $port);
        } else {
            $connection = ftp_connect($host, $port);
        }
        
        if (!$connection) {
            throw new Exception("Không thể kết nối đến FTP server: $host:$port");
        }
        
        if (!ftp_login($connection, $username, $password)) {
            throw new Exception("Xác thực FTP thất bại");
        }
        
        ftp_pasv($connection, true);
        return $connection;
    }
    
    public function setPath($path) {
        if (empty($path)) {
            $this->currentPath = $this->basePath;
            return true;
        }
        
        // Remove leading slash and combine with base path
        $path = ltrim($path, '/');
        $newPath = $this->basePath . '/' . $path;
        
        // Normalize path (remove double slashes, etc.)
        $newPath = str_replace(['//', '\\'], '/', $newPath);
        $newPath = rtrim($newPath, '/');
        
        // Ensure it doesn't go below base path
        if (strpos($newPath, $this->basePath) !== 0) {
            $newPath = $this->basePath;
        }
        
        $this->currentPath = $newPath;
        return true;
    }
    
    public function getCurrentPath() {
        return $this->currentPath;
    }
    
    public function getRelativePath() {
        $relative = str_replace($this->basePath, '', $this->currentPath);
        // If paths are the same, return empty (root)
        if (empty($relative) || $relative === $this->currentPath) {
            return '';
        }
        // Remove leading slash and return
        return ltrim($relative, '/');
    }
    
    public function listFiles($path = null) {
        try {
            if ($path !== null) {
                // Use the provided path
                $targetPath = $this->basePath;
                if (!empty($path)) {
                    $targetPath .= '/' . ltrim($path, '/');
                }
            } else {
                // Use current path
                $targetPath = $this->currentPath;
            }
            
            // Normalize path
            $targetPath = rtrim($targetPath, '/');
            if (empty($targetPath)) {
                $targetPath = $this->basePath;
            }
            
            $files = [];
            
            if ($this->connectionType === 'sftp') {
                if (!$this->sftpConnection) {
                    error_log("SFTP: Connection not established");
                    return [];
                }
                $files = $this->listFilesSFTP($targetPath);
            } else {
                if (!$this->ftpConnection) {
                    error_log("FTP: Connection not established");
                    return [];
                }
                $files = $this->listFilesFTP($targetPath);
            }
            
            // Sort: directories first, then files, both alphabetically
            usort($files, function($a, $b) {
                if ($a['type'] !== $b['type']) {
                    return $a['type'] === 'directory' ? -1 : 1;
                }
                return strcmp(strtolower($a['name']), strtolower($b['name']));
            });
            
            return $files;
        } catch (Exception $e) {
            error_log("listFiles error: " . $e->getMessage());
            return [];
        }
    }
    
    private function listFilesSFTP($path) {
        $files = [];
        
        try {
            // Normalize path
            $path = rtrim($path, '/');
            if (empty($path)) {
                $path = $this->basePath;
            }
            
            $sftpUrl = "ssh2.sftp://{$this->sftpConnection}$path";
            $handle = @opendir($sftpUrl);
            
            if (!$handle) {
                // Try to check if directory exists
                $stat = @ssh2_sftp_stat($this->sftpConnection, $path);
                if (!$stat) {
                    error_log("SFTP: Cannot access path: $path");
                    return [];
                }
                // If stat exists but cannot open, try again with base path
                if ($path !== $this->basePath) {
                    $handle = @opendir("ssh2.sftp://{$this->sftpConnection}" . $this->basePath);
                    $path = $this->basePath;
                }
                if (!$handle) {
                    error_log("SFTP: Cannot open directory: $path");
                    return [];
                }
            }
            
            while (($file = readdir($handle)) !== false) {
                if ($file === '.' || $file === '..') continue;
                
                $filePath = rtrim($path, '/') . '/' . $file;
                $stat = @ssh2_sftp_stat($this->sftpConnection, $filePath);
                
                if (!$stat) {
                    continue; // Skip files we can't stat
                }
                
                $isDir = ($stat['mode'] & 0040000) === 0040000;
                
                $relativePath = str_replace($this->basePath, '', $filePath);
                if (empty($relativePath) || $relativePath === $filePath) {
                    $relativePath = '/' . $file;
                }
                $relativePath = ltrim($relativePath, '/');
                
                $files[] = [
                    'name' => $file,
                    'path' => $relativePath,
                    'type' => $isDir ? 'directory' : 'file',
                    'size' => $isDir ? 0 : ($stat['size'] ?? 0),
                    'modified' => $stat['mtime'] ?? time(),
                    'permissions' => isset($stat['mode']) ? substr(sprintf('%o', $stat['mode']), -4) : '0644'
                ];
            }
            
            closedir($handle);
        } catch (Exception $e) {
            error_log("SFTP listFiles error: " . $e->getMessage());
            return [];
        }
        
        return $files;
    }
    
    private function listFilesFTP($path) {
        $files = [];
        
        try {
            // Normalize path
            $path = rtrim($path, '/');
            if (empty($path)) {
                $path = $this->basePath;
            }
            
            // Try to change to directory first to verify it exists
            $currentDir = @ftp_pwd($this->ftpConnection);
            if (!@ftp_chdir($this->ftpConnection, $path)) {
                // If path doesn't work, try base path
                if ($path !== $this->basePath && @ftp_chdir($this->ftpConnection, $this->basePath)) {
                    $path = $this->basePath;
                } else {
                    error_log("FTP: Cannot access path: $path");
                    if ($currentDir) {
                        @ftp_chdir($this->ftpConnection, $currentDir);
                    }
                    return [];
                }
            }
            
            // Use rawlist for more reliable directory listing
            $items = @ftp_rawlist($this->ftpConnection, $path);
            
            if (!$items || !is_array($items)) {
                // Fallback to nlist
                $items = @ftp_nlist($this->ftpConnection, $path);
                if (!$items) {
                    error_log("FTP: Cannot list directory: $path");
                    if ($currentDir) {
                        @ftp_chdir($this->ftpConnection, $currentDir);
                    }
                    return [];
                }
            }
            
            foreach ($items as $item) {
                $name = null;
                $isDir = false;
                $size = 0;
                $modified = time();
                
                // Try to parse rawlist output (Unix format: drwxr-xr-x ...)
                if (preg_match('/^([-d])([rwx-]{9})\s+.*?\s+(\d+)\s+(\w+\s+\d+\s+[\d:]+)\s+(.+)$/', $item, $matches)) {
                    $isDir = ($matches[1] === 'd');
                    $name = trim($matches[5]);
                    $size = $isDir ? 0 : (int)$matches[3];
                    
                    // Try to parse date
                    $dateStr = $matches[4];
                    $modified = @strtotime($dateStr);
                    if (!$modified) {
                        $modified = time();
                    }
                } 
                // Try alternative format
                elseif (preg_match('/^([-d])([rwx-]+)\s+\d+\s+\d+\s+\d+\s+(\d+)\s+(\w+\s+\d+[\s:]\d+)\s+(.+)$/', $item, $matches)) {
                    $isDir = ($matches[1] === 'd');
                    $name = trim($matches[5]);
                    $size = $isDir ? 0 : (int)$matches[3];
                    $modified = @strtotime($matches[4]) ?: time();
                }
                // Fallback: treat as filename from nlist or simple path
                else {
                    $name = basename($item);
                    if (empty($name)) {
                        $name = trim($item);
                    }
                }
                
                if (empty($name) || $name === '.' || $name === '..') {
                    continue;
                }
                
                // Build full path
                $filePath = rtrim($path, '/') . '/' . $name;
                
                // Verify directory/file status if not determined from rawlist
                if (!isset($matches)) {
                    $testSize = @ftp_size($this->ftpConnection, $filePath);
                    if ($testSize === -1) {
                        $isDir = true;
                    } else {
                        $isDir = false;
                        $size = max(0, $testSize);
                    }
                    
                    // Try to get modification time
                    $testModified = @ftp_mdtm($this->ftpConnection, $filePath);
                    if ($testModified > 0) {
                        $modified = $testModified;
                    }
                }
                
                // Calculate relative path
                $relativePath = str_replace($this->basePath, '', $filePath);
                if (empty($relativePath) || $relativePath === $filePath || strpos($relativePath, '/') !== 0) {
                    $relativePath = $name;
                }
                $relativePath = ltrim($relativePath, '/');
                
                $files[] = [
                    'name' => $name,
                    'path' => $relativePath,
                    'type' => $isDir ? 'directory' : 'file',
                    'size' => $isDir ? 0 : max(0, $size),
                    'modified' => $modified,
                    'permissions' => '0644'
                ];
            }
            
            // Restore original directory
            if ($currentDir) {
                @ftp_chdir($this->ftpConnection, $currentDir);
            }
        } catch (Exception $e) {
            error_log("FTP listFiles error: " . $e->getMessage());
            return [];
        }
        
        return $files;
    }
    
    public function createFile($filename, $content = '') {
        try {
            $filename = basename($filename);
            if (empty($filename)) {
                return false;
            }
            
            $filePath = rtrim($this->currentPath, '/') . '/' . $filename;
            
            if ($this->connectionType === 'sftp') {
                if (!$this->sftpConnection) {
                    error_log("SFTP: Connection not established for createFile");
                    return false;
                }
                $stream = @fopen("ssh2.sftp://{$this->sftpConnection}$filePath", 'w');
                if (!$stream) {
                    error_log("SFTP: Cannot create file: $filePath");
                    return false;
                }
                fwrite($stream, $content);
                fclose($stream);
                return true;
            } else {
                if (!$this->ftpConnection) {
                    error_log("FTP: Connection not established for createFile");
                    return false;
                }
                $tempFile = @tempnam(sys_get_temp_dir(), 'ftp_');
                if (!$tempFile) {
                    error_log("FTP: Cannot create temp file");
                    return false;
                }
                file_put_contents($tempFile, $content);
                $result = @ftp_put($this->ftpConnection, $filePath, $tempFile, FTP_BINARY);
                @unlink($tempFile);
                if (!$result) {
                    error_log("FTP: Cannot upload file: $filePath");
                }
                return $result;
            }
        } catch (Exception $e) {
            error_log("createFile error: " . $e->getMessage());
            return false;
        }
    }
    
    public function createDirectory($dirname) {
        try {
            $dirname = basename($dirname);
            if (empty($dirname)) {
                return false;
            }
            
            $dirPath = rtrim($this->currentPath, '/') . '/' . $dirname;
            
            if ($this->connectionType === 'sftp') {
                if (!$this->sftpConnection) {
                    error_log("SFTP: Connection not established for createDirectory");
                    return false;
                }
                $result = @ssh2_sftp_mkdir($this->sftpConnection, $dirPath, 0755, true);
                if (!$result) {
                    error_log("SFTP: Cannot create directory: $dirPath");
                }
                return $result;
            } else {
                if (!$this->ftpConnection) {
                    error_log("FTP: Connection not established for createDirectory");
                    return false;
                }
                $result = @ftp_mkdir($this->ftpConnection, $dirPath);
                if (!$result) {
                    error_log("FTP: Cannot create directory: $dirPath");
                }
                return $result;
            }
        } catch (Exception $e) {
            error_log("createDirectory error: " . $e->getMessage());
            return false;
        }
    }
    
    public function deleteFile($path) {
        $filePath = $this->basePath . '/' . ltrim($path, '/');
        
        if ($this->connectionType === 'sftp') {
            $stat = ssh2_sftp_stat($this->sftpConnection, $filePath);
            $isDir = ($stat['mode'] & 0040000) === 0040000;
            
            if ($isDir) {
                return $this->deleteDirectorySFTP($filePath);
            } else {
                return ssh2_sftp_unlink($this->sftpConnection, $filePath);
            }
        } else {
            $size = ftp_size($this->ftpConnection, $filePath);
            if ($size === -1) {
                return $this->deleteDirectoryFTP($filePath);
            } else {
                return ftp_delete($this->ftpConnection, $filePath);
            }
        }
    }
    
    private function deleteDirectorySFTP($dir) {
        $handle = opendir("ssh2.sftp://{$this->sftpConnection}$dir");
        if (!$handle) return false;
        
        while (($file = readdir($handle)) !== false) {
            if ($file === '.' || $file === '..') continue;
            $filePath = "$dir/$file";
            $stat = ssh2_sftp_stat($this->sftpConnection, $filePath);
            $isDir = ($stat['mode'] & 0040000) === 0040000;
            
            if ($isDir) {
                $this->deleteDirectorySFTP($filePath);
            } else {
                ssh2_sftp_unlink($this->sftpConnection, $filePath);
            }
        }
        closedir($handle);
        return ssh2_sftp_rmdir($this->sftpConnection, $dir);
    }
    
    private function deleteDirectoryFTP($dir) {
        $items = ftp_nlist($this->ftpConnection, $dir);
        if ($items) {
            foreach ($items as $item) {
                $name = basename($item);
                if ($name === '.' || $name === '..') continue;
                $itemPath = rtrim($dir, '/') . '/' . $name;
                $size = ftp_size($this->ftpConnection, $itemPath);
                if ($size === -1) {
                    $this->deleteDirectoryFTP($itemPath);
                } else {
                    ftp_delete($this->ftpConnection, $itemPath);
                }
            }
        }
        return ftp_rmdir($this->ftpConnection, $dir);
    }
    
    public function uploadFile($localFile, $remotePath = '') {
        $remotePath = $remotePath ?: $this->currentPath . '/' . basename($localFile['name']);
        $remotePath = $this->basePath . '/' . ltrim($remotePath, '/');
        
        if ($this->connectionType === 'sftp') {
            $stream = fopen("ssh2.sftp://{$this->sftpConnection}$remotePath", 'w');
            if (!$stream) return false;
            $localStream = fopen($localFile['tmp_name'], 'r');
            stream_copy_to_stream($localStream, $stream);
            fclose($localStream);
            fclose($stream);
            return true;
        } else {
            return ftp_put($this->ftpConnection, $remotePath, $localFile['tmp_name'], FTP_BINARY);
        }
    }
    
    public function getFileContent($path) {
        $filePath = $this->basePath . '/' . ltrim($path, '/');
        
        if ($this->connectionType === 'sftp') {
            $stream = fopen("ssh2.sftp://{$this->sftpConnection}$filePath", 'r');
            if (!$stream) return false;
            $content = stream_get_contents($stream);
            fclose($stream);
            return $content;
        } else {
            $tempFile = tempnam(sys_get_temp_dir(), 'ftp_');
            if (ftp_get($this->ftpConnection, $tempFile, $filePath, FTP_BINARY)) {
                $content = file_get_contents($tempFile);
                unlink($tempFile);
                return $content;
            }
            return false;
        }
    }
    
    public function saveFileContent($path, $content) {
        $filePath = $this->basePath . '/' . ltrim($path, '/');
        
        if ($this->connectionType === 'sftp') {
            $stream = fopen("ssh2.sftp://{$this->sftpConnection}$filePath", 'w');
            if (!$stream) return false;
            fwrite($stream, $content);
            fclose($stream);
            return true;
        } else {
            $tempFile = tempnam(sys_get_temp_dir(), 'ftp_');
            file_put_contents($tempFile, $content);
            $result = ftp_put($this->ftpConnection, $filePath, $tempFile, FTP_BINARY);
            unlink($tempFile);
            return $result;
        }
    }
    
    public function getDirectorySize($path = null) {
        // Tính toán dung lượng qua SFTP/FTP có thể chậm, nên cache lại
        $targetPath = $path ? $this->basePath . '/' . ltrim($path, '/') : $this->basePath;
        $size = 0;
        
        $files = $this->listFiles($path);
        foreach ($files as $file) {
            if ($file['type'] === 'file') {
                $size += $file['size'];
            } elseif ($file['type'] === 'directory') {
                $size += $this->getDirectorySize($file['path']);
            }
        }
        
        return $size;
    }
    
    public function formatBytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
    
    public function __destruct() {
        if ($this->ftpConnection) {
            ftp_close($this->ftpConnection);
        }
    }
}
