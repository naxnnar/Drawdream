# Deploy foundation profile: remove dashboard button + lazy finance load
# Usage: .\scripts\deploy\sync-foundation-profile.ps1 -SshHost drawdream-vps

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
    "profile.php",
    "profile_foundation_finance.php",
    "includes/foundation_profile_finance_load.php",
    "includes/foundation_profile_finance_partial.php",
    "css/profile.css"
)

Sync-FileListToServer -Root $Root -Files $files -RemoteApp $RemoteApp
foreach ($rel in $files) {
    Write-Host "  OK  $rel" -ForegroundColor DarkGray
}

Invoke-SshCommand 'bash -lc "systemctl reload php8.1-fpm 2>/dev/null; systemctl reload php8.2-fpm 2>/dev/null; systemctl reload nginx"'
Write-Host "Done. Test:" -ForegroundColor Green
Write-Host "  https://drawdream.org/profile.php (foundation login)" -ForegroundColor Cyan
Write-Host "  profile_foundation_finance.php lazy load on finance button" -ForegroundColor Cyan
