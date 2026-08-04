# Deploy foundation bulk tasks + dashboard next-action UX
# Usage: .\scripts\deploy\sync-foundation-bulk-tasks.ps1 -SshHost drawdream-vps

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
    "foundation_bulk_child_outcome.php",
    "foundation_bulk_project_outcome.php",
    "foundation_bulk_needlist_outcome.php",
    "foundation_projects_directory.php",
    "foundation_needlist_directory.php",
    "includes/foundation_bulk_tasks.php",
    "includes/foundation_dashboard_todos.php",
    "includes/foundation_dashboard_ops.php",
    "includes/child_sponsorship.php"
)

Sync-FileListToServer -Root $Root -Files $files -RemoteApp $RemoteApp
foreach ($rel in $files) {
    Write-Host "  OK  $rel" -ForegroundColor DarkGray
}

Invoke-SshCommand 'bash -lc "systemctl reload php8.1-fpm 2>/dev/null; systemctl reload php8.2-fpm 2>/dev/null; systemctl reload nginx"'
Write-Host "Done. Test dashboard next-action + bulk outcome pages." -ForegroundColor Green
