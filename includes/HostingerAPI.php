<?php
/**
 * Hostinger API Client
 * Hỗ trợ kết nối với Hostinger API và SFTP/FTP
 */
class HostingerAPI {
    private $apiUrl;
    private $apiKey;
    private $apiSecret;
    private $sftpConnection = null;
    
    public function __construct($config = null) {
        if ($config === null) {
            $config = require __DIR__ . '/../config/hostinger.php';
        }
        
        $this->apiUrl = $config['api_url'] ?? '';
        $this->apiKey = $config['api_key'] ?? '';
        $this->apiSecret = $config['api_secret'] ?? '';
    }
    
    /**
     * Kết nối SFTP đến Hostinger
     */
    public function connectSFTP($host, $username, $password, $port = 22) {
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
        
        $this->sftpConnection = ssh2_sftp($connection);
        return $this->sftpConnection;
    }
    
    /**
     * Kết nối FTP đến Hostinger
     */
    public function connectFTP($host, $username, $password, $port = 21, $ssl = false) {
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
        
        ftp_pasv($connection, true); // Passive mode
        return $connection;
    }
    
    /**
     * Gọi Hostinger API (nếu có)
     */
    public function apiCall($endpoint, $method = 'GET', $data = []) {
        if (empty($this->apiKey) || empty($this->apiUrl)) {
            throw new Exception("Hostinger API chưa được cấu hình");
        }
        
        $url = rtrim($this->apiUrl, '/') . '/' . ltrim($endpoint, '/');
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
        ]);
        
        if ($method === 'POST' || $method === 'PUT') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 400) {
            throw new Exception("API Error: HTTP $httpCode - $response");
        }
        
        return json_decode($response, true);
    }
    
    /**
     * Lấy danh sách website từ Hostinger API
     */
    public function getWebsites() {
        try {
            return $this->apiCall('/websites');
        } catch (Exception $e) {
            // Nếu API không khả dụng, trả về empty array
            return [];
        }
    }
    
    /**
     * Lấy thông tin website cụ thể
     */
    public function getWebsiteInfo($websiteId) {
        try {
            return $this->apiCall("/websites/$websiteId");
        } catch (Exception $e) {
            return null;
        }
    }
}
