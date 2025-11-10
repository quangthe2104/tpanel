@echo off
echo === Tpanel Installation ===
echo.

REM Tìm PHP trong WAMP
set PHP_PATH=
if exist "C:\wamp64\bin\php\php8.2.0\php.exe" set PHP_PATH=C:\wamp64\bin\php\php8.2.0\php.exe
if exist "C:\wamp64\bin\php\php8.1.0\php.exe" set PHP_PATH=C:\wamp64\bin\php\php8.1.0\php.exe
if exist "C:\wamp64\bin\php\php8.0.0\php.exe" set PHP_PATH=C:\wamp64\bin\php\php8.0.0\php.exe
if exist "C:\wamp64\bin\php\php7.4.0\php.exe" set PHP_PATH=C:\wamp64\bin\php\php7.4.0\php.exe

REM Nếu không tìm thấy, thử tìm trong thư mục con
if "%PHP_PATH%"=="" (
    for /d %%i in (C:\wamp64\bin\php\php*) do (
        if exist "%%i\php.exe" set PHP_PATH=%%i\php.exe
    )
)

REM Nếu vẫn không tìm thấy, yêu cầu người dùng nhập
if "%PHP_PATH%"=="" (
    echo Khong tim thay PHP trong WAMP!
    echo Vui long nhap duong dan den php.exe:
    echo Vi du: C:\wamp64\bin\php\php8.2.0\php.exe
    set /p PHP_PATH="Duong dan PHP: "
)

if not exist "%PHP_PATH%" (
    echo ERROR: Khong tim thay PHP tai: %PHP_PATH%
    echo.
    echo Vui long:
    echo 1. Tim duong dan den php.exe trong WAMP (thuong la C:\wamp64\bin\php\phpX.X.X\php.exe)
    echo 2. Chay lenh: "duong_dan_php.exe" install.php
    pause
    exit /b 1
)

echo Tim thay PHP: %PHP_PATH%
echo.

REM Chạy install.php
"%PHP_PATH%" install.php

pause

