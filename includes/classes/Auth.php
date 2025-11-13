<?php
class Auth {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    public function login($username, $password) {
        // Sanitize input
        $username = trim($username);
        
        // Prevent SQL injection with prepared statements (already done)
        $user = $this->db->fetchOne(
            "SELECT * FROM users WHERE (username = ? OR email = ?) AND status = 'active'",
            [$username, $username]
        );
        
        if ($user && password_verify($password, $user['password'])) {
            // Regenerate session ID on login to prevent session fixation
            session_regenerate_id(true);
            
            // Regenerate CSRF token after session regeneration
            $security = Security::getInstance();
            $security->generateCSRFToken();
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['login_time'] = time();
            
            $this->logActivity($user['id'], null, 'login', 'User logged in');
            return true;
        }
        
        return false;
    }
    
    public function logout() {
        if (isset($_SESSION['user_id'])) {
            $this->logActivity($_SESSION['user_id'], null, 'logout', 'User logged out');
        }
        
        session_unset();
        session_destroy();
    }
    
    public function isLoggedIn() {
        return isset($_SESSION['user_id']);
    }
    
    public function isAdmin() {
        return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
    }
    
    public function getUserId() {
        return $_SESSION['user_id'] ?? null;
    }
    
    public function getUserRole() {
        return $_SESSION['role'] ?? null;
    }
    
    public function requireLogin() {
        if (!$this->isLoggedIn()) {
            header('Location: ' . url('login'));
            exit;
        }
    }
    
    public function requireAdmin() {
        $this->requireLogin();
        if (!$this->isAdmin()) {
            header('Location: ' . url(''));
            exit;
        }
    }
    
    public function hasWebsiteAccess($websiteId) {
        if ($this->isAdmin()) {
            return true;
        }
        
        $userId = $this->getUserId();
        $permission = $this->db->fetchOne(
            "SELECT * FROM user_website_permissions WHERE user_id = ? AND website_id = ?",
            [$userId, $websiteId]
        );
        
        return $permission !== false;
    }
    
    public function canManageFiles($websiteId) {
        if ($this->isAdmin()) {
            return true;
        }
        
        $userId = $this->getUserId();
        $permission = $this->db->fetchOne(
            "SELECT can_manage_files FROM user_website_permissions WHERE user_id = ? AND website_id = ?",
            [$userId, $websiteId]
        );
        
        return $permission && $permission['can_manage_files'] == 1;
    }
    
    public function canManageDatabase($websiteId) {
        if ($this->isAdmin()) {
            return true;
        }
        
        $userId = $this->getUserId();
        $permission = $this->db->fetchOne(
            "SELECT can_manage_database FROM user_website_permissions WHERE user_id = ? AND website_id = ?",
            [$userId, $websiteId]
        );
        
        return $permission && $permission['can_manage_database'] == 1;
    }
    
    public function canBackup($websiteId) {
        if ($this->isAdmin()) {
            return true;
        }
        
        $userId = $this->getUserId();
        $permission = $this->db->fetchOne(
            "SELECT can_backup FROM user_website_permissions WHERE user_id = ? AND website_id = ?",
            [$userId, $websiteId]
        );
        
        return $permission && $permission['can_backup'] == 1;
    }
    
    public function changePassword($userId, $currentPassword, $newPassword) {
        // Lấy thông tin user
        $user = $this->db->fetchOne(
            "SELECT * FROM users WHERE id = ? AND status = 'active'",
            [$userId]
        );
        
        if (!$user) {
            return ['success' => false, 'message' => 'Người dùng không tồn tại'];
        }
        
        // Kiểm tra mật khẩu hiện tại
        if (!password_verify($currentPassword, $user['password'])) {
            return ['success' => false, 'message' => 'Mật khẩu hiện tại không đúng'];
        }
        
        // Kiểm tra mật khẩu mới (tối thiểu 6 ký tự)
        if (strlen($newPassword) < 6) {
            return ['success' => false, 'message' => 'Mật khẩu mới phải có ít nhất 6 ký tự'];
        }
        
        // Hash mật khẩu mới
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        
        // Cập nhật mật khẩu
        $this->db->query(
            "UPDATE users SET password = ? WHERE id = ?",
            [$hashedPassword, $userId]
        );
        
        // Ghi log hoạt động
        $this->logActivity($userId, null, 'change_password', 'User changed password');
        
        return ['success' => true, 'message' => 'Đổi mật khẩu thành công'];
    }
    
    public function logActivity($userId, $websiteId, $action, $description = null) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $this->db->query(
            "INSERT INTO activity_logs (user_id, website_id, action, description, ip_address) VALUES (?, ?, ?, ?, ?)",
            [$userId, $websiteId, $action, $description, $ip]
        );
    }
}
