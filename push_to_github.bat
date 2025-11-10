@echo off
echo === Push Tpanel to GitHub ===
echo.

REM Kiểm tra Git đã cài đặt chưa
where git >nul 2>&1
if %ERRORLEVEL% NEQ 0 (
    echo ========================================
    echo ERROR: Git chua duoc cai dat!
    echo ========================================
    echo.
    echo Vui long cai dat Git:
    echo.
    echo 1. Tai Git tu: https://git-scm.com/download/win
    echo 2. Cai dat Git (chon "Git from the command line" khi cai)
    echo 3. DONG TAT CA PowerShell/CMD va mo lai
    echo 4. Chay lai script nay
    echo.
    echo Hoac xem file HUONG_DAN_CAI_GIT.md de biet chi tiet
    echo.
    pause
    exit /b 1
)

echo Tim thay Git!
echo.

REM Kiểm tra đã init chưa
if not exist ".git" (
    echo Dang khoi tao Git repository...
    git init
    if %ERRORLEVEL% NEQ 0 (
        echo ERROR: Khong the khoi tao Git repository!
        pause
        exit /b 1
    )
    echo ✓ Da khoi tao Git repository
    echo.
)

REM Kiểm tra remote đã thêm chưa
git remote get-url origin >nul 2>&1
if %ERRORLEVEL% NEQ 0 (
    echo Dang them remote repository...
    git remote add origin https://github.com/quangthe2104/tpanel.git
    if %ERRORLEVEL% NEQ 0 (
        echo ERROR: Khong the them remote repository!
        pause
        exit /b 1
    )
    echo ✓ Da them remote repository
    echo.
)

REM Add files
echo Dang them files...
git add .
if %ERRORLEVEL% NEQ 0 (
    echo ERROR: Khong the add files!
    pause
    exit /b 1
)
echo ✓ Da them files
echo.

REM Check có thay đổi không
git diff --cached --quiet
if %ERRORLEVEL% EQU 0 (
    echo Khong co thay doi nao de commit!
    echo.
) else (
    REM Commit
    echo Dang commit...
    git commit -m "Update Tpanel code"
    if %ERRORLEVEL% NEQ 0 (
        echo ERROR: Khong the commit!
        pause
        exit /b 1
    )
    echo ✓ Da commit
    echo.
)

REM Đổi branch thành main nếu cần
git branch --show-current | findstr /C:"main" >nul
if %ERRORLEVEL% NEQ 0 (
    git branch -M main
)

REM Push
echo Dang push len GitHub...
echo.
echo NOTE: Neu lan dau push, ban se can:
echo - Dang nhap GitHub account
echo - Hoac su dung Personal Access Token
echo.
git push -u origin main

if %ERRORLEVEL% EQU 0 (
    echo.
    echo === THANH CONG! ===
    echo Code da duoc day len GitHub!
    echo Repository: https://github.com/quangthe2104/tpanel
) else (
    echo.
    echo === LOI! ===
    echo Khong the push len GitHub.
    echo Vui long kiem tra:
    echo 1. Ban da dang nhap GitHub chua?
    echo 2. Ban co quyen push vao repository khong?
    echo 3. Neu can, tao Personal Access Token tai: https://github.com/settings/tokens
)

echo.
pause
