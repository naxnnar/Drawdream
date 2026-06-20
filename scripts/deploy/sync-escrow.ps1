# Deploy ชุด escrow summary model (แยกจาก UX)
# Usage: .\scripts\deploy\sync-escrow.ps1

param(
    [string]$ServerIp = "82.26.104.99",
    [string]$User = "root",
    [string]$RemoteApp = "/var/www/drawdream"
)

$ErrorActionPreference = "Stop"
$Root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
Set-Location $Root

$files = @(
    "db.php",
    "admin_approve_foundation.php",
    "foundation_add_children.php",
    "foundation_add_project.php",
    "update_profile.php",
    "includes/escrow_funds_schema.php",
    "includes/drawdream_project_payment_finalize.php",
    "includes/drawdream_needlist_payment_finalize.php",
    "includes/drawdream_needlist_schema.php",
    "includes/foundation_review_schema.php",
    "includes/needlist_category_catalog.php",
    "includes/donate_category_resolve.php",
    "includes/child_sponsorship.php",
    "payment/check_needlist_payment.php",
    "payment/check_needlist_service_charge_payment.php",
    "payment/check_project_payment.php",
    "payment/check_project_service_charge_payment.php",
    "payment/needlist_service_charge_qr.php",
    "payment/project_service_charge_qr.php",
    "payment/system_donate.php",
    "tools/test_escrow_summary_model.php",
    "tools/db_integrity_check.php",
    "tools/drop_legacy_profile_columns.php"
)

$target = "${User}@${ServerIp}"
$missing = @()

Write-Host "==> Deploy escrow $($files.Count) files -> $target`:$RemoteApp" -ForegroundColor Cyan

foreach ($rel in $files) {
    $local = Join-Path $Root $rel
    if (-not (Test-Path $local)) {
        $missing += $rel
        continue
    }
    $remotePath = "$RemoteApp/$rel".Replace('\', '/')
    $remoteDir = ($remotePath -replace '/[^/]+$', '')
    ssh $target "mkdir -p `"$remoteDir`""
    scp $local "${target}:${remotePath}"
    Write-Host "  OK  $rel" -ForegroundColor DarkGray
}

if ($missing.Count -gt 0) {
    Write-Host "Missing locally:" -ForegroundColor Red
    $missing | ForEach-Object { Write-Host "  $_" -ForegroundColor Red }
    exit 1
}

Write-Host ""
Write-Host "==> Reload PHP-FPM + Nginx..." -ForegroundColor Cyan
ssh $target "systemctl reload php8.1-fpm 2>/dev/null || systemctl reload php8.2-fpm 2>/dev/null || true; systemctl reload nginx"

Write-Host ""
Write-Host "==> Run escrow test on server..." -ForegroundColor Cyan
ssh $target "cd $RemoteApp && php tools/test_escrow_summary_model.php"

Write-Host ""
Write-Host "Done. Optional drop legacy DB columns:" -ForegroundColor Green
Write-Host "  ssh $target `"php $RemoteApp/tools/drop_legacy_profile_columns.php --apply`"" -ForegroundColor DarkGray
