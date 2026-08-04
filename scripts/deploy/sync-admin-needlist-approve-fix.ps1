# Fix admin needlist approve + price adjustment on approve
# Usage: .\scripts\deploy\sync-admin-needlist-approve-fix.ps1 -SshHost drawdream-vps

param(
    [string]$SshHost = "drawdream-vps",
    [string]$RemoteApp = "/var/www/drawdream"
)

$ErrorActionPreference = "Stop"
$Root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
Set-Location $Root
. (Join-Path $PSScriptRoot "_ssh_target.ps1") -SshHost $SshHost

$files = @(
    "admin_approve_needlist.php",
    "admin_view_needlist.php",
    "admin_bulk_approve.php",
    "includes/drawdream_needlist_schema.php",
    "includes/drawdream_migrations.php",
    "includes/admin_bulk_approve.php",
    "foundation_need_view.php",
    "mark_notif_read.php",
    "notifications_feed.php",
    "foundation_add_need.php",
    "includes/vendor_assets.php",
    "css/admin.css"
)

Write-Host "==> Deploy admin needlist approve fix ($($files.Count) files) -> $SshTarget`:$RemoteApp" -ForegroundColor Cyan
Sync-FileListToServer -Root $Root -Files $files -RemoteApp $RemoteApp
foreach ($rel in $files) {
    Write-Host "  OK  $rel" -ForegroundColor DarkGray
}
Invoke-SshCommand "php $RemoteApp/tools/run_migrations.php --force 2>&1 | tail -6"
Invoke-SshCommand 'bash -lc "systemctl reload php8.1-fpm 2>/dev/null || systemctl reload php8.2-fpm 2>/dev/null || true; php -r \"if (function_exists(\\\"opcache_reset\\\")) { opcache_reset(); echo \\\"opcache_reset\\\\n\\\"; }\""'
Invoke-SshCommand "php -l $RemoteApp/admin_approve_needlist.php && php -l $RemoteApp/includes/drawdream_needlist_schema.php && php -l $RemoteApp/includes/admin_bulk_approve.php"
Write-Host "Done. Test admin_approve_needlist.php approve + price edit." -ForegroundColor Green
