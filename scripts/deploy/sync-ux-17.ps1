# Deploy เฉพาะ 17 ไฟล์งาน UX (ไม่แตะ .env / uploads / ไฟล์อื่นใน repo)
# Usage: .\scripts\deploy\sync-ux-17.ps1
# Optional: -ServerIp 82.26.104.99

param(
    [string]$ServerIp = "82.26.104.99",
    [string]$User = "root",
    [string]$RemoteApp = "/var/www/drawdream"
)

$ErrorActionPreference = "Stop"
$Root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
Set-Location $Root

$files = @(
    "foundation_donate_info.php",
    "foundation.php",
    "css/foundation_donate_info.css",
    "includes/return_to.php",
    "includes/password_policy.php",
    "login.php",
    "auth/google_start.php",
    "auth/google_callback.php",
    "children_donate.php",
    "navbar.php",
    "homepage.php",
    "profile.php",
    "payment/child_donate.php",
    "payment/child_subscription_create.php",
    "payment/foundation_donate.php",
    "payment/payment_project.php",
    "tools/fix_notification_receipt_links.php",
    "about.php",
    "children_.php",
    "detail_alin.php",
    "detail_pin.php",
    "detail_san.php"
)

$target = "${User}@${ServerIp}"
$missing = @()

Write-Host "==> Deploy UX $($files.Count) files -> $target`:$RemoteApp" -ForegroundColor Cyan

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
    Write-Host ""
    Write-Host "Missing locally:" -ForegroundColor Red
    $missing | ForEach-Object { Write-Host "  $_" -ForegroundColor Red }
    exit 1
}

Write-Host ""
Write-Host "==> Reload PHP-FPM + Nginx on server..." -ForegroundColor Cyan
ssh $target "systemctl reload php8.1-fpm 2>/dev/null || systemctl reload php8.2-fpm 2>/dev/null || true; systemctl reload nginx"

Write-Host ""
Write-Host "Done. Test: https://drawdream.org/foundation_donate_info.php?fid=1" -ForegroundColor Green
Write-Host "Optional fix receipt links:" -ForegroundColor DarkGray
Write-Host "  ssh $target `"php $RemoteApp/tools/fix_notification_receipt_links.php --apply`"" -ForegroundColor DarkGray
