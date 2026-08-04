# Deploy admin foundations overview: remove analytics, fix totals link
# Usage: .\scripts\deploy\sync-admin-foundations-overview.ps1 -SshHost drawdream-vps

param(
    [string]$SshHost = "drawdream-vps",
    [string]$RemoteApp = "/var/www/drawdream"
)

$ErrorActionPreference = "Stop"
$Root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
Set-Location $Root
. (Join-Path $PSScriptRoot "_ssh_target.ps1") -SshHost $SshHost

$files = @(
    "admin_foundations_overview.php",
    "admin_view_foundation.php",
    "foundation.php",
    "includes/homepage_impact_stats.php",
    "includes/drawdream_migrations.php",
    "css/admin_directory.css"
)

Write-Host "==> Deploy admin foundations overview (remove pause visibility) -> $SshTarget`:$RemoteApp" -ForegroundColor Cyan
Sync-FileListToServer -Root $Root -Files $files -RemoteApp $RemoteApp
Invoke-SshCommand "rm -f $RemoteApp/includes/foundation_admin_visibility.php"
Invoke-SshCommand "cd $RemoteApp && php tools/run_migrations.php --force"
Invoke-SshCommand 'bash -lc "systemctl reload php8.1-fpm 2>/dev/null; systemctl reload php8.2-fpm 2>/dev/null; true"'
Write-Host "Done. Test https://drawdream.org/admin_foundations_overview.php" -ForegroundColor Green
