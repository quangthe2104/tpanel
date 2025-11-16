<?php
require_once __DIR__ . '/../includes/helpers/functions.php';

$auth = new Auth();
$auth->requireLogin();

// Chỉ admin mới xem được log
if (!$auth->isAdmin()) {
    die('Bạn không có quyền xem log');
}

$logFile = __DIR__ . '/../logs/download_debug.log';

header('Content-Type: text/plain; charset=utf-8');

if (file_exists($logFile)) {
    // Đọc 500 dòng cuối cùng
    $lines = file($logFile);
    $lastLines = array_slice($lines, -500);
    echo implode('', $lastLines);
} else {
    echo "Log file không tồn tại: $logFile\n";
    echo "Thử download một file backup để tạo log.";
}

