# Deploy: SweetAlert forms + dashboard AJAX + foundation.php JS + verify card B
# Usage: .\scripts\deploy\sync-foundation-polish-8.ps1 -SshHost drawdream-vps

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
    "js/drawdream-swal.js",
    "js/foundation_page.js",
    "js/foundation_dashboard_charts.js",
    "includes/foundation_dashboard_donations_load.php",
    "foundation_dashboard_data.php",
    "foundation_dashboard.php",
    "foundation.php",
    "css/foundation.css",
    "foundation_add_need.php",
    "foundation_add_children.php",
    "foundation_add_project.php"
)

Sync-FileListToServer -Root $Root -Files $files -RemoteApp $RemoteApp
foreach ($rel in $files) {
    Write-Host "  OK  $rel" -ForegroundColor DarkGray
}

Invoke-SshCommand 'bash -lc "systemctl reload php8.1-fpm 2>/dev/null; systemctl reload php8.2-fpm 2>/dev/null; systemctl reload nginx"'
Write-Host "Done." -ForegroundColor Green
