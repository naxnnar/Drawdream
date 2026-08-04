# Deploy foundation UX audit fixes (preview, verify, errors, dashboard lazy, navbar)
# Usage: .\scripts\deploy\sync-foundation-ux-audit.ps1 -SshHost drawdream-vps

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
    "includes/drawdream_user_error.php",
    "includes/foundation_account_verified.php",
    "includes/return_to.php",
    "foundation.php",
    "foundation_dashboard.php",
    "foundation_children_directory.php",
    "foundation_projects_directory.php",
    "foundation_needlist_directory.php",
    "foundation_need_view.php",
    "foundation_need_wizard.php",
    "foundation_add_need.php",
    "foundation_add_children.php",
    "foundation_add_project.php",
    "profile.php",
    "update_profile.php",
    "navbar.php"
)

Sync-FileListToServer -Root $Root -Files $files -RemoteApp $RemoteApp
foreach ($rel in $files) {
    Write-Host "  OK  $rel" -ForegroundColor DarkGray
}

Invoke-SshCommand 'bash -lc "systemctl reload php8.1-fpm 2>/dev/null; systemctl reload php8.2-fpm 2>/dev/null; systemctl reload nginx"'
Write-Host "Done. Test preview block msg, unverified dashboard block, full_dash lazy load" -ForegroundColor Green
