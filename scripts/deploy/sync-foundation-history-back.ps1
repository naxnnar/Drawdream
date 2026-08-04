# Foundation history back navigation (navbar + all manage pages)
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
    "js/foundation_manage_nav.js",
    "foundation.php",
    "foundation_dashboard.php",
    "foundation_needlist_directory.php",
    "foundation_need_view.php",
    "foundation_add_need.php",
    "foundation_need_wizard.php",
    "foundation_project_view.php",
    "foundation_projects_directory.php",
    "foundation_children_directory.php",
    "foundation_post_update.php",
    "foundation_post_needlist_result.php",
    "foundation_child_outcome.php",
    "foundation_bulk_needlist_outcome.php",
    "foundation_bulk_project_outcome.php",
    "foundation_bulk_child_outcome.php",
    "foundation_donate_info.php"
)
Write-Host "==> Deploy foundation history back ($($files.Count) files)" -ForegroundColor Cyan
Sync-FileListToServer -Root $Root -Files $files -RemoteApp $RemoteApp
Invoke-SshCommand 'bash -lc "systemctl reload php8.1-fpm 2>/dev/null; systemctl reload php8.2-fpm 2>/dev/null; true"'
Write-Host "Done." -ForegroundColor Green
