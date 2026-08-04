# Deploy Sprint 1 (foundation onboarding) + Sprint 4 (monitor)
# Usage: .\scripts\deploy\sync-sprint1-sprint4.ps1 -SshHost drawdream-vps

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

php tools/e2e/static_suite.php
if ($LASTEXITCODE -ne 0) { throw "static_suite failed" }

$files = @(
    "health.php",
    "foundation.php",
    "navbar.php",
    "css/foundation.css",
    "includes/drawdream_ops_alert.php",
    "tools/monitor/check_errors.php",
    "tools/system_smoke.php",
    "tools/e2e/static_suite.php",
    ".env.example"
)

Write-Host "==> Sync $($files.Count) files" -ForegroundColor Cyan
Sync-FileListToServer -Root $Root -Files $files -RemoteApp $RemoteApp
foreach ($rel in $files) { Write-Host "  OK  $rel" -ForegroundColor DarkGray }

Invoke-DrawdreamPostDeploy -RemoteApp $RemoteApp

Write-Host "==> Server smoke" -ForegroundColor Cyan
Invoke-SshCommand "bash -lc 'cd $RemoteApp && DRAWDREAM_SMOKE_BASE_URL=https://drawdream.org php tools/system_smoke.php'"
Write-Host "Done." -ForegroundColor Green
