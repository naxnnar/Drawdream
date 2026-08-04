# Fix foundation donate item picker (catalog from needlist rows)
# Usage: .\scripts\deploy\sync-foundation-donate-picker.ps1 -SshHost drawdream-vps

param(
    [string]$SshHost = "drawdream-vps",
    [string]$RemoteApp = "/var/www/drawdream"
)

$ErrorActionPreference = "Stop"
$Root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
Set-Location $Root
. (Join-Path $PSScriptRoot "_ssh_target.ps1") -SshHost $SshHost

$files = @(
    "payment/foundation_donate.php",
    "includes/drawdream_needlist_catalog.php",
    "includes/drawdream_needlist_schema.php"
)

Write-Host "==> Deploy foundation donate picker fix ($($files.Count) files) -> $SshTarget`:$RemoteApp" -ForegroundColor Cyan
Sync-FileListToServer -Root $Root -Files $files -RemoteApp $RemoteApp
foreach ($rel in $files) {
    Write-Host "  OK  $rel" -ForegroundColor DarkGray
}
Invoke-SshCommand 'bash -lc "systemctl reload php8.1-fpm 2>/dev/null || systemctl reload php8.2-fpm 2>/dev/null || true"'
Invoke-SshCommand "php -l $RemoteApp/payment/foundation_donate.php"
Write-Host "Done. Test payment/foundation_donate.php?fid=..." -ForegroundColor Green
