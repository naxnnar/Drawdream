# Deploy: fix needlist QR donation stuck on test auto-pay / poll (session lock + picks persistence)
# Usage: .\scripts\deploy\sync-needlist-donation-qr-fix.ps1 -SshHost drawdream-vps

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
    "includes/drawdream_needlist_payment_finalize.php",
    "payment/foundation_donate.php",
    "payment/check_needlist_payment.php",
    "payment/needlist_goal_slot_check.php",
    "payment/scan_qr.php"
)

Sync-FileListToServer -Root $Root -Files $files -RemoteApp $RemoteApp
foreach ($rel in $files) {
    Write-Host "  OK  $rel" -ForegroundColor DarkGray
}

Invoke-SshCommand 'bash -lc "systemctl reload php8.1-fpm 2>/dev/null; systemctl reload php8.2-fpm 2>/dev/null; systemctl reload nginx"'
Write-Host "Done. Test foundation needlist donation QR -> should redirect to receipt in test mode." -ForegroundColor Green
