# Deploy donor UX quick wins + receipt-after-success flow
# Usage: .\scripts\deploy\sync-donor-ux-receipt.ps1 -SshHost drawdream-vps

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
    "children_.php",
    "children_donate.php",
    "foundation.php",
    "homepage.php",
    "donation_receipt.php",
    "login.php",
    "navbar.php",
    "profile.php",
    "project.php",
    "db.php",
    "mark_notif_read.php",
    "notifications_feed.php",
    "includes/e_receipt.php",
    "includes/homepage_impact_stats.php",
    "includes/vendor_assets.php",
    "includes/welcome_session.php",
    "includes/navbar_notifications.php",
    "includes/child_sponsorship.php",
    "includes/child_omise_subscription.php",
    "includes/foundation_review_schema.php",
    "payment/scan_qr.php",
    "payment/check_child_payment.php",
    "payment/check_project_payment.php",
    "payment/check_needlist_payment.php",
    "payment/child_subscription_create.php",
    "payment/project_goal_slot_check.php",
    "payment/needlist_goal_slot_check.php",
    "payment/child_sponsorship_slot_check.php",
    "payment/foundation_donate.php",
    "payment/child_donate.php",
    "payment/payment_project.php",
    "js/drawdream-swal.js",
    "js/thai_address_select.js",
    "vendor/thai_address/raw_database.json",
    "css/children.css",
    "css/profile.css",
    "css/touch_feedback.css",
    "css/foundation.css",
    "css/payment.css",
    "vendor/bootstrap/bootstrap.bundle.min.js",
    "vendor/bootstrap-icons/bootstrap-icons.min.css",
    "vendor/bootstrap-icons/fonts/bootstrap-icons.woff2",
    "vendor/sweetalert2/sweetalert2.all.min.js"
)

$missing = @()
foreach ($rel in $files) {
    if (-not (Test-Path (Join-Path $Root $rel))) {
        $missing += $rel
    }
}

if ($missing.Count -gt 0) {
    Write-Host "Missing locally:" -ForegroundColor Red
    $missing | ForEach-Object { Write-Host "  $_" -ForegroundColor Red }
    exit 1
}

Write-Host "==> Deploy donor UX + receipt ($($files.Count) files) -> $SshTarget`:$RemoteApp" -ForegroundColor Cyan
Sync-FileListToServer -Root $Root -Files $files -RemoteApp $RemoteApp
foreach ($rel in $files) {
    Write-Host "  OK  $rel" -ForegroundColor DarkGray
}

Write-Host ""
Write-Host "==> Reload PHP-FPM + Nginx..." -ForegroundColor Cyan
Invoke-SshCommand 'bash -lc "systemctl reload php8.1-fpm 2>/dev/null; systemctl reload php8.2-fpm 2>/dev/null; systemctl reload nginx"'

Write-Host ""
Write-Host "==> Post-deploy syntax check..." -ForegroundColor Cyan
Invoke-SshCommand "php -l $RemoteApp/donation_receipt.php && php -l $RemoteApp/children_donate.php && php -l $RemoteApp/profile.php && php -l $RemoteApp/payment/payment_project.php"

Write-Host ""
Write-Host "Done. Test:" -ForegroundColor Green
Write-Host "  https://drawdream.org/children_donate.php?id=1" -ForegroundColor Cyan
Write-Host "  https://drawdream.org/profile.php?history=1" -ForegroundColor Cyan
