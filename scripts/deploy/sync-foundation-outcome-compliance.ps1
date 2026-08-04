# Deploy foundation outcome compliance (warn 30d / pause 37d)
# Usage: .\scripts\deploy\sync-foundation-outcome-compliance.ps1 -SshHost drawdream-vps

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
    "includes/foundation_outcome_compliance.php",
    "includes/foundation_account_verified.php",
    "includes/foundation_bulk_tasks.php",
    "includes/drawdream_migrations.php",
    "foundation_dashboard.php",
    "foundation_add_project.php",
    "foundation_add_children.php",
    "foundation_add_need.php",
    "foundation_need_wizard.php",
    "tools/cron_foundation_outcome_compliance.php",
    "payment/config.php",
    ".env.example"
)

Write-Host "==> Sync foundation outcome compliance ($($files.Count) files)..." -ForegroundColor Cyan
Sync-FileListToServer -Root $Root -Files $files -RemoteApp $RemoteApp
foreach ($rel in $files) {
    Write-Host "  OK  $rel" -ForegroundColor DarkGray
}

Write-Host "==> Migration v13 + reload PHP/nginx..." -ForegroundColor Cyan
Invoke-DrawdreamPostDeploy -RemoteApp $RemoteApp

Write-Host ""
Write-Host "Done. Next on server (if not in .env yet):" -ForegroundColor Green
Write-Host "  DRAWDREAM_OUTCOME_COMPLIANCE_CRON_SECRET=<random>" -ForegroundColor Yellow
Write-Host "  Cron daily: php $RemoteApp/tools/cron_foundation_outcome_compliance.php" -ForegroundColor Yellow
Write-Host "Test: https://drawdream.org/foundation_dashboard.php (paused banner when applicable)" -ForegroundColor Cyan
