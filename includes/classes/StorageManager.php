<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/HostingerFileManager.php';

/**
 * Storage Manager để quản lý dung lượng website
 */
class StorageManager {
    private $db;
    private $websiteId;
    private $website;
    private $fileManager;
    
    public function __construct($websiteId) {
        $this->db = Database::getInstance();
        $this->websiteId = $websiteId;
        
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
    
    /**
     * Cập nhật dung lượng từ server (sử dụng script trên server)
     */
    public function updateStorageFromServer() {
        try {
            // Upload script test trước (đơn giản hơn)
            $testScriptPath = __DIR__ . '/server_test_storage.php';
            $remoteTestPath = '.tpanel/storage/test_storage.php';
            
            if (file_exists($testScriptPath)) {
                try {
                    $this->fileManager->putFile($testScriptPath, $remoteTestPath);
                    error_log("StorageManager: Test script uploaded");
                } catch (Exception $e) {
                    error_log("StorageManager: Test script upload failed: " . $e->getMessage());
                }
            }
            
            // Upload script chính lên server
            $scriptPath = __DIR__ . '/../../scripts/server_get_storage_size.php';
            if (!file_exists($scriptPath)) {
                throw new Exception("Script file not found: $scriptPath");
            }
            
            // Thử upload vào thư mục ẩn trước
            $remoteScriptPath = '.tpanel/storage/get_storage_size.php';
            $remoteTestPath = '.tpanel/storage/test_storage.php';
            
            // Log để debug
            error_log("StorageManager: Uploading script from $scriptPath to $remoteScriptPath");
            
            // Upload file (putFile sẽ tự động tạo thư mục cha nếu cần)
            try {
                $this->fileManager->putFile($scriptPath, $remoteScriptPath);
                error_log("StorageManager: Script uploaded successfully");
            } catch (Exception $uploadError) {
                error_log("StorageManager: Upload failed: " . $uploadError->getMessage());
                throw new Exception("Cannot upload script to server: " . $uploadError->getMessage());
            }
            
            // Đợi một chút để đảm bảo file đã được ghi xong
            sleep(2);
            
            // Trigger script trên server
            $domain = $this->website['domain'] ?? '';
            if (empty($domain)) {
                throw new Exception("Website domain not configured");
            }
            
            // Thử nhiều URL khác nhau
            $urls = [
                (strpos($domain, 'http') === 0 ? $domain : 'http://' . $domain) . '/.tpanel/storage/get_storage_size.php',
                (strpos($domain, 'http') === 0 ? $domain : 'http://' . $domain) . '/get_storage_size.php'
            ];
            
            $url = $urls[0]; // Dùng URL đầu tiên
            error_log("StorageManager: Calling script at URL: $url");
            
            // Gọi script và đợi response (timeout 30s)
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Tpanel Storage Manager');
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            // Log for debugging
            error_log("StorageManager: URL=$url, HTTP_CODE=$httpCode, Response length=" . strlen($response ?? ''));
            
            if ($curlError) {
                error_log("StorageManager CURL Error: $curlError");
                throw new Exception("CURL Error: $curlError");
            }
            
            if ($httpCode !== 200) {
                error_log("StorageManager: HTTP Code $httpCode, Response: " . substr($response ?? '', 0, 500));
                throw new Exception("Failed to get storage size from server (HTTP $httpCode). URL: $url");
            }
            
            if (!$response) {
                throw new Exception("Empty response from server. URL: $url");
            }
            
            $result = json_decode($response, true);
            if (!$result) {
                error_log("StorageManager: Invalid JSON response: " . substr($response, 0, 500));
                throw new Exception("Invalid JSON response from server: " . substr($response, 0, 200));
            }
            
            // Kiểm tra success flag hoặc error
            if (isset($result['success']) && $result['success'] === false) {
                throw new Exception("Server error: " . ($result['error'] ?? 'Unknown error'));
            }
            
            if (isset($result['error']) && (!isset($result['success']) || $result['success'] !== true)) {
                throw new Exception("Server error: " . $result['error']);
            }
            
            // Cập nhật database
            $usedStorage = $result['size'] ?? 0;
            if ($usedStorage === 0 && !isset($result['size'])) {
                error_log("StorageManager: Warning - size field not found in response");
            }
            $this->db->query(
                "UPDATE websites SET used_storage = ?, storage_updated_at = NOW() WHERE id = ?",
                [$usedStorage, $this->websiteId]
            );
            
            return $usedStorage;
        } catch (Exception $e) {
            error_log("StorageManager updateStorageFromServer error: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Lấy dung lượng từ database (nhanh)
     */
    public function getStorageFromDB() {
        $website = $this->db->fetchOne("SELECT used_storage, total_storage, storage_updated_at FROM websites WHERE id = ?", [$this->websiteId]);
        return [
            'used' => $website['used_storage'] ?? null,
            'total' => $website['total_storage'] ?? null,
            'updated_at' => $website['storage_updated_at'] ?? null
        ];
    }
    
    /**
     * Cập nhật total_storage (do admin nhập)
     */
    public function updateTotalStorage($totalStorage) {
        $this->db->query(
            "UPDATE websites SET total_storage = ? WHERE id = ?",
            [$totalStorage, $this->websiteId]
        );
    }
}

