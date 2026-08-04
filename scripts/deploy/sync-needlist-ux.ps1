# Deploy P0-P4 needlist validation + UX (มูลนิธิ)
# Usage: .\scripts\deploy\sync-needlist-ux.ps1
# หลัง setup SSH key: .\scripts\deploy\sync-needlist-ux.ps1 -SshHost drawdream-vps

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
    ".user.ini",
    "js/drawdream-image-compress.js",
    "includes/drawdream_upload.php",
    "includes/drawdream_image_compress.php",
    "includes/drawdream_needlist_schema.php",
    "includes/foundation_dashboard_todos.php",
    "admin_approve_needlist.php",
    "foundation_add_need.php",
    "foundation_need_wizard.php",
    "foundation_dashboard.php",
    "foundation_needlist_directory.php",
    "payment/foundation_donate.php",
    "profile.php",
    "update_profile.php",
    "tools/audit_needlist_readiness.php",
    "tools/test_needlist_readiness.php",
    "tools/fix_notification_receipt_links.php",
    "includes/escrow_funds_schema.php",
    "tools/test_escrow_summary_model.php"
)

$missing = @()
foreach ($rel in $files) {
    if (-not (Test-Path (Join-Path $Root $rel))) {
        $missing += $rel
    }
}

Write-Host "==> Deploy needlist UX $($files.Count) files -> $SshTarget`:$RemoteApp (1 upload)" -ForegroundColor Cyan

if ($missing.Count -gt 0) {
    Write-Host "Missing locally:" -ForegroundColor Red
    $missing | ForEach-Object { Write-Host "  $_" -ForegroundColor Red }
    exit 1
}

Sync-FileListToServer -Root $Root -Files $files -RemoteApp $RemoteApp
foreach ($rel in $files) {
    Write-Host "  OK  $rel" -ForegroundColor DarkGray
}

Write-Host ""
Write-Host "==> Reload PHP-FPM + Nginx..." -ForegroundColor Cyan
Invoke-SshCommand 'bash -lc "systemctl reload php8.1-fpm 2>/dev/null; systemctl reload php8.2-fpm 2>/dev/null; systemctl reload nginx"'

Write-Host ""
Write-Host "==> Post-deploy checks on server..." -ForegroundColor Cyan
Invoke-SshCommand "php $RemoteApp/tools/test_needlist_readiness.php; php $RemoteApp/tools/audit_needlist_readiness.php --approved-only; php $RemoteApp/tools/fix_notification_receipt_links.php --apply"

Write-Host ""
Write-Host "Done." -ForegroundColor Green
