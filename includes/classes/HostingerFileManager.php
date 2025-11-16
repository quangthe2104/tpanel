<?php
/**
 * File Manager sử dụng SFTP/FTP để kết nối với server
 */
class HostingerFileManager {
    private $sftpConnection;
    private $ftpConnection;
    private $connectionType; // 'sftp' or 'ftp'
    private $basePath;
    private $currentPath;
    
    public function __construct($host, $username, $password, $basePath = '/', $connectionType = 'sftp', $port = null) {
        $this->connectionType = $connectionType;
        
        if ($connectionType === 'sftp') {
            $port = $port ?? 22;
            $this->sftpConnection = $this->connectSFTP($host, $username, $password, $port);
        } else {
            $port = $port ?? 21;
            $this->ftpConnection = $this->connectFTP($host, $username, $password, $port);
            
            // Auto-detect working directory for FTP if basePath is empty or root
            if (empty($basePath) || $basePath === '/') {
                $pwd = @ftp_pwd($this->ftpConnection);
                if ($pwd) {
                    $basePath = $pwd;
                }
            }
        }
        
        // Normalize basePath
        $basePath = trim($basePath);
        if (empty($basePath) || $basePath === '/') {
            $this->basePath = '/';
        } else {
            $this->basePath = rtrim($basePath, '/');
        }
        
        $this->currentPath = $this->basePath;
    }
    
    private function connectSFTP($host, $username, $password, $port = 22) {
        if (!extension_loaded('ssh2')) {
            throw new Exception("SSH2 extension chưa được cài đặt. Vui lòng cài đặt php-ssh2 extension.");
        }
        
        // Set timeout for SFTP connection
        $methods = [
            'kex' => 'diffie-hellman-group1-sha1,diffie-hellman-group14-sha1',
            'client_to_server' => [
                'crypt' => 'aes256-ctr,aes192-ctr,aes128-ctr',
                'comp' => 'none'
            ],
            'server_to_client' => [
                'crypt' => 'aes256-ctr,aes192-ctr,aes128-ctr',
                'comp' => 'none'
            ]
        ];
        
        $connection = @ssh2_connect($host, $port, $methods);
        
        if (!$connection) {
            throw new Exception("Không thể kết nối đến SFTP server: $host:$port");
        }
        
        // Set timeout
        @stream_set_timeout($connection, 10);
        
        if (!@ssh2_auth_password($connection, $username, $password)) {
            throw new Exception("Xác thực SFTP thất bại");
        }
        
        return ssh2_sftp($connection);
    }
    
    private function connectFTP($host, $username, $password, $port = 21, $ssl = false) {
        // Check if FTP extension is loaded
        if (!function_exists('ftp_connect')) {
            throw new Exception("PHP FTP extension chưa được cài đặt. Vui lòng bật extension 'ftp' trong php.ini (WAMP: PHP → PHP Extensions → php_ftp)");
        }
        
        // Set timeout for FTP connection
        $timeout = 30; // 30 seconds (tăng từ 10s để tránh timeout khi tạo thư mục)
        
        if ($ssl) {
            if (!function_exists('ftp_ssl_connect')) {
                throw new Exception("PHP FTP SSL extension chưa được cài đặt. Vui lòng bật extension 'ftp' trong php.ini");
            }
            $connection = @ftp_ssl_connect($host, $port, $timeout);
        } else {
            $connection = @ftp_connect($host, $port, $timeout);
        }
        
        if (!$connection) {
            throw new Exception("Không thể kết nối đến FTP server: $host:$port (timeout: {$timeout}s)");
        }
        
        // Set timeout for FTP operations
        @ftp_set_option($connection, FTP_TIMEOUT_SEC, $timeout);
        
        if (!@ftp_login($connection, $username, $password)) {
            @ftp_close($connection);
            throw new Exception("Xác thực FTP thất bại");
        }
        
        @ftp_pasv($connection, true);
        return $connection;
    }
    
    public function setPath($path) {
        if (empty($path)) {
            $this->currentPath = $this->basePath;
            return true;
        }
        
        $path = trim($path);
        
        // If path starts with /, it's absolute
        if ($path[0] === '/') {
            // If basePath is root, use path as is
            if ($this->basePath === '/' || empty($this->basePath)) {
                $newPath = rtrim($path, '/');
                if (empty($newPath)) {
                    $newPath = '/';
                }
            } else {
                // Ensure absolute path is within basePath
                if (strpos($path, $this->basePath) === 0) {
                    $newPath = $path;
                } else {
                    $newPath = $this->basePath;
                }
            }
        } else {
            // Relative path - combine with basePath
            // Special handling for .tpanel/trash - always use absolute path from basePath
            if ($path === '.tpanel/trash' || $path === 'tpanel/trash' || strpos($path, '.tpanel/trash') === 0 || strpos($path, 'tpanel/trash') === 0) {
                if ($this->basePath === '/' || empty($this->basePath)) {
                    $newPath = '/.tpanel/trash';
                } else {
                    $newPath = rtrim($this->basePath, '/') . '/.tpanel/trash';
                }
                // If path has subdirectories, append them
                $pathToCheck = str_replace('tpanel/trash', '.tpanel/trash', $path);
                if ($pathToCheck !== '.tpanel/trash' && strlen($pathToCheck) > 13) {
                    $subPath = substr($pathToCheck, 13); // Remove '.tpanel/trash'
                    $subPath = ltrim($subPath, '/');
                    if (!empty($subPath)) {
                        $newPath = rtrim($newPath, '/') . '/' . $subPath;
                    }
                }
            } else {
                // Relative path - treat as relative to basePath (not currentPath)
                // This is because $file['path'] from listFiles() is already relative to basePath
                // For example: if we're in /wp-admin and click on "css", $file['path'] is "wp-admin/css"
                // (not just "css"), so we should use it directly relative to basePath
                if ($this->basePath === '/' || empty($this->basePath)) {
                    $newPath = '/' . $path;
                } else {
                    $newPath = rtrim($this->basePath, '/') . '/' . $path;
                }
            }
        }
        
        // Normalize path (remove double slashes, etc.)
        $newPath = str_replace(['//', '\\'], '/', $newPath);
        $newPath = rtrim($newPath, '/');
        if (empty($newPath)) {
            $newPath = $this->basePath === '/' ? '/' : $this->basePath;
        }
        
        // Ensure it doesn't go below base path (if basePath is not root)
        if ($this->basePath !== '/' && !empty($this->basePath)) {
            if (strpos($newPath, $this->basePath) !== 0) {
                $newPath = $this->basePath;
            }
        }
        
        // If path is .tpanel/trash, ensure it exists before setting
        if (strpos($newPath, '.tpanel/trash') !== false || strpos($newPath, 'tpanel/trash') !== false) {
            $this->ensureTrashDirectoryExists();
        }
        
        $this->currentPath = $newPath;
        return true;
    }
    
    /**
     * Đảm bảo thư mục .tpanel/trash tồn tại
     */
    private function ensureTrashDirectoryExists() {
        if ($this->connectionType === 'sftp') {
            if (!$this->sftpConnection) {
                return false;
            }
            
            $trashPath = rtrim($this->basePath, '/') . '/.tpanel/trash';
            $tpanelPath = rtrim($this->basePath, '/') . '/.tpanel';
            
            // Check if .tpanel exists, create if not
            $tpanelStat = @ssh2_sftp_stat($this->sftpConnection, $tpanelPath);
            if (!$tpanelStat) {
                @ssh2_sftp_mkdir($this->sftpConnection, $tpanelPath, 0755, true);
            }
            
            // Check if trash exists, create if not
            $trashStat = @ssh2_sftp_stat($this->sftpConnection, $trashPath);
            if (!$trashStat) {
                @ssh2_sftp_mkdir($this->sftpConnection, $trashPath, 0755, true);
            }
        } else {
            // FTP
            if (!$this->ftpConnection) {
                return false;
            }
            
            $originalDir = @ftp_pwd($this->ftpConnection);
            
            // Chuyển về basePath
            if ($this->basePath !== '/' && !empty($this->basePath)) {
                if (!@ftp_chdir($this->ftpConnection, $this->basePath)) {
                    return false;
                }
            } else {
                if (!@ftp_chdir($this->ftpConnection, '/')) {
                    return false;
                }
            }
            
            // Build absolute path giống như putFile()
            $trashRelativePath = '.tpanel/trash';
            $trashAbsolutePath = $this->basePath . '/' . ltrim($trashRelativePath, '/');
            $trashAbsolutePath = str_replace('//', '/', $trashAbsolutePath);
            
            // Dùng createDirectoryRecursive giống như putFile()
            $this->createDirectoryRecursive($trashAbsolutePath);
            
            // Restore original directory
            if ($originalDir) {
                @ftp_chdir($this->ftpConnection, $originalDir);
            }
        }
        
        return true;
    }
    
    public function getCurrentPath() {
        return $this->currentPath;
    }
    
    public function getBasePath() {
        return $this->basePath;
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
                if (empty($path)) {
                    $targetPath = $this->basePath;
                } else {
                    // If path is absolute, use it directly (if basePath is root)
                    if ($path[0] === '/' && ($this->basePath === '/' || empty($this->basePath))) {
                        $targetPath = $path;
                    } else {
                        $targetPath = $this->basePath;
                        if ($this->basePath !== '/' && !empty($this->basePath)) {
                            $targetPath .= '/' . ltrim($path, '/');
                        } else {
                            $targetPath = ltrim($path, '/');
                        }
                    }
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
                    return [];
                }
                $files = $this->listFilesSFTP($targetPath);
            } else {
                if (!$this->ftpConnection) {
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
                    return [];
                }
                // If stat exists but cannot open, try again with base path
                if ($path !== $this->basePath) {
                    $handle = @opendir("ssh2.sftp://{$this->sftpConnection}" . $this->basePath);
                    $path = $this->basePath;
                }
                if (!$handle) {
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
            return [];
        }
        
        return $files;
    }
    
    private function listFilesFTP($path) {
        $files = [];
        
        try {
            // Get current working directory
            $currentDir = @ftp_pwd($this->ftpConnection);
            if (!$currentDir) {
                return [];
            }
            
            // Normalize path
            $path = trim($path);
            if (empty($path) || $path === '/') {
                // Use basePath, or current directory if basePath is root
                if ($this->basePath === '/' || empty($this->basePath)) {
                    $path = $currentDir;
                } else {
                    $path = $this->basePath;
                }
            } else {
                // If path is relative, combine with basePath
                if ($path[0] !== '/') {
                    if ($this->basePath === '/' || empty($this->basePath)) {
                        $path = $currentDir . '/' . $path;
                    } else {
                        $path = $this->basePath . '/' . $path;
                    }
                }
                // If path is absolute but basePath is not root, ensure it's within basePath
                elseif ($this->basePath !== '/' && !empty($this->basePath)) {
                    if (strpos($path, $this->basePath) !== 0) {
                        return [];
                    }
                }
            }
            
            // Normalize path
            $path = rtrim($path, '/');
            if (empty($path)) {
                $path = $currentDir;
            }
            
            // Try to change to directory first to verify it exists
            if (!@ftp_chdir($this->ftpConnection, $path)) {
                // Special handling for .tpanel/trash - ensure it exists and try to access it
                if (strpos($path, '.tpanel/trash') !== false || strpos($path, 'tpanel/trash') !== false) {
                    // Ensure trash directory exists first (this will create it if needed)
                    $this->ensureTrashDirectoryExists();
                    
                    // Try to access .tpanel/trash by navigating step by step
                    $basePathForNav = ($this->basePath === '/' || empty($this->basePath)) ? '/' : $this->basePath;
                    
                    if (@ftp_chdir($this->ftpConnection, $basePathForNav)) {
                        if (@ftp_chdir($this->ftpConnection, '.tpanel')) {
                            if (@ftp_chdir($this->ftpConnection, 'trash')) {
                                $actualPath = @ftp_pwd($this->ftpConnection);
                                $path = $actualPath ?: $path;
                            } else {
                                @ftp_chdir($this->ftpConnection, $currentDir);
                                return [];
                            }
                        } else {
                            @ftp_chdir($this->ftpConnection, $currentDir);
                            return [];
                        }
                    } else {
                        @ftp_chdir($this->ftpConnection, $currentDir);
                        return [];
                    }
                } else {
                    // If path doesn't work, try current directory
                    if ($path !== $currentDir) {
                        if (@ftp_chdir($this->ftpConnection, $currentDir)) {
                            $path = $currentDir;
                        } else {
                            return [];
                        }
                    } else {
                        return [];
                    }
                }
            }
            
            // Use rawlist for more reliable directory listing (especially for hidden directories)
            // For .tpanel directories, we need to use rawlist as nlist may not show hidden files
            // Always use rawlist first, especially for root directory to show .tpanel
            $items = @ftp_rawlist($this->ftpConnection, $path);
            
            if (!$items || !is_array($items)) {
                // Fallback to nlist
                $items = @ftp_nlist($this->ftpConnection, $path);
                if (!$items) {
                    // For .tpanel directories or root directory, try to chdir into it first, then list
                    if (strpos($path, '.tpanel') !== false || $path === '/' || $path === $this->basePath) {
                        $originalPwd = @ftp_pwd($this->ftpConnection);
                        if (@ftp_chdir($this->ftpConnection, $path)) {
                            // When listing current directory, use rawlist with '.' to get all files including hidden
                            $items = @ftp_rawlist($this->ftpConnection, '.');
                            @ftp_chdir($this->ftpConnection, $originalPwd);
                            if (!$items) {
                                @ftp_chdir($this->ftpConnection, $currentDir);
                                return [];
                            }
                        } else {
                            @ftp_chdir($this->ftpConnection, $currentDir);
                            return [];
                        }
                    } else {
                        @ftp_chdir($this->ftpConnection, $currentDir);
                        return [];
                    }
                    } else {
                        // If nlist worked but we're in root/basePath, also try rawlist to get hidden files
                        // nlist may not return hidden files like .tpanel
                        if ($path === '/' || $path === $this->basePath || empty($path)) {
                            $rawItems = @ftp_rawlist($this->ftpConnection, $path);
                            if ($rawItems && is_array($rawItems)) {
                                $items = $rawItems;
                            }
                        }
                    }
            }
            
            $parsedFromRawlist = false;
            foreach ($items as $item) {
                $name = null;
                $isDir = false;
                $size = 0;
                $modified = time();
                $parsedFromRawlist = false;
                
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
                    $parsedFromRawlist = true;
                } 
                // Try alternative format
                elseif (preg_match('/^([-d])([rwx-]+)\s+\d+\s+\d+\s+\d+\s+(\d+)\s+(\w+\s+\d+[\s:]\d+)\s+(.+)$/', $item, $matches)) {
                    $isDir = ($matches[1] === 'd');
                    $name = trim($matches[6]);
                    $size = $isDir ? 0 : (int)$matches[3];
                    $modified = @strtotime($matches[4]) ?: time();
                    $parsedFromRawlist = true;
                }
                // Fallback: treat as filename from nlist or simple path
                else {
                    $name = basename($item);
                    if (empty($name)) {
                        $name = trim($item);
                    }
                    // Remove path prefix if present
                    $name = str_replace($path . '/', '', $name);
                    $name = ltrim($name, '/');
                }
                
                // Skip . and ..
                if (empty($name) || $name === '.' || $name === '..') {
                    continue;
                }
                
                // Build full path
                $filePath = rtrim($path, '/') . '/' . $name;
                
                // Verify directory/file status if not determined from rawlist
                if (!$parsedFromRawlist) {
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
                // Special handling for .tpanel/trash and .tpanel/backups: keep the full path from basePath
                if (strpos($path, '.tpanel/trash') !== false) {
                    // For .tpanel/trash, relative path should be .tpanel/trash/filename
                    $relativePath = str_replace($this->basePath, '', $filePath);
                    $relativePath = ltrim($relativePath, '/');
                    // If basePath is root and path is /.tpanel/trash, ensure relative path starts with .tpanel/trash
                    if (($this->basePath === '/' || empty($this->basePath)) && strpos($path, '/.tpanel/trash') === 0) {
                        $relativePath = '.tpanel/trash/' . $name;
                        // If we're in a subdirectory of trash, preserve that
                        $subPath = str_replace('/.tpanel/trash', '', $path);
                        if ($subPath && $subPath !== $path) {
                            $relativePath = '.tpanel/trash' . $subPath . '/' . $name;
                        }
                    }
                } elseif (strpos($path, '.tpanel/backups') !== false || strpos($path, '.tpanel/storage') !== false) {
                    // For .tpanel/backups and .tpanel/storage, relative path should be .tpanel/backups/filename
                    // Skip parent folders (.tpanel) when we're already inside .tpanel/backups
                    if ($name === '.tpanel' && strpos($path, '.tpanel/') === 0) {
                        // Skip parent .tpanel folder when inside .tpanel subdirectories
                        continue;
                    }
                    
                    $relativePath = str_replace($this->basePath, '', $filePath);
                    $relativePath = ltrim($relativePath, '/');
                    
                    // Ensure relative path starts with .tpanel/backups or .tpanel/storage
                    if (strpos($path, '/.tpanel/backups') === 0 || strpos($path, '.tpanel/backups') === 0) {
                        if (strpos($relativePath, '.tpanel/backups') !== 0) {
                            $relativePath = '.tpanel/backups/' . $name;
                        }
                    } elseif (strpos($path, '/.tpanel/storage') === 0 || strpos($path, '.tpanel/storage') === 0) {
                        if (strpos($relativePath, '.tpanel/storage') !== 0) {
                            $relativePath = '.tpanel/storage/' . $name;
                        }
                    }
                } else {
                    // Calculate relative path from basePath (not from current directory)
                    // This ensures that when clicking on a file/folder, the path is always relative to basePath
                    // filePath is absolute (e.g., /wp-admin/css), basePath is absolute (e.g., /)
                    // So relativePath should be wp-admin/css
                    
                    // Only remove basePath from the beginning, not all occurrences
                    if ($this->basePath === '/' || empty($this->basePath)) {
                        // If basePath is root, just remove leading slash
                        $relativePath = ltrim($filePath, '/');
                    } else {
                        // If basePath is not root, remove it from the beginning
                        if (strpos($filePath, $this->basePath) === 0) {
                            $relativePath = substr($filePath, strlen($this->basePath));
                            $relativePath = ltrim($relativePath, '/');
                        } else {
                            // Fallback: construct from path and name
                            $pathRelative = str_replace($this->basePath, '', $path);
                            $pathRelative = ltrim($pathRelative, '/');
                            if ($pathRelative) {
                                $relativePath = $pathRelative . '/' . $name;
                            } else {
                                $relativePath = $name;
                            }
                        }
                    }
                    
                    // Ensure we have proper slashes (no double slashes, but single slashes between parts)
                    $relativePath = str_replace('//', '/', $relativePath);
                }
                
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
                    return false;
                }
                $stream = @fopen("ssh2.sftp://{$this->sftpConnection}$filePath", 'w');
                if (!$stream) {
                    return false;
                }
                fwrite($stream, $content);
                fclose($stream);
                return true;
            } else {
                if (!$this->ftpConnection) {
                    return false;
                }
                $tempFile = @tempnam(sys_get_temp_dir(), 'ftp_');
                if (!$tempFile) {
                    return false;
                }
                file_put_contents($tempFile, $content);
                $result = @ftp_put($this->ftpConnection, $filePath, $tempFile, FTP_BINARY);
                @unlink($tempFile);
                return $result;
            }
        } catch (Exception $e) {
            return false;
        }
    }
    
    public function createDirectory($dirname) {
        try {
            $dirname = basename($dirname);
            if (empty($dirname)) {
                return false;
            }
            
            // Build directory path
            $currentPath = $this->currentPath;
            if ($currentPath === '/' || empty($currentPath)) {
                // For FTP, use current working directory
                if ($this->connectionType === 'ftp' && $this->ftpConnection) {
                    $pwd = @ftp_pwd($this->ftpConnection);
                    if ($pwd) {
                        $dirPath = rtrim($pwd, '/') . '/' . $dirname;
                    } else {
                        $dirPath = '/' . $dirname;
                    }
                } else {
                    $dirPath = '/' . $dirname;
                }
            } else {
                $dirPath = rtrim($currentPath, '/') . '/' . $dirname;
            }
            
            if ($this->connectionType === 'sftp') {
                if (!$this->sftpConnection) {
                    return false;
                }
                return @ssh2_sftp_mkdir($this->sftpConnection, $dirPath, 0755, true);
            } else {
                if (!$this->ftpConnection) {
                    return false;
                }
                
                // Try to change to parent directory first
                $parentDir = dirname($dirPath);
                $originalDir = @ftp_pwd($this->ftpConnection);
                
                if ($parentDir !== '.' && $parentDir !== '/') {
                    @ftp_chdir($this->ftpConnection, $parentDir);
                }
                
                $dirname = basename($dirPath);
                $result = @ftp_mkdir($this->ftpConnection, $dirname);
                
                // Restore original directory
                if ($originalDir) {
                    @ftp_chdir($this->ftpConnection, $originalDir);
                }
                
                return $result;
            }
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Di chuyển file/thư mục vào thùng rác thay vì xóa vĩnh viễn
     */
    public function moveToTrash($path) {
        try {
            // Đảm bảo thư mục thùng rác tồn tại trước khi thực hiện bất kỳ thao tác nào
            if (!$this->ensureTrashDirectoryExists()) {
                return false;
            }
            
            // Normalize path: đảm bảo dùng forward slash
            $path = str_replace('\\', '/', $path);
            $path = ltrim($path, '/');
            
            $filePath = rtrim($this->basePath, '/') . '/' . $path;
            $filePath = str_replace('\\', '/', $filePath);
            $trashPath = $this->getTrashPath();
            
            // Tạo tên file trong thùng rác với timestamp để tránh trùng
            $originalName = basename($path);
            $timestamp = date('Y-m-d_H-i-s');
            $trashName = $timestamp . '_' . $originalName;
            
            // Nếu file có đường dẫn con, giữ lại cấu trúc thư mục
            $relativePath = dirname($path);
            if ($relativePath && $relativePath !== '.' && $relativePath !== '/' && $relativePath !== '\\') {
                $relativePath = str_replace('\\', '/', $relativePath); // Normalize
                $trashName = str_replace('/', '_', ltrim($relativePath, '/')) . '_' . $trashName;
            }
            
            $trashFilePath = rtrim($trashPath, '/') . '/' . $trashName;
            
            // Di chuyển file vào thùng rác
            if ($this->connectionType === 'sftp') {
                if (!$this->sftpConnection) {
                    return false;
                }
                
                // Kiểm tra file hay thư mục
                $stat = @ssh2_sftp_stat($this->sftpConnection, $filePath);
                if (!$stat) {
                    return false;
                }
                
                $isDir = ($stat['mode'] & 0040000) === 0040000;
                
                if ($isDir) {
                    // Di chuyển thư mục: tạo thư mục mới và copy nội dung
                    return $this->moveDirectoryToTrashSFTP($filePath, $trashFilePath);
                } else {
                    // Di chuyển file: rename
                    return @ssh2_sftp_rename($this->sftpConnection, $filePath, $trashFilePath);
                }
            } else {
                if (!$this->ftpConnection) {
                    return false;
                }
                
                // Với FTP, làm việc với đường dẫn tương đối từ basePath
                $originalDir = @ftp_pwd($this->ftpConnection);
                
                // Chuyển đến basePath
                if ($this->basePath !== '/' && !empty($this->basePath)) {
                    if (!@ftp_chdir($this->ftpConnection, $this->basePath)) {
                        return false;
                    }
                }
                
                // Đường dẫn tương đối từ basePath
                $relativeFilePath = $path;
                $fileName = basename($relativeFilePath);
                $fileDir = dirname($relativeFilePath);
                
                // Chuyển đến thư mục chứa file (nếu không phải root)
                if ($fileDir !== '.' && $fileDir !== '/' && $fileDir !== '\\') {
                    $fileDir = str_replace('\\', '/', $fileDir);
                    if (!@ftp_chdir($this->ftpConnection, $fileDir)) {
                        if ($originalDir) {
                            @ftp_chdir($this->ftpConnection, $originalDir);
                        }
                        return false;
                    }
                }
                
                // Kiểm tra file hay thư mục (từ thư mục hiện tại)
                $size = @ftp_size($this->ftpConnection, $fileName);
                $isDir = ($size === -1);
                
                // Tính toán đường dẫn thùng rác tương đối
                $trashDirName = '.tpanel/trash';
                $relativeTrashPath = $trashDirName . '/' . $trashName;
                
                if ($isDir) {
                    // Di chuyển thư mục: tạo thư mục mới và copy nội dung
                    // Chuyển về basePath trước
                    if ($this->basePath !== '/' && !empty($this->basePath)) {
                        @ftp_chdir($this->ftpConnection, $this->basePath);
                    } else {
                        @ftp_chdir($this->ftpConnection, '/');
                    }
                    return $this->moveDirectoryToTrashFTP($relativeFilePath, $relativeTrashPath);
                } else {
                    // Di chuyển file: đơn giản hóa logic
                    // 1. Đảm bảo thư mục .tpanel/trash tồn tại
                    // 2. Chuyển đến thư mục chứa file
                    // 3. Rename với đường dẫn tương đối đến .tpanel/trash
                    
                    // Chuyển về basePath trước
                    if ($this->basePath !== '/' && !empty($this->basePath)) {
                        if (!@ftp_chdir($this->ftpConnection, $this->basePath)) {
                            if ($originalDir) {
                                @ftp_chdir($this->ftpConnection, $originalDir);
                            }
                            return false;
                        }
                    } else {
                        if (!@ftp_chdir($this->ftpConnection, '/')) {
                            if ($originalDir) {
                                @ftp_chdir($this->ftpConnection, $originalDir);
                            }
                            return false;
                        }
                    }
                    
                    // Tạo .tpanel nếu chưa có
                    $tpanelSize = @ftp_size($this->ftpConnection, '.tpanel');
                    if ($tpanelSize === false) {
                        if (!@ftp_mkdir($this->ftpConnection, '.tpanel')) {
                            if ($originalDir) {
                                @ftp_chdir($this->ftpConnection, $originalDir);
                            }
                            return false;
                        }
                    } else if ($tpanelSize !== -1) {
                        if ($originalDir) {
                            @ftp_chdir($this->ftpConnection, $originalDir);
                        }
                        return false;
                    }
                    
                    // Tạo .tpanel/trash nếu chưa có
                    if (!@ftp_chdir($this->ftpConnection, '.tpanel')) {
                        if ($originalDir) {
                            @ftp_chdir($this->ftpConnection, $originalDir);
                        }
                        return false;
                    }
                    
                    $trashSize = @ftp_size($this->ftpConnection, 'trash');
                    if ($trashSize === false) {
                        if (!@ftp_mkdir($this->ftpConnection, 'trash')) {
                            if ($originalDir) {
                                @ftp_chdir($this->ftpConnection, $originalDir);
                            }
                            return false;
                        }
                    } else if ($trashSize !== -1) {
                        if ($originalDir) {
                            @ftp_chdir($this->ftpConnection, $originalDir);
                        }
                        return false;
                    }
                    
                    // Quay lại basePath
                    if ($this->basePath !== '/' && !empty($this->basePath)) {
                        @ftp_chdir($this->ftpConnection, $this->basePath);
                    } else {
                        @ftp_chdir($this->ftpConnection, '/');
                    }
                    
                    // Chuyển đến thư mục chứa file
                    if ($fileDir !== '.' && $fileDir !== '/' && $fileDir !== '\\' && !empty($fileDir)) {
                        if (!@ftp_chdir($this->ftpConnection, $fileDir)) {
                            if ($originalDir) {
                                @ftp_chdir($this->ftpConnection, $originalDir);
                            }
                            return false;
                        }
                    }
                    
                    // Tính số level cần lên để đến basePath, sau đó vào .tpanel/trash
                    $levelsUp = 0;
                    if ($fileDir !== '.' && $fileDir !== '/' && $fileDir !== '\\' && !empty($fileDir)) {
                        $levelsUp = substr_count($fileDir, '/') + 1;
                    }
                    
                    // Đường dẫn tương đối đến thùng rác
                    $relativeTrashPath = str_repeat('../', $levelsUp) . '.tpanel/trash/' . $trashName;
                    
                    // Rename với đường dẫn tương đối
                    $result = @ftp_rename($this->ftpConnection, $fileName, $relativeTrashPath);
                    
                    // Restore original directory
                    if ($originalDir) {
                        @ftp_chdir($this->ftpConnection, $originalDir);
                    }
                    
                    return $result;
                }
            }
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Lấy đường dẫn thùng rác
     */
    private function getTrashPath() {
        return rtrim($this->basePath, '/') . '/.tpanel/trash';
    }
    
    /**
     * Kiểm tra thư mục có tồn tại không
     */
    private function directoryExists($path) {
        if ($this->connectionType === 'sftp') {
            if (!$this->sftpConnection) return false;
            $stat = @ssh2_sftp_stat($this->sftpConnection, $path);
            return $stat && (($stat['mode'] & 0040000) === 0040000);
        } else {
            if (!$this->ftpConnection) return false;
            
            // Với FTP, cần kiểm tra từ thư mục hiện tại
            // Nếu path là đường dẫn tuyệt đối, cần chuyển đến thư mục chứa nó
            $originalDir = @ftp_pwd($this->ftpConnection);
            $dirName = basename($path);
            $parentDir = dirname($path);
            
            // Chuyển đến thư mục cha
            if ($parentDir !== '.' && $parentDir !== '/' && $parentDir !== $this->basePath) {
                if (!@ftp_chdir($this->ftpConnection, $parentDir)) {
                    // Không thể chuyển đến thư mục cha, thử kiểm tra từ basePath
                    if ($this->basePath !== '/' && !empty($this->basePath)) {
                        @ftp_chdir($this->ftpConnection, $this->basePath);
                    }
                    // Nếu path là .tpanel/trash, kiểm tra từ thư mục hiện tại
                    if ($dirName === '.tpanel' || $dirName === 'tpanel') {
                        // Kiểm tra .tpanel/trash
                        @ftp_chdir($this->ftpConnection, '.tpanel');
                        $trashSize = @ftp_size($this->ftpConnection, 'trash');
                        @ftp_chdir($this->ftpConnection, $originalDir);
                        return $trashSize === -1;
                    }
                    if ($dirName === 'trash' && basename(dirname($path)) === '.tpanel') {
                        $size = @ftp_size($this->ftpConnection, $dirName);
                        @ftp_chdir($this->ftpConnection, $originalDir);
                        return $size === -1;
                    }
                }
            }
            
            $size = @ftp_size($this->ftpConnection, $dirName);
            @ftp_chdir($this->ftpConnection, $originalDir);
            return $size === -1; // Directory returns -1
        }
    }
    
    /**
     * Di chuyển thư mục vào thùng rác (SFTP)
     */
    private function moveDirectoryToTrashSFTP($sourceDir, $destDir) {
        // Tạo thư mục đích
        if (!@ssh2_sftp_mkdir($this->sftpConnection, $destDir, 0755, true)) {
            return false;
        }
        
        // Copy nội dung
        $handle = @opendir("ssh2.sftp://{$this->sftpConnection}$sourceDir");
        if (!$handle) {
            return false;
        }
        
        $success = true;
        while (($file = readdir($handle)) !== false) {
            if ($file === '.' || $file === '..') continue;
            
            $sourcePath = "$sourceDir/$file";
            $destPath = "$destDir/$file";
            
            $stat = @ssh2_sftp_stat($this->sftpConnection, $sourcePath);
            if (!$stat) continue;
            
            $isDir = ($stat['mode'] & 0040000) === 0040000;
            
            if ($isDir) {
                if (!$this->moveDirectoryToTrashSFTP($sourcePath, $destPath)) {
                    $success = false;
                }
            } else {
                if (!@ssh2_sftp_rename($this->sftpConnection, $sourcePath, $destPath)) {
                    $success = false;
                }
            }
        }
        closedir($handle);
        
        // Xóa thư mục nguồn nếu copy thành công
        if ($success) {
            @ssh2_sftp_rmdir($this->sftpConnection, $sourceDir);
        }
        
        return $success;
    }
    
    /**
     * Di chuyển thư mục vào thùng rác (FTP)
     */
    private function moveDirectoryToTrashFTP($sourceDir, $destDir) {
        // $destDir có thể là đường dẫn tương đối như ".tpanel/trash/2025-11-11_15-59-23_it"
        // Cần tách thành thư mục cha và tên thư mục
        $originalDir = @ftp_pwd($this->ftpConnection);
        $trashDirName = '.tpanel/trash';
        $destDirName = basename($destDir);
        
        // Đảm bảo đang ở basePath trước (hoặc root nếu basePath là /)
        $targetBasePath = ($this->basePath === '/' || empty($this->basePath)) ? '/' : $this->basePath;
        
        // Chuyển đến basePath
        if (!@ftp_chdir($this->ftpConnection, $targetBasePath)) {
            @ftp_chdir($this->ftpConnection, '/');
        }
        
        // Kiểm tra và tạo thư mục .tpanel nếu chưa có
        $tpanelSize = @ftp_size($this->ftpConnection, '.tpanel');
        if ($tpanelSize !== -1) {
            if (!@ftp_mkdir($this->ftpConnection, '.tpanel')) {
                @ftp_chdir($this->ftpConnection, $originalDir);
                return false;
            }
        }
        
        // Chuyển vào .tpanel và tạo thư mục trash nếu chưa có
        @ftp_chdir($this->ftpConnection, '.tpanel');
        $trashSize = @ftp_size($this->ftpConnection, 'trash');
        if ($trashSize !== -1) {
            if (!@ftp_mkdir($this->ftpConnection, 'trash')) {
                @ftp_chdir($this->ftpConnection, $originalDir);
                return false;
            }
        }
        @ftp_chdir($this->ftpConnection, $targetBasePath);
        
        // Tạo thư mục đích trong thùng rác: .tpanel/trash/destDirName
        // Chuyển vào .tpanel/trash và tạo thư mục destDirName
        @ftp_chdir($this->ftpConnection, '.tpanel');
        @ftp_chdir($this->ftpConnection, 'trash');
        
        // Kiểm tra và tạo thư mục destDirName nếu chưa có
        $destSize = @ftp_size($this->ftpConnection, $destDirName);
        if ($destSize !== -1) {
            if (!@ftp_mkdir($this->ftpConnection, $destDirName)) {
                @ftp_chdir($this->ftpConnection, $originalDir);
                return false;
            }
        }
        
        // Quay lại basePath
        @ftp_chdir($this->ftpConnection, $targetBasePath);
        
        // Chuyển đến thư mục nguồn
        if ($this->basePath !== '/' && !empty($this->basePath)) {
            @ftp_chdir($this->ftpConnection, $this->basePath);
        }
        
        // Copy nội dung từ thư mục nguồn
        // Đảm bảo đang ở thư mục chứa sourceDir
        $sourceParentDir = dirname($sourceDir);
        if ($sourceParentDir !== '.' && $sourceParentDir !== '/' && !empty($sourceParentDir)) {
            if ($this->basePath !== '/' && !empty($this->basePath)) {
                $fullSourceParent = rtrim($this->basePath, '/') . '/' . $sourceParentDir;
            } else {
                $fullSourceParent = '/' . $sourceParentDir;
            }
            if (!@ftp_chdir($this->ftpConnection, $fullSourceParent)) {
                @ftp_chdir($this->ftpConnection, $originalDir);
                return false;
            }
        } else {
            // Source ở root
            if ($this->basePath !== '/' && !empty($this->basePath)) {
                @ftp_chdir($this->ftpConnection, $this->basePath);
            } else {
                @ftp_chdir($this->ftpConnection, '/');
            }
        }
        
        $sourceName = basename($sourceDir);
        $items = @ftp_nlist($this->ftpConnection, $sourceName);
        if (!$items) {
            @ftp_chdir($this->ftpConnection, $originalDir);
            return false;
        }
        
        $success = true;
        foreach ($items as $item) {
            $name = basename($item);
            if ($name === '.' || $name === '..') continue;
            
            $sourcePath = $sourceName . '/' . $name;
            $destPath = $trashDirName . '/' . $destDirName . '/' . $name;
            
            $size = @ftp_size($this->ftpConnection, $sourcePath);
            $isDir = ($size === -1);
            
            if ($isDir) {
                // Đệ quy cho thư mục con
                if (!$this->moveDirectoryToTrashFTP($sourcePath, $destPath)) {
                    $success = false;
                }
            } else {
                // Di chuyển file: dùng đường dẫn tuyệt đối
                // Chuyển về basePath trước
                if ($this->basePath !== '/' && !empty($this->basePath)) {
                    @ftp_chdir($this->ftpConnection, $this->basePath);
                } else {
                    @ftp_chdir($this->ftpConnection, '/');
                }
                
                // Di chuyển file: chuyển đến thư mục chứa file, sau đó rename
                // sourcePath là tương đối từ basePath, cần chuyển đến thư mục chứa nó
                $fileParentDir = dirname($sourcePath);
                if ($fileParentDir !== '.' && $fileParentDir !== '/' && !empty($fileParentDir)) {
                    if ($this->basePath !== '/' && !empty($this->basePath)) {
                        $fullFileParent = rtrim($this->basePath, '/') . '/' . $fileParentDir;
                    } else {
                        $fullFileParent = '/' . $fileParentDir;
                    }
                    if (!@ftp_chdir($this->ftpConnection, $fullFileParent)) {
                        $success = false;
                        continue;
                    }
                } else {
                    // File ở root
                    if ($this->basePath !== '/' && !empty($this->basePath)) {
                        @ftp_chdir($this->ftpConnection, $this->basePath);
                    } else {
                        @ftp_chdir($this->ftpConnection, '/');
                    }
                }
                
                $fileName = basename($sourcePath);
                // destPath là .tpanel/trash/destDirName/name, tính từ basePath
                // Nếu đang ở thư mục con, cần dùng ../ để lên về basePath
                $levelsUp = substr_count($fileParentDir, '/');
                if ($levelsUp > 0) {
                    $relativeDestPath = str_repeat('../', $levelsUp) . $destPath;
                } else {
                    $relativeDestPath = $destPath;
                }
                
                // Rename file vào thùng rác (dùng đường dẫn tương đối)
                if (!@ftp_rename($this->ftpConnection, $fileName, $relativeDestPath)) {
                    $success = false;
                }
                
                // Quay lại basePath
                if ($this->basePath !== '/' && !empty($this->basePath)) {
                    @ftp_chdir($this->ftpConnection, $this->basePath);
                } else {
                    @ftp_chdir($this->ftpConnection, '/');
                }
            }
        }
        
        // Xóa thư mục nguồn nếu copy thành công
        if ($success) {
            // Chuyển về basePath
            if ($this->basePath !== '/' && !empty($this->basePath)) {
                @ftp_chdir($this->ftpConnection, $this->basePath);
            } else {
                @ftp_chdir($this->ftpConnection, '/');
            }
            
            // Tính đường dẫn tuyệt đối cho sourceDir
            if ($this->basePath !== '/' && !empty($this->basePath)) {
                $absoluteSourceDir = rtrim($this->basePath, '/') . '/' . $sourceDir;
            } else {
                $absoluteSourceDir = '/' . ltrim($sourceDir, '/');
            }
            $absoluteSourceDir = str_replace('//', '/', $absoluteSourceDir);
            
            @ftp_rmdir($this->ftpConnection, $absoluteSourceDir);
        }
        
        // Restore original directory
        @ftp_chdir($this->ftpConnection, $originalDir);
        
        return $success;
    }
    
    /**
     * Xóa file vĩnh viễn (giữ lại method cũ để dùng khi cần)
     */
    public function deleteFile($path) {
        // Normalize path
        $path = ltrim($path, '/');
        
        // Build full path
        if (empty($this->basePath) || $this->basePath === '/') {
            $filePath = '/' . $path;
        } else {
            $filePath = rtrim($this->basePath, '/') . '/' . $path;
        }
        
        if ($this->connectionType === 'sftp') {
            if (!$this->sftpConnection) {
                return false;
            }
            
            $stat = @ssh2_sftp_stat($this->sftpConnection, $filePath);
            if (!$stat) {
                return false;
            }
            
            $isDir = ($stat['mode'] & 0040000) === 0040000;
            
            if ($isDir) {
                return $this->deleteDirectorySFTP($filePath);
            } else {
                return @ssh2_sftp_unlink($this->sftpConnection, $filePath);
            }
        } else {
            if (!$this->ftpConnection) {
                return false;
            }
            
            $originalDir = @ftp_pwd($this->ftpConnection);
            $result = false;
            
            // Strategy 1: Try absolute path
            $size = @ftp_size($this->ftpConnection, $filePath);
            if ($size !== -1) {
                // It's a file
                $result = @ftp_delete($this->ftpConnection, $filePath);
            } else {
                // Might be directory or file not found, check if it's a directory
                $items = @ftp_nlist($this->ftpConnection, $filePath);
                if ($items !== false) {
                    // It's a directory
                    $result = $this->deleteDirectoryFTP($filePath);
                } else {
                    // Try relative path
                    if ($this->basePath !== '/' && !empty($this->basePath)) {
                        if (@ftp_chdir($this->ftpConnection, $this->basePath)) {
                            $relativePath = ltrim(str_replace($this->basePath, '', $filePath), '/');
                            if (!empty($relativePath)) {
                                $size = @ftp_size($this->ftpConnection, $relativePath);
                                if ($size !== -1) {
                                    $result = @ftp_delete($this->ftpConnection, $relativePath);
                                } else {
                                    // Try with just the path (not full path)
                                    $result = @ftp_delete($this->ftpConnection, $path);
                                }
                            }
                        }
                    } else {
                        // Try with just the path
                        $result = @ftp_delete($this->ftpConnection, $path);
                    }
                }
            }
            
            // Strategy 2: chdir vào thư mục chứa file, rồi delete với relative filename
            if (!$result) {
                $dirPath = dirname($filePath);
                $filename = basename($filePath);
                
                if ($this->basePath !== '/' && !empty($this->basePath)) {
                    if (@ftp_chdir($this->ftpConnection, $this->basePath)) {
                        $relativeDirPath = ltrim(str_replace($this->basePath, '', $dirPath), '/');
                        if (!empty($relativeDirPath) && $relativeDirPath !== '.') {
                            if (@ftp_chdir($this->ftpConnection, $relativeDirPath)) {
                                $result = @ftp_delete($this->ftpConnection, $filename);
                            }
                        } else {
                            $result = @ftp_delete($this->ftpConnection, $filename);
                        }
                    }
                } else {
                    if (!empty($dirPath) && $dirPath !== '/') {
                        if (@ftp_chdir($this->ftpConnection, $dirPath)) {
                            $result = @ftp_delete($this->ftpConnection, $filename);
                        }
                    } else {
                        $result = @ftp_delete($this->ftpConnection, $filename);
                    }
                }
            }
            
            // Strategy 3: Nếu path đã là relative (như .tpanel/backups/file.zip), thử chdir vào basePath rồi dùng path đó
            if (!$result && strpos($path, '/') !== false) {
                if ($this->basePath !== '/' && !empty($this->basePath)) {
                    if (@ftp_chdir($this->ftpConnection, $this->basePath)) {
                        $size = @ftp_size($this->ftpConnection, $path);
                        if ($size !== -1) {
                            $result = @ftp_delete($this->ftpConnection, $path);
                        }
                    }
                } else {
                    $size = @ftp_size($this->ftpConnection, $path);
                    if ($size !== -1) {
                        $result = @ftp_delete($this->ftpConnection, $path);
                    }
                }
            }
            
            // Restore original directory
            if ($originalDir) {
                @ftp_chdir($this->ftpConnection, $originalDir);
            }
            
            @ftp_delete($this->ftpConnection, $filePath);
            
            return $result;
        }
    }
    
    /**
     * Kiểm tra file có tồn tại không
     */
    public function fileExists($path) {
        // Normalize path
        $path = ltrim($path, '/');
        
        // Build full path
        if (empty($this->basePath) || $this->basePath === '/') {
            $filePath = '/' . $path;
        } else {
            $filePath = rtrim($this->basePath, '/') . '/' . $path;
        }
        
        if ($this->connectionType === 'sftp') {
            if (!$this->sftpConnection) {
                return false;
            }
            $stat = @ssh2_sftp_stat($this->sftpConnection, $filePath);
            return $stat !== false;
        } else {
            if (!$this->ftpConnection) {
                return false;
            }
            
            $originalDir = @ftp_pwd($this->ftpConnection);
            $exists = false;
            
            // Strategy 1: Try absolute path
            $size = @ftp_size($this->ftpConnection, $filePath);
            if ($size !== -1) {
                $exists = true;
            } else {
                // Strategy 2: Try relative path from basePath
                if ($this->basePath !== '/' && !empty($this->basePath)) {
                    if (@ftp_chdir($this->ftpConnection, $this->basePath)) {
                        $relativePath = ltrim(str_replace($this->basePath, '', $filePath), '/');
                        if (!empty($relativePath)) {
                            $size = @ftp_size($this->ftpConnection, $relativePath);
                            if ($size !== -1) {
                                $exists = true;
                            } else {
                                // Try with just the path
                                $size = @ftp_size($this->ftpConnection, $path);
                                if ($size !== -1) {
                                    $exists = true;
                                }
                            }
                        } else {
                            $size = @ftp_size($this->ftpConnection, $path);
                            if ($size !== -1) {
                                $exists = true;
                            }
                        }
                    }
                } else {
                    $size = @ftp_size($this->ftpConnection, $path);
                    if ($size !== -1) {
                        $exists = true;
                    }
                }
                
                // Strategy 3: chdir vào thư mục chứa file, rồi check với relative filename
                if (!$exists) {
                    $dirPath = dirname($filePath);
                    $filename = basename($filePath);
                    
                    if ($this->basePath !== '/' && !empty($this->basePath)) {
                        if (@ftp_chdir($this->ftpConnection, $this->basePath)) {
                            $relativeDirPath = ltrim(str_replace($this->basePath, '', $dirPath), '/');
                            if (!empty($relativeDirPath) && $relativeDirPath !== '.') {
                                if (@ftp_chdir($this->ftpConnection, $relativeDirPath)) {
                                    $size = @ftp_size($this->ftpConnection, $filename);
                                    if ($size !== -1) {
                                        $exists = true;
                                    }
                                }
                            } else {
                                $size = @ftp_size($this->ftpConnection, $filename);
                                if ($size !== -1) {
                                    $exists = true;
                                }
                            }
                        }
                    } else {
                        if (!empty($dirPath) && $dirPath !== '/') {
                            if (@ftp_chdir($this->ftpConnection, $dirPath)) {
                                $size = @ftp_size($this->ftpConnection, $filename);
                                if ($size !== -1) {
                                    $exists = true;
                                }
                            }
                        } else {
                            $size = @ftp_size($this->ftpConnection, $filename);
                            if ($size !== -1) {
                                $exists = true;
                            }
                        }
                    }
                }
            }
            
            // Restore original directory
            if ($originalDir) {
                @ftp_chdir($this->ftpConnection, $originalDir);
            }
            
            return $exists;
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
    
    /**
     * Stream file trực tiếp đến output (không load vào memory)
     * @param string $path Đường dẫn file
     * @param resource $outputStream Stream để ghi (mặc định là php://output)
     * @return bool|int Số bytes đã stream hoặc false nếu lỗi
     */
    public function streamFile($path, $outputStream = null) {
        // Normalize path: remove leading slash and combine with basePath
        $path = ltrim($path, '/');
        
        // Build full path
        if (empty($this->basePath) || $this->basePath === '/') {
            $filePath = '/' . $path;
        } else {
            $filePath = rtrim($this->basePath, '/') . '/' . $path;
        }
        
        if ($outputStream === null) {
            $outputStream = fopen('php://output', 'wb');
            // Đảm bảo không buffer
            if (function_exists('stream_set_write_buffer')) {
                stream_set_write_buffer($outputStream, 0);
            }
        }
        
        if ($this->connectionType === 'sftp') {
            if (!$this->sftpConnection) {
                return false;
            }
            $stream = @fopen("ssh2.sftp://{$this->sftpConnection}$filePath", 'rb');
            if (!$stream) {
                return false;
            }
            
            // Stream từ SFTP đến output với chunk lớn hơn
            $bytesStreamed = 0;
            $chunkCount = 0;
            while (!feof($stream)) {
                $chunk = fread($stream, 65536); // Tăng lên 64KB mỗi lần để nhanh hơn
                if ($chunk === false || strlen($chunk) === 0) {
                    break;
                }
                $written = fwrite($outputStream, $chunk);
                if ($written === false) {
                    break;
                }
                $bytesStreamed += $written;
                $chunkCount++;
                
                // Flush ngay cho chunk đầu tiên, sau đó flush sau mỗi 4 chunks (256KB)
                if ($chunkCount === 1 || $chunkCount % 4 === 0) {
                    if (ob_get_level() > 0) {
                        @ob_flush();
                    }
                    flush();
                }
            }
            // Flush lần cuối
            if (ob_get_level() > 0) {
                @ob_flush();
            }
            flush();
            fclose($stream);
            if ($outputStream && $outputStream !== STDOUT) {
                fclose($outputStream);
            }
            return $bytesStreamed;
        } else {
            if (!$this->ftpConnection) {
                return false;
            }
            
            $tempFile = tempnam(sys_get_temp_dir(), 'ftp_');
            $originalDir = @ftp_pwd($this->ftpConnection);
            
            // Try multiple strategies similar to putFile()
            $result = false;
            
            // Strategy 1: Try absolute path
            $result = @ftp_get($this->ftpConnection, $tempFile, $filePath, FTP_BINARY);
            
            // Strategy 2: chdir vào thư mục chứa file, rồi download với relative filename
            if (!$result) {
                $dirPath = dirname($filePath);
                $filename = basename($filePath);
                
                // Chdir vào basePath trước
                if ($this->basePath !== '/' && !empty($this->basePath)) {
                    if (@ftp_chdir($this->ftpConnection, $this->basePath)) {
                        // Nếu filePath đã là relative từ basePath, thử chdir vào dirPath relative
                        $relativeDirPath = ltrim(str_replace($this->basePath, '', $dirPath), '/');
                        if (!empty($relativeDirPath) && $relativeDirPath !== '.') {
                            if (@ftp_chdir($this->ftpConnection, $relativeDirPath)) {
                                $result = @ftp_get($this->ftpConnection, $tempFile, $filename, FTP_BINARY);
                            }
                        } else {
                            // File ở ngay basePath
                            $result = @ftp_get($this->ftpConnection, $tempFile, $filename, FTP_BINARY);
                        }
                    }
                } else {
                    // basePath là root, thử chdir vào dirPath
                    if (!empty($dirPath) && $dirPath !== '/') {
                        if (@ftp_chdir($this->ftpConnection, $dirPath)) {
                            $result = @ftp_get($this->ftpConnection, $tempFile, $filename, FTP_BINARY);
                        }
                    } else {
                        // File ở root, thử download với filename
                        $result = @ftp_get($this->ftpConnection, $tempFile, $filename, FTP_BINARY);
                    }
                }
            }
            
            // Strategy 3: Nếu path đã là relative (như .tpanel/backups/file.zip), thử chdir vào basePath rồi dùng path đó
            if (!$result && strpos($path, '/') !== false) {
                if ($this->basePath !== '/' && !empty($this->basePath)) {
                    if (@ftp_chdir($this->ftpConnection, $this->basePath)) {
                        $result = @ftp_get($this->ftpConnection, $tempFile, $path, FTP_BINARY);
                    }
                } else {
                    $result = @ftp_get($this->ftpConnection, $tempFile, $path, FTP_BINARY);
                }
            }
            
            // Strategy 4: Thử với path gốc (không normalize)
            if (!$result) {
                $originalPath = $path;
                if (!empty($originalPath)) {
                    $result = @ftp_get($this->ftpConnection, $tempFile, $originalPath, FTP_BINARY);
                }
            }
            
            // Restore original directory
            if ($originalDir) {
                @ftp_chdir($this->ftpConnection, $originalDir);
            }
            
            if ($result) {
                // Stream từ temp file đến output với chunk lớn hơn
                $inputStream = fopen($tempFile, 'rb');
                if ($inputStream) {
                    $bytesStreamed = 0;
                    $chunkCount = 0;
                    while (!feof($inputStream)) {
                        $chunk = fread($inputStream, 65536); // Tăng lên 64KB mỗi lần để nhanh hơn
                        if ($chunk === false || strlen($chunk) === 0) {
                            break;
                        }
                        $written = fwrite($outputStream, $chunk);
                        if ($written === false) {
                            break;
                        }
                        $bytesStreamed += $written;
                        $chunkCount++;
                        
                        // Flush ngay cho chunk đầu tiên để browser nhận dữ liệu ngay
                        // Sau đó flush sau mỗi 4 chunks (256KB) để tối ưu
                        if ($chunkCount === 1 || $chunkCount % 4 === 0) {
                            if (ob_get_level() > 0) {
                                @ob_flush();
                            }
                            flush();
                        }
                    }
                    // Flush lần cuối để đảm bảo tất cả data được gửi
                    if (ob_get_level() > 0) {
                        @ob_flush();
                    }
                    flush();
                    fclose($inputStream);
                }
                @unlink($tempFile);
                if ($outputStream && $outputStream !== STDOUT) {
                    fclose($outputStream);
                }
                return $bytesStreamed;
            } else {
                @unlink($tempFile);
                return false;
            }
        }
    }
    
    /**
     * Lấy kích thước file (không cần download)
     * @param string $path Đường dẫn file
     * @return int|false Kích thước file hoặc false nếu lỗi
     */
    public function getFileSize($path) {
        // Normalize path: remove leading slash and combine with basePath
        $path = ltrim($path, '/');
        
        // Build full path
        if (empty($this->basePath) || $this->basePath === '/') {
            $filePath = '/' . $path;
        } else {
            $filePath = rtrim($this->basePath, '/') . '/' . $path;
        }
        
        if ($this->connectionType === 'sftp') {
            if (!$this->sftpConnection) {
                return false;
            }
            $stat = @ssh2_sftp_stat($this->sftpConnection, $filePath);
            if (!$stat) {
                return false;
            }
            return $stat['size'] ?? false;
        } else {
            if (!$this->ftpConnection) {
                return false;
            }
            
            // Try multiple strategies
            $size = @ftp_size($this->ftpConnection, $filePath);
            if ($size !== -1) {
                return $size;
            }
            
            // Try relative path
            $originalDir = @ftp_pwd($this->ftpConnection);
            if ($this->basePath !== '/' && !empty($this->basePath)) {
                if (@ftp_chdir($this->ftpConnection, $this->basePath)) {
                    $size = @ftp_size($this->ftpConnection, $path);
                    @ftp_chdir($this->ftpConnection, $originalDir);
                    if ($size !== -1) {
                        return $size;
                    }
                }
            }
            
            return false;
        }
    }
    
    public function getFileContent($path) {
        // Normalize path: remove leading slash and combine with basePath
        $path = ltrim($path, '/');
        
        // Build full path
        if (empty($this->basePath) || $this->basePath === '/') {
            $filePath = '/' . $path;
        } else {
            $filePath = rtrim($this->basePath, '/') . '/' . $path;
        }
        
        if ($this->connectionType === 'sftp') {
            if (!$this->sftpConnection) {
                return false;
            }
            $stream = @fopen("ssh2.sftp://{$this->sftpConnection}$filePath", 'r');
            if (!$stream) {
                return false;
            }
            $content = stream_get_contents($stream);
            fclose($stream);
            return $content;
        } else {
            if (!$this->ftpConnection) {
                return false;
            }
            
            $tempFile = tempnam(sys_get_temp_dir(), 'ftp_');
            $originalDir = @ftp_pwd($this->ftpConnection);
            
            // Try multiple strategies similar to putFile()
            $result = false;
            
            // Strategy 1: Try absolute path
            $result = @ftp_get($this->ftpConnection, $tempFile, $filePath, FTP_BINARY);
            
            // Strategy 2: chdir vào thư mục chứa file, rồi download với relative filename
            if (!$result) {
                $dirPath = dirname($filePath);
                $filename = basename($filePath);
                
                // Chdir vào basePath trước
                if ($this->basePath !== '/' && !empty($this->basePath)) {
                    if (@ftp_chdir($this->ftpConnection, $this->basePath)) {
                        // Nếu filePath đã là relative từ basePath, thử chdir vào dirPath relative
                        $relativeDirPath = ltrim(str_replace($this->basePath, '', $dirPath), '/');
                        if (!empty($relativeDirPath) && $relativeDirPath !== '.') {
                            if (@ftp_chdir($this->ftpConnection, $relativeDirPath)) {
                                $result = @ftp_get($this->ftpConnection, $tempFile, $filename, FTP_BINARY);
                            }
                        } else {
                            // File ở ngay basePath
                            $result = @ftp_get($this->ftpConnection, $tempFile, $filename, FTP_BINARY);
                        }
                    }
                } else {
                    // basePath là root, thử chdir vào dirPath
                    if (!empty($dirPath) && $dirPath !== '/') {
                        if (@ftp_chdir($this->ftpConnection, $dirPath)) {
                            $result = @ftp_get($this->ftpConnection, $tempFile, $filename, FTP_BINARY);
                        }
                    } else {
                        // File ở root, thử download với filename
                        $result = @ftp_get($this->ftpConnection, $tempFile, $filename, FTP_BINARY);
                    }
                }
            }
            
            // Strategy 3: Nếu path đã là relative (như .tpanel/backups/file.zip), thử chdir vào basePath rồi dùng path đó
            if (!$result && strpos($path, '/') !== false) {
                if ($this->basePath !== '/' && !empty($this->basePath)) {
                    if (@ftp_chdir($this->ftpConnection, $this->basePath)) {
                        $result = @ftp_get($this->ftpConnection, $tempFile, $path, FTP_BINARY);
                    }
                } else {
                    $result = @ftp_get($this->ftpConnection, $tempFile, $path, FTP_BINARY);
                }
            }
            
            // Strategy 4: Thử với path gốc (không normalize)
            if (!$result) {
                $originalPath = $path;
                if (!empty($originalPath)) {
                    $result = @ftp_get($this->ftpConnection, $tempFile, $originalPath, FTP_BINARY);
                }
            }
            
            // Restore original directory
            if ($originalDir) {
                @ftp_chdir($this->ftpConnection, $originalDir);
            }
            
            if ($result) {
                $content = file_get_contents($tempFile);
                unlink($tempFile);
                return $content;
            } else {
                @unlink($tempFile);
                return false;
            }
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
    
    /**
     * Upload file từ đường dẫn local lên server
     * @param string $localFilePath Đường dẫn file local
     * @param string $remotePath Đường dẫn trên server (tương đối với basePath)
     * @return bool
     */
    public function putFile($localFilePath, $remotePath = '') {
        if (!file_exists($localFilePath)) {
            throw new Exception("Local file not found: $localFilePath");
        }
        
        if (empty($remotePath)) {
            $remotePath = basename($localFilePath);
        }
        
        // Build file path and normalize to avoid double slashes
        $filePath = $this->basePath . '/' . ltrim($remotePath, '/');
        $filePath = str_replace('//', '/', $filePath);
        
        try {
            if ($this->connectionType === 'sftp') {
                if (!$this->sftpConnection) {
                    throw new Exception("SFTP connection not established");
                }
                
                // Tạo thư mục cha nếu chưa có
                $dirPath = dirname($filePath);
                if ($dirPath !== $this->basePath && $dirPath !== '/') {
                    $this->createDirectoryRecursive($dirPath);
                }
                
                $stream = @fopen("ssh2.sftp://{$this->sftpConnection}$filePath", 'w');
                if (!$stream) {
                    throw new Exception("Cannot open remote file for writing: $filePath");
                }
                
                $localStream = @fopen($localFilePath, 'r');
                if (!$localStream) {
                    fclose($stream);
                    throw new Exception("Cannot open local file for reading: $localFilePath");
                }
                
                stream_copy_to_stream($localStream, $stream);
                fclose($localStream);
                fclose($stream);
                return true;
            } else {
                if (!$this->ftpConnection) {
                    throw new Exception("FTP connection not established");
                }
                
                // Tạo thư mục cha nếu chưa có
                $dirPath = dirname($filePath);
                if ($dirPath !== $this->basePath && $dirPath !== '/') {
                    $this->createDirectoryRecursive($dirPath);
                }
                
                // Với FTP, cần chdir vào thư mục đích trước khi put
                // ftp_put() với absolute path có thể không hoạt động trên một số server
                $originalDir = @ftp_pwd($this->ftpConnection);
                $fileName = basename($filePath);
                
                // Chdir vào thư mục đích - nếu fail, thử tạo lại folder
                if (!@ftp_chdir($this->ftpConnection, $dirPath)) {
                    // Thư mục có thể chưa tồn tại, thử tạo lại
                    $this->createDirectoryRecursive($dirPath);
                    // Thử chdir lại
                    if (!@ftp_chdir($this->ftpConnection, $dirPath)) {
                        // Nếu vẫn fail, thử với absolute path
                        $result = @ftp_put($this->ftpConnection, $filePath, $localFilePath, FTP_BINARY);
                        if ($originalDir) {
                            @ftp_chdir($this->ftpConnection, $originalDir);
                        }
                    } else {
                        // Upload với tên file (relative path từ current directory)
                        $result = @ftp_put($this->ftpConnection, $fileName, $localFilePath, FTP_BINARY);
                        // Restore original directory
                        if ($originalDir) {
                            @ftp_chdir($this->ftpConnection, $originalDir);
                        }
                    }
                } else {
                    // Upload với tên file (relative path từ current directory)
                    $result = @ftp_put($this->ftpConnection, $fileName, $localFilePath, FTP_BINARY);
                    // Restore original directory
                    if ($originalDir) {
                        @ftp_chdir($this->ftpConnection, $originalDir);
                    }
                }
                
                if (!$result) {
                    throw new Exception("Cannot upload file to FTP: $filePath");
                }
                return true;
            }
        } catch (Exception $e) {
            throw $e;
        }
    }
    
    /**
     * Tạo thư mục đệ quy
     */
    private function createDirectoryRecursive($dirPath) {
        if ($this->connectionType === 'sftp') {
            // ssh2_sftp_mkdir có thể tạo thư mục đệ quy với flag recursive
            @ssh2_sftp_mkdir($this->sftpConnection, $dirPath, 0755, true);
        } else {
            // FTP: tạo từng thư mục một, kiểm tra tồn tại trước khi tạo
            $normalizedPath = trim($dirPath, '/');
            if (empty($normalizedPath)) {
                return;
            }
            
            $parts = explode('/', $normalizedPath);
            $currentPath = $this->basePath;
            
            // Ensure basePath doesn't end with / (except for root)
            if ($currentPath !== '/' && !empty($currentPath)) {
                $currentPath = rtrim($currentPath, '/');
            }
            
            // Chdir về basePath trước
            if ($currentPath === '/' || empty($currentPath)) {
                @ftp_chdir($this->ftpConnection, '/');
            } else {
                @ftp_chdir($this->ftpConnection, $currentPath);
            }
            
            foreach ($parts as $part) {
                if (empty($part)) continue;
                
                // Build path correctly
                if ($currentPath === '/' || empty($currentPath)) {
                    $currentPath = '/' . $part;
                } else {
                    $currentPath = $currentPath . '/' . $part;
                }
                
                // Kiểm tra xem thư mục đã tồn tại chưa bằng cách thử chdir
                if (!@ftp_chdir($this->ftpConnection, $currentPath)) {
                    // Thư mục chưa tồn tại, tạo mới
                    // Thử với absolute path trước
                    if (!@ftp_mkdir($this->ftpConnection, $currentPath)) {
                        // Nếu fail, thử với relative path từ current directory
                        $parentPath = dirname($currentPath);
                        if ($parentPath !== $currentPath) {
                            @ftp_chdir($this->ftpConnection, $parentPath);
                            @ftp_mkdir($this->ftpConnection, $part);
                            @ftp_chdir($this->ftpConnection, $currentPath);
                        }
                    } else {
                        // Tạo thành công, chdir vào
                        @ftp_chdir($this->ftpConnection, $currentPath);
                    }
                }
            }
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
