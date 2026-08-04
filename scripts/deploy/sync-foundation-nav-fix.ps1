# Deploy Group A foundation navigation fixes
# Usage: .\scripts\deploy\sync-foundation-nav-fix.ps1 -SshHost drawdream-vps

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
    "payment/system_donate.php",
    "navbar.php",
    "children_.php",
    "includes/welcome_session.php",
    "includes/return_to.php",
    "includes/foundation_donor_preview.php",
    "foundation_dashboard.php",
    "foundation_children_directory.php",
    "foundation_projects_directory.php",
    "foundation_needlist_directory.php",
    "foundation_add_children.php",
    "foundation_add_need.php",
    "foundation_add_project.php",
    "foundation_need_view.php",
    "foundation_dashboard.php",
    "js/foundation_dashboard_charts.js"
)

Sync-FileListToServer -Root $Root -Files $files -RemoteApp $RemoteApp
foreach ($rel in $files) {
    Write-Host "  OK  $rel" -ForegroundColor DarkGray
}

Invoke-SshCommand 'bash -lc "systemctl reload php8.1-fpm 2>/dev/null; systemctl reload php8.2-fpm 2>/dev/null; systemctl reload nginx"'
Write-Host "Done. Test:" -ForegroundColor Green
Write-Host "  https://drawdream.org/foundation_dashboard.php (login redirect)" -ForegroundColor Cyan
Write-Host "  https://drawdream.org/children_.php (foundation card + header)" -ForegroundColor Cyan
Write-Host "  Navbar profile menu -> แดชบอร์ดมูลนิธิ" -ForegroundColor Cyan
