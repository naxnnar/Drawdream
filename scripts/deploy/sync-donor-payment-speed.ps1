# Deploy donor payment speed optimizations
param(
    [string]$SshHost = "drawdream-vps",
    [string]$RemoteApp = "/var/www/drawdream"
)

$ErrorActionPreference = "Stop"
$Root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
Set-Location $Root
. (Join-Path $PSScriptRoot "_ssh_target.ps1") -SshHost $SshHost

$files = @(
    "includes/payment_bootstrap.php",
    "includes/qr_payment_abandon.php",
    "includes/e_receipt.php",
    "includes/child_sponsorship.php",
    "includes/pending_child_donation.php",
    "includes/drawdream_needlist_payment_finalize.php",
    "payment/child_donate.php",
    "payment/payment_project.php",
    "payment/foundation_donate.php",
    "payment/scan_qr.php",
    "payment/child_subscription_create.php",
    "payment/check_child_payment.php",
    "payment/check_project_payment.php",
    "payment/check_needlist_payment.php"
)

Sync-FileListToServer -Root $Root -Files $files -RemoteApp $RemoteApp
Invoke-SshCommand 'bash -lc "systemctl reload php8.1-fpm"'
Write-Host "Done." -ForegroundColor Green
