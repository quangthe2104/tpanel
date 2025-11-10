<?php
/**
 * Reset Admin Password Script
 * Chạy script này để reset mật khẩu admin hoặc tạo lại user admin
 */

require_once 'config/config.php';
require_once 'includes/Database.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Admin Password - Tpanel</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 600px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 { color: #667eea; margin-top: 0; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            box-sizing: border-box;
        }
        button {
            background: #667eea;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
        }
        button:hover { background: #5568d3; }
        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        pre {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔑 Reset Admin Password</h1>
        
        <?php
        $db = Database::getInstance();
        $message = '';
        $messageType = '';
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = $_POST['action'] ?? '';
            $username = $_POST['username'] ?? 'admin';
            $password = $_POST['password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            
            try {
                if ($action === 'reset') {
                    if (empty($newPassword)) {
                        $message = "Vui lòng nhập mật khẩu mới!";
                        $messageType = 'danger';
                    } else {
                        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                        $result = $db->query(
                            "UPDATE users SET password = ? WHERE username = ?",
                            [$hashedPassword, $username]
                        );
                        
                        if ($result->rowCount() > 0) {
                            $message = "✅ Đã reset mật khẩu thành công!<br>Username: <strong>$username</strong><br>Password mới: <strong>$newPassword</strong>";
                            $messageType = 'success';
                        } else {
                            $message = "⚠️ Không tìm thấy user '$username'. Đang tạo user mới...";
                            $messageType = 'info';
                            
                            // Tạo user mới
                            $db->query(
                                "INSERT INTO users (username, email, password, full_name, role) VALUES (?, ?, ?, ?, ?)",
                                [$username, $username . '@tpanel.local', $hashedPassword, 'Administrator', 'admin']
                            );
                            $message = "✅ Đã tạo user admin mới!<br>Username: <strong>$username</strong><br>Password: <strong>$newPassword</strong>";
                            $messageType = 'success';
                        }
                    }
                } elseif ($action === 'create') {
                    if (empty($username) || empty($newPassword)) {
                        $message = "Vui lòng nhập đầy đủ thông tin!";
                        $messageType = 'danger';
                    } else {
                        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                        $email = $_POST['email'] ?? $username . '@tpanel.local';
                        $fullName = $_POST['full_name'] ?? 'Administrator';
                        
                        // Kiểm tra user đã tồn tại chưa
                        $existing = $db->fetchOne("SELECT id FROM users WHERE username = ?", [$username]);
                        if ($existing) {
                            $message = "⚠️ User '$username' đã tồn tại!";
                            $messageType = 'danger';
                        } else {
                            $db->query(
                                "INSERT INTO users (username, email, password, full_name, role) VALUES (?, ?, ?, ?, ?)",
                                [$username, $email, $hashedPassword, $fullName, 'admin']
                            );
                            $message = "✅ Đã tạo user admin thành công!<br>Username: <strong>$username</strong><br>Password: <strong>$newPassword</strong>";
                            $messageType = 'success';
                        }
                    }
                }
            } catch (Exception $e) {
                $message = "❌ Lỗi: " . htmlspecialchars($e->getMessage());
                $messageType = 'danger';
            }
        }
        
        // Kiểm tra user admin có tồn tại không
        $adminExists = false;
        try {
            $admin = $db->fetchOne("SELECT * FROM users WHERE username = 'admin' OR role = 'admin' LIMIT 1");
            $adminExists = $admin !== false;
        } catch (Exception $e) {
            $message = "❌ Lỗi kết nối database: " . htmlspecialchars($e->getMessage());
            $messageType = 'danger';
        }
        ?>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <?php if (!$adminExists): ?>
            <div class="alert alert-info">
                <strong>⚠️ Chưa có user admin!</strong> Vui lòng tạo user admin mới.
            </div>
        <?php endif; ?>
        
        <h3>Reset mật khẩu admin</h3>
        <form method="POST">
            <input type="hidden" name="action" value="reset">
            <div class="form-group">
                <label>Username:</label>
                <input type="text" name="username" value="admin" required>
            </div>
            <div class="form-group">
                <label>Mật khẩu mới:</label>
                <input type="password" name="new_password" required placeholder="Nhập mật khẩu mới">
            </div>
            <button type="submit">Reset Password</button>
        </form>
        
        <hr style="margin: 30px 0;">
        
        <h3>Tạo user admin mới</h3>
        <form method="POST">
            <input type="hidden" name="action" value="create">
            <div class="form-group">
                <label>Username:</label>
                <input type="text" name="username" value="admin" required>
            </div>
            <div class="form-group">
                <label>Email:</label>
                <input type="text" name="email" value="admin@tpanel.local" required>
            </div>
            <div class="form-group">
                <label>Họ tên:</label>
                <input type="text" name="full_name" value="Administrator">
            </div>
            <div class="form-group">
                <label>Mật khẩu:</label>
                <input type="password" name="new_password" required placeholder="Nhập mật khẩu">
            </div>
            <button type="submit">Tạo User Admin</button>
        </form>
        
        <hr style="margin: 30px 0;">
        
        <h3>Kiểm tra database</h3>
        <?php
        try {
            $users = $db->fetchAll("SELECT id, username, email, role, status FROM users ORDER BY id");
            if (empty($users)) {
                echo "<p>Chưa có user nào trong database.</p>";
            } else {
                echo "<pre>";
                echo "Danh sách users:\n";
                echo str_repeat("-", 60) . "\n";
                printf("%-5s %-15s %-25s %-10s %-10s\n", "ID", "Username", "Email", "Role", "Status");
                echo str_repeat("-", 60) . "\n";
                foreach ($users as $user) {
                    printf("%-5s %-15s %-25s %-10s %-10s\n", 
                        $user['id'], 
                        $user['username'], 
                        substr($user['email'], 0, 25),
                        $user['role'],
                        $user['status']
                    );
                }
                echo "</pre>";
            }
        } catch (Exception $e) {
            echo "<div class='alert alert-danger'>Lỗi: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
        ?>
        
        <div style="margin-top: 30px; padding: 15px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 5px;">
            <strong>⚠️ Bảo mật:</strong> Sau khi reset password xong, vui lòng xóa file này!
        </div>
        
        <div style="margin-top: 15px;">
            <a href="login.php" style="display: inline-block; padding: 10px 20px; background: #667eea; color: white; text-decoration: none; border-radius: 5px;">Quay lại đăng nhập</a>
        </div>
    </div>
</body>
</html>
