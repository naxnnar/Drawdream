# Deploy donor UI tablet/iPad support (navbar drawer <=1024px + page layouts)
# Usage: .\scripts\deploy\sync-donor-tablet.ps1 -SshHost drawdream-vps

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
    "css/children.css",
    "css/payment.css",
    "css/profile.css",
    "children_.php",
    "children_donate.php",
    "profile.php",
    "payment/payment_project.php"
)

Write-Host "==> Deploy donor tablet UI ($($files.Count) files) -> $SshTarget`:$RemoteApp" -ForegroundColor Cyan
Sync-FileListToServer -Root $Root -Files $files -RemoteApp $RemoteApp
foreach ($rel in $files) {
    Write-Host "  OK  $rel" -ForegroundColor DarkGray
}
Invoke-SshCommand 'bash -lc "systemctl reload php8.1-fpm 2>/dev/null || systemctl reload php8.2-fpm 2>/dev/null || true"'
Write-Host "Done. Test donor pages on iPad width (768-1024px)." -ForegroundColor Green
