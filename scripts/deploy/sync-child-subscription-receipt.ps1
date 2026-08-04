# Child subscription success -> receipt page (monthly / 6-month / yearly)
# Usage: .\scripts\deploy\sync-child-subscription-receipt.ps1 -SshHost drawdream-vps

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
    "children_donate.php",
    "donation_receipt.php",
    "includes/child_omise_subscription.php"
)

Write-Host "==> Deploy child subscription receipt UX ($($files.Count) files) -> $SshTarget`:$RemoteApp" -ForegroundColor Cyan
Sync-FileListToServer -Root $Root -Files $files -RemoteApp $RemoteApp
foreach ($rel in $files) {
    Write-Host "  OK  $rel" -ForegroundColor DarkGray
}
Invoke-SshCommand 'bash -lc "systemctl reload php8.1-fpm 2>/dev/null || systemctl reload php8.2-fpm 2>/dev/null || true"'
Invoke-SshCommand "php -l $RemoteApp/payment/child_subscription_create.php && php -l $RemoteApp/children_donate.php && php -l $RemoteApp/donation_receipt.php"
Write-Host "Done. Test card sponsorship (monthly/semiannual/yearly) on children_donate.php." -ForegroundColor Green
