# Script PowerShell để refresh PATH và test Git

Write-Host "=== Fix Git PATH ===" -ForegroundColor Cyan
Write-Host ""

# Kiểm tra Git có tồn tại không
$gitPaths = @(
    "C:\Program Files\Git\cmd\git.exe",
    "C:\Program Files (x86)\Git\cmd\git.exe",
    "C:\Program Files\Git\bin\git.exe"
)

$gitFound = $null
foreach ($path in $gitPaths) {
    if (Test-Path $path) {
        $gitFound = $path
        Write-Host "[FOUND] Git tại: $path" -ForegroundColor Green
        break
    }
}

if (-not $gitFound) {
    Write-Host "[ERROR] Không tìm thấy Git!" -ForegroundColor Red
    Write-Host ""
    Write-Host "Vui lòng cài đặt Git từ: https://git-scm.com/download/win" -ForegroundColor Yellow
    Read-Host "Nhấn Enter để thoát"
    exit
}

# Refresh PATH từ registry
Write-Host ""
Write-Host "Đang refresh PATH..." -ForegroundColor Yellow
$env:Path = [System.Environment]::GetEnvironmentVariable("Path","Machine") + ";" + [System.Environment]::GetEnvironmentVariable("Path","User")

# Kiểm tra Git trong PATH
$pathArray = $env:Path -split ';'
$gitInPath = $pathArray | Where-Object { $_ -like "*git*" -or $_ -like "*Git*" }

if ($gitInPath) {
    Write-Host "[OK] Git đã có trong PATH:" -ForegroundColor Green
    $gitInPath | ForEach-Object { Write-Host "  - $_" }
} else {
    Write-Host "[WARNING] Git chưa có trong PATH!" -ForegroundColor Yellow
    Write-Host "Đang thêm Git vào PATH (tạm thời cho session này)..." -ForegroundColor Yellow
    $gitDir = Split-Path $gitFound -Parent
    $env:Path = "$gitDir;$env:Path"
}

Write-Host ""
Write-Host "Đang test Git..." -ForegroundColor Yellow
try {
    $version = & git --version 2>&1
    Write-Host "[SUCCESS] Git hoạt động!" -ForegroundColor Green
    Write-Host "Version: $version" -ForegroundColor Green
    Write-Host ""
    Write-Host "Bây giờ bạn có thể sử dụng Git trong PowerShell này!" -ForegroundColor Cyan
} catch {
    Write-Host "[ERROR] Vẫn không chạy được Git!" -ForegroundColor Red
    Write-Host "Lỗi: $_" -ForegroundColor Red
    Write-Host ""
    Write-Host "Giải pháp:" -ForegroundColor Yellow
    Write-Host "1. ĐÓNG PowerShell này" -ForegroundColor White
    Write-Host "2. Mở PowerShell MỚI" -ForegroundColor White
    Write-Host "3. Chạy lại: git --version" -ForegroundColor White
}

Write-Host ""
Read-Host "Nhấn Enter để thoát"
