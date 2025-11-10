@echo off
echo === Kiem tra Git ===
echo.

REM Kiểm tra Git
where git >nul 2>&1
if %ERRORLEVEL% EQU 0 (
    echo [OK] Git da duoc cai dat!
    echo.
    echo Thong tin Git:
    git --version
    echo.
    echo Cau hinh Git:
    git config --list 2>nul
    if %ERRORLEVEL% NEQ 0 (
        echo.
        echo [WARNING] Git chua duoc cau hinh!
        echo Chay cac lenh sau de cau hinh:
        echo   git config --global user.name "Your Name"
        echo   git config --global user.email "your.email@example.com"
    )
) else (
    echo [ERROR] Git CHUA duoc cai dat hoac chua co trong PATH!
    echo.
    echo Cac buoc tiep theo:
    echo 1. Tai Git: https://git-scm.com/download/win
    echo 2. Cai dat Git
    echo 3. Xem file HUONG_DAN_CAI_GIT.md de biet chi tiet
    echo.
)

echo.
echo Tim kiem Git trong he thong...
echo.

REM Tìm Git trong các vị trí thường gặp
set FOUND=0

if exist "C:\Program Files\Git\cmd\git.exe" (
    echo [FOUND] C:\Program Files\Git\cmd\git.exe
    set FOUND=1
)

if exist "C:\Program Files (x86)\Git\cmd\git.exe" (
    echo [FOUND] C:\Program Files (x86)\Git\cmd\git.exe
    set FOUND=1
)

if exist "%LOCALAPPDATA%\Programs\Git\cmd\git.exe" (
    echo [FOUND] %LOCALAPPDATA%\Programs\Git\cmd\git.exe
    set FOUND=1
)

if %FOUND% EQU 0 (
    echo [NOT FOUND] Khong tim thay Git trong cac vi tri thuong gap
    echo.
    echo Git co the da duoc cai dat nhung chua co trong PATH
    echo Xem file HUONG_DAN_CAI_GIT.md de biet cach them vao PATH
) else (
    echo.
    echo [INFO] Neu Git da duoc tim thay nhung van khong chay duoc,
    echo ban can them duong dan vao PATH.
    echo Xem file HUONG_DAN_CAI_GIT.md de biet cach them.
)

echo.
pause
