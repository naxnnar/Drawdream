# Deploy project service charge notification + link fix
param(
    [string]$SshHost = "drawdream-vps"
)

$ErrorActionPreference = "Stop"
$Root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
Set-Location $Root
. (Join-Path $PSScriptRoot "_ssh_target.ps1") -SshHost $SshHost

$Files = @(
    "includes/drawdream_project_service_charge.php",
    "payment/check_project_service_charge_payment.php",
    "payment/project_service_charge.php",
    "db.php"
)

Write-Host "==> Deploying project service charge notification fix..." -ForegroundColor Cyan
Sync-FileListToServer -Root $Root -Files $Files -RemoteApp "/var/www/drawdream"

Write-Host "==> PHP syntax check on server..." -ForegroundColor Cyan
foreach ($f in $Files) {
    Invoke-SshCommand "php -l /var/www/drawdream/$f"
}

Write-Host ""
Write-Host "Done. Refresh: https://drawdream.org" -ForegroundColor Green
