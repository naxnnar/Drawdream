# Drop escrow_funds.omise_charge_id on VPS
param([string]$SshHost = "drawdream-vps")

$ErrorActionPreference = "Stop"
$Root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
Set-Location $Root
. (Join-Path $PSScriptRoot "_ssh_target.ps1") -SshHost $SshHost

$Files = @(
    "includes/escrow_funds_schema.php",
    "db.php",
    "tools/run_escrow_migration.php"
)

Write-Host "==> Deploy escrow omise_charge_id drop..." -ForegroundColor Cyan
Sync-FileListToServer -Root $Root -Files $Files -RemoteApp "/var/www/drawdream"

Write-Host "==> Run migration..." -ForegroundColor Cyan
Invoke-SshCommand "php /var/www/drawdream/tools/run_escrow_migration.php"

Write-Host "Done." -ForegroundColor Green
