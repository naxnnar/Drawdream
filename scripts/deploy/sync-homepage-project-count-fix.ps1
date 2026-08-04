# Deploy: homepage "โครงการที่ระดมทุนสำเร็จ" count matches project.php completed filter
# Usage: .\scripts\deploy\sync-homepage-project-count-fix.ps1 -SshHost drawdream-vps

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
    "includes/drawdream_project_status.php",
    "includes/homepage_impact_stats.php",
    "project.php"
)

Sync-FileListToServer -Root $Root -Files $files -RemoteApp $RemoteApp
foreach ($rel in $files) {
    Write-Host "  OK  $rel" -ForegroundColor DarkGray
}

Invoke-SshCommand 'bash -lc "rm -f /var/www/drawdream/config/homepage_impact_cache.json; systemctl reload php8.1-fpm 2>/dev/null; systemctl reload php8.2-fpm 2>/dev/null; systemctl reload nginx"'
Write-Host 'Done. Refresh homepage - projects_completed should match completed project filter.' -ForegroundColor Green
