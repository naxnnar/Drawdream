# Deploy sequential-ID fixes + run one-time renumber on VPS
param(
    [string]$SshHost = "drawdream-vps",
    [string]$RemoteApp = "/var/www/drawdream",
    [switch]$SkipRenumber,
    [switch]$DryRunOnly
)

$ErrorActionPreference = "Stop"
$Root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
Set-Location $Root
. (Join-Path $PSScriptRoot "_ssh_target.ps1") -SshHost $SshHost

$files = @(
    "includes/qr_payment_abandon.php",
    "includes/notification_audit.php",
    "includes/admin_audit_migrate.php",
    "tools/renumber_admin.php",
    "tools/renumber_notifications.php",
    "tools/renumber_donations.php",
    "tools/renumber_all_sequential_ids.php",
    "tools/fix_notifications_autoincrement.php"
)

Sync-FileListToServer -Root $Root -Files $files -RemoteApp $RemoteApp
Invoke-SshCommand 'bash -lc "systemctl reload php8.1-fpm 2>/dev/null || systemctl reload php-fpm 2>/dev/null || true"'
Write-Host "Code deployed." -ForegroundColor Green

if ($SkipRenumber) {
    Write-Host "Skipped renumber (--SkipRenumber)." -ForegroundColor Yellow
    exit 0
}

$renumberFlag = if ($DryRunOnly) { "--dry-run" } else { "--apply" }
Write-Host "Running renumber_all_sequential_ids.php $renumberFlag on VPS..." -ForegroundColor Cyan
Invoke-SshCommand "bash -lc 'cd $RemoteApp && php tools/renumber_all_sequential_ids.php $renumberFlag'"
Write-Host "Done." -ForegroundColor Green
