# Deploy admin donation history pages (dashboard links + admin_donations.php)
# Usage: .\scripts\deploy\sync-admin-donations.ps1 -SshHost drawdream-vps

param(
    [string]$SshHost = "drawdream-vps",
    [string]$RemoteApp = "/var/www/drawdream"
)

$ErrorActionPreference = "Stop"
$Root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
Set-Location $Root
. (Join-Path $PSScriptRoot "_ssh_target.ps1") -SshHost $SshHost

$files = @(
    "admin_donations.php",
    "includes/admin_donations_filter.php",
    "css/admin_donations.css",
    "css/admin_directory.css"
)

Write-Host "==> Deploy admin donations -> $SshTarget`:$RemoteApp" -ForegroundColor Cyan
Sync-FileListToServer -Root $Root -Files $files -RemoteApp $RemoteApp
Invoke-SshCommand 'bash -lc "systemctl reload php8.1-fpm 2>/dev/null; systemctl reload php8.2-fpm 2>/dev/null; true"'
Write-Host "Done. Test https://drawdream.org/admin_dashboard.php" -ForegroundColor Green
