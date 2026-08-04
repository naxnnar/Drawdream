# Deploy admin foundations chart hybrid (mini donut per foundation)
# Usage: .\scripts\deploy\sync-admin-foundations-chart-hybrid.ps1 -SshHost drawdream-vps

param(
    [string]$SshHost = "drawdream-vps",
    [string]$RemoteApp = "/var/www/drawdream"
)

$ErrorActionPreference = "Stop"
$Root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
Set-Location $Root
. (Join-Path $PSScriptRoot "_ssh_target.ps1") -SshHost $SshHost

$files = @(
    "admin_foundations_chart.php",
    "admin_foundations_overview.php",
    "includes/admin_foundations_donation_chart.php",
    "includes/donate_category_resolve.php",
    "js/admin_foundations_chart.js",
    "css/admin_foundations_chart.css"
)

Write-Host "==> Deploy admin foundations chart hybrid -> $SshTarget`:$RemoteApp" -ForegroundColor Cyan
Sync-FileListToServer -Root $Root -Files $files -RemoteApp $RemoteApp
Invoke-SshCommand "rm -f $RemoteApp/config/admin_foundations_chart_cache.json"
Invoke-SshCommand 'bash -lc "systemctl reload php8.1-fpm 2>/dev/null; systemctl reload php8.2-fpm 2>/dev/null; true"'
Write-Host "Done. Test https://drawdream.org/admin_foundations_chart.php" -ForegroundColor Green
