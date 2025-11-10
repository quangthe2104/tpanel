@echo off
echo === Refresh PATH Environment Variable ===
echo.
echo Dang lam moi PATH trong PowerShell hien tai...
echo.

REM Refresh PATH từ registry
for /f "tokens=2*" %%A in ('reg query "HKLM\SYSTEM\CurrentControlSet\Control\Session Manager\Environment" /v PATH') do set "SYSTEMPATH=%%B"
for /f "tokens=2*" %%A in ('reg query "HKCU\Environment" /v PATH') do set "USERPATH=%%B"

echo System PATH: %SYSTEMPATH%
echo.
echo User PATH: %USERPATH%
echo.

REM Kiểm tra Git trong PATH
echo "%SYSTEMPATH%%USERPATH%" | findstr /i "git" >nul
if %ERRORLEVEL% EQU 0 (
    echo [OK] Git da co trong PATH!
) else (
    echo [WARNING] Git chua co trong PATH!
)

echo.
echo ========================================
echo GIAI PHAP:
echo ========================================
echo.
echo 1. DONG TAT CA PowerShell/CMD DANG MO
echo 2. Mo PowerShell/CMD MOI
echo 3. Chay lai: git --version
echo.
echo Hoac restart may de PATH duoc cap nhat hoan toan.
echo.
pause
