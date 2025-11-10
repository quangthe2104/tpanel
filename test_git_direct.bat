@echo off
echo === Test Git Direct Path ===
echo.

REM Test các đường dẫn Git thường gặp
set "GIT_PATHS[0]=C:\Program Files\Git\cmd\git.exe"
set "GIT_PATHS[1]=C:\Program Files (x86)\Git\cmd\git.exe"
set "GIT_PATHS[2]=C:\Program Files\Git\bin\git.exe"
set "GIT_PATHS[3]=C:\Program Files (x86)\Git\bin\git.exe"

set FOUND=0

for /L %%i in (0,1,3) do (
    call set "TEST_PATH=%%GIT_PATHS[%%i]%%"
    if exist "!TEST_PATH!" (
        echo [FOUND] !TEST_PATH!
        echo.
        echo Dang test...
        "!TEST_PATH!" --version
        if !ERRORLEVEL! EQU 0 (
            echo.
            echo [SUCCESS] Git hoat dong binh thuong!
            echo.
            echo Duong dan Git: !TEST_PATH!
            echo.
            echo De su dung git trong PowerShell, ban can:
            echo 1. DONG PowerShell hien tai
            echo 2. Mo PowerShell MOI
            echo 3. Hoac them vao PATH: %%~dp!TEST_PATH!
            set FOUND=1
            goto :end
        )
        echo.
    )
)

if %FOUND% EQU 0 (
    echo [ERROR] Khong tim thay git.exe trong cac vi tri thuong gap!
    echo.
    echo Vui long kiem tra:
    echo 1. Git da duoc cai dat chua?
    echo 2. Duong dan cai dat Git la gi?
)

:end
pause
