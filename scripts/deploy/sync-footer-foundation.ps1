# Deploy footer + homepage story + detail_alin fixes
# Usage: .\scripts\deploy\sync-footer-foundation.ps1 -SshHost drawdream-vps

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
    "detail_alin.php",
    "homepage.php",
    "foundation.php",
    "includes/site_footer.php",
    "css/site_footer.css",
    "css/brand_logo.css",
    "css/homepage.css",
    "css/child_story_detail.css",
    "css/foundation.css"
)

Sync-FileListToServer -Root $Root -Files $files -RemoteApp $RemoteApp
foreach ($rel in $files) {
    Write-Host "  OK  $rel" -ForegroundColor DarkGray
}

Invoke-SshCommand 'bash -lc "systemctl reload php8.1-fpm 2>/dev/null; systemctl reload php8.2-fpm 2>/dev/null; systemctl reload nginx"'
Write-Host "Done. Test:" -ForegroundColor Green
Write-Host "  https://drawdream.org/detail_alin.php" -ForegroundColor Cyan
Write-Host "  https://drawdream.org/homepage.php#stories" -ForegroundColor Cyan
Write-Host "  https://drawdream.org/foundation.php" -ForegroundColor Cyan
