# Deploy foundation dashboard pagination + E2E test script
# Usage: .\scripts\deploy\sync-foundation-pagination-e2e.ps1 -SshHost drawdream-vps

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
    "foundation_dashboard.php",
    "foundation_dashboard_data.php",
    "includes/foundation_dashboard_donations_load.php",
    "js/foundation_dashboard_charts.js",
    "tools/test_foundation_e2e.php"
)

Sync-FileListToServer -Root $Root -Files $files -RemoteApp $RemoteApp
foreach ($rel in $files) {
    Write-Host "  OK  $rel" -ForegroundColor DarkGray
}

Invoke-SshCommand 'bash -lc "systemctl reload php8.1-fpm 2>/dev/null; systemctl reload php8.2-fpm 2>/dev/null; systemctl reload nginx"'
Write-Host "Done. Run on server:" -ForegroundColor Green
Write-Host "  php tools/test_foundation_e2e.php" -ForegroundColor Cyan
Write-Host "Test UI: foundation_dashboard.php -> Donations tab -> Prev/Next pagination" -ForegroundColor Cyan
