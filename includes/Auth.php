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
        $user = $this->db->fetchOne(
            "SELECT * FROM users WHERE (username = ? OR email = ?) AND status = 'active'",
            [$username, $username]
        );
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['full_name'] = $user['full_name'];
            
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
            header('Location: ' . BASE_URL . 'login.php');
            exit;
        }
    }
    
    public function requireAdmin() {
        $this->requireLogin();
        if (!$this->isAdmin()) {
            header('Location: ' . BASE_URL . 'index.php');
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
    
    public function logActivity($userId, $websiteId, $action, $description = null) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $this->db->query(
            "INSERT INTO activity_logs (user_id, website_id, action, description, ip_address) VALUES (?, ?, ?, ?, ?)",
            [$userId, $websiteId, $action, $description, $ip]
        );
    }
}
