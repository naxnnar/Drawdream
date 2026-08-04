# Align foundation donate "บริจาคครบตามที่เหลือ" with goal remaining
# Usage: .\scripts\deploy\sync-foundation-donate-goal-align.ps1 -SshHost drawdream-vps

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
    "payment/foundation_donate.php",
    "payment/check_needlist_payment.php",
    "payment/scan_qr.php",
    "includes/drawdream_needlist_catalog.php",
    "includes/drawdream_needlist_catalog_funded.php",
    "includes/drawdream_needlist_payment_finalize.php",
    "includes/payment_transaction_schema.php",
    "includes/drawdream_migrations.php",
    "includes/qr_payment_abandon.php",
    "css/foundation.css"
)

Sync-FileListToServer -Root $Root -Files $files -RemoteApp $RemoteApp
foreach ($rel in $files) {
    Write-Host "  OK  $rel" -ForegroundColor DarkGray
}

Invoke-SshCommand "bash -lc 'cd $RemoteApp && php tools/run_migrations.php --force 2>&1 | tail -20'"
Invoke-SshCommand 'bash -lc "systemctl reload php8.1-fpm 2>/dev/null; systemctl reload php8.2-fpm 2>/dev/null; systemctl reload nginx"'
Write-Host "Done. Needlist Option B: picks on completed donation, no QR reservation, faster poll." -ForegroundColor Green
