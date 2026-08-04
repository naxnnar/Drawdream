# Deploy admin sidebar logo size fix
param(
    [string]$SshHost = "drawdream-vps",
    [string]$RemoteApp = "/var/www/drawdream"
)

$ErrorActionPreference = "Stop"
$Root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
Set-Location $Root
. (Join-Path $PSScriptRoot "_ssh_target.ps1") -SshHost $SshHost

$files = @(
    "navbar.php",
    "css/navbar.css",
    "css/brand_logo.css"
)

Sync-FileListToServer -Root $Root -Files $files -RemoteApp $RemoteApp
Invoke-SshCommand 'bash -lc "systemctl reload php8.1-fpm 2>/dev/null || true"'
Write-Host "Done. Test admin sidebar logo on mobile/desktop." -ForegroundColor Green
