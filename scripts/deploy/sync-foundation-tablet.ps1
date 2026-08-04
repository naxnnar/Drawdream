# Deploy foundation mobile/tablet UI
# Usage: .\scripts\deploy\sync-foundation-tablet.ps1 -SshHost drawdream-vps

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
    "css/foundation_manage.css",
    "foundation.php",
    "foundation_dashboard.php",
    "foundation_children_directory.php",
    "foundation_projects_directory.php",
    "foundation_needlist_directory.php",
    "foundation_need_wizard.php",
    "foundation_need_view.php",
    "foundation_project_view.php",
    "foundation_add_need.php",
    "foundation_post_update.php",
    "foundation_post_needlist_result.php",
    "foundation_child_outcome.php",
    "foundation_bulk_project_outcome.php",
    "foundation_bulk_needlist_outcome.php",
    "foundation_bulk_child_outcome.php"
)

Sync-FileListToServer -Root $Root -Files $files -RemoteApp $RemoteApp
foreach ($rel in $files) {
    Write-Host "  OK  $rel" -ForegroundColor DarkGray
}

Invoke-SshCommand 'bash -lc "systemctl reload php8.1-fpm 2>/dev/null; systemctl reload php8.2-fpm 2>/dev/null; systemctl reload nginx"'
Write-Host "Done. Test foundation pages at 768-1024px (iPad) and phone widths." -ForegroundColor Green
