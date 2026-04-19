@echo off
chcp 65001 >nul
setlocal EnableDelayedExpansion

REM DrawDream — PHP built-in server (ไม่ต้องเปิด Apache)
REM ใช้งาน: run_dev_server.bat
REM         run_dev_server.bat 9000

cd /d "%~dp0"
set "PORT=8080"
if not "%~1"=="" set "PORT=%~1"

set "PHP_EXE="
if exist "C:\xampp\php\php.exe" set "PHP_EXE=C:\xampp\php\php.exe"
where php >nul 2>&1
if "!PHP_EXE!"=="" set "PHP_EXE=php"

if "!PHP_EXE!"=="" (
    echo [ERROR] ไม่พบ php.exe — ติดตั้ง XAMPP หรือเพิ่ม PHP ลง PATH
    pause
    exit /b 1
)

REM Resolve bare "php" to full path so we can find the ext\ folder next to php.exe
if /I "!PHP_EXE!"=="php" (
    for /f "delims=" %%P in ('where php 2^>nul') do set "PHP_EXE=%%P" & goto php_ok
    echo [ERROR] ไม่พบ php.exe ใน PATH
    pause
    exit /b 1
)
:php_ok
for %%I in ("!PHP_EXE!") do set "PHP_DIR=%%~dpI"
set "EXT_DIR=!PHP_DIR!ext"
set "PHP_EXTRA="
REM ต้องมี cURL หรือ openssl อย่างใดอย่างหนึ่งสำหรับ Omise HTTPS
"%PHP_EXE%" -r "exit((function_exists('mb_strlen') && function_exists('mysqli_connect') && (function_exists('curl_init') || extension_loaded('openssl'))) ? 0 : 1);" 2>nul
if errorlevel 1 if exist "!EXT_DIR!\*" (
    set "PHP_EXTRA=-d extension_dir=!EXT_DIR! -d extension=mysqli -d extension=mbstring -d extension=openssl -d extension=curl -d extension=fileinfo"
)

echo.
echo ========================================
echo   DrawDream — PHP built-in server
echo ========================================
echo   โฟลเดอร์: %~dp0
echo   URL:      http://127.0.0.1:!PORT!/login.php
echo   หยุด:     ปิดหน้าต่างนี้ หรือ Ctrl+C
echo ========================================
echo.

start "" "http://127.0.0.1:!PORT!/login.php"

"%PHP_EXE%" !PHP_EXTRA! -S 127.0.0.1:!PORT! -t "%~dp0"

endlocal
