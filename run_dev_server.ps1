# DrawDream - PHP built-in server
# Usage: .\run_dev_server.ps1
#        .\run_dev_server.ps1 -Port 9000

param(
    [int]$Port = 8080
)

$root = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $root

$phpExe = $null
if (Test-Path "C:\xampp\php\php.exe") {
    $phpExe = "C:\xampp\php\php.exe"
}
else {
    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if ($cmd) {
        $phpExe = $cmd.Source
    }
}

if (-not $phpExe) {
    Write-Error "php.exe not found. Install XAMPP or add PHP to PATH."
    exit 1
}

# Map Aiven env -> DB env (only when DB_* is not already set)
if (-not $env:DB_HOST -and $env:AIVEN_HOST) { $env:DB_HOST = $env:AIVEN_HOST }
if (-not $env:DB_PORT -and $env:AIVEN_PORT) { $env:DB_PORT = $env:AIVEN_PORT }
if (-not $env:DB_USER -and $env:AIVEN_USER) { $env:DB_USER = $env:AIVEN_USER }
if (-not $env:DB_PASSWORD -and $env:AIVEN_PASSWORD) { $env:DB_PASSWORD = $env:AIVEN_PASSWORD }
if (-not $env:DB_NAME -and $env:AIVEN_DB) { $env:DB_NAME = $env:AIVEN_DB }

# Fall back to project defaults (same as db.php) so the DB line is never blank
if (-not $env:DB_HOST) { $env:DB_HOST = "mysql-17ffeb44-drawdream.c.aivencloud.com" }
if (-not $env:DB_PORT) { $env:DB_PORT = "21503" }
if (-not $env:DB_USER) { $env:DB_USER = "avnadmin" }
if (-not $env:DB_NAME) { $env:DB_NAME = "defaultdb" }

# Standalone PHP (e.g. C:\php) often has no php.ini, so mysqli/mbstring/openssl/curl never load.
# ต้องมีอย่างน้อย cURL หรือ openssl อย่างใดอย่างหนึ่ง — โค้ด Omise ใช้ curl ก่อน; ถ้าไม่มี curl ต้องมี openssl (HTTPS ผ่าน socket / file_get_contents)
# โหลด -d เฉพาะเมื่อ probe ล้ม (ลด warning "already loaded" บน XAMPP ที่เปิด extension ใน php.ini แล้ว)
$phpDir = Split-Path -Parent $phpExe
$extDir = Join-Path $phpDir 'ext'
$extArgs = @()
$probe = 'exit((function_exists(''mb_strlen'') && function_exists(''mysqli_connect'') && (function_exists(''curl_init'') || extension_loaded(''openssl''))) ? 0 : 1);'
& $phpExe -r $probe 2>$null
if ($LASTEXITCODE -ne 0 -and (Test-Path -LiteralPath $extDir)) {
    $extArgs = @(
        '-d', "extension_dir=$extDir",
        '-d', 'extension=mysqli',
        '-d', 'extension=mbstring',
        '-d', 'extension=openssl',
        '-d', 'extension=curl',
        '-d', 'extension=fileinfo'
    )
}

$url = "http://127.0.0.1:$Port/login.php"
Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  DrawDream - PHP built-in server" -ForegroundColor Cyan
Write-Host "========================================"
Write-Host "  Root: $root"
Write-Host "  PHP : $phpExe"
Write-Host "  DB  : $($env:DB_HOST):$($env:DB_PORT)/$($env:DB_NAME)"
Write-Host "  Open: $url"
Write-Host "  Stop: Ctrl+C"
Write-Host "========================================"
Write-Host ""

Start-Process $url
& $phpExe @extArgs -S "127.0.0.1:$Port" -t $root
