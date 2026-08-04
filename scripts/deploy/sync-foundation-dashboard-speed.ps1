# Deploy foundation dashboard load-speed optimizations
# Usage: .\scripts\deploy\sync-foundation-dashboard-speed.ps1 -SshHost drawdream-vps

param(
    [string]$ServerIp = "82.26.104.99",
    [string]$User = "root",
    [string]$SshHost = "",
    [string]$RemoteApp = "/var/www/drawdream"
)

$ErrorActionPreference = "Stop"
$Root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
Set-Location $Root
. (Join-Path $PSScriptRoot "_ssh_target.ps1") -ServerIp $ServerIp -User $User -SshHost $SshHost

$files = @(
    "foundation_dashboard.php",
    "foundation_dashboard_data.php",
    "includes/foundation_dashboard_bootstrap.php",
    "includes/foundation_dashboard_donations_load.php",
    "includes/foundation_dashboard_insights.php",
    "includes/foundation_dashboard_ops.php",
    "includes/foundation_analytics.php",
    "navbar.php",
    "css/foundation_manage.css",
    "js/foundation_dashboard_charts.js"
)

Sync-FileListToServer -Root $Root -Files $files -RemoteApp $RemoteApp
foreach ($rel in $files) {
    Write-Host "  OK  $rel" -ForegroundColor DarkGray
}

Invoke-SshCommand 'bash -lc "systemctl reload php8.1-fpm 2>/dev/null; systemctl reload php8.2-fpm 2>/dev/null; systemctl reload nginx"'
Write-Host "Done. Test foundation_dashboard.php load time" -ForegroundColor Green
