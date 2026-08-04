# Foundation UX: compact todo button + save return navigation
param(
    [string]$SshHost = "drawdream-vps",
    [string]$RemoteApp = "/var/www/drawdream"
)
$ErrorActionPreference = "Stop"
$Root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
Set-Location $Root
. (Join-Path $PSScriptRoot "_ssh_target.ps1") -SshHost $SshHost
$files = @(
    "css/foundation_manage.css",
    "js/foundation_manage_nav.js",
    "includes/foundation_need_flash.php",
    "includes/return_to.php",
    "foundation_dashboard.php",
    "foundation_needlist_directory.php",
    "foundation_need_view.php",
    "foundation_add_need.php",
    "foundation.php"
)
Write-Host "==> Deploy foundation nav/UX ($($files.Count) files)" -ForegroundColor Cyan
Sync-FileListToServer -Root $Root -Files $files -RemoteApp $RemoteApp
Invoke-SshCommand 'bash -lc "systemctl reload php8.1-fpm 2>/dev/null; systemctl reload php8.2-fpm 2>/dev/null; true"'
Write-Host "Done." -ForegroundColor Green
