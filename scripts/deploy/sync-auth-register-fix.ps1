# Donor register: auto-login when email exists with same password
param(
    [string]$SshHost = "drawdream-vps",
    [string]$RemoteApp = "/var/www/drawdream"
)
$ErrorActionPreference = "Stop"
$Root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
Set-Location $Root
. (Join-Path $PSScriptRoot "_ssh_target.ps1") -SshHost $SshHost
$files = @(
    "login.php",
    "includes/auth_register_helpers.php"
)
Write-Host "==> Deploy auth register fix" -ForegroundColor Cyan
Sync-FileListToServer -Root $Root -Files $files -RemoteApp $RemoteApp
Invoke-SshCommand 'bash -lc "systemctl reload php8.1-fpm 2>/dev/null; systemctl reload php8.2-fpm 2>/dev/null; true"'
Write-Host "Done." -ForegroundColor Green
