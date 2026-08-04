# Deploy escrow hot-path fix + payment check speed
param([string]$SshHost = "drawdream-vps")

$ErrorActionPreference = "Stop"
$Root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
Set-Location $Root
. (Join-Path $PSScriptRoot "_ssh_target.ps1") -SshHost $SshHost

$Files = @(
    "includes/escrow_funds_schema.php",
    "payment/check_project_service_charge_payment.php",
    "db.php",
    "scripts/deploy/tune-server.sh"
)

Write-Host "==> Deploying escrow + payment timeout fix..." -ForegroundColor Cyan
Sync-FileListToServer -Root $Root -Files $Files -RemoteApp "/var/www/drawdream"

Write-Host "==> Tuning PHP-FPM pool..." -ForegroundColor Cyan
Invoke-SshCommand "sed -i 's/\r$//' /var/www/drawdream/scripts/deploy/tune-server.sh && bash /var/www/drawdream/scripts/deploy/tune-server.sh"

Write-Host "==> Syntax check..." -ForegroundColor Cyan
foreach ($f in @("includes/escrow_funds_schema.php", "payment/check_project_service_charge_payment.php", "db.php")) {
    Invoke-SshCommand "php -l /var/www/drawdream/$f"
}

Write-Host "Done." -ForegroundColor Green
