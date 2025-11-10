<?php
/**
 * Hostinger API Client
 * Hỗ trợ kết nối với Hostinger API và SFTP/FTP
 */
class HostingerAPI {
    private $sftpConnection = null;
    
    public function __construct() {
        // Class này chỉ dùng để kết nối SFTP/FTP
        // API methods sẽ được thêm khi Hostinger cung cấp API chính thức
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
    
    // API methods sẽ được thêm khi Hostinger cung cấp API chính thức
}
