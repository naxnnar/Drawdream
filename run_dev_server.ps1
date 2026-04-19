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
Write-Host "  Open: $url"
Write-Host "  Stop: Ctrl+C"
Write-Host "========================================"
Write-Host ""

Start-Process $url
& $phpExe @extArgs -S "127.0.0.1:$Port" -t $root
