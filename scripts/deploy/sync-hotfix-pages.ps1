# Deploy หน้าที่แก้เร่งด่วน (BOM fix + children perf) — ใช้แทน rollback แยกไฟล์
# Usage: .\scripts\deploy\sync-hotfix-pages.ps1 -SshHost drawdream-vps

param(
    [string]$SshHost = "drawdream-vps",
    [string]$RemoteApp = "/var/www/drawdream"
)

$ErrorActionPreference = "Stop"
$Root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
Set-Location $Root
. (Join-Path $PSScriptRoot "_ssh_target.ps1") -SshHost $SshHost

$files = @(
    "foundation_donate_info.php",
    "children_.php"
)

Write-Host "==> Deploy hotfix $($files.Count) files -> $SshTarget`:$RemoteApp" -ForegroundColor Cyan
Sync-FileListToServer -Root $Root -Files $files -RemoteApp $RemoteApp
foreach ($rel in $files) {
    Write-Host "  OK  $rel" -ForegroundColor DarkGray
}
Invoke-SshCommand 'bash -lc "systemctl reload php8.1-fpm 2>/dev/null || systemctl reload php8.2-fpm 2>/dev/null || true"'
Write-Host "Done. Test foundation_donate_info.php?fid=1 and children_.php" -ForegroundColor Green
