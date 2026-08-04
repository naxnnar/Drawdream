# Hotfix: foundation_add_need.php bind_param mismatch on edit save
param(
    [string]$SshHost = "drawdream-vps",
    [string]$RemoteApp = "/var/www/drawdream"
)
$ErrorActionPreference = "Stop"
$Root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
Set-Location $Root
. (Join-Path $PSScriptRoot "_ssh_target.ps1") -SshHost $SshHost
Sync-FileListToServer -Root $Root -Files @("foundation_add_need.php") -RemoteApp $RemoteApp
Invoke-SshCommand 'bash -lc "php /var/www/drawdream/tools/run_migrations.php --force 2>&1 | tail -5; systemctl reload php8.1-fpm 2>/dev/null; systemctl reload php8.2-fpm 2>/dev/null; true"'
Write-Host "Done. Test foundation_add_need.php?edit=5 save" -ForegroundColor Green
