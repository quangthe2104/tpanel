<?php
require_once __DIR__ . '/HostingerAPI.php';

/**
 * File Manager sử dụng SFTP/FTP để kết nối với Hostinger
 */
class HostingerFileManager {
    private $sftpConnection;
    private $ftpConnection;
    private $connectionType; // 'sftp' or 'ftp'
    private $basePath;
    private $currentPath;
    private $hostingerAPI;
    
    public function __construct($host, $username, $password, $basePath = '/', $connectionType = 'sftp', $port = null) {
        $this->hostingerAPI = new HostingerAPI();
        $this->basePath = rtrim($basePath, '/');
        $this->currentPath = $this->basePath;
        $this->connectionType = $connectionType;
        
        if ($connectionType === 'sftp') {
            $port = $port ?? 22;
            $this->sftpConnection = $this->hostingerAPI->connectSFTP($host, $username, $password, $port);
        } else {
            $port = $port ?? 21;
            $this->ftpConnection = $this->hostingerAPI->connectFTP($host, $username, $password, $port);
        }
    }
    
    public function setPath($path) {
        $newPath = $this->basePath . '/' . ltrim($path, '/');
        $this->currentPath = $newPath;
        return true;
    }
    
    public function getCurrentPath() {
        return $this->currentPath;
    }
    
    public function getRelativePath() {
        return str_replace($this->basePath, '', $this->currentPath);
    }
    
    public function listFiles($path = null) {
        $targetPath = $path ? $this->basePath . '/' . ltrim($path, '/') : $this->currentPath;
        $files = [];
        
        if ($this->connectionType === 'sftp') {
            $files = $this->listFilesSFTP($targetPath);
        } else {
            $files = $this->listFilesFTP($targetPath);
        }
        
        usort($files, function($a, $b) {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'directory' ? -1 : 1;
            }
            return strcmp($a['name'], $b['name']);
        });
        
        return $files;
    }
    
    private function listFilesSFTP($path) {
        $files = [];
        $handle = opendir("ssh2.sftp://{$this->sftpConnection}$path");
        
        if (!$handle) {
            return [];
        }
        
        while (($file = readdir($handle)) !== false) {
            if ($file === '.' || $file === '..') continue;
            
            $filePath = "$path/$file";
            $stat = ssh2_sftp_stat($this->sftpConnection, $filePath);
            
            $isDir = ($stat['mode'] & 0040000) === 0040000;
            
            $files[] = [
                'name' => $file,
                'path' => str_replace($this->basePath, '', $filePath),
                'type' => $isDir ? 'directory' : 'file',
                'size' => $isDir ? 0 : ($stat['size'] ?? 0),
                'modified' => $stat['mtime'] ?? time(),
                'permissions' => substr(sprintf('%o', $stat['mode']), -4)
            ];
        }
        
        closedir($handle);
        return $files;
    }
    
    private function listFilesFTP($path) {
        $files = [];
        $items = ftp_nlist($this->ftpConnection, $path);
        
        if (!$items) {
            return [];
        }
        
        foreach ($items as $item) {
            $name = basename($item);
            if ($name === '.' || $name === '..') continue;
            
            $filePath = rtrim($path, '/') . '/' . $name;
            $size = ftp_size($this->ftpConnection, $filePath);
            $isDir = $size === -1;
            $modified = ftp_mdtm($this->ftpConnection, $filePath);
            
            $files[] = [
                'name' => $name,
                'path' => str_replace($this->basePath, '', $filePath),
                'type' => $isDir ? 'directory' : 'file',
                'size' => $isDir ? 0 : $size,
                'modified' => $modified ? $modified : time(),
                'permissions' => '0644'
            ];
        }
        
        return $files;
    }
    
    public function createFile($filename, $content = '') {
        $filePath = $this->currentPath . '/' . basename($filename);
        
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
    
    public function createDirectory($dirname) {
        $dirPath = $this->currentPath . '/' . basename($dirname);
        
        if ($this->connectionType === 'sftp') {
            return ssh2_sftp_mkdir($this->sftpConnection, $dirPath, 0755, true);
        } else {
            return ftp_mkdir($this->ftpConnection, $dirPath);
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
