# Faster child card sponsorship (fewer Omise round-trips)
# Usage: .\scripts\deploy\sync-child-subscription-speed.ps1 -SshHost drawdream-vps

param(
    [string]$SshHost = "drawdream-vps",
    [string]$RemoteApp = "/var/www/drawdream"
)

$ErrorActionPreference = "Stop"
$Root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
Set-Location $Root
. (Join-Path $PSScriptRoot "_ssh_target.ps1") -SshHost $SshHost

$files = @(
    "payment/child_subscription_create.php",
    "includes/child_omise_subscription.php",
    "includes/omise_api_client.php"
)

Write-Host "==> Deploy child subscription speed fix ($($files.Count) file) -> $SshTarget`:$RemoteApp" -ForegroundColor Cyan
Sync-FileListToServer -Root $Root -Files $files -RemoteApp $RemoteApp
foreach ($rel in $files) {
    Write-Host "  OK  $rel" -ForegroundColor DarkGray
}
Invoke-SshCommand 'bash -lc "systemctl reload php8.1-fpm 2>/dev/null || systemctl reload php8.2-fpm 2>/dev/null || true"'
Write-Host "Done. Test card sponsorship on children_donate.php." -ForegroundColor Green
