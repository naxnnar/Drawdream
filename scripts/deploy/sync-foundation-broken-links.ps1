# Deploy foundation-side fixes (dashboard donations bind, ops require, BOM/declare)
# Usage: .\scripts\deploy\sync-foundation-broken-links.ps1 -SshHost drawdream-vps

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
    "foundation_edit_profile.php",
    "foundation_child_outcome.php",
    "foundation_need_view.php",
    "foundation_post_needlist_result.php",
    "foundation_post_update.php",
    "foundation_public_profile.php",
    "includes/foundation_dashboard_donations_load.php",
    "includes/foundation_dashboard_ops.php"
)

Sync-FileListToServer -Root $Root -Files $files -RemoteApp $RemoteApp
foreach ($rel in $files) {
    Write-Host "  OK  $rel" -ForegroundColor DarkGray
}

Invoke-SshCommand 'bash -lc "systemctl reload php8.1-fpm 2>/dev/null; systemctl reload php8.2-fpm 2>/dev/null; systemctl reload nginx"'
Write-Host "Done. Test foundation dashboard + directory links + donation table expand." -ForegroundColor Green
