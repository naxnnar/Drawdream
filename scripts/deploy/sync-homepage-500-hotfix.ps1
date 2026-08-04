# Hotfix HTTP 500 — admin_visibility_paused column + safe SQL fallback
# Usage: .\scripts\deploy\sync-homepage-500-hotfix.ps1 -SshHost drawdream-vps

param(
    [string]$SshHost = "drawdream-vps",
    [string]$RemoteApp = "/var/www/drawdream"
)

$ErrorActionPreference = "Stop"
$Root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
Set-Location $Root
. (Join-Path $PSScriptRoot "_ssh_target.ps1") -SshHost $SshHost

$files = @(
    "foundation.php",
    "admin_foundations_overview.php",
    "includes/foundation_admin_visibility.php",
    "includes/homepage_impact_stats.php",
    "includes/drawdream_migrations.php"
)

Sync-FileListToServer -Root $Root -Files $files -RemoteApp $RemoteApp
Invoke-SshCommand "cd $RemoteApp && php tools/run_migrations.php --force"
Invoke-SshCommand 'bash -lc "systemctl reload php8.1-fpm 2>/dev/null || systemctl reload php8.2-fpm 2>/dev/null || true"'
Write-Host "Done. Test homepage.php" -ForegroundColor Green
