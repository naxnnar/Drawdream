# Deploy admin mobile UI (drawer sidebar + responsive tables)
# Usage: .\scripts\deploy\sync-admin-mobile.ps1 -SshHost drawdream-vps

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
    "css/navbar.css",
    "css/admin_directory.css",
    "css/admin_dashboard.css",
    "css/admin_escrow.css",
    "css/admin.css",
    "css/admin_donors_insights.css",
    "css/admin_record_view.css",
    "css/children.css",
    "admin_notifications.php",
    "donation_receipt.php"
)

Write-Host "==> Deploy admin mobile UI ($($files.Count) files) -> $SshTarget`:$RemoteApp" -ForegroundColor Cyan
Sync-FileListToServer -Root $Root -Files $files -RemoteApp $RemoteApp
foreach ($rel in $files) {
    Write-Host "  OK  $rel" -ForegroundColor DarkGray
}
Invoke-SshCommand 'bash -lc "systemctl reload php8.1-fpm 2>/dev/null || systemctl reload php8.2-fpm 2>/dev/null || true"'
Write-Host "Done. Test admin pages on mobile width (<=991px)." -ForegroundColor Green
