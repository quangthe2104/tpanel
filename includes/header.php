<?php
if (!isset($auth)) {
    $auth = new Auth();
    $auth->requireLogin();
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? escape($pageTitle) : 'Tpanel'; ?> - Quản lý Website</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #667eea;
            --secondary-color: #764ba2;
        }
        .sidebar {
            min-height: 100vh;
            background: linear-gradient(180deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 0;
        }
        .sidebar .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 0.75rem 1.5rem;
            border-left: 3px solid transparent;
            transition: all 0.3s;
        }
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            color: white;
            background: rgba(255,255,255,0.1);
            border-left-color: white;
        }
        .sidebar .nav-link i {
            width: 20px;
            margin-right: 10px;
        }
        .main-content {
            background: #f8f9fa;
            min-height: 100vh;
        }
        .navbar-custom {
            background: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .card {
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 1.5rem;
        }
        .stat-card {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            border-radius: 10px;
            padding: 1.5rem;
        }
        .stat-card .stat-value {
            font-size: 2rem;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-2 sidebar p-0">
                <div class="p-3">
                    <h4 class="mb-4"><i class="bi bi-shield-lock"></i> Tpanel</h4>
                </div>
                <nav class="nav flex-column">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>" href="index.php">
                        <i class="bi bi-house-door"></i> Trang chủ
                    </a>
                    <?php if ($auth->isAdmin()): ?>
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'admin_websites.php' ? 'active' : ''; ?>" href="admin_websites.php">
                        <i class="bi bi-globe"></i> Quản lý Website
                    </a>
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'admin_users.php' ? 'active' : ''; ?>" href="admin_users.php">
                        <i class="bi bi-people"></i> Quản lý Người dùng
                    </a>
                    <?php endif; ?>
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'my_websites.php' ? 'active' : ''; ?>" href="my_websites.php">
                        <i class="bi bi-collection"></i> Website của tôi
                    </a>
                    <a class="nav-link" href="logout.php">
                        <i class="bi bi-box-arrow-right"></i> Đăng xuất
                    </a>
                </nav>
            </div>
            
            <!-- Main Content -->
            <div class="col-md-10 main-content p-0">
                <nav class="navbar navbar-expand-lg navbar-custom">
                    <div class="container-fluid">
                        <span class="navbar-text">
                            <i class="bi bi-person-circle"></i> 
                            <?php echo escape($_SESSION['full_name'] ?? $_SESSION['username']); ?>
                            <span class="badge bg-<?php echo $auth->isAdmin() ? 'danger' : 'primary'; ?> ms-2">
                                <?php echo $auth->isAdmin() ? 'Admin' : 'User'; ?>
                            </span>
                        </span>
                    </div>
                </nav>
                
                <div class="p-4">
