# Deploy foundation page load-speed optimizations
# Usage: .\scripts\deploy\sync-foundation-speed.ps1 -SshHost drawdream-vps

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
    "foundation_children_directory.php",
    "foundation_projects_directory.php",
    "foundation_needlist_directory.php",
    "foundation_need_wizard.php",
    "foundation_bulk_child_outcome.php",
    "foundation_bulk_project_outcome.php",
    "foundation_bulk_needlist_outcome.php",
    "foundation_child_outcome.php",
    "includes/child_sponsorship.php",
    "includes/foundation_dashboard_todos.php",
    "includes/foundation_dashboard_ops.php",
    "js/foundation_dashboard_charts.js"
)

Sync-FileListToServer -Root $Root -Files $files -RemoteApp $RemoteApp
foreach ($rel in $files) {
    Write-Host "  OK  $rel" -ForegroundColor DarkGray
}

Invoke-SshCommand 'bash -lc "systemctl reload php8.1-fpm 2>/dev/null; systemctl reload php8.2-fpm 2>/dev/null; systemctl reload nginx"'
Write-Host "Done. Foundation pages should load faster." -ForegroundColor Green
