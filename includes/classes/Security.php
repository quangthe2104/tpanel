<?php
/**
 * Security class for CSRF protection, rate limiting, and input validation
 */
class Security {
    private static $instance = null;
    private $db;
    
    private function __construct() {
        $this->db = Database::getInstance();
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Generate CSRF token
     */
    public function generateCSRFToken() {
        // Ensure session is started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    
    /**
     * Verify CSRF token
     */
    public function verifyCSRFToken($token) {
        // Ensure session is started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (!isset($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }
    
    /**
     * Get CSRF token field for forms
     */
    public function getCSRFField() {
        $token = $this->generateCSRFToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }
    
    /**
     * Check if request has valid CSRF token
     */
    public function checkCSRF() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $token = $_POST['csrf_token'] ?? '';
            if (empty($token)) {
                http_response_code(403);
                die('CSRF token is missing. Please refresh the page and try again.');
            }
            if (!$this->verifyCSRFToken($token)) {
                // Regenerate token for next request
                $this->generateCSRFToken();
                http_response_code(403);
                die('CSRF token validation failed. Please refresh the page and try again.');
            }
        }
    }
    
    /**
     * Rate limiting for login attempts
     */
    public function checkRateLimit($identifier, $maxAttempts = 5, $timeWindow = 900) { // 15 minutes
        $ip = $this->getClientIP();
        $key = 'rate_limit_' . md5($identifier . $ip);
        
        // Get current attempts
        $attempts = $_SESSION[$key] ?? ['count' => 0, 'first_attempt' => time()];
        
        // Reset if time window has passed
        if (time() - $attempts['first_attempt'] > $timeWindow) {
            $attempts = ['count' => 0, 'first_attempt' => time()];
        }
        
        // Check if limit exceeded
        if ($attempts['count'] >= $maxAttempts) {
            $remaining = $timeWindow - (time() - $attempts['first_attempt']);
            return [
                'allowed' => false,
                'remaining' => $remaining,
                'message' => "Quá nhiều lần thử. Vui lòng thử lại sau " . ceil($remaining / 60) . " phút."
            ];
        }
        
        // Increment attempts
        $attempts['count']++;
        $_SESSION[$key] = $attempts;
        
        return ['allowed' => true, 'remaining' => $maxAttempts - $attempts['count']];
    }
    
    /**
     * Reset rate limit for successful action
     */
    public function resetRateLimit($identifier) {
        $ip = $this->getClientIP();
        $key = 'rate_limit_' . md5($identifier . $ip);
        unset($_SESSION[$key]);
    }
    
    /**
     * Get client IP address
     */
    public function getClientIP() {
        $ipKeys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                // Handle comma-separated IPs (from proxies)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    /**
     * Sanitize string input
     */
    public function sanitizeString($input, $maxLength = null) {
        if (!is_string($input)) {
            return '';
        }
        
        $input = trim($input);
        $input = strip_tags($input);
        
        if ($maxLength !== null && strlen($input) > $maxLength) {
            $input = substr($input, 0, $maxLength);
        }
        
        return $input;
    }
    
    /**
     * Sanitize filename
     */
    public function sanitizeFilename($filename) {
        // Handle null or non-string input
        if ($filename === null || !is_string($filename)) {
            return '';
        }
        
        // Remove path traversal attempts
        $filename = basename($filename);
        
        // Remove dangerous characters
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
        
        // Prevent hidden files
        if (substr($filename, 0, 1) === '.') {
            $filename = 'file_' . $filename;
        }
        
        return $filename;
    }
    
    /**
     * Sanitize path (prevent path traversal)
     */
    public function sanitizePath($path) {
        // Handle null or non-string input
        if ($path === null || !is_string($path)) {
            return '';
        }
        
        // Remove null bytes
        $path = str_replace("\0", '', $path);
        
        // Normalize slashes
        $path = str_replace('\\', '/', $path);
        
        // Remove path traversal attempts
        $path = preg_replace('#\.\./#', '', $path);
        $path = preg_replace('#\.\.\\\\#', '', $path);
        
        // Remove leading/trailing slashes
        $path = trim($path, '/');
        
        return $path;
    }
    
    /**
     * Validate email
     */
    public function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * Validate URL
     */
    public function validateURL($url) {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
    
    /**
     * Validate integer
     */
    public function validateInt($value, $min = null, $max = null) {
        $int = filter_var($value, FILTER_VALIDATE_INT);
        if ($int === false) {
            return false;
        }
        if ($min !== null && $int < $min) {
            return false;
        }
        if ($max !== null && $int > $max) {
            return false;
        }
        return $int;
    }
    
    /**
     * Validate file upload
     */
    public function validateFileUpload($file, $allowedTypes = [], $maxSize = 10485760) { // 10MB default
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['valid' => false, 'error' => 'File không hợp lệ'];
        }
        
        // Check file size
        if ($file['size'] > $maxSize) {
            $maxSizeMB = round($maxSize / (1024 * 1024), 2);
            return ['valid' => false, 'error' => "File quá lớn. Kích thước tối đa: {$maxSizeMB} MB"];
        }
        
        // Check file type if specified
        if (!empty($allowedTypes)) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedTypes)) {
                return ['valid' => false, 'error' => 'Loại file không được phép. Chỉ chấp nhận: ' . implode(', ', $allowedTypes)];
            }
        }
        
        // Check for dangerous file types
        $dangerousExtensions = ['php', 'php3', 'php4', 'php5', 'phtml', 'exe', 'sh', 'bat', 'cmd', 'com', 'scr', 'vbs', 'js'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $dangerousExtensions)) {
            return ['valid' => false, 'error' => 'Loại file này không được phép upload vì lý do bảo mật'];
        }
        
        return ['valid' => true];
    }
    
    /**
     * Log security event
     */
    public function logSecurityEvent($event, $details = null) {
        $ip = $this->getClientIP();
        $userId = $_SESSION['user_id'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        
        // Log to database if available
        try {
            $this->db->query(
                "INSERT INTO security_logs (user_id, event, details, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, NOW())",
                [$userId, $event, $details, $ip, $userAgent]
            );
        } catch (Exception $e) {
            // Fallback to error log if table doesn't exist
            error_log("Security Event: $event - IP: $ip - Details: " . ($details ?? 'N/A'));
        }
    }
    
    /**
     * Check if user is blocked
     */
    public function isBlocked($identifier) {
        $ip = $this->getClientIP();
        $key = 'blocked_' . md5($identifier . $ip);
        return isset($_SESSION[$key]) && $_SESSION[$key] > time();
    }
    
    /**
     * Block user temporarily
     */
    public function blockUser($identifier, $duration = 3600) { // 1 hour default
        $ip = $this->getClientIP();
        $key = 'blocked_' . md5($identifier . $ip);
        $_SESSION[$key] = time() + $duration;
        $this->logSecurityEvent('user_blocked', "Identifier: $identifier, IP: $ip, Duration: $duration");
    }
}

